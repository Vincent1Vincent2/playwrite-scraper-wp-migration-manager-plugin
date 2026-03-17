<?php

/**
 * Migration Manager - Settings Page
 * 
 * @package MigrationManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['settings_nonce'], 'migration_manager_settings_nonce')) {

    // Sanitize and save API URL
    if (isset($_POST['migration_manager_api_url'])) {
        $api_url = sanitize_text_field($_POST['migration_manager_api_url']);
        $api_url = rtrim($api_url, '/'); // Remove trailing slash
        update_option('migration_manager_api_url', $api_url);

        echo '<div class="notice notice-success is-dismissible">
            <p><strong>' . __('Settings saved successfully!', 'migration-manager') . '</strong></p>
        </div>';
    }

    // Save other settings
    if (isset($_POST['migration_manager_timeout'])) {
        $timeout = absint($_POST['migration_manager_timeout']);
        if ($timeout < 10) $timeout = 10; // Minimum 10 seconds
        if ($timeout > 300) $timeout = 300; // Maximum 5 minutes
        update_option('migration_manager_timeout', $timeout);
    }

    if (isset($_POST['migration_manager_max_scrapes'])) {
        $max_scrapes = absint($_POST['migration_manager_max_scrapes']);
        if ($max_scrapes < 10) $max_scrapes = 10; // Minimum 10 scrapes
        if ($max_scrapes > 1000) $max_scrapes = 1000; // Maximum 1000 scrapes
        update_option('migration_manager_max_scrapes', $max_scrapes);
    }

    // Auto-cleanup setting
    $auto_cleanup = isset($_POST['migration_manager_auto_cleanup']) ? 1 : 0;
    update_option('migration_manager_auto_cleanup', $auto_cleanup);

    // Cleanup days
    if (isset($_POST['migration_manager_cleanup_days'])) {
        $cleanup_days = absint($_POST['migration_manager_cleanup_days']);
        if ($cleanup_days < 1) $cleanup_days = 30; // Minimum 1 day
        if ($cleanup_days > 365) $cleanup_days = 365; // Maximum 1 year
        update_option('migration_manager_cleanup_days', $cleanup_days);
    }

    // AI settings
    $ai_enabled  = isset($_POST['migration_manager_ai_enabled']) ? 1 : 0;
    $ai_provider = isset($_POST['migration_manager_ai_provider']) ? sanitize_text_field($_POST['migration_manager_ai_provider']) : 'none';
    $ai_api_key  = isset($_POST['migration_manager_ai_api_key']) ? trim(sanitize_text_field($_POST['migration_manager_ai_api_key'])) : '';
    $ai_model    = isset($_POST['migration_manager_ai_model']) ? trim(sanitize_text_field($_POST['migration_manager_ai_model'])) : '';

    update_option('migration_manager_ai_enabled', $ai_enabled);
    update_option('migration_manager_ai_provider', $ai_provider);
    update_option('migration_manager_ai_api_key', $ai_api_key);
    update_option('migration_manager_ai_model', $ai_model);
}

// Get current settings
$api_url = get_option('migration_manager_api_url', 'http://localhost:8000');
$timeout = get_option('migration_manager_timeout', 60);
$max_scrapes = get_option('migration_manager_max_scrapes', 100);
$auto_cleanup = get_option('migration_manager_auto_cleanup', 0);
$cleanup_days = get_option('migration_manager_cleanup_days', 30);

// AI settings
$ai_enabled  = get_option('migration_manager_ai_enabled', 0);
$ai_provider = get_option('migration_manager_ai_provider', 'none');
$ai_api_key  = get_option('migration_manager_ai_api_key', '');
$ai_model    = get_option('migration_manager_ai_model', '');

// Get database statistics
global $wpdb;
$table_name = $wpdb->prefix . 'migration_manager_scrapes';
$total_scrapes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
$successful_scrapes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success'");
$failed_scrapes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");
$oldest_scrape = $wpdb->get_var("SELECT MIN(scraped_at) FROM $table_name");

?>

<div class="wrap migration-manager-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form method="post" action="" id="migration-settings-form">
        <?php wp_nonce_field('migration_manager_settings_nonce', 'settings_nonce'); ?>

        <!-- API Configuration -->
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('API Configuration', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_api_url"><?php _e('API Base URL', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="url"
                                    id="migration_manager_api_url"
                                    name="migration_manager_api_url"
                                    value="<?php echo esc_attr($api_url); ?>"
                                    class="regular-text"
                                    placeholder="http://localhost:8000"
                                    required />
                                <p class="description">
                                    <?php _e('The base URL of your Python scraper API. Do not include trailing slash.', 'migration-manager'); ?>
                                </p>

                                <!-- API Test Section -->
                                <div style="margin-top: 15px;">
                                    <button type="button" class="button button-secondary" id="test-api-connection">
                                        <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                                        <?php _e('Test API Connection', 'migration-manager'); ?>
                                    </button>
                                    <span class="spinner" id="api-test-spinner"></span>
                                    <div id="api-test-result" style="margin-top: 10px;"></div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_timeout"><?php _e('Request Timeout', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="migration_manager_timeout"
                                    name="migration_manager_timeout"
                                    value="<?php echo esc_attr($timeout); ?>"
                                    min="10"
                                    max="300"
                                    class="small-text" />
                                <span><?php _e('seconds', 'migration-manager'); ?></span>
                                <p class="description">
                                    <?php _e('Maximum time to wait for API response (10-300 seconds).', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

     

        <!-- AI Settings -->
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('AI Settings', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_ai_enabled"><?php _e('Enable AI processing', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="migration_manager_ai_enabled"
                                        name="migration_manager_ai_enabled"
                                        value="1"
                                        <?php checked($ai_enabled, 1); ?> />
                                    <?php _e('Use AI to label and reorder sections after scraping.', 'migration-manager'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_ai_provider"><?php _e('AI Provider', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <select
                                    id="migration_manager_ai_provider"
                                    name="migration_manager_ai_provider">
                                    <option value="none" <?php selected($ai_provider, 'none'); ?>><?php _e('None', 'migration-manager'); ?></option>
                                    <option value="openai" <?php selected($ai_provider, 'openai'); ?>>OpenAI</option>
                                    <option value="anthropic" <?php selected($ai_provider, 'anthropic'); ?>>Anthropic</option>
                                </select>
                                <p class="description">
                                    <?php _e('Choose which AI provider to use for optional refinement.', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_ai_api_key"><?php _e('API Key', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="password"
                                    id="migration_manager_ai_api_key"
                                    name="migration_manager_ai_api_key"
                                    value="<?php echo esc_attr($ai_api_key); ?>"
                                    class="regular-text"
                                    autocomplete="off" />
                                <p class="description">
                                    <?php _e('Your API key for the selected provider. Stored in the WordPress options table.', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_ai_model"><?php _e('Model (optional)', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="migration_manager_ai_model"
                                    name="migration_manager_ai_model"
                                    value="<?php echo esc_attr($ai_model); ?>"
                                    class="regular-text"
                                    placeholder="<?php esc_attr_e('e.g. gpt-4.1-mini or claude-haiku-4-5', 'migration-manager'); ?>" />
                                <p class="description">
                                    <?php _e('Override the default model for the selected provider. Leave empty to use the backend default.', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php _e('Test AI Connection', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <button type="button" class="button button-secondary" id="test-ai-connection">
                                    <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                                    <?php _e('Send "Are you ready?"', 'migration-manager'); ?>
                                </button>
                                <span class="spinner" id="ai-test-spinner"></span>
                                <div id="ai-test-result" style="margin-top: 10px;"></div>
                                <p class="description">
                                    <?php _e('Tests the configured AI provider by sending the prompt "Are you ready?" and showing the reply.', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

           <!-- Storage Settings -->
           <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('Storage Settings', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_max_scrapes"><?php _e('Max Stored Scrapes', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="migration_manager_max_scrapes"
                                    name="migration_manager_max_scrapes"
                                    value="<?php echo esc_attr($max_scrapes); ?>"
                                    min="10"
                                    max="1000"
                                    class="small-text" />
                                <p class="description">
                                    <?php _e('Maximum number of scrapes to store in database (10-1000).', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_auto_cleanup"><?php _e('Auto Cleanup', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="migration_manager_auto_cleanup"
                                        name="migration_manager_auto_cleanup"
                                        value="1"
                                        <?php checked($auto_cleanup, 1); ?> />
                                    <?php _e('Automatically delete old scrape data', 'migration-manager'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, old scrape data will be automatically deleted based on the cleanup days setting.', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migration_manager_cleanup_days"><?php _e('Cleanup After', 'migration-manager'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="migration_manager_cleanup_days"
                                    name="migration_manager_cleanup_days"
                                    value="<?php echo esc_attr($cleanup_days); ?>"
                                    min="1"
                                    max="365"
                                    class="small-text" />
                                <span><?php _e('days', 'migration-manager'); ?></span>
                                <p class="description">
                                    <?php _e('Delete scrape data older than this many days (1-365 days).', 'migration-manager'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Manual Cleanup -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h4><?php _e('Manual Cleanup', 'migration-manager'); ?></h4>
                    <p class="description">
                        <?php _e('Clean up old scrape data immediately.', 'migration-manager'); ?>
                    </p>
                    <button type="button" class="button button-secondary" id="manual-cleanup">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <?php _e('Clean Up Old Data', 'migration-manager'); ?>
                    </button>
                    <span class="spinner" id="cleanup-spinner"></span>
                    <div id="cleanup-result" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>

        <!-- Database Statistics -->
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('Database Statistics', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <div class="migration-stats-overview">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                        <div class="stat-card" style="padding: 20px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                            <div style="font-size: 32px; font-weight: 700; color: #0073aa; margin-bottom: 5px;"><?php echo esc_html($total_scrapes ?: '0'); ?></div>
                            <div style="font-size: 14px; color: #666; font-weight: 600;"><?php _e('Total Scrapes', 'migration-manager'); ?></div>
                        </div>
                        <div class="stat-card" style="padding: 20px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                            <div style="font-size: 32px; font-weight: 700; color: #46b450; margin-bottom: 5px;"><?php echo esc_html($successful_scrapes ?: '0'); ?></div>
                            <div style="font-size: 14px; color: #666; font-weight: 600;"><?php _e('Successful', 'migration-manager'); ?></div>
                        </div>
                        <div class="stat-card" style="padding: 20px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                            <div style="font-size: 32px; font-weight: 700; color: #dc3232; margin-bottom: 5px;"><?php echo esc_html($failed_scrapes ?: '0'); ?></div>
                            <div style="font-size: 14px; color: #666; font-weight: 600;"><?php _e('Failed', 'migration-manager'); ?></div>
                        </div>
                        <div class="stat-card" style="padding: 20px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                            <div style="font-size: 16px; font-weight: 500; color: #666; margin-bottom: 5px;">
                                <?php
                                if ($oldest_scrape) {
                                    echo esc_html(mysql2date(get_option('date_format'), $oldest_scrape));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </div>
                            <div style="font-size: 14px; color: #666; font-weight: 600;"><?php _e('Oldest Record', 'migration-manager'); ?></div>
                        </div>
                    </div>

                    <!-- Database Actions -->
                    <div style="padding-top: 20px; border-top: 1px solid #ddd;">
                        <h4><?php _e('Database Actions', 'migration-manager'); ?></h4>
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="button" class="button button-secondary" id="export-all-data">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php _e('Export All Data', 'migration-manager'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="clear-all-data" style="color: #dc3232;">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                <?php _e('Clear All Data', 'migration-manager'); ?>
                            </button>
                        </div>
                        <div id="database-action-result" style="margin-top: 15px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Debug Information -->
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('Debug Information', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td style="width: 200px;"><strong><?php _e('Plugin Version', 'migration-manager'); ?></strong></td>
                            <td><?php echo esc_html(MIGRATION_MANAGER_VERSION); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('WordPress Version', 'migration-manager'); ?></strong></td>
                            <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('PHP Version', 'migration-manager'); ?></strong></td>
                            <td><?php echo esc_html(phpversion()); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Database Table', 'migration-manager'); ?></strong></td>
                            <td><code><?php echo esc_html($table_name); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('AJAX URL', 'migration-manager'); ?></strong></td>
                            <td><code><?php echo esc_html(admin_url('admin-ajax.php')); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php submit_button(__('Save Settings', 'migration-manager')); ?>
    </form>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Test API Connection
        $('#test-api-connection').on('click', function() {
            var $button = $(this);
            var $spinner = $('#api-test-spinner');
            var $result = $('#api-test-result');

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'migration_manager_test_api',
                    nonce: '<?php echo wp_create_nonce('migration_manager_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div style="color: #46b450; font-weight: 600;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</div>');
                    } else {
                        $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> Connection test failed</div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });

        // Manual Cleanup
        $('#manual-cleanup').on('click', function() {
            if (!confirm('<?php _e('Are you sure you want to clean up old scrape data? This action cannot be undone.', 'migration-manager'); ?>')) {
                return;
            }

            var $button = $(this);
            var $spinner = $('#cleanup-spinner');
            var $result = $('#cleanup-result');

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'migration_manager_cleanup_data',
                    nonce: '<?php echo wp_create_nonce('migration_manager_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div style="color: #46b450; font-weight: 600;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</div>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> Cleanup failed</div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });

        // Clear All Data
        $('#clear-all-data').on('click', function() {
            if (!confirm('<?php _e('Are you sure you want to DELETE ALL scrape data? This action cannot be undone!', 'migration-manager'); ?>')) {
                return;
            }

            var $button = $(this);
            var $result = $('#database-action-result');

            $button.prop('disabled', true);
            $result.empty();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'migration_manager_clear_all_data',
                    nonce: '<?php echo wp_create_nonce('migration_manager_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div style="color: #46b450; font-weight: 600;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</div>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> Clear all data failed</div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Export All Data
        $('#export-all-data').on('click', function() {
            var $button = $(this);
            var $result = $('#database-action-result');

            $button.prop('disabled', true);
            $result.html('<div style="color: #0073aa;"><span class="dashicons dashicons-update"></span> Preparing export...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'migration_manager_export_all_data',
                    nonce: '<?php echo wp_create_nonce('migration_manager_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Create download
                        var blob = new Blob([JSON.stringify(response.data, null, 2)], {
                            type: 'application/json'
                        });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'migration-manager-export-' + new Date().toISOString().split('T')[0] + '.json';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        $result.html('<div style="color: #46b450; font-weight: 600;"><span class="dashicons dashicons-yes-alt"></span> Export completed successfully!</div>');
                    } else {
                        $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> Export failed</div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        $("#test-ai-connection").on("click", function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $spinner = $("#ai-test-spinner");
            var $result = $("#ai-test-result");

            $result.empty();
            $spinner.addClass("is-active");
            $btn.prop("disabled", true);

            $.post(ajaxurl, {
                action: "migration_manager_test_ai",
                nonce: $("#settings_nonce").val()
            })
            .done(function (response) {
                if (response.success && response.data && response.data.message) {
                    $result.html('<div style="color: #46b450; font-weight: 600;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</div>');
                } else {
                    var msg = (response.data && response.data.message) || "<?php echo esc_js(__('Unknown AI test error', 'migration-manager')); ?>";
                    $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> ' + msg + '</div>');
                }
            })
            .fail(function () {
                $result.html('<div style="color: #dc3232; font-weight: 600;"><span class="dashicons dashicons-dismiss"></span> <?php echo esc_js(__('Network error while testing AI connection', 'migration-manager')); ?></div>');
            })
            .always(function () {
                $spinner.removeClass("is-active");
                $btn.prop("disabled", false);
            });
        });
    });
</script>