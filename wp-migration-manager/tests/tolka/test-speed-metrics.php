<?php

/**
 * Speed_Metrics tests.
 *
 * The shared Tolka bootstrap may already have loaded the class. When it has
 * not, this file loads it so the cases can run on their own.
 *
 * @package MigrationManager
 */

if (!class_exists('WMM\\Tolka\\Speed_Metrics')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . '/');
    }

    $wmm_tolka_speed_metrics_file = dirname(__DIR__, 2) . '/includes/tolka/class-speed-metrics.php';

    if (is_readable($wmm_tolka_speed_metrics_file)) {
        require_once $wmm_tolka_speed_metrics_file;
    }
}

use PHPUnit\Framework\TestCase;

/**
 * Pages with no hreflang, truncated HTML, and the other analyzer counts.
 */
class Speed_Metrics_Test extends TestCase
{
    /**
     * Skip the suite when this slice is not loaded.
     */
    protected function setUp(): void
    {
        if (!class_exists('WMM\\Tolka\\Speed_Metrics')) {
            $this->markTestSkipped('Speed_Metrics is not loaded.');
        }
    }

    public function test_empty_measurement_is_one_language_and_not_truncated()
    {
        $result = $this->analyze_measurement(array());

        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(0, $result['script_count']);
        $this->assertSame(0, $result['stylesheet_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertFalse($result['truncated']);
    }

    public function test_failed_measurement_with_empty_html_stays_empty()
    {
        $result = $this->analyze_measurement(array(
            'ok' => false,
            'url' => 'https://example.com/',
            'status_code' => 0,
            'total_ms' => 0,
            'ttfb_ms' => null,
            'html_bytes' => 0,
            'html' => '',
            'error' => 'The site did not respond.',
        ));

        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertFalse($result['truncated']);
    }

    public function test_failed_measurement_with_a_byte_count_but_no_html_is_not_truncated()
    {
        $result = $this->analyze_measurement(array(
            'ok' => false,
            'html' => '',
            'html_bytes' => 99999,
            'error' => 'The response body was discarded.',
        ));

        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['image_count']);
    }

    public function test_missing_or_non_string_html_does_not_fatal()
    {
        $missing = $this->analyze_measurement(array('ok' => true));
        $null_html = $this->analyze_measurement(array('html' => null));
        $array_html = $this->analyze_measurement(array('html' => array('<img>')));

        $this->assertSame(1, $missing['language_count']);
        $this->assertSame(0, $null_html['image_count']);
        $this->assertSame(0, $array_html['image_count']);
        $this->assertFalse($null_html['truncated']);
    }

    public function test_page_with_no_hreflang_counts_one_language_and_its_assets()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Northwind Studio</title>
<link rel="icon" href="/favicon.ico">
<link rel="preload" as="style" href="/fonts.css">
<link rel="stylesheet" href="/style.css">
<link href="/print.css" rel="stylesheet" media="print">
<script src="/theme.js"></script>
</head>
<body>
<h1>Northwind Studio</h1>
<img src="/hero.jpg" alt="">
<img src="/logo.svg" alt="">
<script>var ready = true;</script>
</body>
</html>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(2, $result['script_count']);
        $this->assertSame(2, $result['stylesheet_count']);
        $this->assertSame(2, $result['image_count']);
        $this->assertFalse($result['truncated']);
    }

    public function test_words_that_only_look_like_a_switcher_do_not_count()
    {
        $html = '<p>A language item and a polar lang menu are not a switcher.</p><img src="/a.png">';
        $result = $this->analyze($html);

        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(1, $result['image_count']);
    }

    public function test_each_switcher_marker_without_hreflang_counts_as_two_languages()
    {
        $markers = array(
            'polylang',
            'pll-switcher',
            'lang-item',
            'pll-parent-menu-item',
        );

        foreach ($markers as $marker) {
            $result = $this->analyze('<div class="' . strtoupper($marker) . '"></div>');

            $this->assertTrue($result['has_polylang_switcher'], $marker);
            $this->assertSame(0, $result['hreflang_count'], $marker);
            $this->assertSame(2, $result['language_count'], $marker);
            $this->assertFalse($result['truncated'], $marker);
        }
    }

    public function test_polylang_asset_url_without_hreflang_counts_as_two_languages()
    {
        $html = '<script src="/wp-content/plugins/polylang/js/frontend.js?ver=3.6"></script>';
        $result = $this->analyze($html);

        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(2, $result['language_count']);
        $this->assertSame(1, $result['script_count']);
    }

    public function test_menu_switcher_without_hreflang_counts_as_two_languages()
    {
        $html = <<<'HTML'
<nav>
<ul>
<li class="pll-parent-menu-item menu-item">
<a href="/">English</a>
<ul class="sub-menu">
<li class="lang-item lang-item-sv"><a href="/sv/">Svenska</a></li>
</ul>
</li>
</ul>
</nav>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(2, $result['language_count']);
    }

    public function test_only_x_default_falls_back_like_a_page_with_no_hreflang()
    {
        $html = '<link rel="alternate" href="https://example.com/" hreflang="x-default" />';
        $result = $this->analyze($html);

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
    }

    public function test_only_x_default_with_a_switcher_counts_as_two_languages()
    {
        $html = '<link rel="alternate" hreflang="X-Default" href="https://example.com/" class="pll-switcher">';
        $result = $this->analyze($html);

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(2, $result['language_count']);
    }

    public function test_hreflang_values_are_unique_and_skip_x_default()
    {
        $html = <<<'HTML'
<link rel="alternate" href="https://example.com/" hreflang="en" />
<link rel="alternate" href="https://example.com/en-dup/" hreflang="EN" />
<link rel="alternate" href="https://example.com/sv/" hreflang="sv" />
<link rel="alternate" hreflang="x-default" href="https://example.com/" />
<link rel="alternate" hreflang="X-Default" href="https://example.com/" />
<link rel="alternate" hreflang='fr' href="https://example.com/fr/" />
<a hreflang=de href="https://example.com/de/">DE</a>
<a hreflang=" en-GB ">English (UK)</a>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(5, $result['hreflang_count']);
        $this->assertSame(5, $result['language_count']);
    }

    public function test_one_hreflang_wins_over_the_switcher_fallback()
    {
        $html = '<ul class="lang-item"><a hreflang="sv" href="/sv/">SV</a></ul>';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertTrue($result['has_polylang_switcher']);
    }

    public function test_repeated_switcher_hreflang_counts_once_per_language()
    {
        $html = <<<'HTML'
<link rel="alternate" href="https://example.com/" hreflang="en" />
<link rel="alternate" href="https://example.com/sv/" hreflang="sv" />
<link rel="alternate" href="https://example.com/" hreflang="x-default" />
<ul class="pll-switcher">
<li class="lang-item"><a hreflang="en" href="https://example.com/">English</a></li>
<li class="lang-item"><a hreflang="sv" href="https://example.com/sv/">Svenska</a></li>
</ul>
<script src="/wp-content/plugins/polylang/js/frontend.js"></script>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(2, $result['hreflang_count']);
        $this->assertSame(2, $result['language_count']);
        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(1, $result['script_count']);
    }

    public function test_region_codes_stay_distinct()
    {
        $html = '<link hreflang="en" href="/"><link hreflang="en-us" href="/us/"><link hreflang="en_US" href="/us2/">';
        $result = $this->analyze($html);

        $this->assertSame(3, $result['hreflang_count']);
        $this->assertSame(3, $result['language_count']);
    }

    public function test_half_written_hreflang_does_not_invent_a_language()
    {
        $html = '<link rel="alternate" hreflang="en" href="/"><link rel="alternate" hreflang="fr';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_script_and_image_markers_are_case_insensitive_tag_prefixes()
    {
        $html = '<script src="a.js"></script><SCRIPT>var n = 1;</SCRIPT><img src="1.png"><IMG src="2.png"><img><image href="x.svg"></image>';
        $result = $this->analyze($html);

        $this->assertSame(2, $result['script_count']);
        $this->assertSame(3, $result['image_count']);
    }

    public function test_stylesheet_links_ignore_preload_icons_and_the_word_in_a_script()
    {
        $html = <<<'HTML'
<link rel="stylesheet" href="/a.css" />
<link rel='stylesheet' href='/b.css'>
<link href="/c.css" rel="stylesheet">
<link
    rel="stylesheet"
    href="/d.css">
<link REL="STYLESHEET" href="/e.css">
<link rel=stylesheet href=/f.css>
<link rel="alternate stylesheet" href="/g.css">
<link rel="preload" as="style" href="/h.css">
<link rel="icon" href="/favicon.ico">
<link rel="preload" href="/i.css" as="style" onload="this.rel='stylesheet'">
<link rel="icon" href="/stylesheet.css">
<script>var tag = '<link rel="stylesheet" href="/not-a-request.js">';</script>
<style>body{background:url(/bg.png)}</style>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(7, $result['stylesheet_count']);
        $this->assertSame(1, $result['script_count']);
        $this->assertSame(0, $result['image_count']);
    }

    public function test_sample_markup_inside_script_or_style_is_not_a_page_asset()
    {
        $html = <<<'HTML'
<script>var sample = '<img src="/fake.png"><link rel="stylesheet" href="/fake.css"><a hreflang="zz" href="/zz/">';</script>
<style>/* <img src="/also-fake.png"><link rel="stylesheet" href="/also-fake.css"> */</style>
<img src="/real.png">
<link rel="stylesheet" href="/real.css">
HTML;

        $result = $this->analyze($html);

        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['stylesheet_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_duplicate_stylesheet_links_each_count()
    {
        $html = '<link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="/a.css">';
        $result = $this->analyze($html);

        $this->assertSame(2, $result['stylesheet_count']);
    }

    public function test_html_at_the_byte_cap_is_truncated_and_still_counted()
    {
        $prefix = '<img src="/keep.png"><script src="/keep.js"></script><link rel="stylesheet" href="/keep.css"><link rel="alternate" hreflang="sv" href="/sv/">';
        $html = $prefix . str_repeat('x', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($prefix));

        $this->assertSame(2000000, strlen($html));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['stylesheet_count']);
        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_one_byte_under_the_cap_is_not_truncated()
    {
        $html = '<p>Short page</p>' . str_repeat('a', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen('<p>Short page</p>') - 1);

        $this->assertSame(1999999, strlen($html));

        $result = $this->analyze($html);

        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
    }

    public function test_utf8_text_uses_bytes_for_the_cap()
    {
        $html = str_repeat("\xC3\xA5", 1000000);

        $this->assertSame(2000000, strlen($html));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
    }

    public function test_bytes_past_the_cap_are_not_counted()
    {
        $head = '<img src="/keep.png"><script src="/keep.js"></script><link rel="stylesheet" href="/keep.css">';
        $tail = '<img src="/drop.png"><script src="/drop.js"></script><link rel="stylesheet" href="/drop.css"><a hreflang="fr" href="/fr/">FR</a>';
        $html = $head . str_repeat('a', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($head)) . $tail;

        $this->assertGreaterThan(2000000, strlen($html));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['stylesheet_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_truncated_page_with_no_hreflang_keeps_the_fallback()
    {
        $switcher = '<ul class="pll-switcher"><li class="lang-item">Svenska</li></ul>';
        $html = $switcher . str_repeat(' ', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($switcher));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(2, $result['language_count']);
    }

    public function test_cut_inside_an_hreflang_attribute_does_not_invent_a_language()
    {
        $complete = '<link rel="alternate" hreflang="en" href="https://example.com/">';
        $partial = '<link rel="alternate" hreflang="fr';
        $gap = \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($complete) - strlen($partial);
        $html = $complete . str_repeat(' ', $gap) . $partial;

        $this->assertSame(2000000, strlen($html));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_cut_inside_a_script_token_does_not_count_that_script()
    {
        $complete = '<script src="/keep.js"></script>';
        $partial = '<scri';
        $gap = \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($complete) - strlen($partial);
        $html = $complete . str_repeat(' ', $gap) . $partial;

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['script_count']);
    }

    public function test_unclosed_stylesheet_link_at_the_cap_still_counts()
    {
        $open = '<link rel="stylesheet" href="/cut.css"';
        $html = $open . str_repeat(' ', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($open));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['stylesheet_count']);
    }

    public function test_unclosed_stylesheet_rel_does_not_count()
    {
        $open = '<link rel="styleshe';
        $html = $open . str_repeat(' ', \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP - strlen($open));

        $result = $this->analyze($html);

        $this->assertTrue($result['truncated']);
        $this->assertSame(0, $result['stylesheet_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_html_bytes_larger_than_a_short_body_is_truncated()
    {
        $html = '<img src="/partial.png">';
        $result = $this->analyze($html, array(
            'html_bytes' => strlen($html) + 40,
        ));

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_html_bytes_may_be_a_numeric_string()
    {
        $html = '<img src="/partial.png">';
        $result = $this->analyze($html, array(
            'html_bytes' => (string) (strlen($html) + 1),
        ));

        $this->assertTrue($result['truncated']);
    }

    public function test_matching_html_bytes_under_the_cap_is_not_truncated()
    {
        $html = '<img src="/full.png">';
        $result = $this->analyze($html, array(
            'html_bytes' => strlen($html),
            'ok' => true,
            'error' => '',
        ));

        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $result['image_count']);
    }

    public function test_explicit_truncated_flag_is_kept()
    {
        $result = $this->analyze('<img src="/a.png">', array(
            'truncated' => true,
            'html_bytes' => strlen('<img src="/a.png">'),
        ));

        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['image_count']);
    }

    public function test_string_false_is_not_an_explicit_truncated_flag()
    {
        $result = $this->analyze('<img src="/a.png">', array(
            'truncated' => 'false',
        ));

        $this->assertFalse($result['truncated']);
    }

    public function test_analyze_prints_nothing()
    {
        ob_start();
        $result = \WMM\Tolka\Speed_Metrics::analyze(array(
            'html' => '<img src="/a.png"><script src="/a.js"></script>',
        ));
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['script_count']);
    }

    public function test_loader_cap_constant_is_two_million_bytes()
    {
        $this->assertSame(2000000, \WMM\Tolka\Speed_Metrics::HTML_BYTE_CAP);
    }

    public function test_result_repeats_for_the_same_measurement()
    {
        $measurement = array(
            'ok' => true,
            'html' => '<link rel="alternate" hreflang="de" href="/de/"><script src="/a.js"></script>',
            'html_bytes' => 80,
        );

        $this->assertSame(
            \WMM\Tolka\Speed_Metrics::analyze($measurement),
            \WMM\Tolka\Speed_Metrics::analyze($measurement)
        );
    }

    /**
     * Analyze an HTML string and require the contract shape.
     *
     * @param string $html Page HTML.
     * @param array  $extra Extra measurement fields.
     * @return array
     */
    private function analyze($html, array $extra = array())
    {
        if (!array_key_exists('html', $extra)) {
            $extra['html'] = $html;
        }

        return $this->analyze_measurement($extra);
    }

    /**
     * Analyze a measurement and require the contract shape.
     *
     * @param array $measurement Measured page.
     * @return array
     */
    private function analyze_measurement(array $measurement)
    {
        $result = \WMM\Tolka\Speed_Metrics::analyze($measurement);
        $this->assert_contract_shape($result);

        return $result;
    }

    /**
     * The seven keys the estimate and the wizard share.
     *
     * @param array $result Analyzer result.
     */
    private function assert_contract_shape(array $result)
    {
        $this->assertSame(
            array(
                'language_count',
                'hreflang_count',
                'script_count',
                'stylesheet_count',
                'image_count',
                'has_polylang_switcher',
                'truncated',
            ),
            array_keys($result)
        );

        $this->assertTrue(is_int($result['language_count']));
        $this->assertTrue(is_int($result['hreflang_count']));
        $this->assertTrue(is_int($result['script_count']));
        $this->assertTrue(is_int($result['stylesheet_count']));
        $this->assertTrue(is_int($result['image_count']));
        $this->assertTrue(is_bool($result['has_polylang_switcher']));
        $this->assertTrue(is_bool($result['truncated']));
        $this->assertGreaterThanOrEqual(1, $result['language_count']);
    }
}
