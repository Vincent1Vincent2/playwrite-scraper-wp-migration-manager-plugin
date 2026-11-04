<?php

/**
 * Migrate to Tolka wizard.
 *
 * Markup for the five-step Polylang to Tolka migration screen.
 *
 * @package MigrationManager
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wmm-tolka">
    <h1 class="wmm-tolka-title"><?php esc_html_e('Migrate to Tolka', 'migration-manager'); ?></h1>

    <div id="wmm-tolka-wizard" class="wmm-tolka-wizard">
        <ol class="wmm-tolka-steps">
            <li class="wmm-tolka-step">
                <h2 class="wmm-tolka-step-title"><?php esc_html_e('Open Migrate to Tolka', 'migration-manager'); ?></h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Use this screen to read Polylang on this site and prepare a Tolka package. Polylang data is left in place.', 'migration-manager'); ?>
                </p>
            </li>

            <li class="wmm-tolka-step">
                <h2 class="wmm-tolka-step-title"><?php esc_html_e('Check Polylang', 'migration-manager'); ?></h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('The plugin checks whether Polylang is active on this WordPress site before it reads languages and translations.', 'migration-manager'); ?>
                </p>
            </li>

            <li class="wmm-tolka-step">
                <h2 class="wmm-tolka-step-title"><?php esc_html_e('Estimate speed', 'migration-manager'); ?></h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Load the public site URL and see how much faster Tolka is estimated to be.', 'migration-manager'); ?>
                </p>

                <div class="wmm-tolka-field">
                    <label class="wmm-tolka-label" for="wmm-tolka-url">
                        <?php esc_html_e('Public site URL', 'migration-manager'); ?>
                    </label>
                    <input
                        type="url"
                        id="wmm-tolka-url"
                        class="regular-text wmm-tolka-url"
                        name="url"
                        value="<?php echo esc_url(home_url()); ?>"
                        inputmode="url"
                        autocomplete="url" />
                    <p class="description wmm-tolka-description">
                        <?php esc_html_e('Defaults to the home URL of this site. The speed check fetches this address.', 'migration-manager'); ?>
                    </p>
                </div>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-measure"
                        class="button button-secondary wmm-tolka-button"
                        aria-controls="wmm-tolka-speed">
                        <?php esc_html_e('Measure speed', 'migration-manager'); ?>
                    </button>
                </p>

                <div id="wmm-tolka-speed" class="wmm-tolka-speed" aria-live="polite">
                    <?php
                    if (file_exists(__DIR__ . '/speed-estimate.php')) {
                        include __DIR__ . '/speed-estimate.php';
                    }
                    ?>
                </div>
            </li>

            <li class="wmm-tolka-step">
                <h2 class="wmm-tolka-step-title"><?php esc_html_e('Preview', 'migration-manager'); ?></h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Preview languages, posts, terms, strings, menus, and media before anything is written.', 'migration-manager'); ?>
                </p>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-preview"
                        class="button button-secondary wmm-tolka-button"
                        aria-controls="wmm-tolka-preview-result">
                        <?php esc_html_e('Preview migration', 'migration-manager'); ?>
                    </button>
                </p>

                <div id="wmm-tolka-preview-result" class="wmm-tolka-preview-result" aria-live="polite"></div>
            </li>

            <li class="wmm-tolka-step">
                <h2 class="wmm-tolka-step-title"><?php esc_html_e('Run the migration', 'migration-manager'); ?></h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Polylang data stays in place. A Tolka package is stored and Tolka meta is written.', 'migration-manager'); ?>
                </p>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-run"
                        class="button button-primary wmm-tolka-button"
                        aria-controls="wmm-tolka-report">
                        <?php esc_html_e('Run migration', 'migration-manager'); ?>
                    </button>
                </p>

                <div id="wmm-tolka-report" class="wmm-tolka-report" aria-live="polite"></div>
            </li>
        </ol>

        <?php
        if (file_exists(__DIR__ . '/help.php')) {
            include __DIR__ . '/help.php';
        }
        ?>
    </div>
</div>
