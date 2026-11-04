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
     * browser would load. HTML comments are ignored, as are the contents of
     * script, style, template, textarea, title, iframe, and noscript elements.
     * An img counts when it has a src or a srcset. Several srcset candidates
     * are one image. An img with neither does not count. An unclosed comment,
     * script, style, template, textarea, title, iframe, or noscript hides the
     * rest of the body, which is what a cut-off page looks like. Switcher
     * markers are read from that same HTML
     * with comments and noscript contents removed. A marker that appears only
     * inside a comment or noscript does not count. The same marker in the
     * body, including a script URL outside noscript, still counts.
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
        $without_comments = self::without_comments($lower);
        $visible = self::visible_markup($without_comments);
        $hreflang_count = self::unique_hreflang_count($visible['markup']);
        $has_switcher = self::has_polylang_switcher($without_comments);

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
            'image_count' => self::count_images($visible['markup']),
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
     * An empty body is not truncated, even when html_bytes is large. A body
     * of exactly 2000000 bytes is truncated. A shorter non-empty body is
     * truncated when html_bytes is a larger whole number, or when the caller
     * sets truncated itself.
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
     * HTML with comments removed and script or style elements left intact.
     *
     * An unclosed comment consumes the rest of the string. Markers inside a
     * script stay, so a Polylang script URL still counts. A comment is not
     * recognized once a script or style element has started.
     *
     * @param string $lower_html Lowercased HTML.
     * @return string
     */
    private static function without_comments($lower_html)
    {
        $length = strlen($lower_html);
        $chunks = array();
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

            $next_char = ($next + 1 < $length) ? $lower_html[$next + 1] : '';

            if ($next_char >= 'a' && $next_char <= 'z') {
                $tag = self::read_tag($lower_html, $next, $length);
                $name = self::tag_name($tag['html']);

                if (in_array($name, self::raw_text_elements(), true)) {
                    $chunks[] = $tag['html'];
                    $close = self::find_close($lower_html, $tag['next'], $name);

                    if ($close === null) {
                        // An unclosed noscript owns the rest of the page, and
                        // that fallback is not the switcher visitors see.
                        if ($name !== 'noscript') {
                            $chunks[] = self::strip_comment_spans(substr($lower_html, $tag['next']));
                        }

                        break;
                    }

                    if ($name !== 'noscript') {
                        $chunks[] = self::strip_comment_spans(substr(
                            $lower_html,
                            $tag['next'],
                            $close['body_end'] - $tag['next']
                        ));
                    }

                    $chunks[] = substr(
                        $lower_html,
                        $close['body_end'],
                        $close['next'] - $close['body_end']
                    );
                    $i = $close['next'];
                    continue;
                }

                $chunks[] = $tag['html'];
                $i = $tag['next'] > $next ? $tag['next'] : $next + 1;
                continue;
            }

            $chunks[] = '<';
            $i = $next + 1;
        }

        return implode('', $chunks);
    }

    /**
     * Remove comment spans without reading past the end of this fragment.
     *
     * Used inside a script or style element. An unclosed comment stops at the
     * end of the fragment, so a live switcher after the element still counts.
     *
     * @param string $html Fragment.
     * @return string
     */
    private static function strip_comment_spans($html)
    {
        $length = strlen($html);
        $chunks = array();
        $i = 0;

        while ($i < $length) {
            $next = strpos($html, '<!--', $i);

            if ($next === false) {
                $chunks[] = substr($html, $i);
                break;
            }

            if ($next > $i) {
                $chunks[] = substr($html, $i, $next - $i);
            }

            $end = strpos($html, '-->', $next + 4);

            if ($end === false) {
                break;
            }

            $i = $end + 3;
        }

        return implode('', $chunks);
    }

    /**
     * Lowercased name of one start tag.
     *
     * @param string $tag Start tag.
     * @return string
     */
    private static function tag_name($tag)
    {
        $length = strlen($tag);
        $i = 1;

        while ($i < $length && !self::is_ascii_space($tag[$i]) && $tag[$i] !== '>' && $tag[$i] !== '/') {
            $i++;
        }

        return substr($tag, 1, $i - 1);
    }

    /**
     * Locate the end tag that closes a raw element, or null when it was cut off.
     *
     * @param string $html Lowercased HTML.
     * @param int    $body_start Offset just after the opening tag.
     * @param string $name Lowercased element name.
     * @return array{body_end:int,next:int}|null
     */
    private static function find_close($html, $body_start, $name)
    {
        $length = strlen($html);
        $close = '</' . $name;
        $close_length = strlen($close);
        $pos = $body_start;

        while (($end = strpos($html, $close, $pos)) !== false) {
            $j = $end + $close_length;

            while ($j < $length && self::is_ascii_space($html[$j])) {
                $j++;
            }

            if ($j >= $length) {
                return null;
            }

            if ($html[$j] === '>') {
                return array(
                    'body_end' => $end,
                    'next' => $j + 1,
                );
            }

            $pos = $end + 1;
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

            $next_char = ($next + 1 < $length) ? $lower_html[$next + 1] : '';

            if ($next_char >= 'a' && $next_char <= 'z') {
                $tag = self::read_tag($lower_html, $next, $length);
                $name = self::tag_name($tag['html']);

                if (in_array($name, self::raw_text_elements(), true)) {
                    if ($name === 'script') {
                        $scripts++;
                    }

                    $close = self::find_close($lower_html, $tag['next'], $name);
                    $i = ($close === null) ? $length : $close['next'];
                    continue;
                }

                $chunks[] = $tag['html'];
                $i = $tag['next'] > $next ? $tag['next'] : $next + 1;
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
     * Elements whose contents the browser does not load as page markup.
     *
     * @return string[]
     */
    private static function raw_text_elements()
    {
        return array(
            'script',
            'style',
            'template',
            'textarea',
            'title',
            'iframe',
            'noscript',
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
     * Count img tags that actually reference a file.
     *
     * A srcset with several candidates is one image. An img with neither src
     * nor srcset is decorative markup and does not count.
     *
     * @param string $markup Visible HTML.
     * @return int
     */
    private static function count_images($markup)
    {
        if ($markup === '') {
            return 0;
        }

        $count = 0;
        $length = strlen($markup);
        $offset = 0;

        while (($pos = strpos($markup, '<', $offset)) !== false) {
            $next_char = ($pos + 1 < $length) ? $markup[$pos + 1] : '';

            if ($next_char < 'a' || $next_char > 'z') {
                $offset = $pos + 1;
                continue;
            }

            $tag = self::read_tag($markup, $pos, $length);

            if (self::tag_name($tag['html']) === 'img' && self::image_has_source($tag['html'])) {
                $count++;
            }

            $offset = $tag['next'] > $pos ? $tag['next'] : $pos + 1;
        }

        return $count;
    }

    /**
     * Whether an img tag has a non-empty src or srcset.
     *
     * @param string $tag One img start tag.
     * @return bool
     */
    private static function image_has_source($tag)
    {
        $src = self::attribute_value($tag, 'src');

        if ($src !== null && trim($src) !== '') {
            return true;
        }

        $srcset = self::attribute_value($tag, 'srcset');

        return $srcset !== null && trim($srcset) !== '';
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
     * Only real attributes count. A sentence or a title that mentions
     * hreflang is not a language. A value counts when its quotes are closed,
     * or when an unquoted value ends at whitespace or the end of the tag.
     * A cut at the loader cap does not invent a language from a half-written
     * attribute.
     *
     * @param string $markup Visible HTML.
     * @return int
     */
    private static function unique_hreflang_count($markup)
    {
        if ($markup === '') {
            return 0;
        }

        $values = array();
        $length = strlen($markup);
        $offset = 0;

        while (($pos = strpos($markup, '<', $offset)) !== false) {
            $next = ($pos + 1 < $length) ? $markup[$pos + 1] : '';

            if ($next < 'a' || $next > 'z') {
                $offset = $pos + 1;
                continue;
            }

            $tag = self::read_tag($markup, $pos, $length);
            $hreflang = self::attribute_value($tag['html'], 'hreflang');

            if ($hreflang !== null) {
                self::remember_hreflang($hreflang, $values);
            }

            $offset = $tag['next'] > $pos ? $tag['next'] : $pos + 1;
        }

        return count($values);
    }

    /**
     * The value of one attribute, or null when it is missing or cut off.
     *
     * @param string $tag One start tag.
     * @param string $name Lowercased attribute name.
     * @return string|null
     */
    private static function attribute_value($tag, $name)
    {
        $length = strlen($tag);
        $i = 0;

        while ($i < $length && !self::is_ascii_space($tag[$i]) && $tag[$i] !== '>' && $tag[$i] !== '/') {
            $i++;
        }

        while ($i < $length) {
            while ($i < $length && self::is_ascii_space($tag[$i])) {
                $i++;
            }

            if ($i >= $length || $tag[$i] === '>' || $tag[$i] === '/') {
                break;
            }

            $start = $i;

            while (
                $i < $length
                && !self::is_ascii_space($tag[$i])
                && $tag[$i] !== '='
                && $tag[$i] !== '>'
                && $tag[$i] !== '/'
            ) {
                $i++;
            }

            $attr = substr($tag, $start, $i - $start);

            while ($i < $length && self::is_ascii_space($tag[$i])) {
                $i++;
            }

            $value = null;

            if ($i < $length && $tag[$i] === '=') {
                $i++;

                while ($i < $length && self::is_ascii_space($tag[$i])) {
                    $i++;
                }

                if ($i >= $length) {
                    break;
                }

                if ($tag[$i] === '"' || $tag[$i] === "'") {
                    $quote = $tag[$i];
                    $i++;
                    $value_start = $i;

                    while ($i < $length && $tag[$i] !== $quote) {
                        $i++;
                    }

                    if ($i >= $length || $tag[$i] !== $quote) {
                        break;
                    }

                    $value = substr($tag, $value_start, $i - $value_start);
                    $i++;
                } else {
                    $value_start = $i;

                    while ($i < $length && !self::is_ascii_space($tag[$i]) && $tag[$i] !== '>') {
                        // The slash in <link href=/a.css/> closes the tag. A slash
                        // inside /css/style.css is part of the URL.
                        if ($tag[$i] === '/' && ($i + 1 >= $length || $tag[$i + 1] === '>')) {
                            break;
                        }

                        $i++;
                    }

                    // A value that runs off the end of a cut tag is not complete.
                    if ($i >= $length || $i === $value_start) {
                        break;
                    }

                    $value = substr($tag, $value_start, $i - $value_start);
                }
            }

            if ($attr === $name && $value !== null) {
                return $value;
            }
        }

        return null;
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
     * lang-item, and pll-parent-menu-item. The caller removes HTML comments
     * and noscript contents before this search.
     *
     * @param string $lower_html Lowercased HTML.
     * @return bool
     */
    private static function has_polylang_switcher($lower_html)
    {
        if ($lower_html === '' || !self::string_has_marker($lower_html)) {
            return false;
        }

        $length = strlen($lower_html);
        $i = 0;

        while ($i < $length) {
            $next = strpos($lower_html, '<', $i);

            if ($next === false) {
                return self::string_has_marker(substr($lower_html, $i));
            }

            if ($next > $i && self::string_has_marker(substr($lower_html, $i, $next - $i))) {
                return true;
            }

            $next_char = ($next + 1 < $length) ? $lower_html[$next + 1] : '';

            if ($next_char >= 'a' && $next_char <= 'z') {
                $tag = self::read_tag($lower_html, $next, $length);

                if (self::attributes_contain_switcher($tag['html'])) {
                    return true;
                }

                $i = $tag['next'] > $next ? $tag['next'] : $next + 1;
                continue;
            }

            $i = $next + 1;
        }

        return false;
    }

    /**
     * Whether any marker appears in this already-lowercased string.
     *
     * @param string $text Text or attribute value.
     * @return bool
     */
    private static function string_has_marker($text)
    {
        if ($text === '') {
            return false;
        }

        $markers = array(
            'polylang',
            'pll-switcher',
            'lang-item',
            'pll-parent-menu-item',
        );

        foreach ($markers as $marker) {
            if (strpos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class names and script URLs can be the live switcher.
     *
     * Title, alt, and data attributes are not, even when they contain the
     * same word. A value that itself contains "<" is stored HTML.
     *
     * @param string $name Lowercased attribute name.
     * @return bool
     */
    private static function switcher_attribute($name)
    {
        return $name === 'class' || $name === 'src';
    }

    /**
     * Whether a real attribute value contains a switcher marker.
     *
     * @param string $tag One start tag.
     * @return bool
     */
    private static function attributes_contain_switcher($tag)
    {
        $length = strlen($tag);
        $i = 0;

        while ($i < $length && !self::is_ascii_space($tag[$i]) && $tag[$i] !== '>' && $tag[$i] !== '/') {
            $i++;
        }

        while ($i < $length) {
            while ($i < $length && self::is_ascii_space($tag[$i])) {
                $i++;
            }

            if ($i >= $length || $tag[$i] === '>' || $tag[$i] === '/') {
                break;
            }

            $name_start = $i;

            while (
                $i < $length
                && !self::is_ascii_space($tag[$i])
                && $tag[$i] !== '='
                && $tag[$i] !== '>'
                && $tag[$i] !== '/'
            ) {
                $i++;
            }

            $attr = substr($tag, $name_start, $i - $name_start);

            while ($i < $length && self::is_ascii_space($tag[$i])) {
                $i++;
            }

            if ($i >= $length || $tag[$i] !== '=') {
                continue;
            }

            $i++;

            while ($i < $length && self::is_ascii_space($tag[$i])) {
                $i++;
            }

            if ($i >= $length) {
                break;
            }

            if ($tag[$i] === '"' || $tag[$i] === "'") {
                $quote = $tag[$i];
                $i++;
                $start = $i;

                while ($i < $length && $tag[$i] !== $quote) {
                    $i++;
                }

                $value = substr($tag, $start, $i - $start);

                if ($i < $length) {
                    $i++;
                }
            } else {
                $start = $i;

                while ($i < $length && !self::is_ascii_space($tag[$i]) && $tag[$i] !== '>') {
                    if ($tag[$i] === '/' && ($i + 1 >= $length || $tag[$i + 1] === '>')) {
                        break;
                    }

                    $i++;
                }

                if ($i === $start || $i >= $length) {
                    break;
                }

                $value = substr($tag, $start, $i - $start);
            }

            if (
                self::switcher_attribute($attr)
                && strpos($value, '<') === false
                && self::string_has_marker($value)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Link tags whose rel includes the stylesheet token.
     *
     * A ">" inside a quoted attribute does not end the tag. The last tag still
     * counts when a truncated body cuts it off after a complete rel attribute.
     *
     * @param string $lower_html Lowercased HTML.
     * @return int
     */
    private static function count_stylesheets($lower_html)
    {
        if ($lower_html === '') {
            return 0;
        }

        $count = 0;
        $length = strlen($lower_html);
        $offset = 0;

        while (($pos = strpos($lower_html, '<', $offset)) !== false) {
            $next_char = ($pos + 1 < $length) ? $lower_html[$pos + 1] : '';

            if ($next_char < 'a' || $next_char > 'z') {
                $offset = $pos + 1;
                continue;
            }

            $tag = self::read_tag($lower_html, $pos, $length);

            if (self::tag_name($tag['html']) === 'link' && self::rel_has_stylesheet($tag['html'])) {
                $count++;
            }

            $offset = $tag['next'] > $pos ? $tag['next'] : $pos + 1;
        }

        return $count;
    }

    /**
     * One start tag, including a ">" that sits inside a quoted attribute.
     *
     * A truncated body returns the unfinished tag through the last byte.
     *
     * @param string $html Markup.
     * @param int    $start Offset of the opening "<".
     * @param int    $length Byte length of $html.
     * @return array{html:string,next:int}
     */
    private static function read_tag($html, $start, $length)
    {
        $quote = '';
        $i = $start + 1;

        while ($i < $length) {
            $byte = $html[$i];

            if ($quote !== '') {
                if ($byte === $quote) {
                    $quote = '';
                }

                $i++;
                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $i++;
                continue;
            }

            if ($byte === '>') {
                return array(
                    'html' => substr($html, $start, $i - $start + 1),
                    'next' => $i + 1,
                );
            }

            $i++;
        }

        return array(
            'html' => substr($html, $start),
            'next' => $length,
        );
    }

    /**
     * Whether one link tag loads a stylesheet.
     *
     * @param string $tag Lowercased link tag, possibly unclosed.
     * @return bool
     */
    private static function rel_has_stylesheet($tag)
    {
        $rel = self::attribute_value($tag, 'rel');

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
