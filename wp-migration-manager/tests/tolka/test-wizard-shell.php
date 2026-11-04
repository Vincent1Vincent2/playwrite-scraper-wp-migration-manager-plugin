<?php

/**
 * Wizard shell tests. WordPress is stubbed when it is not already loaded.
 *
 * PHPUnit 9 discovers this file by TestCase subclass. PHPUnit 10 and 11
 * match the class short name to the filename, and a hyphenated filename
 * cannot be a class name, so those runners need a loader that collects
 * TestCase subclasses.
 *
 * @package MigrationManager
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (!defined('MIGRATION_MANAGER_PLUGIN_DIR')) {
        define('MIGRATION_MANAGER_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
    }

    if (!defined('MIGRATION_MANAGER_PLUGIN_URL')) {
        define('MIGRATION_MANAGER_PLUGIN_URL', 'https://example.test/wp-content/plugins/wp-migration-manager/');
    }

    if (!defined('MIGRATION_MANAGER_VERSION')) {
        define('MIGRATION_MANAGER_VERSION', '1.0.0');
    }

    if (!function_exists('add_action')) {
        define('WMM_TOLKA_TEST_STUBS', true);

        function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
        {
            $GLOBALS['wmm_tolka_test_actions'][] = array(
                'hook' => $hook,
                'callback' => $callback,
                'priority' => $priority,
            );
            return true;
        }

        function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null)
        {
            $GLOBALS['wmm_tolka_test_menus'][] = array(
                'page_title' => $page_title,
                'menu_title' => $menu_title,
                'capability' => $capability,
                'menu_slug' => $menu_slug,
                'callback' => $callback,
                'icon_url' => $icon_url,
                'position' => $position,
            );
            return 'toplevel_page_' . $menu_slug;
        }

        function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all')
        {
            $GLOBALS['wmm_tolka_test_styles'][] = compact('handle', 'src', 'deps', 'ver', 'media');
        }

        function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false)
        {
            $GLOBALS['wmm_tolka_test_scripts'][] = compact('handle', 'src', 'deps', 'ver', 'in_footer');
        }

        function wp_localize_script($handle, $object_name, $l10n)
        {
            $GLOBALS['wmm_tolka_test_localized'][] = compact('handle', 'object_name', 'l10n');
            return true;
        }

        function admin_url($path = '', $scheme = 'admin')
        {
            return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
        }

        function wp_create_nonce($action = -1)
        {
            return 'test-nonce-' . $action;
        }

        function wp_verify_nonce($nonce, $action = -1)
        {
            return $action === 'wmm_tolka'
                && !empty($GLOBALS['wmm_tolka_test_nonce_valid'])
                && $nonce === 'good-nonce';
        }

        function current_user_can($capability)
        {
            if ($capability !== 'manage_options') {
                return false;
            }

            if (array_key_exists('wmm_tolka_test_can', $GLOBALS)) {
                return (bool) $GLOBALS['wmm_tolka_test_can'];
            }

            return true;
        }

        function sanitize_text_field($str)
        {
            return is_string($str) ? trim(strip_tags($str)) : '';
        }

        function wp_unslash($value)
        {
            return is_string($value) ? stripslashes($value) : $value;
        }

        function esc_url_raw($url, $protocols = null)
        {
            if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
                return '';
            }

            return $url;
        }

        function esc_url($url, $protocols = null, $context = 'display')
        {
            return is_string($url) ? $url : '';
        }

        function esc_html($text)
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }

        function esc_attr($text)
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }

        function __($text, $domain = 'default')
        {
            return $text;
        }

        function esc_html__($text, $domain = 'default')
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }

        function wp_send_json_error($response = null, $status_code = null, $flags = 0)
        {
            $GLOBALS['wmm_tolka_test_json'] = array(
                'success' => false,
                'data' => $response,
            );
            throw new RuntimeException('json_error');
        }

        function wp_send_json_success($response = null, $status_code = null, $flags = 0)
        {
            $GLOBALS['wmm_tolka_test_json'] = array(
                'success' => true,
                'data' => $response,
            );
            throw new RuntimeException('json_success');
        }

        function wp_die($message = '', $title = '', $args = array())
        {
            throw new RuntimeException('wp_die:' . (is_string($message) ? $message : ''));
        }

        function home_url($path = '', $scheme = null)
        {
            return 'https://public.example.test/';
        }

        function get_option($option, $default = false)
        {
            if ($option === 'wmm_tolka_last_report' && isset($GLOBALS['wmm_tolka_test_report'])) {
                return $GLOBALS['wmm_tolka_test_report'];
            }

            return $default;
        }

        function wp_json_encode($data, $options = 0, $depth = 512)
        {
            return json_encode($data, $options, $depth);
        }
    }

    if (!defined('WMM_TOLKA_BOOTSTRAP_LOADED') && defined('MIGRATION_MANAGER_PLUGIN_DIR')) {
        require_once MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/bootstrap.php';
    }
}

namespace WMM\Tolka {

    use PHPUnit\Framework\TestCase;

    class Test_Wizard_Shell extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            if (!defined('WMM_TOLKA_TEST_STUBS')) {
                return;
            }

            $GLOBALS['wmm_tolka_test_can'] = true;
            $GLOBALS['wmm_tolka_test_nonce_valid'] = false;
            $_POST = array();
            unset(
                $GLOBALS['wmm_tolka_test_json'],
                $GLOBALS['wmm_tolka_stub_throw'],
                $GLOBALS['wmm_tolka_test_report'],
                $GLOBALS['wmm_tolka_detector_throw'],
                $GLOBALS['wmm_tolka_detector_active'],
                $GLOBALS['wmm_tolka_detector_version'],
                $GLOBALS['wmm_tolka_detector_errors'],
                $GLOBALS['wmm_tolka_stub_bad_estimate'],
                $GLOBALS['wmm_tolka_stub_html'],
                $GLOBALS['wmm_tolka_stub_bad_shape'],
                $GLOBALS['wmm_tolka_stub_bad_report']
            );
        }

        public function test_main_plugin_requires_bootstrap_once()
        {
            $path = dirname(__DIR__, 2) . '/migration-manager.php';
            $main = file_get_contents($path);

            $this->assertIsString($main);
            $needle = "require_once MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/bootstrap.php';";
            $this->assertSame(1, substr_count($main, $needle));
            $this->assertSame(1, substr_count($main, 'includes/tolka/bootstrap.php'));
        }

        public function test_bootstrap_has_contract_hooks_and_copy()
        {
            $src = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/bootstrap.php');

            $this->assertIsString($src);
            $this->assertStringNotContainsString('strict_types', $src);
            $this->assertStringContainsString("add_action('wp_ajax_wmm_tolka_measure'", $src);
            $this->assertStringContainsString("add_action('wp_ajax_wmm_tolka_preview'", $src);
            $this->assertStringContainsString("add_action('wp_ajax_wmm_tolka_run'", $src);
            $this->assertStringContainsString("'actionMeasure' => 'wmm_tolka_measure'", $src);
            $this->assertStringContainsString("'actionPreview' => 'wmm_tolka_preview'", $src);
            $this->assertStringContainsString("'actionRun' => 'wmm_tolka_run'", $src);
            $this->assertStringContainsString('wmm-tolka-admin', $src);
            $this->assertStringContainsString("wp_create_nonce('wmm_tolka')", $src);
            $this->assertStringContainsString('Migrate to Tolka', $src);
            $this->assertStringContainsString('Nothing was changed.', $src);
            $this->assertStringContainsString('No measurement yet.', $src);
            $this->assertStringContainsString('class-*.php', $src);
        }

        public function test_hooks_menu_and_script_data()
        {
            $this->requireStubs();

            $hooks = array();
            foreach ($GLOBALS['wmm_tolka_test_actions'] as $action) {
                $hooks[] = $action['hook'];
            }

            $this->assertContains('admin_menu', $hooks);
            $this->assertContains('admin_enqueue_scripts', $hooks);
            $this->assertContains('wp_ajax_wmm_tolka_measure', $hooks);
            $this->assertContains('wp_ajax_wmm_tolka_preview', $hooks);
            $this->assertContains('wp_ajax_wmm_tolka_run', $hooks);
            $this->assertContains('wp_ajax_nopriv_wmm_tolka_measure', $hooks);
            $this->assertContains('wp_ajax_nopriv_wmm_tolka_preview', $hooks);
            $this->assertContains('wp_ajax_nopriv_wmm_tolka_run', $hooks);

            $GLOBALS['wmm_tolka_test_menus'] = array();
            \wmm_tolka_register_menu();
            $menu = $GLOBALS['wmm_tolka_test_menus'][0];
            $this->assertSame('Migrate to Tolka', $menu['menu_title']);
            $this->assertSame('manage_options', $menu['capability']);
            $this->assertSame('wmm-tolka', $menu['menu_slug']);
            $this->assertSame('toplevel_page_wmm-tolka', \wmm_tolka_menu_hook());

            $data = \wmm_tolka_script_data();
            $this->assertSame(
                array('ajaxUrl', 'nonce', 'actionMeasure', 'actionPreview', 'actionRun'),
                array_keys($data)
            );
            $this->assertSame('wmm_tolka_measure', $data['actionMeasure']);
            $this->assertSame('wmm_tolka_preview', $data['actionPreview']);
            $this->assertSame('wmm_tolka_run', $data['actionRun']);
            $this->assertSame('test-nonce-wmm_tolka', $data['nonce']);
            $this->assertStringContainsString('admin-ajax.php', $data['ajaxUrl']);
        }

        public function test_assets_enqueue_only_when_files_exist()
        {
            $this->requireStubs();
            \wmm_tolka_register_menu();

            $GLOBALS['wmm_tolka_test_styles'] = array();
            $GLOBALS['wmm_tolka_test_scripts'] = array();
            $GLOBALS['wmm_tolka_test_localized'] = array();

            \wmm_tolka_enqueue_assets('index.php');
            \wmm_tolka_enqueue_assets(\wmm_tolka_menu_hook());
            $this->assertSame(array(), $GLOBALS['wmm_tolka_test_styles']);
            $this->assertSame(array(), $GLOBALS['wmm_tolka_test_scripts']);

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/assets/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $css = $directory . 'tolka-admin.css';
            $js = $directory . 'tolka-admin.js';
            file_put_contents($css, '/* test */');
            file_put_contents($js, '/* test */');
            clearstatcache();

            try {
                \wmm_tolka_enqueue_assets(\wmm_tolka_menu_hook());
                $this->assertSame('wmm-tolka-admin', $GLOBALS['wmm_tolka_test_styles'][0]['handle']);
                $this->assertSame('wmm-tolka-admin', $GLOBALS['wmm_tolka_test_scripts'][0]['handle']);
                $this->assertSame('wmmTolka', $GLOBALS['wmm_tolka_test_localized'][0]['object_name']);
                $this->assertArrayNotHasKey('messages', $GLOBALS['wmm_tolka_test_localized'][0]['l10n']);
            } finally {
                unlink($css);
                unlink($js);
                clearstatcache();
            }
        }

        public function test_unavailable_names_missing_classes()
        {
            $this->requireStubs();

            $missing = \wmm_tolka_unavailable(array(
                'Not_A_Real_Slice' => array(
                    'class' => 'WMM\\Tolka\\Not_A_Real_Slice',
                    'methods' => array('status'),
                ),
            ));

            $this->assertSame(array('Not_A_Real_Slice'), $missing);
        }

        public function test_strip_html_keys_and_plain_values()
        {
            $this->requireStubs();

            $clean = \wmm_tolka_strip_html_keys(array(
                'html' => '<script>secret-html</script>',
                'estimate' => array(
                    'html' => '<script>secret-html</script>',
                    'ok' => true,
                    'basis' => 'Measured page load plus coefficients from benchmarks.json',
                ),
            ));

            $this->assertArrayNotHasKey('html', $clean);
            $this->assertArrayNotHasKey('html', $clean['estimate']);
            $this->assertTrue($clean['estimate']['ok']);
            $this->assertSame('Yes', \wmm_tolka_plain_value(true));
            $this->assertSame('Stored with the report.', \wmm_tolka_plain_value(array('package' => true)));
            $this->assertLessThan(410, strlen(\wmm_tolka_plain_value(str_repeat('a', 800))));

            $kept = \wmm_tolka_without_unusable_percent(array(
                'ok' => true,
                'faster_percent' => 10.0,
                'faster_label' => '10% faster',
            ));
            $this->assertSame(10.0, $kept['faster_percent']);
            $this->assertSame('10% faster', $kept['faster_label']);

            $dropped = \wmm_tolka_without_unusable_percent(array(
                'ok' => false,
                'faster_percent' => 99.9,
                'faster_label' => '99.9% faster',
                'error' => 'The page could not be measured.',
            ));
            $this->assertArrayNotHasKey('faster_percent', $dropped);
            $this->assertArrayNotHasKey('faster_label', $dropped);
            $this->assertSame('The page could not be measured.', $dropped['error']);
            $this->assertSame(
                'The page could not be measured.',
                \wmm_tolka_plain_value('The page could not be measured. <script>secret-html</script>')
            );
        }

        public function test_guard_rejects_bad_nonce_and_capability()
        {
            $this->requireStubs();

            $_POST = array('nonce' => 'bad');
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertStringContainsString('security check failed', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertArrayNotHasKey('missing', $GLOBALS['wmm_tolka_test_json']['data']);

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = false;
            $_POST = array('nonce' => 'good-nonce');
            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertStringContainsString('permission', $GLOBALS['wmm_tolka_test_json']['data']['message']);
        }

        public function test_alternate_nonce_field_is_accepted()
        {
            $this->requireStubs();

            if (class_exists(Speed_Loader::class)) {
                $this->markTestSkipped('Speed classes are already loaded.');
            }

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $_POST = array(
                '_ajax_nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });

            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertContains('Speed_Loader', $GLOBALS['wmm_tolka_test_json']['data']['missing']);
        }

        public function test_missing_slices_return_errors_without_output()
        {
            $this->requireStubs();

            if (
                class_exists(Speed_Loader::class)
                || class_exists(Migration_Runner::class)
                || class_exists(Migration_Report::class)
            ) {
                $this->markTestSkipped('Tolka classes are already loaded.');
            }

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $output = ob_get_clean();

            $this->assertSame('', $output);
            $this->assertSame(
                array('Speed_Loader', 'Speed_Metrics', 'Speed_Estimate'),
                $GLOBALS['wmm_tolka_test_json']['data']['missing']
            );
            $this->assertStringContainsString('Nothing was changed.', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Speed_Loader', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Speed_Metrics', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Speed_Estimate', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertSame(array('Migration_Runner'), $GLOBALS['wmm_tolka_test_json']['data']['missing']);
            $this->assertStringContainsString('Migration_Runner', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringNotContainsString('Migration_Report', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $this->assertSame(
                array('Migration_Runner', 'Migration_Report'),
                $GLOBALS['wmm_tolka_test_json']['data']['missing']
            );
            $this->assertStringContainsString('Migration_Runner', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Migration_Report', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Nothing was changed.', $GLOBALS['wmm_tolka_test_json']['data']['message']);
        }

        public function test_rejected_nonce_is_an_error_without_a_percent()
        {
            $this->requireStubs();

            $requests = array(
                array(),
                array('nonce' => ''),
                array('nonce' => 'rejected'),
                array('url' => 'https://public.example.test/'),
            );
            $handlers = array(
                'wmm_tolka_ajax_measure',
                'wmm_tolka_ajax_preview',
                'wmm_tolka_ajax_run',
            );

            foreach ($requests as $post) {
                foreach ($handlers as $handler) {
                    $GLOBALS['wmm_tolka_test_nonce_valid'] = false;
                    $GLOBALS['wmm_tolka_test_can'] = true;
                    $_POST = $post;
                    unset($GLOBALS['wmm_tolka_test_json']);

                    ob_start();
                    $this->capture(function () use ($handler) {
                        $handler();
                    });
                    $output = ob_get_clean();

                    $this->assertSame('', $output);
                    $payload = $GLOBALS['wmm_tolka_test_json'];
                    $this->assertFalse($payload['success']);
                    $this->assertStringContainsString('security check failed', $payload['data']['message']);
                    $this->assertArrayNotHasKey('estimate', $payload['data']);
                    $this->assertArrayNotHasKey('missing', $payload['data']);
                    $this->assertNoSpeedPercent($payload);
                }
            }
        }

        public function test_user_without_manage_options_is_rejected()
        {
            $this->requireStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = false;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            foreach (array('wmm_tolka_ajax_measure', 'wmm_tolka_ajax_preview', 'wmm_tolka_ajax_run') as $handler) {
                unset($GLOBALS['wmm_tolka_test_json']);
                ob_start();
                $this->capture(function () use ($handler) {
                    $handler();
                });
                $output = ob_get_clean();

                $this->assertSame('', $output);
                $payload = $GLOBALS['wmm_tolka_test_json'];
                $this->assertFalse($payload['success']);
                $this->assertStringContainsString('permission', $payload['data']['message']);
                $this->assertStringContainsString('do not have permission', $payload['data']['message']);
                $this->assertArrayNotHasKey('estimate', $payload['data']);
                $this->assertArrayNotHasKey('missing', $payload['data']);
                $this->assertNoSpeedPercent($payload);
            }
        }

        public function test_measure_without_speed_classes_returns_no_percent()
        {
            $this->requireStubs();

            if (
                class_exists(Speed_Loader::class)
                || class_exists(Speed_Metrics::class)
                || class_exists(Speed_Estimate::class)
            ) {
                $this->markTestSkipped('Speed classes are already loaded.');
            }

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $output = ob_get_clean();

            $this->assertSame('', $output);
            $payload = $GLOBALS['wmm_tolka_test_json'];
            $this->assertFalse($payload['success']);
            $this->assertSame(
                array('Speed_Loader', 'Speed_Metrics', 'Speed_Estimate'),
                $payload['data']['missing']
            );
            $this->assertArrayNotHasKey('estimate', $payload['data']);
            $this->assertArrayNotHasKey('metrics', $payload['data']);
            $this->assertArrayNotHasKey('faster_percent', $payload['data']);
            $this->assertArrayNotHasKey('faster_label', $payload['data']);
            $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);
            $this->assertStringContainsString('Speed_Loader', $payload['data']['message']);
            $this->assertStringContainsString('Speed_Metrics', $payload['data']['message']);
            $this->assertStringContainsString('Speed_Estimate', $payload['data']['message']);
            $this->assertNoSpeedPercent($payload);
        }

        public function test_fallback_screen_has_empty_states_and_required_ids()
        {
            $this->requireStubs();

            $html = $this->render();
            $ids = array(
                'wmm-tolka-wizard',
                'wmm-tolka-url',
                'wmm-tolka-measure',
                'wmm-tolka-preview',
                'wmm-tolka-run',
                'wmm-tolka-speed',
                'wmm-tolka-current-load',
                'wmm-tolka-tolka-load',
                'wmm-tolka-faster',
                'wmm-tolka-basis',
                'wmm-tolka-provenance-note',
                'wmm-tolka-preview-result',
                'wmm-tolka-report',
            );

            foreach ($ids as $id) {
                $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), $id);
            }

            $this->assertStringContainsString('value="https://public.example.test/"', $html);
            $this->assertStringContainsString('Speed estimates, migration preview, and running the migration are not available', $html);
            $this->assertStringContainsString('A speed estimate is not available on this install yet.', $html);
            $this->assertStringContainsString('Migration preview is not available on this install yet.', $html);
            $this->assertStringContainsString('Running the migration is not available on this install yet.', $html);
            $this->assertStringNotContainsString('writes a Tolka package', $html);
            $this->assertStringNotContainsString('No measurement yet.', $html);
            $this->assertStringNotContainsString('Nothing has been previewed yet.', $html);
            $this->assertStringNotContainsString('No migration has been run from this screen yet.', $html);
            $this->assertSame(3, substr_count($html, ' disabled '));
            $this->assertStringContainsString('Polylang cannot be checked on this install yet.', $html);
            $this->assertStringContainsString('includes/tolka/data/benchmarks.json', $html);
            $this->assertStringContainsString('id="wmm-tolka-measure-note"', $html);
            $this->assertStringContainsString('aria-describedby="wmm-tolka-measure-note"', $html);
            $this->assertStringContainsString('aria-describedby="wmm-tolka-preview-note"', $html);
            $this->assertStringContainsString('id="wmm-tolka-fallback"', $html);
            $this->assertStringContainsString('typeof request.then', $html);
            $this->assertStringContainsString('removeAttribute("aria-describedby")', $html);
            $this->assertStringContainsString('The request could not be completed.', $html);
            $this->assertStringContainsString('username or password', $html);
            $this->assertStringContainsString('longer than 2000 characters', $html);
            $this->assertStringContainsString('javascript: and data:', $html);
            $this->assertStringContainsString('That address is on a local or private network, so it was not measured.', $html);
            $this->assertStringContainsString('Enter', $html);
            $this->assertStringContainsString('Enable JavaScript', $html);
            $this->assertStringNotContainsString('secret-html', $html);
        }

        public function test_run_names_only_classes_that_are_missing()
        {
            $this->requireStubs();

            if (class_exists(Migration_Runner::class) || class_exists(Migration_Report::class)) {
                $this->markTestSkipped('Migration classes are already loaded.');
            }

            $this->defineRunnerStub();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $output = ob_get_clean();
            $payload = $GLOBALS['wmm_tolka_test_json'];

            $this->assertSame('', $output);
            $this->assertFalse($payload['success']);
            $this->assertSame(array('Migration_Report'), $payload['data']['missing']);
            $this->assertStringContainsString('Migration_Report', $payload['data']['message']);
            $this->assertStringNotContainsString('Migration_Runner', $payload['data']['message']);
            $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertSame(array(), $GLOBALS['wmm_tolka_test_json']['data']['missing']);
        }

        public function test_ready_steps_invite_action_and_skip_fallback_script_when_js_exists()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $html = $this->render();
            $this->assertStringContainsString('No measurement yet.', $html);
            $this->assertStringContainsString('Nothing has been previewed yet.', $html);
            $this->assertStringContainsString('No migration has been run from this screen yet.', $html);
            $this->assertSame(0, substr_count($html, ' disabled '));
            $this->assertStringContainsString('button-primary', $html);
            $this->assertStringContainsString('writes a Tolka package', $html);

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/assets/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            $js = $directory . 'tolka-admin.js';
            file_put_contents($js, '/* behavior slice */');
            clearstatcache();

            try {
                $with_js = $this->render();
                $this->assertStringNotContainsString('id="wmm-tolka-fallback"', $with_js);
                $this->assertStringContainsString('id="wmm-tolka-wizard"', $with_js);
            } finally {
                unlink($js);
                clearstatcache();
            }
        }

        public function test_empty_wizard_view_falls_back()
        {
            $this->requireStubs();

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/views/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $view = $directory . 'wizard.php';
            file_put_contents($view, "<?php\n/* empty screen */\n");
            clearstatcache();

            try {
                $html = $this->render();
                $this->assertSame(1, substr_count($html, 'id="wmm-tolka-wizard"'));
                $this->assertStringContainsString('id="wmm-tolka-url"', $html);
            } finally {
                unlink($view);
                clearstatcache();
            }
        }

        public function test_missing_method_is_unavailable()
        {
            $this->requireStubs();

            if (!class_exists(Empty_Method_Slice::class)) {
                eval('namespace WMM\\Tolka; class Empty_Method_Slice {}');
            }

            $missing = \wmm_tolka_unavailable(array(
                'Empty_Method_Slice' => array(
                    'class' => Empty_Method_Slice::class,
                    'methods' => array('status'),
                ),
            ));

            $this->assertSame(array('Empty_Method_Slice'), $missing);
        }

        public function test_empty_measure_url_does_not_call_speed_loader()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;

            $cases = array(
                array('nonce' => 'good-nonce'),
                array('nonce' => 'good-nonce', 'url' => ''),
                array('nonce' => 'good-nonce', 'url' => '   '),
                array('nonce' => 'good-nonce', 'url' => "\n\t"),
                array('nonce' => 'good-nonce', 'url' => array('https://public.example.test/')),
                array('nonce' => 'good-nonce', 'url' => 'not a url'),
            );

            foreach ($cases as $post) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = $post;
                unset($GLOBALS['wmm_tolka_test_json']);

                ob_start();
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $output = ob_get_clean();
                $payload = $GLOBALS['wmm_tolka_test_json'];

                $this->assertSame('', $output);
                $this->assertFalse($payload['success']);
                $this->assertStringContainsString('Enter a public site URL', $payload['data']['message']);
                $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);
                $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls']);
                $this->assertNoSpeedPercent($payload);
            }

            $GLOBALS['wmm_tolka_measure_calls'] = 0;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => '  https://public.example.test/  ',
            );
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertSame(1, $GLOBALS['wmm_tolka_measure_calls']);
        }

        public function test_long_and_scriptable_measure_urls_are_rejected()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;

            $prefix = 'https://public.example.test/';
            $exact = $prefix . str_repeat('a', 2000 - strlen($prefix));
            $this->assertSame(2000, strlen($exact));
            $too_long = $exact . 'UNIQUE-TAIL-NOT-IN-ERROR';

            $GLOBALS['wmm_tolka_measure_calls'] = 0;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => '  ' . $exact . '  ',
            );
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertSame(1, $GLOBALS['wmm_tolka_measure_calls']);

            $GLOBALS['wmm_tolka_measure_calls'] = 0;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => $too_long,
            );
            unset($GLOBALS['wmm_tolka_test_json']);
            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $output = ob_get_clean();
            $payload = $GLOBALS['wmm_tolka_test_json'];
            $encoded = json_encode($payload);

            $this->assertSame('', $output);
            $this->assertFalse($payload['success']);
            $this->assertStringContainsString('2000 characters', $payload['data']['message']);
            $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);
            $this->assertLessThan(300, strlen($payload['data']['message']));
            $this->assertStringNotContainsString($too_long, $encoded);
            $this->assertStringNotContainsString('UNIQUE-TAIL-NOT-IN-ERROR', $encoded);
            $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
            $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls']);
            $this->assertNoSpeedPercent($payload);

            $blocked = array(
                'javascript:alert(UNIQUEJS)',
                ' javascript:alert(UNIQUEJS)',
                "java\nscript:alert(UNIQUEJS)",
                'Javascript:alert(UNIQUEJS)',
                'javascript&#58;alert(UNIQUEJS)',
                'data:text/html,<script>secret-html</script>UNIQUEHTML',
                ' DATA:text/html,UNIQUEHTML',
                'data :text/html,UNIQUEHTML',
            );

            foreach ($blocked as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);

                ob_start();
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $output = ob_get_clean();
                $payload = $GLOBALS['wmm_tolka_test_json'];
                $encoded = json_encode($payload);

                $this->assertSame('', $output);
                $this->assertFalse($payload['success']);
                $this->assertStringContainsString('javascript: and data:', $payload['data']['message']);
                $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);
                $this->assertLessThan(300, strlen($payload['data']['message']));
                $this->assertStringNotContainsString($url, $encoded);
                $this->assertStringNotContainsString('UNIQUE-TAIL-NOT-IN-ERROR', $encoded);
                $this->assertStringNotContainsString('UNIQUEJS', $encoded);
                $this->assertStringNotContainsString('UNIQUEHTML', $encoded);
                $this->assertStringNotContainsString('secret-html', $encoded);
                $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
                $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls']);
                $this->assertNoSpeedPercent($payload);
            }
        }

        public function test_local_and_private_measure_urls_are_rejected()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;

            $rejected = array(
                'http://localhost/',
                'http://localhost',
                'https://LOCALHOST/path',
                'http://foo.localhost/',
                'http://localhost./',
                'http://127.0.0.1/',
                'http://127.0.0.1:8080/wp-admin',
                'http://127.255.255.255/',
                'http://127.1/',
                'http://10.1.2.3/secret-path',
                'http://192.168.1.20/page',
                'http://172.16.5.5/',
                'http://172.31.255.255/',
                'http://[::1]/',
                'http://[::ffff:127.0.0.1]/',
                'http://[::ffff:10.0.0.8]/',
                'http://2130706433/',
            );

            foreach ($rejected as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);

                ob_start();
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $output = ob_get_clean();
                $payload = $GLOBALS['wmm_tolka_test_json'];
                $encoded = json_encode($payload);
                $message = $payload['data']['message'];

                $this->assertSame('', $output, $url);
                $this->assertFalse($payload['success'], $url);
                $this->assertSame('That address is on a local or private network, so it was not measured.', $message, $url);
                $this->assertSame(1, preg_match_all('/[.!?](?:\s|$)/', $message), $url);
                $this->assertStringNotContainsString("\n", $message);
                $this->assertDoesNotMatchRegularExpression('/stack trace|#\d+\s|Exception|Fatal error/i', $encoded);
                $this->assertStringNotContainsString($url, $encoded);
                $this->assertStringNotContainsString('127.0.0.1', $message);
                $this->assertStringNotContainsString('192.168', $message);
                $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
                $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls'], $url);
                $this->assertNoSpeedPercent($payload);
            }

            $allowed = array(
                'https://public.example.test/',
                'http://8.8.8.8/',
                'http://172.32.0.1/',
                'https://example.com/localhost',
            );

            foreach ($allowed as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success'], $url);
                $this->assertSame(1, $GLOBALS['wmm_tolka_measure_calls'], $url);
            }
        }

        public function test_empty_host_measure_urls_are_rejected()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;

            $rejected = array(
                'https:///',
                'https://',
                'http:///',
                'http:///path',
                'https://#frag',
                'https://?q=1',
                'https://.',
                '  https:///UNIQUE-EMPTY-HOST  ',
                'http:///' . str_repeat('p', 80),
            );

            foreach ($rejected as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);

                ob_start();
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $output = ob_get_clean();
                $payload = $GLOBALS['wmm_tolka_test_json'];
                $encoded = json_encode($payload);
                $message = $payload['data']['message'];

                $this->assertSame('', $output, $url);
                $this->assertFalse($payload['success'], $url);
                $this->assertSame('That address has no host, so it was not measured.', $message, $url);
                $this->assertStringNotContainsString(trim($url), $encoded);
                $this->assertStringNotContainsString('UNIQUE-EMPTY-HOST', $encoded);
                $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
                $this->assertDoesNotMatchRegularExpression('/stack trace|#\d+\s|Exception|Fatal error/i', $encoded);
                $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls'], $url);
                $this->assertNoSpeedPercent($payload);
            }

            $GLOBALS['wmm_tolka_measure_calls'] = 0;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertSame(1, $GLOBALS['wmm_tolka_measure_calls']);
        }

        public function test_measure_url_with_userinfo_does_not_call_speed_loader()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;

            $rejected = array(
                'https://user:secret@example.com',
                'https://user@example.com',
                'http://:secret@example.com',
                'https://user:@example.com',
                'HTTPS://User:Secret@Example.com/path',
                'https://user:secret@example.com:8443/x?q=1',
                'https://example.com:secret@evil.com',
                '  https://user:secret@example.com/path  ',
            );

            foreach ($rejected as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);

                ob_start();
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $output = ob_get_clean();
                $payload = $GLOBALS['wmm_tolka_test_json'];
                $encoded = json_encode($payload);

                $this->assertSame('', $output);
                $this->assertFalse($payload['success']);
                $this->assertStringContainsString('username or password', $payload['data']['message']);
                $this->assertStringContainsString('Nothing was changed.', $payload['data']['message']);
                $this->assertStringNotContainsString('secret', $encoded);
                $this->assertStringNotContainsString('user:secret', $encoded);
                $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
                $this->assertSame(0, $GLOBALS['wmm_tolka_measure_calls']);
                $this->assertNoSpeedPercent($payload);
            }

            $allowed = array(
                'https://public.example.test/?email=a@b.com',
                'https://public.example.test/path#user:secret@example.com',
            );

            foreach ($allowed as $url) {
                $GLOBALS['wmm_tolka_measure_calls'] = 0;
                $_POST = array(
                    'nonce' => 'good-nonce',
                    'url' => $url,
                );
                unset($GLOBALS['wmm_tolka_test_json']);
                $this->capture(function () {
                    \wmm_tolka_ajax_measure();
                });
                $this->assertTrue($GLOBALS['wmm_tolka_test_json']['success']);
                $this->assertSame(1, $GLOBALS['wmm_tolka_measure_calls']);
                $encoded = json_encode($GLOBALS['wmm_tolka_test_json']);
                $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $encoded);
                $this->assertStringNotContainsString('secret-html', $encoded);
            }
        }

        public function test_saved_report_is_shown()
        {
            $this->requireStubs();

            $GLOBALS['wmm_tolka_test_report'] = array(
                'ok' => false,
                'title' => 'Migration needs attention',
                'summary' => 'Polylang data was left in place.',
                'lines' => array(
                    array('label' => 'Languages', 'value' => 0),
                ),
            );

            $html = $this->render();
            $this->assertStringContainsString('Migration needs attention', $html);
            $this->assertStringContainsString('Polylang data was left in place.', $html);
            $this->assertStringContainsString('wmm-tolka-panel-error', $html);
            $this->assertStringNotContainsString('No migration has been run from this screen yet.', $html);
        }

        public function test_permission_failure_does_not_render_the_wizard()
        {
            $this->requireStubs();
            $GLOBALS['wmm_tolka_test_can'] = false;

            try {
                \wmm_tolka_render_page();
                $this->fail('Expected the permission check to stop the page.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('permission', $e->getMessage());
            }
        }

        public function test_broken_class_file_does_not_fatal()
        {
            $this->requireStubs();

            $thrown = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/class-zz-thrown.php';
            $parsed = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/class-zz-parse.php';
            file_put_contents($thrown, "<?php\nthrow new RuntimeException('broken slice');\n");
            file_put_contents($parsed, "<?php\nthis is not valid php;\n");

            try {
                \wmm_tolka_load_classes();
                $files = array();
                foreach (\wmm_tolka_load_errors() as $error) {
                    $files[] = $error['file'];
                }
                $this->assertContains('class-zz-thrown.php', $files);
                $this->assertContains('class-zz-parse.php', $files);

                $html = $this->render();
                $this->assertStringContainsString('Part of Migrate to Tolka could not be loaded.', $html);
                $this->assertStringContainsString('Could not load class-zz-thrown.php.', $html);
                $this->assertStringContainsString('id="wmm-tolka-wizard"', $html);
            } finally {
                unlink($thrown);
                unlink($parsed);
                clearstatcache();
            }
        }

        public function test_existing_wizard_view_is_used()
        {
            $this->requireStubs();

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/views/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $view = $directory . 'wizard.php';
            file_put_contents($view, '<div id="wmm-tolka-wizard">SCREEN_MARKER</div>');
            clearstatcache();

            try {
                $html = $this->render();
                $this->assertStringContainsString('SCREEN_MARKER', $html);
                $this->assertSame(1, substr_count($html, 'id="wmm-tolka-wizard"'));
                $this->assertStringNotContainsString('No measurement yet.', $html);
            } finally {
                unlink($view);
                clearstatcache();
            }
        }

        public function test_broken_wizard_view_falls_back()
        {
            $this->requireStubs();

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/views/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $view = $directory . 'wizard.php';
            file_put_contents($view, "<?php\nthrow new RuntimeException('view broke');\n");
            clearstatcache();

            try {
                $html = $this->render();
                $this->assertStringContainsString('A basic screen is shown instead.', $html);
                $this->assertStringContainsString('No measurement yet.', $html);
            } finally {
                unlink($view);
                clearstatcache();
            }
        }

        public function test_installed_slices_return_results_without_html()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $output = ob_get_clean();
            $payload = $GLOBALS['wmm_tolka_test_json'];
            $encoded = json_encode($payload);

            $this->assertSame('', $output);
            $this->assertTrue($payload['success']);
            $this->assertSame(array('metrics', 'estimate'), array_keys($payload['data']));
            $this->assertArrayNotHasKey('html', $payload['data']['metrics']);
            $this->assertArrayNotHasKey('html', $payload['data']['estimate']);
            $this->assertStringNotContainsString('secret-html', $encoded);
            $this->assertSame('Measured page load plus coefficients from benchmarks.json', $payload['data']['estimate']['basis']);
            $this->assertSame(25.0, $payload['data']['estimate']['faster_percent']);
            $this->assertSame('25% faster', $payload['data']['estimate']['faster_label']);

            $GLOBALS['wmm_tolka_stub_html'] = true;
            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $html_output = ob_get_clean();
            $html_payload = $GLOBALS['wmm_tolka_test_json'];
            $html_encoded = json_encode($html_payload);

            $this->assertSame('', $html_output);
            $this->assertTrue($html_payload['success']);
            $this->assertSame(25.0, $html_payload['data']['estimate']['faster_percent']);
            $this->assertSame(2, $html_payload['data']['metrics']['language_count']);
            $this->assertSame('counted', $html_payload['data']['metrics']['note']);
            $this->assertArrayNotHasKey('html', $html_payload['data']['metrics']);
            $this->assertArrayNotHasKey('HTML', $html_payload['data']['metrics']);
            $this->assertArrayNotHasKey('html', $html_payload['data']['estimate']);
            $this->assertDoesNotMatchRegularExpression('/<\/?[a-z!]/i', $html_encoded);
            $this->assertStringNotContainsString('secret-html', $html_encoded);
            $this->assertStringNotContainsString('onerror', $html_encoded);

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertSame(0, $GLOBALS['wmm_tolka_test_json']['data']['counts']['languages']);

            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $this->assertSame('Migration finished', $GLOBALS['wmm_tolka_test_json']['data']['title']);
        }

        public function test_polylang_status_is_safe_and_nonfatal()
        {
            $this->requireStubs();

            if (!class_exists(Polylang_Detector::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Polylang_Detector {
    public static function status() {
        if (!empty($GLOBALS['wmm_tolka_detector_throw'])) {
            throw new \RuntimeException('detector-boom');
        }
        return array(
            'active' => !empty($GLOBALS['wmm_tolka_detector_active']),
            'version' => isset($GLOBALS['wmm_tolka_detector_version']) ? $GLOBALS['wmm_tolka_detector_version'] : '',
            'errors' => isset($GLOBALS['wmm_tolka_detector_errors']) ? $GLOBALS['wmm_tolka_detector_errors'] : array(),
        );
    }
}
PHP
                );
            }

            $GLOBALS['wmm_tolka_detector_throw'] = false;
            $GLOBALS['wmm_tolka_detector_active'] = true;
            $GLOBALS['wmm_tolka_detector_version'] = '3.6<script>';
            $GLOBALS['wmm_tolka_detector_errors'] = array('<b>bad</b>', 'Stored rows were unreadable.', array('skip'));

            $html = $this->render();
            $this->assertStringContainsString('Polylang 3.6 is active on this site.', $html);
            $this->assertStringContainsString('>bad<', $html);
            $this->assertStringContainsString('Stored rows were unreadable.', $html);
            $this->assertStringNotContainsString('3.6<script>', $html);
            $this->assertStringNotContainsString('<b>bad</b>', $html);

            $GLOBALS['wmm_tolka_detector_active'] = false;
            $GLOBALS['wmm_tolka_detector_version'] = '';
            $GLOBALS['wmm_tolka_detector_errors'] = array();
            $html = $this->render();
            $this->assertStringContainsString('Polylang is not active on this site.', $html);
            $this->assertStringContainsString('will not delete Polylang data.', $html);

            $GLOBALS['wmm_tolka_detector_throw'] = true;
            $html = $this->render();
            $this->assertStringContainsString('Polylang status could not be read.', $html);
            $this->assertStringNotContainsString('detector-boom', $html);
        }

        public function test_installed_partials_replace_fallback_copy()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/views/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $speed = $directory . 'speed-estimate.php';
            $help = $directory . 'help.php';
            file_put_contents($speed, '<p id="speed-partial">SPEED_PARTIAL</p>');
            file_put_contents($help, '<p id="help-partial">HELP_PARTIAL</p>');
            clearstatcache();

            try {
                $html = $this->render();
                $this->assertStringContainsString('SPEED_PARTIAL', $html);
                $this->assertStringContainsString('HELP_PARTIAL', $html);
                $this->assertStringNotContainsString('No measurement yet.', $html);
                $this->assertStringNotContainsString('See whether Polylang is active.', $html);
            } finally {
                unlink($speed);
                unlink($help);
                clearstatcache();
            }
        }

        public function test_speed_partial_fields_are_not_duplicated()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $directory = MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/views/';
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $speed = $directory . 'speed-estimate.php';
            $markup = '<p id="wmm-tolka-current-load">1 ms</p>'
                . '<p id="wmm-tolka-tolka-load">1 ms</p>'
                . '<p id="wmm-tolka-faster">0% faster</p>'
                . '<p id="wmm-tolka-basis">From the partial.</p>'
                . '<p id="wmm-tolka-provenance-note">Partial note.</p>';
            file_put_contents($speed, $markup);
            clearstatcache();

            try {
                $html = $this->render();
                foreach (array(
                    'wmm-tolka-current-load',
                    'wmm-tolka-tolka-load',
                    'wmm-tolka-faster',
                    'wmm-tolka-basis',
                    'wmm-tolka-provenance-note',
                ) as $id) {
                    $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), $id);
                }
                $this->assertStringContainsString('From the partial.', $html);
            } finally {
                unlink($speed);
                clearstatcache();
            }
        }

        public function test_failed_estimate_does_not_keep_a_percent()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_stub_bad_estimate'] = true;
            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $output = ob_get_clean();

            $this->assertSame('', $output);
            $payload = $GLOBALS['wmm_tolka_test_json'];
            $this->assertTrue($payload['success']);
            $this->assertFalse($payload['data']['estimate']['ok']);
            $this->assertSame('The page could not be measured.', $payload['data']['estimate']['error']);
            $this->assertStringNotContainsString('secret-html', json_encode($payload));
            $this->assertArrayNotHasKey('faster_percent', $payload['data']['estimate']);
            $this->assertArrayNotHasKey('faster_label', $payload['data']['estimate']);
            $this->assertArrayNotHasKey('html', $payload['data']['estimate']);
            $this->assertNoSpeedPercent($payload);
        }

        public function test_bad_preview_and_run_results_do_not_fatal()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_stub_bad_shape'] = true;
            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $GLOBALS['wmm_tolka_test_can'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
            );

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $output = ob_get_clean();
            $this->assertSame('', $output);
            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertStringContainsString('could not be built', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Migration_Runner', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Nothing was changed.', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $output = ob_get_clean();
            $this->assertSame('', $output);
            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertStringContainsString('did not finish', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('Migration_Runner', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('not deleted', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            $GLOBALS['wmm_tolka_stub_bad_shape'] = false;
            $GLOBALS['wmm_tolka_stub_bad_report'] = true;
            ob_start();
            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $output = ob_get_clean();
            $this->assertSame('', $output);
            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertStringContainsString('Migration_Report', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('could not be read', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('not deleted', $GLOBALS['wmm_tolka_test_json']['data']['message']);
        }

        public function test_slice_exceptions_become_errors()
        {
            $this->requireStubs();
            $this->defineSliceStubs();

            $GLOBALS['wmm_tolka_stub_throw'] = true;
            $GLOBALS['wmm_tolka_test_nonce_valid'] = true;
            $_POST = array(
                'nonce' => 'good-nonce',
                'url' => 'https://public.example.test/',
            );

            $this->capture(function () {
                \wmm_tolka_ajax_measure();
            });
            $this->assertFalse($GLOBALS['wmm_tolka_test_json']['success']);
            $this->assertStringContainsString('could not measure', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringNotContainsString('boom', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertStringContainsString('could not be built', $GLOBALS['wmm_tolka_test_json']['data']['message']);

            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $this->assertStringContainsString('did not finish', $GLOBALS['wmm_tolka_test_json']['data']['message']);
            $this->assertStringContainsString('not deleted', $GLOBALS['wmm_tolka_test_json']['data']['message']);
        }

        private function requireStubs()
        {
            if (!defined('WMM_TOLKA_TEST_STUBS') || !function_exists('wmm_tolka_render_page')) {
                $this->markTestSkipped('Wizard shell stubs are not active.');
            }
        }

        private function render()
        {
            ob_start();
            \wmm_tolka_render_page();
            return ob_get_clean();
        }

        private function capture($callback)
        {
            try {
                $callback();
                $this->fail('Expected the JSON response to stop the handler.');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('json_', $e->getMessage());
            }
        }

        /**
         * A failed measure must not carry a speed percent.
         *
         * @param mixed $value Response payload.
         */
        private function assertNoSpeedPercent($value)
        {
            if (is_string($value)) {
                $this->assertDoesNotMatchRegularExpression('/faster_percent|faster_label|%\\s*faster|\\d+(?:\\.\\d+)?\\s*%/i', $value);
                return;
            }

            if (!is_array($value)) {
                return;
            }

            foreach ($value as $key => $item) {
                if ($key === 'faster_percent' || $key === 'faster_label') {
                    $this->fail('A speed percent was returned.');
                }
                $this->assertNoSpeedPercent($item);
            }
        }

        private function defineRunnerStub()
        {
            if (class_exists(Migration_Runner::class)) {
                return;
            }

            eval(<<<'PHP'
namespace WMM\Tolka;
class Migration_Runner {
    public static function preview() {
        if (!empty($GLOBALS['wmm_tolka_stub_throw'])) {
            throw new \RuntimeException('preview-boom');
        }
        if (!empty($GLOBALS['wmm_tolka_stub_bad_shape'])) {
            return 'not-an-array';
        }
        return array(
            'counts' => array(
                'languages' => 0,
                'posts' => 0,
                'terms' => 0,
                'strings' => 0,
                'menus' => 0,
                'media' => 0,
            ),
            'sample' => array(),
            'errors' => array(),
            'missing' => array(),
        );
    }
    public static function run() {
        if (!empty($GLOBALS['wmm_tolka_stub_throw'])) {
            throw new \RuntimeException('run-boom');
        }
        if (!empty($GLOBALS['wmm_tolka_stub_bad_shape'])) {
            return null;
        }
        return array(
            'ok' => true,
            'counts' => array(),
            'write' => array(),
            'errors' => array(),
            'missing' => array(),
        );
    }
}
PHP
            );
        }

        private function defineSliceStubs()
        {
            if (!class_exists(Speed_Loader::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Speed_Loader {
    public static function measure($url) {
        if (!isset($GLOBALS['wmm_tolka_measure_calls'])) {
            $GLOBALS['wmm_tolka_measure_calls'] = 0;
        }
        $GLOBALS['wmm_tolka_measure_calls']++;
        if (!empty($GLOBALS['wmm_tolka_stub_throw'])) {
            throw new \RuntimeException('boom');
        }
        return array(
            'ok' => true,
            'url' => $url,
            'html' => '<script>secret-html</script>',
            'total_ms' => 120,
        );
    }
}
PHP
                );
            }

            if (!class_exists(Speed_Metrics::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Speed_Metrics {
            public static function analyze($measurement) {
        $metrics = array(
            'language_count' => 2,
            'html' => '<script>secret-html</script>',
            'HTML' => '<div>secret-html</div>',
        );
        if (!empty($GLOBALS['wmm_tolka_stub_html'])) {
            $metrics['note'] = '<img src=x onerror=alert(1)>counted <!-- <script>secret-html</script> -->';
        }
        return $metrics;
    }
}
PHP
                );
            }

            if (!class_exists(Speed_Estimate::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Speed_Estimate {
    public static function benchmarks() {
        return array('id' => 'tolka-lab');
    }
    public static function project($measurement, $metrics, $benchmarks) {
        if (!empty($GLOBALS['wmm_tolka_stub_bad_estimate'])) {
            return array(
                'ok' => false,
                'current_ms' => 0,
                'tolka_ms' => 0,
                'faster_percent' => 99.9,
                'faster_label' => '99.9% faster',
                'error' => 'The page could not be measured. <script>secret-html</script>',
                'html' => '<script>secret-html</script>',
            );
        }
        if (!empty($GLOBALS['wmm_tolka_stub_html'])) {
            return array(
                'ok' => true,
                'current_ms' => 120,
                'tolka_ms' => 90,
                'faster_percent' => 25.0,
                'faster_label' => '25% faster',
                'basis' => 'Measured <b>page</b> load plus coefficients from benchmarks.json',
                'error' => '<script>secret-html</script>',
                'html' => '<script>secret-html</script>',
                'provenance' => 'placeholder',
                'provenance_note' => 'Placeholder <!-- <style>secret-html</style> -->coefficients',
            );
        }
        return array(
            'ok' => true,
            'current_ms' => 120,
            'tolka_ms' => 90,
            'faster_percent' => 25.0,
            'faster_label' => '25% faster',
            'basis' => 'Measured page load plus coefficients from benchmarks.json',
            'provenance' => 'placeholder',
            'provenance_note' => 'Placeholder coefficients',
            'html' => '<script>secret-html</script>',
            'error' => '',
        );
    }
}
PHP
                );
            }

            if (!class_exists(Migration_Runner::class)) {
                $this->defineRunnerStub();
            }

            if (!class_exists(Migration_Report::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Migration_Report {
    public static function format($run) {
        if (!empty($GLOBALS['wmm_tolka_stub_bad_report'])) {
            return 'not-a-report';
        }
        return array(
            'ok' => true,
            'title' => 'Migration finished',
            'summary' => 'Polylang data was left in place.',
            'lines' => array(
                array('label' => 'Languages', 'value' => '0'),
            ),
        );
    }
}
PHP
                );
            }
        }
    }
}
