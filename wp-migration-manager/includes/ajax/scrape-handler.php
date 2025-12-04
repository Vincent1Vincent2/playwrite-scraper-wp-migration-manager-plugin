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
    $api_url = rtrim($api_base_url, '/') . '/scrape?url=' . urlencode($url);

    error_log("Migration Manager: Calling API: " . $api_url);

    // Set up request args
    $args = array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress Migration Manager Plugin v' . MIGRATION_MANAGER_VERSION
        )
    );

    // Make the API request
    $response = wp_remote_get($api_url, $args);

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

// Register AJAX actions
add_action('wp_ajax_migration_manager_scrape', 'migration_manager_handle_scrape_request');
add_action('wp_ajax_migration_manager_load_scrape', 'migration_manager_handle_load_scrape');
add_action('wp_ajax_migration_manager_test_api', 'migration_manager_test_api_connection');
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

// Register auto cleanup hook
add_action('migration_manager_auto_cleanup_hook', 'migration_manager_auto_cleanup');

// Schedule cleanup if not already scheduled
if (!wp_next_scheduled('migration_manager_auto_cleanup_hook')) {
    wp_schedule_event(time(), 'daily', 'migration_manager_auto_cleanup_hook');
}
