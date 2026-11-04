<?php

/**
 * Markup for the Tolka speed estimate panel.
 *
 * Runs without WordPress. Stubs the escape helpers when the test
 * bootstrap has not loaded them.
 *
 * @package MigrationManager
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2));
}

if (!function_exists('__')) {
    /**
     * Identity stand-in for WordPress translations.
     *
     * @param string $text   Source text.
     * @param string $domain Unused text domain.
     * @return string
     */
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_html_e')) {
    /**
     * Echo an escaped translated string.
     *
     * @param string $text   Source text.
     * @param string $domain Unused text domain.
     */
    function esc_html_e($text, $domain = null)
    {
        echo esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_html')) {
    /**
     * Escape HTML text.
     *
     * @param string $text Raw text.
     * @return string
     */
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape an HTML attribute.
     *
     * @param string $text Raw text.
     * @return string
     */
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

use PHPUnit\Framework\TestCase;

class WMM_Tolka_Speed_Screen_Test extends TestCase
{
    /**
     * Render the speed partial.
     *
     * @param bool  $provide  Whether $estimate is in scope.
     * @param mixed $estimate Optional estimate payload.
     * @return string
     */
    private function render($provide, $estimate = null)
    {
        $view = dirname(__DIR__, 2) . '/includes/tolka/views/speed-estimate.php';
        $render = static function () use ($view, $provide, $estimate) {
            if (!$provide) {
                unset($estimate);
            }

            ob_start();
            include $view;

            return ob_get_clean();
        };

        set_error_handler(static function ($severity, $message) {
            throw new RuntimeException($message);
        });

        try {
            return $render();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Load rendered markup.
     *
     * @param string $html Markup.
     * @return DOMDocument
     */
    private function document($html)
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Visible text of an element.
     *
     * @param string $html Markup.
     * @param string $id   Element id.
     * @return string
     */
    private function text($html, $id)
    {
        $node = $this->document($html)->getElementById($id);
        $this->assertNotNull($node, $id);

        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * A successful placeholder estimate.
     *
     * @return array
     */
    private function ready_estimate()
    {
        return array(
            'ok' => true,
            'current_ms' => 1842,
            'tolka_ms' => 900,
            'faster_percent' => 51.1,
            'faster_label' => '51.1% faster',
            'overhead_ms' => 942,
            'basis' => 'Measured page load plus coefficients from benchmarks.json',
            'provenance' => 'placeholder',
            'provenance_note' => 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
            'coefficients' => array(),
            'error' => '',
        );
    }

    public function test_direct_access_prints_nothing()
    {
        $view = dirname(__DIR__, 2) . '/includes/tolka/views/speed-estimate.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($view) . ' 2>&1';
        $output = array();
        $status = 0;
        exec($command, $output, $status);

        $this->assertSame(0, $status);
        $this->assertSame('', implode("\n", $output));
    }

    public function test_empty_state_names_the_measurement_and_hides_the_result()
    {
        $html = $this->render(false);

        $this->assertStringContainsString('wmm-tolka-is-empty', $html);
        $this->assertStringContainsString('Choose Measure speed', $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertSame('Current load', $this->text($html, 'wmm-tolka-current-load-label'));
        $this->assertSame('Estimated Tolka load', $this->text($html, 'wmm-tolka-tolka-load-label'));
        $this->assertSame('Percent faster', $this->text($html, 'wmm-tolka-faster-label'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-faster'));
        $this->assertStringNotContainsString('Placeholder coefficients', $html);
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-basis'));
        $this->assertFalse($this->document($html)->getElementById('wmm-tolka-basis')->hasAttribute('hidden'));
        $this->assertStringContainsString('single page', $this->text($html, 'wmm-tolka-speed-scope'));
        $this->assertTrue($this->document($html)->getElementById('wmm-tolka-speed-error')->parentNode->hasAttribute('hidden'));
        $this->assertTrue($this->document($html)->getElementById('wmm-tolka-provenance-note')->parentNode->hasAttribute('hidden'));
    }

    public function test_value_slots_can_be_updated_in_place()
    {
        $html = $this->render(false);
        $document = $this->document($html);
        $slots = array(
            'wmm-tolka-current-load' => 'current_ms',
            'wmm-tolka-tolka-load' => 'tolka_ms',
            'wmm-tolka-faster' => 'faster',
            'wmm-tolka-basis' => 'basis',
        );

        foreach ($slots as $id => $name) {
            $node = $document->getElementById($id);
            $this->assertNotNull($node, $id);
            $this->assertSame($name, $node->getAttribute('data-wmm-tolka'));
            $this->assertSame(0, $this->element_child_count($node), $id);
        }

        $note = $document->getElementById('wmm-tolka-provenance-note');
        $this->assertSame('provenance_note', $note->parentNode->getAttribute('data-wmm-tolka'));
        $this->assertStringContainsString('wmm-tolka-provenance', $note->parentNode->getAttribute('class'));
        $this->assertStringContainsString('wmm-tolka-value', $note->getAttribute('class'));
    }

    public function test_empty_array_stays_unmeasured()
    {
        $html = $this->render(true, array());

        $this->assertStringContainsString('data-state="empty"', $html);
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-faster'));
    }

    public function test_ready_placeholder_estimate_sells_the_three_figures_and_the_note()
    {
        $html = $this->render(true, $this->ready_estimate());
        $document = $this->document($html);
        $panel = $document->getElementById('wmm-tolka-speed-panel');

        $this->assertSame('ready', $panel->getAttribute('data-state'));
        $this->assertSame('placeholder', $panel->getAttribute('data-provenance'));
        $this->assertStringContainsString('wmm-tolka-is-placeholder', $panel->getAttribute('class'));
        $this->assertSame(
            'Tolka is estimated to be 51.1% faster on this page. The current load is 1,842 ms (1.84 seconds), and the estimated Tolka load is 900 ms.',
            $this->text($html, 'wmm-tolka-speed-summary')
        );
        $this->assertSame('1,842 ms (1.84 seconds)', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('900 ms', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertSame('51.1% faster', $this->text($html, 'wmm-tolka-faster'));
        $this->assertSame('51.1', $document->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'));
        $this->assertSame('1842', $document->getElementById('wmm-tolka-current-load')->getAttribute('data-ms'));
        $this->assertSame('wmm-tolka-speed-scope', $document->getElementById('wmm-tolka-faster')->getAttribute('aria-describedby'));
        $this->assertSame(
            'The estimated Tolka load is 942 ms shorter than the measured load.',
            $this->text($html, 'wmm-tolka-speed-savings')
        );
        $this->assertStringContainsString('single page', $this->text($html, 'wmm-tolka-speed-scope'));
        $this->assertSame(
            'Measured page load plus coefficients from benchmarks.json.',
            $this->text($html, 'wmm-tolka-basis')
        );
        $this->assertSame(
            'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
            $this->text($html, 'wmm-tolka-provenance-note')
        );
        $this->assertStringContainsString('notice notice-warning', $html);
        $this->assertFalse($document->getElementById('wmm-tolka-provenance-note')->parentNode->hasAttribute('hidden'));
    }

    public function test_lab_provenance_hides_the_placeholder_note()
    {
        $estimate = $this->ready_estimate();
        $estimate['provenance'] = 'lab';
        $estimate['provenance_note'] = 'Should not appear';
        $estimate['basis'] = 'Lab sample, March.';
        $html = $this->render(true, $estimate);

        $this->assertStringNotContainsString('Should not appear', $html);
        $this->assertStringNotContainsString('wmm-tolka-is-placeholder', $html);
        $this->assertTrue($this->document($html)->getElementById('wmm-tolka-provenance-note')->parentNode->hasAttribute('hidden'));
        $this->assertSame('Lab sample, March.', $this->text($html, 'wmm-tolka-basis'));
    }

    public function test_failed_estimate_offers_no_percent()
    {
        $estimate = $this->ready_estimate();
        $estimate['ok'] = false;
        $estimate['current_ms'] = 0;
        $estimate['tolka_ms'] = 0;
        $estimate['faster_percent'] = 0;
        $estimate['faster_label'] = '0.0% faster';
        $estimate['error'] = 'The host is not allowed.';
        $html = $this->render(true, $estimate);
        $document = $this->document($html);

        $this->assertStringNotContainsString('0.0% faster', $html);
        $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-faster'));
        $this->assertSame('', $document->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'));
        $this->assertSame(
            'The speed estimate could not be completed. The host is not allowed.',
            $this->text($html, 'wmm-tolka-speed-error')
        );
        $this->assertSame('alert', $document->getElementById('wmm-tolka-speed-error')->parentNode->getAttribute('role'));
        $this->assertTrue($document->getElementById('wmm-tolka-basis')->hasAttribute('hidden'));
        $this->assertSame('', $this->text($html, 'wmm-tolka-basis'));
        $this->assertStringContainsString('Placeholder coefficients', $this->text($html, 'wmm-tolka-provenance-note'));
    }

    public function test_failed_estimate_finishes_a_fragment_and_does_not_repeat_itself()
    {
        $fragment = $this->render(true, array('ok' => false, 'error' => 'Missing coefficient'));
        $this->assertSame(
            'The speed estimate could not be completed. Missing coefficient.',
            $this->text($fragment, 'wmm-tolka-speed-error')
        );

        $already = $this->render(true, array(
            'ok' => false,
            'error' => 'The speed estimate could not be completed. Try another public URL.',
        ));
        $this->assertSame(
            'The speed estimate could not be completed. Try another public URL.',
            $this->text($already, 'wmm-tolka-speed-error')
        );
    }

    public function test_failed_estimate_without_a_reason_still_explains_the_failure()
    {
        $html = $this->render(true, array('ok' => false));

        $this->assertSame(
            'The speed estimate could not be completed. Check the public site URL and measure again.',
            $this->text($html, 'wmm-tolka-speed-error')
        );
        $this->assertStringNotContainsString('% faster', $html);
    }

    public function test_zero_percent_does_not_pretend_to_be_a_gain()
    {
        $estimate = $this->ready_estimate();
        $estimate['current_ms'] = 800;
        $estimate['tolka_ms'] = 800;
        $estimate['faster_percent'] = 0;
        $estimate['faster_label'] = '0.0% faster';
        $estimate['provenance'] = 'lab';
        $html = $this->render(true, $estimate);

        $this->assertStringContainsString('about the same time', $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertSame('0.0% faster', $this->text($html, 'wmm-tolka-faster'));
        $this->assertTrue($this->document($html)->getElementById('wmm-tolka-speed-savings')->hasAttribute('hidden'));
    }

    public function test_negative_percent_is_not_sold_as_faster()
    {
        $estimate = $this->ready_estimate();
        $estimate['current_ms'] = 400;
        $estimate['tolka_ms'] = 500;
        $estimate['faster_percent'] = -25;
        $estimate['faster_label'] = '-25.0% faster';
        $estimate['provenance'] = 'lab';
        $html = $this->render(true, $estimate);

        $this->assertStringContainsString('not estimated to be faster', $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertTrue($this->document($html)->getElementById('wmm-tolka-speed-savings')->hasAttribute('hidden'));
    }

    public function test_measure_payload_and_missing_label_still_render()
    {
        $html = $this->render(true, array(
            'metrics' => array('language_count' => 2),
            'estimate' => array(
                'ok' => true,
                'current_ms' => 1000,
                'tolka_ms' => 800,
                'faster_percent' => 20,
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'placeholder',
                'provenance_note' => 'Note <b>x</b>',
                'error' => '',
            ),
        ));

        $this->assertSame('20.0% faster', $this->text($html, 'wmm-tolka-faster'));
        $this->assertStringContainsString('1,000 ms (1 second)', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function test_missing_loads_do_not_leave_a_percent_on_screen()
    {
        $html = $this->render(true, array(
            'ok' => true,
            'current_ms' => -5,
            'tolka_ms' => 'nope',
            'faster_label' => '<img src=x onerror=alert(1)>',
            'faster_percent' => 10,
            'basis' => '<script>',
            'provenance' => 'placeholder',
            'provenance_note' => '',
            'error' => '',
        ));

        $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-faster'));
        $this->assertSame('', $this->document($html)->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('No percent is shown', $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertStringContainsString(
            'Placeholder coefficients, not lab measurements.',
            $this->text($html, 'wmm-tolka-provenance-note')
        );
    }

    public function test_whole_seconds_are_not_shown_with_trailing_zeroes()
    {
        $estimate = $this->ready_estimate();
        $estimate['current_ms'] = 2000;
        $estimate['tolka_ms'] = 1500;
        $estimate['faster_percent'] = 25;
        $estimate['faster_label'] = '25.0% faster';
        $estimate['provenance'] = 'lab';
        $html = $this->render(true, $estimate);

        $this->assertSame('2,000 ms (2 seconds)', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('1,500 ms (1.5 seconds)', $this->text($html, 'wmm-tolka-tolka-load'));
    }

    public function test_decimal_milliseconds_keep_their_tenth()
    {
        $estimate = $this->ready_estimate();
        $estimate['current_ms'] = 420.5;
        $estimate['tolka_ms'] = 300;
        $estimate['provenance'] = 'lab';
        $html = $this->render(true, $estimate);

        $this->assertSame('420.5', $this->document($html)->getElementById('wmm-tolka-current-load')->getAttribute('data-ms'));
        $this->assertSame('420.5 ms', $this->text($html, 'wmm-tolka-current-load'));
    }

    public function test_non_array_estimate_fails_closed()
    {
        $html = $this->render(true, '<script>alert(1)</script>');

        $this->assertStringContainsString('could not be completed', $this->text($html, 'wmm-tolka-speed-error'));
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_include_twice_does_not_fatal_or_leak_locals()
    {
        $view = dirname(__DIR__, 2) . '/includes/tolka/views/speed-estimate.php';
        $estimate = array('ok' => false, 'error' => 'Again.');
        ob_start();
        include $view;
        include $view;
        $html = ob_get_clean();

        $this->assertSame(2, substr_count($html, 'Again.'));
        foreach (array_keys(get_defined_vars()) as $name) {
            $this->assertStringStartsNotWith('wmm_tolka_speed_', $name);
        }
    }

    /**
     * Element children, ignoring text nodes.
     *
     * @param DOMNode $node Node.
     * @return int
     */
    private function element_child_count($node)
    {
        $count = 0;
        foreach ($node->childNodes as $child) {
            if (XML_ELEMENT_NODE === $child->nodeType) {
                $count++;
            }
        }

        return $count;
    }

}
