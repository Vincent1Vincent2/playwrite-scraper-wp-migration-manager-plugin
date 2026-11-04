<?php

/**
 * Tolka speed estimate from a live page measurement and benchmarks.json.
 *
 * @package WMM\Tolka
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Projects how a measured page would load with Tolka.
 *
 * The percent comes only from the measured time and the coefficients in
 * benchmarks.json. A failed measurement or unusable coefficients returns
 * zero times and no percent.
 */
class Speed_Estimate
{
    /**
     * Sentence stored on every estimate.
     */
    const BASIS = 'Measured page load plus coefficients from benchmarks.json';

    /**
     * Coefficient keys required by the projection formula, in file order.
     *
     * @var string[]
     */
    private static $coefficient_keys = array(
        'polylang_extra_ms_per_language',
        'polylang_switcher_ms',
        'polylang_extra_ms_per_script',
        'tolka_ms_per_language',
        'max_overhead_ratio',
    );

    /**
     * Plain names for the coefficient keys, for failure messages.
     *
     * @var array<string, string>
     */
    private static $coefficient_labels = array(
        'polylang_extra_ms_per_language' => 'extra milliseconds per language',
        'polylang_switcher_ms' => 'language switcher milliseconds',
        'polylang_extra_ms_per_script' => 'extra milliseconds per script',
        'tolka_ms_per_language' => 'Tolka milliseconds per language',
        'max_overhead_ratio' => 'maximum overhead ratio',
    );

    /**
     * Largest magnitude that still fits in an integer millisecond.
     */
    private const MAX_SAFE_MS = 9007199254740991;

    /**
     * Read includes/tolka/data/benchmarks.json.
     *
     * Returns an empty array when the file is missing, unreadable, or not
     * a JSON object. Callers then get a failure from project() instead of
     * a made-up percent.
     *
     * @return array
     */
    public static function benchmarks()
    {
        $path = __DIR__ . '/data/benchmarks.json';
        clearstatcache(true, $path);

        return self::read_benchmarks($path);
    }

    /**
     * Project a Tolka load time.
     *
     * The returned array always has ok, current_ms, tolka_ms, faster_percent,
     * faster_label, overhead_ms, basis, provenance, provenance_note,
     * coefficients, and error. On success, faster_label is "{percent}% faster"
     * with one decimal, error is empty, and provenance and provenance_note are
     * copied from the benchmarks argument. On failure, the three times are 0,
     * faster_percent is 0, faster_label is empty, and error is the sentence
     * to show the admin. basis is always the benchmarks.json sentence.
     * benchmarks() returns the five coefficient keys stored in the file.
     * A total_ms of 0 still estimates from a 1 ms floor and returns a percent.
     * A language_count of 0 is one language.
     *
     * @param array $measurement Loader measurement. `ok` must be boolean true.
     * @param array $metrics     Metrics from Speed_Metrics::analyze().
     * @param array $benchmarks  Coefficients, normally from benchmarks().
     * @return array
     */
    public static function project(array $measurement, array $metrics, array $benchmarks)
    {
        $reasons = array();

        if (!self::measurement_is_ok($measurement)) {
            $reasons[] = self::measurement_failure_sentence($measurement);
        } else {
            $total_issue = self::total_ms_issue($measurement);
            if ($total_issue !== '') {
                $reasons[] = $total_issue;
            }
            $language_issue = self::combined_count_issue(
                $metrics,
                $measurement,
                'language_count',
                'The language count is not a usable number.',
                'The language count cannot be negative.'
            );
            if ($language_issue !== '') {
                $reasons[] = $language_issue;
            }
            $script_issue = self::combined_count_issue(
                $metrics,
                $measurement,
                'script_count',
                'The script count is not a usable number.',
                'The script count cannot be negative.'
            );
            if ($script_issue !== '') {
                $reasons[] = $script_issue;
            }
        }

        $coefficient_issue = self::coefficient_issue($benchmarks);
        if ($coefficient_issue !== '') {
            $reasons[] = $coefficient_issue;
        }

        if ($reasons !== array()) {
            return self::unavailable($benchmarks, self::unavailable_message($reasons));
        }

        // A language count of 0 still estimates as one language.
        $languages = max(1, self::metric_count($metrics, 'language_count'));
        $scripts = self::metric_count($metrics, 'script_count');
        $switcher = self::switcher_present($metrics, $measurement);

        $per_language = self::coefficient_number($benchmarks, 'polylang_extra_ms_per_language');
        $switcher_ms = self::coefficient_number($benchmarks, 'polylang_switcher_ms');
        $per_script = self::coefficient_number($benchmarks, 'polylang_extra_ms_per_script');
        $tolka_per_language = self::coefficient_number($benchmarks, 'tolka_ms_per_language');
        $max_ratio = self::coefficient_number($benchmarks, 'max_overhead_ratio');
        if ($per_language === null || $switcher_ms === null || $per_script === null || $tolka_per_language === null || $max_ratio === null) {
            return self::unavailable(
                $benchmarks,
                self::unavailable_message(array(self::translate('The benchmark coefficients are not usable.')))
            );
        }

        $current = self::integer_milliseconds($measurement['total_ms']);
        if ($current === null) {
            return self::unavailable(
                $benchmarks,
                self::unavailable_message(array(self::translate('The measured load time is not a usable number.')))
            );
        }
        // A measured 0 ms still estimates. The formula floor is 1 ms.
        $current = max(1, $current);

        $raw = ($languages * $per_language)
            + ($switcher ? $switcher_ms : 0)
            + ($scripts * $per_script);
        $cap = self::floor_product($current, $max_ratio);

        if (!is_finite($raw) || $cap === null) {
            return self::too_large($benchmarks);
        }

        $overhead = self::integer_milliseconds(min($raw, $cap));
        if ($overhead === null) {
            return self::too_large($benchmarks);
        }

        $tolka_languages = $languages * $tolka_per_language;
        if (!is_finite($tolka_languages)) {
            return self::too_large($benchmarks);
        }

        $tolka = $current - $overhead + min($tolka_languages, $overhead);
        $tolka = self::integer_milliseconds($tolka);
        if ($tolka === null) {
            return self::too_large($benchmarks);
        }
        $tolka = max(1, $tolka);

        $faster_percent = round((($current - $tolka) / $current) * 100, 1);
        if (!is_finite($faster_percent)) {
            return self::too_large($benchmarks);
        }

        return self::result(
            true,
            (int) $current,
            (int) $tolka,
            $faster_percent,
            self::faster_label($faster_percent),
            (int) $overhead,
            $benchmarks,
            ''
        );
    }

    /**
     * Load a benchmark JSON object from disk.
     *
     * @param string $path
     * @return array
     */
    private static function read_benchmarks($path)
    {
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            return array();
        }

        $size = filesize($path);
        if ($size === false || $size < 2 || $size > 1048576) {
            return array();
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return array();
        }

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $decoded = json_decode($raw, true, 32);
        if (!is_array($decoded) || self::is_list($decoded)) {
            return array();
        }

        return $decoded;
    }

    /**
     * True when the array is a JSON list rather than an object.
     *
     * @param array $value
     * @return bool
     */
    private static function is_list(array $value)
    {
        $index = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return $index !== 0;
    }

    /**
     * @param array $measurement
     * @return bool
     */
    private static function measurement_is_ok(array $measurement)
    {
        return isset($measurement['ok']) && $measurement['ok'] === true;
    }

    /**
     * @param array $measurement
     * @return string
     */
    private static function measurement_failure_sentence(array $measurement)
    {
        $url = self::public_url(isset($measurement['url']) ? $measurement['url'] : '');
        $detail = self::plain_detail(isset($measurement['error']) ? $measurement['error'] : '');
        $has_detail = $detail !== '' && preg_match('/[\p{L}\p{N}]/u', $detail) === 1;
        if ($url !== '' && $has_detail && stripos($detail, $url) !== false) {
            $url = '';
        }

        if (!$has_detail) {
            if ($url === '') {
                return self::translate('The page could not be measured.');
            }

            return sprintf(self::translate('The page at %s could not be measured.'), $url);
        }

        if (preg_match('/\s/u', $detail) === 1) {
            if (preg_match('/[.!?]$/u', $detail) !== 1) {
                $detail .= '.';
            }

            $lead = $url === ''
                ? self::translate('The page could not be measured.')
                : sprintf(self::translate('The page at %s could not be measured.'), $url);

            return $lead . ' ' . $detail;
        }

        $detail = rtrim($detail, '.');
        if ($url === '') {
            return sprintf(self::translate('The page could not be measured (%s).'), $detail);
        }

        return sprintf(self::translate('The page at %s could not be measured (%s).'), $url, $detail);
    }

    /**
     * HTTP(S) URL safe to repeat in an admin sentence.
     *
     * Credentials are left out so a failed measurement cannot echo them.
     *
     * @param mixed $value
     * @return string
     */
    private static function public_url($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $url = trim($value);
        if ($url === '' || $url !== trim(strip_tags($url))) {
            return '';
        }

        if (strlen($url) > 200 || preg_match('/\s/u', $url) === 1) {
            return '';
        }

        if (preg_match('#\Ahttps?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        return $url;
    }

    /**
     * Why total_ms cannot be used, or an empty string when it can.
     *
     * @param array $measurement
     * @return string
     */
    private static function total_ms_issue(array $measurement)
    {
        if (!array_key_exists('total_ms', $measurement)) {
            return self::translate('The measured load time is missing.');
        }

        if (self::integer_milliseconds($measurement['total_ms']) === null) {
            return self::translate('The measured load time is not a usable number.');
        }

        return '';
    }

    /**
     * Finished sentence for missing or non-numeric coefficients.
     *
     * @param array $benchmarks
     * @return string
     */
    private static function coefficient_issue(array $benchmarks)
    {
        $missing = array();
        $invalid = array();

        foreach (self::$coefficient_keys as $key) {
            if (!array_key_exists($key, $benchmarks)) {
                $missing[] = $key;
                continue;
            }
            if (self::coefficient_number($benchmarks, $key) === null) {
                $invalid[] = $key;
            }
        }

        $ratio = self::coefficient_number($benchmarks, 'max_overhead_ratio');
        if ($ratio !== null && ($ratio < 0 || $ratio > 1)) {
            $invalid = array_values(array_diff($invalid, array('max_overhead_ratio')));
            $range_error = self::translate('The maximum overhead ratio (max_overhead_ratio) must be from 0 through 1.');
        } else {
            $range_error = '';
        }

        if ($missing === array() && $invalid === array() && $range_error === '') {
            return '';
        }

        if ($benchmarks === array()) {
            return self::translate('The benchmark coefficients could not be read from includes/tolka/data/benchmarks.json. Check that the file exists and that this site can read it.');
        }

        $sentences = array();
        if ($missing !== array()) {
            $sentences[] = self::count_sentence(
                count($missing),
                'The benchmark file is missing a number for %s.',
                'The benchmark file is missing numbers for %s.',
                self::labels_for($missing)
            );
        }
        if ($invalid !== array()) {
            $sentences[] = self::count_sentence(
                count($invalid),
                'The benchmark file has a value that is not a number for %s.',
                'The benchmark file has values that are not numbers for %s.',
                self::labels_for($invalid)
            );
        }
        if ($range_error !== '') {
            $sentences[] = $range_error;
        }

        return implode(' ', $sentences);
    }

    /**
     * @param string[] $keys
     * @return string[]
     */
    private static function labels_for(array $keys)
    {
        $labels = array();
        foreach ($keys as $key) {
            $name = isset(self::$coefficient_labels[$key]) ? self::$coefficient_labels[$key] : $key;
            $labels[] = $name . ' (' . $key . ')';
        }

        return $labels;
    }

    /**
     * @param int      $count
     * @param string   $one
     * @param string   $many
     * @param string[] $labels
     * @return string
     */
    private static function count_sentence($count, $one, $many, array $labels)
    {
        $template = self::translate($count === 1 ? $one : $many);

        return sprintf($template, self::join_labels($labels));
    }

    /**
     * @param string[] $labels
     * @return string
     */
    private static function join_labels(array $labels)
    {
        $count = count($labels);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0] . ' and ' . $labels[1];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ', and ' . $last;
    }

    /**
     * @param string[] $reasons
     * @return string
     */
    private static function unavailable_message(array $reasons)
    {
        $sentences = array(self::translate('A Tolka speed estimate is not available.'));
        foreach ($reasons as $reason) {
            if (!is_string($reason) || $reason === '') {
                continue;
            }
            $sentences[] = $reason;
        }

        return implode(' ', $sentences);
    }

    /**
     * @param array $benchmarks
     * @return array
     */
    private static function too_large(array $benchmarks)
    {
        return self::unavailable(
            $benchmarks,
            self::unavailable_message(array(self::translate('These figures are too large to calculate a load time.')))
        );
    }

    /**
     * @param array  $benchmarks
     * @param string $error
     * @return array
     */
    private static function unavailable(array $benchmarks, $error)
    {
        return self::result(false, 0, 0, 0.0, '', 0, $benchmarks, $error);
    }

    /**
     * @param bool   $ok
     * @param int    $current
     * @param int    $tolka
     * @param float  $faster_percent
     * @param string $faster_label
     * @param int    $overhead
     * @param array  $benchmarks
     * @param string $error
     * @return array
     */
    private static function result($ok, $current, $tolka, $faster_percent, $faster_label, $overhead, array $benchmarks, $error)
    {
        return array(
            'ok' => (bool) $ok,
            'current_ms' => (int) $current,
            'tolka_ms' => (int) $tolka,
            'faster_percent' => $faster_percent,
            'faster_label' => $faster_label,
            'overhead_ms' => (int) $overhead,
            'basis' => self::BASIS,
            'provenance' => self::copied_text($benchmarks, 'provenance'),
            'provenance_note' => self::copied_text($benchmarks, 'provenance_note'),
            'coefficients' => self::json_safe_array($benchmarks),
            'error' => $error,
        );
    }

    /**
     * Copy coefficients into a shape wp_send_json can encode.
     *
     * INF, NAN, and resources would make the whole admin response fail.
     *
     * @param array $value
     * @param int   $depth
     * @return array
     */
    private static function json_safe_array(array $value, $depth = 0)
    {
        if ($depth > 8) {
            return array();
        }

        $safe = array();
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $safe[$key] = self::json_safe_array($item, $depth + 1);
                continue;
            }
            if (is_string($item)) {
                $safe[$key] = preg_match('//u', $item) === 1 ? $item : '';
                continue;
            }
            if (is_int($item) || is_bool($item) || $item === null) {
                $safe[$key] = $item;
                continue;
            }
            if (is_float($item)) {
                $safe[$key] = is_finite($item) ? $item : null;
                continue;
            }
            $safe[$key] = null;
        }

        return $safe;
    }

    /**
     * Copy a text field from the benchmarks array that was passed in.
     *
     * The file on disk is not read again here, and a missing or non-string
     * value stays blank instead of being replaced with a stock sentence.
     *
     * @param array  $benchmarks
     * @param string $key
     * @return string
     */
    private static function copied_text(array $benchmarks, $key)
    {
        if (!array_key_exists($key, $benchmarks) || !is_string($benchmarks[$key])) {
            return '';
        }

        if (preg_match('//u', $benchmarks[$key]) !== 1) {
            return '';
        }

        return $benchmarks[$key];
    }

    /**
     * Finite number for a coefficient, or null when it cannot be used.
     *
     * @param array  $benchmarks
     * @param string $key
     * @return int|float|null
     */
    private static function coefficient_number(array $benchmarks, $key)
    {
        if (!array_key_exists($key, $benchmarks)) {
            return null;
        }

        return self::finite_number($benchmarks[$key]);
    }

    /**
     * @param mixed $value
     * @return int|float|null
     */
    private static function finite_number($value)
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        $number = $value + 0;
        if (!is_int($number) && !is_float($number)) {
            return null;
        }
        if (!is_finite((float) $number)) {
            return null;
        }
        if (abs((float) $number) > self::MAX_SAFE_MS) {
            return null;
        }

        return $number;
    }

    /**
     * Nearest integer millisecond, or null when the value is not usable.
     *
     * Integer inputs are unchanged. The formula then runs on whole milliseconds.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function integer_milliseconds($value)
    {
        $number = self::finite_number($value);
        if ($number === null) {
            return null;
        }

        $rounded = round((float) $number);
        if (!is_finite($rounded) || abs($rounded) > self::MAX_SAFE_MS) {
            return null;
        }

        return (int) $rounded;
    }

    /**
     * Why a provided count cannot be used, or an empty string when it can.
     *
     * A missing count is allowed and means zero. A negative count is not.
     *
     * @param array  $metrics
     * @param string $key
     * @param string $unusable
     * @param string $negative
     * @return string
     */
    private static function count_issue(array $metrics, $key, $unusable, $negative)
    {
        if (!array_key_exists($key, $metrics)) {
            return '';
        }

        $number = self::finite_number($metrics[$key]);
        if ($number === null) {
            return self::translate($unusable);
        }
        if ((float) $number < 0) {
            return self::translate($negative);
        }

        return '';
    }

    /**
     * A negative count on the metrics or the measurement fails the estimate.
     *
     * @param array  $metrics
     * @param array  $measurement
     * @param string $key
     * @param string $unusable
     * @param string $negative
     * @return string
     */
    private static function combined_count_issue(array $metrics, array $measurement, $key, $unusable, $negative)
    {
        $problem = '';
        foreach (array($metrics, $measurement) as $source) {
            $issue = self::count_issue($source, $key, $unusable, $negative);
            if ($issue === '') {
                continue;
            }
            if ($problem === '') {
                $problem = $issue;
                continue;
            }
            if ($problem !== $issue) {
                return $problem . ' ' . $issue;
            }
        }

        return $problem;
    }

    /**
     * Whole count from a metric. Missing counts are 0.
     *
     * @param array  $metrics
     * @param string $key
     * @return int
     */
    private static function metric_count(array $metrics, $key)
    {
        if (!array_key_exists($key, $metrics)) {
            return 0;
        }

        $count = self::integer_milliseconds($metrics[$key]);
        if ($count === null) {
            return 0;
        }

        return $count;
    }

    /**
     * Whether the Polylang switcher cost applies.
     *
     * A false flag on the measurement does not add polylang_switcher_ms,
     * including when the metrics also carry a flag.
     *
     * @param array $metrics
     * @param array $measurement
     * @return bool
     */
    private static function switcher_present(array $metrics, array $measurement)
    {
        if (array_key_exists('has_polylang_switcher', $measurement)
            && self::switcher_flag($measurement['has_polylang_switcher']) === false) {
            return false;
        }

        if (!array_key_exists('has_polylang_switcher', $metrics)) {
            return false;
        }

        return self::switcher_flag($metrics['has_polylang_switcher']) === true;
    }

    /**
     * @param mixed $value
     * @return bool|null
     */
    private static function switcher_flag($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $value !== 0 && $value !== 0.0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($normalized, array('', '0', 'false', 'no', 'off'), true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * floor(current * ratio), corrected when binary floats land just under an integer.
     *
     * 700 * 0.7 is 489.99999999999994 in IEEE math. The formula's floor is 490.
     *
     * @param int       $current
     * @param int|float $ratio
     * @return int|null
     */
    private static function floor_product($current, $ratio)
    {
        if (!is_finite((float) $current) || !is_finite((float) $ratio)) {
            return null;
        }

        $product = (float) $current * (float) $ratio;
        if (!is_finite($product)) {
            return null;
        }

        $nearest = round($product);
        if (is_finite($nearest) && abs($product - $nearest) < 1.0e-9) {
            $product = $nearest;
        }

        $floored = floor($product);
        if (!is_finite($floored) || $floored > self::MAX_SAFE_MS || $floored < -self::MAX_SAFE_MS) {
            return null;
        }

        return (int) $floored;
    }

    /**
     * @param float $faster_percent
     * @return string
     */
    private static function faster_label($faster_percent)
    {
        return number_format($faster_percent, 1, '.', '') . '% faster';
    }

    /**
     * Collapse a measurement error into one short plain sentence fragment.
     *
     * @param mixed $value
     * @return string
     */
    private static function plain_detail($value)
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return '';
        }

        if (strlen($value) > 4000) {
            $value = self::byte_prefix($value, 4000);
        }

        $detail = strip_tags($value);
        if (preg_match('//u', $detail) !== 1) {
            return '';
        }

        $detail = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $detail);
        if (!is_string($detail)) {
            return '';
        }

        $detail = preg_replace('/\s+/u', ' ', $detail);
        if (!is_string($detail)) {
            return '';
        }

        $detail = trim($detail);
        if ($detail === '') {
            return '';
        }

        return self::shorten($detail, 240);
    }

    /**
     * First $bytes of a string, backed up so a multibyte character is not split.
     *
     * @param string $value
     * @param int    $bytes
     * @return string
     */
    private static function byte_prefix($value, $bytes)
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        $prefix = substr($value, 0, $bytes);
        if (!is_string($prefix)) {
            return '';
        }
        if (preg_match('//u', $prefix) === 1) {
            return $prefix;
        }

        for ($trim = 1; $trim <= 3; $trim++) {
            $candidate = substr($prefix, 0, -$trim);
            if (is_string($candidate) && preg_match('//u', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Keep a measurement error inside one short fragment.
     *
     * Prefer the last whole word so the admin does not see a cut-off token.
     *
     * @param string $detail
     * @param int    $limit
     * @return string
     */
    private static function shorten($detail, $limit)
    {
        $length = self::string_length($detail);
        if ($length <= $limit) {
            return $detail;
        }

        $room = $limit - 1;
        $slice = self::string_slice($detail, $room);
        $extended = self::string_slice($detail, $room + 1);
        $next = self::string_length($extended) > $room ? self::string_last_character($extended) : '';
        $ends_on_boundary = $next === '' || $next === ' ' || substr($slice, -1) === ' ';
        if (!$ends_on_boundary) {
            $space = self::string_last_space($slice);
            if ($space !== null && $space >= 80) {
                $slice = self::string_slice($slice, $space);
            }
        }

        return rtrim($slice) . '…';
    }

    /**
     * @param string $text
     * @return string
     */
    private static function string_last_character($text)
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, -1);
        }

        if (preg_match('/.$/us', $text, $matches) === 1) {
            return $matches[0];
        }

        return (string) substr($text, -1);
    }

    /**
     * @param string $text
     * @return int
     */
    private static function string_length($text)
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text);
        }

        $count = preg_match_all('/./u', $text, $matches);
        if ($count !== false) {
            return (int) $count;
        }

        return strlen($text);
    }

    /**
     * @param string $text
     * @param int    $length
     * @return string
     */
    private static function string_slice($text, $length)
    {
        if ($length < 1) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $length);
        }

        $pattern = '/^.{0,' . $length . '}/us';
        if (preg_match($pattern, $text, $matches) === 1) {
            return $matches[0];
        }

        return substr($text, 0, $length);
    }

    /**
     * @param string $text
     * @return int|null
     */
    private static function string_last_space($text)
    {
        if (function_exists('mb_strrpos')) {
            $space = mb_strrpos($text, ' ');
        } else {
            $space = strrpos($text, ' ');
        }

        if ($space === false) {
            return null;
        }

        return (int) $space;
    }

    /**
     * Translate a finished sentence when WordPress is loaded.
     *
     * @param string $text
     * @return string
     */
    private static function translate($text)
    {
        if (function_exists('__')) {
            return __($text, 'migration-manager');
        }

        return $text;
    }
}
