<?php

/**
 * Migrate to Tolka — in-plugin help.
 *
 * Short copy for the five migration steps and the speed benchmark file.
 * The wizard view includes this file when it exists.
 *
 * @package MigrationManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wmm-tolka-help">
    <h2 class="wmm-tolka-help__title"><?php esc_html_e('How to migrate from Polylang', 'migration-manager'); ?></h2>
    <ol class="wmm-tolka-help__steps">
        <li><?php esc_html_e('Open Migrate to Tolka.', 'migration-manager'); ?></li>
        <li><?php esc_html_e('The plugin checks Polylang on this WordPress site.', 'migration-manager'); ?></li>
        <li><?php esc_html_e('It loads the public site URL (the site home URL by default) and shows how much faster Tolka is estimated to be.', 'migration-manager'); ?></li>
        <li><?php esc_html_e('Preview languages, posts, terms, strings, menus, and media.', 'migration-manager'); ?></li>
        <li><?php esc_html_e('Run the migration. Polylang data stays in place. A Tolka package is stored and Tolka meta is written.', 'migration-manager'); ?></li>
    </ol>
    <p class="wmm-tolka-help__benchmark">
        <?php esc_html_e('The speed estimate reads its coefficients from the benchmark file below, inside this plugin. Those coefficients are placeholders, not lab measurements. The page timing is measured live. Replace the file with Tolka lab data before using the percent in a pitch.', 'migration-manager'); ?>
    </p>
    <p class="wmm-tolka-help__path">
        <code>includes/tolka/data/benchmarks.json</code>
    </p>
</div>
