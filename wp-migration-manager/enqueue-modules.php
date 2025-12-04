<?php

/**
 * Enqueue Migration Manager Modular Scripts
 * Add this to your main plugin file or include it
 */

// Hook into admin enqueue scripts
add_action('admin_enqueue_scripts', 'migration_manager_enqueue_modular_scripts');

function migration_manager_enqueue_modular_scripts($hook)
{
    // Define base URL for scripts
    $plugin_url = plugin_dir_url(__FILE__) . 'assets/';
    $version = '2.0.0'; // Bump version to force cache refresh

    // Check if we're on the right pages
    $is_migration_page = ($hook === 'toplevel_page_migration-manager' ||
        $hook === 'migration_page_migration-manager');
    $is_editor_page = ($hook === 'post.php' || $hook === 'post-new.php');

    // Only load on relevant pages
    if (!$is_migration_page && !$is_editor_page) {
        return;
    }

    // Core modules - must load first
    wp_enqueue_script(
        'migration-manager-state',
        $plugin_url . 'js/core/state.js',
        array('jquery'),
        $version,
        true
    );

    wp_enqueue_script(
        'migration-manager-eventbus',
        $plugin_url . 'js/core/eventbus.js',
        array('jquery'),
        $version,
        true
    );

    wp_enqueue_script(
        'migration-manager-api',
        $plugin_url . 'js/core/api.js',
        array('jquery', 'migration-manager-state', 'migration-manager-eventbus'),
        $version,
        true
    );

    // UI modules
    wp_enqueue_script(
        'migration-manager-renderer',
        $plugin_url . 'js/ui/renderer.js',
        array('jquery', 'migration-manager-state', 'migration-manager-eventbus'),
        $version,
        true
    );

    wp_enqueue_script(
        'migration-manager-messages',
        $plugin_url . 'js/ui/messages.js',
        array('jquery', 'migration-manager-state', 'migration-manager-eventbus'),
        $version,
        true
    );

    // Feature modules
    wp_enqueue_script(
        'migration-manager-dragdrop',
        $plugin_url . 'js/modules/drag-drop.js',
        array('jquery', 'migration-manager-state', 'migration-manager-eventbus', 'migration-manager-messages'),
        $version,
        true
    );

    wp_enqueue_script(
        'migration-manager-group-manager',
        $plugin_url . 'js/modules/group-manager.js',
        array('jquery', 'migration-manager-state', 'migration-manager-eventbus', 'migration-manager-api', 'migration-manager-renderer', 'migration-manager-messages'),
        $version,
        true
    );

    // Main application module - must load last
    wp_enqueue_script(
        'migration-manager-main',
        $plugin_url . 'js/core/main.js',
        array(
            'jquery',
            'migration-manager-state',
            'migration-manager-eventbus',
            'migration-manager-api',
            'migration-manager-renderer',
            'migration-manager-messages',
            'migration-manager-dragdrop',
            'migration-manager-group-manager'
        ),
        $version,
        true
    );

    // Include lodash for utility functions (if not already loaded)
    if (!wp_script_is('lodash', 'enqueued')) {
        wp_enqueue_script('lodash');
    }

    // Localize script with necessary data
    wp_localize_script('migration-manager-main', 'migrationManager', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('migration_manager_nonce'),
        'isEditor' => $is_editor_page,
        'isMainPage' => $is_migration_page,
        'postId' => $is_editor_page ? get_the_ID() : null,
        'strings' => array(
            'loading' => __('Loading...', 'migration-manager'),
            'success' => __('Operation completed successfully!', 'migration-manager'),
            'error' => __('An error occurred. Please try again.', 'migration-manager'),
            'scraping' => __('Scraping website...', 'migration-manager'),
            'noData' => __('No data found', 'migration-manager'),
            'invalidUrl' => __('Please enter a valid URL', 'migration-manager'),
            'confirmDelete' => __('Are you sure you want to delete this?', 'migration-manager'),
        ),
        'debug' => (defined('WP_DEBUG') && WP_DEBUG) || isset($_GET['debug'])
    ));

    // Add inline CSS for drag-drop effects
    wp_add_inline_style('wp-admin', '
        .drag-over {
            background-color: #f0f8ff !important;
            border: 2px dashed #4a90e2 !important;
            transition: all 0.3s ease;
        }
        
        .blinking {
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 50%, 100% { opacity: 1; }
            25%, 75% { opacity: 0.5; background-color: #fffbf0; }
        }
        
        .draggable-item {
            cursor: grab;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .draggable-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .draggable-item.dragging {
            cursor: grabbing;
            opacity: 0.5;
        }
        
        .migration-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    ');
}

/**
 * Alternative: Load modules with a single minified file in production
 * Uncomment this section if you want to use a build process
 */
/*
function migration_manager_enqueue_production_scripts($hook) {
    $plugin_url = plugin_dir_url(__FILE__);
    $version = '2.0.0';
    
    $is_migration_page = ($hook === 'toplevel_page_migration-manager');
    $is_editor_page = ($hook === 'post.php' || $hook === 'post-new.php');
    
    if (!$is_migration_page && !$is_editor_page) {
        return;
    }
    
    // In production, use minified combined file
    wp_enqueue_script(
        'migration-manager-bundle',
        $plugin_url . 'js/dist/migration-manager.min.js',
        array('jquery', 'lodash'),
        $version,
        true
    );
    
    // Still need to localize
    wp_localize_script('migration-manager-bundle', 'migrationManager', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('migration_manager_nonce'),
        'isEditor' => $is_editor_page,
        // ... rest of localization
    ));
}
*/

/**
 * Create the folder structure if it doesn't exist
 * Run this once to create the directories
 */
function migration_manager_create_folder_structure()
{
    $plugin_dir = plugin_dir_path(__FILE__);

    $folders = array(
        'js/core',
        'js/modules',
        'js/ui',
        'js/utils',
        'js/handlers',
        'js/integrations'
    );

    foreach ($folders as $folder) {
        $path = $plugin_dir . $folder;
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
    }
}

// Run folder creation on plugin activation
register_activation_hook(__FILE__, 'migration_manager_create_folder_structure');

/**
 * Debug function to check if all files exist
 */
function migration_manager_check_module_files()
{
    $plugin_dir = plugin_dir_path(__FILE__);

    $required_files = array(
        'js/core/state.js' => 'State Management Module',
        'js/core/eventbus.js' => 'EventBus Module',
        'js/core/api.js' => 'API Module',
        'js/core/main.js' => 'Main Application Module',
        'js/ui/renderer.js' => 'Renderer Module',
        'js/ui/messages.js' => 'Messages Module',
        'js/modules/drag-drop.js' => 'Drag & Drop Module',
        'js/modules/group-manager.js' => 'Group Manager Module'
    );

    $missing_files = array();

    foreach ($required_files as $file => $name) {
        $full_path = $plugin_dir . $file;
        if (!file_exists($full_path)) {
            $missing_files[] = "$name ($file)";
        }
    }

    if (!empty($missing_files)) {
        add_action('admin_notices', function () use ($missing_files) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Migration Manager:</strong> Missing module files:<br>';
            echo implode('<br>', $missing_files);
            echo '</p></div>';
        });
    }
}

// Check files in development mode
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_init', 'migration_manager_check_module_files');
}

/**
 * Instructions for implementation:
 * 
 * 1. Create the folder structure:
 *    /your-plugin/
 *    ├── js/
 *    │   ├── core/
 *    │   │   ├── state.js
 *    │   │   ├── eventbus.js
 *    │   │   ├── api.js
 *    │   │   └── main.js
 *    │   ├── modules/
 *    │   │   ├── drag-drop.js
 *    │   │   └── group-manager.js
 *    │   └── ui/
 *    │       ├── renderer.js
 *    │       └── messages.js
 *    
 * 2. Place each module file in its correct location
 * 
 * 3. Replace your current enqueue function with this one
 * 
 * 4. Clear browser cache and test
 * 
 * 5. Check console for "Migration Manager initialized successfully" message
 */
