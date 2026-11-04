<?php

/**
 * Migration Manager - Tolka speed loader
 *
 * Fetches the customer's public site and times that request for the
 * Migrate to Tolka speed estimate.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads one public page and reports how long the request took.
 *
 * Local, loopback, link-local, and private addresses are refused before
 * any request is sent. Polylang data is never read or deleted.
 */
class Speed_Loader
{
    /**
     * wp_remote_get timeout, in seconds.
     */
    const TIMEOUT_SECONDS = 15;

    /**
     * Maximum redirects WordPress may follow.
     */
    const MAX_REDIRECTS = 3;

    /**
     * Largest HTML body kept for the metrics pass, in bytes.
     */
    const MAX_HTML_BYTES = 2000000;

    /**
     * User agent sent with the measurement request.
     */
    const USER_AGENT = 'WMM-Tolka-Speed/1.0';

    /**
     * Time one public page load.
     *
     * The same keys are always returned. On failure ok is false, html is
     * empty, and error is a sentence the admin screen can show. ttfb_ms is
     * always null. total_ms is the wall-clock time of the HTTP request, in
     * milliseconds, and is 0 when the address is rejected before a request.
     *
     * @param string $url Public http or https URL.
     * @return array{ok: bool, url: string, status_code: int, total_ms: int, ttfb_ms: null, html_bytes: int, html: string, error: string}
     */
    public static function measure(string $url): array
    {
        $checked = self::parse_public_url($url);
        $shown = $checked['url'] !== '' ? $checked['url'] : trim($url);
        $result = self::blank($shown);

        if ($checked['error'] !== '') {
            return self::fail($result, $checked['error']);
        }

        $started = self::now_ns();

        try {
            $response = static::remote_get($checked['url']);
        } catch (\Throwable $e) {
            $result['total_ms'] = self::elapsed_ms($started);
            return self::fail($result, self::text('The site could not be reached. Check the address and try again.'));
        }

        $result['total_ms'] = self::elapsed_ms($started);

        $transport_error = self::transport_error($response);
        if ($transport_error !== '') {
            return self::fail($result, $transport_error);
        }

        $code = self::response_code($response);
        $result['status_code'] = $code;

        $final = self::response_url($response);
        if ($final !== '') {
            $final_scheme = self::url_scheme($final);
            if ($final_scheme !== '' && $final_scheme !== 'http' && $final_scheme !== 'https') {
                return self::fail(
                    $result,
                    self::text('The site redirected to an address that cannot be measured.'),
                    $code
                );
            }
            if ($final_scheme === 'http' || $final_scheme === 'https') {
                $landed = self::parse_public_url($final);
                if ($landed['error'] !== '') {
                    $message = self::text('The site redirected to an address that cannot be measured.');
                if ($landed['reason'] === 'private' || $landed['reason'] === 'private-dns') {
                    $message = self::text('The site redirected to a private or local address, so it cannot be measured. Enter the public address visitors use.');
                }
                    return self::fail($result, $message, $code);
                }
                if ($landed['url'] !== '') {
                    $result['url'] = $landed['url'];
                }
            }
        }

        if ($code < 200 || $code > 299) {
            if ($code === 0) {
                return self::fail($result, self::text('The site could not be reached. Check the address and try again.'));
            }
            return self::fail($result, self::http_status_error($code), $code);
        }

        $html = self::clip_html(self::response_body($response));
        $result['ok'] = true;
        $result['html'] = $html;
        $result['html_bytes'] = strlen($html);
        $result['error'] = '';
        $result['ttfb_ms'] = null;

        return $result;
    }

    /**
     * Arguments passed to wp_remote_get.
     *
     * Timeout, redirect limit, and user agent are fixed by the speed contract.
     * limit_response_size keeps a huge page from exceeding the HTML cap when
     * this WordPress version honors it.
     *
     * @return array{timeout: int, redirection: int, user-agent: string, limit_response_size: int}
     */
    private static function request_args()
    {
        return array(
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => self::MAX_REDIRECTS,
            'user-agent' => self::USER_AGENT,
            'limit_response_size' => self::MAX_HTML_BYTES,
        );
    }

    /**
     * Perform the timed request.
     *
     * Tests may override this to supply a response without loading WordPress.
     * Production calls wp_remote_get with the contract arguments.
     *
     * @param string $url Already validated public URL.
     * @return mixed WordPress response array, WP_Error, or null when HTTP is unavailable.
     */
    protected static function remote_get($url)
    {
        if (!function_exists('wp_remote_get')) {
            return null;
        }

        return wp_remote_get($url, self::request_args());
    }

    /**
     * Resolve a host name to IP addresses.
     *
     * Tests may override this. An empty list means the name could not be
     * resolved; the HTTP request then fails with its own message. Any
     * private address in the list refuses the measurement.
     *
     * @param string $host Host name, without a port.
     * @return string[]
     */
    protected static function resolve_ips($host)
    {
        $ips = array();
        $previous = false;
        $restore = false;

        if (function_exists('getenv') && function_exists('putenv')) {
            $previous = getenv('RES_OPTIONS');
            $restore = putenv('RES_OPTIONS=attempts:1 timeout:2');
        }

        try {
            if (function_exists('gethostbynamel')) {
                $found = @gethostbynamel($host);
                if (is_array($found)) {
                    foreach ($found as $ip) {
                        if (is_string($ip) && $ip !== '') {
                            $ips[] = strtolower($ip);
                        }
                    }
                }
            }

            if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!is_array($record) || empty($record['ipv6']) || !is_string($record['ipv6'])) {
                            continue;
                        }
                        $ips[] = strtolower($record['ipv6']);
                    }
                }
            }
        } finally {
            if ($restore) {
                if ($previous === false) {
                    putenv('RES_OPTIONS');
                } else {
                    putenv('RES_OPTIONS=' . $previous);
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Empty measurement. Every failure and success starts from this shape.
     *
     * @param string $url Address to show in the result.
     * @return array
     */
    private static function blank($url)
    {
        return array(
            'ok' => false,
            'url' => $url,
            'status_code' => 0,
            'total_ms' => 0,
            'ttfb_ms' => null,
            'html_bytes' => 0,
            'html' => '',
            'error' => '',
        );
    }

    /**
     * Mark a measurement failed and drop any HTML.
     *
     * @param array       $result Measurement.
     * @param string      $error  Finished sentence.
     * @param int|null    $status HTTP status to keep, when one was received.
     * @return array
     */
    private static function fail(array $result, $error, $status = null)
    {
        $result['ok'] = false;
        $result['html'] = '';
        $result['html_bytes'] = 0;
        $result['error'] = $error;
        $result['ttfb_ms'] = null;

        if ($status !== null) {
            $result['status_code'] = (int) $status;
        }

        return $result;
    }

    /**
     * Validate a URL and rebuild it without userinfo or a fragment.
     *
     * @param string $url Raw address.
     * @return array{url: string, error: string, reason: string}
     */
    private static function parse_public_url($url)
    {
        $trimmed = trim($url);
        $none = array(
            'url' => '',
            'error' => '',
            'reason' => '',
        );

        if ($trimmed === '') {
            $none['error'] = self::reason_message('empty');
            $none['reason'] = 'empty';
            return $none;
        }

        $trimmed = self::with_https_scheme($trimmed);

        if (strlen($trimmed) > 4096) {
            $none['url'] = $trimmed;
            $none['error'] = self::reason_message('long');
            $none['reason'] = 'long';
            return $none;
        }

        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $trimmed)) {
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message('malformed');
            $none['reason'] = 'malformed';
            return $none;
        }

        if (strpos($trimmed, ' ') !== false) {
            $reason = preg_match('#^https?://#i', $trimmed) ? 'malformed' : 'scheme';
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message($reason);
            $none['reason'] = $reason;
            return $none;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            $reason = 'scheme';
            if (preg_match('#^https?://#i', $trimmed)) {
                $reason = 'malformed';
            }
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message($reason);
            $none['reason'] = $reason;
            return $none;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message('scheme');
            $none['reason'] = 'scheme';
            return $none;
        }

        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        if ($host === '') {
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message('host');
            $none['reason'] = 'host';
            return $none;
        }

        $clean = self::rebuild_url($scheme, $parts);
        if ($clean === '') {
            $none['url'] = self::redact($trimmed);
            $none['error'] = self::reason_message('malformed');
            $none['reason'] = 'malformed';
            return $none;
        }

        $reason = self::judge_host($host);
        if ($reason !== '') {
            return array(
                'url' => $clean,
                'error' => self::reason_message($reason),
                'reason' => $reason,
            );
        }

        return array(
            'url' => $clean,
            'error' => '',
            'reason' => '',
        );
    }

    /**
     * Decide whether a host is empty, unreadable, local, or safe to look up.
     *
     * @param string $host Host from parse_url, possibly bracketed.
     * @return string Reason code, or an empty string when the host may be fetched.
     */
    private static function judge_host($host)
    {
        $saw_name = false;
        $saw_invalid = false;
        $name_for_dns = '';

        foreach (self::host_variants($host) as $variant) {
            $normalized = self::normalized_host($variant);
            if ($normalized === '') {
                return 'host';
            }
            if (strpos($variant, "\0") !== false || strpos($normalized, "\0") !== false) {
                $raw = strpos($variant, "\0") !== false ? $variant : $normalized;
                $cut = strstr($raw, "\0", true);
                $before = is_string($cut) ? self::normalized_host($cut) : '';
                if ($before !== '' && self::literal_is_blocked($before)) {
                    return 'private';
                }
                return 'malformed';
            }
            if (strlen($normalized) > 253 || preg_match('/[\s\/\\\\@#?]/', $normalized)) {
                return 'malformed';
            }
            if (self::is_localhost_name($normalized)) {
                return 'private';
            }

            $literal = self::classify_literal($normalized);
            if ($literal['kind'] === 'ip') {
                if (self::ip_is_blocked($literal['ip'])) {
                    return 'private';
                }
                continue;
            }
            if ($literal['kind'] === 'invalid') {
                $saw_invalid = true;
                continue;
            }

            $saw_name = true;
            $name_for_dns = self::ascii_host($normalized);
        }

        if ($saw_name) {
            $ips = static::resolve_ips($name_for_dns);
            if (is_array($ips)) {
                foreach ($ips as $ip) {
                    if (is_string($ip) && $ip !== '' && self::ip_is_blocked($ip)) {
                        return 'private-dns';
                    }
                }
            }
            return '';
        }

        if ($saw_invalid) {
            return 'malformed';
        }

        return '';
    }

    /**
     * True when a single host form is a blocked IP literal.
     *
     * @param string $host Normalized host.
     * @return bool
     */
    private static function literal_is_blocked($host)
    {
        if (self::is_localhost_name($host)) {
            return true;
        }
        $literal = self::classify_literal($host);
        return $literal['kind'] === 'ip' && self::ip_is_blocked($literal['ip']);
    }

    /**
     * Host as typed, plus a few percent-decoded forms used to hide IP literals.
     *
     * @param string $host Raw host.
     * @return string[]
     */
    private static function host_variants($host)
    {
        $current = (string) $host;
        $variants = array();

        for ($i = 0; $i < 4; $i++) {
            $variants[] = $current;
            $decoded = rawurldecode($current);
            if ($decoded === $current) {
                break;
            }
            $current = $decoded;
        }

        return $variants;
    }

    /**
     * Lowercase host without brackets, a zone id, or a trailing dot.
     *
     * @param string $host Host variant.
     * @return string
     */
    private static function normalized_host($host)
    {
        $host = strtolower(trim((string) $host));
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        $zone = strpos($host, '%');
        if ($zone !== false) {
            $host = substr($host, 0, $zone);
        }

        return rtrim($host, '.');
    }

    /**
     * Names that always mean this machine, even before DNS.
     *
     * @param string $host Normalized host.
     * @return bool
     */
    private static function is_localhost_name($host)
    {
        if (
            $host === 'localhost'
            || $host === 'localhost.localdomain'
            || $host === 'ip6-localhost'
            || $host === 'ip6-loopback'
        ) {
            return true;
        }

        $suffix = '.localhost';
        $length = strlen($suffix);
        return strlen($host) > $length && substr($host, -$length) === $suffix;
    }

    /**
     * Classify a normalized host as a name, an IP, or an invalid numeric literal.
     *
     * @param string $host Normalized host.
     * @return array{kind: string, ip: string}
     */
    private static function classify_literal($host)
    {
        if (strpos($host, ':') !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return array('kind' => 'ip', 'ip' => $host);
            }
            return array('kind' => 'invalid', 'ip' => '');
        }

        $standard = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (is_string($standard)) {
            return array('kind' => 'ip', 'ip' => $standard);
        }

        $expanded = self::expand_obfuscated_ipv4($host);
        if ($expanded === false) {
            return array('kind' => 'invalid', 'ip' => '');
        }
        if (is_string($expanded)) {
            return array('kind' => 'ip', 'ip' => $expanded);
        }

        return array('kind' => 'name', 'ip' => '');
    }

    /**
     * Expand decimal, octal, hex, and short inet_aton IPv4 forms.
     *
     * @param string $host Host without colons.
     * @return string|false|null IP string, false when the literal is invalid, null when it is a name.
     */
    private static function expand_obfuscated_ipv4($host)
    {
        if ($host === '' || strpos($host, ':') !== false) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }

        $numbers = array();
        foreach ($parts as $part) {
            $value = self::parse_ip_component($part);
            if ($value === null) {
                return null;
            }
            if ($value === false) {
                $numbers[] = false;
                continue;
            }
            $numbers[] = $value;
        }

        foreach ($numbers as $number) {
            if ($number === false) {
                return false;
            }
        }

        return self::assemble_ipv4($numbers);
    }

    /**
     * Parse one IPv4 label.
     *
     * @param string $part Label.
     * @return float|false|null Number, false when a numeric label is illegal, null when it is not numeric.
     */
    private static function parse_ip_component($part)
    {
        if (!is_string($part) || $part === '') {
            return false;
        }

        if (preg_match('/^0x([0-9a-f]+)$/i', $part, $matches)) {
            if (strlen($matches[1]) > 8) {
                return false;
            }
            return (float) hexdec($matches[1]);
        }

        if ($part[0] === '0' && strlen($part) > 1) {
            if (!preg_match('/^[0-7]+$/', $part)) {
                return false;
            }
            return (float) octdec($part);
        }

        if (!preg_match('/^[0-9]+$/', $part)) {
            return null;
        }

        if (strlen($part) > 10) {
            return false;
        }

        return (float) $part;
    }

    /**
     * Build a dotted IPv4 address from inet_aton parts.
     *
     * @param array $numbers One to four numeric parts.
     * @return string|false
     */
    private static function assemble_ipv4(array $numbers)
    {
        $count = count($numbers);
        if ($count < 1 || $count > 4) {
            return false;
        }

        if ($count === 4) {
            foreach ($numbers as $number) {
                if ($number < 0 || $number > 255) {
                    return false;
                }
            }
            return (int) $numbers[0] . '.' . (int) $numbers[1] . '.' . (int) $numbers[2] . '.' . (int) $numbers[3];
        }

        $last_limit = array(
            1 => 4294967295,
            2 => 16777215,
            3 => 65535,
        );
        $last = (float) $numbers[$count - 1];
        if ($last < 0 || $last > $last_limit[$count]) {
            return false;
        }

        for ($i = 0; $i < $count - 1; $i++) {
            if ($numbers[$i] < 0 || $numbers[$i] > 255) {
                return false;
            }
        }

        if ($count === 1) {
            return self::uint_to_ipv4($last);
        }
        if ($count === 2) {
            return self::uint_to_ipv4(((float) $numbers[0] * 16777216) + $last);
        }

        return self::uint_to_ipv4(((float) $numbers[0] * 16777216) + ((float) $numbers[1] * 65536) + $last);
    }

    /**
     * Convert an integer in 0..4294967295 to dotted IPv4.
     *
     * @param float $number Address as an integer.
     * @return string|false
     */
    private static function uint_to_ipv4($number)
    {
        $number = (float) $number;
        if ($number < 0 || $number > 4294967295) {
            return false;
        }

        $first = (int) floor($number / 16777216);
        $number -= $first * 16777216;
        $second = (int) floor($number / 65536);
        $number -= $second * 65536;
        $third = (int) floor($number / 256);
        $fourth = (int) ($number - ($third * 256));

        return $first . '.' . $second . '.' . $third . '.' . $fourth;
    }

    /**
     * True when an IP is loopback, link-local, private, or otherwise not public.
     *
     * Contract ranges: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, and ::1.
     * Also refused: 0/8, ::, IPv6 link-local fe80::/10, IPv6 unique-local
     * fc00::/7, deprecated site-local fec0::/10, and IPv4 addresses embedded
     * in IPv6. Obfuscated spellings are decoded before this check.
     *
     * @param string $ip IP address.
     * @return bool
     */
    private static function ip_is_blocked($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::ipv4_packed_is_blocked($packed);
        }

        if (strlen($packed) === 16) {
            return self::ipv6_packed_is_blocked($packed);
        }

        return false;
    }

    /**
     * @param string $packed Four-byte IPv4.
     * @return bool
     */
    private static function ipv4_packed_is_blocked($packed)
    {
        $ranges = array(
            array('0.0.0.0', 8),
            array('10.0.0.0', 8),
            array('127.0.0.0', 8),
            array('169.254.0.0', 16),
            array('172.16.0.0', 12),
            array('192.168.0.0', 16),
        );

        foreach ($ranges as $range) {
            $network = inet_pton($range[0]);
            if (is_string($network) && self::packed_in_cidr($packed, $network, $range[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $packed Sixteen-byte IPv6.
     * @return bool
     */
    private static function ipv6_packed_is_blocked($packed)
    {
        $loopback = inet_pton('::1');
        $unspecified = inet_pton('::');
        if ($packed === $loopback || $packed === $unspecified) {
            return true;
        }

        $embedded = self::embedded_ipv4($packed);
        if ($embedded !== '') {
            $v4 = inet_pton($embedded);
            return is_string($v4) && self::ipv4_packed_is_blocked($v4);
        }

        $local_ranges = array(
            array('fe80::', 10),
            array('fc00::', 7),
            array('fec0::', 10),
        );
        foreach ($local_ranges as $range) {
            $network = inet_pton($range[0]);
            if (is_string($network) && self::packed_in_cidr($packed, $network, $range[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv4 address embedded in an IPv4-mapped or IPv4-compatible IPv6 address.
     *
     * @param string $packed Sixteen-byte IPv6.
     * @return string Dotted IPv4, or an empty string.
     */
    private static function embedded_ipv4($packed)
    {
        if (strlen($packed) !== 16) {
            return '';
        }

        $prefix = substr($packed, 0, 12);
        $mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        $compatible = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        if ($prefix !== $mapped && $prefix !== $compatible) {
            return '';
        }

        $v4 = inet_ntop(substr($packed, 12, 4));
        if (!is_string($v4)) {
            return '';
        }

        return $v4;
    }

    /**
     * True when a packed address sits inside a CIDR block of the same family.
     *
     * @param string $packed  Address bytes.
     * @param string $network Network bytes.
     * @param int    $bits    Prefix length.
     * @return bool
     */
    private static function packed_in_cidr($packed, $network, $bits)
    {
        if (strlen($packed) !== strlen($network) || $bits < 0) {
            return false;
        }

        $max_bits = strlen($packed) * 8;
        if ($bits > $max_bits) {
            return false;
        }

        $bytes = (int) ($bits / 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * Punycode a Unicode host when intl is available.
     *
     * @param string $host Normalized host.
     * @return string
     */
    private static function ascii_host($host)
    {
        if ($host === '' || !function_exists('idn_to_ascii')) {
            return $host;
        }
        if (!preg_match('/[^\x20-\x7e]/', $host)) {
            return $host;
        }

        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        if (PHP_VERSION_ID >= 80000) {
            $ascii = idn_to_ascii($host, $flags);
        } elseif (defined('INTL_IDNA_VARIANT_UTS46')) {
            $ascii = idn_to_ascii($host, $flags, INTL_IDNA_VARIANT_UTS46);
        } else {
            $ascii = idn_to_ascii($host);
        }

        if (!is_string($ascii) || $ascii === '') {
            return $host;
        }

        return strtolower($ascii);
    }

    /**
     * Rebuild an http(s) URL without userinfo or a fragment.
     *
     * @param string $scheme http or https.
     * @param array  $parts  parse_url parts.
     * @return string Empty when the port is unusable.
     */
    private static function rebuild_url($scheme, array $parts)
    {
        // empty("0") is true in PHP, but "0" is a real host (0.0.0.0).
        if (!isset($parts['host']) || (string) $parts['host'] === '') {
            return '';
        }

        $host = self::format_host((string) $parts['host']);
        $url = $scheme . '://' . $host;

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return '';
            }
            $url .= ':' . $port;
        }

        if (isset($parts['path'])) {
            $url .= (string) $parts['path'];
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . (string) $parts['query'];
        }

        return $url;
    }

    /**
     * Keep brackets around an IPv6 host.
     *
     * @param string $host Host from parse_url.
     * @return string
     */
    private static function format_host($host)
    {
        if (strpos($host, ':') !== false && $host !== '' && $host[0] !== '[') {
            return '[' . $host . ']';
        }

        return $host;
    }

    /**
     * Drop userinfo and fragments so a password is not echoed or stored.
     *
     * @param string $url Raw URL.
     * @return string
     */
    private static function redact($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || !isset($parts['host']) || (string) $parts['host'] === '') {
            return $url;
        }
        if (!isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])) {
            return $url;
        }

        $clean = self::rebuild_url(strtolower((string) $parts['scheme']), $parts);
        return $clean !== '' ? $clean : $url;
    }

    /**
     * Treat a pasted host, such as example.com, as https.
     *
     * A scheme that is already present is left alone, including ftp and
     * javascript. Words that are not a host stay unchanged.
     *
     * @param string $url Trimmed address.
     * @return string
     */
    private static function with_https_scheme($url)
    {
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }
        // javascript: and data: are schemes. user:pass@host is not.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^[^/@]+:[^/@]*@#', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url)) {
            return $url;
        }

        $host = $url;
        $slash = strpos($host, '/');
        if ($slash !== false) {
            $host = substr($host, 0, $slash);
        }
        $at = strrpos($host, '@');
        if ($at !== false) {
            $host = substr($host, $at + 1);
        }
        if ($host !== '' && $host[0] !== '[') {
            $colon = strpos($host, ':');
            if ($colon !== false) {
                $host = substr($host, 0, $colon);
            }
        }

        $host = strtolower(rtrim($host, '.'));
        $looks_like_host = strpos($host, '.') !== false
            || $host === 'localhost'
            || (strlen($host) >= 2 && $host[0] === '[');
        if (!$looks_like_host && preg_match('/^(0x[0-9a-f]+|[0-9]+)$/i', $host)) {
            $looks_like_host = true;
        }
        if (!$looks_like_host) {
            return $url;
        }

        return 'https://' . $url;
    }

    /**
     * Customer-facing sentence for a validation reason.
     *
     * @param string $reason Reason code.
     * @return string
     */
    private static function reason_message($reason)
    {
        switch ($reason) {
            case 'empty':
                return self::text('Enter the public address of the site you want to measure.');
            case 'long':
                return self::text('That address is too long to measure. Use the site\'s public home page.');
            case 'scheme':
                return self::text('Only a public http or https address can be measured.');
            case 'host':
                return self::text('That address has no host name, so it cannot be measured.');
            case 'private':
                return self::text('That address points at a private or local network, so it cannot be measured. Enter the public address visitors use.');
            case 'private-dns':
                return self::text('That address resolves to a private or local network, so it cannot be measured. Enter the public address visitors use.');
            default:
                return self::text('That address could not be read. Use a full http or https URL.');
        }
    }

    /**
     * Turn a transport failure into a sentence a site owner can act on.
     *
     * @param mixed $response Response from remote_get.
     * @return string Empty when the response is an HTTP message.
     */
    private static function transport_error($response)
    {
        if ($response === null) {
            return self::text('WordPress could not load the page because the HTTP API is unavailable.');
        }

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $message = $response->get_error_message();
            return self::friendly_transport_error(is_string($message) ? $message : '');
        }

        if (is_object($response) && method_exists($response, 'get_error_message')) {
            $message = $response->get_error_message();
            return self::friendly_transport_error(is_string($message) ? $message : '');
        }

        if (!is_array($response)) {
            return self::text('The site could not be reached. Check the address and try again.');
        }

        return '';
    }

    /**
     * Map cURL and WordPress transport text to a finished sentence.
     *
     * @param string $message Raw transport message.
     * @return string
     */
    private static function friendly_transport_error($message)
    {
        $text = strtolower($message);

        if ($text === '') {
            return self::text('The site could not be reached. Check the address and try again.');
        }
        if (
            strpos($text, 'timed out') !== false
            || strpos($text, 'timeout') !== false
            || strpos($text, 'curl error 28') !== false
        ) {
            return self::text('The site did not respond within 15 seconds. Check the address and try again.');
        }
        if (
            strpos($text, 'too many redirect') !== false
            || strpos($text, 'curl error 47') !== false
            || (strpos($text, 'redirect') !== false && strpos($text, 'maximum') !== false)
        ) {
            return self::text('The site redirected too many times, so the load time could not be measured.');
        }
        if (
            strpos($text, 'ssl') !== false
            || strpos($text, 'certificate') !== false
            || strpos($text, 'curl error 60') !== false
            || strpos($text, 'curl error 51') !== false
        ) {
            return self::text('The site\'s certificate could not be verified, so the page was not loaded.');
        }
        if (
            strpos($text, 'could not resolve') !== false
            || strpos($text, 'name or service not known') !== false
            || strpos($text, 'getaddrinfo') !== false
            || strpos($text, 'php_network_getaddresses') !== false
            || strpos($text, 'curl error 6') !== false
        ) {
            return self::text('The host name could not be found. Check the address and try again.');
        }
        if (
            strpos($text, 'connection refused') !== false
            || strpos($text, 'failed to connect') !== false
            || strpos($text, 'curl error 7') !== false
        ) {
            return self::text('The site refused the connection. Check the address and try again.');
        }
        if (strpos($text, 'blocked requests through http') !== false) {
            return self::text('WordPress is blocking requests to other sites, so the page could not be loaded.');
        }
        if (strpos($text, 'a valid url was not provided') !== false) {
            return self::text('Only a public http or https address can be measured.');
        }

        return self::text('The site could not be reached. Check the address and try again.');
    }

    /**
     * Sentence for an HTTP status that is not a usable page.
     *
     * @param int $code Status code.
     * @return string
     */
    private static function http_status_error($code)
    {
        $code = (int) $code;
        if ($code === 401) {
            return self::text('The site asked for a login, so the public page could not be measured.');
        }
        if ($code === 403) {
            return self::text('The site refused access to that page, so the load time could not be measured.');
        }
        if ($code === 404) {
            return self::text('That page was not found, so the load time could not be measured.');
        }
        if ($code >= 300 && $code <= 399) {
            return sprintf(
                self::status_template('The site redirected (HTTP %d) and did not return a page, so the load time could not be measured.'),
                $code
            );
        }
        if ($code >= 500 && $code <= 599) {
            return sprintf(
                self::status_template('The site reported an error (HTTP %d), so the load time could not be measured.'),
                $code
            );
        }

        return sprintf(
            self::status_template('The site returned HTTP %d, so the load time could not be measured.'),
            $code
        );
    }

    /**
     * Translate an sprintf template when WordPress is loaded.
     *
     * @param string $template Template with a %d placeholder.
     * @return string
     */
    private static function status_template($template)
    {
        if (function_exists('__')) {
            return __($template, 'migration-manager');
        }

        return $template;
    }

    /**
     * HTTP status from a WordPress response array.
     *
     * @param mixed $response Response.
     * @return int
     */
    private static function response_code($response)
    {
        if (function_exists('wp_remote_retrieve_response_code')) {
            return (int) wp_remote_retrieve_response_code($response);
        }
        if (is_array($response) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }

        return 0;
    }

    /**
     * Body from a WordPress response array.
     *
     * @param mixed $response Response.
     * @return string
     */
    private static function response_body($response)
    {
        if (function_exists('wp_remote_retrieve_body')) {
            $body = wp_remote_retrieve_body($response);
            return is_string($body) ? $body : '';
        }
        if (is_array($response) && isset($response['body']) && is_string($response['body'])) {
            return $response['body'];
        }

        return '';
    }

    /**
     * Scheme of a URL, or an empty string when it has none.
     *
     * @param string $url URL.
     * @return string
     */
    private static function url_scheme($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return '';
        }

        return strtolower((string) $parts['scheme']);
    }

    /**
     * Final URL after redirects, when the transport reports one.
     *
     * @param mixed $response Response.
     * @return string
     */
    private static function response_url($response)
    {
        if (!is_array($response)) {
            return '';
        }
        if (isset($response['url']) && is_string($response['url'])) {
            return $response['url'];
        }
        if (isset($response['http_response']) && is_object($response['http_response'])) {
            $http = $response['http_response'];
            if (method_exists($http, 'get_response_object')) {
                $raw = $http->get_response_object();
                if (is_object($raw) && isset($raw->url) && is_string($raw->url)) {
                    return $raw->url;
                }
            }
        }

        return '';
    }

    /**
     * Keep at most the first 2,000,000 bytes of HTML.
     *
     * @param string $html Response body.
     * @return string
     */
    private static function clip_html($html)
    {
        if (!is_string($html)) {
            return '';
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return substr($html, 0, self::MAX_HTML_BYTES);
        }

        return $html;
    }

    /**
     * Monotonic clock in nanoseconds.
     *
     * @return float|int
     */
    private static function now_ns()
    {
        if (function_exists('hrtime')) {
            $now = hrtime(true);
            if (is_int($now) || is_float($now)) {
                return $now;
            }
        }

        return microtime(true) * 1000000000;
    }

    /**
     * Milliseconds since a now_ns reading.
     *
     * @param float|int $start Start time.
     * @return int
     */
    private static function elapsed_ms($start)
    {
        $delta = self::now_ns() - $start;
        if ($delta < 0) {
            return 0;
        }

        $ms = (int) round($delta / 1000000);
        if ($ms < 0) {
            return 0;
        }

        return $ms;
    }

    /**
     * Translate when WordPress is loaded. Otherwise return the English sentence.
     *
     * @param string $message English message.
     * @return string
     */
    private static function text($message)
    {
        if (function_exists('__')) {
            return __($message, 'migration-manager');
        }

        return $message;
    }
}
