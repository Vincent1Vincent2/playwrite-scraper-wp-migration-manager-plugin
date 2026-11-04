<?php

/**
 * Migrate to Tolka speed estimate.
 *
 * Markup inside #wmm-tolka-speed. The wizard includes this file.
 * Optional $estimate is Speed_Estimate::project(), or an AJAX payload
 * whose estimate key is that array.
 *
 * Slots the behavior script can fill: [data-field="summary"],
 * [data-field="current_ms"], [data-field="tolka_ms"],
 * [data-field="faster_label"], [data-field="basis"],
 * [data-field="provenance_note"], and [data-field="error"].
 * The provenance note is shown when provenance is placeholder.
 *
 * @package MigrationManager
 */

if (!defined('ABSPATH')) {
    exit;
}

$wmm_tolka_speed_text = static function ($value) {
    if (is_string($value) || is_numeric($value)) {
        return trim((string) $value);
    }

    return '';
};

$wmm_tolka_speed_format_ms = static function ($milliseconds) {
    if (!is_scalar($milliseconds) || !is_numeric($milliseconds)) {
        return '';
    }

    $number = (float) $milliseconds;
    if ($number < 0) {
        return '';
    }

    $decimals = ((float) (int) $number === $number) ? 0 : 1;
    if (function_exists('number_format_i18n')) {
        $formatted = number_format_i18n($number, $decimals);
    } else {
        $formatted = number_format($number, $decimals);
    }

    return sprintf(
        /* translators: %s: load time in milliseconds. */
        __('%s ms', 'migration-manager'),
        $formatted
    );
};

$wmm_tolka_speed_ms_attr = static function ($milliseconds) {
    if (!is_scalar($milliseconds) || !is_numeric($milliseconds)) {
        return '';
    }

    $number = (float) $milliseconds;
    if ($number < 0) {
        return '';
    }

    return (string) (int) round($number);
};

$wmm_tolka_speed_pending = __('Not measured yet', 'migration-manager');
$wmm_tolka_speed_unavailable = __('Unavailable', 'migration-manager');
$wmm_tolka_speed_basis_fallback = 'Measured page load plus coefficients from benchmarks.json';
$wmm_tolka_speed_note_fallback = 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.';

$wmm_tolka_speed_view = array(
    'state' => 'empty',
    'provenance' => '',
    'show_note' => false,
    'summary' => __('No measurement yet. Measure the public site URL to see the current load, the estimated Tolka load, and how much faster Tolka is.', 'migration-manager'),
    'current' => $wmm_tolka_speed_pending,
    'current_ms' => '',
    'tolka' => $wmm_tolka_speed_pending,
    'tolka_ms' => '',
    'faster' => $wmm_tolka_speed_pending,
    'faster_percent' => '',
    'basis' => '',
    'note' => '',
    'error' => '',
);

$wmm_tolka_speed_payload = null;
if (isset($estimate) && is_array($estimate)) {
    $wmm_tolka_speed_payload = $estimate;
    if (
        isset($wmm_tolka_speed_payload['estimate'])
        && is_array($wmm_tolka_speed_payload['estimate'])
        && !array_key_exists('ok', $wmm_tolka_speed_payload)
        && !array_key_exists('current_ms', $wmm_tolka_speed_payload)
    ) {
        $wmm_tolka_speed_payload = $wmm_tolka_speed_payload['estimate'];
    }
} elseif (isset($estimate)) {
    $wmm_tolka_speed_view['state'] = 'error';
}

if (is_array($wmm_tolka_speed_payload) && array() !== $wmm_tolka_speed_payload) {
    $wmm_tolka_speed_ok = (isset($wmm_tolka_speed_payload['ok']) && true === $wmm_tolka_speed_payload['ok']);
    $wmm_tolka_speed_view['provenance'] = $wmm_tolka_speed_text(
        isset($wmm_tolka_speed_payload['provenance']) ? $wmm_tolka_speed_payload['provenance'] : ''
    );
    $wmm_tolka_speed_view['show_note'] = ('placeholder' === $wmm_tolka_speed_view['provenance']);
    if ($wmm_tolka_speed_view['show_note']) {
        $wmm_tolka_speed_view['note'] = $wmm_tolka_speed_text(
            isset($wmm_tolka_speed_payload['provenance_note']) ? $wmm_tolka_speed_payload['provenance_note'] : ''
        );
        if ('' === $wmm_tolka_speed_view['note']) {
            $wmm_tolka_speed_view['note'] = $wmm_tolka_speed_note_fallback;
        }
    }

    if ($wmm_tolka_speed_ok) {
        $wmm_tolka_speed_view['state'] = 'ready';
        $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_format_ms(
            isset($wmm_tolka_speed_payload['current_ms']) ? $wmm_tolka_speed_payload['current_ms'] : null
        );
        $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_format_ms(
            isset($wmm_tolka_speed_payload['tolka_ms']) ? $wmm_tolka_speed_payload['tolka_ms'] : null
        );
        $wmm_tolka_speed_view['current_ms'] = $wmm_tolka_speed_ms_attr(
            isset($wmm_tolka_speed_payload['current_ms']) ? $wmm_tolka_speed_payload['current_ms'] : null
        );
        $wmm_tolka_speed_view['tolka_ms'] = $wmm_tolka_speed_ms_attr(
            isset($wmm_tolka_speed_payload['tolka_ms']) ? $wmm_tolka_speed_payload['tolka_ms'] : null
        );
        $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_text(
            isset($wmm_tolka_speed_payload['faster_label']) ? $wmm_tolka_speed_payload['faster_label'] : ''
        );
        if (
            '' === $wmm_tolka_speed_view['faster']
            && isset($wmm_tolka_speed_payload['faster_percent'])
            && is_numeric($wmm_tolka_speed_payload['faster_percent'])
        ) {
            $wmm_tolka_speed_view['faster'] = number_format((float) $wmm_tolka_speed_payload['faster_percent'], 1, '.', '') . '% faster';
        }
        if (
            isset($wmm_tolka_speed_payload['faster_percent'])
            && is_numeric($wmm_tolka_speed_payload['faster_percent'])
        ) {
            $wmm_tolka_speed_view['faster_percent'] = number_format((float) $wmm_tolka_speed_payload['faster_percent'], 1, '.', '');
        }
        $wmm_tolka_speed_view['basis'] = $wmm_tolka_speed_text(
            isset($wmm_tolka_speed_payload['basis']) ? $wmm_tolka_speed_payload['basis'] : ''
        );
        if ('' === $wmm_tolka_speed_view['basis']) {
            $wmm_tolka_speed_view['basis'] = $wmm_tolka_speed_basis_fallback;
        }

        if (
            '' !== $wmm_tolka_speed_view['current']
            && '' !== $wmm_tolka_speed_view['tolka']
            && '' !== $wmm_tolka_speed_view['faster']
        ) {
            $wmm_tolka_speed_view['summary'] = sprintf(
                /* translators: 1: percent faster label, such as "27.9% faster". 2: current load. 3: estimated Tolka load. */
                __('Tolka is estimated to be %1$s on this page. The current load is %2$s, and the estimated Tolka load is %3$s.', 'migration-manager'),
                $wmm_tolka_speed_view['faster'],
                $wmm_tolka_speed_view['current'],
                $wmm_tolka_speed_view['tolka']
            );
        } else {
            $wmm_tolka_speed_view['current'] = '' !== $wmm_tolka_speed_view['current'] ? $wmm_tolka_speed_view['current'] : $wmm_tolka_speed_unavailable;
            $wmm_tolka_speed_view['tolka'] = '' !== $wmm_tolka_speed_view['tolka'] ? $wmm_tolka_speed_view['tolka'] : $wmm_tolka_speed_unavailable;
            $wmm_tolka_speed_view['faster'] = '' !== $wmm_tolka_speed_view['faster'] ? $wmm_tolka_speed_view['faster'] : $wmm_tolka_speed_unavailable;
            $wmm_tolka_speed_view['summary'] = __('The speed estimate completed, but one or more figures were missing.', 'migration-manager');
        }
    } else {
        $wmm_tolka_speed_view['state'] = 'error';
        $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_unavailable;
        $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_unavailable;
        $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_unavailable;
        $wmm_tolka_speed_detail = $wmm_tolka_speed_text(
            isset($wmm_tolka_speed_payload['error']) ? $wmm_tolka_speed_payload['error'] : ''
        );
        if ('' === $wmm_tolka_speed_detail) {
            $wmm_tolka_speed_view['error'] = __('The speed estimate could not be completed. Check the public site URL and measure again.', 'migration-manager');
        } else {
            $wmm_tolka_speed_view['error'] = sprintf(
                /* translators: %s: reason the speed estimate failed. */
                __('The speed estimate could not be completed. %s', 'migration-manager'),
                $wmm_tolka_speed_detail
            );
        }
        $wmm_tolka_speed_view['summary'] = '';
    }
}

if ('error' === $wmm_tolka_speed_view['state'] && '' === $wmm_tolka_speed_view['error']) {
    $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['error'] = __('The speed estimate could not be completed. Check the public site URL and measure again.', 'migration-manager');
    $wmm_tolka_speed_view['summary'] = '';
}

$wmm_tolka_speed_classes = array('wmm-tolka-speed-panel');
if ('ready' === $wmm_tolka_speed_view['state']) {
    $wmm_tolka_speed_classes[] = 'wmm-tolka-is-ready';
} elseif ('error' === $wmm_tolka_speed_view['state']) {
    $wmm_tolka_speed_classes[] = 'wmm-tolka-is-error';
} else {
    $wmm_tolka_speed_classes[] = 'wmm-tolka-is-empty';
}
if ($wmm_tolka_speed_view['show_note']) {
    $wmm_tolka_speed_classes[] = 'wmm-tolka-is-placeholder';
}
?>

<div
    class="<?php echo esc_attr(implode(' ', $wmm_tolka_speed_classes)); ?>"
    data-state="<?php echo esc_attr($wmm_tolka_speed_view['state']); ?>"
    data-provenance="<?php echo esc_attr($wmm_tolka_speed_view['provenance']); ?>"
    aria-labelledby="wmm-tolka-speed-heading">
    <h3 class="wmm-tolka-speed-heading" id="wmm-tolka-speed-heading">
        <?php esc_html_e('Estimated speed', 'migration-manager'); ?>
    </h3>

    <?php if ('error' !== $wmm_tolka_speed_view['state']) : ?>
        <p
            class="wmm-tolka-speed-summary<?php echo ('empty' === $wmm_tolka_speed_view['state']) ? ' description' : ''; ?>"
            id="wmm-tolka-speed-summary"
            data-field="summary">
            <?php echo esc_html($wmm_tolka_speed_view['summary']); ?>
        </p>
    <?php else : ?>
        <p class="wmm-tolka-speed-summary" id="wmm-tolka-speed-summary" data-field="summary" hidden></p>
    <?php endif; ?>

    <dl class="wmm-tolka-speed-figures">
        <div class="wmm-tolka-speed-figure wmm-tolka-speed-current">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-current-load-label">
                <?php esc_html_e('Current load', 'migration-manager'); ?>
            </dt>
            <dd
                class="wmm-tolka-speed-value"
                id="wmm-tolka-current-load"
                data-field="current_ms"
                data-ms="<?php echo esc_attr($wmm_tolka_speed_view['current_ms']); ?>"
                aria-labelledby="wmm-tolka-current-load-label">
                <?php echo esc_html($wmm_tolka_speed_view['current']); ?>
            </dd>
        </div>

        <div class="wmm-tolka-speed-figure wmm-tolka-speed-tolka">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-tolka-load-label">
                <?php esc_html_e('Estimated Tolka load', 'migration-manager'); ?>
            </dt>
            <dd
                class="wmm-tolka-speed-value"
                id="wmm-tolka-tolka-load"
                data-field="tolka_ms"
                data-ms="<?php echo esc_attr($wmm_tolka_speed_view['tolka_ms']); ?>"
                aria-labelledby="wmm-tolka-tolka-load-label">
                <?php echo esc_html($wmm_tolka_speed_view['tolka']); ?>
            </dd>
        </div>

        <div class="wmm-tolka-speed-figure wmm-tolka-speed-faster">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-faster-label">
                <?php esc_html_e('Percent faster', 'migration-manager'); ?>
            </dt>
            <dd
                class="wmm-tolka-speed-value"
                id="wmm-tolka-faster"
                data-field="faster_label"
                data-faster-percent="<?php echo esc_attr($wmm_tolka_speed_view['faster_percent']); ?>"
                aria-labelledby="wmm-tolka-faster-label">
                <?php echo esc_html($wmm_tolka_speed_view['faster']); ?>
            </dd>
        </div>
    </dl>

    <p
        class="wmm-tolka-speed-basis"
        id="wmm-tolka-basis"
        data-field="basis"
        <?php echo ('' === $wmm_tolka_speed_view['basis']) ? 'hidden' : ''; ?>>
        <?php echo esc_html($wmm_tolka_speed_view['basis']); ?>
    </p>

    <div
        class="wmm-tolka-provenance-note<?php echo $wmm_tolka_speed_view['show_note'] ? ' notice notice-warning' : ''; ?>"
        <?php echo $wmm_tolka_speed_view['show_note'] ? '' : 'hidden'; ?>>
        <p id="wmm-tolka-provenance-note" data-field="provenance_note" role="note">
            <?php echo esc_html($wmm_tolka_speed_view['note']); ?>
        </p>
    </div>

    <div
        class="wmm-tolka-speed-error<?php echo ('error' === $wmm_tolka_speed_view['state']) ? ' notice notice-error' : ''; ?>"
        <?php echo ('error' === $wmm_tolka_speed_view['state']) ? 'role="alert"' : 'hidden'; ?>>
        <p id="wmm-tolka-speed-error" data-field="error">
            <?php echo esc_html($wmm_tolka_speed_view['error']); ?>
        </p>
    </div>
</div>

<?php
unset(
    $wmm_tolka_speed_text,
    $wmm_tolka_speed_format_ms,
    $wmm_tolka_speed_ms_attr,
    $wmm_tolka_speed_pending,
    $wmm_tolka_speed_unavailable,
    $wmm_tolka_speed_basis_fallback,
    $wmm_tolka_speed_note_fallback,
    $wmm_tolka_speed_view,
    $wmm_tolka_speed_payload,
    $wmm_tolka_speed_ok,
    $wmm_tolka_speed_detail,
    $wmm_tolka_speed_classes
);
