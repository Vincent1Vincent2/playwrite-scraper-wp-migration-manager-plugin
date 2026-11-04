<?php

/**
 * Wizard shell tests. WordPress is stubbed when it is not already loaded.
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
            return is_string($url) ? $url : '';
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
            unset($GLOBALS['wmm_tolka_test_json'], $GLOBALS['wmm_tolka_stub_throw'], $GLOBALS['wmm_tolka_test_report']);
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

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertSame(array('Migration_Runner'), $GLOBALS['wmm_tolka_test_json']['data']['missing']);

            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $this->assertSame(
                array('Migration_Runner', 'Migration_Report'),
                $GLOBALS['wmm_tolka_test_json']['data']['missing']
            );
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
                'wmm-tolka-preview-result',
                'wmm-tolka-report',
            );

            foreach ($ids as $id) {
                $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), $id);
            }

            $this->assertStringContainsString('value="https://public.example.test/"', $html);
            $this->assertStringContainsString('No measurement yet.', $html);
            $this->assertStringContainsString('Nothing has been previewed yet.', $html);
            $this->assertStringContainsString('No migration has been run from this screen yet.', $html);
            $this->assertStringContainsString('Polylang cannot be checked on this install yet.', $html);
            $this->assertStringContainsString('A speed estimate is not available on this install yet.', $html);
            $this->assertStringContainsString('includes/tolka/data/benchmarks.json', $html);
            $this->assertStringContainsString('id="wmm-tolka-fallback"', $html);
            $this->assertStringContainsString('Enable JavaScript', $html);
            $this->assertStringNotContainsString('secret-html', $html);
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

            $this->capture(function () {
                \wmm_tolka_ajax_preview();
            });
            $this->assertSame(0, $GLOBALS['wmm_tolka_test_json']['data']['counts']['languages']);

            $this->capture(function () {
                \wmm_tolka_ajax_run();
            });
            $this->assertSame('Migration finished', $GLOBALS['wmm_tolka_test_json']['data']['title']);
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

        private function defineSliceStubs()
        {
            if (!class_exists(Speed_Loader::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Speed_Loader {
    public static function measure($url) {
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
        return array(
            'language_count' => 2,
            'html' => '<script>secret-html</script>',
        );
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
                eval(<<<'PHP'
namespace WMM\Tolka;
class Migration_Runner {
    public static function preview() {
        if (!empty($GLOBALS['wmm_tolka_stub_throw'])) {
            throw new \RuntimeException('preview-boom');
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

            if (!class_exists(Migration_Report::class)) {
                eval(<<<'PHP'
namespace WMM\Tolka;
class Migration_Report {
    public static function format($run) {
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
