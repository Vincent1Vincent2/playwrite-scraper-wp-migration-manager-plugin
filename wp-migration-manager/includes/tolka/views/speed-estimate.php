<?php

/**
 * Migrate to Tolka speed estimate.
 *
 * Markup inside #wmm-tolka-speed. The wizard includes this file.
 * Optional $estimate is Speed_Estimate::project(), or a measure payload
 * with an estimate key.
 *
 * Stable ids, filled in place by the behavior script:
 * #wmm-tolka-current-load, #wmm-tolka-tolka-load, #wmm-tolka-faster,
 * #wmm-tolka-basis, and #wmm-tolka-provenance-note.
 * The provenance note is shown when provenance is placeholder.
 * Basis text, the faster label, the provenance note, and the load
 * times are escaped before they are printed.
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

$wmm_tolka_speed_plain = static function ($value) use ($wmm_tolka_speed_text) {
    $text = $wmm_tolka_speed_text($value);
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    if (!is_string($stripped)) {
        return str_replace("\0", '', $text);
    }

    return $stripped;
};

$wmm_tolka_speed_number = static function ($value) {
    if (!is_scalar($value) || !is_numeric($value)) {
        return null;
    }

    $number = (float) $value;
    if (!is_finite($number) || $number < 0) {
        return null;
    }

    return $number;
};

$wmm_tolka_speed_format_ms = static function ($number) {
    if (!is_float($number) && !is_int($number)) {
        return '';
    }

    $decimals = ((float) (int) $number === (float) $number) ? 0 : 1;
    if (function_exists('number_format_i18n')) {
        $formatted = number_format_i18n($number, $decimals);
    } else {
        $formatted = number_format($number, $decimals);
    }

    if ($number >= 1000) {
        $seconds = $number / 1000;
        $hundredths = (int) round($seconds * 100);
        if (0 === $hundredths % 100) {
            $second_decimals = 0;
        } elseif (0 === $hundredths % 10) {
            $second_decimals = 1;
        } else {
            $second_decimals = 2;
        }

        if (0 === $second_decimals && 1 === (int) round($seconds)) {
            return sprintf(
                /* translators: %s: load time in milliseconds. */
                __('%s ms (1 second)', 'migration-manager'),
                $formatted
            );
        }

        if (function_exists('number_format_i18n')) {
            $seconds_text = number_format_i18n($seconds, $second_decimals);
        } else {
            $seconds_text = number_format($seconds, $second_decimals);
        }

        return sprintf(
            /* translators: 1: load time in milliseconds, 2: the same load time in seconds. */
            __('%1$s ms (%2$s seconds)', 'migration-manager'),
            $formatted,
            $seconds_text
        );
    }

    return sprintf(
        /* translators: %s: load time in milliseconds. */
        __('%s ms', 'migration-manager'),
        $formatted
    );
};

$wmm_tolka_speed_ms_attr = static function ($number) {
    if (!is_float($number) && !is_int($number)) {
        return '';
    }

    if ((float) (int) $number === (float) $number) {
        return (string) (int) $number;
    }

    return number_format($number, 1, '.', '');
};

$wmm_tolka_speed_finish = static function ($sentence) {
    if ('' === $sentence) {
        return '';
    }

    if (preg_match('/[.!?]["\']?$/', $sentence)) {
        return $sentence;
    }

    return $sentence . '.';
};

$wmm_tolka_speed_pending = __('Not measured yet', 'migration-manager');
$wmm_tolka_speed_unavailable = __('Unavailable', 'migration-manager');
$wmm_tolka_speed_basis_fallback = __('Measured page load plus coefficients from benchmarks.json', 'migration-manager');
$wmm_tolka_speed_note_fallback = __('Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.', 'migration-manager');
$wmm_tolka_speed_scope_text = __('The speed percent is an estimate for the single page you load. It is not a result for every page on the site.', 'migration-manager');
$wmm_tolka_speed_error_fallback = __('The speed estimate could not be completed. Check the public site URL and measure again. No percent is shown.', 'migration-manager');

$wmm_tolka_speed_view = array(
    'state' => 'empty',
    'provenance' => '',
    'show_note' => false,
    'summary' => __('No measurement yet. Measure the site: enter its public URL and choose Measure speed. You will see the current load, the estimated Tolka load, the percent faster, and the basis of that estimate.', 'migration-manager'),
    'current' => $wmm_tolka_speed_pending,
    'current_ms' => '',
    'tolka' => $wmm_tolka_speed_pending,
    'tolka_ms' => '',
    'faster' => $wmm_tolka_speed_pending,
    'faster_percent' => '',
    'savings' => '',
    'saved_ms' => '',
    'show_scope' => false,
    'basis' => '',
    'note' => '',
    'error' => '',
);

$wmm_tolka_speed_truncated = false;
if (
    isset($estimate) && is_array($estimate)
    && isset($estimate['metrics']) && is_array($estimate['metrics'])
    && isset($estimate['metrics']['truncated']) && true === $estimate['metrics']['truncated']
) {
    $wmm_tolka_speed_truncated = true;
}

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
        $wmm_tolka_speed_view['note'] = $wmm_tolka_speed_plain(
            isset($wmm_tolka_speed_payload['provenance_note']) ? $wmm_tolka_speed_payload['provenance_note'] : ''
        );
        if ('' === $wmm_tolka_speed_view['note']) {
            $wmm_tolka_speed_view['note'] = $wmm_tolka_speed_note_fallback;
        }
    }

    if ($wmm_tolka_speed_ok) {
        $wmm_tolka_speed_view['state'] = 'ready';
        $wmm_tolka_speed_current_number = $wmm_tolka_speed_number(
            isset($wmm_tolka_speed_payload['current_ms']) ? $wmm_tolka_speed_payload['current_ms'] : null
        );
        $wmm_tolka_speed_tolka_number = $wmm_tolka_speed_number(
            isset($wmm_tolka_speed_payload['tolka_ms']) ? $wmm_tolka_speed_payload['tolka_ms'] : null
        );
        $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_plain($wmm_tolka_speed_format_ms($wmm_tolka_speed_current_number));
        $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_plain($wmm_tolka_speed_format_ms($wmm_tolka_speed_tolka_number));
        $wmm_tolka_speed_view['current_ms'] = $wmm_tolka_speed_ms_attr($wmm_tolka_speed_current_number);
        $wmm_tolka_speed_view['tolka_ms'] = $wmm_tolka_speed_ms_attr($wmm_tolka_speed_tolka_number);
        // Show the supplied label. Do not derive a percent from the two load times.
        $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_plain(
            isset($wmm_tolka_speed_payload['faster_label']) ? $wmm_tolka_speed_payload['faster_label'] : ''
        );

        $wmm_tolka_speed_percent = null;
        if ('' !== $wmm_tolka_speed_view['faster'] && isset($wmm_tolka_speed_payload['faster_percent']) && is_numeric($wmm_tolka_speed_payload['faster_percent'])) {
            $wmm_tolka_speed_percent = (float) $wmm_tolka_speed_payload['faster_percent'];
            if (!is_finite($wmm_tolka_speed_percent)) {
                $wmm_tolka_speed_percent = null;
            }
        }

        $wmm_tolka_speed_view['basis'] = $wmm_tolka_speed_finish($wmm_tolka_speed_plain(
            isset($wmm_tolka_speed_payload['basis']) ? $wmm_tolka_speed_payload['basis'] : ''
        ));
        if ('' === $wmm_tolka_speed_view['basis']) {
            $wmm_tolka_speed_view['basis'] = $wmm_tolka_speed_finish($wmm_tolka_speed_basis_fallback);
        }

        $wmm_tolka_speed_loads_ok = ('' !== $wmm_tolka_speed_view['current'] && '' !== $wmm_tolka_speed_view['tolka']);
        $wmm_tolka_speed_faster_ok = ('' !== $wmm_tolka_speed_view['faster']);

        if (!$wmm_tolka_speed_loads_ok) {
            if ('' === $wmm_tolka_speed_view['current']) {
                $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_unavailable;
            }
            if ('' === $wmm_tolka_speed_view['tolka']) {
                $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_unavailable;
            }
            $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_unavailable;
            $wmm_tolka_speed_view['summary'] = __('The speed estimate completed, but one or more figures were missing. No percent is shown.', 'migration-manager');
        } elseif (!$wmm_tolka_speed_faster_ok) {
            $wmm_tolka_speed_view['faster'] = __('No percent is shown.', 'migration-manager');
            $wmm_tolka_speed_view['summary'] = __('The speed estimate completed, but the faster label was empty. No percent is shown.', 'migration-manager');
        } else {
            if (null !== $wmm_tolka_speed_percent) {
                $wmm_tolka_speed_view['faster_percent'] = number_format($wmm_tolka_speed_percent, 1, '.', '');
            }
            $wmm_tolka_speed_view['show_scope'] = true;

            if (null !== $wmm_tolka_speed_percent && $wmm_tolka_speed_percent < 0) {
                $wmm_tolka_speed_view['summary'] = sprintf(
                    /* translators: 1: current load, 2: estimated Tolka load. */
                    __('Tolka is not estimated to be faster on this page. The current load is %1$s, and the estimated Tolka load is %2$s.', 'migration-manager'),
                    $wmm_tolka_speed_view['current'],
                    $wmm_tolka_speed_view['tolka']
                );
            } elseif (null !== $wmm_tolka_speed_percent && $wmm_tolka_speed_percent < 0.05) {
                $wmm_tolka_speed_view['summary'] = sprintf(
                    /* translators: 1: current load, 2: estimated Tolka load. */
                    __('Tolka is estimated to take about the same time on this page. The current load is %1$s, and the estimated Tolka load is %2$s.', 'migration-manager'),
                    $wmm_tolka_speed_view['current'],
                    $wmm_tolka_speed_view['tolka']
                );
            } else {
                $wmm_tolka_speed_view['summary'] = sprintf(
                    /* translators: 1: percent faster label, such as "27.9% faster". 2: current load. 3: estimated Tolka load. */
                    __('Tolka is estimated to be %1$s on this page. The current load is %2$s, and the estimated Tolka load is %3$s.', 'migration-manager'),
                    $wmm_tolka_speed_view['faster'],
                    $wmm_tolka_speed_view['current'],
                    $wmm_tolka_speed_view['tolka']
                );
            }

            if (
                null !== $wmm_tolka_speed_current_number
                && null !== $wmm_tolka_speed_tolka_number
                && (null === $wmm_tolka_speed_percent || $wmm_tolka_speed_percent > 0)
            ) {
                $wmm_tolka_speed_saved = $wmm_tolka_speed_current_number - $wmm_tolka_speed_tolka_number;
                if ($wmm_tolka_speed_saved > 0) {
                    $wmm_tolka_speed_view['savings'] = $wmm_tolka_speed_plain(sprintf(
                        /* translators: %s: time saved, such as "942 ms". */
                        __('The estimated Tolka load is %s shorter than the measured load.', 'migration-manager'),
                        $wmm_tolka_speed_format_ms($wmm_tolka_speed_saved)
                    ));
                    $wmm_tolka_speed_view['saved_ms'] = $wmm_tolka_speed_ms_attr($wmm_tolka_speed_saved);
                }
            }
        }
    } else {
        $wmm_tolka_speed_view['state'] = 'error';
    }
}

if ('error' === $wmm_tolka_speed_view['state']) {
    $wmm_tolka_speed_view['summary'] = '';
    $wmm_tolka_speed_view['current'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['tolka'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['faster'] = $wmm_tolka_speed_unavailable;
    $wmm_tolka_speed_view['faster_percent'] = '';
    $wmm_tolka_speed_view['current_ms'] = '';
    $wmm_tolka_speed_view['tolka_ms'] = '';
    $wmm_tolka_speed_view['savings'] = '';
    $wmm_tolka_speed_view['saved_ms'] = '';
    $wmm_tolka_speed_view['show_scope'] = false;
    $wmm_tolka_speed_view['basis'] = '';
    $wmm_tolka_speed_detail = '';
    if (is_array($wmm_tolka_speed_payload)) {
        $wmm_tolka_speed_detail = $wmm_tolka_speed_plain(
            isset($wmm_tolka_speed_payload['error']) ? $wmm_tolka_speed_payload['error'] : ''
        );
    }
    $wmm_tolka_speed_detail = $wmm_tolka_speed_finish($wmm_tolka_speed_detail);
    if ('' === $wmm_tolka_speed_detail) {
        $wmm_tolka_speed_view['error'] = $wmm_tolka_speed_error_fallback;
    } elseif (preg_match('/the speed estimate could not be completed/i', $wmm_tolka_speed_detail)) {
        $wmm_tolka_speed_view['error'] = $wmm_tolka_speed_detail;
    } else {
        $wmm_tolka_speed_view['error'] = sprintf(
            /* translators: %s: reason the speed estimate failed. */
            __('The speed estimate could not be completed. %s', 'migration-manager'),
            $wmm_tolka_speed_detail
        );
    }
}

if ('empty' === $wmm_tolka_speed_view['state']) {
    $wmm_tolka_speed_view['show_scope'] = true;
    if ('' === $wmm_tolka_speed_view['basis']) {
        $wmm_tolka_speed_view['basis'] = $wmm_tolka_speed_pending;
    }
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

$wmm_tolka_speed_summary_class = 'wmm-tolka-speed-summary';
if ('empty' === $wmm_tolka_speed_view['state']) {
    $wmm_tolka_speed_summary_class .= ' description';
}

$wmm_tolka_speed_described_by = array();
if ($wmm_tolka_speed_view['show_scope']) {
    $wmm_tolka_speed_described_by[] = 'wmm-tolka-speed-scope';
}
if ($wmm_tolka_speed_view['show_note']) {
    $wmm_tolka_speed_described_by[] = 'wmm-tolka-provenance-note';
}
?>

<div
    id="wmm-tolka-speed-panel"
    class="<?php echo esc_attr(implode(' ', $wmm_tolka_speed_classes)); ?>"
    data-state="<?php echo esc_attr($wmm_tolka_speed_view['state']); ?>"
    data-provenance="<?php echo esc_attr($wmm_tolka_speed_view['provenance']); ?>"
    aria-labelledby="wmm-tolka-speed-heading">
    <h3 class="wmm-tolka-speed-heading" id="wmm-tolka-speed-heading"><?php esc_html_e('Estimated speed', 'migration-manager'); ?></h3>

    <div class="wmm-tolka-speed-error<?php echo ('error' === $wmm_tolka_speed_view['state']) ? ' notice notice-error' : ''; ?>"<?php echo ('error' === $wmm_tolka_speed_view['state']) ? ' role="alert"' : ' hidden'; ?>>
        <p id="wmm-tolka-speed-error" data-field="error"><?php echo esc_html($wmm_tolka_speed_view['error']); ?></p>
    </div>

    <div class="<?php echo esc_attr($wmm_tolka_speed_summary_class); ?>" id="wmm-tolka-speed-summary" data-field="summary"<?php echo ('' === $wmm_tolka_speed_view['summary']) ? ' hidden' : ''; ?>>
        <?php echo esc_html($wmm_tolka_speed_view['summary']); ?>
    </div>

    <dl class="wmm-tolka-speed-figures">
        <div class="wmm-tolka-speed-figure">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-faster-label"><?php esc_html_e('Percent faster', 'migration-manager'); ?></dt>
            <dd class="wmm-tolka-speed-value wmm-tolka-value wmm-tolka-speed-faster" id="wmm-tolka-faster" data-wmm-tolka="faster" data-field="faster_label" data-faster-percent="<?php echo esc_attr($wmm_tolka_speed_view['faster_percent']); ?>" aria-labelledby="wmm-tolka-faster-label"<?php echo array() !== $wmm_tolka_speed_described_by ? ' aria-describedby="' . esc_attr(implode(' ', $wmm_tolka_speed_described_by)) . '"' : ''; ?>>
                <?php echo esc_html($wmm_tolka_speed_view['faster']); ?>
            </dd>
        </div>
        <div class="wmm-tolka-speed-figure">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-current-load-label"><?php esc_html_e('Current load', 'migration-manager'); ?></dt>
            <dd class="wmm-tolka-speed-value wmm-tolka-value wmm-tolka-speed-current" id="wmm-tolka-current-load" data-wmm-tolka="current_ms" data-field="current_ms" data-ms="<?php echo esc_attr($wmm_tolka_speed_view['current_ms']); ?>" aria-labelledby="wmm-tolka-current-load-label">
                <?php echo esc_html($wmm_tolka_speed_view['current']); ?>
            </dd>
        </div>
        <div class="wmm-tolka-speed-figure">
            <dt class="wmm-tolka-speed-label" id="wmm-tolka-tolka-load-label"><?php esc_html_e('Estimated Tolka load', 'migration-manager'); ?></dt>
            <dd class="wmm-tolka-speed-value wmm-tolka-value wmm-tolka-speed-tolka" id="wmm-tolka-tolka-load" data-wmm-tolka="tolka_ms" data-field="tolka_ms" data-ms="<?php echo esc_attr($wmm_tolka_speed_view['tolka_ms']); ?>" aria-labelledby="wmm-tolka-tolka-load-label">
                <?php echo esc_html($wmm_tolka_speed_view['tolka']); ?>
            </dd>
        </div>
    </dl>

    <div class="wmm-tolka-provenance wmm-tolka-provenance-note<?php echo $wmm_tolka_speed_view['show_note'] ? ' notice notice-warning' : ''; ?>" data-wmm-tolka="provenance_note"<?php echo $wmm_tolka_speed_view['show_note'] ? '' : ' hidden'; ?>>
        <p class="wmm-tolka-value" id="wmm-tolka-provenance-note" data-field="provenance_note" role="note"<?php echo $wmm_tolka_speed_view['show_note'] ? '' : ' hidden'; ?>><?php echo esc_html($wmm_tolka_speed_view['note']); ?></p>
    </div>

    <p class="wmm-tolka-speed-savings" id="wmm-tolka-speed-savings" data-field="savings" data-saved-ms="<?php echo esc_attr($wmm_tolka_speed_view['saved_ms']); ?>"<?php echo ('' === $wmm_tolka_speed_view['savings']) ? ' hidden' : ''; ?>>
        <?php echo esc_html($wmm_tolka_speed_view['savings']); ?>
    </p>

    <p class="wmm-tolka-speed-scope" id="wmm-tolka-speed-scope" data-field="scope"<?php echo $wmm_tolka_speed_view['show_scope'] ? '' : ' hidden'; ?>>
        <?php echo $wmm_tolka_speed_view['show_scope'] ? esc_html($wmm_tolka_speed_scope_text) : ''; ?>
    </p>

    <?php if ($wmm_tolka_speed_truncated) : ?>
        <p class="wmm-tolka-speed-truncated" id="wmm-tolka-speed-truncated"><?php esc_html_e('Only the first part of the page HTML was measured.', 'migration-manager'); ?></p>
    <?php endif; ?>

    <p class="wmm-tolka-speed-basis-label"<?php echo ('' === $wmm_tolka_speed_view['basis']) ? ' hidden' : ''; ?>><?php esc_html_e('Basis', 'migration-manager'); ?></p>
    <p class="wmm-tolka-speed-basis wmm-tolka-value" id="wmm-tolka-basis" data-wmm-tolka="basis" data-field="basis"<?php echo ('' === $wmm_tolka_speed_view['basis']) ? ' hidden' : ''; ?>>
        <?php echo esc_html($wmm_tolka_speed_view['basis']); ?>
    </p>
</div>

<?php
unset(
    $wmm_tolka_speed_text,
    $wmm_tolka_speed_plain,
    $wmm_tolka_speed_number,
    $wmm_tolka_speed_format_ms,
    $wmm_tolka_speed_ms_attr,
    $wmm_tolka_speed_finish,
    $wmm_tolka_speed_pending,
    $wmm_tolka_speed_unavailable,
    $wmm_tolka_speed_basis_fallback,
    $wmm_tolka_speed_note_fallback,
    $wmm_tolka_speed_scope_text,
    $wmm_tolka_speed_error_fallback,
    $wmm_tolka_speed_view,
    $wmm_tolka_speed_payload,
    $wmm_tolka_speed_ok,
    $wmm_tolka_speed_current_number,
    $wmm_tolka_speed_tolka_number,
    $wmm_tolka_speed_percent,
    $wmm_tolka_speed_loads_ok,
    $wmm_tolka_speed_faster_ok,
    $wmm_tolka_speed_saved,
    $wmm_tolka_speed_detail,
    $wmm_tolka_speed_classes,
    $wmm_tolka_speed_summary_class,
    $wmm_tolka_speed_described_by,
    $wmm_tolka_speed_truncated
);
