<?php

/**
 * Migration Manager - AJAX Scrape Handler
 * 
 * @package MigrationManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle scrape request via AJAX
 */
function migration_manager_handle_scrape_request()
{
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $url = sanitize_url($_POST['url']);

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array(
            'message' => __('Invalid URL provided', 'migration-manager')
        ));
        return;
    }

    // Log the scraping attempt
    error_log("Migration Manager: Starting scrape for URL: " . $url);

    // Call the scraper API
    $result = migration_manager_call_scraper_api($url);

    if ($result['success']) {
        // Save successful scrape to database
        migration_manager_save_scrape_to_db($url, $result['data'], 'success');

        wp_send_json_success($result['data']);
    } else {
        // Save failed scrape to database
        migration_manager_save_scrape_to_db($url, array('error' => $result['message']), 'failed');

        wp_send_json_error(array(
            'message' => $result['message']
        ));
    }
}

/**
 * Call the Python scraper API
 */
function migration_manager_call_scraper_api($url)
{
    $api_base_url = get_option('migration_manager_api_url', 'http://localhost:8000');
    $api_url = rtrim($api_base_url, '/') . '/scrape';

    error_log("Migration Manager: Calling API: " . $api_url);

    // Load AI settings from plugin options
    $ai_enabled  = (bool) get_option('migration_manager_ai_enabled', false);
    $ai_provider = get_option('migration_manager_ai_provider', 'none');
    $ai_api_key  = get_option('migration_manager_ai_api_key', '');
    $ai_model    = get_option('migration_manager_ai_model', '');

    // If AI is enabled but no key is configured for a real provider, fail fast with a clear message
    if ($ai_enabled && $ai_provider !== 'none' && empty($ai_api_key)) {
        $msg = __('AI processing is enabled, but no API key is configured. Please add an API key in the Migration Manager settings or disable AI.', 'migration-manager');
        error_log('Migration Manager: ' . $msg);

        return array(
            'success' => false,
            'message' => $msg,
        );
    }

    $body = array(
        'url' => $url,
        'ai'  => array(
            'enabled'  => $ai_enabled,
            'provider' => $ai_provider,
            'api_key'  => $ai_api_key,
            'model'    => $ai_model,
        ),
    );

    // Set up request args
    $args = array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress Migration Manager Plugin v' . MIGRATION_MANAGER_VERSION
        ),
        'body'    => wp_json_encode($body),
        'method'  => 'POST',
    );

    // Make the API request
    $response = wp_remote_request($api_url, $args);

    // Check for errors
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("Migration Manager: API request failed: " . $error_message);

        return array(
            'success' => false,
            'message' => sprintf(__('API request failed: %s', 'migration-manager'), $error_message)
        );
    }

    $status_code = wp_remote_retrieve_response_code($response);
    error_log("Migration Manager: API response status: " . $status_code);

    if ($status_code !== 200) {
        $error_message = sprintf(__('API returned status code: %d', 'migration-manager'), $status_code);
        error_log("Migration Manager: " . $error_message);

        return array(
            'success' => false,
            'message' => $error_message
        );
    }

    // Parse JSON response
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $error_message = __('Invalid JSON response from API', 'migration-manager');
        error_log("Migration Manager: " . $error_message . " - JSON Error: " . json_last_error_msg());
        error_log("Migration Manager: Response body: " . substr($body, 0, 500));

        return array(
            'success' => false,
            'message' => $error_message
        );
    }

    // Check if API returned success
    if (!isset($data['status']) || $data['status'] !== 'success') {
        $error_message = isset($data['message']) ? $data['message'] : __('Unknown API error', 'migration-manager');
        error_log("Migration Manager: API error: " . $error_message);

        return array(
            'success' => false,
            'message' => $error_message
        );
    }

    // Log success
    $item_count = isset($data['data']) ? count($data['data']) : 0;
    error_log("Migration Manager: Scrape successful. Found " . $item_count . " items.");

    return array(
        'success' => true,
        'data' => $data
    );
}

/**
 * Save scrape data to database
 */
function migration_manager_save_scrape_to_db($url, $data, $status = 'scraped')
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $result = $wpdb->insert(
        $table_name,
        array(
            'url' => $url,
            'scraped_data' => json_encode($data),
            'scraped_at' => current_time('mysql'),
            'status' => $status
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s'
        )
    );

    if ($result === false) {
        error_log("Migration Manager: Failed to save scrape to database: " . $wpdb->last_error);
    } else {
        error_log("Migration Manager: Scrape saved to database with ID: " . $wpdb->insert_id);
    }

    return $result;
}

/**
 * Get scrape data from database
 */
function migration_manager_get_scrape_from_db($url)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $result = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE url = %s ORDER BY scraped_at DESC LIMIT 1",
            $url
        ),
        ARRAY_A
    );

    if ($result && isset($result['scraped_data'])) {
        $result['scraped_data'] = json_decode($result['scraped_data'], true);
    }

    return $result;
}

/**
 * Handle load previous scrape request
 */
function migration_manager_handle_load_scrape()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $url = sanitize_url($_POST['url']);

    if (empty($url)) {
        wp_send_json_error(array(
            'message' => __('URL is required', 'migration-manager')
        ));
        return;
    }

    $scrape_data = migration_manager_get_scrape_from_db($url);

    if (!$scrape_data) {
        wp_send_json_error(array(
            'message' => __('No previous scrape found for this URL', 'migration-manager')
        ));
        return;
    }

    wp_send_json_success($scrape_data);
}

/**
 * Test API connection
 */
function migration_manager_test_api_connection()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $api_base_url = get_option('migration_manager_api_url', 'http://localhost:8000');
    $api_url = rtrim($api_base_url, '/') . '/';

    // Test basic connection
    $response = wp_remote_get($api_url, array(
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'WordPress Migration Manager Plugin v' . MIGRATION_MANAGER_VERSION
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => sprintf(__('API connection failed: %s', 'migration-manager'), $response->get_error_message())
        ));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code === 200) {
        wp_send_json_success(array(
            'message' => __('API connection successful', 'migration-manager'),
            'status_code' => $status_code,
            'response' => json_decode($body, true)
        ));
    } else {
        wp_send_json_error(array(
            'message' => sprintf(__('API returned status code: %d', 'migration-manager'), $status_code),
            'status_code' => $status_code,
            'response' => $body
        ));
    }
}

/**
 * Test AI connection
 */
function migration_manager_test_ai_connection()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_settings_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
    }

    $api_base_url = get_option('migration_manager_api_url', 'http://localhost:8000');
    $api_url      = rtrim($api_base_url, '/') . '/ai-test';

    // Load AI settings
    $ai_enabled  = (bool) get_option('migration_manager_ai_enabled', false);
    $ai_provider = get_option('migration_manager_ai_provider', 'none');
    $ai_api_key  = get_option('migration_manager_ai_api_key', '');
    $ai_model    = get_option('migration_manager_ai_model', '');

    if (!$ai_enabled || $ai_provider === 'none') {
        wp_send_json_error(array(
            'message' => __('AI is disabled or provider is set to None. Enable AI and choose a provider first.', 'migration-manager'),
        ));
    }

    if (empty($ai_api_key)) {
        wp_send_json_error(array(
            'message' => __('No AI API key configured. Please add an API key in the AI settings.', 'migration-manager'),
        ));
    }

    $body = array(
        'ai' => array(
            'provider' => $ai_provider,
            'api_key'  => $ai_api_key,
            'model'    => $ai_model,
        ),
    );

    $args = array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'WordPress Migration Manager Plugin v' . MIGRATION_MANAGER_VERSION,
        ),
        'body'   => wp_json_encode($body),
        'method' => 'POST',
    );

    $response = wp_remote_request($api_url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => sprintf(__('AI test request failed: %s', 'migration-manager'), $response->get_error_message()),
        ));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body        = wp_remote_retrieve_body($response);
    $data        = json_decode($body, true);

    if ($status_code !== 200 || !isset($data['success']) || !$data['success']) {
        $error_detail = isset($data['detail']['error']) ? $data['detail']['error'] : ($data['detail'] ?? 'Unknown error');
        wp_send_json_error(array(
            'message' => sprintf(
                __('AI test failed (HTTP %d): %s', 'migration-manager'),
                $status_code,
                $error_detail
            ),
        ));
    }

    wp_send_json_success(array(
        'message' => sprintf(
            __('AI test successful! Provider: %s, Model: %s, Reply: %s', 'migration-manager'),
            esc_html($data['provider']),
            esc_html($data['model']),
            esc_html($data['reply'])
        ),
    ));
}

// Register AJAX actions
add_action('wp_ajax_migration_manager_scrape', 'migration_manager_handle_scrape_request');
add_action('wp_ajax_migration_manager_load_scrape', 'migration_manager_handle_load_scrape');
add_action('wp_ajax_migration_manager_test_api', 'migration_manager_test_api_connection');
add_action('wp_ajax_migration_manager_test_ai', 'migration_manager_test_ai_connection');
/**
 * Handle manual cleanup of old data
 */
function migration_manager_handle_cleanup_data()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $cleanup_days = get_option('migration_manager_cleanup_days', 30);
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$cleanup_days} days"));

    // Count records to be deleted
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE scraped_at < %s",
        $cutoff_date
    ));

    if ($count == 0) {
        wp_send_json_success(array(
            'message' => __('No old data found to clean up.', 'migration-manager')
        ));
        return;
    }

    // Delete old records
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE scraped_at < %s",
        $cutoff_date
    ));

    if ($deleted === false) {
        wp_send_json_error(array(
            'message' => __('Failed to clean up data: ', 'migration-manager') . $wpdb->last_error
        ));
        return;
    }

    wp_send_json_success(array(
        'message' => sprintf(__('Successfully cleaned up %d old records.', 'migration-manager'), $deleted)
    ));
}

/**
 * Handle clearing all scrape data
 */
function migration_manager_handle_clear_all_data()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    // Count total records
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    if ($total == 0) {
        wp_send_json_success(array(
            'message' => __('No data found to clear.', 'migration-manager')
        ));
        return;
    }

    // Clear all records
    $result = $wpdb->query("TRUNCATE TABLE $table_name");

    if ($result === false) {
        wp_send_json_error(array(
            'message' => __('Failed to clear data: ', 'migration-manager') . $wpdb->last_error
        ));
        return;
    }

    wp_send_json_success(array(
        'message' => sprintf(__('Successfully cleared %d records from database.', 'migration-manager'), $total)
    ));
}

/**
 * Handle exporting all scrape data
 */
function migration_manager_handle_export_all_data()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    // Get all scrape data
    $scrapes = $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY scraped_at DESC",
        ARRAY_A
    );

    if (empty($scrapes)) {
        wp_send_json_error(array(
            'message' => __('No data found to export.', 'migration-manager')
        ));
        return;
    }

    // Process the data for export
    $export_data = array(
        'export_info' => array(
            'plugin_version' => MIGRATION_MANAGER_VERSION,
            'export_date' => current_time('mysql'),
            'total_records' => count($scrapes),
            'wordpress_version' => get_bloginfo('version'),
            'site_url' => get_site_url()
        ),
        'scrapes' => array()
    );

    foreach ($scrapes as $scrape) {
        // Decode JSON data for export
        $scrape_data = json_decode($scrape['scraped_data'], true);

        $export_data['scrapes'][] = array(
            'id' => $scrape['id'],
            'url' => $scrape['url'],
            'scraped_at' => $scrape['scraped_at'],
            'status' => $scrape['status'],
            'scraped_data' => $scrape_data
        );
    }

    wp_send_json_success($export_data);
}

/**
 * Get scrape statistics for dashboard
 */
function migration_manager_get_scrape_stats()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $stats = array(
        'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
        'successful' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success'"),
        'failed' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'"),
        'today' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE DATE(scraped_at) = %s",
            current_time('Y-m-d')
        )),
        'this_week' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE scraped_at >= %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        )),
        'oldest' => $wpdb->get_var("SELECT MIN(scraped_at) FROM $table_name"),
        'newest' => $wpdb->get_var("SELECT MAX(scraped_at) FROM $table_name")
    );

    return $stats;
}

/**
 * Get recent scrapes for display
 */
function migration_manager_get_recent_scrapes($limit = 10)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $recent_scrapes = $wpdb->get_results($wpdb->prepare(
        "SELECT id, url, scraped_at, status FROM $table_name ORDER BY scraped_at DESC LIMIT %d",
        $limit
    ), ARRAY_A);

    return $recent_scrapes;
}

/**
 * Clean up old scrapes automatically (called by cron)
 */
function migration_manager_auto_cleanup()
{
    $auto_cleanup = get_option('migration_manager_auto_cleanup', 0);

    if (!$auto_cleanup) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $cleanup_days = get_option('migration_manager_cleanup_days', 30);
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$cleanup_days} days"));

    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE scraped_at < %s",
        $cutoff_date
    ));

    if ($deleted > 0) {
        error_log("Migration Manager: Auto cleanup removed {$deleted} old scrape records");
    }
}

/**
 * Enforce max scrapes limit
 */
function migration_manager_enforce_max_scrapes()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $max_scrapes = get_option('migration_manager_max_scrapes', 100);
    $current_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    if ($current_count > $max_scrapes) {
        $excess = $current_count - $max_scrapes;

        // Delete oldest records
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name ORDER BY scraped_at ASC LIMIT %d",
            $excess
        ));

        error_log("Migration Manager: Removed {$excess} old scrapes to enforce limit of {$max_scrapes}");
    }
}




/**
 * Handle delete group request via AJAX
 */
function migration_manager_handle_delete_group()
{
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $url = sanitize_url($_POST['url']);
    $group_index = intval($_POST['group_index']);

    if (empty($url)) {
        wp_send_json_error(array(
            'message' => __('URL is required', 'migration-manager')
        ));
        return;
    }

    if ($group_index < 0) {
        wp_send_json_error(array(
            'message' => __('Invalid group index', 'migration-manager')
        ));
        return;
    }

    // Get existing scrape data
    $scrape_data = migration_manager_get_scrape_from_db($url);

    if (!$scrape_data) {
        wp_send_json_error(array(
            'message' => __('No scrape data found for this URL', 'migration-manager')
        ));
        return;
    }

    $scraped_json = $scrape_data['scraped_data'];

    // Check if data has the expected structure
    if (!isset($scraped_json['data']) || !is_array($scraped_json['data'])) {
        wp_send_json_error(array(
            'message' => __('Invalid scrape data structure', 'migration-manager')
        ));
        return;
    }

    $data_array = $scraped_json['data'];

    // Check if group index exists
    if (!isset($data_array[$group_index])) {
        wp_send_json_error(array(
            'message' => __('Group not found at specified index', 'migration-manager')
        ));
        return;
    }

    // Remove the group from the array
    array_splice($data_array, $group_index, 1);

    // Update the scraped data
    $scraped_json['data'] = $data_array;

    // Update stats if they exist
    if (isset($scraped_json['stats'])) {
        $scraped_json['stats']['total'] = count($data_array);

        // Recalculate type counts
        $type_counts = array();
        foreach ($data_array as $item) {
            $type = $item['type'] ?? 'unknown';
            $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
        }

        foreach ($type_counts as $type => $count) {
            $scraped_json['stats'][$type] = $count;
        }
    }

    // Save updated data back to database
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $result = $wpdb->update(
        $table_name,
        array(
            'scraped_data' => json_encode($scraped_json),
            'scraped_at' => current_time('mysql') // Update timestamp to show it was modified
        ),
        array('url' => $url),
        array('%s', '%s'),
        array('%s')
    );

    if ($result === false) {
        wp_send_json_error(array(
            'message' => __('Failed to save updated data: ', 'migration-manager') . $wpdb->last_error
        ));
        return;
    }

    // Log the deletion
    error_log("Migration Manager: Group at index {$group_index} deleted from URL: " . $url);

    wp_send_json_success(array(
        'message' => __('Group deleted successfully', 'migration-manager'),
        'updated_data' => $scraped_json,
        'deleted_index' => $group_index,
        'remaining_count' => count($data_array)
    ));
}

/**
 * Handle delete multiple groups request via AJAX
 */
function migration_manager_handle_delete_multiple_groups()
{
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $url = sanitize_url($_POST['url']);
    $group_indices = $_POST['group_indices'] ?? array();

    if (empty($url)) {
        wp_send_json_error(array(
            'message' => __('URL is required', 'migration-manager')
        ));
        return;
    }

    if (!is_array($group_indices) || empty($group_indices)) {
        wp_send_json_error(array(
            'message' => __('No group indices provided', 'migration-manager')
        ));
        return;
    }

    // Sanitize and validate indices
    $group_indices = array_map('intval', $group_indices);
    $group_indices = array_filter($group_indices, function ($index) {
        return $index >= 0;
    });

    if (empty($group_indices)) {
        wp_send_json_error(array(
            'message' => __('No valid group indices provided', 'migration-manager')
        ));
        return;
    }

    // Get existing scrape data
    $scrape_data = migration_manager_get_scrape_from_db($url);

    if (!$scrape_data) {
        wp_send_json_error(array(
            'message' => __('No scrape data found for this URL', 'migration-manager')
        ));
        return;
    }

    $scraped_json = $scrape_data['scraped_data'];

    // Check if data has the expected structure
    if (!isset($scraped_json['data']) || !is_array($scraped_json['data'])) {
        wp_send_json_error(array(
            'message' => __('Invalid scrape data structure', 'migration-manager')
        ));
        return;
    }

    $data_array = $scraped_json['data'];

    // Sort indices in descending order to avoid index shifting issues
    rsort($group_indices);

    $deleted_count = 0;
    foreach ($group_indices as $index) {
        if (isset($data_array[$index])) {
            array_splice($data_array, $index, 1);
            $deleted_count++;
        }
    }

    if ($deleted_count === 0) {
        wp_send_json_error(array(
            'message' => __('No groups were found at the specified indices', 'migration-manager')
        ));
        return;
    }

    // Update the scraped data
    $scraped_json['data'] = $data_array;

    // Update stats if they exist
    if (isset($scraped_json['stats'])) {
        $scraped_json['stats']['total'] = count($data_array);

        // Recalculate type counts
        $type_counts = array();
        foreach ($data_array as $item) {
            $type = $item['type'] ?? 'unknown';
            $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
        }

        foreach ($type_counts as $type => $count) {
            $scraped_json['stats'][$type] = $count;
        }
    }

    // Save updated data back to database
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $result = $wpdb->update(
        $table_name,
        array(
            'scraped_data' => json_encode($scraped_json),
            'scraped_at' => current_time('mysql')
        ),
        array('url' => $url),
        array('%s', '%s'),
        array('%s')
    );

    if ($result === false) {
        wp_send_json_error(array(
            'message' => __('Failed to save updated data: ', 'migration-manager') . $wpdb->last_error
        ));
        return;
    }

    // Log the deletion
    error_log("Migration Manager: {$deleted_count} groups deleted from URL: " . $url);

    wp_send_json_success(array(
        'message' => sprintf(__('%d groups deleted successfully', 'migration-manager'), $deleted_count),
        'updated_data' => $scraped_json,
        'deleted_count' => $deleted_count,
        'remaining_count' => count($data_array)
    ));
}

/**
 * Handle download images request via AJAX
 */
function migration_manager_handle_download_images()
{
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $url = sanitize_url($_POST['url']);

    if (empty($url)) {
        wp_send_json_error(array(
            'message' => __('URL is required', 'migration-manager')
        ));
        return;
    }

    // Get existing scrape data
    $scrape_data = migration_manager_get_scrape_from_db($url);

    if (!$scrape_data) {
        wp_send_json_error(array(
            'message' => __('No scrape data found for this URL', 'migration-manager')
        ));
        return;
    }

    $scraped_json = $scrape_data['scraped_data'];

    // Check if data has the expected structure
    if (!isset($scraped_json['data']) || !is_array($scraped_json['data'])) {
        wp_send_json_error(array(
            'message' => __('Invalid scrape data structure', 'migration-manager')
        ));
        return;
    }

    // Extract all image URLs from scraped data
    $image_urls = migration_manager_extract_image_urls($scraped_json['data']);

    if (empty($image_urls)) {
        wp_send_json_success(array(
            'message' => __('No images found in scraped content', 'migration-manager'),
            'downloaded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total' => 0,
            'processed' => 0,
            'updated_data' => $scraped_json
        ));
        return;
    }

    // Check if this is a batch request
    $batch_index = isset($_POST['batch_index']) ? intval($_POST['batch_index']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
    $url_mapping = isset($_POST['url_mapping']) ? json_decode(stripslashes($_POST['url_mapping']), true) : array();
    
    if (!is_array($url_mapping)) {
        $url_mapping = array();
    }

    // Process batch
    $total_images = count($image_urls);
    $batch_start = $batch_index * $batch_size;
    $batch_end = min($batch_start + $batch_size, $total_images);
    $batch_images = array_slice($image_urls, $batch_start, $batch_size);
    
    // Process batch with progress updates
    $batch_result = migration_manager_process_image_batch($batch_images, $url, $url_mapping, $batch_start, $total_images);
    
    // Merge URL mappings
    $url_mapping = array_merge($url_mapping, $batch_result['url_mapping']);
    
    // Get accumulated totals from previous batches
    $previous_downloaded = isset($_POST['previous_downloaded']) ? intval($_POST['previous_downloaded']) : 0;
    $previous_skipped = isset($_POST['previous_skipped']) ? intval($_POST['previous_skipped']) : 0;
    $previous_failed = isset($_POST['previous_failed']) ? intval($_POST['previous_failed']) : 0;
    
    // Accumulate totals
    $total_downloaded = $previous_downloaded + $batch_result['total_downloaded'];
    $total_skipped = $previous_skipped + $batch_result['total_skipped'];
    $total_failed = $previous_failed + $batch_result['total_failed'];
    
    // Check if more batches needed
    $processed = $batch_end;
    $is_complete = $processed >= $total_images;
    
    if ($is_complete) {
        // All images processed, update scraped data
        $updated_data = migration_manager_replace_image_urls($scraped_json['data'], $url_mapping);
        
        // Save updated data back to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'migration_manager_scrapes';
        
        $scraped_json_updated = array(
            'data' => $updated_data,
            'url' => $url
        );
        
        if (isset($scraped_json['stats'])) {
            $scraped_json_updated['stats'] = $scraped_json['stats'];
        }
        
        $wpdb->update(
            $table_name,
            array(
                'scraped_data' => json_encode($scraped_json_updated),
                'scraped_at' => current_time('mysql')
            ),
            array('url' => $url),
            array('%s', '%s'),
            array('%s')
        );
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Downloaded %d images, skipped %d (already in library), failed %d', 'migration-manager'),
                $total_downloaded,
                $total_skipped,
                $total_failed
            ),
            'downloaded' => $total_downloaded,
            'skipped' => $total_skipped,
            'failed' => $total_failed,
            'total' => $total_images,
            'processed' => $processed,
            'complete' => true,
            'updated_data' => $scraped_json_updated,
            'url_mapping' => $url_mapping
        ));
        } else {
            // More batches to process
            // Get the last processed image detail for status
            $last_detail = !empty($batch_result['processed_details']) ? end($batch_result['processed_details']) : null;
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Processing batch %d/%d...', 'migration-manager'),
                    $batch_index + 1,
                    ceil($total_images / $batch_size)
                ),
                'downloaded' => $total_downloaded,
                'skipped' => $total_skipped,
                'failed' => $total_failed,
                'total' => $total_images,
                'processed' => $processed,
                'complete' => false,
                'batch_index' => $batch_index + 1,
                'url_mapping' => $url_mapping,
                'progress' => round(($processed / $total_images) * 100, 1),
                'current_image' => $last_detail
            ));
        }
}

/**
 * Process a batch of images
 */
function migration_manager_process_image_batch($image_urls, $source_url, $existing_url_mapping = array(), $start_index = 0, $total_images = 0)
{
    $downloaded = 0;
    $skipped = 0;
    $failed = 0;
    $url_mapping = $existing_url_mapping;
    $processed_details = array(); // Track individual image processing

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $image_index = $start_index;
    foreach ($image_urls as $image_info) {
        $image_index++;
        $original_url = $image_info['url'];
        $alt_text = $image_info['alt'];
        $image_filename = basename(parse_url($original_url, PHP_URL_PATH)) ?: 'image';

        // Skip if already processed in previous batch
        if (isset($url_mapping[$original_url])) {
            $skipped++;
            $processed_details[] = array(
                'index' => $image_index,
                'total' => $total_images,
                'filename' => $image_filename,
                'status' => 'skipped',
                'message' => 'Already processed'
            );
            continue;
        }

        // Check if image already exists in media library
        $existing_attachment = migration_manager_find_attachment_by_url($original_url);

        if ($existing_attachment) {
            // Image already exists, use it
            $wp_url = wp_get_attachment_url($existing_attachment);
            $url_mapping[$original_url] = $wp_url;
            $skipped++;
            $processed_details[] = array(
                'index' => $image_index,
                'total' => $total_images,
                'filename' => $image_filename,
                'status' => 'skipped',
                'message' => 'Already in media library'
            );
            continue;
        }

        // Track progress: downloading
        $processed_details[] = array(
            'index' => $image_index,
            'total' => $total_images,
            'filename' => $image_filename,
            'status' => 'downloading',
            'message' => 'Downloading image...'
        );

        // Try to download and upload the image
        try {
            // Make URL absolute if relative
            $absolute_url = migration_manager_make_url_absolute($original_url, $source_url);

            // Check file size before downloading (prevent memory issues)
            $response = wp_remote_head($absolute_url, array('timeout' => 10));
            if (!is_wp_error($response)) {
                $content_length = wp_remote_retrieve_header($response, 'content-length');
                if ($content_length) {
                    $file_size_mb = $content_length / (1024 * 1024);
                    $max_upload_size = wp_max_upload_size() / (1024 * 1024);
                    
                    if ($file_size_mb > $max_upload_size) {
                        error_log("Migration Manager: Image {$absolute_url} exceeds upload limit ({$file_size_mb}MB > {$max_upload_size}MB)");
                        $failed++;
                        continue;
                    }
                }
            }

            // Download the image file
            $tmp = download_url($absolute_url);
            
            if (is_wp_error($tmp)) {
                error_log("Migration Manager: Failed to download image {$absolute_url}: " . $tmp->get_error_message());
                $failed++;
                continue;
            }

            // Get file extension and create proper filename
            $file_array = array();
            $file_array['name'] = basename(parse_url($absolute_url, PHP_URL_PATH));
            
            // If no extension, try to get from content type
            if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
                $file_array['name'] .= '.jpg'; // Default to jpg
            }
            
            // Sanitize filename
            $file_array['name'] = sanitize_file_name($file_array['name']);
            $file_array['tmp_name'] = $tmp;

            // Upload file to WordPress
            $attachment_id = media_handle_sideload($file_array, 0, $alt_text);

            // Clean up temp file if still exists
            if (file_exists($tmp)) {
                @unlink($tmp);
            }

            if (is_wp_error($attachment_id)) {
                error_log("Migration Manager: Failed to upload image {$absolute_url}: " . $attachment_id->get_error_message());
                $failed++;
                continue;
            }

            // Ensure we have a valid attachment ID
            if (!is_numeric($attachment_id)) {
                error_log("Migration Manager: Invalid attachment ID returned for {$absolute_url}");
                $failed++;
                continue;
            }

            // Get the WordPress URL for the uploaded image
            $wp_url = wp_get_attachment_url($attachment_id);
            if (!$wp_url) {
                error_log("Migration Manager: Failed to get URL for attachment {$attachment_id}");
                $failed++;
                continue;
            }

            $url_mapping[$original_url] = $wp_url;
            $downloaded++;

            // Store original URL in attachment meta for future duplicate detection
            update_post_meta($attachment_id, '_migration_manager_original_url', $original_url);
            
            // Track progress: completed (this is the last status for this image)
            $processed_details[] = array(
                'index' => $image_index,
                'total' => $total_images,
                'filename' => $image_filename,
                'status' => 'completed',
                'message' => 'Uploaded successfully'
            );

        } catch (Exception $e) {
            error_log("Migration Manager: Exception downloading image {$original_url}: " . $e->getMessage());
            $failed++;
            $processed_details[] = array(
                'index' => $image_index,
                'total' => $total_images,
                'filename' => $image_filename,
                'status' => 'failed',
                'message' => 'Failed: ' . $e->getMessage()
            );
            continue;
        }
    }

    return array(
        'url_mapping' => $url_mapping,
        'total_downloaded' => $downloaded,
        'total_skipped' => $skipped,
        'total_failed' => $failed,
        'processed_details' => $processed_details
    );
}

/**
 * Extract all image URLs from scraped data
 */
function migration_manager_extract_image_urls($data)
{
    $image_urls = array();
    $seen_urls = array();

    foreach ($data as $item) {
        // Check if item is an image
        if (isset($item['type']) && $item['type'] === 'image' && isset($item['url'])) {
            $image_url = $item['url'];
            // Skip duplicates
            if (!in_array($image_url, $seen_urls)) {
                $image_urls[] = array(
                    'url' => $image_url,
                    'alt' => isset($item['alt']) ? $item['alt'] : '',
                    'source' => isset($item['source']) ? $item['source'] : 'img_tag'
                );
                $seen_urls[] = $image_url;
            }
        }

        // Check if item is a group with children
        if (isset($item['type']) && $item['type'] === 'group' && isset($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                if (isset($child['type']) && $child['type'] === 'image' && isset($child['url'])) {
                    $image_url = $child['url'];
                    // Skip duplicates
                    if (!in_array($image_url, $seen_urls)) {
                        $image_urls[] = array(
                            'url' => $image_url,
                            'alt' => isset($child['alt']) ? $child['alt'] : '',
                            'source' => isset($child['source']) ? $child['source'] : 'img_tag'
                        );
                        $seen_urls[] = $image_url;
                    }
                }
            }
        }
    }

    return $image_urls;
}

/**
 * Download images and update scraped data
 */
function migration_manager_download_images($image_urls, $scraped_data, $source_url)
{
    $downloaded = 0;
    $skipped = 0;
    $failed = 0;
    $url_mapping = array(); // Maps original URL to WordPress URL

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    foreach ($image_urls as $image_info) {
        $original_url = $image_info['url'];
        $alt_text = $image_info['alt'];

        // Check if image already exists in media library
        $existing_attachment = migration_manager_find_attachment_by_url($original_url);

        if ($existing_attachment) {
            // Image already exists, use it
            $wp_url = wp_get_attachment_url($existing_attachment);
            $url_mapping[$original_url] = $wp_url;
            $skipped++;
            continue;
        }

        // Try to download and upload the image
        try {
            // Make URL absolute if relative
            $absolute_url = migration_manager_make_url_absolute($original_url, $source_url);

            // Check file size before downloading (prevent memory issues)
            $response = wp_remote_head($absolute_url, array('timeout' => 10));
            if (!is_wp_error($response)) {
                $content_length = wp_remote_retrieve_header($response, 'content-length');
                if ($content_length) {
                    $file_size_mb = $content_length / (1024 * 1024);
                    $max_upload_size = wp_max_upload_size() / (1024 * 1024);
                    
                    if ($file_size_mb > $max_upload_size) {
                        error_log("Migration Manager: Image {$absolute_url} exceeds upload limit ({$file_size_mb}MB > {$max_upload_size}MB)");
                        $failed++;
                        continue;
                    }
                }
            }

            // Download the image file
            $tmp = download_url($absolute_url);
            
            if (is_wp_error($tmp)) {
                error_log("Migration Manager: Failed to download image {$absolute_url}: " . $tmp->get_error_message());
                $failed++;
                continue;
            }

            // Get file extension and create proper filename
            $file_array = array();
            $file_array['name'] = basename(parse_url($absolute_url, PHP_URL_PATH));
            
            // If no extension, try to get from content type
            if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
                $file_array['name'] .= '.jpg'; // Default to jpg
            }
            
            // Sanitize filename
            $file_array['name'] = sanitize_file_name($file_array['name']);
            $file_array['tmp_name'] = $tmp;

            // Upload file to WordPress
            $attachment_id = media_handle_sideload($file_array, 0, $alt_text);

            // Clean up temp file if still exists
            if (file_exists($tmp)) {
                @unlink($tmp);
            }

            if (is_wp_error($attachment_id)) {
                error_log("Migration Manager: Failed to upload image {$absolute_url}: " . $attachment_id->get_error_message());
                $failed++;
                continue;
            }

            // Ensure we have a valid attachment ID
            if (!is_numeric($attachment_id)) {
                error_log("Migration Manager: Invalid attachment ID returned for {$absolute_url}");
                $failed++;
                continue;
            }

            // Get the WordPress URL for the uploaded image
            $wp_url = wp_get_attachment_url($attachment_id);
            if (!$wp_url) {
                error_log("Migration Manager: Failed to get URL for attachment {$attachment_id}");
                $failed++;
                continue;
            }

            $url_mapping[$original_url] = $wp_url;
            $downloaded++;

            // Store original URL in attachment meta for future duplicate detection
            update_post_meta($attachment_id, '_migration_manager_original_url', $original_url);

        } catch (Exception $e) {
            error_log("Migration Manager: Exception downloading image {$original_url}: " . $e->getMessage());
            $failed++;
            continue;
        }
    }

    // Update scraped data with new URLs
    $updated_data = migration_manager_replace_image_urls($scraped_data, $url_mapping);

    // Save updated data back to database
    global $wpdb;
    $table_name = $wpdb->prefix . 'migration_manager_scrapes';

    $scraped_json = array(
        'data' => $updated_data,
        'url' => $source_url
    );

    if (isset($scraped_data['stats'])) {
        $scraped_json['stats'] = $scraped_data['stats'];
    }

    $result = $wpdb->update(
        $table_name,
        array(
            'scraped_data' => json_encode($scraped_json),
            'scraped_at' => current_time('mysql')
        ),
        array('url' => $source_url),
        array('%s', '%s'),
        array('%s')
    );

    if ($result === false) {
        error_log("Migration Manager: Failed to save updated data: " . $wpdb->last_error);
    }

    return array(
        'message' => sprintf(
            __('Downloaded %d images, skipped %d (already in library), failed %d', 'migration-manager'),
            $downloaded,
            $skipped,
            $failed
        ),
        'downloaded' => $downloaded,
        'skipped' => $skipped,
        'failed' => $failed,
        'updated_data' => $scraped_json
    );
}

/**
 * Find attachment by original URL
 */
function migration_manager_find_attachment_by_url($url)
{
    global $wpdb;

    // First, try to find by meta key
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_migration_manager_original_url' AND meta_value = %s LIMIT 1",
        $url
    ));

    if ($attachment_id) {
        return $attachment_id;
    }

    // Also check if URL matches any attachment URL
    $attachments = get_posts(array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_migration_manager_original_url',
                'value' => $url,
                'compare' => '='
            )
        )
    ));

    if (!empty($attachments)) {
        return $attachments[0]->ID;
    }

    return false;
}

/**
 * Make URL absolute if it's relative
 */
function migration_manager_make_url_absolute($url, $base_url)
{
    // If already absolute, return as is
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }

    // Parse base URL
    $parsed_base = parse_url($base_url);
    $scheme = isset($parsed_base['scheme']) ? $parsed_base['scheme'] : 'http';
    $host = isset($parsed_base['host']) ? $parsed_base['host'] : '';

    // If URL starts with //, add scheme
    if (substr($url, 0, 2) === '//') {
        return $scheme . ':' . $url;
    }

    // If URL starts with /, add scheme and host
    if (substr($url, 0, 1) === '/') {
        return $scheme . '://' . $host . $url;
    }

    // Otherwise, construct relative to base URL path
    $path = isset($parsed_base['path']) ? dirname($parsed_base['path']) : '';
    if ($path === '.') {
        $path = '';
    }
    return $scheme . '://' . $host . $path . '/' . $url;
}

/**
 * Replace image URLs in scraped data
 */
function migration_manager_replace_image_urls($data, $url_mapping)
{
    foreach ($data as &$item) {
        // Replace URL if item is an image
        if (isset($item['type']) && $item['type'] === 'image' && isset($item['url']) && isset($url_mapping[$item['url']])) {
            $item['url'] = $url_mapping[$item['url']];
            $item['wp_uploaded'] = true; // Mark as uploaded
        }

        // Replace URLs in group children
        if (isset($item['type']) && $item['type'] === 'group' && isset($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as &$child) {
                if (isset($child['type']) && $child['type'] === 'image' && isset($child['url']) && isset($url_mapping[$child['url']])) {
                    $child['url'] = $url_mapping[$child['url']];
                    $child['wp_uploaded'] = true; // Mark as uploaded
                }
            }
        }
    }

    return $data;
  }

/**
 * Handle upload single image request via AJAX
 */
function migration_manager_handle_upload_single_image()
{
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'migration_manager_nonce')) {
        wp_send_json_error(array(
            'message' => __('Security check failed', 'migration-manager')
        ));
        return;
    }

    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Insufficient permissions', 'migration-manager')
        ));
        return;
    }

    $image_url = sanitize_url($_POST['image_url']);
    $alt_text = sanitize_text_field($_POST['alt_text'] ?? '');
    $source_url = sanitize_url($_POST['source_url'] ?? '');

    if (empty($image_url)) {
        wp_send_json_error(array(
            'message' => __('Image URL is required', 'migration-manager')
        ));
        return;
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Check if image already exists in media library
    $existing_attachment = migration_manager_find_attachment_by_url($image_url);

    if ($existing_attachment) {
        // Image already exists, return existing URL
        $wp_url = wp_get_attachment_url($existing_attachment);
        wp_send_json_success(array(
            'message' => __('Image already exists in media library', 'migration-manager'),
            'wp_url' => $wp_url,
            'original_url' => $image_url,
            'attachment_id' => $existing_attachment,
            'skipped' => true
        ));
        return;
    }

    // Try to download and upload the image
    try {
        // Make URL absolute if relative
        $absolute_url = migration_manager_make_url_absolute($image_url, $source_url);

        // Check file size before downloading
        $response = wp_remote_head($absolute_url, array('timeout' => 10));
        if (!is_wp_error($response)) {
            $content_length = wp_remote_retrieve_header($response, 'content-length');
            if ($content_length) {
                $file_size_mb = $content_length / (1024 * 1024);
                $max_upload_size = wp_max_upload_size() / (1024 * 1024);
                
                if ($file_size_mb > $max_upload_size) {
                    wp_send_json_error(array(
                        'message' => sprintf(
                            __('Image exceeds upload limit (%dMB > %dMB)', 'migration-manager'),
                            round($file_size_mb, 2),
                            round($max_upload_size, 2)
                        )
                    ));
                    return;
                }
            }
        }

        // Download the image file
        $tmp = download_url($absolute_url);
        
        if (is_wp_error($tmp)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Failed to download image: %s', 'migration-manager'),
                    $tmp->get_error_message()
                )
            ));
            return;
        }

        // Get file extension and create proper filename
        $file_array = array();
        $file_array['name'] = basename(parse_url($absolute_url, PHP_URL_PATH));
        
        // If no extension, try to get from content type
        if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
            $file_array['name'] .= '.jpg'; // Default to jpg
        }
        
        // Sanitize filename
        $file_array['name'] = sanitize_file_name($file_array['name']);
        $file_array['tmp_name'] = $tmp;

        // Upload file to WordPress
        $attachment_id = media_handle_sideload($file_array, 0, $alt_text);

        // Clean up temp file if still exists
        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Failed to upload image to media library: %s', 'migration-manager'),
                    $attachment_id->get_error_message()
                )
            ));
            return;
        }

        // Ensure we have a valid attachment ID
        if (!is_numeric($attachment_id)) {
            wp_send_json_error(array(
                'message' => __('Invalid attachment ID returned', 'migration-manager')
            ));
            return;
        }

        // Get the WordPress URL for the uploaded image
        $wp_url = wp_get_attachment_url($attachment_id);
        if (!$wp_url) {
            wp_send_json_error(array(
                'message' => __('Failed to get URL for uploaded image', 'migration-manager')
            ));
            return;
        }

        // Store original URL in attachment meta for future duplicate detection
        update_post_meta($attachment_id, '_migration_manager_original_url', $image_url);

        wp_send_json_success(array(
            'message' => __('Image uploaded successfully!', 'migration-manager'),
            'wp_url' => $wp_url,
            'original_url' => $image_url,
            'attachment_id' => $attachment_id,
            'downloaded' => true
        ));

    } catch (Exception $e) {
        error_log("Migration Manager: Exception uploading image {$image_url}: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => sprintf(
                __('Error uploading image: %s', 'migration-manager'),
                $e->getMessage()
            )
        ));
    }
}

// Register AJAX actions
add_action('wp_ajax_migration_manager_delete_group', 'migration_manager_handle_delete_group');
add_action('wp_ajax_migration_manager_delete_multiple_groups', 'migration_manager_handle_delete_multiple_groups');


// Register AJAX actions
add_action('wp_ajax_migration_manager_scrape', 'migration_manager_handle_scrape_request');
add_action('wp_ajax_migration_manager_load_scrape', 'migration_manager_handle_load_scrape');
add_action('wp_ajax_migration_manager_test_api', 'migration_manager_test_api_connection');
add_action('wp_ajax_migration_manager_cleanup_data', 'migration_manager_handle_cleanup_data');
add_action('wp_ajax_migration_manager_clear_all_data', 'migration_manager_handle_clear_all_data');
add_action('wp_ajax_migration_manager_export_all_data', 'migration_manager_handle_export_all_data');
add_action('wp_ajax_migration_manager_download_images', 'migration_manager_handle_download_images');
add_action('wp_ajax_migration_manager_upload_single_image', 'migration_manager_handle_upload_single_image');

// Register auto cleanup hook
add_action('migration_manager_auto_cleanup_hook', 'migration_manager_auto_cleanup');

// Schedule cleanup if not already scheduled
if (!wp_next_scheduled('migration_manager_auto_cleanup_hook')) {
    wp_schedule_event(time(), 'daily', 'migration_manager_auto_cleanup_hook');
}
