<?php

/**
 * Speed_Estimate formula tests.
 *
 * Expected times are calculated by hand from the coefficients in
 * benchmarks.json. They are not produced by calling the class.
 *
 * @package WMM\Tolka
 */

namespace WMM\Tolka\Tests;

use PHPUnit\Framework\TestCase;
use WMM\Tolka\Speed_Estimate;

class Test_Speed_Estimate extends TestCase
{
    const BASIS = 'Measured page load plus coefficients from benchmarks.json';

    const PROVENANCE_NOTE = 'Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.';

    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }

        if (class_exists(Speed_Estimate::class)) {
            return;
        }

        $path = self::classPath();
        if (!is_readable($path)) {
            self::markTestSkipped('Speed_Estimate is not part of this install.');
        }

        require_once $path;
    }

    public function test_benchmarks_json_is_the_contract_object()
    {
        $expected = "{\n"
            . "  \"id\": \"tolka-lab\",\n"
            . "  \"provenance\": \"placeholder\",\n"
            . "  \"provenance_note\": \"Placeholder coefficients, not lab measurements. The page timing is measured live. Replace this file with Tolka lab data before using the percent in a pitch.\",\n"
            . "  \"polylang_extra_ms_per_language\": 35,\n"
            . "  \"polylang_switcher_ms\": 25,\n"
            . "  \"polylang_extra_ms_per_script\": 8,\n"
            . "  \"tolka_ms_per_language\": 6,\n"
            . "  \"max_overhead_ratio\": 0.7\n"
            . "}\n";

        $this->assertSame($expected, file_get_contents(self::benchmarksPath()));
        $this->assertSame(self::contractBenchmarks(), Speed_Estimate::benchmarks());
        $this->assertSame(
            array(
                'id',
                'provenance',
                'provenance_note',
                'polylang_extra_ms_per_language',
                'polylang_switcher_ms',
                'polylang_extra_ms_per_script',
                'tolka_ms_per_language',
                'max_overhead_ratio',
            ),
            array_keys(Speed_Estimate::benchmarks())
        );
        $this->assertSame(35, Speed_Estimate::benchmarks()['polylang_extra_ms_per_language']);
        $this->assertSame(25, Speed_Estimate::benchmarks()['polylang_switcher_ms']);
        $this->assertSame(8, Speed_Estimate::benchmarks()['polylang_extra_ms_per_script']);
        $this->assertSame(6, Speed_Estimate::benchmarks()['tolka_ms_per_language']);
        $this->assertSame(0.7, Speed_Estimate::benchmarks()['max_overhead_ratio']);
        $this->assertSame('placeholder', Speed_Estimate::benchmarks()['provenance']);
        $this->assertSame(self::PROVENANCE_NOTE, Speed_Estimate::benchmarks()['provenance_note']);

        $mutated = Speed_Estimate::benchmarks();
        $mutated['polylang_extra_ms_per_language'] = 999;
        $using_mutated = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            $mutated
        );
        // raw = 999, cap = floor(1000 * 0.7) = 700, tolka = 1000 - 700 + 6 = 306.
        $this->assertSame(306, $using_mutated['tolka_ms']);
        $this->assertSame(69.4, $using_mutated['faster_percent']);
        $this->assertSame(35, Speed_Estimate::benchmarks()['polylang_extra_ms_per_language']);
    }

    public function test_class_has_no_strict_types_and_uses_the_tolka_namespace()
    {
        $source = file_get_contents(self::classPath());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('strict_types', $source);
        $this->assertStringContainsString('namespace WMM\\Tolka;', $source);
        $this->assertStringNotContainsString('blazing', strtolower($source));
        $this->assertStringNotContainsString('times faster', strtolower($source));

        foreach (array(
            'wp_remote_get',
            'update_option',
            'delete_option',
            '$_POST',
            '$_GET',
            'curl_exec',
            'file_put_contents',
        ) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * @dataProvider formulaCases
     *
     * @param array $measurement
     * @param array $metrics
     * @param array $expected
     */
    public function test_formula_against_json_coefficients(array $measurement, array $metrics, array $expected)
    {
        $benchmarks = self::contractBenchmarks();
        $estimate = Speed_Estimate::project($measurement, $metrics, $benchmarks);

        $this->assertSuccess($estimate, $expected, $benchmarks);
        $this->assertSame('placeholder', $estimate['provenance']);
        $this->assertSame(self::PROVENANCE_NOTE, $estimate['provenance_note']);
    }

    /**
     * Hand-worked cases. Coefficients are 35, 25, 8, 6, and 0.7.
     *
     * @return array<string, array{0: array, 1: array, 2: array}>
     */
    public static function formulaCases()
    {
        return array(
            // raw = 3*35 + 25 + 4*8 = 162
            // cap = floor(1000 * 0.7) = 700
            // tolka = 1000 - 162 + min(18, 162) = 856
            // percent = round(144 / 1000 * 100, 1) = 14.4
            'three languages under the cap' => array(
                array('ok' => true, 'total_ms' => 1000),
                array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
                array(
                    'current_ms' => 1000,
                    'tolka_ms' => 856,
                    'overhead_ms' => 162,
                    'faster_percent' => 14.4,
                    'faster_label' => '14.4% faster',
                ),
            ),
            // raw = 2*35 + 8 = 78; tolka = 1000 - 78 + 12 = 934; percent = 6.6
            'switcher absent' => array(
                array('ok' => true, 'total_ms' => 1000),
                array('language_count' => 2, 'has_polylang_switcher' => false, 'script_count' => 1),
                array(
                    'current_ms' => 1000,
                    'tolka_ms' => 934,
                    'overhead_ms' => 78,
                    'faster_percent' => 6.6,
                    'faster_label' => '6.6% faster',
                ),
            ),
            // L = max(1, 0) = 1; raw = 35; tolka = 200 - 35 + 6 = 171; percent = 14.5
            'language count below one' => array(
                array('ok' => true, 'total_ms' => 200),
                array('language_count' => 0, 'has_polylang_switcher' => false, 'script_count' => 0),
                array(
                    'current_ms' => 200,
                    'tolka_ms' => 171,
                    'overhead_ms' => 35,
                    'faster_percent' => 14.5,
                    'faster_label' => '14.5% faster',
                ),
            ),
            // Missing counts use the same floor: L = 1, no switcher, no scripts.
            // tolka = 1000 - 35 + 6 = 971; percent = 2.9
            'empty metrics' => array(
                array('ok' => true, 'total_ms' => 1000),
                array(),
                array(
                    'current_ms' => 1000,
                    'tolka_ms' => 971,
                    'overhead_ms' => 35,
                    'faster_percent' => 2.9,
                    'faster_label' => '2.9% faster',
                ),
            ),
            // current = max(1, 0) = 1; cap = floor(0.7) = 0; tolka = 1; percent = 0.0
            'measured time below one millisecond' => array(
                array('ok' => true, 'total_ms' => 0),
                array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
                array(
                    'current_ms' => 1,
                    'tolka_ms' => 1,
                    'overhead_ms' => 0,
                    'faster_percent' => 0.0,
                    'faster_label' => '0.0% faster',
                ),
            ),
            // raw = 35; cap = floor(300 * 0.7) = 210; tolka = 271; percent = 9.7
            'one decimal rounding' => array(
                array('ok' => true, 'total_ms' => 300),
                array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
                array(
                    'current_ms' => 300,
                    'tolka_ms' => 271,
                    'overhead_ms' => 35,
                    'faster_percent' => 9.7,
                    'faster_label' => '9.7% faster',
                ),
            ),
        );
    }

    public function test_overhead_cap_limits_the_polylang_cost()
    {
        // raw = 20*35 + 25 + 50*8 = 1125
        // cap = floor(400 * 0.7) = 280
        // tolka = 400 - 280 + min(120, 280) = 240
        // percent = round(160 / 400 * 100, 1) = 40.0
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 400),
            array('language_count' => 20, 'has_polylang_switcher' => true, 'script_count' => 50),
            self::contractBenchmarks()
        );

        $this->assertSuccess(
            $estimate,
            array(
                'current_ms' => 400,
                'tolka_ms' => 240,
                'overhead_ms' => 280,
                'faster_percent' => 40.0,
                'faster_label' => '40.0% faster',
            ),
            self::contractBenchmarks()
        );
        $this->assertNotSame(1125, $estimate['overhead_ms']);
        $this->assertLessThanOrEqual(280, $estimate['overhead_ms']);
    }

    public function test_overhead_cap_floors_decimal_products()
    {
        // raw = 40*35 + 25 + 100*8 = 2225
        // floor(700 * 0.7) = 490. A raw IEEE floor of that product is 489.
        // tolka = 700 - 490 + min(240, 490) = 450
        // percent = round(250 / 700 * 100, 1) = 35.7
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 700),
            array('language_count' => 40, 'has_polylang_switcher' => true, 'script_count' => 100),
            self::contractBenchmarks()
        );

        $this->assertSuccess(
            $estimate,
            array(
                'current_ms' => 700,
                'tolka_ms' => 450,
                'overhead_ms' => 490,
                'faster_percent' => 35.7,
                'faster_label' => '35.7% faster',
            ),
            self::contractBenchmarks()
        );
        $this->assertNotSame(489, $estimate['overhead_ms']);

        // raw = 10*35 + 25 + 10*8 = 455
        // cap = floor(333 * 0.7) = floor(233.1) = 233
        // tolka = 333 - 233 + min(60, 233) = 160
        // percent = round(173 / 333 * 100, 1) = 52.0
        $fractional = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 333),
            array('language_count' => 10, 'has_polylang_switcher' => true, 'script_count' => 10),
            self::contractBenchmarks()
        );

        $this->assertSuccess(
            $fractional,
            array(
                'current_ms' => 333,
                'tolka_ms' => 160,
                'overhead_ms' => 233,
                'faster_percent' => 52.0,
                'faster_label' => '52.0% faster',
            ),
            self::contractBenchmarks()
        );
    }

    public function test_overhead_cap_for_several_page_times()
    {
        // raw = 100*35 + 25 + 100*8 = 4325, which is above every cap below.
        $metrics = array('language_count' => 100, 'has_polylang_switcher' => true, 'script_count' => 100);
        $caps = array(
            1 => 0,
            10 => 7,
            333 => 233,
            400 => 280,
            700 => 490,
            999 => 699,
            1000 => 700,
        );

        foreach ($caps as $total_ms => $cap) {
            $estimate = Speed_Estimate::project(
                array('ok' => true, 'total_ms' => $total_ms),
                $metrics,
                self::contractBenchmarks()
            );

            $this->assertTrue($estimate['ok'], 'total_ms ' . $total_ms);
            $this->assertSame($cap, $estimate['overhead_ms'], 'total_ms ' . $total_ms);
            $this->assertNotSame(4325, $estimate['overhead_ms']);
            $this->assertLessThanOrEqual($total_ms, $estimate['tolka_ms']);
        }
    }

    public function test_overhead_cap_matches_exact_tenths_for_the_contract_ratio()
    {
        $benchmarks = self::contractBenchmarks();
        $this->assertSame(0.7, $benchmarks['max_overhead_ratio']);

        $metrics = array(
            'language_count' => 40,
            'has_polylang_switcher' => true,
            'script_count' => 80,
        );
        $raw = 40 * 35 + 25 + 80 * 8;
        $currents = array(1, 2, 9, 10, 11, 333, 699, 700, 701, 1000, 1001, 4096);
        for ($current = 1; $current <= 1500; $current += 13) {
            $currents[] = $current;
        }

        foreach ($currents as $current) {
            $estimate = Speed_Estimate::project(
                array('ok' => true, 'total_ms' => $current),
                $metrics,
                $benchmarks
            );
            $cap = intdiv($current * 7, 10);
            $overhead = min($raw, $cap);
            $tolka = max(1, $current - $overhead + min(40 * 6, $overhead));

            $this->assertSame($current, $estimate['current_ms'], 'current ' . $current);
            $this->assertSame($overhead, $estimate['overhead_ms'], 'current ' . $current);
            $this->assertSame($tolka, $estimate['tolka_ms'], 'current ' . $current);
            $this->assertSame(
                round((($current - $tolka) / $current) * 100, 1),
                $estimate['faster_percent'],
                'current ' . $current
            );
        }

        // raw = 20*35 = 700, which is exactly floor(1000 * 0.7).
        $equal = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 20, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );
        $this->assertSame(700, $equal['overhead_ms']);
        $this->assertSame(420, $equal['tolka_ms']);
        $this->assertSame(58.0, $equal['faster_percent']);
        $this->assertSame('58.0% faster', $equal['faster_label']);
    }

    public function test_overhead_ratio_outside_zero_through_one_does_not_invent_a_percent()
    {
        $metrics = array('language_count' => 30, 'has_polylang_switcher' => true, 'script_count' => 10);
        $measurement = array('ok' => true, 'total_ms' => 1000, 'html' => '<p>SECRET_PAGE_MARKUP</p>');
        $message = 'A Tolka speed estimate is not available. The maximum overhead ratio (max_overhead_ratio) must be from 0 through 1.';

        foreach (array(1.5, 1.01, 2, -0.1, -1, '1.5', ' -0.2 ') as $ratio) {
            $benchmarks = self::contractBenchmarks();
            $benchmarks['max_overhead_ratio'] = $ratio;
            $estimate = Speed_Estimate::project($measurement, $metrics, $benchmarks);

            $this->assertUnavailable($estimate, $message);
            $this->assertSame(0, $estimate['current_ms']);
            $this->assertSame(0, $estimate['tolka_ms']);
            $this->assertSame(0, $estimate['overhead_ms']);
            $this->assertSame(0.0, $estimate['faster_percent']);
            $this->assertSame('', $estimate['faster_label']);
            $this->assertNotSame(97.5, $estimate['faster_percent']);
            $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($estimate));
        }

        // 0 and 1 are allowed. 0.5 is used as given, not replaced by the file's 0.7.
        // raw = 40*35 + 25 = 1425.
        $heavy = array('language_count' => 40, 'has_polylang_switcher' => true, 'script_count' => 0);
        $half = self::contractBenchmarks();
        $half['max_overhead_ratio'] = 0.5;
        $at_half = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            $heavy,
            $half
        );
        $this->assertTrue($at_half['ok']);
        $this->assertSame(500, $at_half['overhead_ms']);
        $this->assertSame(740, $at_half['tolka_ms']);
        $this->assertSame(26.0, $at_half['faster_percent']);
        $this->assertNotSame(700, $at_half['overhead_ms']);

        $zero = self::contractBenchmarks();
        $zero['max_overhead_ratio'] = 0;
        $at_zero = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            $zero
        );
        $this->assertTrue($at_zero['ok']);
        $this->assertSame(0, $at_zero['overhead_ms']);
        $this->assertSame(1000, $at_zero['tolka_ms']);
        $this->assertSame(0.0, $at_zero['faster_percent']);
        $this->assertSame('0.0% faster', $at_zero['faster_label']);

        $full = self::contractBenchmarks();
        $full['max_overhead_ratio'] = 1;
        $at_full = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            $heavy,
            $full
        );
        $this->assertTrue($at_full['ok']);
        $this->assertSame(1000, $at_full['overhead_ms']);
        $this->assertSame(240, $at_full['tolka_ms']);
        $this->assertSame(76.0, $at_full['faster_percent']);
        $this->assertSame('76.0% faster', $at_full['faster_label']);
    }

    public function test_switcher_false_does_not_add_switcher_milliseconds()
    {
        $benchmarks = self::contractBenchmarks();
        $switcher_ms = $benchmarks['polylang_switcher_ms'];
        $measurement = array('ok' => true, 'total_ms' => 1000);
        $off_metrics = array('language_count' => 2, 'script_count' => 1, 'has_polylang_switcher' => false);
        $on_metrics = array('language_count' => 2, 'script_count' => 1, 'has_polylang_switcher' => true);

        $off = Speed_Estimate::project($measurement, $off_metrics, $benchmarks);
        $on = Speed_Estimate::project($measurement, $on_metrics, $benchmarks);

        $this->assertTrue($off['ok']);
        $this->assertTrue($on['ok']);
        $this->assertSame(78, $off['overhead_ms']);
        $this->assertSame(103, $on['overhead_ms']);
        $this->assertSame($switcher_ms, $on['overhead_ms'] - $off['overhead_ms']);
        $this->assertSame(934, $off['tolka_ms']);
        $this->assertSame(6.6, $off['faster_percent']);

        $measurement_off = $measurement;
        $measurement_off['has_polylang_switcher'] = false;
        $forced_off = Speed_Estimate::project($measurement_off, $on_metrics, $benchmarks);
        $this->assertSame($off['overhead_ms'], $forced_off['overhead_ms']);
        $this->assertSame($off['tolka_ms'], $forced_off['tolka_ms']);
        $this->assertSame($off['faster_percent'], $forced_off['faster_percent']);
        $this->assertNotSame($on['overhead_ms'], $forced_off['overhead_ms']);
    }

    public function test_unused_metrics_do_not_change_the_percent()
    {
        $measurement = array('ok' => true, 'total_ms' => 1000);
        $base = array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4);
        $extra = $base;
        $extra['image_count'] = 500;
        $extra['stylesheet_count'] = 80;
        $extra['hreflang_count'] = 12;
        $extra['truncated'] = true;

        $plain = Speed_Estimate::project($measurement, $base, self::contractBenchmarks());
        $busy = Speed_Estimate::project($measurement, $extra, self::contractBenchmarks());

        $this->assertSame($plain['tolka_ms'], $busy['tolka_ms']);
        $this->assertSame($plain['overhead_ms'], $busy['overhead_ms']);
        $this->assertSame($plain['faster_percent'], $busy['faster_percent']);
        $this->assertSame(856, $busy['tolka_ms']);
        $this->assertSame(14.4, $busy['faster_percent']);
    }

    public function test_fractional_measured_time_rounds_to_integer_milliseconds()
    {
        $metrics = array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4);
        $lower = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000.4),
            $metrics,
            self::contractBenchmarks()
        );
        $half = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000.5),
            $metrics,
            self::contractBenchmarks()
        );

        // 1000.4 ms is 1000 ms. Same projection as an integer 1000 ms load.
        $this->assertSame(1000, $lower['current_ms']);
        $this->assertSame(856, $lower['tolka_ms']);
        $this->assertSame(14.4, $lower['faster_percent']);

        // 1000.5 ms rounds half up to 1001.
        // cap = floor(700.7) = 700; overhead stays 162; tolka = 857; percent = 14.4
        $this->assertSame(1001, $half['current_ms']);
        $this->assertSame(857, $half['tolka_ms']);
        $this->assertSame(162, $half['overhead_ms']);
        $this->assertSame(14.4, $half['faster_percent']);
        $this->assertSame('14.4% faster', $half['faster_label']);
    }

    public function test_negative_measured_time_uses_one_millisecond()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => -12),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            self::contractBenchmarks()
        );

        $this->assertSame(1, $estimate['current_ms']);
        $this->assertSame(1, $estimate['tolka_ms']);
        $this->assertSame(0, $estimate['overhead_ms']);
        $this->assertSame(0.0, $estimate['faster_percent']);
        $this->assertSame('0.0% faster', $estimate['faster_label']);
    }

    public function test_numeric_strings_follow_the_same_formula()
    {
        $benchmarks = self::contractBenchmarks();
        foreach (array(
            'polylang_extra_ms_per_language',
            'polylang_switcher_ms',
            'polylang_extra_ms_per_script',
            'tolka_ms_per_language',
            'max_overhead_ratio',
        ) as $key) {
            $benchmarks[$key] = ' ' . $benchmarks[$key] . ' ';
        }

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => '1000'),
            array('language_count' => '3', 'has_polylang_switcher' => 'yes', 'script_count' => '4'),
            $benchmarks
        );

        $this->assertTrue($estimate['ok']);
        $this->assertSame(856, $estimate['tolka_ms']);
        $this->assertSame(14.4, $estimate['faster_percent']);
        $this->assertSame($benchmarks, $estimate['coefficients']);

        $decimal = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => '1000.0'),
            array('language_count' => '3.0', 'has_polylang_switcher' => true, 'script_count' => '4.0'),
            self::contractBenchmarks()
        );
        $this->assertTrue($decimal['ok']);
        $this->assertSame(1000, $decimal['current_ms']);
        $this->assertSame(856, $decimal['tolka_ms']);
        $this->assertSame(162, $decimal['overhead_ms']);
        $this->assertSame(14.4, $decimal['faster_percent']);
        $this->assertSame('14.4% faster', $decimal['faster_label']);
    }

    public function test_switcher_flag_accepts_common_forms()
    {
        $on = array('language_count' => 2, 'script_count' => 1, 'has_polylang_switcher' => true);
        $off = array('language_count' => 2, 'script_count' => 1, 'has_polylang_switcher' => false);
        $measurement = array('ok' => true, 'total_ms' => 1000);

        $with_switcher = Speed_Estimate::project($measurement, $on, self::contractBenchmarks());
        $without_switcher = Speed_Estimate::project($measurement, $off, self::contractBenchmarks());

        $this->assertSame(909, $with_switcher['tolka_ms']);
        $this->assertSame(934, $without_switcher['tolka_ms']);

        foreach (array(true, 1, 1.0, '1', 'true', 'yes', ' YES ', 'on') as $flag) {
            $metrics = $on;
            $metrics['has_polylang_switcher'] = $flag;
            $estimate = Speed_Estimate::project($measurement, $metrics, self::contractBenchmarks());
            $this->assertSame(909, $estimate['tolka_ms'], 'flag ' . var_export($flag, true));
        }

        foreach (array(false, 0, 0.0, '0', 'false', 'no', '', 'off', null, array(), new \stdClass()) as $flag) {
            $metrics = $off;
            $metrics['has_polylang_switcher'] = $flag;
            $estimate = Speed_Estimate::project($measurement, $metrics, self::contractBenchmarks());
            $this->assertSame(934, $estimate['tolka_ms'], 'flag ' . var_export($flag, true));
        }
    }

    public function test_extra_benchmark_keys_do_not_change_the_formula()
    {
        $benchmarks = self::contractBenchmarks();
        $benchmarks['lab_note'] = 'Recorded on the fixture page.';
        $benchmarks['provenance'] = 'lab';
        $benchmarks['provenance_note'] = 'Lab note kept verbatim.';

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
            $benchmarks
        );

        $this->assertTrue($estimate['ok']);
        $this->assertSame(856, $estimate['tolka_ms']);
        $this->assertSame(14.4, $estimate['faster_percent']);
        $this->assertSame('lab', $estimate['provenance']);
        $this->assertSame('Lab note kept verbatim.', $estimate['provenance_note']);
        $this->assertSame($benchmarks, $estimate['coefficients']);
    }

    public function test_zero_coefficients_do_not_invent_a_speedup()
    {
        $benchmarks = self::contractBenchmarks();
        $benchmarks['polylang_extra_ms_per_language'] = 0;
        $benchmarks['polylang_switcher_ms'] = 0;
        $benchmarks['polylang_extra_ms_per_script'] = 0;
        $benchmarks['tolka_ms_per_language'] = 0;
        $benchmarks['max_overhead_ratio'] = 0.7;

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 8, 'has_polylang_switcher' => true, 'script_count' => 12),
            $benchmarks
        );

        $this->assertTrue($estimate['ok']);
        $this->assertSame(1000, $estimate['current_ms']);
        $this->assertSame(1000, $estimate['tolka_ms']);
        $this->assertSame(0, $estimate['overhead_ms']);
        $this->assertSame(0.0, $estimate['faster_percent']);
        $this->assertSame('0.0% faster', $estimate['faster_label']);
    }

    public function test_failed_measurement_does_not_invent_a_percent()
    {
        $benchmarks = self::contractBenchmarks();
        $estimate = Speed_Estimate::project(
            array(
                'ok' => false,
                'total_ms' => 5000,
                'error' => 'The host is not allowed.',
                'html' => '<html>SECRET_PAGE_MARKUP</html>',
            ),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
            $benchmarks
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The page could not be measured. The host is not allowed.'
        );
        $this->assertSame('placeholder', $estimate['provenance']);
        $this->assertSame(self::PROVENANCE_NOTE, $estimate['provenance_note']);
        $this->assertSame($benchmarks, $estimate['coefficients']);
        $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($estimate));
        $this->assertNotSame(5000, $estimate['current_ms']);
    }

    public function test_failed_measurement_names_a_public_url()
    {
        $benchmarks = self::contractBenchmarks();
        $named = Speed_Estimate::project(
            array(
                'ok' => false,
                'url' => 'https://example.com/fr/',
                'error' => 'The host is not allowed.',
            ),
            array(),
            $benchmarks
        );
        $this->assertUnavailable(
            $named,
            'A Tolka speed estimate is not available. The page at https://example.com/fr/ could not be measured. The host is not allowed.'
        );

        $repeated = Speed_Estimate::project(
            array(
                'ok' => false,
                'url' => 'https://example.com/fr/',
                'error' => 'Could not reach https://Example.com/fr/.',
            ),
            array(),
            $benchmarks
        );
        $this->assertUnavailable(
            $repeated,
            'A Tolka speed estimate is not available. The page could not be measured. Could not reach https://Example.com/fr/.'
        );

        $token = Speed_Estimate::project(
            array('ok' => false, 'url' => 'http://example.com', 'error' => '404'),
            array(),
            $benchmarks
        );
        $this->assertUnavailable(
            $token,
            'A Tolka speed estimate is not available. The page at http://example.com could not be measured (404).'
        );

        foreach (array(
            'javascript:alert(1)',
            'https://user:pass@example.com/secret',
            'https://example.com/not a url',
            'ftp://example.com/file',
            '<script>https://example.com</script>',
            str_repeat('https://example.com/', 20),
        ) as $url) {
            $estimate = Speed_Estimate::project(
                array('ok' => false, 'url' => $url),
                array(),
                $benchmarks
            );
            $this->assertSame(
                'A Tolka speed estimate is not available. The page could not be measured.',
                $estimate['error'],
                'url ' . $url
            );
            $this->assertStringNotContainsString('user:pass', $estimate['error']);
            $this->assertStringNotContainsString('alert', $estimate['error']);
        }
    }

    public function test_measurement_failure_messages_are_finished_sentences()
    {
        $benchmarks = self::contractBenchmarks();
        $cases = array(
            array(
                array('ok' => false),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('ok' => false, 'error' => '   '),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('ok' => false, 'error' => array('nope')),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('ok' => false, 'error' => "Timed\nout."),
                'A Tolka speed estimate is not available. The page could not be measured. Timed out.',
            ),
            array(
                array('ok' => false, 'error' => '<b>Timed out</b>'),
                'A Tolka speed estimate is not available. The page could not be measured. Timed out.',
            ),
            array(
                array('ok' => false, 'error' => '404'),
                'A Tolka speed estimate is not available. The page could not be measured (404).',
            ),
            array(
                array('ok' => false, 'error' => 404),
                'A Tolka speed estimate is not available. The page could not be measured (404).',
            ),
            array(
                array('ok' => false, 'error' => 404.0),
                'A Tolka speed estimate is not available. The page could not be measured (404).',
            ),
            array(
                array('ok' => false, 'error' => 'What happened?'),
                'A Tolka speed estimate is not available. The page could not be measured. What happened?',
            ),
            array(
                array('ok' => false, 'error' => '....'),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('ok' => false, 'error' => "\xFF\xFE"),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('ok' => 1, 'total_ms' => 1000),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
            array(
                array('total_ms' => 1000),
                'A Tolka speed estimate is not available. The page could not be measured.',
            ),
        );

        foreach ($cases as $case) {
            $estimate = Speed_Estimate::project($case[0], array(), $benchmarks);
            $this->assertUnavailable($estimate, $case[1]);
        }
    }

    public function test_long_measurement_error_is_shortened()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => false, 'error' => str_repeat('a', 300)),
            array(),
            self::contractBenchmarks()
        );

        $this->assertStringStartsWith(
            'A Tolka speed estimate is not available. The page could not be measured (',
            $estimate['error']
        );
        $this->assertStringEndsWith('…).', $estimate['error']);
        $this->assertStringNotContainsString(str_repeat('a', 250), $estimate['error']);

        $words = trim(str_repeat('measured slowly ', 40));
        $wordy = Speed_Estimate::project(
            array('ok' => false, 'error' => $words),
            array(),
            self::contractBenchmarks()
        );

        $this->assertMatchesRegularExpression('/ slowly…\.$/', $wordy['error']);
        $this->assertLessThanOrEqual(241, self::characterLength(self::detailFrom($wordy['error'])));

        $multibyte = trim(str_repeat('åäö ', 80));
        $letters = Speed_Estimate::project(
            array('ok' => false, 'error' => $multibyte),
            array(),
            self::contractBenchmarks()
        );
        $this->assertSame(1, preg_match('//u', $letters['error']));
        $this->assertMatchesRegularExpression('/åäö…\.$/u', $letters['error']);

        $huge = str_repeat('x', 10000) . 'TAIL_MARKER';
        $capped = Speed_Estimate::project(
            array('ok' => false, 'error' => $huge),
            array(),
            self::contractBenchmarks()
        );
        $this->assertStringNotContainsString('TAIL_MARKER', $capped['error']);
        $this->assertStringEndsWith('…).', $capped['error']);

        // 4000 is not a multiple of 3, so a naive byte cut splits a euro sign.
        $euros = str_repeat('error ', 20) . str_repeat('€', 2000) . 'TAIL_MARKER';
        $unicode = Speed_Estimate::project(
            array('ok' => false, 'error' => $euros),
            array(),
            self::contractBenchmarks()
        );
        $this->assertSame(1, preg_match('//u', $unicode['error']));
        $this->assertStringContainsString('error', $unicode['error']);
        $this->assertStringNotContainsString('TAIL_MARKER', $unicode['error']);
        $this->assertNotSame(
            'A Tolka speed estimate is not available. The page could not be measured.',
            $unicode['error']
        );
    }

    public function test_public_url_with_a_percent_sign_is_not_treated_as_a_format()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => false, 'url' => 'https://example.com/%73tart', 'error' => '404'),
            array(),
            self::contractBenchmarks()
        );

        $this->assertSame(
            'A Tolka speed estimate is not available. The page at https://example.com/%73tart could not be measured (404).',
            $estimate['error']
        );

        $placeholder = Speed_Estimate::project(
            array('ok' => false, 'url' => 'https://example.com/%s', 'error' => 'bad %s thing'),
            array(),
            self::contractBenchmarks()
        );
        $this->assertSame(
            'A Tolka speed estimate is not available. The page at https://example.com/%s could not be measured. bad %s thing.',
            $placeholder['error']
        );
        $this->assertSame('', $placeholder['faster_label']);
        $this->assertSame(0.0, $placeholder['faster_percent']);
    }

    public function test_missing_or_unusable_load_time_does_not_invent_a_percent()
    {
        $metrics = array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4);
        $benchmarks = self::contractBenchmarks();

        $missing = Speed_Estimate::project(array('ok' => true), $metrics, $benchmarks);
        $this->assertUnavailable(
            $missing,
            'A Tolka speed estimate is not available. The measured load time is missing.'
        );

        $blank = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => '   '),
            $metrics,
            $benchmarks
        );
        $this->assertUnavailable(
            $blank,
            'A Tolka speed estimate is not available. The measured load time is not a usable number.'
        );

        $unusable = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 'soon'),
            $metrics,
            $benchmarks
        );
        $this->assertUnavailable(
            $unusable,
            'A Tolka speed estimate is not available. The measured load time is not a usable number.'
        );

        $infinite = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => INF),
            $metrics,
            $benchmarks
        );
        $this->assertUnavailable(
            $infinite,
            'A Tolka speed estimate is not available. The measured load time is not a usable number.'
        );
        $this->assertTrue(is_finite($infinite['faster_percent']));

        $not_a_number = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => NAN),
            $metrics,
            $benchmarks
        );
        $this->assertUnavailable(
            $not_a_number,
            'A Tolka speed estimate is not available. The measured load time is not a usable number.'
        );
        $this->assertTrue(is_finite($not_a_number['faster_percent']));
    }

    public function test_both_unusable_counts_are_explained()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 'several', 'script_count' => array(4)),
            self::contractBenchmarks()
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The language count is not a usable number. The script count is not a usable number.'
        );
    }

    public function test_negative_counts_follow_the_formula_without_a_boost()
    {
        // language_count -4 uses L = max(1, -4) = 1. Same as a count of 0.
        $languages = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 200),
            array('language_count' => -4, 'has_polylang_switcher' => false, 'script_count' => 0),
            self::contractBenchmarks()
        );
        $this->assertSame(171, $languages['tolka_ms']);
        $this->assertSame(14.5, $languages['faster_percent']);

        // script_count -1 changes raw from 35 to 27. tolka = 1000 - 27 + 6 = 979.
        // That is a smaller percent than scripts = 0 (2.9), not a larger one.
        $scripts = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => -1),
            self::contractBenchmarks()
        );
        $this->assertSame(979, $scripts['tolka_ms']);
        $this->assertSame(27, $scripts['overhead_ms']);
        $this->assertSame(2.1, $scripts['faster_percent']);
        $this->assertLessThan(2.9, $scripts['faster_percent']);
    }

    public function test_estimate_stays_json_encodable_when_coefficients_are_not()
    {
        $benchmarks = self::contractBenchmarks();
        $benchmarks['max_overhead_ratio'] = INF;
        $benchmarks['handle'] = fopen('php://memory', 'r');
        $loop = array('name' => 'cycle');
        $loop['self'] = &$loop;
        $benchmarks['loop'] = $loop;

        try {
            $estimate = Speed_Estimate::project(
                array('ok' => true, 'total_ms' => 1000, 'html' => '<p>SECRET_PAGE_MARKUP</p>'),
                array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
                $benchmarks
            );
        } finally {
            if (is_resource($benchmarks['handle'])) {
                fclose($benchmarks['handle']);
            }
        }

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The benchmark file has a value that is not a number for maximum overhead ratio (max_overhead_ratio).'
        );
        $this->assertNull($estimate['coefficients']['max_overhead_ratio']);
        $this->assertNull($estimate['coefficients']['handle']);
        $encoded = json_encode($estimate);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', $encoded);
        $decoded = json_decode($encoded, true);
        $this->assertSame(0, $decoded['current_ms']);
        $this->assertSame('', $decoded['faster_label']);
    }

    public function test_non_string_provenance_stays_blank()
    {
        $benchmarks = self::contractBenchmarks();
        $benchmarks['provenance'] = array('lab');
        $benchmarks['provenance_note'] = 12;

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );

        $this->assertTrue($estimate['ok']);
        $this->assertSame('', $estimate['provenance']);
        $this->assertSame('', $estimate['provenance_note']);
        $this->assertSame(971, $estimate['tolka_ms']);
    }

    public function test_unusable_counts_do_not_invent_a_percent()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 'several', 'script_count' => 4, 'has_polylang_switcher' => false),
            self::contractBenchmarks()
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The language count is not a usable number.'
        );

        $scripts = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 2, 'script_count' => 'many'),
            self::contractBenchmarks()
        );
        $this->assertUnavailable(
            $scripts,
            'A Tolka speed estimate is not available. The script count is not a usable number.'
        );
    }

    public function test_missing_coefficients_do_not_invent_a_percent()
    {
        $measurement = array('ok' => true, 'total_ms' => 1000);
        $metrics = array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4);

        $empty = Speed_Estimate::project($measurement, $metrics, array());
        $this->assertUnavailable(
            $empty,
            'A Tolka speed estimate is not available. The benchmark coefficients could not be read from includes/tolka/data/benchmarks.json. Check that the file exists and that this site can read it.'
        );

        $only_id = Speed_Estimate::project($measurement, $metrics, array('id' => 'tolka-lab'));
        $this->assertUnavailable(
            $only_id,
            'A Tolka speed estimate is not available. The benchmark file is missing numbers for extra milliseconds per language (polylang_extra_ms_per_language), language switcher milliseconds (polylang_switcher_ms), extra milliseconds per script (polylang_extra_ms_per_script), Tolka milliseconds per language (tolka_ms_per_language), and maximum overhead ratio (max_overhead_ratio).'
        );

        $partial = self::contractBenchmarks();
        unset($partial['polylang_switcher_ms'], $partial['max_overhead_ratio']);
        $estimate = Speed_Estimate::project($measurement, $metrics, $partial);
        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The benchmark file is missing numbers for language switcher milliseconds (polylang_switcher_ms) and maximum overhead ratio (max_overhead_ratio).'
        );

        $one = self::contractBenchmarks();
        $one['max_overhead_ratio'] = 'fast';
        $invalid = Speed_Estimate::project($measurement, $metrics, $one);
        $this->assertUnavailable(
            $invalid,
            'A Tolka speed estimate is not available. The benchmark file has a value that is not a number for maximum overhead ratio (max_overhead_ratio).'
        );

        $both = self::contractBenchmarks();
        unset($both['polylang_switcher_ms']);
        $both['max_overhead_ratio'] = true;
        $mixed = Speed_Estimate::project($measurement, $metrics, $both);
        $this->assertUnavailable(
            $mixed,
            'A Tolka speed estimate is not available. The benchmark file is missing a number for language switcher milliseconds (polylang_switcher_ms). The benchmark file has a value that is not a number for maximum overhead ratio (max_overhead_ratio).'
        );
    }

    public function test_failed_measurement_and_missing_coefficients_are_both_explained()
    {
        $benchmarks = self::contractBenchmarks();
        unset($benchmarks['tolka_ms_per_language']);

        $estimate = Speed_Estimate::project(
            array('ok' => false, 'total_ms' => 5000),
            array(),
            $benchmarks
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The page could not be measured. The benchmark file is missing a number for Tolka milliseconds per language (tolka_ms_per_language).'
        );
    }

    public function test_figures_that_cannot_be_calculated_do_not_invent_a_percent()
    {
        $benchmarks = self::contractBenchmarks();
        $benchmarks['max_overhead_ratio'] = 9007199254740991;

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 1, 'has_polylang_switcher' => false, 'script_count' => 0),
            $benchmarks
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The maximum overhead ratio (max_overhead_ratio) must be from 0 through 1.'
        );
        $this->assertSame('', $estimate['faster_label']);
        $this->assertSame(0.0, $estimate['faster_percent']);
    }

    public function test_project_does_not_change_its_inputs_or_warn()
    {
        $measurement = array(
            'ok' => true,
            'total_ms' => 1000,
            'html' => '<p>SECRET_PAGE_MARKUP</p>',
        );
        $metrics = array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4);
        $benchmarks = self::contractBenchmarks();
        $measurement_before = $measurement;
        $metrics_before = $metrics;
        $benchmarks_before = $benchmarks;
        $warnings = array();

        set_error_handler(
            function ($severity, $message) use (&$warnings) {
                $warnings[] = $message;
                return true;
            }
        );
        try {
            $first = Speed_Estimate::project($measurement, $metrics, $benchmarks);
            $second = Speed_Estimate::project($measurement, array(), $benchmarks);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(array(), $warnings);
        $this->assertSame($measurement_before, $measurement);
        $this->assertSame($metrics_before, $metrics);
        $this->assertSame($benchmarks_before, $benchmarks);
        $this->assertSame($first, Speed_Estimate::project($measurement, $metrics, $benchmarks));
        $this->assertSame(971, $second['tolka_ms']);
        $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($first));
    }

    public function test_missing_coefficient_key_does_not_invent_a_percent()
    {
        $benchmarks = self::contractBenchmarks();
        unset($benchmarks['polylang_extra_ms_per_language']);

        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 5000, 'html' => '<p>SECRET_PAGE_MARKUP</p>'),
            array('language_count' => 4, 'has_polylang_switcher' => true, 'script_count' => 9),
            $benchmarks
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The benchmark file is missing a number for extra milliseconds per language (polylang_extra_ms_per_language).'
        );
        $this->assertSame(0, $estimate['current_ms']);
        $this->assertSame(0, $estimate['tolka_ms']);
        $this->assertSame(0, $estimate['overhead_ms']);
        $this->assertSame(0.0, $estimate['faster_percent']);
        $this->assertSame('', $estimate['faster_label']);
        $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($estimate));
        $this->assertArrayNotHasKey('polylang_extra_ms_per_language', $estimate['coefficients']);

        $listed = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
            array(35, 25, 8, 6, 0.7)
        );
        $this->assertUnavailable(
            $listed,
            'A Tolka speed estimate is not available. The benchmark file is missing numbers for extra milliseconds per language (polylang_extra_ms_per_language), language switcher milliseconds (polylang_switcher_ms), extra milliseconds per script (polylang_extra_ms_per_script), Tolka milliseconds per language (tolka_ms_per_language), and maximum overhead ratio (max_overhead_ratio).'
        );
        $this->assertSame(0.0, $listed['faster_percent']);
        $this->assertSame('', $listed['faster_label']);
        $this->assertNotSame(856, $listed['tolka_ms']);
        $this->assertNotSame(14.4, $listed['faster_percent']);

        $blank = self::contractBenchmarks();
        $blank['polylang_switcher_ms'] = '   ';
        $blank_estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
            $blank
        );
        $this->assertUnavailable(
            $blank_estimate,
            'A Tolka speed estimate is not available. The benchmark file has a value that is not a number for language switcher milliseconds (polylang_switcher_ms).'
        );
        $this->assertSame(0.0, $blank_estimate['faster_percent']);
        $this->assertSame('', $blank_estimate['faster_label']);
        $this->assertNotSame(11.9, $blank_estimate['faster_percent']);
        $this->assertNotSame(881, $blank_estimate['tolka_ms']);
    }

    public function test_non_numeric_script_count_does_not_invent_a_percent()
    {
        $estimate = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1800, 'html' => '<p>SECRET_PAGE_MARKUP</p>'),
            array(
                'language_count' => 3,
                'has_polylang_switcher' => true,
                'script_count' => '12 scripts',
            ),
            self::contractBenchmarks()
        );

        $this->assertUnavailable(
            $estimate,
            'A Tolka speed estimate is not available. The script count is not a usable number.'
        );
        $this->assertSame(0, $estimate['current_ms']);
        $this->assertSame(0, $estimate['tolka_ms']);
        $this->assertSame(0, $estimate['overhead_ms']);
        $this->assertSame(0.0, $estimate['faster_percent']);
        $this->assertSame('', $estimate['faster_label']);
        $this->assertNotSame(1592, $estimate['tolka_ms']);
        $this->assertNotSame(11.6, $estimate['faster_percent']);
        $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($estimate));

        $boolean = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1800),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => false),
            self::contractBenchmarks()
        );
        $this->assertUnavailable(
            $boolean,
            'A Tolka speed estimate is not available. The script count is not a usable number.'
        );
        $this->assertSame('', $boolean['faster_label']);

        $padded = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => ' 4 '),
            self::contractBenchmarks()
        );
        $this->assertTrue($padded['ok']);
        $this->assertSame(856, $padded['tolka_ms']);
        $this->assertSame(14.4, $padded['faster_percent']);
        $this->assertSame('14.4% faster', $padded['faster_label']);

        $comma = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => '4,0'),
            self::contractBenchmarks()
        );
        $this->assertUnavailable(
            $comma,
            'A Tolka speed estimate is not available. The script count is not a usable number.'
        );
        $this->assertSame(0.0, $comma['faster_percent']);
        $this->assertSame('', $comma['faster_label']);
        $this->assertNotSame(14.4, $comma['faster_percent']);
        $this->assertNotSame(43.2, $comma['faster_percent']);

        $overflow = Speed_Estimate::project(
            array('ok' => true, 'total_ms' => 1000),
            array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => '1e309'),
            self::contractBenchmarks()
        );
        $this->assertUnavailable(
            $overflow,
            'A Tolka speed estimate is not available. The script count is not a usable number.'
        );
        $this->assertSame(0.0, $overflow['faster_percent']);
        $this->assertSame('', $overflow['faster_label']);
    }

    public function test_benchmarks_returns_nothing_when_the_file_cannot_be_read()
    {
        $path = self::benchmarksPath();
        $before = file_get_contents($path);
        $this->assertIsString($before);
        $mode = fileperms($path);
        $this->assertNotFalse($mode);

        $this->assertTrue(chmod($path, 0000));
        clearstatcache(true, $path);
        try {
            $this->assertFalse(is_readable($path));
            $loaded = Speed_Estimate::benchmarks();
            $this->assertSame(array(), $loaded);

            $estimate = Speed_Estimate::project(
                array('ok' => true, 'total_ms' => 2500, 'html' => '<p>SECRET_PAGE_MARKUP</p>'),
                array('language_count' => 3, 'has_polylang_switcher' => true, 'script_count' => 4),
                $loaded
            );
            $this->assertUnavailable(
                $estimate,
                'A Tolka speed estimate is not available. The benchmark coefficients could not be read from includes/tolka/data/benchmarks.json. Check that the file exists and that this site can read it.'
            );
            $this->assertSame(0, $estimate['current_ms']);
            $this->assertSame(0, $estimate['tolka_ms']);
            $this->assertSame(0, $estimate['overhead_ms']);
            $this->assertSame(0.0, $estimate['faster_percent']);
            $this->assertSame('', $estimate['faster_label']);
            $this->assertStringNotContainsString('SECRET_PAGE_MARKUP', (string) json_encode($estimate));
        } finally {
            chmod($path, $mode & 0777);
            clearstatcache(true, $path);
        }

        $this->assertTrue(is_readable($path));
        $this->assertSame($before, file_get_contents($path));
        $restored = Speed_Estimate::benchmarks();
        $this->assertSame(35, $restored['polylang_extra_ms_per_language']);
        $this->assertSame(25, $restored['polylang_switcher_ms']);
        $this->assertSame(8, $restored['polylang_extra_ms_per_script']);
        $this->assertSame(6, $restored['tolka_ms_per_language']);
        $this->assertSame(0.7, $restored['max_overhead_ratio']);
    }

    public function test_benchmark_file_failures_return_no_coefficients()
    {
        $method = new \ReflectionMethod(Speed_Estimate::class, 'read_benchmarks');
        $method->setAccessible(true);
        $read = function ($path) use ($method) {
            return $method->invoke(null, $path);
        };

        $this->assertSame(array(), $read(self::benchmarksPath() . '.missing'));
        $this->assertSame(array(), $read(sys_get_temp_dir()));

        $directory = sys_get_temp_dir() . '/tolka-benchmarks-' . getmypid();
        mkdir($directory);
        try {
            $empty = $directory . '/empty.json';
            file_put_contents($empty, '');
            $this->assertSame(array(), $read($empty));

            $blank = $directory . '/blank.json';
            file_put_contents($blank, "  \n\t");
            $this->assertSame(array(), $read($blank));

            $list = $directory . '/list.json';
            file_put_contents($list, '[1, 2, 3]');
            $this->assertSame(array(), $read($list));

            $broken = $directory . '/broken.json';
            file_put_contents($broken, '{"id":');
            $this->assertSame(array(), $read($broken));

            $null = $directory . '/null.json';
            file_put_contents($null, 'null');
            $this->assertSame(array(), $read($null));

            $object = $directory . '/object.json';
            file_put_contents($object, '{}');
            $this->assertSame(array(), $read($object));

            $nested = $directory . '/nested.json';
            file_put_contents($nested, str_repeat('[', 40) . str_repeat(']', 40));
            $this->assertSame(array(), $read($nested));

            $bom = $directory . '/bom.json';
            file_put_contents($bom, "\xEF\xBB\xBF" . '{"id":"tolka-lab","provenance":"placeholder"}');
            $this->assertSame(
                array('id' => 'tolka-lab', 'provenance' => 'placeholder'),
                $read($bom)
            );

            $huge = $directory . '/huge.json';
            file_put_contents($huge, str_repeat('x', 1048577));
            $this->assertSame(array(), $read($huge));
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_failure_sentences_use_the_plugin_text_domain()
    {
        $script = '<?php' . "\n"
            . 'function __($text, $domain = "default") {' . "\n"
            . '    if ($domain !== "migration-manager") {' . "\n"
            . '        fwrite(STDERR, "domain=" . $domain);' . "\n"
            . '        exit(2);' . "\n"
            . '    }' . "\n"
            . '    return "TR:" . $text;' . "\n"
            . '}' . "\n"
            . 'define("ABSPATH", __DIR__);' . "\n"
            . 'require ' . var_export(self::classPath(), true) . ';' . "\n"
            . '$estimate = \\WMM\\Tolka\\Speed_Estimate::project(array("ok" => false, "total_ms" => 5000), array(), array());' . "\n"
            . '$with_url = \\WMM\\Tolka\\Speed_Estimate::project(array("ok" => false, "url" => "https://example.com/start"), array(), array());' . "\n"
            . 'echo $estimate["error"], "\n", $with_url["error"], "\n", $estimate["basis"], "\n", $estimate["faster_label"], "\n", $estimate["faster_percent"], "\n", $estimate["current_ms"];' . "\n";

        $path = tempnam(sys_get_temp_dir(), 'tolka-i18n-');
        file_put_contents($path, $script);
        $output = array();
        $exit_code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exit_code);
        unlink($path);

        $text = implode("\n", $output);
        $this->assertSame(0, $exit_code, $text);
        $this->assertSame(
            "TR:A Tolka speed estimate is not available. TR:The page could not be measured. TR:The benchmark coefficients could not be read from includes/tolka/data/benchmarks.json. Check that the file exists and that this site can read it.\n"
            . 'TR:A Tolka speed estimate is not available. TR:The page at https://example.com/start could not be measured. TR:The benchmark coefficients could not be read from includes/tolka/data/benchmarks.json. Check that the file exists and that this site can read it.' . "\n"
            . "Measured page load plus coefficients from benchmarks.json\n"
            . "\n"
            . "0\n"
            . '0',
            $text
        );
    }

    /**
     * Character length without requiring the mbstring extension.
     *
     * @param string $text
     * @return int
     */
    private static function characterLength($text)
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text);
        }

        $count = preg_match_all('/./u', $text, $matches);
        if ($count === false) {
            return strlen($text);
        }

        return (int) $count;
    }

    /**
     * Detail inside the measurement-failure parentheses or following sentence.
     *
     * @param string $error
     * @return string
     */
    private static function detailFrom($error)
    {
        $prefix = 'The page could not be measured. ';
        $position = strpos($error, $prefix);
        if ($position === false) {
            return '';
        }

        return substr($error, $position + strlen($prefix));
    }

    /**
     * @param array $estimate
     * @param array $expected
     * @param array $benchmarks
     */
    private function assertSuccess(array $estimate, array $expected, array $benchmarks)
    {
        $this->assertEstimateShape($estimate);
        $this->assertTrue($estimate['ok']);
        $this->assertSame($expected['current_ms'], $estimate['current_ms']);
        $this->assertSame($expected['tolka_ms'], $estimate['tolka_ms']);
        $this->assertSame($expected['overhead_ms'], $estimate['overhead_ms']);
        $this->assertSame($expected['faster_percent'], $estimate['faster_percent']);
        $this->assertSame($expected['faster_label'], $estimate['faster_label']);
        $this->assertSame('', $estimate['error']);
        $this->assertSame($benchmarks, $estimate['coefficients']);
        $this->assertSame(self::BASIS, $estimate['basis']);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d% faster$/', $estimate['faster_label']);
        $this->assertLessThanOrEqual($estimate['current_ms'], $estimate['tolka_ms']);
    }

    /**
     * @param array  $estimate
     * @param string $error
     */
    private function assertUnavailable(array $estimate, $error)
    {
        $this->assertEstimateShape($estimate);
        $this->assertFalse($estimate['ok']);
        $this->assertSame(0, $estimate['current_ms']);
        $this->assertSame(0, $estimate['tolka_ms']);
        $this->assertSame(0, $estimate['overhead_ms']);
        $this->assertSame(0.0, $estimate['faster_percent']);
        $this->assertSame('', $estimate['faster_label']);
        $this->assertSame(self::BASIS, $estimate['basis']);
        $this->assertSame($error, $estimate['error']);
        $this->assertMatchesRegularExpression('/[.!?]$/', $estimate['error']);
    }

    /**
     * @param array $estimate
     */
    private function assertEstimateShape(array $estimate)
    {
        $this->assertSame(
            array(
                'ok',
                'current_ms',
                'tolka_ms',
                'faster_percent',
                'faster_label',
                'overhead_ms',
                'basis',
                'provenance',
                'provenance_note',
                'coefficients',
                'error',
            ),
            array_keys($estimate)
        );
        $this->assertIsBool($estimate['ok']);
        $this->assertIsInt($estimate['current_ms']);
        $this->assertIsInt($estimate['tolka_ms']);
        $this->assertIsFloat($estimate['faster_percent']);
        $this->assertIsString($estimate['faster_label']);
        $this->assertIsInt($estimate['overhead_ms']);
        $this->assertIsString($estimate['basis']);
        $this->assertIsString($estimate['provenance']);
        $this->assertIsString($estimate['provenance_note']);
        $this->assertIsArray($estimate['coefficients']);
        $this->assertIsString($estimate['error']);
    }

    /**
     * @return array
     */
    private static function contractBenchmarks()
    {
        $decoded = json_decode((string) file_get_contents(self::benchmarksPath()), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('benchmarks.json could not be read.');
        }

        return $decoded;
    }

    /**
     * @return string
     */
    private static function benchmarksPath()
    {
        return dirname(__DIR__, 2) . '/includes/tolka/data/benchmarks.json';
    }

    /**
     * @return string
     */
    private static function classPath()
    {
        return dirname(__DIR__, 2) . '/includes/tolka/class-speed-estimate.php';
    }
}
