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
        $this->assertSame(
            'No measurement yet. Measure the site: enter its public URL and choose Measure speed. You will see the current load, the estimated Tolka load, the percent faster, and the basis of that estimate.',
            $this->text($html, 'wmm-tolka-speed-summary')
        );
        $this->assertSame('Current load', $this->text($html, 'wmm-tolka-current-load-label'));
        $this->assertSame('Estimated Tolka load', $this->text($html, 'wmm-tolka-tolka-load-label'));
        $this->assertSame('Percent faster', $this->text($html, 'wmm-tolka-faster-label'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-faster'));
        $document = $this->document($html);
        foreach (array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        ) as $id) {
            $this->assertNotNull($document->getElementById($id), $id);
        }

        $summary = $document->getElementById('wmm-tolka-speed-summary');
        $this->assertSame('div', strtolower($summary->tagName));
        $this->assertFalse($summary->hasAttribute('data-wmm-tolka-generated'));
        $stamped = preg_replace(
            '/(<p\b[^>]*\bid="wmm-tolka-speed-summary"[^>]*)(>)/',
            '$1 data-wmm-tolka-generated="1"$2',
            $html,
            1
        );
        $this->assertStringNotContainsString('data-wmm-tolka-generated', $stamped);
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

    public function test_before_measurement_tells_the_administrator_to_measure_the_site()
    {
        $html = $this->render(false);
        $document = $this->document($html);
        $ids = array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        );

        $this->assertStringContainsString('Measure the site', $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertStringContainsString('Measure speed', $this->text($html, 'wmm-tolka-speed-summary'));
        foreach ($ids as $id) {
            $this->assertNotNull($document->getElementById($id), $id);
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), $id);
        }
        $this->assertSame('', $document->getElementById('wmm-tolka-current-load')->getAttribute('data-ms'));
        $this->assertSame('', $document->getElementById('wmm-tolka-tolka-load')->getAttribute('data-ms'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertSame('Not measured yet', $this->text($html, 'wmm-tolka-faster'));
    }

    public function test_successful_estimate_shows_the_milliseconds_and_the_faster_label()
    {
        $cases = array(
            'contract label' => array(
                'current_ms' => 1842,
                'tolka_ms' => 900,
                'current_text' => '1,842 ms',
                'tolka_text' => '900 ms',
                'current_attr' => '1842',
                'tolka_attr' => '900',
                'faster_label' => '51.1% faster',
                'faster_percent' => 51.1,
            ),
            'supplied label' => array(
                'current_ms' => 2500,
                'tolka_ms' => 400,
                'current_text' => '2,500 ms',
                'tolka_text' => '400 ms',
                'current_attr' => '2500',
                'tolka_attr' => '400',
                'faster_label' => 'Nearly twice as quick',
                'faster_percent' => 84,
            ),
            'numeric strings' => array(
                'current_ms' => '1842',
                'tolka_ms' => '900',
                'current_text' => '1,842 ms',
                'tolka_text' => '900 ms',
                'current_attr' => '1842',
                'tolka_attr' => '900',
                'faster_label' => '51.1% faster',
                'faster_percent' => '51.1',
            ),
        );

        foreach ($cases as $name => $case) {
            $html = $this->render(true, array(
                'ok' => true,
                'current_ms' => $case['current_ms'],
                'tolka_ms' => $case['tolka_ms'],
                'faster_percent' => $case['faster_percent'],
                'faster_label' => $case['faster_label'],
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'lab',
                'provenance_note' => 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
                'error' => '',
            ));
            $document = $this->document($html);

            foreach (array(
                'wmm-tolka-current-load',
                'wmm-tolka-tolka-load',
                'wmm-tolka-faster',
                'wmm-tolka-basis',
                'wmm-tolka-provenance-note',
            ) as $id) {
                $this->assertNotNull($document->getElementById($id), $name . ' #' . $id);
            }

            $this->assertSame($case['current_attr'], $document->getElementById('wmm-tolka-current-load')->getAttribute('data-ms'), $name);
            $this->assertSame($case['tolka_attr'], $document->getElementById('wmm-tolka-tolka-load')->getAttribute('data-ms'), $name);
            $this->assertStringContainsString($case['current_text'], $this->text($html, 'wmm-tolka-current-load'), $name);
            $this->assertStringContainsString($case['tolka_text'], $this->text($html, 'wmm-tolka-tolka-load'), $name);
            $this->assertSame($case['faster_label'], $this->text($html, 'wmm-tolka-faster'), $name);
            $this->assertStringContainsString($case['faster_label'], $this->text($html, 'wmm-tolka-speed-summary'), $name);
            $this->assertStringNotContainsString('Placeholder coefficients', $html, $name);
        }
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
        $this->assertSame(
            'wmm-tolka-speed-scope wmm-tolka-provenance-note',
            $document->getElementById('wmm-tolka-faster')->getAttribute('aria-describedby')
        );
        $this->assertSame(
            'The estimated Tolka load is 942 ms shorter than the measured load.',
            $this->text($html, 'wmm-tolka-speed-savings')
        );
        $this->assertStringContainsString('single page', $this->text($html, 'wmm-tolka-speed-scope'));
        $this->assertSame('Basis', $this->text_by_class($html, 'wmm-tolka-speed-basis-label'));
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

    public function test_non_placeholder_provenance_does_not_show_the_placeholder_note()
    {
        $contract_note = 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.';
        $provenances = array('', 'lab', 'Placeholder', 'placeholders', 'tolka-lab');

        foreach ($provenances as $provenance) {
            foreach (array(true, false) as $ok) {
                $estimate = $this->ready_estimate();
                $estimate['ok'] = $ok;
                $estimate['provenance'] = $provenance;
                $estimate['provenance_note'] = $contract_note;
                $name = ($ok ? 'ready ' : 'failed ') . ($provenance === '' ? 'empty provenance' : $provenance);

                if (!$ok) {
                    $estimate['current_ms'] = 0;
                    $estimate['tolka_ms'] = 0;
                    $estimate['faster_percent'] = 44.4;
                    $estimate['faster_label'] = '44.4% faster';
                    $estimate['error'] = 'The page could not be loaded.';
                }

                $html = $this->render(true, $estimate);
                $note = $this->document($html)->getElementById('wmm-tolka-provenance-note');

                $this->assertNotNull($note, $name);
                $this->assertTrue($note->hasAttribute('hidden'), $name);
                $this->assertTrue($note->parentNode->hasAttribute('hidden'), $name);
                $this->assertSame('', $this->text($html, 'wmm-tolka-provenance-note'), $name);
                $this->assertStringNotContainsString('Placeholder coefficients', $html, $name);
                $this->assertStringNotContainsString('wmm-tolka-is-placeholder', $html, $name);
                $this->assertStringNotContainsString('notice-warning', $html, $name);
                if ($ok) {
                    $this->assertSame('51.1% faster', $this->text($html, 'wmm-tolka-faster'), $name);
                } else {
                    $this->assertStringNotContainsString('% faster', $html, $name);
                    $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-faster'), $name);
                }
            }
        }
    }

    public function test_speed_ids_stay_unique_on_an_empty_screen_and_after_a_failure()
    {
        $ids = array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        );
        $pages = array(
            'empty' => $this->render(false),
            'failed' => $this->render(true, array(
                'ok' => false,
                'current_ms' => 0,
                'tolka_ms' => 0,
                'faster_percent' => 3,
                'faster_label' => '3.0% faster',
                'provenance' => 'lab',
                'provenance_note' => 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
                'error' => 'The host is not allowed.',
            )),
            'ready placeholder' => $this->render(true, $this->ready_estimate()),
        );

        foreach ($pages as $name => $html) {
            foreach ($ids as $id) {
                $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), $name . ' #' . $id);
            }
        }

        $failed = $pages['failed'];
        $this->assertStringNotContainsString('3.0% faster', $failed);
        $this->assertStringNotContainsString('Placeholder coefficients', $failed);
        $this->assertSame('Unavailable', $this->text($failed, 'wmm-tolka-faster'));
    }

    public function test_failed_estimate_keeps_the_speed_ids_and_shows_no_percent()
    {
        $ids = array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        );
        $cases = array(
            'lab result that includes a percent' => array(
                'ok' => false,
                'current_ms' => 0,
                'tolka_ms' => 0,
                'faster_percent' => 44.4,
                'faster_label' => '44.4% faster',
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'lab',
                'provenance_note' => 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
                'error' => 'The page could not be loaded.',
            ),
            'boolean success flag is required' => array(
                'ok' => 1,
                'current_ms' => 1200,
                'tolka_ms' => 900,
                'faster_percent' => 25,
                'faster_label' => '25.0% faster',
                'provenance' => 'lab',
                'provenance_note' => 'Lab note that must stay hidden.',
                'error' => 'The success flag was not boolean true.',
            ),
            'no provenance and no reason' => array(
                'ok' => false,
                'faster_percent' => 9.5,
                'faster_label' => '9.5% faster',
            ),
        );

        foreach ($cases as $name => $estimate) {
            $html = $this->render(true, $estimate);
            $document = $this->document($html);

            foreach ($ids as $id) {
                $this->assertNotNull($document->getElementById($id), $name . ' keeps #' . $id);
            }

            $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-current-load'), $name);
            $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-tolka-load'), $name);
            $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-faster'), $name);
            $this->assertSame('', $document->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'), $name);
            $this->assertStringNotContainsString('% faster', $html, $name);
            if (isset($estimate['faster_label'])) {
                $this->assertStringNotContainsString($estimate['faster_label'], $html, $name);
            }
            if (isset($estimate['faster_percent'])) {
                $this->assertStringNotContainsString((string) $estimate['faster_percent'], $html, $name);
            }
            $provenance = isset($estimate['provenance']) ? trim((string) $estimate['provenance']) : '';
            if ('placeholder' !== $provenance && !empty($estimate['provenance_note'])) {
                $this->assertStringNotContainsString($estimate['provenance_note'], $html, $name);
                $this->assertTrue(
                    $document->getElementById('wmm-tolka-provenance-note')->parentNode->hasAttribute('hidden'),
                    $name
                );
            }
        }
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

    public function test_placeholder_provenance_always_shows_the_note_and_failure_shows_no_percent()
    {
        $contract_note = 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.';
        $ids = array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        );
        $cases = array(
            'ready with the contract note' => array(
                'ok' => true,
                'current_ms' => 1000,
                'tolka_ms' => 800,
                'faster_percent' => 20,
                'faster_label' => '20.0% faster',
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'placeholder',
                'provenance_note' => $contract_note,
                'error' => '',
            ),
            'ready with a blank note' => array(
                'ok' => true,
                'current_ms' => 1000,
                'tolka_ms' => 800,
                'faster_percent' => 20,
                'faster_label' => '20.0% faster',
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => ' placeholder ',
                'provenance_note' => '   ',
                'error' => '',
            ),
            'failed with a percent in the payload' => array(
                'ok' => false,
                'current_ms' => 0,
                'tolka_ms' => 0,
                'faster_percent' => 88.2,
                'faster_label' => '88.2% faster',
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'placeholder',
                'provenance_note' => $contract_note,
                'error' => 'The host is not allowed.',
            ),
            'failed with an empty note' => array(
                'ok' => false,
                'current_ms' => 0,
                'tolka_ms' => 0,
                'faster_percent' => 12,
                'faster_label' => '12.0% faster',
                'provenance' => 'placeholder',
                'provenance_note' => '',
                'error' => 'Missing coefficient',
            ),
        );

        foreach ($cases as $name => $estimate) {
            $html = $this->render(true, $estimate);
            $document = $this->document($html);

            foreach ($ids as $id) {
                $this->assertNotNull($document->getElementById($id), $name . ' keeps #' . $id);
            }

            $note = $document->getElementById('wmm-tolka-provenance-note');
            $this->assertFalse($note->parentNode->hasAttribute('hidden'), $name);
            $this->assertSame($contract_note, $this->text($html, 'wmm-tolka-provenance-note'), $name);

            if (true !== $estimate['ok']) {
                $this->assertStringNotContainsString('% faster', $html, $name);
                $this->assertStringNotContainsString((string) $estimate['faster_percent'], $html, $name);
                $this->assertSame('', $document->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'), $name);
                $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-faster'), $name);
                $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-current-load'), $name);
                $this->assertSame('Unavailable', $this->text($html, 'wmm-tolka-tolka-load'), $name);
            }
        }
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
            'The speed estimate could not be completed. Check the public site URL and measure again. No percent is shown.',
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

    public function test_successful_placeholder_estimate_escapes_basis_label_and_note()
    {
        $label = '40% <img src=x onerror=alert(1)> faster';
        $basis = 'Basis </p><script>alert(1)</script>';
        $note = 'Placeholder <svg/onload=alert(1)> note';
        $html = $this->render(true, array(
            'ok' => true,
            'current_ms' => 1842,
            'tolka_ms' => 900,
            'faster_percent' => 40,
            'faster_label' => $label,
            'basis' => $basis,
            'provenance' => 'placeholder',
            'provenance_note' => $note,
            'error' => '',
        ));
        $document = $this->document($html);
        $note_node = $document->getElementById('wmm-tolka-provenance-note');

        foreach (array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        ) as $id) {
            $this->assertNotNull($document->getElementById($id), $id);
        }

        $this->assertFalse($note_node->hasAttribute('hidden'));
        $this->assertFalse($note_node->parentNode->hasAttribute('hidden'));
        $this->assertSame($note, $this->text($html, 'wmm-tolka-provenance-note'));
        $this->assertSame($label, $this->text($html, 'wmm-tolka-faster'));
        $this->assertSame($basis . '.', $this->text($html, 'wmm-tolka-basis'));
        $this->assertStringContainsString($label, $this->text($html, 'wmm-tolka-speed-summary'));
        $this->assertStringContainsString('1,842 ms', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertStringContainsString('900 ms', $this->text($html, 'wmm-tolka-tolka-load'));

        foreach (array('<img', '<script', '</script', '<svg') as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw);
        }
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;/p&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;svg', $html);

        $broken = $this->render(true, array(
            'ok' => true,
            'current_ms' => 1000,
            'tolka_ms' => 800,
            'faster_percent' => 20,
            'faster_label' => "<scr\0ipt>alert(1)</script>",
            'basis' => "Load <scr\0ipt>alert(2)</script>",
            'provenance' => 'placeholder',
            'provenance_note' => "Note <scr\0ipt>alert(3)</script>",
            'error' => '',
        ));
        $this->assertSame('<script>alert(1)</script>', $this->text($broken, 'wmm-tolka-faster'));
        $this->assertSame('Load <script>alert(2)</script>.', $this->text($broken, 'wmm-tolka-basis'));
        $this->assertSame('Note <script>alert(3)</script>', $this->text($broken, 'wmm-tolka-provenance-note'));
        $this->assertFalse($this->document($broken)->getElementById('wmm-tolka-provenance-note')->hasAttribute('hidden'));
        $this->assertStringNotContainsString('<script', $broken);
        $this->assertStringNotContainsString("\0", $broken);
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

        $this->assertSame('No percent is shown.', $this->text($html, 'wmm-tolka-faster'));
        $this->assertSame('', $this->document($html)->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'));
        $this->assertStringNotContainsString('% faster', $html);
        $this->assertStringNotContainsString('20.0', $html);
        $this->assertStringContainsString('1,000 ms (1 second)', $this->text($html, 'wmm-tolka-current-load'));
        $this->assertStringContainsString('800 ms', $this->text($html, 'wmm-tolka-tolka-load'));
        $this->assertFalse($this->document($html)->getElementById('wmm-tolka-basis')->hasAttribute('hidden'));
        $this->assertSame(
            'Measured page load plus coefficients from benchmarks.json.',
            $this->text($html, 'wmm-tolka-basis')
        );
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function test_successful_estimate_shows_the_basis_sentence_and_an_empty_label_prints_no_percent()
    {
        $contract_basis = 'Measured page load plus coefficients from benchmarks.json.';
        $ids = array(
            'wmm-tolka-current-load',
            'wmm-tolka-tolka-load',
            'wmm-tolka-faster',
            'wmm-tolka-basis',
            'wmm-tolka-provenance-note',
        );
        $basis_cases = array(
            'given sentence' => array(
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'expected' => $contract_basis,
            ),
            'finished sentence' => array(
                'basis' => 'Lab sample, March.',
                'expected' => 'Lab sample, March.',
            ),
            'missing basis' => array(
                'basis' => '',
                'expected' => $contract_basis,
            ),
        );

        foreach ($basis_cases as $name => $case) {
            $html = $this->render(true, array(
                'ok' => true,
                'current_ms' => 1842,
                'tolka_ms' => 900,
                'faster_percent' => 51.1,
                'faster_label' => '51.1% faster',
                'basis' => $case['basis'],
                'provenance' => 'lab',
                'error' => '',
            ));
            $document = $this->document($html);
            $basis = $document->getElementById('wmm-tolka-basis');

            foreach ($ids as $id) {
                $this->assertNotNull($document->getElementById($id), $name . ' #' . $id);
            }
            $this->assertNotNull($basis, $name);
            $this->assertFalse($basis->hasAttribute('hidden'), $name);
            $this->assertSame($case['expected'], $this->text($html, 'wmm-tolka-basis'), $name);
            $this->assertSame('51.1% faster', $this->text($html, 'wmm-tolka-faster'), $name);
        }

        $blank_labels = array(
            'missing' => array('faster_percent' => 20),
            'empty' => array('faster_percent' => 20, 'faster_label' => ''),
            'whitespace' => array('faster_percent' => 33.3, 'faster_label' => '   '),
        );
        foreach ($blank_labels as $name => $extra) {
            $estimate = array(
                'ok' => true,
                'current_ms' => 1000,
                'tolka_ms' => 800,
                'basis' => 'Measured page load plus coefficients from benchmarks.json',
                'provenance' => 'lab',
                'error' => '',
            );
            $html = $this->render(true, array_merge($estimate, $extra));
            $document = $this->document($html);

            foreach ($ids as $id) {
                $this->assertNotNull($document->getElementById($id), $name . ' #' . $id);
            }
            $this->assertFalse($document->getElementById('wmm-tolka-basis')->hasAttribute('hidden'), $name);
            $this->assertSame($contract_basis, $this->text($html, 'wmm-tolka-basis'), $name);
            $this->assertStringContainsString('1,000 ms', $this->text($html, 'wmm-tolka-current-load'), $name);
            $this->assertStringContainsString('800 ms', $this->text($html, 'wmm-tolka-tolka-load'), $name);
            $this->assertSame('No percent is shown.', $this->text($html, 'wmm-tolka-faster'), $name);
            $this->assertSame('', $document->getElementById('wmm-tolka-faster')->getAttribute('data-faster-percent'), $name);
            $this->assertStringNotContainsString('% faster', $html, $name);
            $this->assertStringNotContainsString('20.0', $html, $name);
            $this->assertStringNotContainsString('33.3', $html, $name);
            $this->assertStringContainsString('No percent is shown', $this->text($html, 'wmm-tolka-speed-summary'), $name);
        }
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

    /**
     * Text of the first element with a class.
     *
     * @param string $html  Markup.
     * @param string $class Class name.
     * @return string
     */
    private function text_by_class($html, $class)
    {
        $xpath = new DOMXPath($this->document($html));
        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]');
        $this->assertGreaterThan(0, $nodes->length, $class);

        return trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
    }
}
