<?php

/**
 * Migration Manager - Migrate to Tolka help
 *
 * Customer-facing copy for the five migration steps and the speed benchmark file.
 *
 * @package MigrationManager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="wmm-tolka-help" aria-labelledby="wmm-tolka-help-title">
    <h2 id="wmm-tolka-help-title" class="wmm-tolka-help__title"><?php esc_html_e('How this migration works', 'migration-manager'); ?></h2>
    <p class="wmm-tolka-help__intro">
        <?php esc_html_e('Migrate to Tolka prepares this Polylang site on one screen. You do not need another tool. You check Polylang, estimate the public site, preview the content, and run the migration when you are ready.', 'migration-manager'); ?>
    </p>
    <p class="wmm-tolka-help__intro">
        <?php esc_html_e('Polylang stays installed. The plugin does not delete Polylang content, options, or terms.', 'migration-manager'); ?>
    </p>
    <p class="wmm-tolka-help__intro">
        <?php esc_html_e('Until you measure a URL, there is no speed estimate. Until you preview, there is no content list. Until you run the migration, nothing from this screen has been stored.', 'migration-manager'); ?>
    </p>

    <ol class="wmm-tolka-help__steps">
        <li class="wmm-tolka-help__step">
            <h3 class="wmm-tolka-help__step-title"><?php esc_html_e('Open Migrate to Tolka', 'migration-manager'); ?></h3>
            <p>
                <?php esc_html_e('Open Migrate to Tolka from the WordPress admin menu. An administrator can run every step on this screen.', 'migration-manager'); ?>
            </p>
        </li>
        <li class="wmm-tolka-help__step">
            <h3 class="wmm-tolka-help__step-title"><?php esc_html_e('Check Polylang', 'migration-manager'); ?></h3>
            <p>
                <?php esc_html_e('The plugin checks whether Polylang is active on this WordPress site. When the version can be read from Polylang, the check includes that version.', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__note">
                <?php esc_html_e('If Polylang is inactive or missing, you see an error and empty content. The screen does not crash, and nothing is deleted.', 'migration-manager'); ?>
            </p>
        </li>
        <li class="wmm-tolka-help__step">
            <h3 class="wmm-tolka-help__step-title"><?php esc_html_e('Estimate the public site', 'migration-manager'); ?></h3>
            <p>
                <?php esc_html_e('The plugin loads the public site URL and shows how much faster Tolka is estimated to be. The address starts as the home URL of this site, and you can replace it with another public page before the check runs.', 'migration-manager'); ?>
            </p>
            <p>
                <?php esc_html_e('The result shows the measured load time, the estimated Tolka load time, the percent faster, and a basis line. The basis line reads:', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__quote">
                <?php esc_html_e('Measured page load plus coefficients from benchmarks.json.', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__note">
                <?php esc_html_e('The address must start with http:// or https:// and must be reachable on the public internet. The check refuses an address that only works on this computer or on a private network.', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__note">
                <?php esc_html_e('If the page cannot be loaded, you see an error and no percent is offered. If the benchmark file lacks the coefficients the estimate needs, you also see an error. A failed estimate is not the same as zero percent faster.', 'migration-manager'); ?>
            </p>
        </li>
        <li class="wmm-tolka-help__step">
            <h3 class="wmm-tolka-help__step-title"><?php esc_html_e('Preview the content', 'migration-manager'); ?></h3>
            <p>
                <?php esc_html_e('Preview languages, posts, terms, strings, menus, and media before anything is written.', 'migration-manager'); ?>
            </p>
            <ul class="wmm-tolka-help__details">
                <li><?php esc_html_e('Languages are the languages configured in Polylang, including which one is the default.', 'migration-manager'); ?></li>
                <li><?php esc_html_e('Posts are grouped by translation, so one row covers the same post in each language rather than a separate row per language.', 'migration-manager'); ?></li>
                <li><?php esc_html_e('Terms are categories, tags, and other taxonomies, also grouped by translation.', 'migration-manager'); ?></li>
                <li><?php esc_html_e('Strings are the interface phrases Polylang stores, with a translation for each language.', 'migration-manager'); ?></li>
                <li><?php esc_html_e('Menus are the theme locations and the translated menu assigned to each language.', 'migration-manager'); ?></li>
                <li><?php esc_html_e('Media is the alternative text and titles on attachments, per language.', 'migration-manager'); ?></li>
            </ul>
            <p>
                <?php esc_html_e('Each part shows a count of everything found and a sample of at most twenty rows. The sample is only a look at the data. The migration includes every item that was found, not just the rows in the sample.', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__note">
                <?php esc_html_e('If a part has no items, that part stays empty and the preview can include a short error explaining why. If a part cannot be read at all, the preview names that part and still shows the parts that could be read.', 'migration-manager'); ?>
            </p>
        </li>
        <li class="wmm-tolka-help__step">
            <h3 class="wmm-tolka-help__step-title"><?php esc_html_e('Run the migration', 'migration-manager'); ?></h3>
            <p>
                <?php esc_html_e('Run the migration after you have read the preview. The plugin stores a Tolka package on this site and records Tolka language and group fields on each translated post and term.', 'migration-manager'); ?>
            </p>
            <p>
                <?php esc_html_e('Polylang data stays in place. The plugin does not delete other custom fields, and it does not delete Polylang content, options, or terms.', 'migration-manager'); ?>
            </p>
            <p class="wmm-tolka-help__note">
                <?php esc_html_e('When the run finishes, read the result. It tells you whether the migration succeeded and what failed. If the package cannot be saved, you see that error, and Polylang data is still left in place.', 'migration-manager'); ?>
            </p>
        </li>
    </ol>

    <h3 class="wmm-tolka-help__benchmark-title"><?php esc_html_e('Benchmark file', 'migration-manager'); ?></h3>
    <p class="wmm-tolka-help__benchmark">
        <?php esc_html_e('The speed estimate reads its coefficients from the benchmark file inside this plugin. The path is relative to the plugin folder.', 'migration-manager'); ?>
    </p>
    <p class="wmm-tolka-help__path">
        <code>includes/tolka/data/benchmarks.json</code>
    </p>
    <?php if (defined('MIGRATION_MANAGER_PLUGIN_DIR')) : ?>
        <p class="wmm-tolka-help__benchmark">
            <?php esc_html_e('On this server the file is at the following path.', 'migration-manager'); ?>
        </p>
        <p class="wmm-tolka-help__path">
            <code><?php echo esc_html(MIGRATION_MANAGER_PLUGIN_DIR . 'includes/tolka/data/benchmarks.json'); ?></code>
        </p>
    <?php endif; ?>
    <p class="wmm-tolka-help__benchmark">
        <?php esc_html_e('The copy shipped with the plugin uses placeholder coefficients, not laboratory measurements. The time to load the page is measured live. Replace the file with Tolka lab data before you use the percent in a pitch. Until you replace it, the speed result shows this note.', 'migration-manager'); ?>
    </p>
    <blockquote class="wmm-tolka-help__quote">
        <p><?php esc_html_e('Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.', 'migration-manager'); ?></p>
    </blockquote>
</section>
