<?php

/**
 * Tolka speed metrics.
 *
 * Counts language and asset signals on a page the speed loader measured.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a measured HTML document into the counts the Tolka estimate uses.
 */
class Speed_Metrics
{
    /**
     * The loader keeps at most this many HTML bytes.
     */
    const HTML_BYTE_CAP = 2000000;

    /**
     * Count languages and assets on a measured page.
     *
     * A page with no hreflang counts as one language, or two when a Polylang
     * switcher is present. Unique hreflang values other than x-default replace
     * that fallback. Empty or failed measurements return that one-language
     * result and do not throw.
     *
     * The result is truncated when the measurement says so, when the HTML
     * reaches the 2,000,000 byte cap, or when a non-empty body is shorter
     * than a reported html_bytes value. Bytes past the cap are not counted.
     *
     * @param array $measurement Result from Speed_Loader::measure.
     * @return array{
     *     language_count:int,
     *     hreflang_count:int,
     *     script_count:int,
     *     stylesheet_count:int,
     *     image_count:int,
     *     has_polylang_switcher:bool,
     *     truncated:bool
     * }
     */
    public static function analyze(array $measurement)
    {
        $html = self::html_string($measurement);
        $received_bytes = strlen($html);
        $truncated = self::is_truncated($measurement, $html, $received_bytes);

        if ($received_bytes > self::HTML_BYTE_CAP) {
            $html = substr($html, 0, self::HTML_BYTE_CAP);
            $truncated = true;
        }

        $lower = self::ascii_lower($html);
        $has_switcher = self::has_polylang_switcher($lower);
        $markup = self::hide_embedded_text($lower);
        $hreflang_count = self::unique_hreflang_count($markup);

        if ($hreflang_count > 0) {
            $language_count = $hreflang_count;
        } elseif ($has_switcher) {
            $language_count = 2;
        } else {
            $language_count = 1;
        }

        return array(
            'language_count' => $language_count,
            'hreflang_count' => $hreflang_count,
            'script_count' => substr_count($lower, '<script'),
            'stylesheet_count' => self::count_stylesheets($markup),
            'image_count' => substr_count($markup, '<img'),
            'has_polylang_switcher' => $has_switcher,
            'truncated' => $truncated,
        );
    }

    /**
     * HTML body from the measurement, or an empty string when it is missing.
     *
     * @param array $measurement Measured page.
     * @return string
     */
    private static function html_string(array $measurement)
    {
        if (!isset($measurement['html']) || !is_string($measurement['html'])) {
            return '';
        }

        return $measurement['html'];
    }

    /**
     * Whether the body is an incomplete copy of the page.
     *
     * An empty body is a failed or blank measurement, not a truncated page,
     * unless the caller sets truncated itself or the cap was reached.
     *
     * @param array  $measurement Measured page.
     * @param string $html HTML that was received.
     * @param int    $received_bytes Byte length of that HTML.
     * @return bool
     */
    private static function is_truncated(array $measurement, $html, $received_bytes)
    {
        if (isset($measurement['truncated']) && self::flag_is_true($measurement['truncated'])) {
            return true;
        }

        if ($received_bytes >= self::HTML_BYTE_CAP) {
            return true;
        }

        $reported = self::reported_html_bytes($measurement);
        if ($html !== '' && $reported !== null && $reported > $received_bytes) {
            return true;
        }

        return false;
    }

    /**
     * Accept only real boolean-like flags so the string "false" is not true.
     *
     * @param mixed $value Raw flag.
     * @return bool
     */
    private static function flag_is_true($value)
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Whole-byte html_bytes, or null when the field is absent or not a count.
     *
     * @param array $measurement Measured page.
     * @return int|null
     */
    private static function reported_html_bytes(array $measurement)
    {
        if (!array_key_exists('html_bytes', $measurement)) {
            return null;
        }

        $value = $measurement['html_bytes'];

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value) && is_finite($value) && $value >= 0) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Drop the contents of closed script and style elements.
     *
     * Sample markup in those elements is not a request the browser will make.
     * Switcher markers and script tags are counted on the original HTML, so a
     * Polylang script URL still counts. An unclosed element is left in place:
     * a truncated page must not lose the tags that follow a cut-off end tag.
     *
     * @param string $lower_html Lowercased HTML.
     * @return string
     */
    private static function hide_embedded_text($lower_html)
    {
        if ($lower_html === '') {
            return '';
        }

        $without_scripts = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/s', ' ', $lower_html);

        if (!is_string($without_scripts)) {
            $without_scripts = $lower_html;
        }

        $without_styles = preg_replace('/<style\b[^>]*>.*?<\/style\s*>/s', ' ', $without_scripts);

        if (!is_string($without_styles)) {
            return $without_scripts;
        }

        return $without_styles;
    }

    /**
     * ASCII case fold that does not depend on the server locale.
     *
     * @param string $html HTML bytes.
     * @return string
     */
    private static function ascii_lower($html)
    {
        return strtr(
            $html,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz'
        );
    }

    /**
     * Unique hreflang values, excluding x-default.
     *
     * Comparison is case-insensitive. A value is counted only when its quotes
     * are closed, or when an unquoted value is followed by whitespace or the
     * end of the tag. A cut at the loader cap therefore does not invent a
     * language from a half-written attribute.
     *
     * @param string $lower_html Lowercased HTML.
     * @return int
     */
    private static function unique_hreflang_count($lower_html)
    {
        if ($lower_html === '') {
            return 0;
        }

        $values = array();

        if (preg_match_all('/(?:^|[\s<])hreflang\s*=\s*"([^"]*)"/', $lower_html, $doubles)) {
            foreach ($doubles[1] as $value) {
                self::remember_hreflang($value, $values);
            }
        }

        if (preg_match_all('/(?:^|[\s<])hreflang\s*=\s*\'([^\']*)\'/', $lower_html, $singles)) {
            foreach ($singles[1] as $value) {
                self::remember_hreflang($value, $values);
            }
        }

        if (preg_match_all('/(?:^|[\s<])hreflang\s*=\s*([^\s"\'=<>`\/]+)(?=[\s>\/])/', $lower_html, $bare)) {
            foreach ($bare[1] as $value) {
                self::remember_hreflang($value, $values);
            }
        }

        return count($values);
    }

    /**
     * Keep one language code, dropping blanks and x-default.
     *
     * @param string $value Raw attribute value.
     * @param array  $values Set of codes already seen.
     * @return void
     */
    private static function remember_hreflang($value, array &$values)
    {
        $value = trim($value);

        if ($value === '' || $value === 'x-default') {
            return;
        }

        $values[$value] = true;
    }

    /**
     * Whether the HTML contains a Polylang switcher marker.
     *
     * Markers, matched case-insensitively, are polylang, pll-switcher,
     * lang-item, and pll-parent-menu-item.
     *
     * @param string $lower_html Lowercased HTML.
     * @return bool
     */
    private static function has_polylang_switcher($lower_html)
    {
        if ($lower_html === '') {
            return false;
        }

        $markers = array(
            'polylang',
            'pll-switcher',
            'lang-item',
            'pll-parent-menu-item',
        );

        foreach ($markers as $marker) {
            if (strpos($lower_html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Link tags whose rel includes the stylesheet token.
     *
     * The last tag still counts when a truncated body cuts it off after a
     * complete rel attribute.
     *
     * @param string $lower_html Lowercased HTML.
     * @return int
     */
    private static function count_stylesheets($lower_html)
    {
        if ($lower_html === '' || !preg_match_all('/<link\b[^>]*(?:>|$)/', $lower_html, $tags)) {
            return 0;
        }

        $count = 0;

        foreach ($tags[0] as $tag) {
            if (self::rel_has_stylesheet($tag)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether one link tag loads a stylesheet.
     *
     * @param string $tag Lowercased link tag, possibly unclosed.
     * @return bool
     */
    private static function rel_has_stylesheet($tag)
    {
        $rel = null;

        if (preg_match('/(?:^|\s)rel\s*=\s*"([^"]*)"/', $tag, $match)) {
            $rel = $match[1];
        } elseif (preg_match('/(?:^|\s)rel\s*=\s*\'([^\']*)\'/', $tag, $match)) {
            $rel = $match[1];
        } elseif (preg_match('/(?:^|\s)rel\s*=\s*([^\s"\'=<>`\/]+)/', $tag, $match)) {
            $rel = $match[1];
        }

        if ($rel === null) {
            return false;
        }

        $tokens = preg_split('/\s+/', trim($rel), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens)) {
            return false;
        }

        return in_array('stylesheet', $tokens, true);
    }
}
