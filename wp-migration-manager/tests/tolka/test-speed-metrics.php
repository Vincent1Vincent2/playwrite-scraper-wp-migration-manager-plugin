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

    public function test_measurement_that_is_not_ok()
    {
        $result = $this->analyze_measurement(array(
            'ok' => false,
            'url' => 'https://example.com/',
            'status_code' => 500,
            'total_ms' => 1500,
            'ttfb_ms' => null,
            'html_bytes' => 0,
            'html' => '',
            'error' => 'Polylang blocked the request. The lang-item pll-switcher was not read.',
        ));

        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(0, $result['script_count']);
        $this->assertSame(0, $result['stylesheet_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertFalse($result['truncated']);
    }

    public function test_the_page_url_is_not_scanned_for_a_switcher()
    {
        $html = '<p>One language brochure.</p>';
        $result = $this->analyze($html, array(
            'ok' => false,
            'url' => 'https://example.com/polylang/lang-item/pll-switcher/',
            'error' => 'pll-parent-menu-item',
            'html_bytes' => strlen($html),
        ));

        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertFalse($result['truncated']);
    }

    public function test_not_ok_measurement_ignores_a_commented_switcher()
    {
        $html = '<!-- lang-item pll-switcher polylang pll-parent-menu-item -->';
        $result = $this->analyze($html, array(
            'ok' => false,
            'error' => 'The site did not respond.',
            'html_bytes' => strlen($html),
        ));

        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
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

    public function test_duplicate_hreflang_values_that_differ_only_by_case_count_once()
    {
        $html = <<<'HTML'
<link rel="alternate" href="https://example.com/" hreflang="en" />
<link rel="alternate" href="https://example.com/en/" hreflang="EN" />
<link rel="alternate" href="https://example.com/en-2/" hreflang="En" />
<link rel="alternate" href="https://example.com/en-3/" hreflang='EN' />
<link rel="alternate" href="https://example.com/us/" hreflang="en-US" />
<link rel="alternate" href="https://example.com/us-2/" hreflang="EN-us" />
<link rel="alternate" href="https://example.com/sv/" hreflang="sv" />
HTML;

        $result = $this->analyze($html);

        $this->assertSame(3, $result['hreflang_count']);
        $this->assertSame(3, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
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

    public function test_comments_do_not_add_assets_or_languages()
    {
        $html = <<<'HTML'
<!-- <script src="/old.js"></script> -->
<!-- <img src="/old.png"><link rel="stylesheet" href="/old.css"> -->
<!-- <link rel="alternate" hreflang="de" href="/de/"> -->
<img src="/live.png">
<link rel="stylesheet" href="/live.css">
HTML;

        $result = $this->analyze($html);

        $this->assertSame(0, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['stylesheet_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
    }

    public function test_switcher_marker_inside_an_html_comment_does_not_count()
    {
        $markers = array(
            'polylang',
            'pll-switcher',
            'lang-item',
            'pll-parent-menu-item',
        );

        foreach ($markers as $marker) {
            $html = "<!--\n<div class=\"" . strtoupper($marker) . "\"></div>\n-->";
            $result = $this->analyze($html);

            $this->assertFalse($result['has_polylang_switcher'], $marker);
            $this->assertSame(0, $result['hreflang_count'], $marker);
            $this->assertSame(1, $result['language_count'], $marker);
        }

        $commented_only = $this->analyze('<!-- /wp-content/plugins/polylang/js/frontend.js --><script src="/theme.js"></script>');
        $this->assertFalse($commented_only['has_polylang_switcher']);
        $this->assertSame(1, $commented_only['language_count']);
        $this->assertSame(1, $commented_only['script_count']);

        $live_after_comment = $this->analyze('<!-- <ul class="lang-item"></ul> --><nav class="pll-switcher"></nav>');
        $this->assertTrue($live_after_comment['has_polylang_switcher']);
        $this->assertSame(2, $live_after_comment['language_count']);

        $cut_off_comment = $this->analyze('<!-- <nav class="pll-switcher">');
        $this->assertFalse($cut_off_comment['has_polylang_switcher']);
        $this->assertSame(1, $cut_off_comment['language_count']);

        $inside_script = $this->analyze('<script>var note = "<!-- lang-item -->";</script><nav class="pll-switcher"></nav>');
        $this->assertTrue($inside_script['has_polylang_switcher']);
        $this->assertSame(1, $inside_script['script_count']);
        $this->assertSame(2, $inside_script['language_count']);

        $only_inside_script = $this->analyze('<script>var note = "<!-- pll-parent-menu-item -->";</script>');
        $this->assertFalse($only_inside_script['has_polylang_switcher']);
        $this->assertSame(1, $only_inside_script['language_count']);
        $this->assertSame(1, $only_inside_script['script_count']);
    }

    public function test_greater_than_inside_an_attribute_does_not_hide_the_rest()
    {
        $script = $this->analyze('<script data-note="a>b"></script><nav class="lang-item"></nav><img src="/shown.png">');
        $this->assertSame(1, $script['script_count']);
        $this->assertSame(1, $script['image_count']);
        $this->assertTrue($script['has_polylang_switcher']);
        $this->assertSame(2, $script['language_count']);

        $style = $this->analyze('<style data-note="a>b">x</style><link rel="stylesheet" href="/a.css"><img src="/a.png">');
        $this->assertSame(0, $style['script_count']);
        $this->assertSame(1, $style['stylesheet_count']);
        $this->assertSame(1, $style['image_count']);
        $this->assertFalse($style['has_polylang_switcher']);

        $hidden = $this->analyze('<script data-note="a>b"><img src="/hidden.png"></script><img src="/shown.png">');
        $this->assertSame(1, $hidden['script_count']);
        $this->assertSame(1, $hidden['image_count']);

        $language = $this->analyze('<a title="a>b" hreflang="sv" href="/sv/"></a>');
        $this->assertSame(1, $language['hreflang_count']);
        $this->assertSame(1, $language['language_count']);
    }

    public function test_comment_syntax_inside_an_attribute_is_not_a_switcher()
    {
        $html = '<div class="lang<!-- x -->-item"></div><link title="<!-- note -->" rel="stylesheet" href="/theme.css">';
        $result = $this->analyze($html);

        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(1, $result['stylesheet_count']);

        $live = $this->analyze('<div class="lang-item"></div>');
        $this->assertTrue($live['has_polylang_switcher']);
        $this->assertSame(2, $live['language_count']);
    }

    public function test_truncated_prefixes_ignore_a_commented_switcher()
    {
        $html = <<<'HTML'
<!-- POLYLANG pll-switcher lang-item pll-parent-menu-item -->
<link rel="stylesheet" href="/style.css">
<nav class="pll-switcher"></nav>
<link rel="alternate" hreflang="EN" href="/" />
<link rel="alternate" hreflang="en" href="/en/" />
<link rel="alternate" hreflang="sv" href="/sv/" />
HTML;
        $live = strpos($html, 'pll-switcher', strpos($html, '-->') + 3);
        $live_end = $live + strlen('pll-switcher');
        $length = strlen($html);
        $previous = null;

        for ($i = 0; $i <= $length; $i++) {
            $result = $this->analyze_measurement(array(
                'html' => substr($html, 0, $i),
                'html_bytes' => $length,
            ));

            if ($i === 0 || $i === $length) {
                $this->assertFalse($result['truncated'], 'byte ' . $i);
            } else {
                $this->assertTrue($result['truncated'], 'byte ' . $i);
            }

            if ($i < $live_end) {
                $this->assertFalse($result['has_polylang_switcher'], 'byte ' . $i);
                $this->assertSame(0, $result['hreflang_count'], 'byte ' . $i);
                $this->assertSame(1, $result['language_count'], 'byte ' . $i);
            } elseif ($result['hreflang_count'] === 0) {
                $this->assertTrue($result['has_polylang_switcher'], 'byte ' . $i);
                $this->assertSame(2, $result['language_count'], 'byte ' . $i);
            }

            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous['hreflang_count'], $result['hreflang_count'], 'byte ' . $i);
                $this->assertGreaterThanOrEqual($previous['stylesheet_count'], $result['stylesheet_count'], 'byte ' . $i);
                if ($previous['has_polylang_switcher']) {
                    $this->assertTrue($result['has_polylang_switcher'], 'byte ' . $i);
                }
            }

            $previous = $result;
        }

        $this->assertSame(2, $previous['hreflang_count']);
        $this->assertSame(2, $previous['language_count']);
        $this->assertTrue($previous['has_polylang_switcher']);
        $this->assertSame(1, $previous['stylesheet_count']);
    }

    public function test_unclosed_script_hides_the_rest_of_a_cut_body()
    {
        $html = '<script>var sample = "<img src=\'/fake.png\'><link rel=\'stylesheet\' href=\'/fake.css\'><a hreflang=\'zz\' href=\'/zz/\'>";';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['script_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertSame(0, $result['stylesheet_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_unclosed_comment_hides_the_rest_of_a_cut_body()
    {
        $html = '<!-- cut mid-comment <img src="/fake.png"><script src="/fake.js"></script><a hreflang="fr" href="/fr/">';
        $result = $this->analyze($html);

        $this->assertSame(0, $result['script_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
    }

    public function test_markup_after_a_closed_script_still_counts()
    {
        $html = '<script src="/a.js"></script><img src="/a.png"><link rel="stylesheet" href="/a.css">';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['stylesheet_count']);
    }

    public function test_noscript_images_are_not_part_of_the_scripted_page()
    {
        $result = $this->analyze('<noscript><img src="/fallback.png"><link rel="stylesheet" href="/fallback.css"></noscript><img src="/live.png">');

        $this->assertSame(0, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(0, $result['stylesheet_count']);
    }

    public function test_inert_markup_does_not_change_the_speed_counts()
    {
        $html = <<<'HTML'
<title>Northwind <img src="/in-title.png"></title>
<textarea><img src="/in-field.png"><a hreflang="ja" href="/ja/">JA</a></textarea>
<iframe src="/map"><img src="/in-frame.png"><script src="/in-frame.js"></script></iframe>
<template>
<script src="/not-run.js"></script>
<img src="/not-loaded.png">
<link rel="stylesheet" href="/not-loaded.css">
<a hreflang="ja" href="/ja/">JA</a>
</template>
<script src="/run.js"></script>
<img src="/loaded.png">
<link rel="stylesheet" href="/loaded.css">
<a hreflang="sv" href="/sv/">SV</a>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
        $this->assertSame(1, $result['stylesheet_count']);
        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_browser_ends_a_script_at_the_first_closing_tag()
    {
        $html = '<script>var end = "</script>";</script><img src="/after.png">';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['script_count']);
        $this->assertSame(1, $result['image_count']);
    }

    public function test_polylang_home_page_counts_live_languages_and_assets()
    {
        $result = $this->analyze($this->polylang_home_html());

        $this->assertSame(2, $result['hreflang_count']);
        $this->assertSame(2, $result['language_count']);
        $this->assertTrue($result['has_polylang_switcher']);
        $this->assertSame(3, $result['script_count']);
        $this->assertSame(2, $result['stylesheet_count']);
        $this->assertSame(2, $result['image_count']);
        $this->assertFalse($result['truncated']);
    }

    public function test_wordpress_head_counts_each_stylesheet_once()
    {
        $html = <<<'HTML'
<!doctype html><html lang=en><head>
<meta charset=utf-8>
<link rel=icon href=data:,>
<link rel="alternate" type="application/rss+xml" title="Feed" href="https://example.com/feed/" />
<link rel="alternate" type="application/json+oembed" href="https://example.com/embed.json" />
<link rel="https://api.w.org/" href="https://example.com/wp-json/" />
<link rel='stylesheet' id='theme-css' href='https://example.com/style.css?ver=6.6' media="print" onload="this.media='all'" />
<noscript><link rel='stylesheet' id='theme-css' href='https://example.com/style.css?ver=6.6' media='all' /></noscript>
<link href="https://cdn.example/font.css?family=a>b" rel="stylesheet">
<style>body{margin:0}</style>
<title>Studio</title>
</head><body>
<p>One language, no switcher.</p>
<script src=/theme.js></script>
</body></html>
HTML;

        $result = $this->analyze($html);

        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['hreflang_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(2, $result['stylesheet_count']);
        $this->assertSame(1, $result['script_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertFalse($result['truncated']);
    }

    public function test_hreflang_split_across_lines_still_counts()
    {
        $html = "<link\n\trel=\"alternate\"\n\threflang=\"sv\"\n\thref=\"/sv/\"\n>";
        $result = $this->analyze($html);

        $this->assertSame(1, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertSame(0, $result['stylesheet_count']);
    }

    public function test_mentioning_hreflang_in_text_does_not_add_a_language()
    {
        $html = <<<'HTML'
<p>The theme can add an hreflang="de" attribute for you.</p>
<a title='hreflang="fr"' href="/">Home</a>
<link title="rel=stylesheet" href="/not-a-stylesheet.css">
<link href="/theme.css" rel="stylesheet">
HTML;

        $result = $this->analyze($html);

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
        $this->assertSame(1, $result['stylesheet_count']);
    }

    public function test_blank_hreflang_is_not_a_language()
    {
        $html = '<link rel="alternate" hreflang="   " href="/"><link rel="alternate" hreflang="" href="/empty">';
        $result = $this->analyze($html);

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
    }

    public function test_data_hreflang_is_not_a_language()
    {
        $result = $this->analyze('<div data-hreflang="zz">Hello</div>');

        $this->assertSame(0, $result['hreflang_count']);
        $this->assertSame(1, $result['language_count']);
        $this->assertFalse($result['has_polylang_switcher']);
    }

    public function test_one_image_tag_counts_once_with_srcset()
    {
        $html = '<img src="/a.jpg" srcset="/a.jpg 1x, /a@2.jpg 2x" alt="">';
        $result = $this->analyze($html);

        $this->assertSame(1, $result['image_count']);
    }

    public function test_scientific_notation_and_negative_byte_counts_are_ignored()
    {
        $html = '<img src="/a.png">';

        $scientific = $this->analyze($html, array('html_bytes' => '2e6'));
        $negative = $this->analyze($html, array('html_bytes' => -1));
        $blank = $this->analyze($html, array('html_bytes' => ''));

        $this->assertFalse($scientific['truncated']);
        $this->assertFalse($negative['truncated']);
        $this->assertFalse($blank['truncated']);
        $this->assertSame(1, $scientific['image_count']);
    }

    public function test_prefixes_of_a_page_never_lose_a_completed_signal()
    {
        $html = $this->polylang_home_html();
        $length = strlen($html);
        $previous = null;

        for ($i = 0; $i <= $length; $i++) {
            $result = $this->analyze_measurement(array(
                'html' => substr($html, 0, $i),
                'html_bytes' => $length,
            ));

            if ($i === 0 || $i === $length) {
                $this->assertFalse($result['truncated'], 'byte ' . $i);
            } else {
                $this->assertTrue($result['truncated'], 'byte ' . $i);
            }

            if ($result['hreflang_count'] > 0) {
                $this->assertSame($result['hreflang_count'], $result['language_count'], 'byte ' . $i);
            } elseif ($result['has_polylang_switcher']) {
                $this->assertSame(2, $result['language_count'], 'byte ' . $i);
            } else {
                $this->assertSame(1, $result['language_count'], 'byte ' . $i);
            }

            if ($previous === null) {
                $previous = $result;
                continue;
            }

            $this->assertGreaterThanOrEqual($previous['hreflang_count'], $result['hreflang_count'], 'hreflang at byte ' . $i);
            $this->assertGreaterThanOrEqual($previous['script_count'], $result['script_count'], 'script at byte ' . $i);
            $this->assertGreaterThanOrEqual($previous['stylesheet_count'], $result['stylesheet_count'], 'stylesheet at byte ' . $i);
            $this->assertGreaterThanOrEqual($previous['image_count'], $result['image_count'], 'image at byte ' . $i);

            if ($previous['has_polylang_switcher']) {
                $this->assertTrue($result['has_polylang_switcher'], 'switcher at byte ' . $i);
            }

            $previous = $result;
        }

        $this->assertSame(2, $previous['language_count']);
        $this->assertSame(2, $previous['hreflang_count']);
    }

    public function test_corrupt_html_does_not_fatal()
    {
        $state = 20261104;

        for ($n = 0; $n < 80; $n++) {
            $length = $state % 240;
            $html = '';

            for ($i = 0; $i < $length; $i++) {
                $state = (int) (($state * 1103515245 + 12345) & 0x7fffffff);
                $html .= chr($state % 256);
            }

            $state = (int) (($state * 1103515245 + 12345) & 0x7fffffff);
            $result = $this->analyze_measurement(array(
                'html' => $html,
                'html_bytes' => ($state % 400) - 5,
                'truncated' => ($state % 5 === 0) ? 'false' : ($state % 2 === 0),
                'error' => 'The response was cut off.',
            ));

            if ($result['hreflang_count'] > 0) {
                $this->assertSame($result['hreflang_count'], $result['language_count'], 'case ' . $n);
            } elseif ($result['has_polylang_switcher']) {
                $this->assertSame(2, $result['language_count'], 'case ' . $n);
            } else {
                $this->assertSame(1, $result['language_count'], 'case ' . $n);
            }
        }
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
     * A small Polylang front page: assets first, then live hreflang.
     *
     * The retired German alternate is inside a comment. Counting it would
     * tell the site owner the page has a language it does not serve.
     *
     * @return string
     */
    private function polylang_home_html()
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en-US">
<head>
<meta charset="utf-8">
<title>Northwind Studio <img src="/not-in-the-title.png"></title>
<textarea><img src="/not-in-the-field.png"><a hreflang="ja" href="/ja/">JA</a></textarea>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/wp-content/themes/northwind/style.css">
<link rel="stylesheet" href="/wp-content/plugins/polylang/css/frontend.css?ver=3.6.2">
<script src="/wp-includes/js/jquery/jquery.min.js"></script>
<script src="/wp-content/themes/northwind/theme.js"></script>
<script src="/wp-content/plugins/polylang/js/frontend.js?ver=3.6.2"></script>
<!-- retired translation
<link rel="alternate" hreflang="de" href="https://example.com/de/" />
<script src="/old.js"></script>
<img src="/old.png">
-->
<link rel="alternate" href="https://example.com/" hreflang="en" />
<link rel="alternate" href="https://example.com/sv/" hreflang="sv" />
<link rel="alternate" href="https://example.com/" hreflang="x-default" />
</head>
<body>
<header>
<img src="/logo.svg" alt="Northwind">
<nav><ul><li class="pll-parent-menu-item"><a href="/">English</a>
<ul class="sub-menu"><li class="lang-item lang-item-sv"><a href="/sv/" hreflang="sv">Svenska</a></li></ul>
</li></ul></nav>
</header>
<img src="/hero.jpg" alt="">
</body>
</html>
HTML;
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
