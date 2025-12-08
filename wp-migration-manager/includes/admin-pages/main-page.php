<?php

/**
 * Migration Manager - Main Admin Page
 * 
 * @package MigrationManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$api_url = migration_manager()->getApiBaseUrl();
?>

<div class="wrap migration-manager-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- Status Messages -->
    <div id="migration-messages" class="migration-messages"></div>

    <div class="migration-manager-container">

        <!-- Scraping Form Section -->
        <div class="postbox migration-scrape-form">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('Scrape Website Content', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <form id="migration-scrape-form" class="migration-form">
                    <?php wp_nonce_field('migration_manager_nonce', 'migration_nonce'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="scrape-url"><?php _e('Website URL', 'migration-manager'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="url"
                                        id="scrape-url"
                                        name="scrape_url"
                                        class="regular-text"
                                        placeholder="https://example.com"
                                        required />
                                    <p class="description">
                                        <?php _e('Enter the full URL of the page you want to migrate content from.', 'migration-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large" id="scrape-btn">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <?php _e('Scrape Content', 'migration-manager'); ?>
                        </button>
                        <span class="spinner" id="scrape-spinner"></span>
                    </p>
                </form>

                <!-- API Status -->
                <div class="migration-api-status">
                    <p>
                        <strong><?php _e('API Endpoint:', 'migration-manager'); ?></strong>
                        <code><?php echo esc_html($api_url); ?></code>
                        <a href="<?php echo admin_url('admin.php?page=migration-manager-settings'); ?>" class="button button-small">
                            <?php _e('Configure', 'migration-manager'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div id="migration-results" class="migration-results" style="display: none;">

            <!-- Results Header -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2 class="hndle">
                        <span><?php _e('Scraped Content', 'migration-manager'); ?></span>
                    </h2>
                    <div class="handle-actions">
                        <button type="button" class="button button-secondary" id="clear-results">
                            <?php _e('Clear Results', 'migration-manager'); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="export-json">
                            <?php _e('Export JSON', 'migration-manager'); ?>
                        </button>
                    </div>
                </div>
                <div class="inside">
                    <!-- Source URL Display -->
                    <div class="migration-source-info">
                        <strong><?php _e('Source URL:', 'migration-manager'); ?></strong>
                        <a href="#" id="source-url-link" target="_blank" rel="noopener noreferrer"></a>
                    </div>

                    <!-- Statistics -->
                    <div class="migration-stats" id="migration-stats">
                        <!-- Stats will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Content Preview -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2 class="hndle">
                        <span><?php _e('Content Preview', 'migration-manager'); ?></span>
                    </h2>
                </div>
                <div class="inside">
                    <div class="migration-content-preview" id="content-preview">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Migration Actions -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2 class="hndle">
                        <span><?php _e('Migration Actions', 'migration-manager'); ?></span>
                    </h2>
                </div>
                <div class="inside">
                    <div class="migration-actions">
                        <p class="description">
                            <?php _e('Choose how you want to process the scraped content:', 'migration-manager'); ?>
                        </p>

                        <div class="migration-action-buttons">
                            <button type="button" class="button button-primary" id="create-posts" disabled>
                                <span class="dashicons dashicons-admin-post" aria-hidden="true"></span>
                                <?php _e('Create Posts', 'migration-manager'); ?>
                            </button>

                            <button type="button" class="button button-secondary" id="create-pages" disabled>
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <?php _e('Create Pages', 'migration-manager'); ?>
                            </button>

                            <button type="button" class="button button-secondary" id="save-draft" disabled>
                                <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                                <?php _e('Save as Draft', 'migration-manager'); ?>
                            </button>

                            <button type="button" class="button button-secondary" id="preview-mode">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                <?php _e('Preview Mode', 'migration-manager'); ?>
                            </button>

                            <button type="button" class="button button-secondary" id="download-images" disabled>
                                <span class="dashicons dashicons-images-alt" aria-hidden="true"></span>
                                <?php _e('Upload Images', 'migration-manager'); ?>
                            </button>
                        </div>
                        
                        <!-- Image Download Progress -->
                        <div id="image-download-progress" style="display: none; margin-top: 15px;">
                            <div class="progress-bar-container" style="background: #f0f0f0; border-radius: 4px; padding: 2px;">
                                <div id="progress-bar" style="background: #2271b1; height: 20px; border-radius: 2px; width: 0%; transition: width 0.3s ease;"></div>
                            </div>
                            <p id="progress-text" style="margin-top: 10px; font-size: 13px;"></p>
                        </div>

                        <!-- Advanced Options -->
                        <details class="migration-advanced-options">
                            <summary><?php _e('Advanced Options', 'migration-manager'); ?></summary>
                            <div class="advanced-options-content">
                                <table class="form-table" role="presentation">
                                    <tbody>
                                        <tr>
                                            <th scope="row">
                                                <label for="post-category"><?php _e('Default Category', 'migration-manager'); ?></label>
                                            </th>
                                            <td>
                                                <?php
                                                wp_dropdown_categories(array(
                                                    'id' => 'post-category',
                                                    'name' => 'post_category',
                                                    'show_option_none' => __('Select Category', 'migration-manager'),
                                                    'option_none_value' => '',
                                                    'hide_empty' => false,
                                                ));
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="post-status"><?php _e('Post Status', 'migration-manager'); ?></label>
                                            </th>
                                            <td>
                                                <select id="post-status" name="post_status">
                                                    <option value="draft"><?php _e('Draft', 'migration-manager'); ?></option>
                                                    <option value="private"><?php _e('Private', 'migration-manager'); ?></option>
                                                    <option value="publish"><?php _e('Published', 'migration-manager'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="post-author"><?php _e('Author', 'migration-manager'); ?></label>
                                            </th>
                                            <td>
                                                <?php
                                                wp_dropdown_users(array(
                                                    'id' => 'post-author',
                                                    'name' => 'post_author',
                                                    'selected' => get_current_user_id(),
                                                    'show_option_none' => __('Current User', 'migration-manager'),
                                                ));
                                                ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Scrapes -->
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle">
                    <span><?php _e('Recent Scrapes', 'migration-manager'); ?></span>
                </h2>
            </div>
            <div class="inside">
                <div class="migration-recent-scrapes">
                    <?php
                    // Get recent scrapes from database
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'migration_manager_scrapes';
                    $recent_scrapes = $wpdb->get_results(
                        "SELECT url, scraped_at, status FROM $table_name ORDER BY scraped_at DESC LIMIT 5",
                        ARRAY_A
                    );

                    if (!empty($recent_scrapes)) {
                        echo '<table class="widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('URL', 'migration-manager') . '</th>';
                        echo '<th>' . __('Date', 'migration-manager') . '</th>';
                        echo '<th>' . __('Status', 'migration-manager') . '</th>';
                        echo '<th>' . __('Actions', 'migration-manager') . '</th>';
                        echo '</tr></thead>';
                        echo '<tbody>';

                        foreach ($recent_scrapes as $scrape) {
                            echo '<tr>';
                            echo '<td><a href="' . esc_url($scrape['url']) . '" target="_blank">' . esc_html($scrape['url']) . '</a></td>';
                            echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $scrape['scraped_at'])) . '</td>';
                            echo '<td><span class="status-' . esc_attr($scrape['status']) . '">' . esc_html(ucfirst($scrape['status'])) . '</span></td>';
                            echo '<td>';
                            echo '<button type="button" class="button button-small load-scrape" data-url="' . esc_attr($scrape['url']) . '">' . __('Load', 'migration-manager') . '</button>';
                            echo '</td>';
                            echo '</tr>';
                        }

                        echo '</tbody></table>';
                    } else {
                        echo '<p>' . __('No recent scrapes found.', 'migration-manager') . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>

    </div>
</div>