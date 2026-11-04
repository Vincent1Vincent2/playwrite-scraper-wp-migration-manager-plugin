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
     * Script, image, stylesheet, and hreflang signals come from markup a
     * browser would parse. HTML comments are ignored. The inside of a script
     * or style element is ignored, and an unclosed comment, script, or style
     * hides the rest of the body. That is the shape of a page cut off inside
     * one of those. Switcher markers are read from the whole HTML string.
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
        $visible = self::visible_markup($lower);
        $hreflang_count = self::unique_hreflang_count($visible['markup']);
        $has_switcher = self::has_polylang_switcher($lower);

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
            'script_count' => $visible['scripts'],
            'stylesheet_count' => self::count_stylesheets($visible['markup']),
            'image_count' => self::count_tags($visible['markup'], 'img'),
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
     * Markup with comments and embedded raw text removed, plus a script count.
     *
     * An unclosed comment, script, or style consumes the rest of the string.
     * A truncated measurement that was cut inside one of those does not grow
     * extra languages or assets from the unfinished tail.
     *
     * @param string $lower_html Lowercased HTML.
     * @return array{markup:string,scripts:int}
     */
    private static function visible_markup($lower_html)
    {
        $length = strlen($lower_html);
        $chunks = array();
        $scripts = 0;
        $i = 0;

        while ($i < $length) {
            $next = strpos($lower_html, '<', $i);

            if ($next === false) {
                $chunks[] = substr($lower_html, $i);
                break;
            }

            if ($next > $i) {
                $chunks[] = substr($lower_html, $i, $next - $i);
            }

            if (substr($lower_html, $next, 4) === '<!--') {
                $end = strpos($lower_html, '-->', $next + 4);

                if ($end === false) {
                    break;
                }

                $i = $end + 3;
                continue;
            }

            if (self::opens_element($lower_html, $next, 'script')) {
                $scripts++;
                $i = self::skip_element($lower_html, $next, 'script');
                continue;
            }

            if (self::opens_element($lower_html, $next, 'style')) {
                $i = self::skip_element($lower_html, $next, 'style');
                continue;
            }

            $chunks[] = '<';
            $i = $next + 1;
        }

        return array(
            'markup' => implode('', $chunks),
            'scripts' => $scripts,
        );
    }

    /**
     * Whether position $i starts an HTML element of the given name.
     *
     * The name must end at the string, or be followed by a tag boundary, so
     * a cut at "<scri" is not a script and "<image" is not an image.
     *
     * @param string $html Lowercased HTML.
     * @param int    $i Byte offset.
     * @param string $name Lowercased element name.
     * @return bool
     */
    private static function opens_element($html, $i, $name)
    {
        $open = '<' . $name;
        $length = strlen($open);

        if (substr($html, $i, $length) !== $open) {
            return false;
        }

        return self::is_tag_boundary($html, $i + $length);
    }

    /**
     * Move past an element, or to the end when the closer was cut off.
     *
     * @param string $html Lowercased HTML.
     * @param int    $i Offset of the opening "<".
     * @param string $name Lowercased element name.
     * @return int
     */
    private static function skip_element($html, $i, $name)
    {
        $length = strlen($html);
        $gt = strpos($html, '>', $i);

        if ($gt === false) {
            return $length;
        }

        $close = '</' . $name;
        $close_length = strlen($close);
        $pos = $gt + 1;

        while (($end = strpos($html, $close, $pos)) !== false) {
            $j = $end + $close_length;

            while ($j < $length && self::is_ascii_space($html[$j])) {
                $j++;
            }

            if ($j >= $length) {
                return $length;
            }

            if ($html[$j] === '>') {
                return $j + 1;
            }

            $pos = $end + 1;
        }

        return $length;
    }

    /**
     * A character that can follow a tag name.
     *
     * @param string $html Lowercased HTML.
     * @param int    $after Offset just after the tag name.
     * @return bool
     */
    private static function is_tag_boundary($html, $after)
    {
        if ($after >= strlen($html)) {
            return true;
        }

        $next = $html[$after];

        return $next === '>' || $next === '/' || self::is_ascii_space($next);
    }

    /**
     * ASCII whitespace only, so the server locale cannot change a count.
     *
     * @param string $byte One byte.
     * @return bool
     */
    private static function is_ascii_space($byte)
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === "\r" || $byte === "\f";
    }

    /**
     * Count start tags of one name in already-visible markup.
     *
     * @param string $markup Visible HTML.
     * @param string $name Lowercased tag name.
     * @return int
     */
    private static function count_tags($markup, $name)
    {
        if ($markup === '') {
            return 0;
        }

        $open = '<' . $name;
        $length = strlen($open);
        $count = 0;
        $offset = 0;

        while (($pos = strpos($markup, $open, $offset)) !== false) {
            if (self::is_tag_boundary($markup, $pos + $length)) {
                $count++;
            }

            $offset = $pos + $length;
        }

        return $count;
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
