<?php

/**
 * Migration Manager - Tolka speed loader tests
 *
 * Rejects bad and private URLs, maps timeouts, and caps HTML.
 * No full WordPress load and no live network call.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$wmm_tolka_speed_loader_file = dirname(__DIR__, 2) . '/includes/tolka/class-speed-loader.php';
if (!class_exists(__NAMESPACE__ . '\\Speed_Loader', false) && is_readable($wmm_tolka_speed_loader_file)) {
    require_once $wmm_tolka_speed_loader_file;
}

if (class_exists(__NAMESPACE__ . '\\Speed_Loader', false)) {
    /**
     * Supplies a fake transport and DNS answer.
     */
    class Speed_Loader_Harness extends Speed_Loader
    {
        /**
         * Response returned by the fake transport.
         *
         * @var mixed
         */
        public static $response;

        /**
         * Addresses the fake resolver returns. Null uses the real resolver.
         *
         * @var array|null
         */
        public static $resolved;

        /**
         * Whether the fake transport was entered.
         *
         * @var bool
         */
        public static $requested = false;

        /**
         * URL passed to the fake transport.
         *
         * @var string
         */
        public static $requested_url = '';

        /**
         * Throw from the fake transport when true.
         *
         * @var bool
         */
        public static $throw = false;

        /**
         * Microseconds to pause inside the fake transport.
         *
         * @var int
         */
        public static $delay_us = 0;

        /**
         * @param string $url Validated URL.
         * @return mixed
         */
        protected static function remote_get($url)
        {
            self::$requested = true;
            self::$requested_url = $url;
            if (self::$delay_us > 0) {
                usleep(self::$delay_us);
            }
            if (self::$throw) {
                throw new \RuntimeException('transport failed');
            }

            return self::$response;
        }

        /**
         * @param string $host Host name.
         * @return string[]
         */
        protected static function resolve_ips($host)
        {
            if (is_array(self::$resolved)) {
                return self::$resolved;
            }

            return parent::resolve_ips($host);
        }

        /**
         * Reset fake transport state.
         */
        public static function reset()
        {
            self::$response = null;
            self::$resolved = array();
            self::$requested = false;
            self::$requested_url = '';
            self::$throw = false;
            self::$delay_us = 0;
        }
    }
}

/**
 * Transport failure stub. It is not a WordPress WP_Error.
 */
class Speed_Loader_Transport_Error
{
    /**
     * @var string
     */
    private $message;

    /**
     * @param string $message Raw transport message.
     */
    public function __construct($message)
    {
        $this->message = $message;
    }

    /**
     * @return string
     */
    public function get_error_message()
    {
        return $this->message;
    }
}

/**
 * Tests for Speed_Loader::measure.
 */
class Test_Speed_Loader extends TestCase
{
    const PRIVATE_MESSAGE = 'That address points at a private or local network, so it cannot be measured. Enter the public address visitors use.';

    const PRIVATE_DNS_MESSAGE = 'That address resolves to a private or local network, so it cannot be measured. Enter the public address visitors use.';

    const SCHEME_MESSAGE = 'Only a public http or https address can be measured.';

    const MALFORMED_MESSAGE = 'That address could not be read. Use a full http or https URL.';

    const HOST_MESSAGE = 'That address has no host name, so it cannot be measured.';

    const EMPTY_MESSAGE = 'Enter the public address of the site you want to measure.';

    const LONG_MESSAGE = 'That address is too long to measure. Use the site\'s public home page.';

    const HTTP_API_MESSAGE = 'WordPress could not load the page because the HTTP API is unavailable.';

    const TIMEOUT_MESSAGE = 'The site did not respond within 15 seconds. Check the address and try again.';

    const RESULT_KEYS = array(
        'ok',
        'url',
        'status_code',
        'total_ms',
        'ttfb_ms',
        'html_bytes',
        'html',
        'error',
    );

    protected function setUp(): void
    {
        if (!class_exists(Speed_Loader::class)) {
            $this->markTestSkipped('Speed_Loader is not loaded.');
        }
        if (class_exists(Speed_Loader_Harness::class)) {
            Speed_Loader_Harness::reset();
        }
    }

    public function test_source_matches_the_loader_contract()
    {
        $file = dirname(__DIR__, 2) . '/includes/tolka/class-speed-loader.php';
        $source = file_get_contents($file);

        $this->assertIsString($source);
        $this->assertStringNotContainsString('strict_types', $source);
        $this->assertStringContainsString('namespace WMM\\Tolka;', $source);
        $this->assertStringContainsString('function measure(string $url): array', $source);
        $this->assertStringContainsString('migration-manager', $source);
        $this->assertStringContainsString("'timeout' => self::TIMEOUT_SECONDS", $source);
        $this->assertStringContainsString("'redirection' => self::MAX_REDIRECTS", $source);
        $this->assertStringContainsString("'user-agent' => self::USER_AGENT", $source);
        $this->assertSame(15, Speed_Loader::TIMEOUT_SECONDS);
        $this->assertSame(3, Speed_Loader::MAX_REDIRECTS);
        $this->assertSame(2000000, Speed_Loader::MAX_HTML_BYTES);
        $this->assertSame('WMM-Tolka-Speed/1.0', Speed_Loader::USER_AGENT);
    }

    public function test_request_arguments_match_the_contract()
    {
        $method = new \ReflectionMethod(Speed_Loader::class, 'request_args');
        $method->setAccessible(true);
        $args = $method->invoke(null);

        $this->assertSame(15, $args['timeout']);
        $this->assertSame(3, $args['redirection']);
        $this->assertSame('WMM-Tolka-Speed/1.0', $args['user-agent']);
        $this->assertSame(2000000, $args['limit_response_size']);
    }

    /**
     * @dataProvider rejectedUrlProvider
     * @param string $url   Address that must not be fetched.
     * @param string $error Expected sentence.
     */
    public function test_rejected_urls_return_a_finished_error($url, $error)
    {
        $result = Speed_Loader::measure($url);

        $this->assertFailure($result);
        $this->assertSame($error, $result['error'], $url);
        $this->assertSame(0, $result['status_code'], $url);
        $this->assertSame(0, $result['total_ms'], $url);
        $this->assertStringNotContainsString('secret', strtolower((string) json_encode($result)));
    }

    /**
     * Addresses refused before a request, with the sentence the screen should show.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        $private = self::PRIVATE_MESSAGE;
        $scheme = self::SCHEME_MESSAGE;
        $malformed = self::MALFORMED_MESSAGE;
        $host = self::HOST_MESSAGE;

        return array(
            'empty' => array('', self::EMPTY_MESSAGE),
            'spaces' => array('   ', self::EMPTY_MESSAGE),
            'words' => array('not a url', $scheme),
            'single word' => array('notaurl', $scheme),
            'javascript' => array('javascript:alert(1)', $scheme),
            'data' => array('data:text/html,hi', $scheme),
            'file' => array('file:///etc/passwd', $scheme),
            'ftp' => array('ftp://example.com/file', $scheme),
            'gopher' => array('gopher://example.com/', $scheme),
            'http bare' => array('http://', $malformed),
            'https empty path' => array('https:///nope', $malformed),
            'bad port' => array('http://example.com:99999/', $malformed),
            'port zero' => array('http://1.1.1.1:0/', $malformed),
            'backslash' => array('http://example.com\\@1.1.1.1/', $malformed),
            'newline' => array("http://1.1.1.1/\npath", $malformed),
            'tab' => array("http://1.1.1.1/a\tb", $malformed),
            'space in path' => array('http://1.1.1.1/my page', $malformed),
            'dot host' => array('http://./', $host),
            'empty brackets' => array('http://[]/', $host),
            'localhost' => array('http://localhost/wp', $private),
            'bare localhost' => array('localhost', $private),
            'bare loopback' => array('127.0.0.1', $private),
            'protocol relative loopback' => array('//127.0.0.1/admin', $private),
            'bare ten' => array('10.1.2.3/wp', $private),
            'nbsp around loopback' => array("\u{00A0}http://127.0.0.1/\u{00A0}", $private),
            'zero width inside loopback' => array("https://127.0.0.\u{200B}1/", $private),
            'localhost pasted newline' => array("http://localhost/\n", $private),
            'loopback pasted spaces' => array('  http://127.0.0.1/  ', $private),
            'integer zero with port' => array('http://0:80/', $private),
            'ipv6 loopback no slash' => array('http://[::1]', $private),
            'localhost upper' => array('HTTP://LOCALHOST/wp', $private),
            'localhost dot' => array('http://localhost./', $private),
            'localhost fqdn' => array('http://localhost.localdomain/', $private),
            'star localhost' => array('https://foo.localhost/path', $private),
            'ip6 localhost' => array('http://ip6-localhost/', $private),
            'ip6 loopback' => array('http://ip6-loopback/', $private),
            'loopback' => array('http://127.0.0.1/', $private),
            'loopback end' => array('http://127.255.255.255/', $private),
            'loopback start' => array('https://127.0.0.0/wp-admin/', $private),
            'shorthand loopback' => array('http://127.1/', $private),
            'decimal loopback' => array('http://2130706433/', $private),
            'hex loopback' => array('http://0x7f000001/', $private),
            'octal loopback' => array('http://0177.0.0.1/', $private),
            'encoded loopback' => array('http://127%2e0%2e0%2e1/', $private),
            'double encoded loopback' => array('http://127%252e0%252e0%252e1/', $private),
            'hex dotted loopback' => array('http://0x7f.0x0.0x0.0x1/', $private),
            'octal shorthand private' => array('http://012.0.0.1/', $private),
            'octal private lan' => array('http://0300.0250.0.1/', $private),
            'nul loopback' => array('http://127.0.0.1%00.example.com/', $private),
            'userinfo loopback' => array('http://user:secret@127.0.0.1/admin', $private),
            'ten start' => array('http://10.0.0.0/', $private),
            'ten end' => array('http://10.255.255.255/', $private),
            'ten shorthand' => array('http://10.1/', $private),
            'ten port' => array('http://10.1.2.3:8080/wp', $private),
            'link local start' => array('http://169.254.0.0/', $private),
            'link local metadata' => array('http://169.254.169.254/latest/meta-data/', $private),
            'link local end' => array('http://169.254.255.255/', $private),
            'private 172 start' => array('http://172.16.0.0/', $private),
            'private 172 mid' => array('http://172.24.1.1/', $private),
            'private 172 end' => array('http://172.31.255.255/', $private),
            'private 172 shorthand' => array('http://172.16.5/', $private),
            'lan start' => array('http://192.168.0.0/', $private),
            'lan typical' => array('http://192.168.1.20/blog/', $private),
            'lan end' => array('http://192.168.255.255/', $private),
            'lan shorthand' => array('http://192.168.1/', $private),
            'padded lan' => array('http://192.168.001.001/', $private),
            'this network' => array('http://0.0.0.0/', $private),
            'this network end' => array('http://0.255.255.255/', $private),
            'integer zero' => array('http://0/', $private),
            'ipv6 loopback' => array('http://[::1]/', $private),
            'ipv6 loopback expanded' => array('http://[0:0:0:0:0:0:0:1]/', $private),
            'ipv6 unspecified' => array('http://[::]/', $private),
            'ipv6 mapped loopback' => array('http://[::ffff:127.0.0.1]/', $private),
            'ipv6 mapped ten' => array('http://[::ffff:10.1.2.3]/', $private),
            'ipv6 mapped lan' => array('http://[::ffff:192.168.0.1]/', $private),
            'ipv6 mapped 172' => array('http://[::ffff:172.16.0.1]/', $private),
            'ipv6 mapped metadata' => array('http://[::ffff:169.254.169.254]/', $private),
            'ipv6 link local' => array('http://[fe80::1]/', $private),
            'ipv6 link local zone' => array('http://[fe80::1%25eth0]/', $private),
            'ipv6 link local top' => array('http://[febf::1]/', $private),
            'ipv6 unique local' => array('http://[fc00::1]/', $private),
            'ipv6 unique local fd' => array('http://[fd12:3456::1]/', $private),
            'ipv6 site local' => array('http://[fec0::1]/', $private),
            'invalid quad' => array('http://999.1.1.1/', $malformed),
            'bad octal' => array('http://08.8.8.8/', $malformed),
        );
    }

    public function test_private_literals_do_not_wait_on_the_network()
    {
        $started = microtime(true);
        foreach (self::rejectedUrlProvider() as $case) {
            $result = Speed_Loader::measure($case[0]);
            $this->assertFalse($result['ok'], $case[0]);
            $this->assertSame(0, $result['total_ms'], $case[0]);
            $this->assertSame('', $result['html'], $case[0]);
        }
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_long_address_is_refused()
    {
        $url = 'https://example.com/' . str_repeat('a', 5000);
        $result = Speed_Loader::measure($url);

        $this->assertFailure($result);
        $this->assertSame(self::LONG_MESSAGE, $result['error']);
        $this->assertSame(0, $result['total_ms']);
    }

    public function test_public_literals_are_not_treated_as_private()
    {
        if (function_exists('wp_remote_get')) {
            $this->markTestSkipped('WordPress HTTP API is loaded; this check would place a live request.');
        }

        $urls = array(
            'http://1.1.1.1/',
            'https://8.8.8.8/path',
            'http://9.255.255.255/',
            'http://11.0.0.1/',
            'http://126.255.255.255/',
            'http://128.0.0.1/',
            'http://169.253.1.1/',
            'http://169.255.1.1/',
            'http://172.15.255.255/',
            'http://172.32.0.1/',
            'http://192.167.1.1/',
            'http://192.169.1.1/',
            'http://1.2.3/',
            'http://8.8.8/',
            'http://134744072/',
            'http://0x08080808/',
            'http://0x8.0x8.0x8.0x8/',
            'http://010.0.0.1/',
            'http://255.255.255.255/',
            'http://[2606:4700:4700::1111]/',
            'http://[::ffff:8.8.8.8]/',
            'http://[2001:4860:4860::8888]/',
            'HTTPS://1.1.1.1/page',
        );

        foreach ($urls as $url) {
            $result = Speed_Loader::measure($url);
            $this->assertFailure($result);
            $this->assertSame(self::HTTP_API_MESSAGE, $result['error'], $url);
            $this->assertStringNotContainsString('private', strtolower($result['error']), $url);
            $this->assertNull($result['ttfb_ms'], $url);
        }
    }

    public function test_credentials_and_fragments_are_not_kept()
    {
        if (function_exists('wp_remote_get')) {
            $this->markTestSkipped('WordPress HTTP API is loaded; this check would place a live request.');
        }

        $pasted = Speed_Loader::measure("  http://1.1.1.1/ \n");
        $this->assertSame('http://1.1.1.1/', $pasted['url']);
        $this->assertSame(self::HTTP_API_MESSAGE, $pasted['error']);

        $result = Speed_Loader::measure('https://user:secret@1.1.1.1/admin#top');

        $this->assertSame('https://1.1.1.1/admin', $result['url']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $this->assertStringNotContainsString('#', $result['url']);
        $this->assertSame(self::HTTP_API_MESSAGE, $result['error']);
    }

    public function test_cleaned_url_is_what_would_be_fetched()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>ok</html>');

        $result = Speed_Loader_Harness::measure('HTTPS://Example.COM/path?x=1#section');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://Example.COM/path?x=1', $result['url']);
        $this->assertSame('https://Example.COM/path?x=1', Speed_Loader_Harness::$requested_url);
        $this->assertSame('<html>ok</html>', $result['html']);
        $this->assertSame(strlen('<html>ok</html>'), $result['html_bytes']);
        $this->assertSame('', $result['error']);
        $this->assertNull($result['ttfb_ms']);
        $this->assertSame(200, $result['status_code']);
        $this->assertGreaterThanOrEqual(0, $result['total_ms']);
    }

    public function test_name_that_resolves_privately_is_refused_before_the_request()
    {
        $this->requireHarness();
        $names = array(
            '10.0.0.1',
            '127.0.0.1',
            '192.168.1.1',
            '169.254.169.254',
            '::1',
            'fe80::1',
        );

        foreach ($names as $ip) {
            Speed_Loader_Harness::reset();
            Speed_Loader_Harness::$resolved = array($ip);
            Speed_Loader_Harness::$response = $this->http_response(200, '<html>secret body</html>');

            $result = Speed_Loader_Harness::measure('https://customer.example/');

            $this->assertFailure($result);
            $this->assertSame(self::PRIVATE_DNS_MESSAGE, $result['error'], $ip);
            $this->assertFalse(Speed_Loader_Harness::$requested, $ip);
            $this->assertSame(0, $result['total_ms'], $ip);
            $this->assertStringNotContainsString('secret', $result['html']);
        }
    }

    public function test_mixed_dns_answers_fail_closed()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1', '10.0.0.5');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>no</html>');

        $result = Speed_Loader_Harness::measure('http://cdn.example/');

        $this->assertSame(self::PRIVATE_DNS_MESSAGE, $result['error']);
        $this->assertFalse(Speed_Loader_Harness::$requested);
        $this->assertSame('', $result['html']);
    }

    public function test_public_dns_answer_is_measured()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('93.184.216.34', '2606:4700:4700::1111');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html lang="en"></html>');

        $result = Speed_Loader_Harness::measure('https://example.com./');

        $this->assertTrue($result['ok']);
        $this->assertTrue(Speed_Loader_Harness::$requested);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame('<html lang="en"></html>', $result['html']);
        $this->assertSame(strlen($result['html']), $result['html_bytes']);
    }

    public function test_unicode_host_is_measured_when_it_resolves_publicly()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(200, 'hello');

        $result = Speed_Loader_Harness::measure('https://bücher.example/');

        $this->assertTrue($result['ok']);
        $this->assertSame('hello', $result['html']);
        $this->assertNotSame('', Speed_Loader_Harness::$requested_url);
    }

    /**
     * @dataProvider transportErrorProvider
     * @param string $raw      Message from the HTTP transport.
     * @param string $expected Sentence shown to the site owner.
     */
    public function test_transport_failures_use_finished_sentences($raw, $expected)
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = new Speed_Loader_Transport_Error($raw);

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame($expected, $result['error'], $raw);
        $this->assertSame(0, $result['status_code']);
        $this->assertStringNotContainsString('cURL', $result['error']);
        $this->assertGreaterThanOrEqual(0, $result['total_ms']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function transportErrorProvider(): array
    {
        return array(
            'curl timeout' => array(
                'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received',
                self::TIMEOUT_MESSAGE,
            ),
            'timeout reached' => array(
                'Operation timed out',
                self::TIMEOUT_MESSAGE,
            ),
            'connection timed out' => array(
                'Connection timed out after 15000 milliseconds',
                self::TIMEOUT_MESSAGE,
            ),
            'too many redirects' => array(
                'Too many redirects',
                'The site redirected too many times, so the load time could not be measured.',
            ),
            'curl 47' => array(
                'cURL error 47: Maximum (3) redirects followed',
                'The site redirected too many times, so the load time could not be measured.',
            ),
            'certificate' => array(
                'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
                'The site\'s certificate could not be verified, so the page was not loaded.',
            ),
            'resolve' => array(
                'cURL error 6: Could not resolve host: example.com',
                'The host name could not be found. Check the address and try again.',
            ),
            'getaddrinfo' => array(
                'php_network_getaddresses: getaddrinfo failed: Name or service not known',
                'The host name could not be found. Check the address and try again.',
            ),
            'refused' => array(
                'cURL error 7: Failed to connect to example.com port 443: Connection refused',
                'The site refused the connection. Check the address and try again.',
            ),
            'blocked' => array(
                'User has blocked requests through HTTP.',
                'WordPress is blocking requests to other sites, so the page could not be loaded.',
            ),
            'invalid url from wordpress' => array(
                'A valid URL was not provided.',
                self::SCHEME_MESSAGE,
            ),
            'empty' => array(
                '',
                'The site could not be reached. Check the address and try again.',
            ),
            'unknown' => array(
                'something unexpected from the transport',
                'The site could not be reached. Check the address and try again.',
            ),
        );
    }

    public function test_timeout_records_the_request_time()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$delay_us = 100000;
        Speed_Loader_Harness::$response = new Speed_Loader_Transport_Error(
            'cURL error 28: Operation timed out after 15000 milliseconds'
        );

        $result = Speed_Loader_Harness::measure('http://1.1.1.1/');

        $this->assertFalse($result['ok']);
        $this->assertSame(self::TIMEOUT_MESSAGE, $result['error']);
        $this->assertSame('', $result['html']);
        $this->assertNull($result['ttfb_ms']);
        $this->assertGreaterThanOrEqual(50, $result['total_ms']);
        $this->assertLessThan(5000, $result['total_ms']);
    }

    public function test_transport_exception_is_not_fatal()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$throw = true;

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame('The site could not be reached. Check the address and try again.', $result['error']);
        $this->assertStringNotContainsString('transport failed', $result['error']);
    }

    public function test_http_errors_drop_the_body()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');

        $expected = array(
            301 => 'The site redirected (HTTP 301) and did not return a page, so the load time could not be measured.',
            302 => 'The site redirected (HTTP 302) and did not return a page, so the load time could not be measured.',
            400 => 'The site returned HTTP 400, so the load time could not be measured.',
            401 => 'The site asked for a login, so the public page could not be measured.',
            403 => 'The site refused access to that page, so the load time could not be measured.',
            404 => 'That page was not found, so the load time could not be measured.',
            500 => 'The site reported an error (HTTP 500), so the load time could not be measured.',
            503 => 'The site reported an error (HTTP 503), so the load time could not be measured.',
        );

        foreach ($expected as $code => $error) {
            Speed_Loader_Harness::$response = $this->http_response($code, '<html>secret error page</html>');
            $result = Speed_Loader_Harness::measure('https://example.com/missing');

            $this->assertFailure($result);
            $this->assertSame($code, $result['status_code'], (string) $code);
            $this->assertSame($error, $result['error'], (string) $code);
            $this->assertStringNotContainsString('secret', (string) json_encode($result));
        }
    }

    public function test_a_pasted_host_is_measured_as_https()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('93.184.216.34');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>home</html>');

        $cases = array(
            'example.com/about' => 'https://example.com/about',
            'www.example.com' => 'https://www.example.com',
            '//www.example.com/path?x=1#section' => 'https://www.example.com/path?x=1',
            'user:secret@example.com/admin' => 'https://example.com/admin',
            'example.com:8443/path' => 'https://example.com:8443/path',
            'example.com?utm=1' => 'https://example.com?utm=1',
            'example.com#pricing' => 'https://example.com',
            "\u{FEFF}example.com/home" => 'https://example.com/home',
        );

        foreach ($cases as $typed => $fetched) {
            Speed_Loader_Harness::reset();
            Speed_Loader_Harness::$resolved = array('93.184.216.34');
            Speed_Loader_Harness::$response = $this->http_response(200, '<html>home</html>');

            $result = Speed_Loader_Harness::measure($typed);

            $this->assertTrue($result['ok'], $typed);
            $this->assertSame($fetched, $result['url'], $typed);
            $this->assertSame($fetched, Speed_Loader_Harness::$requested_url, $typed);
            $this->assertStringNotContainsString('secret', (string) json_encode($result));
        }
    }

    public function test_empty_success_page_keeps_the_timing()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(204, '');

        $result = Speed_Loader_Harness::measure('https://example.com/empty');

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['html_bytes']);
        $this->assertSame('', $result['error']);
        $this->assertSame(204, $result['status_code']);
        $this->assertNull($result['ttfb_ms']);
    }

    public function test_html_is_capped_at_two_million_bytes()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        $body = str_repeat('a', 2000000) . str_repeat('b', 50);
        Speed_Loader_Harness::$response = $this->http_response(200, $body);

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame(2000000, strlen($result['html']));
        $this->assertSame(2000000, $result['html_bytes']);
        $this->assertSame(str_repeat('a', 2000000), $result['html']);
        $this->assertStringNotContainsString('b', $result['html']);
        $this->assertSame('', $result['error']);
    }

    public function test_html_at_the_cap_is_kept_whole()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        $body = str_repeat('c', 2000000);
        Speed_Loader_Harness::$response = $this->http_response(200, $body);

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame($body, $result['html']);
        $this->assertSame(2000000, $result['html_bytes']);
    }

    public function test_redirect_to_a_private_address_drops_the_body()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>private page</html>', 'http://127.0.0.1/secret');

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame(
            'The site redirected to a private or local address, so it cannot be measured. Enter the public address visitors use.',
            $result['error']
        );
        $this->assertSame('', $result['html']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame('https://example.com/', $result['url']);
        $this->assertStringNotContainsString('secret', json_encode($result));
    }

    public function test_redirect_to_a_public_address_is_the_measured_url()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>home</html>', 'https://www.example.com/home');

        $result = Speed_Loader_Harness::measure('http://example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://www.example.com/home', $result['url']);
        $this->assertSame('<html>home</html>', $result['html']);
        $this->assertSame(200, $result['status_code']);
    }

    public function test_redirect_to_a_non_http_scheme_is_refused()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>file</html>', 'file:///etc/passwd');

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame('The site redirected to an address that cannot be measured.', $result['error']);
        $this->assertSame('', $result['html']);
        $this->assertSame('https://example.com/', $result['url']);
        $this->assertStringNotContainsString('passwd', (string) json_encode($result));
    }

    public function test_redirect_credentials_are_not_echoed()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = $this->http_response(
            200,
            '<html>home</html>',
            'https://user:secret@www.example.com/home'
        );

        $result = Speed_Loader_Harness::measure('http://example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://www.example.com/home', $result['url']);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_missing_http_api_is_a_finished_error()
    {
        if (function_exists('wp_remote_get')) {
            $this->markTestSkipped('WordPress HTTP API is loaded.');
        }

        $result = Speed_Loader::measure('http://1.1.1.1/');

        $this->assertFailure($result);
        $this->assertSame(self::HTTP_API_MESSAGE, $result['error']);
        $this->assertSame('http://1.1.1.1/', $result['url']);
    }

    /**
     * @param array $result Measurement.
     */
    private function assertFailure(array $result)
    {
        $this->assertSame(self::RESULT_KEYS, array_keys($result));
        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['html_bytes']);
        $this->assertNull($result['ttfb_ms']);
        $this->assertIsInt($result['status_code']);
        $this->assertIsInt($result['total_ms']);
        $this->assertIsString($result['url']);
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertMatchesRegularExpression('/\.$/', $result['error']);
        $this->assertGreaterThanOrEqual(0, $result['total_ms']);
    }

    /**
     * @param int    $code Status code.
     * @param string $body Body.
     * @param string $url  Final URL, when the transport followed a redirect.
     * @return array
     */
    private function http_response($code, $body, $url = '')
    {
        $response = array(
            'headers' => array(),
            'body' => $body,
            'response' => array(
                'code' => $code,
                'message' => 'OK',
            ),
            'cookies' => array(),
            'filename' => null,
        );
        if ($url !== '') {
            $response['url'] = $url;
        }

        return $response;
    }

    private function requireHarness()
    {
        if (!class_exists(Speed_Loader_Harness::class)) {
            $this->markTestSkipped('Speed_Loader is not loaded.');
        }
    }
}
