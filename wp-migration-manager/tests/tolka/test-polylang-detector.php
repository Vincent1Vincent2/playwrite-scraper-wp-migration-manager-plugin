<?php
/**
 * Polylang detector checks.
 *
 * Does not define pll_languages_list(), PLL, or Polylang, and does not
 * define WordPress functions. Other tests in the same process stay isolated.
 *
 * @package WMM\Tolka
 */

$wmm_tolka_detector_file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-detector.php';
if (is_readable($wmm_tolka_detector_file)) {
    require_once $wmm_tolka_detector_file;
}

use PHPUnit\Framework\TestCase;

if (class_exists('WMM\\Tolka\\Polylang_Detector')) {
    class Polylang_Detector_Fixture extends WMM\Tolka\Polylang_Detector
    {
        public static $directories = array();

        public static $active = false;

        public static $listed = array();

        public static $use_loaded = false;

        public static $use_list = false;

        public static $origin = '';

        protected static function is_active(): bool
        {
            return (bool) static::$active;
        }

        protected static function use_loaded_plugin(): bool
        {
            return (bool) static::$use_loaded;
        }

        protected static function use_plugin_list(): bool
        {
            return (bool) static::$use_list;
        }

        protected static function plugin_directories(): array
        {
            return is_array(static::$directories) ? static::$directories : array();
        }

        protected static function loaded_origin_file(): string
        {
            return is_string(static::$origin) ? static::$origin : '';
        }

        protected static function raw_active_plugins(): array
        {
            return is_array(static::$listed) ? static::$listed : array();
        }

        public static function expose_rank(string $path): int
        {
            return static::rank_plugin_file($path);
        }

        public static function expose_file(string $path): array
        {
            return static::inspect_plugin_file($path);
        }

        public static function header_limit(): int
        {
            return static::header_byte_limit();
        }

        public static function expose_beside(string $plugin_file): array
        {
            return static::directories_beside_plugin($plugin_file);
        }
    }

    class Polylang_Detector_Warning_Fixture extends Polylang_Detector_Fixture
    {
        protected static function is_active(): bool
        {
            trigger_error('noisy detector', E_USER_WARNING);

            return false;
        }
    }

    class Polylang_Detector_Throwing_Fixture extends WMM\Tolka\Polylang_Detector
    {
        protected static function is_active(): bool
        {
            throw new RuntimeException('boom');
        }
    }

    class Polylang_Detector_Inspect_Throwing_Fixture extends WMM\Tolka\Polylang_Detector
    {
        protected static function is_active(): bool
        {
            return true;
        }

        protected static function inspect(): array
        {
            throw new RuntimeException('disk failed');
        }
    }

    class Polylang_Detector_Api_Fixture extends WMM\Tolka\Polylang_Detector
    {
        public static $directories = array();

        protected static function use_loaded_plugin(): bool
        {
            return false;
        }

        protected static function use_plugin_list(): bool
        {
            return false;
        }

        protected static function plugin_directories(): array
        {
            return is_array(self::$directories) ? self::$directories : array();
        }
    }

    class Polylang_Detector_Read_Throws_Fixture extends Polylang_Detector_Api_Fixture
    {
        protected static function inspect(): array
        {
            throw new RuntimeException('header read failed');
        }
    }

    class Polylang_Detector_Reflect_Fixture extends Polylang_Detector_Api_Fixture
    {
        protected static function use_loaded_plugin(): bool
        {
            return true;
        }
    }
}

class Polylang_Detector_Test extends TestCase
{
    const INACTIVE_ABSENT = 'Polylang is not active. Install and activate Polylang, then return to Migrate to Tolka.';

    const INACTIVE_INSTALLED = 'Polylang %s is installed but not active. Activate Polylang, then return to Migrate to Tolka.';

    const INACTIVE_INSTALLED_PRO = 'Polylang Pro %s is installed but not active. Activate Polylang Pro, then return to Migrate to Tolka.';

    const ALSO_FREE = 'Polylang %s is also installed.';

    const ALSO_PRO = 'Polylang Pro %s is also installed.';

    const ALSO_MISSING_FREE = 'A Polylang plugin file was also found, but it has no readable Version header.';

    const ALSO_MISSING_PRO = 'A Polylang Pro plugin file was also found, but it has no readable Version header.';

    const INACTIVE_LISTED = 'Polylang %s is listed as active, but it is not loaded yet. Reload this screen. If Polylang is switched off, activate it, then return to Migrate to Tolka.';

    const INACTIVE_LISTED_PRO = 'Polylang Pro %s is listed as active, but it is not loaded yet. Reload this screen. If Polylang Pro is switched off, activate it, then return to Migrate to Tolka.';

    const INACTIVE_HEADER = 'A Polylang plugin file was found, but it has no readable Version header, and Polylang is not active. Activate Polylang, then return to Migrate to Tolka.';

    const INACTIVE_HEADER_PRO = 'A Polylang Pro plugin file was found, but it has no readable Version header, and Polylang Pro is not active. Activate Polylang Pro, then return to Migrate to Tolka.';

    const INACTIVE_LISTED_HEADER = 'Polylang is listed as active, but its plugin file has no readable Version header and Polylang is not loaded yet. Reload this screen. If Polylang is switched off, activate it, then return to Migrate to Tolka.';

    const ACTIVE_HEADER = 'Polylang is active, but its plugin file has no readable Version header. You can continue. The migration uses the Polylang data already loaded on this site.';

    const ACTIVE_MISSING = 'Polylang is active, but its plugin header could not be read. You can continue. The migration uses the Polylang data already loaded on this site.';

    const CHECK_FAILED = 'Polylang could not be checked on this site. Reload Migrate to Tolka and try again.';

    private $temp_directories = array();

    protected function setUp(): void
    {
        if (!class_exists('WMM\\Tolka\\Polylang_Detector') || !class_exists('Polylang_Detector_Fixture')) {
            $this->markTestSkipped('Polylang_Detector is not loaded.');
        }

        $this->reset_fixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->temp_directories as $directory) {
            $this->remove_directory($directory);
        }
        $this->temp_directories = array();
        if (class_exists('Polylang_Detector_Fixture')) {
            $this->reset_fixture();
        }
    }

    public function test_live_status_matches_the_contract(): void
    {
        $status = WMM\Tolka\Polylang_Detector::status();
        $loaded = function_exists('pll_languages_list') || class_exists('PLL') || class_exists('Polylang');

        $this->assertSame(array('active', 'version', 'errors'), array_keys($status));
        $this->assertSame($loaded, $status['active']);
        $this->assertIsString($status['version']);
        $this->assertMatchesRegularExpression('/^(?:|[0-9A-Za-z][0-9A-Za-z._+-]*)$/', $status['version']);
        $this->assertIsArray($status['errors']);
        foreach ($status['errors'] as $index => $error) {
            $this->assertIsInt($index);
            $this->assertIsString($error);
            $this->assertMatchesRegularExpression('/\.$/', $error);
            $this->assertStringNotContainsString('<', $error);
        }

        if (!$loaded && $status['version'] === '') {
            $this->assertSame(array(self::INACTIVE_ABSENT), $status['errors']);
        }
        if (!$loaded && $status['version'] !== '') {
            $this->assertNotEmpty($status['errors']);
            $this->assertStringContainsString($status['version'], $status['errors'][0]);
        }
        if ($loaded && $status['version'] !== '') {
            $this->assertSame(array(), $status['errors']);
        }
        if ($loaded && $status['version'] === '') {
            $this->assertNotEmpty($status['errors']);
        }

        $this->assertSame($status, WMM\Tolka\Polylang_Detector::status());
    }

    public function test_polylang_inactive_returns_a_clear_error(): void
    {
        if (function_exists('pll_languages_list') || class_exists('PLL', false) || class_exists('Polylang', false)) {
            $this->markTestSkipped('Polylang is loaded in this process.');
        }

        $status = WMM\Tolka\Polylang_Detector::status();

        $this->assertFalse($status['active']);
        $this->assertIsString($status['version']);
        $this->assertNotEmpty($status['errors']);
        foreach ($status['errors'] as $error) {
            $this->assertIsString($error);
            $this->assertMatchesRegularExpression('/\.$/', $error);
            $this->assertStringNotContainsString('<', $error);
        }
        $this->assertSame($status, WMM\Tolka\Polylang_Detector::status());
    }

    public function test_inactive_without_plugin_asks_the_admin_to_install(): void
    {
        Polylang_Detector_Fixture::$directories = array($this->temp_dir());

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_ABSENT),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_inactive_with_version_asks_the_admin_to_activate(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang/polylang.php',
            $this->wordpress_header('Polylang', '3.6.4')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame(
            array(
                'active' => false,
                'version' => '3.6.4',
                'errors' => array(sprintf(self::INACTIVE_INSTALLED, '3.6.4')),
            ),
            $status
        );
        $this->assertStringNotContainsString($root, $status['errors'][0]);
    }

    public function test_inactive_missing_version_header_explains_the_gap(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang/polylang.php',
            "<?php\n/**\n * Plugin Name: Polylang\n * Description: Header without a version.\n */\n"
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_HEADER),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_malformed_version_is_not_shown(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang/polylang.php',
            $this->wordpress_header('Polylang', 'not a version')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('', $status['version']);
        $this->assertSame(array(self::INACTIVE_HEADER), $status['errors']);
    }

    public function test_active_with_version_has_no_errors(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang/polylang.php',
            $this->wordpress_header('Polylang', '3.6.4')
        );
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$active = true;

        $this->assertSame(
            array(
                'active' => true,
                'version' => '3.6.4',
                'errors' => array(),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_active_missing_version_header_lets_the_admin_continue(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', "<?php\n");
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$active = true;

        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_HEADER),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_active_without_a_plugin_file_explains_the_missing_header(): void
    {
        Polylang_Detector_Fixture::$directories = array($this->temp_dir());
        Polylang_Detector_Fixture::$active = true;

        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_MISSING),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_plugin_header_with_no_version_line(): void
    {
        $root = $this->temp_dir();
        $contents = $this->header_without_version('Polylang');
        $this->assertDoesNotMatchRegularExpression('/^[ \t\/*#@]*Version:/m', $contents);
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_HEADER),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_both_free_and_pro_copies_present(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.1'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame(
            array(
                'active' => false,
                'version' => '3.6.4',
                'errors' => array(
                    'Polylang 3.6.4 is installed but not active. Polylang Pro 3.7.1 is also installed. Activate Polylang, then return to Migrate to Tolka.',
                ),
            ),
            $status
        );
    }

    public function test_both_copies_when_free_header_has_no_version_line(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->header_without_version('Polylang'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.1'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertFalse($status['active']);
        $this->assertSame('3.7.1', $status['version']);
        $this->assertSame(
            array('Polylang Pro 3.7.1 is installed but not active. A Polylang plugin file was also found, but it has no readable Version header. Activate Polylang Pro, then return to Migrate to Tolka.'),
            $status['errors']
        );
    }

    public function test_active_with_both_copies_has_no_errors(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.1'));
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$active = true;

        $this->assertSame(
            array(
                'active' => true,
                'version' => '3.6.4',
                'errors' => array(),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_free_copy_mentions_a_pro_header_with_no_version_line(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->header_without_version('Polylang Pro'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.6.4', $status['version']);
        $this->assertSame(
            array($this->expect_with_note(sprintf(self::INACTIVE_INSTALLED, '3.6.4'), self::ALSO_MISSING_PRO)),
            $status['errors']
        );
    }

    public function test_both_copies_without_version_lines(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->header_without_version('Polylang'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->header_without_version('Polylang Pro'));
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array('A Polylang plugin file was found, but it has no readable Version header, and Polylang is not active. A Polylang Pro plugin file was also found, but it has no readable Version header. Activate Polylang, then return to Migrate to Tolka.'),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_free_copy_is_preferred_when_neither_copy_is_active(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.0'));
        $this->write_plugin(
            $root,
            'polylang/index.php',
            $this->wordpress_header('Polylang', '0.0.1')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.6.4', $status['version']);
        $this->assertSame(
            array($this->expect_with_note(sprintf(self::INACTIVE_INSTALLED, '3.6.4'), sprintf(self::ALSO_PRO, '3.7.0'))),
            $status['errors']
        );
        $this->assertStringNotContainsString('0.0.1', $status['errors'][0]);
    }

    public function test_pro_copy_mentions_a_renamed_free_copy(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'custom-polylang/plugin.php',
            $this->wordpress_header('Polylang', '3.6.4')
        );
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.0'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.7.0', $status['version']);
        $this->assertSame(
            array($this->expect_with_note(sprintf(self::INACTIVE_INSTALLED_PRO, '3.7.0'), sprintf(self::ALSO_FREE, '3.6.4'))),
            $status['errors']
        );
    }

    public function test_pro_only_install_uses_the_pro_header(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.0'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertFalse($status['active']);
        $this->assertSame('3.7.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_INSTALLED_PRO, '3.7.0')), $status['errors']);
    }

    public function test_pro_file_without_version_names_polylang_pro(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang-pro/polylang.php',
            "<?php\n/**\n * Plugin Name: Polylang Pro\n * Description: Header without a version.\n */\n"
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_HEADER_PRO),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_windows_plugin_path_lists_the_pro_copy(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.0.0'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '4.2.0'));
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('polylang-pro\\polylang.php');

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('4.2.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_LISTED_PRO, '4.2.0')), $status['errors']);
    }

    public function test_parent_directory_segment_is_not_treated_as_active(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'plugins/polylang/polylang.php', $this->wordpress_header('Polylang', '3.1.0'));
        Polylang_Detector_Fixture::$directories = array($root . '/plugins');
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('../polylang/polylang.php');

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.1.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_INSTALLED, '3.1.0')), $status['errors']);
    }

    public function test_listed_pro_copy_outranks_an_inactive_free_copy(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.0.0'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '4.0.0'));
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('polylang-pro/polylang.php');

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('4.0.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_LISTED_PRO, '4.0.0')), $status['errors']);
    }

    public function test_listed_file_without_version_does_not_borrow_another_install(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', "<?php\n/**\n * Plugin Name: Polylang\n */\n");
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '9.9.9'));
        Polylang_Detector_Fixture::$directories = array($root);
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('polylang/polylang.php');

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_LISTED_HEADER),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_running_copy_wins_over_another_install(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'running/includes/api.php', "<?php\n");
        $this->write_plugin($root, 'running/polylang.php', $this->wordpress_header('Polylang', '1.4.0'));
        $this->write_plugin($root, 'other/polylang/polylang.php', $this->wordpress_header('Polylang', '9.9.9'));
        Polylang_Detector_Fixture::$directories = array($root . '/other');
        Polylang_Detector_Fixture::$active = true;
        Polylang_Detector_Fixture::$use_loaded = true;
        Polylang_Detector_Fixture::$origin = $root . '/running/includes/api.php';

        $this->assertSame(
            array(
                'active' => true,
                'version' => '1.4.0',
                'errors' => array(),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_running_copy_without_version_does_not_borrow_another_install(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'running/includes/api.php', "<?php\n");
        $this->write_plugin($root, 'running/polylang.php', "<?php\n/**\n * Plugin Name: Polylang\n */\n");
        $this->write_plugin($root, 'other/polylang/polylang.php', $this->wordpress_header('Polylang', '9.9.9'));
        Polylang_Detector_Fixture::$directories = array($root . '/other');
        Polylang_Detector_Fixture::$active = true;
        Polylang_Detector_Fixture::$use_loaded = true;
        Polylang_Detector_Fixture::$origin = $root . '/running/includes/api.php';

        $status = Polylang_Detector_Fixture::status();

        $this->assertTrue($status['active']);
        $this->assertSame('', $status['version']);
        $this->assertSame(array(self::ACTIVE_HEADER), $status['errors']);
    }

    public function test_header_accepts_bom_crlf_and_comment_closers(): void
    {
        $root = $this->temp_dir();
        $contents = "\xEF\xBB\xBF<?php\r\n"
            . "/**\r\n"
            . " * Plugin Name: Polylang\r\n"
            . " * Version: 1.2.3 */\r\n"
            . " */\r\n";
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('1.2.3', $status['version']);
    }

    public function test_version_past_the_header_window_is_missing(): void
    {
        $root = $this->temp_dir();
        $padding = str_repeat('*', Polylang_Detector_Fixture::header_limit() + 256);
        $contents = "<?php\n" . $padding . "\n * Plugin Name: Polylang\n * Version: 8.8.8\n";
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('', $status['version']);
        $this->assertSame(array(self::INACTIVE_HEADER), $status['errors']);
    }

    public function test_bootstrap_in_a_crowded_directory_is_still_read(): void
    {
        $root = $this->temp_dir();
        for ($index = 0; $index < 45; $index++) {
            $this->write_plugin(
                $root,
                'custom-polylang/aaa-' . sprintf('%02d', $index) . '.php',
                "<?php\n"
            );
        }
        $this->write_plugin(
            $root,
            'custom-polylang/plugin.php',
            $this->wordpress_header('Polylang', '3.9.1')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.9.1', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_folder_named_bootstrap_in_a_crowded_directory_is_still_read(): void
    {
        $root = $this->temp_dir();
        for ($index = 0; $index < 45; $index++) {
            $this->write_plugin(
                $root,
                'custom-polylang/aaa-' . sprintf('%02d', $index) . '.php',
                "<?php\n"
            );
        }
        $this->write_plugin(
            $root,
            'custom-polylang/custom-polylang.php',
            $this->wordpress_header('Polylang Pro', '3.9.2')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.9.2', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_custom_folder_with_polylang_pro_name(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'custom-polylang/plugin.php',
            $this->wordpress_header('Polylang Pro', '3.5.0')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.5.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_INSTALLED_PRO, '3.5.0')), $status['errors']);
    }

    public function test_must_use_plugin_file_is_read(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang.php', $this->wordpress_header('Polylang', '2.8.1'));
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('2.8.1', $status['version']);
    }

    public function test_listed_must_use_file_is_not_confused_with_the_nested_plugin(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'mu-plugins/polylang.php', $this->wordpress_header('Polylang', '2.2.2'));
        $this->write_plugin($root, 'plugins/polylang/polylang.php', $this->wordpress_header('Polylang', '3.3.3'));
        Polylang_Detector_Fixture::$directories = array(
            $root . '/mu-plugins',
            $root . '/plugins',
        );
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('./polylang.php');

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('2.2.2', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_LISTED, '2.2.2')), $status['errors']);
    }

    public function test_beta_and_release_candidate_versions_are_kept(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.7-RC1'));
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.7-RC1', Polylang_Detector_Fixture::status()['version']);

        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.7-beta1'));

        $this->assertSame('3.7-beta1', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_loaded_pro_header_is_polylang_php_not_a_stub(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang-pro/includes/api.php', "<?php\n");
        $this->write_plugin($root, 'polylang-pro/polylang-pro.php', $this->wordpress_header('Polylang Pro', '0.1.0'));
        $this->write_plugin($root, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '5.1.0'));
        Polylang_Detector_Fixture::$active = true;
        Polylang_Detector_Fixture::$use_loaded = true;
        Polylang_Detector_Fixture::$origin = $root . '/polylang-pro/includes/api.php';

        $this->assertSame('5.1.0', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_loaded_file_several_directories_down_still_finds_the_header(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'running/l1/l2/l3/l4/l5/l6/api.php', "<?php\n");
        $this->write_plugin($root, 'running/polylang.php', $this->wordpress_header('Polylang', '3.8.0'));
        Polylang_Detector_Fixture::$active = true;
        Polylang_Detector_Fixture::$use_loaded = true;
        Polylang_Detector_Fixture::$origin = $root . '/running/l1/l2/l3/l4/l5/l6/api.php';

        $this->assertSame('3.8.0', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_tab_separated_header_fields_are_read(): void
    {
        $root = $this->temp_dir();
        $contents = "<?php\n/**\n * Plugin Name:\tPolylang\n * Version:\t3.4.2\n */\n";
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.4.2', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_loaded_must_use_file_is_its_own_header(): void
    {
        $root = $this->temp_dir();
        $path = $this->write_plugin($root, 'mu-plugins/polylang.php', $this->wordpress_header('Polylang', '2.4.0'));
        Polylang_Detector_Fixture::$active = true;
        Polylang_Detector_Fixture::$use_loaded = true;
        Polylang_Detector_Fixture::$origin = $path;

        $this->assertSame(
            array(
                'active' => true,
                'version' => '2.4.0',
                'errors' => array(),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_official_filename_with_another_plugin_name_is_ignored(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Hello Dolly', '1.7.2'));
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_ABSENT),
            ),
            Polylang_Detector_Fixture::status()
        );
    }

    public function test_official_file_with_only_a_version_header_is_read(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang/polylang.php',
            "<?php\n/**\n * Version: 3.6.4\n */\n"
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.6.4', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_spaced_polylang_pro_name_is_accepted(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'custom-polylang/plugin.php',
            "<?php\n/**\n * Plugin Name: Polylang   Pro\n * Version: 3.5.1\n */\n"
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.5.1', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_backup_directory_with_dotdot_in_the_name_can_be_listed(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'plugins/polylang..backup/polylang.php',
            $this->wordpress_header('Polylang', '6.1.0')
        );
        Polylang_Detector_Fixture::$directories = array($root . '/plugins');
        Polylang_Detector_Fixture::$use_list = true;
        Polylang_Detector_Fixture::$listed = array('polylang..backup/polylang.php');

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('6.1.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_LISTED, '6.1.0')), $status['errors']);
    }

    public function test_first_version_header_wins(): void
    {
        $root = $this->temp_dir();
        $contents = "<?php\n"
            . "/**\n"
            . " * Plugin Name: Polylang\n"
            . " * Version: 1.0.0\n"
            . " * Version: 9.9.9\n"
            . " */\n";
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('1.0.0', Polylang_Detector_Fixture::status()['version']);
    }

    public function test_unrelated_plugin_is_ignored(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin($root, 'hello.php', $this->wordpress_header('Hello Dolly', '1.7.2'));
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_ABSENT),
            ),
            Polylang_Detector_Fixture::status()
        );
        $this->assertFalse(Polylang_Detector_Fixture::expose_file($root . '/hello.php')['is_polylang']);
    }

    public function test_missing_file_is_not_a_polylang_header(): void
    {
        $parsed = Polylang_Detector_Fixture::expose_file($this->temp_dir() . '/polylang/polylang.php');

        $this->assertSame(
            array(
                'is_polylang' => false,
                'version' => '',
            ),
            $parsed
        );
    }

    public function test_plugins_directory_also_checks_must_use_plugins(): void
    {
        $plugin_file = '/var/www/wp-content/plugins/wp-migration-manager/includes/tolka/class-polylang-detector.php';

        $this->assertSame(
            array(
                '/var/www/wp-content/plugins',
                '/var/www/wp-content/mu-plugins',
            ),
            Polylang_Detector_Fixture::expose_beside($plugin_file)
        );
    }

    public function test_must_use_plugin_also_checks_the_plugins_directory(): void
    {
        $plugin_file = '/var/www/wp-content/mu-plugins/wp-migration-manager/includes/tolka/class-polylang-detector.php';

        $this->assertSame(
            array(
                '/var/www/wp-content/mu-plugins',
                '/var/www/wp-content/plugins',
            ),
            Polylang_Detector_Fixture::expose_beside($plugin_file)
        );
    }

    public function test_unrelated_project_directory_does_not_guess_mu_plugins(): void
    {
        $plugin_file = '/agent/repos/project/wp-migration-manager/includes/tolka/class-polylang-detector.php';

        $this->assertSame(
            array('/agent/repos/project'),
            Polylang_Detector_Fixture::expose_beside($plugin_file)
        );
    }

    public function test_short_plugin_path_does_not_scan_the_filesystem_root(): void
    {
        $this->assertSame(array(), Polylang_Detector_Fixture::expose_beside('/class-polylang-detector.php'));
    }

    public function test_official_file_ranks(): void
    {
        $this->assertSame(0, Polylang_Detector_Fixture::expose_rank('/var/www/wp-content/plugins/polylang/polylang.php'));
        $this->assertSame(1, Polylang_Detector_Fixture::expose_rank('/var/www/wp-content/plugins/polylang-pro/polylang.php'));
        $this->assertSame(2, Polylang_Detector_Fixture::expose_rank('/var/www/wp-content/mu-plugins/polylang.php'));
        $this->assertSame(3, Polylang_Detector_Fixture::expose_rank('/var/www/wp-content/plugins/custom/polylang-pro.php'));
        $this->assertSame(4, Polylang_Detector_Fixture::expose_rank('/var/www/wp-content/plugins/custom-polylang/plugin.php'));
    }

    public function test_warning_during_the_check_stays_off_the_screen(): void
    {
        Polylang_Detector_Warning_Fixture::$directories = array($this->temp_dir());

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::INACTIVE_ABSENT),
            ),
            Polylang_Detector_Warning_Fixture::status()
        );
    }

    public function test_uppercase_pro_name_is_polylang_pro(): void
    {
        $root = $this->temp_dir();
        $this->write_plugin(
            $root,
            'polylang-pro/polylang.php',
            $this->wordpress_header('POLYLANG PRO', '3.2.0')
        );
        Polylang_Detector_Fixture::$directories = array($root);

        $status = Polylang_Detector_Fixture::status();

        $this->assertSame('3.2.0', $status['version']);
        $this->assertSame(array(sprintf(self::INACTIVE_INSTALLED_PRO, '3.2.0')), $status['errors']);
    }

    public function test_status_check_failure_is_a_finished_sentence(): void
    {
        $status = Polylang_Detector_Throwing_Fixture::status();

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::CHECK_FAILED),
            ),
            $status
        );
        $this->assertStringNotContainsString('boom', $status['errors'][0]);
    }

    public function test_header_read_throw_when_polylang_is_not_loaded(): void
    {
        if (function_exists('pll_languages_list') || class_exists('PLL', false) || class_exists('Polylang', false)) {
            $this->markTestSkipped('Polylang is loaded in this process.');
        }

        $status = Polylang_Detector_Read_Throws_Fixture::status();

        $this->assertSame(
            array(
                'active' => false,
                'version' => '',
                'errors' => array(self::CHECK_FAILED),
            ),
            $status
        );
        $this->assertMatchesRegularExpression('/\.$/', $status['errors'][0]);
        $this->assertStringNotContainsString('header read failed', $status['errors'][0]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_pll_languages_list_stays_active_when_the_header_cannot_be_read(): void
    {
        require_once $this->polylang_api_stub();

        Polylang_Detector_Api_Fixture::$directories = array($this->temp_dir());
        $status = Polylang_Detector_Api_Fixture::status();

        $this->assertTrue(function_exists('pll_languages_list'));
        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_MISSING),
            ),
            $status
        );

        $plain = $this->temp_dir();
        $this->write_plugin($plain, 'polylang/polylang.php', $this->header_without_version('Polylang'));
        Polylang_Detector_Api_Fixture::$directories = array($plain);
        $no_version = Polylang_Detector_Api_Fixture::status();

        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_HEADER),
            ),
            $no_version
        );

        $root = $this->temp_dir();
        $this->write_plugin($root, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        Polylang_Detector_Api_Fixture::$directories = array($root);
        $with_header = Polylang_Detector_Api_Fixture::status();

        $this->assertSame(
            array(
                'active' => true,
                'version' => '3.6.4',
                'errors' => array(),
            ),
            $with_header
        );

        $both = $this->temp_dir();
        $this->write_plugin($both, 'polylang/polylang.php', $this->wordpress_header('Polylang', '3.6.4'));
        $this->write_plugin($both, 'polylang-pro/polylang.php', $this->wordpress_header('Polylang Pro', '3.7.1'));
        Polylang_Detector_Api_Fixture::$directories = array($both);
        $both_status = Polylang_Detector_Api_Fixture::status();

        $this->assertSame(
            array(
                'active' => true,
                'version' => '3.6.4',
                'errors' => array(),
            ),
            $both_status
        );

        $thrown = Polylang_Detector_Read_Throws_Fixture::status();
        $this->assertTrue($thrown['active']);
        $this->assertSame('', $thrown['version']);
        $this->assertSame(array(self::ACTIVE_MISSING), $thrown['errors']);
        $this->assertStringNotContainsString('header read failed', $thrown['errors'][0]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_pll_class_stays_active_when_the_header_cannot_be_read(): void
    {
        require_once $this->pll_class_stub();

        Polylang_Detector_Api_Fixture::$directories = array($this->temp_dir());
        $status = Polylang_Detector_Api_Fixture::status();

        $this->assertTrue(class_exists('PLL', false));
        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_MISSING),
            ),
            $status
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_polylang_class_stays_active_when_the_header_cannot_be_read(): void
    {
        require_once $this->polylang_class_stub();

        $plain = $this->temp_dir();
        $this->write_plugin($plain, 'polylang/polylang.php', $this->header_without_version('Polylang'));
        Polylang_Detector_Api_Fixture::$directories = array($plain);
        $status = Polylang_Detector_Api_Fixture::status();

        $this->assertTrue(class_exists('Polylang', false));
        $this->assertFalse(function_exists('pll_languages_list'));
        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_HEADER),
            ),
            $status
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_loaded_class_header_wins_over_another_installed_copy(): void
    {
        $running = $this->temp_dir();
        $this->write_plugin($running, 'polylang.php', $this->wordpress_header('Polylang', '2.1.0'));
        $class_file = $running . '/include/pll-body.php';
        mkdir(dirname($class_file), 0777, true);
        file_put_contents($class_file, "<?php\nclass PLL\n{\n}\n");
        require_once $class_file;

        $other = $this->temp_dir();
        $this->write_plugin($other, 'polylang/polylang.php', $this->wordpress_header('Polylang', '9.9.9'));
        Polylang_Detector_Reflect_Fixture::$directories = array($other);

        $this->assertSame(
            array(
                'active' => true,
                'version' => '2.1.0',
                'errors' => array(),
            ),
            Polylang_Detector_Reflect_Fixture::status()
        );
    }

    public function test_header_read_failure_while_active_does_not_fatal(): void
    {
        $status = Polylang_Detector_Inspect_Throwing_Fixture::status();

        $this->assertSame(
            array(
                'active' => true,
                'version' => '',
                'errors' => array(self::ACTIVE_MISSING),
            ),
            $status
        );
        $this->assertStringNotContainsString('disk failed', $status['errors'][0]);
    }

    public function test_version_with_php_closer_is_trimmed(): void
    {
        $root = $this->temp_dir();
        $contents = "<?php\n"
            . "/*\n"
            . "Plugin Name: Polylang\n"
            . "Version: 3.0.0 ?>\n"
            . "*/\n";
        $this->write_plugin($root, 'polylang/polylang.php', $contents);
        Polylang_Detector_Fixture::$directories = array($root);

        $this->assertSame('3.0.0', Polylang_Detector_Fixture::status()['version']);
    }

    private function reset_fixture(): void
    {
        Polylang_Detector_Fixture::$directories = array();
        Polylang_Detector_Fixture::$active = false;
        Polylang_Detector_Fixture::$listed = array();
        Polylang_Detector_Fixture::$use_loaded = false;
        Polylang_Detector_Fixture::$use_list = false;
        Polylang_Detector_Fixture::$origin = '';
        if (class_exists('Polylang_Detector_Api_Fixture')) {
            Polylang_Detector_Api_Fixture::$directories = array();
        }
    }

    private function temp_dir(): string
    {
        $directory = sys_get_temp_dir() . '/wmm-tolka-detector-' . uniqid('', true);
        mkdir($directory, 0777, true);
        $this->temp_directories[] = $directory;

        return $directory;
    }

    private function write_plugin(string $directory, string $relative, string $contents): string
    {
        $path = $directory . '/' . $relative;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function polylang_api_stub(): string
    {
        $path = $this->temp_dir() . '/pll-api-stub.php';
        file_put_contents(
            $path,
            "<?php\nfunction pll_languages_list()\n{\n    throw new RuntimeException('pll_languages_list must not be called');\n}\n"
        );

        return $path;
    }

    private function pll_class_stub(): string
    {
        $path = $this->temp_dir() . '/pll-class-stub.php';
        file_put_contents($path, "<?php\nclass PLL\n{\n}\n");

        return $path;
    }

    private function polylang_class_stub(): string
    {
        $path = $this->temp_dir() . '/polylang-class-stub.php';
        file_put_contents($path, "<?php\nclass Polylang\n{\n}\n");

        return $path;
    }

    private function expect_with_note(string $message, string $note): string
    {
        $note = trim($note);
        foreach (array(' Activate ', ' Reload ') as $marker) {
            $position = strpos($message, $marker);
            if ($position !== false) {
                return substr($message, 0, $position) . ' ' . $note . substr($message, $position);
            }
        }

        return $message . ' ' . $note;
    }

    private function header_without_version(string $name): string
    {
        return "<?php\n"
            . "/**\n"
            . " * Plugin Name:       {$name}\n"
            . " * Plugin URI:        https://polylang.pro\n"
            . " * Description:       Adds multilingual capability. Version: see the readme.\n"
            . " * Requires at least: 6.2\n"
            . " * Requires PHP:      7.2\n"
            . " * Author:            WP SYNTEX\n"
            . " * Text Domain:       polylang\n"
            . " * License:           GPL v3 or later\n"
            . " */\n";
    }

    private function wordpress_header(string $name, string $version): string
    {
        return "<?php\n"
            . "/**\n"
            . " * Polylang\n"
            . " *\n"
            . " * @package           Polylang\n"
            . " * @author            WP SYNTEX\n"
            . " *\n"
            . " * @wordpress-plugin\n"
            . " * Plugin Name:       {$name}\n"
            . " * Plugin URI:        https://polylang.pro\n"
            . " * Description:       Adds multilingual capability to WordPress\n"
            . " * Version:           {$version}\n"
            . " * Requires at least: 6.2\n"
            . " * Requires PHP:      7.2\n"
            . " * Author:            WP SYNTEX\n"
            . " * Text Domain:       polylang\n"
            . " * License:           GPL v3 or later\n"
            . " */\n";
    }

    private function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
