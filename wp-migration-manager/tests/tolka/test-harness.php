<?php

/**
 * Tests for the Tolka PHPUnit harness.
 *
 * WordPress is not loaded. A test that needs a class which is not in this
 * checkout calls wmm_tolka_skip_without_class() and is skipped.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Shared temp-directory cleanup for harness tests.
 */
abstract class Tolka_Test_Case extends TestCase
{
    /**
     * Directories created by the current test.
     *
     * @var string[]
     */
    private $temp_directories = array();

    /**
     * Remove temporary class directories created by a test.
     */
    protected function tearDown(): void
    {
        foreach ($this->temp_directories as $directory) {
            $this->remove_directory($directory);
        }

        $this->temp_directories = array();
        parent::tearDown();
    }

    /**
     * Create a temporary directory the loader can scan.
     *
     * @return string
     */
    protected function make_temp_dir()
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/wmm-tolka-' . uniqid('', true);

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->fail('The test could not create a temporary directory for class files.');
        }

        $this->temp_directories[] = $directory;

        return $directory;
    }

    /**
     * Whether an exception is PHPUnit's skip signal.
     *
     * @param Throwable $error Caught failure.
     * @return bool
     */
    protected function is_skip_exception($error)
    {
        return strpos(get_class($error), 'Skip') !== false;
    }

    /**
     * Delete a temporary directory and anything the test wrote inside it.
     *
     * @param string $directory Directory path.
     */
    private function remove_directory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        @chmod($directory, 0755);
        $items = scandir($directory);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->remove_directory($path);
                continue;
            }

            @chmod($path, 0644);
            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * Call a Tolka class method, or skip when WordPress is not loaded.
     *
     * @param string $class Fully qualified class name.
     * @param string $method Method name.
     * @param array $arguments Arguments.
     * @return mixed
     */
    protected function call_static($class, $method, $arguments)
    {
        try {
            return call_user_func_array(array($class, $method), $arguments);
        } catch (Throwable $error) {
            if ($this->needs_wordpress($error)) {
                $this->markTestSkipped(
                    $class . '::' . $method . ' needs WordPress, so this test was skipped. ' . $error->getMessage()
                );
            }

            throw $error;
        }
    }

    /**
     * Whether a failure is a missing WordPress or PHP API, not a logic bug.
     *
     * @param Throwable $error Caught failure.
     * @return bool
     */
    protected function needs_wordpress($error)
    {
        $message = $error->getMessage();

        return (bool) preg_match('/undefined function|undefined constant/i', $message);
    }

    /**
     * Placeholder coefficients from the migration contract.
     *
     * @return array
     */
    protected function coefficients()
    {
        return array(
            'id' => 'tolka-lab',
            'provenance' => 'placeholder',
            'provenance_note' => 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.',
            'polylang_extra_ms_per_language' => 35,
            'polylang_switcher_ms' => 25,
            'polylang_extra_ms_per_script' => 8,
            'tolka_ms_per_language' => 6,
            'max_overhead_ratio' => 0.7,
        );
    }

    /**
     * The contract formula, kept here so the class under test cannot mark its own homework.
     *
     * @param array $measurement Speed measurement.
     * @param array $metrics Page metrics.
     * @param array $benchmarks Coefficient map.
     * @return array
     */
    protected function expected_projection($measurement, $metrics, $benchmarks)
    {
        $languages = max(1, (int) $metrics['language_count']);
        $raw = $languages * $benchmarks['polylang_extra_ms_per_language'];

        if (!empty($metrics['has_polylang_switcher'])) {
            $raw += $benchmarks['polylang_switcher_ms'];
        }

        $raw += (int) $metrics['script_count'] * $benchmarks['polylang_extra_ms_per_script'];
        $current = max(1, (int) $measurement['total_ms']);
        $overhead = (int) min($raw, (int) floor($current * $benchmarks['max_overhead_ratio']));
        $tolka = (int) max(1, $current - $overhead + min($languages * $benchmarks['tolka_ms_per_language'], $overhead));
        $faster = round(($current - $tolka) / $current * 100, 1);

        return array(
            'current_ms' => (int) $current,
            'tolka_ms' => $tolka,
            'overhead_ms' => $overhead,
            'faster_percent' => $faster,
        );
    }

    /**
     * Compare two millisecond or percent figures.
     *
     * @param float|int $expected Expected number.
     * @param mixed $actual Actual number.
     * @param string $label What is being compared.
     */
    protected function assert_close($expected, $actual, $label)
    {
        $this->assertTrue(
            abs((float) $expected - (float) $actual) < 0.001,
            $label . ' expected ' . $expected . ' and got ' . $actual . '.'
        );
    }

    /**
     * A message a person can read ends as a sentence.
     *
     * @param string $message Message text.
     * @param string $label Case label.
     */
    protected function assert_finished_sentence($message, $label = '')
    {
        $message = trim((string) $message);
        $this->assertNotSame('', $message, $label);
        $last = substr($message, -1);
        $this->assertTrue(
            $last === '.' || $last === '!' || $last === '?',
            $label . ' should end as a sentence: ' . $message
        );
    }
}

/**
 * Contract checks for slices that may not have landed yet.
 *
 * Each test skips, with a finished sentence, when its class file is missing.
 */
trait Tolka_Contract_Tests
{
    /**
     * Hand-checked examples for the overhead cap and the floor at 1ms.
     */
    public function test_reference_formula_matches_worked_examples()
    {
        $benchmarks = $this->coefficients();
        $under = $this->expected_projection(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 2, 'has_polylang_switcher' => false, 'script_count' => 1),
            $benchmarks
        );
        $capped = $this->expected_projection(
            array('ok' => true, 'total_ms' => 100),
            array('language_count' => 8, 'has_polylang_switcher' => true, 'script_count' => 20),
            $benchmarks
        );
        $floor = $this->expected_projection(
            array('ok' => true, 'total_ms' => 0),
            array('language_count' => 0, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );
        $exact = $this->expected_projection(
            array('ok' => true, 'total_ms' => 100),
            array('language_count' => 2, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );
        $tenth = $this->expected_projection(
            array('ok' => true, 'total_ms' => 300),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );
        $negative = $this->expected_projection(
            array('ok' => true, 'total_ms' => -5),
            array('language_count' => -2, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );

        $this->assertSame(78, $under['overhead_ms']);
        $this->assertSame(934, $under['tolka_ms']);
        $this->assert_close(6.6, $under['faster_percent'], 'Under the cap');
        $this->assertSame(70, $capped['overhead_ms']);
        $this->assertSame(78, $capped['tolka_ms']);
        $this->assert_close(22.0, $capped['faster_percent'], 'Overhead cap');
        $this->assertSame(1, $floor['current_ms']);
        $this->assertSame(0, $floor['overhead_ms']);
        $this->assertSame(1, $floor['tolka_ms']);
        $this->assert_close(0.0, $floor['faster_percent'], 'Floor at one millisecond');
        $this->assertSame(70, $exact['overhead_ms']);
        $this->assertSame(42, $exact['tolka_ms']);
        $this->assert_close(58.0, $exact['faster_percent'], 'Overhead exactly at the cap');
        $this->assertSame(35, $tenth['overhead_ms']);
        $this->assertSame(271, $tenth['tolka_ms']);
        $this->assert_close(9.7, $tenth['faster_percent'], 'One decimal place');
        $this->assertSame(1, $negative['current_ms']);
        $this->assertSame(0, $negative['overhead_ms']);
        $this->assertSame(1, $negative['tolka_ms']);
        $this->assert_close(0.0, $negative['faster_percent'], 'Negative inputs');
    }

    /**
     * Speed_Estimate::project follows the contract formula, including the cap.
     */
    public function test_estimate_matches_the_contract_formula()
    {
        wmm_tolka_skip_without_class('Speed_Estimate');

        $benchmarks = $this->coefficients();
        $cases = array(
            'under the cap' => array(
                'measurement' => array('ok' => true, 'total_ms' => 1000),
                'metrics' => array('language_count' => 2, 'has_polylang_switcher' => false, 'script_count' => 1),
            ),
            'overhead cap' => array(
                'measurement' => array('ok' => true, 'total_ms' => 100),
                'metrics' => array('language_count' => 8, 'has_polylang_switcher' => true, 'script_count' => 20),
            ),
            'no languages and no time' => array(
                'measurement' => array('ok' => true, 'total_ms' => 0),
                'metrics' => array('language_count' => 0, 'has_polylang_switcher' => false, 'script_count' => 0),
            ),
            'overhead exactly at the cap' => array(
                'measurement' => array('ok' => true, 'total_ms' => 100),
                'metrics' => array('language_count' => 2, 'has_polylang_switcher' => false, 'script_count' => 0),
            ),
            'one decimal place' => array(
                'measurement' => array('ok' => true, 'total_ms' => 300),
                'metrics' => array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            ),
            'negative inputs' => array(
                'measurement' => array('ok' => true, 'total_ms' => -5),
                'metrics' => array('language_count' => -2, 'has_polylang_switcher' => false, 'script_count' => 0),
            ),
        );

        foreach ($cases as $label => $case) {
            $expected = $this->expected_projection($case['measurement'], $case['metrics'], $benchmarks);
            $result = $this->call_static(Speed_Estimate::class, 'project', array($case['measurement'], $case['metrics'], $benchmarks));

            $this->assertTrue(is_array($result), $label);
            $this->assertTrue($result['ok'], $label);
            $this->assertSame($expected['current_ms'], (int) $result['current_ms'], $label);
            $this->assertSame($expected['tolka_ms'], (int) $result['tolka_ms'], $label);
            $this->assertSame($expected['overhead_ms'], (int) $result['overhead_ms'], $label);
            $this->assert_whole_milliseconds($result['current_ms'], $label . ' current');
            $this->assert_whole_milliseconds($result['tolka_ms'], $label . ' tolka');
            $this->assert_whole_milliseconds($result['overhead_ms'], $label . ' overhead');
            $this->assert_close($expected['faster_percent'], $result['faster_percent'], $label);
            $this->assertTrue(is_array($result['coefficients']), $label);
            foreach (array('polylang_extra_ms_per_language', 'polylang_switcher_ms', 'polylang_extra_ms_per_script', 'tolka_ms_per_language', 'max_overhead_ratio') as $key) {
                $this->assertEquals($benchmarks[$key], $result['coefficients'][$key], $label . ' ' . $key);
            }
            $this->assertSame('Measured page load plus coefficients from benchmarks.json', $result['basis'], $label);
            $this->assertSame('placeholder', $result['provenance'], $label);
            $this->assertSame($benchmarks['provenance_note'], $result['provenance_note'], $label);
            $this->assertStringContainsString('% faster', $result['faster_label'], $label);
            $this->assert_close($result['faster_percent'], str_replace('% faster', '', $result['faster_label']), $label . ' label');
        }
    }

    /**
     * A failed measurement must not invent a percent.
     */
    public function test_estimate_does_not_invent_a_percent_when_measurement_failed()
    {
        wmm_tolka_skip_without_class('Speed_Estimate');

        $result = $this->call_static(
            Speed_Estimate::class,
            'project',
            array(
                array('ok' => false, 'total_ms' => 800, 'html' => '<html></html>', 'error' => 'The page did not load.'),
                array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
                $this->coefficients(),
            )
        );

        $this->assert_failed_estimate($result);
    }

    /**
     * A missing coefficient must not invent a percent.
     */
    public function test_estimate_does_not_invent_a_percent_when_a_coefficient_is_missing()
    {
        wmm_tolka_skip_without_class('Speed_Estimate');

        $required = array(
            'polylang_extra_ms_per_language',
            'polylang_switcher_ms',
            'polylang_extra_ms_per_script',
            'tolka_ms_per_language',
            'max_overhead_ratio',
        );

        foreach ($required as $key) {
            $benchmarks = $this->coefficients();
            unset($benchmarks[$key]);
            $result = $this->call_static(
                Speed_Estimate::class,
                'project',
                array(
                    array('ok' => true, 'total_ms' => 500),
                    array('language_count' => 2, 'has_polylang_switcher' => true, 'script_count' => 1),
                    $benchmarks,
                )
            );
            $this->assert_failed_estimate($result, $key);
        }
    }

    /**
     * benchmarks.json is the coefficient source when the estimate slice has added it.
     */
    public function test_estimate_benchmarks_match_the_json_file()
    {
        wmm_tolka_skip_without_class('Speed_Estimate');

        $path = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka/data/benchmarks.json';

        if (!is_file($path)) {
            $this->markTestSkipped('benchmarks.json is not in this checkout, so this test was skipped.');
        }

        $decoded = json_decode(file_get_contents($path), true);
        $loaded = $this->call_static(Speed_Estimate::class, 'benchmarks', array());

        $this->assertTrue(is_array($decoded));
        $this->assertTrue(is_array($loaded));
        $this->assertSame($this->coefficients(), $decoded);
        $this->assertSame($decoded, $loaded);
    }

    /**
     * Metrics count hreflang, assets, and the Polylang switcher.
     */
    public function test_metrics_count_languages_assets_and_switcher()
    {
        wmm_tolka_skip_without_class('Speed_Metrics');

        $html = '<!DOCTYPE html><html><head>'
            . '<link rel="stylesheet" href="/a.css">'
            . '<link rel="stylesheet" href="/b.css">'
            . '<link href="/c.css" rel="stylesheet">'
            . '<link rel="alternate" hreflang="en" href="https://example.com/en">'
            . '<link rel="alternate" hreflang="sv" href="https://example.com/sv">'
            . '<link rel="alternate" hreflang="de" href="https://example.com/de">'
            . '<link rel="alternate" hreflang="en" href="https://example.com/en/page">'
            . '<link rel="alternate" hreflang="x-default" href="https://example.com/">'
            . '</head><body>'
            . '<script src="/a.js"></script>'
            . '<script>var x = 1;</script>'
            . '<img src="/a.png">'
            . '<img src="/b.png" alt="">'
            . '<img src="/c.png">'
            . '<div class="pll-switcher">Languages</div>'
            . '</body></html>';

        $metrics = $this->analyze_html($html);

        $this->assertSame(3, (int) $metrics['hreflang_count']);
        $this->assertSame(3, (int) $metrics['language_count']);
        $this->assertSame(2, (int) $metrics['script_count']);
        $this->assertSame(3, (int) $metrics['stylesheet_count']);
        $this->assertSame(3, (int) $metrics['image_count']);
        $this->assertTrue((bool) $metrics['has_polylang_switcher']);
        $this->assertTrue(is_bool($metrics['truncated']));
    }

    /**
     * Without hreflang, a switcher means two languages and a plain page means one.
     */
    public function test_metrics_language_count_falls_back_when_hreflang_is_absent()
    {
        wmm_tolka_skip_without_class('Speed_Metrics');

        $plain = $this->analyze_html('<html><body><p>Hello</p></body></html>');
        $this->assertSame(0, (int) $plain['hreflang_count']);
        $this->assertSame(1, (int) $plain['language_count']);
        $this->assertFalse((bool) $plain['has_polylang_switcher']);

        $only_default = $this->analyze_html('<link rel="alternate" hreflang="x-default" href="https://example.com/">');
        $this->assertSame(0, (int) $only_default['hreflang_count']);
        $this->assertSame(1, (int) $only_default['language_count']);
        $this->assertFalse((bool) $only_default['has_polylang_switcher']);

        $tokens = array('POLYLANG', 'PLL-Switcher', 'Lang-Item', 'pll-parent-menu-item');

        foreach ($tokens as $token) {
            $metrics = $this->analyze_html('<html><body><div>' . $token . '</div></body></html>');
            $this->assertSame(2, (int) $metrics['language_count'], $token);
            $this->assertTrue((bool) $metrics['has_polylang_switcher'], $token);
        }
    }

    /**
     * The package keeps items only, and missing sections are empty lists.
     */
    public function test_schema_package_uses_empty_items_for_missing_sections()
    {
        wmm_tolka_skip_without_class('Tolka_Schema');

        $package = $this->call_static(
            Tolka_Schema::class,
            'package',
            array(
                array(
                    'languages' => array(
                        'items' => array(
                            array(
                                'slug' => 'en',
                                'name' => 'English',
                                'locale' => 'en_US',
                                'is_default' => true,
                                'term_id' => 3,
                                'flag' => 'us',
                            ),
                        ),
                        'errors' => array(),
                    ),
                    'posts' => array(
                        'items' => array(),
                        'errors' => array('No post translations were found.'),
                    ),
                ),
            )
        );

        $this->assertSame('tolka-migration', $package['format']);
        $this->assertEquals(1, $package['version']);
        $this->assertSame('en', $package['languages'][0]['slug']);
        $this->assertSame(array(), $package['posts']);

        foreach (array('terms', 'strings', 'menus', 'media') as $section) {
            $this->assertArrayHasKey($section, $package);
            $this->assertSame(array(), $package[$section]);
        }

        $this->assertTrue(
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/', $package['generated_at']),
            $package['generated_at']
        );
        $stamp = strtotime($package['generated_at']);
        $this->assertTrue($stamp !== false && abs($stamp - time()) < 172800);
        $this->assertStringNotContainsString('No post translations were found.', json_encode($package));
    }

    /**
     * Private, local, and non-HTTP URLs fail closed and do not return HTML.
     */
    public function test_loader_rejects_urls_that_are_not_public_http()
    {
        wmm_tolka_skip_without_class('Speed_Loader');

        $urls = array(
            '',
            '   ',
            '/wp-admin',
            'ftp://example.com/file',
            'file:///etc/passwd',
            'http://localhost/',
            'http://localhost:8080/path',
            'HTTP://LOCALHOST/',
            'http://127.0.0.1/',
            'https://127.0.0.2/admin',
            'http://10.1.2.3/',
            'http://172.16.0.1/',
            'http://172.31.255.255/',
            'http://192.168.1.20/page',
            'http://169.254.1.1/',
            'http://[::1]/',
            'http://',
            'https://',
            'http:///missing-host',
            'javascript:alert(1)',
            'http://10.0.0.0/',
            'http://10.255.255.255/',
            'http://172.16.0.0/',
            'http://192.168.0.0/',
            'http://127.255.255.255/',
            'http://169.254.255.254/',
            'http://user:secret@127.0.0.1/path',
        );

        foreach ($urls as $url) {
            $result = $this->call_static(Speed_Loader::class, 'measure', array($url));
            $this->assertTrue(is_array($result), $url);
            $this->assertFalse($result['ok'], $url);
            $this->assertSame('', $result['html'], $url);
            $this->assertNull($result['ttfb_ms'], $url);
            $this->assertTrue(is_string($result['error']) && $result['error'] !== '', $url);
            $this->assert_finished_sentence($result['error'], $url);
        }
    }

    /**
     * With Polylang absent, detection is inactive and does not invent a version.
     */
    public function test_detector_reports_an_inactive_site_without_wordpress()
    {
        wmm_tolka_skip_without_class('Polylang_Detector');

        if (function_exists('pll_languages_list') || class_exists('PLL', false) || class_exists('Polylang', false)) {
            $this->markTestSkipped('Polylang is present in this process, so the inactive-site check was skipped.');
        }

        $status = $this->call_static(Polylang_Detector::class, 'status', array());

        $this->assertFalse($status['active']);
        $this->assertSame('', $status['version']);
        $this->assertTrue(is_array($status['errors']));

        foreach ($status['errors'] as $error) {
            $this->assertTrue(is_string($error));
            if ($error !== '') {
                $this->assert_finished_sentence($error, 'detector');
            }
        }
    }

    /**
     * Each exporter returns items and errors, and does not throw when Polylang is missing.
     */
    public function test_exporters_return_envelopes_without_throwing()
    {
        $classes = array(
            'Polylang_Languages',
            'Polylang_Posts',
            'Polylang_Terms',
            'Polylang_Strings',
            'Polylang_Menus',
            'Polylang_Media',
        );
        $seen = false;

        foreach ($classes as $short) {
            if (!class_exists('WMM\\Tolka\\' . $short, false)) {
                continue;
            }

            $seen = true;
            $result = $this->call_static('WMM\\Tolka\\' . $short, 'export', array());
            $this->assertTrue(is_array($result['items']), $short);
            $this->assertTrue(is_array($result['errors']), $short);

            if (count($result['items']) === 0) {
                $this->assertNotEmpty($result['errors'], $short);
            }

            foreach ($result['errors'] as $error) {
                $this->assertTrue(is_string($error) && $error !== '', $short);
                $this->assert_finished_sentence($error, $short);
            }

            if (count($result['items']) > 0) {
                $this->assert_export_items($short, $result['items']);
            }
        }

        if (!$seen) {
            wmm_tolka_skip_without_class($classes);
        }
    }

    /**
     * Collect returns exporter envelopes and can list classes that have not landed.
     */
    public function test_runner_collect_returns_envelopes_or_lists_what_is_missing()
    {
        wmm_tolka_skip_without_class('Migration_Runner');

        $collected = $this->call_static(Migration_Runner::class, 'collect', array());

        $this->assertTrue(is_array($collected));

        foreach (array('languages', 'posts', 'terms', 'strings', 'menus', 'media') as $section) {
            if (!isset($collected[$section]) || !is_array($collected[$section])) {
                continue;
            }

            $this->assertArrayHasKey('items', $collected[$section], $section);
            $this->assertArrayHasKey('errors', $collected[$section], $section);
        }

        if (isset($collected['missing'])) {
            $this->assertTrue(is_array($collected['missing']));

            foreach ($collected['missing'] as $name) {
                $this->assertTrue(is_string($name) && $name !== '');
            }
        }
    }

    /**
     * Preview samples stay small, and missing slices are listed rather than fatal.
     */
    public function test_runner_preview_keeps_samples_small()
    {
        wmm_tolka_skip_without_class('Migration_Runner');

        $preview = $this->call_static(Migration_Runner::class, 'preview', array());

        $this->assertArrayHasKey('counts', $preview);
        $this->assert_section_counts($preview['counts'], 'preview');
        $this->assertArrayHasKey('sample', $preview);
        $this->assertArrayHasKey('errors', $preview);
        $this->assertTrue(is_array($preview['errors']));
        $this->assertTrue(is_array($preview['missing']));

        foreach ($preview['missing'] as $name) {
            $this->assertTrue(is_string($name) && $name !== '');
        }

        $this->assertTrue(is_array($preview['sample']));

        foreach ($preview['sample'] as $section => $items) {
            $this->assertTrue(is_array($items), $section);
            $this->assertLessThanOrEqual(20, count($items), $section);
        }
    }

    /**
     * A run reports ok, counts, the writer result, errors, and missing classes.
     */
    public function test_runner_run_reports_the_write()
    {
        wmm_tolka_skip_without_class('Migration_Runner');

        $run = $this->call_static(Migration_Runner::class, 'run', array());

        foreach (array('ok', 'counts', 'write', 'errors', 'missing') as $key) {
            $this->assertArrayHasKey($key, $run);
        }

        $this->assertTrue(is_bool($run['ok']));
        $this->assertTrue(is_array($run['errors']));
        $this->assertTrue(is_array($run['missing']));
        $this->assert_section_counts($run['counts'], 'run');
    }

    /**
     * The writer does not pretend it stored a package when WordPress is absent.
     */
    public function test_writer_reports_a_failure_without_wordpress()
    {
        wmm_tolka_skip_without_class('Tolka_Writer');

        $package = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'languages' => array(),
            'posts' => array(),
            'terms' => array(),
            'strings' => array(),
            'menus' => array(),
            'media' => array(),
        );
        $result = $this->call_static(Tolka_Writer::class, 'write', array($package));

        $this->assertTrue(is_bool($result['ok']));
        $this->assertTrue(is_array($result['errors']));

        if (isset($result['counts']) && is_array($result['counts'])) {
            $this->assert_section_counts($result['counts'], 'writer');
        } else {
            $this->assert_section_counts($result, 'writer');
        }

        if (!function_exists('update_option')) {
            $this->assertFalse($result['ok']);
            $this->assertNotEmpty($result['errors']);
        }

        foreach ($result['errors'] as $error) {
            $this->assertTrue(is_string($error) && $error !== '');
            $this->assert_finished_sentence($error, 'writer');
        }
    }

    /**
     * The report is something an admin can read: a title, a summary, and labeled lines.
     */
    public function test_report_uses_finished_sentences()
    {
        wmm_tolka_skip_without_class('Migration_Report');

        $report = $this->call_static(
            Migration_Report::class,
            'format',
            array(
                array(
                    'ok' => false,
                    'counts' => array(
                        'languages' => 0,
                        'posts' => 0,
                        'terms' => 0,
                        'strings' => 0,
                        'menus' => 0,
                        'media' => 0,
                    ),
                    'errors' => array('Polylang is not active on this site.'),
                    'missing' => array('Tolka_Writer'),
                    'write' => null,
                ),
            )
        );

        $this->assertTrue(is_bool($report['ok']));
        $this->assertTrue(is_string($report['title']) && trim($report['title']) !== '');
        $this->assertTrue(is_string($report['summary']) && trim($report['summary']) !== '');
        $this->assert_finished_sentence($report['title'], 'title');
        $this->assert_finished_sentence($report['summary'], 'summary');
        $this->assertTrue(is_array($report['lines']));

        foreach ($report['lines'] as $line) {
            $this->assertTrue(is_string($line['label']) && $line['label'] !== '');
            $this->assertTrue(is_string($line['value']) || is_numeric($line['value']));
        }
    }

    /**
     * A failed projection keeps every time at zero and explains why.
     *
     * @param array $result Projection.
     * @param string $label Case label.
     */
    private function assert_failed_estimate($result, $label = '')
    {
        $this->assertTrue(is_array($result), $label);
        $this->assertFalse($result['ok'], $label);
        $this->assertSame(0, (int) $result['current_ms'], $label);
        $this->assertSame(0, (int) $result['tolka_ms'], $label);
        $this->assertSame(0, (int) $result['overhead_ms'], $label);
        $this->assert_close(0, $result['faster_percent'], $label);
        $this->assert_whole_milliseconds($result['current_ms'], $label);
        $this->assert_whole_milliseconds($result['tolka_ms'], $label);
        $this->assert_whole_milliseconds($result['overhead_ms'], $label);
        $this->assertTrue(is_string($result['error']) && $result['error'] !== '', $label);
        $this->assert_finished_sentence($result['error'], $label);

        if (isset($result['faster_label'])) {
            $this->assert_close(0, str_replace('% faster', '', $result['faster_label']), $label . ' label');
        }
    }

    /**
     * Millisecond fields are whole numbers.
     *
     * @param mixed $value Reported time.
     * @param string $label Case label.
     */
    private function assert_whole_milliseconds($value, $label)
    {
        $this->assertTrue(is_numeric($value), $label);
        $this->assert_close((int) $value, $value, $label);
    }

    /**
     * Counts for languages, posts, terms, strings, menus, and media.
     *
     * @param array $counts Section counts.
     * @param string $label Case label.
     */
    private function assert_section_counts($counts, $label)
    {
        $this->assertTrue(is_array($counts), $label);

        foreach (array('languages', 'posts', 'terms', 'strings', 'menus', 'media') as $section) {
            $this->assertArrayHasKey($section, $counts, $label . ' ' . $section);
            $this->assertTrue(is_numeric($counts[$section]), $label . ' ' . $section);
            $this->assertGreaterThanOrEqual(0, $counts[$section], $label . ' ' . $section);
        }
    }

    /**
     * Item fields from the contract, checked only when an exporter returned rows.
     *
     * @param string $short Exporter class short name.
     * @param array $items Exported items.
     */
    private function assert_export_items($short, $items)
    {
        foreach ($items as $item) {
            $this->assertTrue(is_array($item), $short);

            if ($short === 'Polylang_Languages') {
                foreach (array('slug', 'name', 'locale', 'flag') as $key) {
                    $this->assertTrue(isset($item[$key]) && is_string($item[$key]), $short . ' ' . $key);
                }
                $this->assertTrue(is_bool($item['is_default']), $short);
                $this->assertTrue(is_numeric($item['term_id']), $short);
                continue;
            }

            if ($short === 'Polylang_Posts') {
                $this->assertTrue((bool) preg_match('/^post:\d+$/', (string) $item['group']), $short);
                $this->assertTrue(is_string($item['type']), $short);
                $this->assert_translation_map($item, $short);
                continue;
            }

            if ($short === 'Polylang_Terms') {
                $this->assertTrue(is_string($item['taxonomy']), $short);
                $this->assertTrue((bool) preg_match('/^term:\d+$/', (string) $item['group']), $short);
                $this->assert_translation_map($item, $short);
                continue;
            }

            if ($short === 'Polylang_Strings') {
                foreach (array('name', 'string', 'context') as $key) {
                    $this->assertTrue(isset($item[$key]) && is_string($item[$key]), $short . ' ' . $key);
                }
                $this->assertTrue(is_array($item['translations']), $short);
                foreach ($item['translations'] as $translated) {
                    $this->assertTrue(is_string($translated), $short);
                }
                continue;
            }

            if ($short === 'Polylang_Menus') {
                $this->assertTrue(isset($item['location']) && is_string($item['location']), $short);
                $this->assert_menu_items($item, $short);
                continue;
            }

            if ($short === 'Polylang_Media') {
                $this->assertTrue(is_numeric($item['attachment_id']), $short);
                $this->assertTrue(is_string($item['file']), $short);
                $this->assertTrue(is_array($item['alt']), $short);
                $this->assertTrue(is_array($item['title']), $short);
                foreach (array('alt', 'title') as $map) {
                    foreach ($item[$map] as $value) {
                        $this->assertTrue(is_string($value), $short);
                    }
                }
            }
        }
    }

    /**
     * A translation map is either ids or records that carry an id.
     *
     * @param array $item Exported item.
     * @param string $short Exporter class short name.
     */
    private function assert_translation_map($item, $short)
    {
        $this->assertTrue(is_array($item['translations']), $short);

        foreach ($item['translations'] as $value) {
            if (is_array($value)) {
                $this->assertTrue(isset($value['id']) && is_numeric($value['id']), $short);
                continue;
            }

            $this->assertTrue(is_numeric($value), $short);
        }
    }

    /**
     * Menu rows carry title, url, object, and object id.
     *
     * @param array $item Exported menu.
     * @param string $short Exporter class short name.
     */
    private function assert_menu_items($item, $short)
    {
        $lists = array();

        if (isset($item['items']) && is_array($item['items'])) {
            $lists[] = $item['items'];
        }

        if (isset($item['translations']) && is_array($item['translations'])) {
            foreach ($item['translations'] as $value) {
                if (is_array($value) && isset($value['items']) && is_array($value['items'])) {
                    $lists[] = $value['items'];
                }
            }
        }

        foreach ($lists as $list) {
            foreach ($list as $child) {
                $this->assertTrue(is_array($child), $short);
                $this->assertTrue(isset($child['title']) && is_string($child['title']), $short);
                $this->assertTrue(isset($child['url']) && is_string($child['url']), $short);
                $this->assertTrue(isset($child['object']) && is_string($child['object']), $short);
                $this->assertTrue(isset($child['object_id']) && is_numeric($child['object_id']), $short);
            }
        }
    }

    /**
     * Analyze one HTML document with the metrics class.
     *
     * @param string $html Page HTML.
     * @return array
     */
    private function analyze_html($html)
    {
        return $this->call_static(
            Speed_Metrics::class,
            'analyze',
            array(
                array(
                    'ok' => true,
                    'html' => $html,
                    'html_bytes' => strlen($html),
                ),
            )
        );
    }
}

/**
 * Proves the suite loads class files, skips missing slices, and stays free
 * of a WordPress install.
 */
class Harness_Test extends Tolka_Test_Case
{
    use Tolka_Contract_Tests;

    /**
     * WordPress function names the harness must not define.
     *
     * @return string[]
     */
    private function wordpress_functions()
    {
        return array(
            'add_action',
            'add_filter',
            'wp_remote_get',
            'wp_remote_post',
            'get_option',
            'update_option',
            'home_url',
            'wp_insert_post',
            'wp_send_json_success',
            'plugin_dir_path',
        );
    }

    /**
     * The suite must not boot WordPress or pretend Polylang is installed.
     */
    public function test_wordpress_is_not_loaded()
    {
        $this->assertTrue(defined('ABSPATH'));
        $this->assertTrue(defined('WMM_TOLKA_TEST_BOOTSTRAP'));
        $this->assertFalse(is_file(ABSPATH . 'wp-load.php'));
        $this->assertFalse(is_file(ABSPATH . 'wp-settings.php'));
        $this->assertFalse($this->included_wordpress_bootstrap());

        foreach ($this->wordpress_functions() as $function) {
            $this->assert_not_defined_by_bootstrap($function);
        }

        $this->assertFalse(function_exists('pll_languages_list'));
        $this->assertFalse(class_exists('PLL', false));
        $this->assertFalse(class_exists('Polylang', false));
    }

    /**
     * The bootstrap source does not include WordPress or declare its functions.
     */
    public function test_bootstrap_source_does_not_load_wordpress()
    {
        $source = file_get_contents(WMM_TOLKA_TEST_DIR . '/bootstrap.php');

        $this->assertTrue(is_string($source));
        $this->assertStringContainsString("if (!defined('ABSPATH'))", $source);
        $this->assertFalse(
            (bool) preg_match('/\b(require|include)(_once)?\b[^;]*wp-load\.php/', $source),
            'The bootstrap must not load wp-load.php.'
        );
        $this->assertFalse(
            (bool) preg_match('/\b(require|include)(_once)?\b[^;]*wp-settings\.php/', $source),
            'The bootstrap must not load wp-settings.php.'
        );
        $this->assertFalse(
            (bool) preg_match('/function\s+wp_remote_get\s*\(/', $source),
            'The bootstrap must not define wp_remote_get.'
        );
        $this->assertFalse((bool) preg_match('/function\s+add_action\s*\(/', $source));
        $this->assertFalse((bool) preg_match('/function\s+get_option\s*\(/', $source));
        $this->assertFalse((bool) preg_match('/function\s+home_url\s*\(/', $source));
    }

    /**
     * An ABSPATH that already exists is left alone, and WordPress stays out.
     */
    public function test_bootstrap_defines_abspath_only_when_it_is_missing()
    {
        $sentinel = '/wmm-tolka-abspath-sentinel-' . getmypid() . '/';
        $probe = $this->wordpress_probe('define(\'ABSPATH\', ' . var_export($sentinel, true) . ');');

        $this->assertSame(0, $probe['status'], $probe['output']);
        $this->assertSame($sentinel, $probe['abspath']);
        $this->assertSame('', $probe['bootstrap_functions']);
        $this->assertFalse($probe['loaded']);
    }

    /**
     * A fresh process gets a plugin ABSPATH and still does not boot WordPress.
     */
    public function test_fresh_bootstrap_sets_abspath_without_wordpress()
    {
        $probe = $this->wordpress_probe('');

        $this->assertSame(0, $probe['status'], $probe['output']);
        $this->assertSame(WMM_TOLKA_PLUGIN_DIR . '/', $probe['abspath']);
        $this->assertSame('', $probe['bootstrap_functions']);
        $this->assertFalse($probe['loaded']);
        $this->assertFalse(is_file($probe['abspath'] . 'wp-load.php'));
    }

    /**
     * Loading the bootstrap again must not replace ABSPATH or add WordPress APIs.
     */
    public function test_loading_the_bootstrap_twice_does_not_define_wordpress()
    {
        $before = ABSPATH;
        require WMM_TOLKA_TEST_DIR . '/bootstrap.php';
        $this->assertSame($before, ABSPATH);
        $this->assertFalse($this->included_wordpress_bootstrap());

        foreach ($this->wordpress_functions() as $function) {
            $this->assert_not_defined_by_bootstrap($function);
        }
    }

    /**
     * A WordPress API must not be declared by the test bootstrap.
     *
     * @param string $function Function name.
     */
    private function assert_not_defined_by_bootstrap($function)
    {
        if (!function_exists($function)) {
            $this->assertFalse(function_exists($function), $function);
            return;
        }

        $file = str_replace('\\', '/', (string) (new \ReflectionFunction($function))->getFileName());
        $bootstrap = 'tests/tolka/bootstrap.php';
        $this->assertFalse(
            substr($file, -strlen($bootstrap)) === $bootstrap,
            $function . ' must not be defined by the Tolka test bootstrap.'
        );
    }

    /**
     * Whether this process included the WordPress bootstrap.
     *
     * @return bool
     */
    private function included_wordpress_bootstrap()
    {
        foreach (get_included_files() as $file) {
            $basename = basename($file);

            if ($basename === 'wp-load.php' || $basename === 'wp-settings.php') {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the bootstrap in a clean process and report WordPress leakage.
     *
     * @param string $preamble PHP statements to run before the bootstrap.
     * @return array
     */
    private function wordpress_probe($preamble)
    {
        $runner = $this->make_temp_dir() . '/runner.php';
        $functions = var_export($this->wordpress_functions(), true);
        $script = "<?php\n"
            . $preamble . "\n"
            . 'require ' . var_export(WMM_TOLKA_TEST_DIR . '/bootstrap.php', true) . ";\n"
            . '$from_bootstrap = array();' . "\n"
            . 'foreach (' . $functions . ' as $function) {' . "\n"
            . '    if (!function_exists($function)) {' . "\n"
            . '        continue;' . "\n"
            . '    }' . "\n"
            . '    $file = str_replace(\'\\\\\', \'/\', (string) (new ReflectionFunction($function))->getFileName());' . "\n"
            . '    if (substr($file, -strlen(\'tests/tolka/bootstrap.php\')) === \'tests/tolka/bootstrap.php\') {' . "\n"
            . '        $from_bootstrap[] = $function;' . "\n"
            . '    }' . "\n"
            . '}' . "\n"
            . '$loaded = false;' . "\n"
            . 'foreach (get_included_files() as $file) {' . "\n"
            . '    $basename = basename($file);' . "\n"
            . '    if ($basename === \'wp-load.php\' || $basename === \'wp-settings.php\') {' . "\n"
            . '        $loaded = true;' . "\n"
            . '    }' . "\n"
            . '}' . "\n"
            . 'echo \'ABSPATH=\' . ABSPATH . "\n";' . "\n"
            . 'echo \'BOOTFUNCS=\' . implode(\',\', $from_bootstrap) . "\n";' . "\n"
            . 'echo \'WPLOAD=\' . ($loaded ? \'1\' : \'0\') . "\n";' . "\n";
        file_put_contents($runner, $script);

        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);
        $abspath = '';
        $bootstrap_functions = 'missing';
        $loaded = true;

        foreach ($output as $line) {
            if (strpos($line, 'ABSPATH=') === 0) {
                $abspath = substr($line, strlen('ABSPATH='));
            }

            if (strpos($line, 'BOOTFUNCS=') === 0) {
                $bootstrap_functions = substr($line, strlen('BOOTFUNCS='));
            }

            if (strpos($line, 'WPLOAD=') === 0) {
                $loaded = substr($line, strlen('WPLOAD=')) === '1';
            }
        }

        return array(
            'status' => $status,
            'output' => $text,
            'abspath' => $abspath,
            'bootstrap_functions' => $bootstrap_functions,
            'loaded' => $loaded,
        );
    }

    /**
     * phpunit.xml.dist scans this directory and does not point at WordPress.
     */
    public function test_phpunit_config_scans_the_tolka_directory()
    {
        $path = WMM_TOLKA_PLUGIN_DIR . '/phpunit.xml.dist';
        $this->assertFileExists($path);

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->load($path);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'phpunit.xml.dist is not valid XML.');
        $this->assertCount(0, $errors);

        $phpunit = $document->documentElement;
        $this->assertNotNull($phpunit);
        $this->assertSame('phpunit', $phpunit->nodeName);
        $this->assertSame('tests/tolka/bootstrap.php', $phpunit->getAttribute('bootstrap'));

        $suites = $phpunit->getElementsByTagName('testsuite');
        $this->assertSame(1, $suites->length);
        $this->assertSame('tolka', $suites->item(0)->getAttribute('name'));

        $directories = $suites->item(0)->getElementsByTagName('directory');
        $this->assertSame(1, $directories->length);
        $this->assertSame('tests/tolka', trim($directories->item(0)->textContent));
        $this->assertSame('test-', $directories->item(0)->getAttribute('prefix'));
        $this->assertSame('.php', $directories->item(0)->getAttribute('suffix'));

        $xml = file_get_contents($path);
        $this->assertStringNotContainsString('WP_TESTS_DIR', $xml);
        $this->assertFalse(
            (bool) preg_match('/\b(require|include)(_once)?\b[^;]*wp-load\.php/', $xml),
            'phpunit.xml.dist must not load wp-load.php.'
        );
    }

    /**
     * A class that already loaded is not a reason to skip.
     */
    public function test_loaded_class_is_not_skipped()
    {
        wmm_tolka_skip_without_class('Harness_Test');
        wmm_tolka_skip_without_class('\\WMM\\Tolka\\Harness_Test');
        wmm_tolka_skip_without_class('  harness_test  ');
        wmm_tolka_skip_without_class(array('Harness_Test', 'WMM\\Tolka\\Harness_Test'));
        wmm_tolka_skip_without_class(array());

        $this->assertTrue(class_exists(__CLASS__, false));
    }

    /**
     * A missing slice skips with a sentence a developer can act on.
     */
    public function test_missing_class_skips_with_a_finished_sentence()
    {
        try {
            wmm_tolka_skip_without_class('Missing_From_The_Suite');
            $this->fail('A missing class should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('WMM\\Tolka\\Missing_From_The_Suite', $error->getMessage());
            $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
            $this->assertStringContainsString('missing', strtolower($error->getMessage()));
            $this->assert_finished_sentence($error->getMessage(), 'missing class');
        }
    }

    /**
     * Every slice class that has not landed skips with a finished sentence.
     * The suite keeps running when none of those files exist.
     */
    public function test_every_missing_slice_class_skips_with_a_finished_sentence()
    {
        $classes = array(
            'Polylang_Detector',
            'Polylang_Languages',
            'Polylang_Posts',
            'Polylang_Terms',
            'Polylang_Strings',
            'Polylang_Menus',
            'Polylang_Media',
            'Tolka_Schema',
            'Tolka_Writer',
            'Migration_Runner',
            'Migration_Report',
            'Speed_Loader',
            'Speed_Metrics',
            'Speed_Estimate',
        );
        $missing = 0;

        foreach ($classes as $short) {
            if (class_exists('WMM\\Tolka\\' . $short, false)) {
                $this->assertTrue(class_exists('WMM\\Tolka\\' . $short, false), $short);
                continue;
            }

            $missing++;

            try {
                wmm_tolka_skip_without_class($short);
                $this->fail($short . ' is not loaded, so the test should have skipped.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error) . ' for ' . $short);
                $this->assertStringContainsString('WMM\\Tolka\\' . $short, $error->getMessage(), $short);
                $this->assertStringContainsString('skipped', strtolower($error->getMessage()), $short);
                $this->assert_finished_sentence($error->getMessage(), $short);
            }
        }

        if ($missing === count($classes)) {
            $directory = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka';
            $files = is_dir($directory) ? glob($directory . '/class-*.php') : array();

            if (!is_array($files) || count($files) === 0) {
                $this->assertSame(array(), wmm_tolka_load_classes());
                return;
            }

            $this->assertTrue(is_array(wmm_tolka_load_classes()));
        }
    }

    /**
     * An empty name is an empty state, not a crash.
     */
    public function test_blank_class_name_skips_with_a_finished_sentence()
    {
        try {
            wmm_tolka_skip_without_class('   ');
            $this->fail('A blank class name should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('did not name a Tolka class', $error->getMessage());
            $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
            $this->assert_finished_sentence($error->getMessage(), 'blank class');
        }
    }

    /**
     * One missing class in a list skips, and loaded classes stay out of the reason.
     */
    public function test_any_missing_class_in_a_list_skips()
    {
        try {
            wmm_tolka_skip_without_class(array('Harness_Test', 'Missing_From_The_Suite'));
            $this->fail('One missing class should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('WMM\\Tolka\\Missing_From_The_Suite', $error->getMessage());
            $this->assertStringNotContainsString('Harness_Test', $error->getMessage());
        }
    }

    /**
     * A name that already includes a namespace is not rewritten.
     */
    public function test_class_outside_the_tolka_namespace_can_skip()
    {
        try {
            wmm_tolka_skip_without_class('Other\\Harness_Test');
            $this->fail('A class outside this suite should skip when it is not loaded.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('Other\\Harness_Test', $error->getMessage());
        }
    }

    /**
     * End-to-end skip: PHPUnit records the test as skipped, not failed.
     */
    public function test_missing_slice_is_skipped_instead_of_failed()
    {
        wmm_tolka_skip_without_class('Not_A_Real_Tolka_Class');
    }

    /**
     * One missing class is one skip, and the skip names that class.
     */
    public function test_first_missing_class_is_its_own_skip()
    {
        wmm_tolka_skip_without_class('First_Missing_Slice');
    }

    /**
     * A second missing class is a second skip, and it names that class.
     */
    public function test_second_missing_class_is_its_own_skip()
    {
        wmm_tolka_skip_without_class('Second_Missing_Slice');
    }

    /**
     * Two missing classes produce two skips, and the suite still exits successfully.
     */
    public function test_two_missing_classes_produce_two_skips_and_exit_successfully()
    {
        $binary = $this->phpunit_binary();

        if ($binary === null) {
            $this->markTestSkipped('PHPUnit could not be found for a subprocess, so this check was skipped.');
        }

        $cache = sys_get_temp_dir() . '/wmm-tolka-two-skips-' . getmypid();
        $version_output = array();
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' --version 2>&1', $version_output);
        $version_text = implode(' ', $version_output);
        $extra = ' --verbose';
        $major = 9;

        if (preg_match('/PHPUnit (\d+)/', $version_text, $version_match)) {
            $major = (int) $version_match[1];
        }

        if ($major >= 10) {
            $extra = ' --display-skipped --do-not-fail-on-skipped --cache-directory ' . escapeshellarg($cache);
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
            . ' -c ' . escapeshellarg(WMM_TOLKA_PLUGIN_DIR . '/phpunit.xml.dist')
            . ' --do-not-cache-result' . $extra
            . ' --filter ' . escapeshellarg('test_first_missing_class_is_its_own_skip|test_second_missing_class_is_its_own_skip');
        $output = array();
        $status = 1;
        exec($command . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        $this->assertMatchesRegularExpression('/Skipped:\s+2\b/', $text);
        $this->assertStringContainsString('WMM\\Tolka\\First_Missing_Slice', $text);
        $this->assertStringContainsString('WMM\\Tolka\\Second_Missing_Slice', $text);
        $this->assertGreaterThanOrEqual(2, substr_count($text, 'is not loaded, so this test was skipped'));
        $this->assertStringNotContainsString('Fatal error', $text);
        $this->assertStringNotContainsString('FAILURES', $text);
    }

    /**
     * Class files use the plugin's class-*.php names.
     */
    public function test_class_file_names_follow_the_plugin_pattern()
    {
        $this->assertSame('class-speed-estimate.php', wmm_tolka_class_basename('Speed_Estimate'));
        $this->assertSame('class-polylang-detector.php', wmm_tolka_class_basename('WMM\\Tolka\\Polylang_Detector'));
        $this->assertSame('class-tolka-schema.php', wmm_tolka_class_basename('\\WMM\\Tolka\\Tolka_Schema'));
        $this->assertSame('', wmm_tolka_class_basename('   '));
        $this->assertSame('WMM\\Tolka\\Speed_Estimate', wmm_tolka_expected_class('class-speed-estimate.php'));
        $this->assertSame('WMM\\Tolka\\Polylang_Detector', wmm_tolka_expected_class('class-polylang-detector.php'));
        $this->assertSame('WMM\\Tolka\\Migration_Runner', wmm_tolka_expected_class('class-migration-runner.php'));
        $this->assertSame('', wmm_tolka_expected_class('bootstrap.php'));
    }

    /**
     * A file that loads under the wrong class name skips instead of failing.
     */
    public function test_loaded_file_without_its_class_skips_with_a_finished_sentence()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-orphan-shell.php',
            "<?php\nnamespace WMM\\Tolka;\n\nclass Different_Name\n{\n}\n"
        );
        $previous_undefined = wmm_tolka_undefined_classes();

        try {
            $loaded = wmm_tolka_load_classes($directory);
            $found = false;

            foreach ($loaded as $path) {
                if (basename($path) === 'class-orphan-shell.php') {
                    $found = true;
                }
            }

            $this->assertTrue($found);
            $this->assertTrue(class_exists('WMM\\Tolka\\Different_Name', false));
            $this->assertFalse(class_exists('WMM\\Tolka\\Orphan_Shell', false));

            try {
                wmm_tolka_skip_without_class('Orphan_Shell');
                $this->fail('A file that does not define its class should skip the test.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('WMM\\Tolka\\Orphan_Shell', $error->getMessage());
                $this->assertStringContainsString('class-orphan-shell.php', $error->getMessage());
                $this->assertStringContainsString('did not define', $error->getMessage());
                $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
                $this->assertStringNotContainsString('class file is missing', $error->getMessage());
                $this->assert_finished_sentence($error->getMessage(), 'orphan shell');
            }
        } finally {
            $GLOBALS['wmm_tolka_undefined_classes'] = $previous_undefined;
        }
    }

    /**
     * An empty class file is an empty state. PHPUnit records a skip, not a failure.
     */
    public function test_empty_class_file_is_skipped_instead_of_failed()
    {
        $directory = $this->make_temp_dir();
        file_put_contents($directory . '/class-blank-shell.php', "<?php\n");
        $previous_undefined = wmm_tolka_undefined_classes();

        try {
            wmm_tolka_load_classes($directory);
            $this->assertFalse(class_exists('WMM\\Tolka\\Blank_Shell', false));
            wmm_tolka_skip_without_class('Blank_Shell');
        } finally {
            $GLOBALS['wmm_tolka_undefined_classes'] = $previous_undefined;
        }
    }

    /**
     * The same short name in the global namespace is not the Tolka class.
     */
    public function test_global_class_with_the_file_name_still_skips()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-global-shell.php',
            "<?php\nclass Global_Shell\n{\n}\n"
        );
        $previous_undefined = wmm_tolka_undefined_classes();

        try {
            wmm_tolka_load_classes($directory);
            $this->assertTrue(class_exists('Global_Shell', false));
            $this->assertFalse(class_exists('WMM\\Tolka\\Global_Shell', false));

            try {
                wmm_tolka_skip_without_class('Global_Shell');
                $this->fail('A global class should not satisfy the Tolka namespace.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('did not define', $error->getMessage());
                $this->assertStringContainsString('WMM\\Tolka\\Global_Shell', $error->getMessage());
                $this->assert_finished_sentence($error->getMessage(), 'global shell');
            }
        } finally {
            $GLOBALS['wmm_tolka_undefined_classes'] = $previous_undefined;
        }
    }

    /**
     * One loaded-but-undefined class skips a list, and loaded classes stay out of the reason.
     */
    public function test_a_list_skips_when_one_loaded_file_omits_its_class()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-list-shell.php',
            "<?php\nnamespace WMM\\Tolka;\nclass Not_List_Shell {}\n"
        );
        $previous_undefined = wmm_tolka_undefined_classes();

        try {
            wmm_tolka_load_classes($directory);

            try {
                wmm_tolka_skip_without_class(array('Harness_Test', 'List_Shell'));
                $this->fail('A list with one missing class should skip.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('WMM\\Tolka\\List_Shell', $error->getMessage());
                $this->assertStringContainsString('did not define', $error->getMessage());
                $this->assertStringNotContainsString('Harness_Test', $error->getMessage());
                $this->assert_finished_sentence($error->getMessage(), 'list shell');
            }
        } finally {
            $GLOBALS['wmm_tolka_undefined_classes'] = $previous_undefined;
        }
    }

    /**
     * A file that defines the class its name promises is not skipped.
     */
    public function test_loaded_file_that_defines_its_class_is_not_skipped()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-present-shell.php',
            "<?php\nnamespace WMM\\Tolka;\n\nclass Present_Shell\n{\n    public static function ok()\n    {\n        return true;\n    }\n}\n"
        );
        $previous_undefined = wmm_tolka_undefined_classes();

        try {
            wmm_tolka_load_classes($directory);
            wmm_tolka_skip_without_class('Present_Shell');
            $this->assertTrue(\WMM\Tolka\Present_Shell::ok());
            $this->assertArrayNotHasKey('class-present-shell.php', wmm_tolka_undefined_classes());
        } finally {
            $GLOBALS['wmm_tolka_undefined_classes'] = $previous_undefined;
        }
    }

    /**
     * Abstract classes and anonymous classes are not the test PHPUnit should run.
     */
    public function test_concrete_class_names_skip_abstract_and_anonymous_classes()
    {
        $directory = $this->make_temp_dir();
        $file = $directory . '/classes.php';
        file_put_contents(
            $file,
            "<?php\nabstract class Abstract_Probe {}\nfinal class Probe_Test {}\n\$made = new class {};\nclass Other_Test {}\n"
        );

        $this->assertSame(array('Probe_Test', 'Other_Test'), wmm_tolka_concrete_class_names($file));
        $this->assertSame(
            'Estimate_Test',
            wmm_tolka_primary_test_class(array('Fixture_Builder', 'Estimate_Test', 'Other_Test'))
        );
        $this->assertSame('Probe_Test', wmm_tolka_primary_test_class(array('Probe_Test')));
        $this->assertSame('Helper', wmm_tolka_primary_test_class(array('Helper')));
        $this->assertSame('', wmm_tolka_primary_test_class(array()));
    }

    /**
     * Requiring the bootstrap twice must not redeclare its helpers.
     */
    public function test_bootstrap_can_be_loaded_twice()
    {
        require_once WMM_TOLKA_TEST_DIR . '/bootstrap.php';
        require WMM_TOLKA_TEST_DIR . '/bootstrap.php';
        require WMM_TOLKA_TEST_DIR . '/bootstrap.php';

        $this->assertTrue(function_exists('wmm_tolka_skip_without_class'));
        $this->assertTrue(function_exists('wmm_tolka_load_classes'));
        $this->assertTrue(defined('WMM_TOLKA_TEST_BOOTSTRAP'));
    }

    /**
     * A second include in a fresh process stays quiet under E_ALL.
     */
    public function test_including_the_bootstrap_twice_does_not_warn_or_fail()
    {
        $bootstrap = var_export(WMM_TOLKA_TEST_DIR . '/bootstrap.php', true);
        $resolved = var_export(realpath(WMM_TOLKA_TEST_DIR . '/bootstrap.php'), true);
        $runner = $this->make_temp_dir() . '/runner.php';
        $script = "<?php\n"
            . "error_reporting(E_ALL);\n"
            . "ini_set('display_errors', '1');\n"
            . "set_error_handler(function (\$severity, \$message) {\n"
            . "    fwrite(STDERR, 'WARN ' . \$message . \"\\n\");\n"
            . "    return true;\n"
            . "});\n"
            . "require $bootstrap;\n"
            . "require $resolved;\n"
            . "include $bootstrap;\n"
            . "echo function_exists('wmm_tolka_load_classes') ? \"OK\\n\" : \"MISSING\\n\";\n";
        file_put_contents($runner, $script);

        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        $this->assertStringContainsString('OK', $text);
        $this->assertStringNotContainsString('WARN', $text);
        $this->assertStringNotContainsString('Cannot redeclare', $text);
    }

    /**
     * PHP files in tests/tolka that are not test-*.php are not test cases.
     */
    public function test_files_that_are_not_test_scripts_are_not_executed()
    {
        $binary = $this->phpunit_binary();

        if ($binary === null) {
            $this->markTestSkipped('PHPUnit could not be found for a subprocess, so this check was skipped.');
        }

        $directory = WMM_TOLKA_TEST_DIR;
        $decoys = array(
            $directory . '/not-a-test.php',
            $directory . '/test.php',
            $directory . '/TolkaExtraTest.php',
            $directory . '/Test-Foo.php',
            $directory . '/support/helper.php',
        );
        $support = $directory . '/support';

        if (!is_dir($support)) {
            mkdir($support, 0700, true);
        }

        $bomb = "<?php\necho \"EXECUTED_NOT_A_TEST\\n\";\nexit(97);\n";

        try {
            foreach ($decoys as $path) {
                file_put_contents($path, $bomb);
            }

            $cache = sys_get_temp_dir() . '/wmm-tolka-list-' . getmypid();
            $version_output = array();
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' --version 2>&1', $version_output);
            $cache_args = '';

            if (preg_match('/PHPUnit (\d+)/', implode(' ', $version_output), $version_match) && (int) $version_match[1] >= 10) {
                $cache_args = ' --cache-directory ' . escapeshellarg($cache);
            }

            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
                . ' -c ' . escapeshellarg(WMM_TOLKA_PLUGIN_DIR . '/phpunit.xml.dist')
                . ' --list-tests --do-not-cache-result' . $cache_args;
            $output = array();
            $status = 1;
            exec($command . ' 2>&1', $output, $status);
            $text = implode("\n", $output);

            $this->assertSame(0, $status, $text);
            $this->assertStringNotContainsString('EXECUTED_NOT_A_TEST', $text);
            $this->assertStringContainsString('Harness_Test', $text);
            $this->assertStringNotContainsString('not-a-test.php', $text);
            $this->assertStringNotContainsString('TolkaExtraTest', $text);
            $this->assertStringNotContainsString('helper.php', $text);
        } finally {
            foreach ($decoys as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($support)) {
                @rmdir($support);
            }
        }
    }

    /**
     * The PHPUnit entry point that launched this process, when it is a file.
     *
     * @return string|null
     */
    private function phpunit_binary()
    {
        if (isset($_SERVER['argv'][0]) && is_file($_SERVER['argv'][0])) {
            return $_SERVER['argv'][0];
        }

        return null;
    }

    /**
     * A fresh process can boot the suite when no class files exist yet.
     */
    public function test_bootstrap_runs_in_a_fresh_process_without_wordpress()
    {
        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WMM_TOLKA_TEST_DIR . '/bootstrap.php') . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame('', trim(implode("\n", $output)));
    }

    /**
     * The direct-access guard used by plugin files must not exit the suite.
     */
    public function test_direct_access_guard_does_not_abort_the_process()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-guarded.php',
            "<?php\nif (!defined('ABSPATH')) {\n    exit(3);\n}\nclass Wmm_Tolka_Guarded_Probe {\n}\n"
        );

        $runner = $directory . '/runner.php';
        $script = "<?php\nrequire " . var_export(WMM_TOLKA_TEST_DIR . '/bootstrap.php', true) . ";\n"
            . "wmm_tolka_load_classes(" . var_export($directory, true) . ");\n"
            . "echo class_exists('Wmm_Tolka_Guarded_Probe', false) ? \"WMM_TOLKA_PROBE=loaded\\n\" : \"WMM_TOLKA_PROBE=missing\\n\";\n"
            . "\$wordpress = function_exists('add_action') || function_exists('wp_remote_get') || function_exists('get_option');\n"
            . "echo \$wordpress ? \"WMM_TOLKA_WP=1\\n\" : \"WMM_TOLKA_WP=0\\n\";\n";
        file_put_contents($runner, $script);

        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        $this->assertStringContainsString('WMM_TOLKA_PROBE=loaded', $text);
        $this->assertStringContainsString('WMM_TOLKA_WP=0', $text);
    }

    /**
     * The loader must not create includes/tolka when that slice is absent.
     */
    public function test_loader_does_not_create_the_class_directory()
    {
        $directory = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka';
        $existed = is_dir($directory);
        $loaded = wmm_tolka_load_classes();

        $this->assertTrue(is_array($loaded));

        if (!$existed) {
            $this->assertFalse(is_dir($directory));
            $this->assertSame(array(), $loaded);
        }
    }

    /**
     * A checkout without includes/tolka finishes in skips, not a fatal error.
     */
    public function test_suite_finishes_with_skips_when_tolka_directory_is_missing()
    {
        if (getenv('WMM_TOLKA_NESTED_SUITE') === '1') {
            $this->assertFalse(is_dir(WMM_TOLKA_PLUGIN_DIR . '/includes/tolka'));
            $this->assertSame(array(), wmm_tolka_load_classes());
            return;
        }

        $binary = $this->phpunit_binary();

        if ($binary === null) {
            $this->markTestSkipped('PHPUnit could not be found for a subprocess, so this check was skipped.');
        }

        $directory = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka';
        $backup = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka-aside-' . getmypid();
        $existed = is_dir($directory);

        if ($existed && !rename($directory, $backup)) {
            $this->fail('The test could not move includes/tolka aside.');
        }

        try {
            if (!class_exists('WMM\\Tolka\\Speed_Estimate', false)) {
                wmm_tolka_load_classes();

                try {
                    wmm_tolka_skip_without_class('Speed_Estimate');
                    $this->fail('A missing Tolka directory should skip the test.');
                } catch (Throwable $error) {
                    $this->assertTrue($this->is_skip_exception($error), get_class($error));
                    $this->assertStringContainsString('includes/tolka directory is missing', $error->getMessage());
                    $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
                    $this->assert_finished_sentence($error->getMessage(), 'missing directory');
                }
            }

            $boot_output = array();
            $boot_status = 1;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WMM_TOLKA_TEST_DIR . '/bootstrap.php') . ' 2>&1', $boot_output, $boot_status);
            $boot_text = implode("\n", $boot_output);
            $this->assertSame(0, $boot_status, $boot_text);
            $this->assertStringNotContainsString('Fatal error', $boot_text);
            $this->assertSame('', trim($boot_text));

            $cache = sys_get_temp_dir() . '/wmm-tolka-missing-dir-' . getmypid();
            $version_output = array();
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' --version 2>&1', $version_output);
            $cache_args = '';

            if (preg_match('/PHPUnit (\d+)/', implode(' ', $version_output), $version_match) && (int) $version_match[1] >= 10) {
                $cache_args = ' --cache-directory ' . escapeshellarg($cache);
            }

            $command = 'WMM_TOLKA_NESTED_SUITE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
                . ' -c ' . escapeshellarg(WMM_TOLKA_PLUGIN_DIR . '/phpunit.xml.dist')
                . ' --do-not-cache-result' . $cache_args;
            $output = array();
            $status = 1;
            exec($command . ' 2>&1', $output, $status);
            $text = implode("\n", $output);

            $this->assertSame(0, $status, $text);
            $this->assertStringNotContainsString('Fatal error', $text);
            $this->assertStringNotContainsString('Uncaught', $text);
            $this->assertStringNotContainsString('failed to open stream', strtolower($text));
            $this->assertMatchesRegularExpression('/skipped/i', $text);
            $this->assertFalse(is_dir($directory));
        } finally {
            if ($existed && is_dir($backup)) {
                if (is_dir($directory)) {
                    $left = array_diff(scandir($directory), array('.', '..'));

                    if (count($left) === 0) {
                        rmdir($directory);
                    }
                }

                if (!is_dir($directory)) {
                    rename($backup, $directory);
                }
            }

            wmm_tolka_load_classes();
        }
    }

    /**
     * A blank path means the real include directory.
     */
    public function test_blank_directory_uses_the_plugin_class_directory()
    {
        $loaded = wmm_tolka_load_classes('');

        $this->assertTrue(is_array($loaded));
    }

    /**
     * A directory that has not been added yet is an empty state.
     */
    public function test_loader_accepts_a_missing_directory()
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/wmm-tolka-missing-' . uniqid('', true);

        $this->assertFalse(is_dir($directory));
        $this->assertSame(array(), wmm_tolka_load_classes($directory));
    }

    /**
     * An empty include directory loads nothing and does not fail.
     */
    public function test_loader_accepts_an_empty_directory()
    {
        $directory = $this->make_temp_dir();

        $this->assertSame(array(), wmm_tolka_load_classes($directory));
    }

    /**
     * Only a string path is a class directory.
     */
    public function test_loader_rejects_a_directory_that_is_not_a_path()
    {
        try {
            wmm_tolka_load_classes(array('not-a-path'));
            $this->fail('A class directory that is not a path should be rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('must be a path', $error->getMessage());
        }
    }

    /**
     * class-*.php files load in sorted order, and only once.
     */
    public function test_loader_requires_class_files_in_sorted_order_once()
    {
        $directory = $this->make_temp_dir();
        $GLOBALS['wmm_tolka_harness_order'] = array();

        try {
            file_put_contents($directory . '/class-m.php', "<?php\n\$GLOBALS['wmm_tolka_harness_order'][] = 'm';\n");
            file_put_contents($directory . '/class-a.php', "<?php\n\$GLOBALS['wmm_tolka_harness_order'][] = 'a';\n");

            $first = wmm_tolka_load_classes($directory);
            $second = wmm_tolka_load_classes($directory);

            $this->assertSame(array('a', 'm'), $GLOBALS['wmm_tolka_harness_order']);
            $this->assertCount(2, $first);
            $this->assertCount(2, $second);
        } finally {
            unset($GLOBALS['wmm_tolka_harness_order']);
        }
    }

    /**
     * Notes, text files, and nested class files are not part of the suite.
     */
    public function test_loader_ignores_files_that_are_not_top_level_class_files()
    {
        $directory = $this->make_temp_dir();
        mkdir($directory . '/nested');
        $GLOBALS['wmm_tolka_harness_seen'] = array();

        try {
            $record = "<?php\n\$GLOBALS['wmm_tolka_harness_seen'][] = '%s';\n";
            file_put_contents($directory . '/notes.php', sprintf($record, 'notes'));
            file_put_contents($directory . '/class-foo.txt', sprintf($record, 'txt'));
            file_put_contents($directory . '/aclass-foo.php', sprintf($record, 'aprefix'));
            file_put_contents($directory . '/nested/class-nested.php', sprintf($record, 'nested'));
            file_put_contents($directory . '/class-real.php', sprintf($record, 'real'));

            $loaded = wmm_tolka_load_classes($directory);
            $basenames = array();

            foreach ($loaded as $path) {
                $basenames[] = basename($path);
            }

            $this->assertSame(array('real'), $GLOBALS['wmm_tolka_harness_seen']);
            $this->assertSame(array('class-real.php'), $basenames);
        } finally {
            unset($GLOBALS['wmm_tolka_harness_seen']);
        }
    }

    /**
     * A syntax error names the file and stays a readable failure.
     */
    public function test_syntax_error_in_a_class_file_is_reported_clearly()
    {
        $directory = $this->make_temp_dir();
        file_put_contents($directory . '/class-broken.php', "<?php\nclass Broken {\n");

        try {
            wmm_tolka_load_classes($directory);
            $this->fail('A syntax error in a class file should stop the suite with a clear message.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('class-broken.php', $error->getMessage());
            $this->assertStringContainsString('syntax error', strtolower($error->getMessage()));
        }
    }

    /**
     * A class file that hits a missing API does not take the rest of the suite down.
     */
    public function test_load_error_lets_the_suite_skip_that_class()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-undefined-load.php',
            "<?php\nnamespace WMM\\Tolka;\n\nwmm_tolka_this_function_does_not_exist();\n\nclass Undefined_Load\n{\n}\n"
        );

        $previous = wmm_tolka_load_errors();

        try {
            $loaded = wmm_tolka_load_classes($directory);
            $basenames = array();

            foreach ($loaded as $path) {
                $basenames[] = basename($path);
            }

            $found_broken_file = false;

            foreach ($basenames as $basename) {
                if ($basename === 'class-undefined-load.php') {
                    $found_broken_file = true;
                }
            }

            $this->assertFalse($found_broken_file);
            $this->assertTrue(class_exists('WMM\\Tolka\\Undefined_Load', false));

            $errors = wmm_tolka_load_errors();
            $this->assertArrayHasKey('class-undefined-load.php', $errors);
            $this->assertStringContainsString('could not finish loading', $errors['class-undefined-load.php']);
            $this->assertStringContainsString('wmm_tolka_this_function_does_not_exist', $errors['class-undefined-load.php']);

            // PHP still registers the class, so a later test can use it.
            wmm_tolka_skip_without_class('Undefined_Load');
            $this->assertTrue(class_exists('WMM\\Tolka\\Undefined_Load', false));
        } finally {
            $GLOBALS['wmm_tolka_load_errors'] = $previous;
        }
    }

    /**
     * An unreadable class directory tells the reader how to fix it.
     */
    public function test_unreadable_class_directory_explains_how_to_fix_it()
    {
        $directory = $this->make_temp_dir();
        chmod($directory, 0000);

        if (is_readable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('This environment can still read a directory with mode 000, so the unreadable-directory test was skipped.');
        }

        try {
            wmm_tolka_load_classes($directory);
            $this->fail('An unreadable class directory should stop the suite with a clear message.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('could not read the class directory', $error->getMessage());
            $this->assertStringContainsString('readable', $error->getMessage());
        } finally {
            chmod($directory, 0755);
        }
    }

    /**
     * An unreadable class file skips with a finished sentence and does not abort.
     */
    public function test_unreadable_class_file_skips_with_a_finished_sentence()
    {
        $directory = $this->make_temp_dir();
        $file = $directory . '/class-unreadable-shell.php';
        file_put_contents($file, "<?php\nnamespace WMM\\Tolka;\nclass Unreadable_Shell {}\n");
        chmod($file, 0000);

        if (is_readable($file)) {
            chmod($file, 0644);
            $this->markTestSkipped('This environment can still read a file with mode 000, so the unreadable-file test was skipped.');
        }

        $previous = wmm_tolka_load_errors();

        try {
            $loaded = wmm_tolka_load_classes($directory);
            $found = false;

            foreach ($loaded as $path) {
                if (basename($path) === 'class-unreadable-shell.php') {
                    $found = true;
                }
            }

            $this->assertFalse($found);
            $this->assertFalse(class_exists('WMM\\Tolka\\Unreadable_Shell', false));

            try {
                wmm_tolka_skip_without_class('Unreadable_Shell');
                $this->fail('An unreadable class file should skip the test.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('could not read', $error->getMessage());
                $this->assertStringContainsString('class-unreadable-shell.php', $error->getMessage());
                $this->assertStringContainsString('readable', $error->getMessage());
                $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
                $this->assert_finished_sentence($error->getMessage(), 'unreadable file');
            }
        } finally {
            chmod($file, 0644);
            $GLOBALS['wmm_tolka_load_errors'] = $previous;
        }
    }

    /**
     * Loading the bootstrap leaves the process in the directory it started in.
     */
    public function test_loading_the_bootstrap_does_not_change_the_working_directory()
    {
        $before = getcwd();
        require WMM_TOLKA_TEST_DIR . '/bootstrap.php';
        $this->assertSame($before, getcwd());

        $workdir = $this->make_temp_dir();
        $runner = $workdir . '/runner.php';
        $script = "<?php\n"
            . 'chdir(' . var_export($workdir, true) . ");\n"
            . 'require ' . var_export(WMM_TOLKA_TEST_DIR . '/bootstrap.php', true) . ";\n"
            . "echo getcwd();\n";
        file_put_contents($runner, $script);

        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        $this->assertSame(realpath($workdir), trim($text));
    }

    /**
     * A class file that changes directory does not leave the suite there.
     */
    public function test_a_class_file_cannot_change_the_working_directory()
    {
        $directory = $this->make_temp_dir();
        $away = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-chdir-shell.php',
            "<?php\nnamespace WMM\\Tolka;\nchdir(" . var_export($away, true) . ");\nclass Chdir_Shell {}\n"
        );
        $before = getcwd();

        try {
            wmm_tolka_load_classes($directory);
            $this->assertSame($before, getcwd());
            $this->assertTrue(class_exists('WMM\\Tolka\\Chdir_Shell', false));
        } finally {
            if (getcwd() !== $before && $before !== false) {
                chdir($before);
            }
        }
    }

    /**
     * A file that errors before it declares a class stays skippable.
     */
    public function test_load_error_without_a_class_is_included_in_the_skip_reason()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-missing-body.php',
            "<?php\nnamespace WMM\\Tolka;\n\nwmm_tolka_body_missing();\n"
        );

        $previous = wmm_tolka_load_errors();

        try {
            wmm_tolka_load_classes($directory);
            $this->assertFalse(class_exists('WMM\\Tolka\\Missing_Body', false));

            try {
                wmm_tolka_skip_without_class('Missing_Body');
                $this->fail('A class that never loaded should skip.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('WMM\\Tolka\\Missing_Body', $error->getMessage());
                $this->assertStringContainsString('could not finish loading', $error->getMessage());
                $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
            }
        } finally {
            $GLOBALS['wmm_tolka_load_errors'] = $previous;
        }
    }

    /**
     * PHPUnit 10 and 11 need a real path named after the test class.
     * The scanned file stays tests/tolka/test-harness.php.
     */
    public function test_hyphenated_test_file_is_part_of_the_suite()
    {
        $source = WMM_TOLKA_TEST_DIR . '/test-harness.php';
        $resolved = realpath($source);

        $this->assertFileExists($source);
        $this->assertNotFalse($resolved);
        $this->assertSame('Harness_Test', (new \ReflectionClass($this))->getShortName());

        if ((int) \PHPUnit\Runner\Version::id() >= 10) {
            $this->assertStringEndsWith('Harness_Test.php', $resolved);
            $this->assertTrue(is_link($source));
            return;
        }

        $this->assertStringEndsWith('test-harness.php', $resolved);
        $this->assertFalse(is_link($source));
    }
}
