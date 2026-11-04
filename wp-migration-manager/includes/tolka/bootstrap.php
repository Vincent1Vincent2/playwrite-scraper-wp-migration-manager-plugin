<?php

/**
 * Migrate to Tolka wizard shell.
 *
 * Loads whatever Tolka classes are installed, registers the admin screen,
 * and handles measure, preview, and run. Missing slices show an error
 * instead of taking the screen down. Polylang data is never deleted here.
 *
 * AJAX POST field `nonce` is checked against action `wmm_tolka`.
 * `wmm_tolka_measure` also reads POST field `url`.
 *
 * @package MigrationManager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WMM_TOLKA_BOOTSTRAP_LOADED')) {
    return;
}

define('WMM_TOLKA_BOOTSTRAP_LOADED', true);

if (!defined('MIGRATION_MANAGER_PLUGIN_DIR')) {
    return;
}

/**
 * Path under includes/tolka.
 *
 * @param string $relative Relative path.
 * @return string
 */
function wmm_tolka_path($relative)
{
    return MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/' . ltrim($relative, '/');
}

/**
 * Record a class file that could not be loaded.
 *
 * @param array|null $error File name and message, or null to read the list.
 * @return array
 */
function wmm_tolka_load_errors($error = null)
{
    static $errors = array();

    if (is_array($error)) {
        $errors[] = $error;
    }

    return $errors;
}

/**
 * Require every installed Tolka class file. A broken file is skipped.
 */
function wmm_tolka_load_classes()
{
    $class_files = glob(wmm_tolka_path('class-*.php'));

    if (!is_array($class_files)) {
        return;
    }

    sort($class_files, SORT_STRING);

    foreach ($class_files as $class_file) {
        try {
            require_once $class_file;
        } catch (Throwable $e) {
            wmm_tolka_load_errors(array(
                'file' => basename($class_file),
                'message' => $e->getMessage(),
            ));
            error_log('Migrate to Tolka: could not load ' . basename($class_file));
        }
    }
}

/**
 * Admin hook suffix for the wizard, set when the menu is registered.
 *
 * @param string|null $hook Hook suffix to store.
 * @return string
 */
function wmm_tolka_menu_hook($hook = null)
{
    static $stored = '';

    if (is_string($hook) && $hook !== '') {
        $stored = $hook;
    }

    return $stored;
}

/**
 * Register the Migrate to Tolka menu.
 */
function wmm_tolka_register_menu()
{
    $hook = add_menu_page(
        __('Migrate to Tolka', 'migration-manager'),
        __('Migrate to Tolka', 'migration-manager'),
        'manage_options',
        'wmm-tolka',
        'wmm_tolka_render_page',
        'dashicons-translation',
        31
    );

    if (is_string($hook) && $hook !== '') {
        wmm_tolka_menu_hook($hook);
    }
}

/**
 * Localized data for the admin script. Keys match the shared contract.
 *
 * @return array
 */
function wmm_tolka_script_data()
{
    return array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wmm_tolka'),
        'actionMeasure' => 'wmm_tolka_measure',
        'actionPreview' => 'wmm_tolka_preview',
        'actionRun' => 'wmm_tolka_run',
    );
}

/**
 * Enqueue assets only on this screen, and only when the files exist.
 *
 * @param string $hook Current admin page hook.
 */
function wmm_tolka_enqueue_assets($hook)
{
    $menu_hook = wmm_tolka_menu_hook();

    if ($menu_hook === '' || $hook !== $menu_hook || !defined('MIGRATION_MANAGER_PLUGIN_URL')) {
        return;
    }

    $version = defined('MIGRATION_MANAGER_VERSION') ? MIGRATION_MANAGER_VERSION : '1.0.0';
    $style_path = wmm_tolka_path('assets/tolka-admin.css');
    $script_path = wmm_tolka_path('assets/tolka-admin.js');

    if (file_exists($style_path)) {
        wp_enqueue_style(
            'wmm-tolka-admin',
            MIGRATION_MANAGER_PLUGIN_URL . 'includes/tolka/assets/tolka-admin.css',
            array(),
            $version
        );
    }

    if (file_exists($script_path)) {
        wp_enqueue_script(
            'wmm-tolka-admin',
            MIGRATION_MANAGER_PLUGIN_URL . 'includes/tolka/assets/tolka-admin.js',
            array(),
            $version,
            true
        );
        wp_localize_script('wmm-tolka-admin', 'wmmTolka', wmm_tolka_script_data());
    }
}

/**
 * JSON-encode for inline script, with HTML special characters escaped.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wmm_tolka_json($data)
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $json = false;

    if (function_exists('wp_json_encode')) {
        $json = wp_json_encode($data, $flags);
    }

    if (!is_string($json) || $json === '') {
        $json = json_encode($data, $flags);
    }

    if (!is_string($json) || $json === '') {
        return '{}';
    }

    return $json;
}

/**
 * Nonce sent with an AJAX request.
 *
 * @return string
 */
function wmm_tolka_posted_nonce()
{
    $keys = array('nonce', '_ajax_nonce', '_wpnonce');

    foreach ($keys as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key]) && $_POST[$key] !== '') {
            return sanitize_text_field(wp_unslash($_POST[$key]));
        }
    }

    return '';
}

/**
 * Stop an AJAX request that fails the nonce or capability check.
 */
function wmm_tolka_ajax_guard()
{
    $nonce = wmm_tolka_posted_nonce();

    if ($nonce === '' || !wp_verify_nonce($nonce, 'wmm_tolka')) {
        wmm_tolka_json_error(__('The security check failed. Reload the page and try again.', 'migration-manager'));
        return;
    }

    if (!current_user_can('manage_options')) {
        wmm_tolka_json_error(__('You do not have permission to migrate this site.', 'migration-manager'));
        return;
    }
}

/**
 * Send an error payload. Missing class names stay machine-readable.
 *
 * @param string $message Finished sentence for the screen.
 * @param array  $missing Short class names.
 */
function wmm_tolka_json_error($message, $missing = array())
{
    $data = array(
        'message' => $message,
    );

    if (!empty($missing)) {
        $data['missing'] = array_values($missing);
    }

    wp_send_json_error($data);
}

/**
 * Classes and methods a step needs. Short names are returned when missing.
 *
 * @param array $requirements Short name => class and methods.
 * @return string[]
 */
function wmm_tolka_unavailable($requirements)
{
    $missing = array();

    foreach ($requirements as $short_name => $spec) {
        $class_name = isset($spec['class']) ? $spec['class'] : '';
        $methods = isset($spec['methods']) && is_array($spec['methods']) ? $spec['methods'] : array();
        $exists = false;

        try {
            $exists = is_string($class_name) && $class_name !== '' && class_exists($class_name);
        } catch (Throwable $e) {
            $exists = false;
        }

        if (!$exists) {
            $missing[] = $short_name;
            continue;
        }

        foreach ($methods as $method) {
            if (!is_string($method) || !method_exists($class_name, $method)) {
                $missing[] = $short_name;
                break;
            }
        }
    }

    return $missing;
}

/**
 * Speed classes required by the measure action.
 *
 * @return array
 */
function wmm_tolka_speed_requirements()
{
    return array(
        'Speed_Loader' => array(
            'class' => \WMM\Tolka\Speed_Loader::class,
            'methods' => array('measure'),
        ),
        'Speed_Metrics' => array(
            'class' => \WMM\Tolka\Speed_Metrics::class,
            'methods' => array('analyze'),
        ),
        'Speed_Estimate' => array(
            'class' => \WMM\Tolka\Speed_Estimate::class,
            'methods' => array('benchmarks', 'project'),
        ),
    );
}

/**
 * Preview classes required by the preview action.
 *
 * @return array
 */
function wmm_tolka_preview_requirements()
{
    return array(
        'Migration_Runner' => array(
            'class' => \WMM\Tolka\Migration_Runner::class,
            'methods' => array('preview'),
        ),
    );
}

/**
 * Classes required before a migration can be reported.
 *
 * @return array
 */
function wmm_tolka_run_requirements()
{
    return array(
        'Migration_Runner' => array(
            'class' => \WMM\Tolka\Migration_Runner::class,
            'methods' => array('run'),
        ),
        'Migration_Report' => array(
            'class' => \WMM\Tolka\Migration_Report::class,
            'methods' => array('format'),
        ),
    );
}

/**
 * Call a slice method. Exceptions become a failed result, not a fatal error.
 *
 * @param string $class  Fully qualified class name.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return array
 */
function wmm_tolka_invoke($class, $method, $args = array())
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return array(
            'called' => false,
            'reason' => 'missing',
        );
    }

    try {
        $value = call_user_func_array(array($class, $method), $args);
    } catch (Throwable $e) {
        $detail = preg_replace('/\s+/', ' ', $e->getMessage());
        if (!is_string($detail)) {
            $detail = '';
        }
        $detail = trim($detail);
        if (strlen($detail) > 200) {
            $detail = substr($detail, 0, 200);
        }
        error_log('Migrate to Tolka: ' . $class . '::' . $method . ' failed. ' . $detail);

        return array(
            'called' => false,
            'reason' => 'exception',
        );
    }

    return array(
        'called' => true,
        'value' => $value,
    );
}

/**
 * Remove raw HTML payloads before they can be sent to the browser.
 *
 * @param mixed $value Arbitrary slice data.
 * @return mixed
 */
function wmm_tolka_strip_html_keys($value)
{
    if (!is_array($value)) {
        return $value;
    }

    unset($value['html']);

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = wmm_tolka_strip_html_keys($item);
        }
    }

    return $value;
}

/**
 * Public URL posted to the measure action. Arrays are ignored.
 *
 * @return string
 */
function wmm_tolka_posted_url()
{
    if (!isset($_POST['url']) || !is_string($_POST['url'])) {
        return '';
    }

    return esc_url_raw(wp_unslash($_POST['url']));
}

/**
 * Measure the public URL and return metrics plus the Tolka estimate.
 */
function wmm_tolka_ajax_measure()
{
    wmm_tolka_ajax_guard();

    $missing = wmm_tolka_unavailable(wmm_tolka_speed_requirements());

    if (!empty($missing)) {
        wmm_tolka_json_error(
            __('A speed estimate is not available on this install. Nothing was changed.', 'migration-manager'),
            $missing
        );
        return;
    }

    $measured = wmm_tolka_invoke(\WMM\Tolka\Speed_Loader::class, 'measure', array(wmm_tolka_posted_url()));

    if (!$measured['called'] || !is_array($measured['value'])) {
        wmm_tolka_json_error(__('The speed check could not measure that URL. Nothing was changed.', 'migration-manager'));
        return;
    }

    $analyzed = wmm_tolka_invoke(\WMM\Tolka\Speed_Metrics::class, 'analyze', array($measured['value']));

    if (!$analyzed['called'] || !is_array($analyzed['value'])) {
        wmm_tolka_json_error(__('The speed check could not read the page. No estimate was calculated.', 'migration-manager'));
        return;
    }

    $benchmarks = wmm_tolka_invoke(\WMM\Tolka\Speed_Estimate::class, 'benchmarks', array());

    if (!$benchmarks['called'] || !is_array($benchmarks['value'])) {
        wmm_tolka_json_error(__('The speed benchmarks could not be read. No estimate was calculated.', 'migration-manager'));
        return;
    }

    $projected = wmm_tolka_invoke(
        \WMM\Tolka\Speed_Estimate::class,
        'project',
        array($measured['value'], $analyzed['value'], $benchmarks['value'])
    );

    if (!$projected['called'] || !is_array($projected['value'])) {
        wmm_tolka_json_error(__('A Tolka speed estimate could not be calculated. Nothing was changed.', 'migration-manager'));
        return;
    }

    wp_send_json_success(array(
        'metrics' => wmm_tolka_public_copy($analyzed['value']),
        'estimate' => wmm_tolka_without_unusable_percent(wmm_tolka_public_copy($projected['value'])),
    ));
}

/**
 * Drop a percent unless the estimate itself says it is usable.
 *
 * @param mixed $estimate Projected estimate.
 * @return mixed
 */
function wmm_tolka_without_unusable_percent($estimate)
{
    if (!is_array($estimate)) {
        return $estimate;
    }

    $ok = array_key_exists('ok', $estimate) && ($estimate['ok'] === true || $estimate['ok'] === 1);

    if ($ok) {
        return $estimate;
    }

    unset($estimate['faster_percent'], $estimate['faster_label']);

    return $estimate;
}

/**
 * Preview migration counts and samples without writing Tolka data.
 */
function wmm_tolka_ajax_preview()
{
    wmm_tolka_ajax_guard();

    $missing = wmm_tolka_unavailable(wmm_tolka_preview_requirements());

    if (!empty($missing)) {
        wmm_tolka_json_error(
            __('Migration preview is not available on this install. Nothing was changed.', 'migration-manager'),
            $missing
        );
        return;
    }

    $preview = wmm_tolka_invoke(\WMM\Tolka\Migration_Runner::class, 'preview', array());

    if (!$preview['called'] || !is_array($preview['value'])) {
        wmm_tolka_json_error(__('The migration preview could not be built. Nothing was changed.', 'migration-manager'));
        return;
    }

    wp_send_json_success($preview['value']);
}

/**
 * Run the migration, then return the formatted report.
 */
function wmm_tolka_ajax_run()
{
    wmm_tolka_ajax_guard();

    $missing = wmm_tolka_unavailable(wmm_tolka_run_requirements());

    if (!empty($missing)) {
        wmm_tolka_json_error(
            __('Running the migration is not available on this install. Nothing was changed.', 'migration-manager'),
            $missing
        );
        return;
    }

    $run = wmm_tolka_invoke(\WMM\Tolka\Migration_Runner::class, 'run', array());

    if (!$run['called'] || !is_array($run['value'])) {
        wmm_tolka_json_error(__('The migration did not finish. Polylang data was not deleted.', 'migration-manager'));
        return;
    }

    $report = wmm_tolka_invoke(\WMM\Tolka\Migration_Report::class, 'format', array($run['value']));

    if (!$report['called'] || !is_array($report['value'])) {
        wmm_tolka_json_error(__('The migration finished, but the result could not be read. Polylang data was not deleted.', 'migration-manager'));
        return;
    }

    wp_send_json_success($report['value']);
}

/**
 * Plain text for admin output. Long values are shortened.
 *
 * @param mixed $value Slice value.
 * @return string
 */
function wmm_tolka_plain_value($value)
{
    if (is_bool($value)) {
        return $value ? __('Yes', 'migration-manager') : __('No', 'migration-manager');
    }

    if ($value === null) {
        return '';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (!is_string($value)) {
        return __('Stored with the report.', 'migration-manager');
    }

    $without_blocks = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $value);
    if (is_string($without_blocks)) {
        $value = $without_blocks;
    }

    $value = strip_tags($value);

    $collapsed = preg_replace('/\s+/', ' ', $value);
    if (is_string($collapsed)) {
        $value = trim($collapsed);
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($value) > 400) {
        return mb_substr($value, 0, 400) . '...';
    }

    if (strlen($value) > 400) {
        return substr($value, 0, 400) . '...';
    }

    return $value;
}

/**
 * Copy a measure result for the browser, without raw HTML.
 *
 * @param mixed $value Slice data.
 * @return mixed
 */
function wmm_tolka_public_copy($value)
{
    if (is_string($value)) {
        return wmm_tolka_plain_value($value);
    }

    if (!is_array($value)) {
        return $value;
    }

    unset($value['html']);

    foreach ($value as $key => $item) {
        $value[$key] = wmm_tolka_public_copy($item);
    }

    return $value;
}

/**
 * Whether a formatted report has anything to show.
 *
 * @param mixed $report Report array.
 * @return bool
 */
function wmm_tolka_report_has_body($report)
{
    if (!is_array($report)) {
        return false;
    }

    if (isset($report['title']) && trim(wmm_tolka_plain_value($report['title'])) !== '') {
        return true;
    }

    if (isset($report['summary']) && trim(wmm_tolka_plain_value($report['summary'])) !== '') {
        return true;
    }

    return !empty($report['lines']) && is_array($report['lines']);
}

/**
 * Last report stored by the report slice, when that option exists.
 *
 * @return array
 */
function wmm_tolka_saved_report()
{
    if (!function_exists('get_option')) {
        return array();
    }

    $report = get_option('wmm_tolka_last_report', array());

    if (!is_array($report)) {
        return array();
    }

    return $report;
}

/**
 * Site home URL, used as the default address to measure.
 *
 * @return string
 */
function wmm_tolka_home_url()
{
    if (!function_exists('home_url')) {
        return '';
    }

    $home = home_url('/');

    return is_string($home) ? $home : '';
}

/**
 * Include a PHP file and capture its markup. Failures stay on this screen.
 *
 * @param string $path Absolute path.
 * @return array
 */
function wmm_tolka_capture_include($path)
{
    ob_start();

    try {
        include $path;
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('Migrate to Tolka: could not render ' . basename($path));

        return array(
            'ok' => false,
            'html' => '',
        );
    }

    $html = ob_get_clean();

    return array(
        'ok' => true,
        'html' => is_string($html) ? $html : '',
    );
}

/**
 * Whether captured markup contains an element id.
 *
 * @param string $html Markup.
 * @param string $id   Element id.
 * @return bool
 */
function wmm_tolka_html_has_id($html, $id)
{
    if (!is_string($html) || $id === '') {
        return false;
    }

    return strpos($html, 'id="' . $id . '"') !== false || strpos($html, "id='" . $id . "'") !== false;
}

/**
 * Admin notice.
 *
 * @param string $type    success, info, warning, or error.
 * @param string $message Finished sentence.
 */
function wmm_tolka_notice($type, $message)
{
    $allowed = array('success', 'info', 'warning', 'error');

    if (!in_array($type, $allowed, true)) {
        $type = 'warning';
    }

    echo '<div class="notice notice-' . esc_attr($type) . ' wmm-tolka-notice"><p>';
    echo esc_html($message);
    echo '</p></div>';
}

/**
 * Page title.
 */
function wmm_tolka_render_title()
{
    echo '<h1 class="wmm-tolka-title">' . esc_html__('Migrate to Tolka', 'migration-manager') . '</h1>';
}

/**
 * Files that failed to load.
 */
function wmm_tolka_render_load_errors()
{
    $errors = wmm_tolka_load_errors();

    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error wmm-tolka-notice"><p>';
    echo esc_html__('Part of Migrate to Tolka could not be loaded. The rest of this screen is still available. Nothing was changed.', 'migration-manager');
    echo '</p><ul class="wmm-tolka-gaps">';

    foreach ($errors as $error) {
        $file = isset($error['file']) ? trim((string) $error['file']) : '';
        if ($file === '') {
            $file = __('a plugin file', 'migration-manager');
        }
        echo '<li>' . esc_html(sprintf(
            /* translators: %s: file name */
            __('Could not load %s.', 'migration-manager'),
            $file
        )) . '</li>';
    }

    echo '</ul></div>';
}

/**
 * Polylang status, or a clear message when the detector is missing.
 */
function wmm_tolka_render_status()
{
    $detector = \WMM\Tolka\Polylang_Detector::class;

    if (!class_exists($detector) || !method_exists($detector, 'status')) {
        wmm_tolka_notice(
            'warning',
            __('Polylang cannot be checked on this install yet. Nothing has been deleted.', 'migration-manager')
        );
        return;
    }

    $result = wmm_tolka_invoke($detector, 'status', array());

    if (!$result['called'] || !is_array($result['value'])) {
        wmm_tolka_notice('warning', __('Polylang status could not be read. Nothing was changed.', 'migration-manager'));
        return;
    }

    $status = $result['value'];
    $active = !empty($status['active']);
    $version = '';

    if (isset($status['version']) && (is_string($status['version']) || is_numeric($status['version']))) {
        $version = trim(wmm_tolka_plain_value((string) $status['version']));
    }

    if ($active && $version !== '') {
        wmm_tolka_notice('success', sprintf(
            /* translators: %s: Polylang version */
            __('Polylang %s is active on this site.', 'migration-manager'),
            $version
        ));
    } elseif ($active) {
        wmm_tolka_notice('success', __('Polylang is active on this site.', 'migration-manager'));
    } else {
        wmm_tolka_notice(
            'warning',
            __('Polylang is not active on this site. Preview and migration still will not delete Polylang data.', 'migration-manager')
        );
    }

    if (empty($status['errors']) || !is_array($status['errors'])) {
        return;
    }

    $lines = array();

    foreach ($status['errors'] as $error) {
        if (!is_string($error)) {
            continue;
        }

        $text = trim(wmm_tolka_plain_value($error));
        if ($text === '') {
            continue;
        }

        $lines[] = $text;
    }

    if (empty($lines)) {
        return;
    }

    echo '<ul class="wmm-tolka-problems">';

    foreach ($lines as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }

    echo '</ul>';
}

/**
 * Human sentences for steps that cannot run yet, keyed by measure, preview, and run.
 *
 * @return array
 */
function wmm_tolka_gap_messages()
{
    $gaps = array();

    if (wmm_tolka_unavailable(wmm_tolka_speed_requirements())) {
        $gaps['measure'] = __('A speed estimate is not available on this install yet.', 'migration-manager');
    }

    if (wmm_tolka_unavailable(wmm_tolka_preview_requirements())) {
        $gaps['preview'] = __('Migration preview is not available on this install yet.', 'migration-manager');
    }

    if (wmm_tolka_unavailable(wmm_tolka_run_requirements())) {
        $gaps['run'] = __('Running the migration is not available on this install yet.', 'migration-manager');
    }

    return $gaps;
}

/**
 * Tell the site owner which steps are not installed yet.
 */
function wmm_tolka_render_gaps()
{
    $gaps = wmm_tolka_gap_messages();

    if (empty($gaps)) {
        return;
    }

    echo '<div class="notice notice-info wmm-tolka-notice"><p>';

    if (count($gaps) === 3) {
        echo esc_html__('Speed estimates, migration preview, and running the migration are not available on this install yet. Nothing has been deleted.', 'migration-manager');
        echo '</p></div>';
        return;
    }

    echo esc_html__('Some steps are not available on this install yet. Use the steps that are ready. Nothing has been deleted.', 'migration-manager');
    echo '</p><ul class="wmm-tolka-gaps">';

    foreach ($gaps as $gap) {
        echo '<li>' . esc_html($gap) . '</li>';
    }

    echo '</ul></div>';
}

/**
 * Action button. Unavailable steps are disabled so they cannot look ready.
 *
 * @param string $id           Element id.
 * @param string $label        Visible label.
 * @param string $unavailable  Empty when the step can run.
 * @param bool   $primary      Use the primary button style when the step can run.
 * @param string $described_by Space-separated ids.
 */
function wmm_tolka_render_button($id, $label, $unavailable, $primary = false, $described_by = '')
{
    $class = 'button';

    if ($unavailable === '' && $primary) {
        $class .= ' button-primary';
    }

    echo '<button type="button" class="' . esc_attr($class) . '" id="' . esc_attr($id) . '"';

    if ($described_by !== '') {
        echo ' aria-describedby="' . esc_attr($described_by) . '"';
    }

    if ($unavailable !== '') {
        echo ' disabled aria-disabled="true" title="' . esc_attr($unavailable) . '"';
    }

    echo '>' . esc_html($label) . '</button>';
}

/**
 * Saved or empty migration result.
 *
 * @param array  $report       Formatted report.
 * @param string $unavailable  Shown when this install cannot run a migration.
 */
function wmm_tolka_render_report_body($report, $unavailable = '')
{
    if (!wmm_tolka_report_has_body($report)) {
        echo '<p class="wmm-tolka-empty">';
        if ($unavailable !== '') {
            echo esc_html($unavailable . ' ' . __('Nothing was changed.', 'migration-manager'));
        } else {
            echo esc_html__('No migration has been run from this screen yet. Running the migration writes Tolka data and leaves Polylang data in place.', 'migration-manager');
        }
        echo '</p>';
        return;
    }

    if ($unavailable !== '') {
        echo '<p class="wmm-tolka-empty">' . esc_html($unavailable) . '</p>';
    }

    if (isset($report['title'])) {
        $title = trim(wmm_tolka_plain_value($report['title']));
        if ($title !== '') {
            echo '<h3 class="wmm-tolka-report-title">' . esc_html($title) . '</h3>';
        }
    }

    if (isset($report['summary'])) {
        $summary = trim(wmm_tolka_plain_value($report['summary']));
        if ($summary !== '') {
            echo '<p class="wmm-tolka-summary">' . esc_html($summary) . '</p>';
        }
    }

    if (empty($report['lines']) || !is_array($report['lines'])) {
        return;
    }

    echo '<dl class="wmm-tolka-lines">';
    $count = 0;

    foreach ($report['lines'] as $line) {
        if (!is_array($line) || $count >= 50) {
            continue;
        }

        $count++;
        $label = isset($line['label']) ? trim(wmm_tolka_plain_value($line['label'])) : '';
        $value = array_key_exists('value', $line) ? trim(wmm_tolka_plain_value($line['value'])) : '';
        echo '<dt>' . esc_html($label) . '</dt>';
        echo '<dd>' . esc_html($value) . '</dd>';
    }

    echo '</dl>';
}

/**
 * Short help when the help partial is not installed.
 */
function wmm_tolka_render_fallback_help()
{
    echo '<ol class="wmm-tolka-steps">';
    echo '<li>' . esc_html__('See whether Polylang is active.', 'migration-manager') . '</li>';
    echo '<li>' . esc_html__('Measure the public site URL. The screen shows the measured load, the estimated Tolka time, how much faster Tolka is estimated to be, and the basis for that estimate.', 'migration-manager') . '</li>';
    echo '<li>' . esc_html__('Preview languages, posts, terms, strings, menus, and media.', 'migration-manager') . '</li>';
    echo '<li>' . esc_html__('Run the migration. Polylang data stays in place.', 'migration-manager') . '</li>';
    echo '<li>' . esc_html__('Read the result. The estimate reads coefficients from includes/tolka/data/benchmarks.json. When that file marks the coefficients as placeholders, the result says so.', 'migration-manager') . '</li>';
    echo '</ol>';
}

/**
 * Wizard markup used when the screens slice is missing or unusable.
 */
function wmm_tolka_render_fallback_wizard()
{
    $home = wmm_tolka_home_url();
    $report = wmm_tolka_saved_report();
    $gaps = wmm_tolka_gap_messages();
    $report_class = 'wmm-tolka-panel';

    if (wmm_tolka_report_has_body($report) && isset($report['ok']) && $report['ok'] === false) {
        $report_class .= ' wmm-tolka-panel-error';
    }

    echo '<div id="wmm-tolka-wizard" class="wmm-tolka">';
    echo '<p class="wmm-tolka-intro">';
    echo esc_html__('Move this site from Polylang to Tolka. Measure the public URL, preview what would be copied, then run the migration when you are ready. Polylang data stays in place.', 'migration-manager');
    echo '</p>';

    echo '<div class="wmm-tolka-field">';
    echo '<label for="wmm-tolka-url" class="wmm-tolka-label">' . esc_html__('Public site URL', 'migration-manager') . '</label>';
    echo '<input type="url" class="widefat wmm-tolka-url" id="wmm-tolka-url" name="wmm_tolka_url" inputmode="url" spellcheck="false" value="' . esc_attr($home) . '" aria-describedby="wmm-tolka-url-hint">';
    echo '<p class="wmm-tolka-hint" id="wmm-tolka-url-hint">';
    echo esc_html__('Use the public address visitors open. Local and private addresses are not measured.', 'migration-manager');
    echo '</p></div>';

    echo '<p class="wmm-tolka-actions">';
    wmm_tolka_render_button(
        'wmm-tolka-measure',
        __('Measure speed', 'migration-manager'),
        isset($gaps['measure']) ? $gaps['measure'] : ''
    );
    wmm_tolka_render_button(
        'wmm-tolka-preview',
        __('Preview migration', 'migration-manager'),
        isset($gaps['preview']) ? $gaps['preview'] : ''
    );
    wmm_tolka_render_button(
        'wmm-tolka-run',
        __('Run migration', 'migration-manager'),
        isset($gaps['run']) ? $gaps['run'] : '',
        true,
        'wmm-tolka-run-hint'
    );
    echo '</p>';
    echo '<p class="wmm-tolka-hint" id="wmm-tolka-run-hint">';
    if (isset($gaps['run'])) {
        echo esc_html($gaps['run'] . ' ' . __('Nothing was changed.', 'migration-manager'));
    } else {
        echo esc_html__('Running the migration writes a Tolka package and translation meta. Polylang content, terms, and settings stay in place.', 'migration-manager');
    }
    echo '</p>';

    echo '<section class="wmm-tolka-section" aria-labelledby="wmm-tolka-speed-heading">';
    echo '<h2 id="wmm-tolka-speed-heading" class="wmm-tolka-heading">' . esc_html__('Speed', 'migration-manager') . '</h2>';
    echo '<div id="wmm-tolka-speed" class="wmm-tolka-panel" aria-live="polite">';
    $speed = isset($gaps['measure']) ? '' : wmm_tolka_capture_partial('views/speed-estimate.php');
    if ($speed !== '') {
        echo $speed;
    } elseif (isset($gaps['measure'])) {
        echo '<p class="wmm-tolka-empty">';
        echo esc_html($gaps['measure'] . ' ' . __('Nothing was changed.', 'migration-manager'));
        echo '</p>';
    } else {
        echo '<p class="wmm-tolka-empty">';
        echo esc_html__('No measurement yet. Enter the public site URL, then choose Measure speed. If the check fails, this screen shows an error and does not invent a percent.', 'migration-manager');
        echo '</p>';
    }
    echo '</div></section>';

    echo '<section class="wmm-tolka-section" aria-labelledby="wmm-tolka-preview-heading">';
    echo '<h2 id="wmm-tolka-preview-heading" class="wmm-tolka-heading">' . esc_html__('Preview', 'migration-manager') . '</h2>';
    echo '<div id="wmm-tolka-preview-result" class="wmm-tolka-panel" aria-live="polite">';
    echo '<p class="wmm-tolka-empty">';
    if (isset($gaps['preview'])) {
        echo esc_html($gaps['preview'] . ' ' . __('Nothing was changed.', 'migration-manager'));
    } else {
        echo esc_html__('Nothing has been previewed yet. Preview lists languages, posts, terms, strings, menus, and media, and does not change the site.', 'migration-manager');
    }
    echo '</p></div></section>';

    echo '<section class="wmm-tolka-section" aria-labelledby="wmm-tolka-report-heading">';
    echo '<h2 id="wmm-tolka-report-heading" class="wmm-tolka-heading">' . esc_html__('Result', 'migration-manager') . '</h2>';
    echo '<div id="wmm-tolka-report" class="' . esc_attr($report_class) . '" aria-live="polite">';
    wmm_tolka_render_report_body($report, isset($gaps['run']) ? $gaps['run'] : '');
    echo '</div></section>';

    echo '<section class="wmm-tolka-section" aria-labelledby="wmm-tolka-help-heading">';
    echo '<h2 id="wmm-tolka-help-heading" class="wmm-tolka-heading">' . esc_html__('How this works', 'migration-manager') . '</h2>';
    $help = wmm_tolka_capture_partial('views/help.php');
    if ($help !== '') {
        echo $help;
    } else {
        wmm_tolka_render_fallback_help();
    }
    echo '</section>';
    echo '</div>';
}

/**
 * Include a view partial when it exists and produces markup.
 *
 * @param string $relative Path under includes/tolka.
 * @return string
 */
function wmm_tolka_capture_partial($relative)
{
    $path = wmm_tolka_path($relative);

    if (!file_exists($path)) {
        return '';
    }

    $captured = wmm_tolka_capture_include($path);

    if (!$captured['ok'] || trim($captured['html']) === '') {
        return '';
    }

    return $captured['html'];
}

/**
 * Fallback presentation used until the styles slice is installed.
 */
function wmm_tolka_render_fallback_css()
{
    echo '<style>';
    echo '#wmm-tolka-wizard{max-width:52rem}';
    echo '#wmm-tolka-wizard .wmm-tolka-intro,#wmm-tolka-wizard .wmm-tolka-hint,#wmm-tolka-wizard .wmm-tolka-steps{max-width:46rem}';
    echo '#wmm-tolka-wizard .wmm-tolka-hint{color:#50575e}';
    echo '#wmm-tolka-wizard .wmm-tolka-label{display:block;font-weight:600;margin-bottom:4px}';
    echo '#wmm-tolka-wizard .wmm-tolka-url{max-width:40rem}';
    echo '#wmm-tolka-wizard .wmm-tolka-actions{display:flex;flex-wrap:wrap;gap:8px}';
    echo '#wmm-tolka-wizard .wmm-tolka-actions .button{margin:0}';
    echo '#wmm-tolka-wizard .button[disabled]{opacity:.65;cursor:not-allowed}';
    echo '#wmm-tolka-wizard .wmm-tolka-measured-url{word-break:break-all;margin:0 0 8px;color:#50575e}';
    echo '#wmm-tolka-wizard .wmm-tolka-heading{margin:1.4em 0 .4em}';
    echo '#wmm-tolka-wizard .wmm-tolka-panel{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:12px 16px}';
    echo '#wmm-tolka-wizard .wmm-tolka-panel-error{border-color:#d63638}';
    echo '#wmm-tolka-wizard .wmm-tolka-empty{color:#50575e;margin:0}';
    echo '#wmm-tolka-wizard .wmm-tolka-error{color:#8a2424;margin:0 0 8px}';
    echo '#wmm-tolka-wizard .wmm-tolka-stat{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #dcdcde;padding:6px 0}';
    echo '#wmm-tolka-wizard .wmm-tolka-basis{margin:12px 0 0}';
    echo '#wmm-tolka-wizard .wmm-tolka-placeholder{margin-top:12px;background:#fcf9e8;padding:8px 12px}';
    echo '#wmm-tolka-wizard .wmm-tolka-lines{display:grid;grid-template-columns:minmax(8rem,14rem) 1fr;gap:4px 16px;margin:12px 0 0}';
    echo '#wmm-tolka-wizard .wmm-tolka-lines dt,#wmm-tolka-wizard .wmm-tolka-lines dd{margin:0}';
    echo '#wmm-tolka-wizard .wmm-tolka-sample{margin:0 0 12px 1.2em}';
    echo '#wmm-tolka-wizard .wmm-tolka-is-busy,#wmm-tolka-wizard.wmm-tolka-is-busy{cursor:progress}';
    echo '.wmm-tolka-wrap .wmm-tolka-gaps,.wmm-tolka-wrap .wmm-tolka-problems{margin:.4em 0 .6em 1.2em;list-style:disc}';
    echo '#wmm-tolka-wizard [tabindex="-1"]:focus{outline:2px solid #2271b1;outline-offset:2px}';
    echo '</style>';
}

/**
 * Sentences for the fallback script. Kept out of the contract localize object.
 *
 * @return array
 */
function wmm_tolka_fallback_copy()
{
    return array(
        'measuring' => __('Measuring...', 'migration-manager'),
        'previewing' => __('Previewing...', 'migration-manager'),
        'running' => __('Migrating...', 'migration-manager'),
        'confirmRun' => __('Run the migration now? Polylang data stays in place. A Tolka package and translation meta will be written.', 'migration-manager'),
        'network' => __('The request could not be completed. Check your connection and try again. Nothing was changed.', 'migration-manager'),
        'failed' => __('The request failed. Nothing was changed.', 'migration-manager'),
        'noFetch' => __('This browser cannot run the migration screen. Use a current browser and reload the page.', 'migration-manager'),
        'languages' => __('Languages', 'migration-manager'),
        'posts' => __('Posts', 'migration-manager'),
        'terms' => __('Terms', 'migration-manager'),
        'strings' => __('Strings', 'migration-manager'),
        'menus' => __('Menus', 'migration-manager'),
        'media' => __('Media', 'migration-manager'),
        'sectionEmpty' => __('None found.', 'migration-manager'),
        'sectionUnknown' => __('This part did not report a count.', 'migration-manager'),
        'sectionCount' => __('%s found.', 'migration-manager'),
        'previewEmpty' => __('Nothing to migrate was found. The site was not changed.', 'migration-manager'),
        'missingPieces' => __('Not installed: %s.', 'migration-manager'),
        'unreadableItem' => __('This item could not be read.', 'migration-manager'),
        'unreadableReport' => __('The migration result could not be read. Polylang data was not deleted.', 'migration-manager'),
        'emptyReport' => __('The migration did not return a result. Polylang data was not deleted.', 'migration-manager'),
        'storedDetail' => __('Stored with the report.', 'migration-manager'),
        'notAvailable' => __('Not available', 'migration-manager'),
        'currentLoad' => __('Measured load', 'migration-manager'),
        'tolkaLoad' => __('Estimated on Tolka', 'migration-manager'),
        'difference' => __('Difference', 'migration-manager'),
        'languageCount' => __('Languages counted', 'migration-manager'),
        'noEstimate' => __('No speed estimate is available for this URL. Nothing was changed.', 'migration-manager'),
        'problems' => __('Details', 'migration-manager'),
        'measuredUrl' => __('Address checked', 'migration-manager'),
        'yes' => __('Yes', 'migration-manager'),
        'no' => __('No', 'migration-manager'),
        'needUrl' => __('Enter a public site URL to measure. Nothing was changed.', 'migration-manager'),
        'timeoutMeasure' => __('The speed check took too long and was stopped. Nothing was changed.', 'migration-manager'),
        'timeoutPreview' => __('The preview took too long and was stopped. Nothing was changed.', 'migration-manager'),
        'timeoutRun' => __('The migration took too long to answer. Reload this screen before trying again. Polylang data was not deleted.', 'migration-manager'),
    );
}

/**
 * Fallback behavior used until the behavior slice is installed.
 *
 * @return string
 */
function wmm_tolka_fallback_script()
{
    return <<<'JS'
(function () {
    var copy = window.wmmTolkaCopy || {};
    var config = window.wmmTolka || {};
    var active = false;
    var jobs = [
        { id: "wmm-tolka-measure", action: config.actionMeasure, target: "wmm-tolka-speed", busy: text("measuring", "Measuring..."), needsUrl: true, timeout: 35000, timeoutMessage: text("timeoutMeasure", "The speed check took too long and was stopped. Nothing was changed."), render: renderSpeed },
        { id: "wmm-tolka-preview", action: config.actionPreview, target: "wmm-tolka-preview-result", busy: text("previewing", "Previewing..."), timeout: 60000, timeoutMessage: text("timeoutPreview", "The preview took too long and was stopped. Nothing was changed."), render: renderPreview },
        { id: "wmm-tolka-run", action: config.actionRun, target: "wmm-tolka-report", busy: text("running", "Migrating..."), timeout: 120000, timeoutMessage: text("timeoutRun", "The migration took too long to answer. Reload this screen before trying again. Polylang data was not deleted."), confirmText: text("confirmRun", "Run the migration now?"), render: renderReport }
    ];

    function text(key, fallback) {
        if (copy && typeof copy[key] === "string" && copy[key] !== "") {
            return copy[key];
        }
        return fallback;
    }

    if (!window.fetch || !window.URLSearchParams || !config.ajaxUrl) {
        jobs.forEach(function (job) {
            var button = document.getElementById(job.id);
            if (!button) {
                return;
            }
            button.addEventListener("click", function () {
                showError(document.getElementById(job.target), text("noFetch", "This browser cannot run the migration screen."));
            });
        });
        return;
    }

    jobs.forEach(function (job) {
        var button = document.getElementById(job.id);
        if (!button || button.getAttribute("data-wmm-tolka-bound") === "1") {
            return;
        }
        button.setAttribute("data-wmm-tolka-bound", "1");
        button.addEventListener("click", function () {
            onClick(job);
        });
    });

    var urlInput = document.getElementById("wmm-tolka-url");
    if (urlInput && urlInput.getAttribute("data-wmm-tolka-bound") !== "1") {
        urlInput.setAttribute("data-wmm-tolka-bound", "1");
        urlInput.addEventListener("keydown", function (event) {
            if (event.key !== "Enter") {
                return;
            }
            event.preventDefault();
            var measure = document.getElementById("wmm-tolka-measure");
            if (measure && !measure.disabled) {
                measure.click();
            }
        });
    }

    function onClick(job) {
        if (active) {
            return;
        }
        if (job.confirmText && !window.confirm(job.confirmText)) {
            return;
        }
        var body = new window.URLSearchParams();
        body.set("action", job.action || "");
        body.set("nonce", config.nonce || "");
        if (job.needsUrl) {
            var input = document.getElementById("wmm-tolka-url");
            var url = input && input.value ? input.value.replace(/^\s+|\s+$/g, "") : "";
            if (!url) {
                showError(document.getElementById(job.target), text("needUrl", "Enter a public site URL to measure. Nothing was changed."));
                return;
            }
            body.set("url", url);
        }
        var controller = window.AbortController ? new window.AbortController() : null;
        var timer = null;
        if (controller && job.timeout) {
            timer = window.setTimeout(function () {
                controller.abort();
            }, job.timeout);
        }
        setBusy(true, job);
        var fetchOptions = {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: body.toString()
        };
        if (controller) {
            fetchOptions.signal = controller.signal;
        }
        window.fetch(config.ajaxUrl, fetchOptions).then(function (response) {
            return response.text().then(function (raw) {
                var data = null;
                try {
                    data = JSON.parse(raw);
                } catch (ignore) {
                    data = null;
                }
                return data;
            });
        }).then(function (data) {
            var target = document.getElementById(job.target);
            if (!data || data.success !== true) {
                showError(target, errorMessage(data));
                return;
            }
            try {
                job.render(target, data.data || {});
                focusPanel(target);
            } catch (ignore) {
                showError(target, text("failed", "The request failed. Nothing was changed."));
            }
        }).catch(function (error) {
            var timedOut = error && error.name === "AbortError";
            var message = timedOut
                ? (job.timeoutMessage || text("timeoutMeasure", "The speed check took too long and was stopped. Nothing was changed."))
                : text("network", "The request could not be completed.");
            showError(document.getElementById(job.target), message);
        }).then(function () {
            if (timer) {
                window.clearTimeout(timer);
            }
            setBusy(false, job);
        });
    }

    function errorMessage(payload) {
        if (payload && payload.data && typeof payload.data.message === "string" && payload.data.message) {
            return payload.data.message;
        }
        return text("failed", "The request failed. Nothing was changed.");
    }

    function setBusy(on, job) {
        active = on;
        var root = document.getElementById("wmm-tolka-wizard");
        if (root) {
            if (on) {
                root.classList.add("wmm-tolka-is-busy");
            } else {
                root.classList.remove("wmm-tolka-is-busy");
            }
        }
        jobs.forEach(function (entry) {
            var button = document.getElementById(entry.id);
            if (!button) {
                return;
            }
            if (on) {
                if (!button.getAttribute("data-wmm-label")) {
                    button.setAttribute("data-wmm-label", button.textContent);
                }
                button.disabled = true;
                if (entry.id === job.id) {
                    button.setAttribute("aria-busy", "true");
                    button.textContent = job.busy;
                }
                return;
            }
            button.disabled = false;
            button.removeAttribute("aria-busy");
            var label = button.getAttribute("data-wmm-label");
            if (label) {
                button.textContent = label;
            }
        });
    }

    function clear(node) {
        if (!node) {
            return;
        }
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function focusPanel(node) {
        if (!node) {
            return;
        }
        node.setAttribute("tabindex", "-1");
        if (node.focus) {
            node.focus();
        }
    }

    function showError(node, message) {
        if (!node) {
            return;
        }
        clear(node);
        node.classList.add("wmm-tolka-panel-error");
        var paragraph = document.createElement("p");
        paragraph.className = "wmm-tolka-error";
        paragraph.setAttribute("role", "alert");
        paragraph.textContent = message || text("failed", "The request failed. Nothing was changed.");
        node.appendChild(paragraph);
        focusPanel(node);
    }

    function addParagraph(target, className, message) {
        var paragraph = document.createElement("p");
        paragraph.className = className;
        paragraph.textContent = message;
        target.appendChild(paragraph);
    }

    function addStat(target, label, value) {
        var row = document.createElement("div");
        row.className = "wmm-tolka-stat";
        var name = document.createElement("span");
        name.className = "wmm-tolka-stat-label";
        name.textContent = label;
        var strong = document.createElement("strong");
        strong.className = "wmm-tolka-stat-value";
        strong.textContent = value;
        row.appendChild(name);
        row.appendChild(strong);
        target.appendChild(row);
    }

    function formatMs(value) {
        var number = typeof value === "number" ? value : parseFloat(value);
        if (isNaN(number)) {
            return text("notAvailable", "Not available");
        }
        return String(Math.round(number)) + " ms";
    }

    function checkedAddress() {
        var input = document.getElementById("wmm-tolka-url");
        if (!input || !input.value) {
            return "";
        }
        var url = input.value.replace(/^\s+|\s+$/g, "");
        if (!url) {
            return "";
        }
        return text("measuredUrl", "Address checked") + ": " + url;
    }

    function renderSpeed(target, data) {
        clear(target);
        target.classList.remove("wmm-tolka-panel-error");
        var estimate = data.estimate || {};
        var metrics = data.metrics || {};
        var address = checkedAddress();
        if (estimate.ok !== true && estimate.ok !== 1) {
            showError(target, estimate.error || text("noEstimate", "No speed estimate is available for this URL."));
            if (address) {
                addParagraph(target, "wmm-tolka-measured-url", address);
            }
            return;
        }
        if (address) {
            addParagraph(target, "wmm-tolka-measured-url", address);
        }
        addStat(target, text("currentLoad", "Measured load"), formatMs(estimate.current_ms));
        addStat(target, text("tolkaLoad", "Estimated on Tolka"), formatMs(estimate.tolka_ms));
        var difference = estimate.faster_label;
        if (typeof difference !== "string" || difference === "") {
            if (typeof estimate.faster_percent === "number") {
                difference = String(estimate.faster_percent) + "% faster";
            } else {
                difference = text("notAvailable", "Not available");
            }
        }
        addStat(target, text("difference", "Difference"), difference);
        if (typeof metrics.language_count === "number") {
            addStat(target, text("languageCount", "Languages counted"), String(metrics.language_count));
        }
        if (typeof estimate.basis === "string" && estimate.basis !== "") {
            addParagraph(target, "wmm-tolka-basis", estimate.basis);
        }
        if (estimate.provenance === "placeholder" && typeof estimate.provenance_note === "string" && estimate.provenance_note !== "") {
            addParagraph(target, "wmm-tolka-placeholder", estimate.provenance_note);
        }
    }

    function summarize(section, item) {
        if (!item || typeof item !== "object") {
            return text("unreadableItem", "This item could not be read.");
        }
        var parts = [];
        if (section === "languages") {
            parts = [item.name, item.slug, item.locale];
        } else if (section === "posts") {
            parts = [item.title, item.type, item.group];
        } else if (section === "terms") {
            parts = [item.name, item.taxonomy, item.group];
        } else if (section === "strings") {
            parts = [item.name, item.context];
        } else if (section === "menus") {
            parts = [item.name, item.location];
        } else if (section === "media") {
            parts = [item.file, item.attachment_id];
        }
        var line = [];
            parts.forEach(function (part) {
            if (part === undefined || part === null || part === "") {
                return;
            }
            var str = String(part);
            if (str.length > 180) {
                str = str.slice(0, 180) + "...";
            }
            line.push(str);
        });
        if (!line.length) {
            return text("unreadableItem", "This item could not be read.");
        }
        return line.join(" · ");
    }

    function renderProblems(target, data) {
        var errors = data && data.errors;
        if (errors && errors.length) {
            var list = document.createElement("ul");
            list.className = "wmm-tolka-problems";
            errors.forEach(function (error) {
                if (typeof error !== "string" || error === "") {
                    return;
                }
                var item = document.createElement("li");
                item.textContent = error;
                list.appendChild(item);
            });
            if (list.childNodes.length) {
                target.appendChild(list);
            }
        }
        var missing = data && data.missing;
        if (missing && missing.length) {
            addParagraph(target, "wmm-tolka-error", text("missingPieces", "Not installed: %s.").replace("%s", missing.join(", ")));
        }
    }

    function renderPreview(target, data) {
        clear(target);
        target.classList.remove("wmm-tolka-panel-error");
        var sections = [
            ["languages", text("languages", "Languages")],
            ["posts", text("posts", "Posts")],
            ["terms", text("terms", "Terms")],
            ["strings", text("strings", "Strings")],
            ["menus", text("menus", "Menus")],
            ["media", text("media", "Media")]
        ];
        var counts = data.counts || {};
        var sample = data.sample || {};
        var total = 0;
        var known = 0;
        if (data.errors && data.errors.length) {
            renderProblems(target, { errors: data.errors });
        }
        sections.forEach(function (section) {
            var count = counts[section[0]];
            var number = typeof count === "number" ? count : parseInt(count, 10);
            if (!isNaN(number)) {
                total += number;
                known += 1;
            }
            var heading = document.createElement("h3");
            heading.className = "wmm-tolka-section-title";
            heading.textContent = section[1];
            target.appendChild(heading);
            var line = document.createElement("p");
            if (count === undefined || count === null || count === "") {
                line.className = "wmm-tolka-empty";
                line.textContent = text("sectionUnknown", "This part did not report a count.");
            } else if (Number(count) === 0) {
                line.className = "wmm-tolka-empty";
                line.textContent = text("sectionEmpty", "None found.");
            } else {
                line.textContent = text("sectionCount", "%s found.").replace("%s", String(count));
            }
            target.appendChild(line);
            var items = sample[section[0]];
            if (!items || !items.length) {
                return;
            }
            var list = document.createElement("ul");
            list.className = "wmm-tolka-sample";
            items.slice(0, 20).forEach(function (item) {
                var entry = document.createElement("li");
                entry.textContent = summarize(section[0], item);
                list.appendChild(entry);
            });
            target.appendChild(list);
        });
        if (known === sections.length && total === 0 && !(data.errors && data.errors.length)) {
            var empty = document.createElement("p");
            empty.className = "wmm-tolka-empty";
            empty.textContent = text("previewEmpty", "Nothing to migrate was found. The site was not changed.");
            target.insertBefore(empty, target.firstChild);
        }
        renderProblems(target, { missing: data.missing });
    }

    function plainValue(value) {
        if (value === null || value === undefined) {
            return "";
        }
        if (typeof value === "boolean") {
            return value ? text("yes", "Yes") : text("no", "No");
        }
        if (typeof value === "string" || typeof value === "number") {
            return String(value);
        }
        return text("storedDetail", "Stored with the report.");
    }

    function renderReport(target, report) {
        clear(target);
        if (!report || typeof report !== "object") {
            showError(target, text("unreadableReport", "The migration result could not be read."));
            return;
        }
        if (report.ok === false) {
            target.classList.add("wmm-tolka-panel-error");
        } else {
            target.classList.remove("wmm-tolka-panel-error");
        }
        if (report.title) {
            var heading = document.createElement("h3");
            heading.className = "wmm-tolka-report-title";
            heading.textContent = plainValue(report.title);
            target.appendChild(heading);
        }
        if (report.summary) {
            addParagraph(target, "wmm-tolka-summary", plainValue(report.summary));
        }
        var lines = report.lines;
        if (lines && lines.length) {
            var list = document.createElement("dl");
            list.className = "wmm-tolka-lines";
            lines.slice(0, 50).forEach(function (line) {
                if (!line || typeof line !== "object") {
                    return;
                }
                var term = document.createElement("dt");
                term.textContent = plainValue(line.label);
                var detail = document.createElement("dd");
                detail.textContent = plainValue(line.value);
                list.appendChild(term);
                list.appendChild(detail);
            });
            target.appendChild(list);
        }
        if (!report.title && !report.summary && (!lines || !lines.length)) {
            showError(target, text("emptyReport", "The migration did not return a result."));
            return;
        }
        renderProblems(target, report);
    }
})();
JS;
}

/**
 * Print the fallback script and its copy.
 */
function wmm_tolka_render_fallback_js()
{
    echo '<script>';
    echo 'var wmmTolka = ' . wmm_tolka_json(wmm_tolka_script_data()) . ';';
    echo 'var wmmTolkaCopy = ' . wmm_tolka_json(wmm_tolka_fallback_copy()) . ';';
    echo '</script>';
    echo '<script id="wmm-tolka-fallback">';
    echo wmm_tolka_fallback_script();
    echo '</script>';
}

/**
 * Render the Migrate to Tolka admin screen.
 */
function wmm_tolka_render_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(
            esc_html__('You do not have permission to migrate this site.', 'migration-manager'),
            esc_html__('Migrate to Tolka', 'migration-manager'),
            array('response' => 403)
        );
    }

    $view_html = '';
    $view_failed = false;
    $view_path = wmm_tolka_path('views/wizard.php');

    if (file_exists($view_path)) {
        $captured = wmm_tolka_capture_include($view_path);
        if (!$captured['ok']) {
            $view_failed = true;
        } else {
            $view_html = $captured['html'];
        }
    }

    $view_has_wizard = wmm_tolka_html_has_id($view_html, 'wmm-tolka-wizard');
    $view_has_title = is_string($view_html) && stripos($view_html, '<h1') !== false;

    echo '<div class="wrap wmm-tolka-wrap">';

    if (!$view_has_title) {
        wmm_tolka_render_title();
    }

    wmm_tolka_render_load_errors();
    wmm_tolka_render_status();
    wmm_tolka_render_gaps();

    if ($view_failed) {
        echo '<div class="notice notice-error wmm-tolka-notice"><p>';
        echo esc_html__('The migration screen could not be displayed. A basic screen is shown instead. Nothing was changed.', 'migration-manager');
        echo '</p></div>';
    }

    if ($view_has_wizard) {
        echo $view_html;
    } else {
        if ($view_html !== '') {
            echo $view_html;
        }
        wmm_tolka_render_fallback_wizard();
    }

    echo '<noscript><div class="notice notice-warning wmm-tolka-notice"><p>';
    echo esc_html__('This screen uses JavaScript to measure, preview, and migrate. Enable JavaScript, then reload the page. Nothing has been changed.', 'migration-manager');
    echo '</p></div></noscript>';

    if (!file_exists(wmm_tolka_path('assets/tolka-admin.css'))) {
        wmm_tolka_render_fallback_css();
    }

    if (!file_exists(wmm_tolka_path('assets/tolka-admin.js'))) {
        wmm_tolka_render_fallback_js();
    }

    echo '</div>';
}

add_action('admin_menu', 'wmm_tolka_register_menu');
add_action('admin_enqueue_scripts', 'wmm_tolka_enqueue_assets');
add_action('wp_ajax_wmm_tolka_measure', 'wmm_tolka_ajax_measure');
add_action('wp_ajax_wmm_tolka_preview', 'wmm_tolka_ajax_preview');
add_action('wp_ajax_wmm_tolka_run', 'wmm_tolka_ajax_run');

wmm_tolka_load_classes();
