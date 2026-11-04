<?php

/**
 * Migrate to Tolka wizard.
 *
 * Markup for the five-step Polylang to Tolka screen.
 * Speed and help partials in this directory are included when they exist.
 *
 * @package MigrationManager
 */

if (!defined('ABSPATH')) {
    exit;
}

$wmm_tolka_home_url = '';
if (function_exists('home_url')) {
    $wmm_tolka_home_candidate = home_url();
    if (is_string($wmm_tolka_home_candidate)) {
        $wmm_tolka_home_url = $wmm_tolka_home_candidate;
    }
}

$wmm_tolka_partial_html = array(
    'speed' => '',
    'help' => '',
);
$wmm_tolka_partial_found = array(
    'speed' => false,
    'help' => false,
);
$wmm_tolka_partial_files = array(
    'speed' => 'speed-estimate.php',
    'help' => 'help.php',
);

foreach ($wmm_tolka_partial_files as $wmm_tolka_partial_key => $wmm_tolka_partial_name) {
    if (!is_string($wmm_tolka_partial_name) || basename($wmm_tolka_partial_name) !== $wmm_tolka_partial_name) {
        continue;
    }

    $wmm_tolka_partial_path = __DIR__ . '/' . $wmm_tolka_partial_name;
    if (!is_file($wmm_tolka_partial_path) || !is_readable($wmm_tolka_partial_path)) {
        continue;
    }

    $wmm_tolka_partial_found[$wmm_tolka_partial_key] = true;
    ob_start();
    include $wmm_tolka_partial_path;
    $wmm_tolka_partial_captured = ob_get_clean();
    if (is_string($wmm_tolka_partial_captured)) {
        $wmm_tolka_partial_html[$wmm_tolka_partial_key] = $wmm_tolka_partial_captured;
    }
}

$wmm_tolka_polylang = array(
    'state' => 'missing',
    'version' => '',
    'errors' => array(),
);

if (class_exists('\WMM\Tolka\Polylang_Detector', false) && method_exists('\WMM\Tolka\Polylang_Detector', 'status')) {
    $wmm_tolka_polylang['state'] = 'unreadable';
    $wmm_tolka_status = null;

    try {
        $wmm_tolka_status = \WMM\Tolka\Polylang_Detector::status();
    } catch (\Throwable $wmm_tolka_status_error) {
        $wmm_tolka_status_message = trim($wmm_tolka_status_error->getMessage());
        if ($wmm_tolka_status_message !== '') {
            $wmm_tolka_polylang['errors'][] = $wmm_tolka_status_message;
        }
    }

    if (is_array($wmm_tolka_status) && array_key_exists('active', $wmm_tolka_status) && is_bool($wmm_tolka_status['active'])) {
        $wmm_tolka_polylang['state'] = $wmm_tolka_status['active'] ? 'active' : 'inactive';

        if (isset($wmm_tolka_status['version']) && is_string($wmm_tolka_status['version'])) {
            $wmm_tolka_polylang['version'] = trim($wmm_tolka_status['version']);
        }

        if (isset($wmm_tolka_status['errors']) && is_array($wmm_tolka_status['errors'])) {
            foreach ($wmm_tolka_status['errors'] as $wmm_tolka_polylang_error) {
                if (!is_string($wmm_tolka_polylang_error)) {
                    continue;
                }

                $wmm_tolka_polylang_error = trim($wmm_tolka_polylang_error);
                if ($wmm_tolka_polylang_error === '') {
                    continue;
                }

                $wmm_tolka_polylang['errors'][] = $wmm_tolka_polylang_error;
            }
        }
    }
}

$wmm_tolka_speed_html = $wmm_tolka_partial_html['speed'];
$wmm_tolka_help_html = $wmm_tolka_partial_html['help'];
$wmm_tolka_speed_ready = trim($wmm_tolka_speed_html) !== '';
$wmm_tolka_help_ready = trim($wmm_tolka_help_html) !== '';
?>

<div class="wrap wmm-tolka">
    <h1 class="wmm-tolka-title"><?php esc_html_e('Migrate to Tolka', 'migration-manager'); ?></h1>
    <p class="wmm-tolka-intro">
        <?php esc_html_e('Move this site from Polylang to Tolka in five steps. You can stop after any step. Polylang data stays in place.', 'migration-manager'); ?>
    </p>

    <div id="wmm-tolka-wizard" class="wmm-tolka-wizard">
        <ol class="wmm-tolka-steps">
            <li class="wmm-tolka-step">
                <h2 id="wmm-tolka-step-open-title" class="wmm-tolka-step-title">
                    <?php esc_html_e('Open Migrate to Tolka', 'migration-manager'); ?>
                </h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('This screen checks Polylang, estimates speed from the public site URL, previews languages, posts, terms, strings, menus, and media, then runs the migration when you choose to.', 'migration-manager'); ?>
                </p>
            </li>

            <li class="wmm-tolka-step">
                <h2 id="wmm-tolka-step-polylang-title" class="wmm-tolka-step-title">
                    <?php esc_html_e('Check Polylang', 'migration-manager'); ?>
                </h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('The plugin checks whether Polylang is active on this WordPress site. The check leaves Polylang installed, and it leaves Polylang data, options, and terms in place.', 'migration-manager'); ?>
                </p>

                <div id="wmm-tolka-polylang" class="wmm-tolka-panel wmm-tolka-polylang" role="status" aria-labelledby="wmm-tolka-step-polylang-title">
                    <?php if ($wmm_tolka_polylang['state'] === 'active') : ?>
                        <div class="notice notice-success inline wmm-tolka-status wmm-tolka-status-active">
                            <p>
                                <?php esc_html_e('Polylang is active on this site.', 'migration-manager'); ?>
                                <?php if ($wmm_tolka_polylang['version'] !== '') : ?>
                                    <?php
                                    printf(
                                        /* translators: %s: Polylang version number. */
                                        esc_html__('Version %s.', 'migration-manager'),
                                        esc_html($wmm_tolka_polylang['version'])
                                    );
                                    ?>
                                <?php endif; ?>
                                <?php esc_html_e('Its data stays in place during the migration.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php elseif ($wmm_tolka_polylang['state'] === 'inactive') : ?>
                        <div class="notice notice-warning inline wmm-tolka-status wmm-tolka-status-inactive">
                            <p>
                                <?php esc_html_e('Polylang is not active on this site. You can still use the steps that are available. Polylang data stays in place.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php elseif ($wmm_tolka_polylang['state'] === 'unreadable') : ?>
                        <div class="notice notice-error inline wmm-tolka-status wmm-tolka-status-unreadable">
                            <p>
                                <?php esc_html_e('Polylang could not be checked. The status came back in a form this screen cannot read. No data was changed.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-warning inline wmm-tolka-status wmm-tolka-status-missing">
                            <p>
                                <?php esc_html_e('Polylang could not be checked because that check is missing from this copy of the plugin. No data was changed. You can continue with the speed estimate, the preview, and the migration steps that are present.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($wmm_tolka_polylang['errors'])) : ?>
                        <div class="notice notice-error inline wmm-tolka-errors" role="alert">
                            <p><?php esc_html_e('The Polylang check reported a problem:', 'migration-manager'); ?></p>
                            <ul class="wmm-tolka-error-list">
                                <?php foreach ($wmm_tolka_polylang['errors'] as $wmm_tolka_polylang_error_line) : ?>
                                    <li><?php echo esc_html($wmm_tolka_polylang_error_line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </li>

            <li class="wmm-tolka-step">
                <h2 id="wmm-tolka-step-speed-title" class="wmm-tolka-step-title">
                    <?php esc_html_e('Estimate speed', 'migration-manager'); ?>
                </h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Load the public site URL. The result shows the current load, the estimated Tolka load, the percent faster, and the basis for that estimate.', 'migration-manager'); ?>
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
                        value="<?php echo esc_url($wmm_tolka_home_url); ?>"
                        inputmode="url"
                        autocomplete="url"
                        autocapitalize="off"
                        spellcheck="false"
                        aria-describedby="wmm-tolka-url-note" />
                    <p id="wmm-tolka-url-note" class="description wmm-tolka-description">
                        <?php if ($wmm_tolka_home_url !== '') : ?>
                            <?php
                            printf(
                                /* translators: %s: site home URL. */
                                esc_html__('Starts as the home URL of this site, %s. Measure a public http or https address.', 'migration-manager'),
                                esc_html($wmm_tolka_home_url)
                            );
                            ?>
                        <?php else : ?>
                            <?php esc_html_e('No home URL is available. Enter a public http or https address to measure.', 'migration-manager'); ?>
                        <?php endif; ?>
                        <?php esc_html_e('If the check cannot finish, the reason appears in the speed panel and this site is left unchanged.', 'migration-manager'); ?>
                    </p>
                </div>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-measure"
                        class="button button-secondary button-large wmm-tolka-button"
                        aria-busy="false"
                        aria-controls="wmm-tolka-speed">
                        <span class="dashicons dashicons-performance" aria-hidden="true"></span>
                        <?php esc_html_e('Measure speed', 'migration-manager'); ?>
                    </button>
                </p>

                <div
                    id="wmm-tolka-speed"
                    class="wmm-tolka-panel wmm-tolka-speed"
                    role="region"
                    aria-labelledby="wmm-tolka-step-speed-title"
                    aria-live="polite"
                    aria-atomic="true"
                    tabindex="-1">
                    <?php // Speed partial owns this interior when it has markup. Otherwise the waiting state below is shown. Replace this element's contents with the measurement or the error string. ?>
                    <?php if ($wmm_tolka_speed_ready) : ?>
                        <?php echo $wmm_tolka_speed_html; ?>
                    <?php else : ?>
                        <div class="wmm-tolka-empty" data-wmm-tolka-empty="true">
                            <?php if ($wmm_tolka_partial_found['speed']) : ?>
                                <div class="notice notice-error inline wmm-tolka-notice">
                                    <p>
                                        <?php esc_html_e('The speed panel did not load any details. You can still measure. A result or an error replaces this waiting state, and the site is left unchanged when the check fails.', 'migration-manager'); ?>
                                    </p>
                                </div>
                            <?php else : ?>
                                <div class="notice notice-warning inline wmm-tolka-notice">
                                    <p>
                                        <?php esc_html_e('The speed panel is not included in this copy of the plugin. You can still measure. A result or an error replaces this waiting state, and the site is left unchanged when the check fails.', 'migration-manager'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <p class="wmm-tolka-empty-lead">
                                <?php esc_html_e('No speed estimate yet. Measure the public site URL to fill the figures below.', 'migration-manager'); ?>
                            </p>
                            <table class="widefat striped wmm-tolka-summary">
                                <caption class="screen-reader-text wmm-tolka-caption">
                                    <?php esc_html_e('Speed estimate waiting for a measurement.', 'migration-manager'); ?>
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e('Figure', 'migration-manager'); ?></th>
                                        <th scope="col"><?php esc_html_e('Status', 'migration-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Current load', 'migration-manager'); ?></th>
                                        <td><?php esc_html_e('Not measured yet', 'migration-manager'); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Estimated Tolka load', 'migration-manager'); ?></th>
                                        <td><?php esc_html_e('Not measured yet', 'migration-manager'); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Percent faster', 'migration-manager'); ?></th>
                                        <td><?php esc_html_e('Not measured yet', 'migration-manager'); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Basis', 'migration-manager'); ?></th>
                                        <td><?php esc_html_e('Not measured yet', 'migration-manager'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </li>

            <li class="wmm-tolka-step">
                <h2 id="wmm-tolka-step-preview-title" class="wmm-tolka-step-title">
                    <?php esc_html_e('Preview', 'migration-manager'); ?>
                </h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Preview languages, posts, terms, strings, menus, and media before anything is written. The preview shows a count and a sample of up to 20 items for each section.', 'migration-manager'); ?>
                </p>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-preview"
                        class="button button-secondary button-large wmm-tolka-button"
                        aria-busy="false"
                        aria-controls="wmm-tolka-preview-result">
                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                        <?php esc_html_e('Preview migration', 'migration-manager'); ?>
                    </button>
                </p>

                <div
                    id="wmm-tolka-preview-result"
                    class="wmm-tolka-panel wmm-tolka-preview-result"
                    role="region"
                    aria-labelledby="wmm-tolka-step-preview-title"
                    aria-live="polite"
                    aria-atomic="true"
                    tabindex="-1">
                    <?php // Waiting state. Replace this element's contents with the preview or the error string. ?>
                    <div class="wmm-tolka-empty" data-wmm-tolka-empty="true">
                        <p class="wmm-tolka-empty-lead">
                            <?php esc_html_e('Nothing has been previewed yet. Choose Preview migration to load each section. The preview writes nothing.', 'migration-manager'); ?>
                        </p>
                        <table class="widefat striped wmm-tolka-summary">
                            <caption class="screen-reader-text wmm-tolka-caption">
                                <?php esc_html_e('Migration preview waiting to be loaded.', 'migration-manager'); ?>
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Section', 'migration-manager'); ?></th>
                                    <th scope="col"><?php esc_html_e('What it includes', 'migration-manager'); ?></th>
                                    <th scope="col"><?php esc_html_e('Status', 'migration-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Languages', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Languages configured on this site.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Posts', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Translation groups for posts and other post types.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Terms', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Translation groups for categories, tags, and other terms.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Strings', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Interface strings and their translations.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Menus', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Menu locations and the menu used for each language.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Media', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Attachment files, alt text, and titles.', 'migration-manager'); ?></td>
                                    <td><?php esc_html_e('Not loaded yet', 'migration-manager'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="notice notice-info inline wmm-tolka-notice">
                            <p>
                                <?php esc_html_e('If Polylang is inactive or part of the migration is missing, the error replaces this waiting state. Polylang data stays in place.', 'migration-manager'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </li>

            <li class="wmm-tolka-step">
                <h2 id="wmm-tolka-step-run-title" class="wmm-tolka-step-title">
                    <?php esc_html_e('Run the migration', 'migration-manager'); ?>
                </h2>
                <p class="wmm-tolka-step-text">
                    <?php esc_html_e('Run the migration when the preview looks right. Polylang data stays in place. A Tolka package is stored and Tolka meta is written. Existing Polylang options and terms stay on this site.', 'migration-manager'); ?>
                </p>

                <p class="wmm-tolka-actions">
                    <button
                        type="button"
                        id="wmm-tolka-run"
                        class="button button-primary button-large wmm-tolka-button"
                        aria-busy="false"
                        aria-controls="wmm-tolka-report">
                        <span class="dashicons dashicons-migrate" aria-hidden="true"></span>
                        <?php esc_html_e('Run migration', 'migration-manager'); ?>
                    </button>
                </p>

                <div
                    id="wmm-tolka-report"
                    class="wmm-tolka-panel wmm-tolka-report"
                    role="region"
                    aria-labelledby="wmm-tolka-step-run-title"
                    aria-live="polite"
                    aria-atomic="true"
                    tabindex="-1">
                    <?php // Waiting state. Replace this element's contents with the migration report or the error string. ?>
                    <div class="wmm-tolka-empty" data-wmm-tolka-empty="true">
                        <div class="notice notice-info inline wmm-tolka-notice">
                            <p>
                                <?php esc_html_e('No migration has been run yet. The result appears here after you run it. If the run cannot finish, the error appears here instead, and Polylang data is left in place.', 'migration-manager'); ?>
                            </p>
                        </div>
                        <table class="widefat striped wmm-tolka-summary">
                            <caption class="screen-reader-text wmm-tolka-caption">
                                <?php esc_html_e('Migration report waiting for a run.', 'migration-manager'); ?>
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Report line', 'migration-manager'); ?></th>
                                    <th scope="col"><?php esc_html_e('Status', 'migration-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Title', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Summary', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Languages', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Posts', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Terms', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Strings', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Menus', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Media', 'migration-manager'); ?></th>
                                    <td><?php esc_html_e('Not run yet', 'migration-manager'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </li>
        </ol>

        <aside class="wmm-tolka-help-region" aria-label="<?php esc_attr_e('How to migrate from Polylang', 'migration-manager'); ?>">
            <?php if ($wmm_tolka_help_ready) : ?>
                <?php echo $wmm_tolka_help_html; ?>
            <?php else : ?>
                <div class="wmm-tolka-help wmm-tolka-help-fallback">
                    <h2 id="wmm-tolka-help-heading" class="wmm-tolka-help-title">
                        <?php esc_html_e('How to migrate from Polylang', 'migration-manager'); ?>
                    </h2>
                    <?php if ($wmm_tolka_partial_found['help']) : ?>
                        <div class="notice notice-error inline wmm-tolka-notice">
                            <p>
                                <?php esc_html_e('Help for these steps did not load. Follow the five steps on this screen. No data was changed.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-warning inline wmm-tolka-notice">
                            <p>
                                <?php esc_html_e('In-plugin help is not included in this copy of the plugin. Follow the five steps on this screen. No data was changed.', 'migration-manager'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    <ol class="wmm-tolka-help-steps">
                        <li><?php esc_html_e('Open Migrate to Tolka.', 'migration-manager'); ?></li>
                        <li><?php esc_html_e('The plugin checks Polylang on this WordPress site.', 'migration-manager'); ?></li>
                        <li><?php esc_html_e('It loads the public site URL (the site home URL by default) and shows how much faster Tolka is estimated to be.', 'migration-manager'); ?></li>
                        <li><?php esc_html_e('Preview languages, posts, terms, strings, menus, and media.', 'migration-manager'); ?></li>
                        <li><?php esc_html_e('Run the migration. Polylang data stays in place. A Tolka package is stored and Tolka meta is written.', 'migration-manager'); ?></li>
                    </ol>
                    <p class="wmm-tolka-help-benchmark">
                        <?php esc_html_e('The speed estimate reads its coefficients from the benchmark file below, inside this plugin. Those coefficients are placeholders, not lab measurements. The page timing is measured live. Replace the file with Tolka lab data before using the percent in a pitch.', 'migration-manager'); ?>
                    </p>
                    <p class="wmm-tolka-help-path">
                        <code>includes/tolka/data/benchmarks.json</code>
                    </p>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
