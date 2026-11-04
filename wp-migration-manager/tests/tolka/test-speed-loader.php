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
         * Every URL the fake transport was asked to fetch, in order.
         *
         * @var string[]
         */
        public static $requested_urls = array();

        /**
         * Responses consumed in order. When empty, $response is used.
         *
         * @var array
         */
        public static $queue = array();

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
            self::$requested_urls[] = $url;
            if (self::$delay_us > 0) {
                usleep(self::$delay_us);
            }
            if (self::$throw) {
                throw new \RuntimeException('transport failed');
            }
            if (self::$queue !== array()) {
                return array_shift(self::$queue);
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
            self::$requested_urls = array();
            self::$queue = array();
            self::$throw = false;
            self::$delay_us = 0;
        }
    }
}

/**
 * Case-insensitive header bag, like WordPress's HTTP header dictionary.
 */
class Speed_Loader_Header_Bag
{
    /**
     * @var array
     */
    private $items = array();

    /**
     * @param array $items Header names and values.
     */
    public function __construct(array $items)
    {
        foreach ($items as $key => $value) {
            $this->items[strtolower((string) $key)] = $value;
        }
    }

    /**
     * @param mixed $offset Header name.
     * @return mixed
     */
    public function offsetGet($offset)
    {
        $key = strtolower((string) $offset);
        return isset($this->items[$key]) ? $this->items[$key] : null;
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
class Speed_Loader_Test extends TestCase
{
    const PRIVATE_MESSAGE = 'That address is on a private or local network. Enter the public address visitors use.';

    const PRIVATE_DNS_MESSAGE = 'That address resolves to a private or local network. Enter the public address visitors use.';

    const SCHEME_MESSAGE = 'Only a public http or https address can be used.';

    const MALFORMED_MESSAGE = 'That address could not be read. Use a full http or https URL.';

    const HOST_MESSAGE = 'That address has no host name.';

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
        $file = (new \ReflectionClass(Speed_Loader::class))->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        $this->assertIsString($source);
        $this->assertStringNotContainsString('strict_types', $source);
        $this->assertStringContainsString('namespace WMM\\Tolka;', $source);
        $this->assertStringContainsString('function measure(string $url): array', $source);
        $this->assertStringContainsString('migration-manager', $source);
        $this->assertStringContainsString("'timeout' => self::TIMEOUT_SECONDS", $source);
        $this->assertStringContainsString("'redirection' => 0", $source);
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
        $this->assertSame(0, $args['redirection']);
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
            'decimal loopback no slash' => array('http://2130706433', $private),
            'hex loopback no slash' => array('http://0x7f000001', $private),
            'mapped unspecified' => array('http://[::ffff:0.0.0.0]/', $private),
            'mapped unspecified hex' => array('http://[::ffff:0:0]/', $private),
            'link local with port' => array('http://[fe80::1]:80/', $private),
            'ten no slash' => array('https://10.0.0.1', $private),
            'ipv6 loopback' => array('http://[::1]/', $private),
            'bare ipv6 loopback' => array('::1', $private),
            'unbracketed ipv6 loopback' => array('https://::1', $private),
            'bare link local v6' => array('fe80::1', $private),
            'unbracketed link local v6' => array('http://fe80::1/path', $private),
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

    /**
     * URLs the shared Tolka suite rejects. Keep this list in step with that check.
     */
    public function test_shared_suite_rejects_non_public_urls()
    {
        $urls = array(
            '',
            '   ',
            '/wp-admin',
            'ftp://example.com/file',
            'file:///etc/passwd',
            'http://localhost/',
            'http://localhost:8080/path',
            'HTTP://LOCALHOST/',
            'http://127.0.0.1/',
            'https://127.0.0.2/admin',
            'http://10.1.2.3/',
            'http://172.16.0.1/',
            'http://172.31.255.255/',
            'http://192.168.1.20/page',
            'http://169.254.1.1/',
            'http://[::1]/',
            'http://',
            'https://',
        );

        foreach ($urls as $url) {
            $result = Speed_Loader::measure($url);
            $this->assertFalse($result['ok'], $url);
            $this->assertSame('', $result['html'], $url);
            $this->assertNull($result['ttfb_ms'], $url);
            $this->assertNotSame('', $result['error'], $url);
            $this->assertSame(0, $result['total_ms'], $url);
        }
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
            '2606:4700:4700::1111',
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

        $result = Speed_Loader::measure('https://example.com/page#top');

        $this->assertStringNotContainsString('#', $result['url']);
    }

    public function test_userinfo_is_refused_before_any_request()
    {
        $this->requireHarness();
        $urls = array(
            'https://user:secret@example.com',
            'https://user:secret@example.com/admin',
            'https://user@example.com/path',
            'https://user:secret@1.1.1.1/admin#top',
            'user:secret@example.com/admin',
            'https://:secret@example.com/',
            'http://user:secret@127.0.0.1/admin',
        );

        foreach ($urls as $url) {
            Speed_Loader_Harness::reset();
            Speed_Loader_Harness::$resolved = array('1.1.1.1');
            Speed_Loader_Harness::$response = $this->http_response(200, '<html>secret page</html>');
            $result = Speed_Loader_Harness::measure($url);

            $this->assertFalse($result['ok'], $url);
            $this->assertSame('', $result['html'], $url);
            $this->assertSame(0, $result['html_bytes'], $url);
            $this->assertSame(0, $result['total_ms'], $url);
            $this->assertSame(0, $result['status_code'], $url);
            $this->assertSame(array(), Speed_Loader_Harness::$requested_urls, $url);
            $this->assertSame(
                'That address contains a username or password. Enter it again without a login.',
                $result['error'],
                $url
            );
            $this->assertMatchesRegularExpression('/\.$/', $result['error'], $url);
            $this->assertStringNotContainsString('secret', (string) json_encode($result), $url);
            $this->assertStringNotContainsString('@', $result['url'], $url);
        }
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

    public function test_a_name_that_only_looks_private_is_not_blocked()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('93.184.216.34');
        Speed_Loader_Harness::$response = $this->http_response(200, '<html>named</html>');

        $result = Speed_Loader_Harness::measure('https://192.168.0.1.example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame('<html>named</html>', $result['html']);
        $this->assertSame('https://192.168.0.1.example.com/', $result['url']);
    }

    public function test_string_status_and_non_string_body_do_not_fatal()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$response = array(
            'headers' => array(),
            'body' => null,
            'response' => array(
                'code' => '404',
                'message' => 'Not Found',
            ),
            'cookies' => array(),
            'filename' => null,
        );

        $result = Speed_Loader_Harness::measure('https://example.com/missing');

        $this->assertFailure($result);
        $this->assertSame(404, $result['status_code']);
        $this->assertSame('That page was not found.', $result['error']);
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
                'The site redirected too many times.',
            ),
            'curl 47' => array(
                'cURL error 47: Maximum (3) redirects followed',
                'The site redirected too many times.',
            ),
            'certificate' => array(
                'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
                'The site\'s certificate could not be verified.',
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
                'WordPress is blocking requests to other sites.',
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
            301 => 'The site redirected (HTTP 301) and did not return a page.',
            302 => 'The site redirected (HTTP 302) and did not return a page.',
            400 => 'The site returned HTTP 400.',
            401 => 'The site asked for a login.',
            403 => 'The site refused access to that page.',
            404 => 'That page was not found.',
            500 => 'The site reported an error (HTTP 500).',
            503 => 'The site reported an error (HTTP 503).',
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
        $this->assertLessThanOrEqual(2000000, strlen($result['html']));
        $this->assertSame(2000000, strlen($result['html']));
        $this->assertSame(2000050, $result['html_bytes']);
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

    /**
     * @dataProvider privateRedirectProvider
     * @param string $location Next hop that must not be fetched.
     */
    public function test_private_redirect_is_refused_before_it_is_fetched($location)
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '<html>secret hop</html>', $location),
            $this->http_response(200, '<html>should not load</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/start');

        $this->assertFailure($result);
        $this->assertSame(
            'The site redirected to a private or local address. Enter the public address visitors use.',
            $result['error'],
            $location
        );
        $this->assertSame('', $result['html'], $location);
        $this->assertSame(0, $result['html_bytes'], $location);
        $this->assertSame(302, $result['status_code'], $location);
        $this->assertGreaterThanOrEqual(0, $result['total_ms'], $location);
        $this->assertSame('https://example.com/start', $result['url'], $location);
        $this->assertSame(array('https://example.com/start'), Speed_Loader_Harness::$requested_urls, $location);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
        $this->assertStringNotContainsString('should not load', (string) json_encode($result));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateRedirectProvider(): array
    {
        return array(
            'loopback' => array('http://127.0.0.1/secret'),
            'loopback encoded' => array('http://127%2e0%2e0%2e1/secret'),
            'ten' => array('http://10.1.2.3/admin'),
            'lan' => array('http://192.168.1.9/a'),
            'private 172' => array('http://172.16.9.9/a'),
            'link local' => array('http://169.254.169.254/latest'),
            'localhost' => array('http://localhost/wp'),
            'ipv6 loopback' => array('http://[::1]/secret'),
            'ipv6 link local' => array('http://[fe80::1]/secret'),
            'protocol relative' => array('//127.0.0.1/hidden'),
            'unbracketed ipv6' => array('http://::1/secret'),
            'unspecified v4' => array('http://0.0.0.0/x'),
            'unspecified v6' => array('http://[::]/x'),
            'ten boundary' => array('http://10.255.255.255/'),
            'lan boundary' => array('http://192.168.255.255/'),
        );
    }

    public function test_private_page_from_a_followed_redirect_is_not_returned()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        $response = $this->http_response(200, '<html>secret private page</html>');
        $response['url'] = 'http://127.0.0.1/secret';
        Speed_Loader_Harness::$response = $response;

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame('', $result['html']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(array('https://example.com/'), Speed_Loader_Harness::$requested_urls);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_redirect_to_a_private_name_is_not_fetched()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('10.0.0.8');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(301, '<html>secret</html>', 'http://internal.example/admin'),
            $this->http_response(200, '<html>no</html>'),
        );

        $result = Speed_Loader_Harness::measure('http://1.1.1.1/start');

        $this->assertSame(
            'The site redirected to a private or local address. Enter the public address visitors use.',
            $result['error']
        );
        $this->assertSame('', $result['html']);
        $this->assertSame(301, $result['status_code']);
        $this->assertSame(array('http://1.1.1.1/start'), Speed_Loader_Harness::$requested_urls);
    }

    public function test_redirect_to_a_public_address_is_the_measured_url()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '<html>ignore</html>', 'https://www.example.com/home'),
            $this->http_response(200, '<html>home</html>'),
        );

        $result = Speed_Loader_Harness::measure('http://example.com/');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://www.example.com/home', $result['url']);
        $this->assertSame('<html>home</html>', $result['html']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame(
            array('http://example.com/', 'https://www.example.com/home'),
            Speed_Loader_Harness::$requested_urls
        );
        $this->assertLessThanOrEqual(2000000, strlen($result['html']));
    }

    public function test_relative_redirect_stays_on_the_public_host()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '', '/home'),
            $this->http_response(200, '<html>home</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/dir/page');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/home', $result['url']);
        $this->assertSame(
            array('https://example.com/dir/page', 'https://example.com/home'),
            Speed_Loader_Harness::$requested_urls
        );
    }

    public function test_redirect_to_a_non_http_scheme_is_refused()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '<html>file</html>', 'file:///etc/passwd'),
            $this->http_response(200, 'should not load'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFailure($result);
        $this->assertSame('The site redirected to an address that is not a public http or https page.', $result['error']);
        $this->assertSame('', $result['html']);
        $this->assertSame(302, $result['status_code']);
        $this->assertSame(array('https://example.com/'), Speed_Loader_Harness::$requested_urls);
        $this->assertStringNotContainsString('passwd', (string) json_encode($result));
    }

    public function test_redirect_credentials_are_not_echoed()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '', 'https://user:secret@www.example.com/home'),
            $this->http_response(200, '<html>home</html>'),
        );

        $result = Speed_Loader_Harness::measure('http://example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(302, $result['status_code']);
        $this->assertSame(
            'The site redirected to an address that contains a username or password.',
            $result['error']
        );
        $this->assertMatchesRegularExpression('/\.$/', $result['error']);
        $this->assertSame(array('http://example.com/'), Speed_Loader_Harness::$requested_urls);
        $this->assertStringNotContainsString('secret', (string) json_encode(Speed_Loader_Harness::$requested_urls));
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
        $this->assertStringNotContainsString('@', $result['url']);
    }

    public function test_fourth_redirect_is_not_fetched()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, 'one', 'https://example.com/b'),
            $this->http_response(302, 'two', 'https://example.com/c'),
            $this->http_response(302, 'three', 'https://example.com/d'),
            $this->http_response(302, 'four', 'https://example.com/e'),
            $this->http_response(200, 'five'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/a');

        $this->assertFailure($result);
        $this->assertSame('The site redirected too many times.', $result['error']);
        $this->assertMatchesRegularExpression('/\A[A-Z].+\.\z/', $result['error']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['html_bytes']);
        $this->assertSame(302, $result['status_code']);
        $this->assertStringNotContainsString('five', (string) json_encode($result));
        $this->assertSame(
            array(
                'https://example.com/a',
                'https://example.com/b',
                'https://example.com/c',
                'https://example.com/d',
            ),
            Speed_Loader_Harness::$requested_urls
        );
        $this->assertNotContains('https://example.com/e', Speed_Loader_Harness::$requested_urls);
    }

    public function test_parent_relative_redirect_stays_on_the_public_host()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '', '../home'),
            $this->http_response(200, '<html>home</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/dir/page');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/home', $result['url']);
        $this->assertSame(
            array('https://example.com/dir/page', 'https://example.com/home'),
            Speed_Loader_Harness::$requested_urls
        );
    }

    public function test_query_redirect_keeps_the_public_host()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '', '?lang=en'),
            $this->http_response(200, '<html>en</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/pricing');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/pricing?lang=en', $result['url']);
        $this->assertSame(
            array('https://example.com/pricing', 'https://example.com/pricing?lang=en'),
            Speed_Loader_Harness::$requested_urls
        );
    }

    public function test_case_insensitive_location_header_is_checked_first()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        $redirect = $this->http_response(302, '<html>secret</html>');
        $redirect['headers'] = new Speed_Loader_Header_Bag(array(
            'LoCaTiOn' => 'http://192.168.8.8/secret',
        ));
        Speed_Loader_Harness::$queue = array(
            $redirect,
            $this->http_response(200, '<html>no</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(302, $result['status_code']);
        $this->assertSame(array('https://example.com/'), Speed_Loader_Harness::$requested_urls);
    }

    public function test_location_header_list_is_read_before_the_next_hop()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        $redirect = $this->http_response(302, '<html>secret</html>');
        $redirect['headers']['Location'] = array('http://10.2.2.2/secret', 'https://example.com/later');
        Speed_Loader_Harness::$queue = array(
            $redirect,
            $this->http_response(200, '<html>later</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(302, $result['status_code']);
        $this->assertSame(array('https://example.com/'), Speed_Loader_Harness::$requested_urls);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_redirect_to_an_error_page_drops_that_html()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$delay_us = 80000;
        Speed_Loader_Harness::$queue = array(
            $this->http_response(302, '<html>hop</html>', 'https://example.com/missing'),
            $this->http_response(404, '<html>secret missing</html>'),
        );

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['html_bytes']);
        $this->assertSame(404, $result['status_code']);
        $this->assertGreaterThanOrEqual(50, $result['total_ms']);
        $this->assertSame(
            array('https://example.com/', 'https://example.com/missing'),
            Speed_Loader_Harness::$requested_urls
        );
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_statuses_from_200_through_299_keep_html()
    {
        $this->requireHarness();

        foreach (array(200, 201, 204, 299) as $code) {
            $body = ($code === 204) ? '' : '<html>ok</html>';
            Speed_Loader_Harness::reset();
            Speed_Loader_Harness::$resolved = array('1.1.1.1');
            Speed_Loader_Harness::$response = $this->http_response($code, $body);
            $result = Speed_Loader_Harness::measure('https://example.com/');

            $this->assertTrue($result['ok'], (string) $code);
            $this->assertSame($body, $result['html'], (string) $code);
            $this->assertSame($code, $result['status_code'], (string) $code);
            $this->assertLessThanOrEqual(2000000, strlen($result['html']), (string) $code);
            $this->assertSame('', $result['error'], (string) $code);
        }
    }

    public function test_status_outside_success_range_drops_html()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');

        foreach (array(100, 199, 300, 600) as $code) {
            Speed_Loader_Harness::reset();
            Speed_Loader_Harness::$resolved = array('1.1.1.1');
            Speed_Loader_Harness::$response = $this->http_response($code, '<html>secret</html>');
            $result = Speed_Loader_Harness::measure('https://example.com/');

            $this->assertFalse($result['ok'], (string) $code);
            $this->assertSame('', $result['html'], (string) $code);
            $this->assertSame($code, $result['status_code'], (string) $code);
            $this->assertGreaterThanOrEqual(0, $result['total_ms'], (string) $code);
            $this->assertStringNotContainsString('secret', (string) json_encode($result));
        }
    }

    public function test_error_status_keeps_timing_and_drops_html()
    {
        $this->requireHarness();
        Speed_Loader_Harness::$resolved = array('1.1.1.1');
        Speed_Loader_Harness::$delay_us = 80000;
        Speed_Loader_Harness::$response = $this->http_response(500, '<html>secret error page</html>');

        $result = Speed_Loader_Harness::measure('https://example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['html_bytes']);
        $this->assertSame(500, $result['status_code']);
        $this->assertGreaterThanOrEqual(50, $result['total_ms']);
        $this->assertNull($result['ttfb_ms']);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_curl_uses_the_addresses_already_checked()
    {
        $method = new \ReflectionMethod(Speed_Loader::class, 'curl_resolve_entries');
        $method->setAccessible(true);

        $this->assertSame(
            array('example.com:443:1.1.1.1'),
            $method->invoke(null, 'https://example.com/path', array('1.1.1.1'))
        );
        $this->assertSame(
            array('example.com:80:8.8.8.8'),
            $method->invoke(null, 'http://example.com/', array('8.8.8.8'))
        );
        $this->assertSame(
            array('example.com:8443:1.1.1.1'),
            $method->invoke(null, 'https://example.com:8443/path', array('1.1.1.1'))
        );
        $this->assertSame(
            array('example.com:443:[2606:4700:4700::1111]'),
            $method->invoke(null, 'https://example.com/', array('2606:4700:4700::1111'))
        );
        $this->assertSame(
            array('example.com:443:1.1.1.1'),
            $method->invoke(null, 'https://example.com/', array('1.1.1.1', '10.0.0.1', '127.0.0.1', ''))
        );
        $this->assertSame(
            array(),
            $method->invoke(null, 'https://1.1.1.1/', array('1.1.1.1'))
        );
        $this->assertSame(
            array(),
            $method->invoke(null, 'https://[2606:4700:4700::1111]/', array('2606:4700:4700::1111'))
        );
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
     * @param int    $code     Status code.
     * @param string $body     Body.
     * @param string $location Location header, when this hop redirects.
     * @return array
     */
    private function http_response($code, $body, $location = '')
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
        if ($location !== '') {
            $response['headers']['Location'] = $location;
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
