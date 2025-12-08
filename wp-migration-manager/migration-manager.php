<?php

/**
 * Plugin Name: Migration Manager
 * Description: A powerful WordPress plugin to migrate content from external websites using advanced scraping and intelligent content grouping.
 * Version: 1.0.0
 * Author: Vincent J Ahlin
 * Author URI: https://vincent1vincent2.github.io/PortfolioV2/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: migration-manager
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MIGRATION_MANAGER_VERSION', '1.0.0');
define('MIGRATION_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MIGRATION_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MIGRATION_MANAGER_PLUGIN_FILE', __FILE__);

/* include plugin_dir_path(__FILE__) . '/enqueue-modules.php'; */
/**
 * Main Migration Manager Plugin Class
 */
class MigrationManager
{

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->api_base_url = get_option('migration_manager_api_url', 'http://localhost:8000');

        // Initialize plugin
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));

        // Add editor sidebar hooks
        add_action('add_meta_boxes', array($this, 'addEditorSidebar'));
        add_action('admin_footer', array($this, 'addEditorSidebarHtml'));

        // Load AJAX handlers
        require_once MIGRATION_MANAGER_PLUGIN_DIR . 'includes/ajax/scrape-handler.php';

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Load text domain for translations
        load_plugin_textdomain('migration-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu()
    {
        add_menu_page(
            __('Migration Manager', 'migration-manager'),
            __('Migration Manager', 'migration-manager'),
            'manage_options',
            'migration-manager',
            array($this, 'renderAdminPage'),
            'dashicons-migrate',
            30
        );

        add_submenu_page(
            'migration-manager',
            __('Scrape Content', 'migration-manager'),
            __('Scrape Content', 'migration-manager'),
            'manage_options',
            'migration-manager',
            array($this, 'renderAdminPage')
        );

        add_submenu_page(
            'migration-manager',
            __('Settings', 'migration-manager'),
            __('Settings', 'migration-manager'),
            'manage_options',
            'migration-manager-settings',
            array($this, 'renderSettingsPage')
        );
    }

    /**
     * Add editor sidebar meta box
     */
    public function addEditorSidebar()
    {
        $screens = ['post', 'page'];
        foreach ($screens as $screen) {
            add_meta_box(
                'migration-manager-sidebar',
                __('Migration Manager', 'migration-manager'),
                array($this, 'renderEditorSidebar'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render editor sidebar content
     */
    public function renderEditorSidebar($post)
    {
        wp_nonce_field('migration_manager_editor_nonce', 'migration_manager_editor_nonce');
?>
        <div id="migration-manager-editor-sidebar">
            <div class="migration-sidebar-header">
                <h4><?php _e('Content Scraper', 'migration-manager'); ?></h4>
                <p><?php _e('Scrape content and drag it to editor fields', 'migration-manager'); ?></p>
            </div>

            <div class="migration-sidebar-form">
                <div class="form-group">
                    <label for="editor-scrape-url"><?php _e('Website URL:', 'migration-manager'); ?></label>
                    <input type="url" id="editor-scrape-url" class="widefat" placeholder="https://example.com">
                </div>

                <div class="form-actions">
                    <button type="button" id="editor-scrape-btn" class="button button-primary">
                        <?php _e('Scrape Content', 'migration-manager'); ?>
                    </button>
                    <span id="editor-scrape-spinner" class="spinner"></span>
                </div>
            </div>

            <div id="editor-migration-messages"></div>

            <div id="editor-recent-scrapes" class="migration-sidebar-section">
                <h5><?php _e('Recent Scrapes', 'migration-manager'); ?></h5>
                <div id="editor-recent-list">
                    <?php $this->renderRecentScrapesForEditor(); ?>
                </div>
            </div>

            <div id="editor-scraped-content" class="migration-sidebar-section" style="display: none;">
                <div class="sidebar-section-header">
                    <h5><?php _e('Scraped Content', 'migration-manager'); ?></h5>
                    <button type="button" id="editor-clear-results" class="button button-small">
                        <?php _e('X', 'migration-manager'); ?>
                    </button>
                </div>
                <div id="editor-content-stats"></div>
                <div id="editor-content-preview"></div>
            </div>
        </div>
    <?php
    }

    /**
     * Render recent scrapes for editor sidebar
     */
    public function renderRecentScrapesForEditor($limit = 5)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'migration_manager_scrapes';

        $recent_scrapes = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, scraped_at, status FROM $table_name WHERE status = 'success' ORDER BY scraped_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($recent_scrapes)) {
            echo '<p class="no-scrapes">' . __('No recent scrapes found.', 'migration-manager') . '</p>';
            return;
        }

        echo '<div class="recent-scrapes-list">';
        foreach ($recent_scrapes as $scrape) {
            $domain = parse_url($scrape['url'], PHP_URL_HOST);
            $time_ago = human_time_diff(strtotime($scrape['scraped_at']), current_time('timestamp'));

            echo '<div class="recent-scrape-item">';
            echo '<div class="scrape-domain">' . esc_html($domain) . '</div>';
            echo '<div class="scrape-time">' . sprintf(__('%s ago', 'migration-manager'), $time_ago) . '</div>';
            echo '<button type="button" class="button button-small editor-load-scrape" data-url="' . esc_attr($scrape['url']) . '">';
            echo __('Load', 'migration-manager');
            echo '</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Add sticky sidebar HTML to editor pages
     */
    public function addEditorSidebarHtml()
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['post']) || !in_array($screen->post_type, ['post', 'page'])) {
            return;
        }
    ?>
        <div id="migration-sticky-sidebar" class="migration-sticky-sidebar" style="display: none;">
            <div class="sticky-sidebar-handle">
                <button type="button" id="toggle-sticky-sidebar" class="button">
                    <span class="dashicons dashicons-migrate"></span>
                    <?php _e('Migration Manager', 'migration-manager'); ?>
                </button>
            </div>
            <div class="sticky-sidebar-content">
                <div class="sticky-sidebar-header">
                    <h4><?php _e('Migration Manager', 'migration-manager'); ?></h4>
                    <button type="button" id="close-sticky-sidebar" class="button-link">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>

                <div class="sticky-sidebar-form">
                    <div class="form-group">
                        <label for="sticky-scrape-url"><?php _e('Website URL:', 'migration-manager'); ?></label>
                        <input type="url" id="sticky-scrape-url" class="widefat" placeholder="https://example.com">
                    </div>

                    <div class="form-actions">
                        <button type="button" id="sticky-scrape-btn" class="button button-primary">
                            <?php _e('Scrape Content', 'migration-manager'); ?>
                        </button>
                        <span id="sticky-scrape-spinner" class="spinner"></span>
                    </div>
                </div>

                <div id="sticky-migration-messages"></div>

                <div id="sticky-recent-scrapes" class="migration-sidebar-section">
                    <h5><?php _e('Recent Scrapes', 'migration-manager'); ?></h5>
                    <div id="sticky-recent-list">
                        <?php $this->renderRecentScrapesForEditor(); ?>
                    </div>
                </div>

                <div id="sticky-scraped-content" class="migration-sidebar-section" style="display: none;">
                    <div class="sidebar-section-header">
                        <h5><?php _e('Scraped Content', 'migration-manager'); ?></h5>
                        <button type="button" id="sticky-clear-results" class="button button-small">
                            <?php _e('Clear', 'migration-manager'); ?>
                        </button>
                    </div>
                    <div id="sticky-content-stats"></div>
                    <div id="sticky-content-preview"></div>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueAdminScripts($hook)
    {
        $screen = get_current_screen();

        // Load on plugin pages
        $is_plugin_page = strpos($hook, 'migration-manager') !== false;

        // Load on post/page editor
        $is_editor_page = $screen && in_array($screen->base, ['post']) && in_array($screen->post_type, ['post', 'page']);

        if (!$is_plugin_page && !$is_editor_page) {
            return;
        }

        wp_enqueue_script(
            'migration-manager-admin',
            MIGRATION_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            MIGRATION_MANAGER_VERSION,
            true
        );

        wp_enqueue_style(
            'migration-manager-admin',
            MIGRATION_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MIGRATION_MANAGER_VERSION
        );

        // Localize script for AJAX
        wp_localize_script('migration-manager-admin', 'migrationManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('migration_manager_nonce'),
            'isEditor' => $is_editor_page,
            'strings' => array(
                'scraping' => __('Scraping...', 'migration-manager'),
                'success' => __('Content scraped successfully!', 'migration-manager'),
                'error' => __('Error occurred while scraping', 'migration-manager'),
                'invalidUrl' => __('Please enter a valid URL', 'migration-manager'),
                'noData' => __('No content found', 'migration-manager')
            )
        ));
    }

    /**
     * Render main admin page
     */
    public function renderAdminPage()
    {
        include MIGRATION_MANAGER_PLUGIN_DIR . 'includes/admin-pages/main-page.php';
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage()
    {
        include MIGRATION_MANAGER_PLUGIN_DIR . 'includes/admin-pages/settings-page.php';
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Set default options
        if (!get_option('migration_manager_api_url')) {
            add_option('migration_manager_api_url', 'http://localhost:8000');
        }

        // Create database tables if needed (for future features)
        $this->createTables();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Clean up if needed
    }

    /**
     * Create database tables for storing scraped content
     */
    private function createTables()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'migration_manager_scrapes';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            scraped_data longtext NOT NULL,
            scraped_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'scraped',
            PRIMARY KEY (id),
            INDEX url_index (url),
            INDEX status_index (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get API base URL
     */
    public function getApiBaseUrl()
    {
        return $this->api_base_url;
    }
}

// Initialize the plugin
MigrationManager::getInstance();

/**
 * Helper function to get plugin instance
 */
function migration_manager()
{
    return MigrationManager::getInstance();
}
