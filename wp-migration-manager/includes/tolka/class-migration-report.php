<?php

/**
 * Migration Manager - Tolka migration report
 *
 * Turns a migration run into a short result a site owner can read.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats the result of a Polylang to Tolka migration.
 */
class Migration_Report
{
    /**
     * Option that stores the last formatted report.
     */
    const OPTION_LAST_REPORT = 'wmm_tolka_last_report';

    /**
     * Format a migration run and store the report.
     *
     * A clean run with content returns ok true. An empty site still returns
     * ok true, with a title that says nothing was found. Any failed save,
     * missing step, or error returns ok false. Polylang data is never deleted.
     *
     * @param array $run Runner result with ok, counts, write, errors, and missing.
     * @return array{ok: bool, title: string, summary: string, lines: array<int, array{label: string, value: string}>}
     */
    public static function format(array $run): array
    {
        $report = self::compose($run);
        self::remember($report);

        return $report;
    }

    /**
     * Build the report array without storing it.
     *
     * @param array $run Runner result.
     * @return array
     */
    private static function compose(array $run)
    {
        $run_ok = isset($run['ok']) && $run['ok'] === true;
        $write = (isset($run['write']) && is_array($run['write'])) ? $run['write'] : null;
        $package = self::package_state($run_ok, $write);
        $counts = self::resolve_counts($run, $write);
        $errors = self::collect_errors($run, $write);
        $missing = self::collect_missing($run);
        $unavailable = self::missing_section_keys($missing['classes']);
        $description = self::describe_content($counts, $unavailable);
        $total = self::total_items($counts);
        $has_problems = !empty($errors) || !empty($missing['labels']);
        $status = self::decide($run_ok, $package, $has_problems, $total);
        $inactive = self::polylang_is_inactive($errors);
        $next = self::next_step($status, $inactive, count($missing['labels']), $has_problems);
        $title = self::title_for($status);
        $summary = self::summary_for($status, $package, $description, $total, $missing['labels'], $errors, $next, $run_ok);

        return array(
            'ok' => ($status === 'complete' || $status === 'empty'),
            'title' => $title,
            'summary' => $summary,
            'lines' => self::lines_for($title, $counts, $unavailable, $package, $total, $next, $missing['lines'], $errors),
        );
    }

    /**
     * Store the report for the next time the site owner opens the screen.
     *
     * @param array $report Formatted report.
     */
    private static function remember(array $report)
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::OPTION_LAST_REPORT, $report);
    }

    /**
     * Saved, failed, or not included in the run.
     *
     * @param bool       $run_ok Overall runner flag.
     * @param array|null $write  Writer result, when the runner included one.
     * @return string saved|failed|unknown
     */
    private static function package_state($run_ok, $write)
    {
        if (is_array($write) && array_key_exists('ok', $write)) {
            return ($write['ok'] === true) ? 'saved' : 'failed';
        }

        if (!$run_ok) {
            return 'failed';
        }

        if (is_array($write)) {
            return 'unknown';
        }

        return 'saved';
    }

    /**
     * Section counts from the run, or from the writer when the run omitted them.
     *
     * @param array      $run   Runner result.
     * @param array|null $write Writer result.
     * @return array<string, int>
     */
    private static function resolve_counts(array $run, $write)
    {
        $from_run = self::read_counts(
            (isset($run['counts']) && is_array($run['counts'])) ? $run['counts'] : array()
        );

        if ($from_run['present']) {
            return $from_run['values'];
        }

        $source = array();
        if (is_array($write)) {
            if (isset($write['counts']) && is_array($write['counts'])) {
                $source = $write['counts'];
            } else {
                $source = $write;
            }
        }

        $from_write = self::read_counts($source);
        if ($from_write['present']) {
            return $from_write['values'];
        }

        return $from_run['values'];
    }

    /**
     * Read the six section counts. Missing keys are zero.
     *
     * @param array $counts Raw counts.
     * @return array{present: bool, values: array<string, int>}
     */
    private static function read_counts(array $counts)
    {
        $values = array();
        $present = false;

        foreach (array_keys(self::section_catalog()) as $key) {
            if (isset($counts[$key]) && !is_bool($counts[$key]) && is_numeric($counts[$key])) {
                $count = (int) $counts[$key];
                $values[$key] = ($count > 0) ? $count : 0;
                $present = true;
                continue;
            }

            $values[$key] = 0;
        }

        return array(
            'present' => $present,
            'values' => $values,
        );
    }

    /**
     * Error sentences from the run and the writer, without duplicates.
     *
     * @param array      $run   Runner result.
     * @param array|null $write Writer result.
     * @return string[]
     */
    private static function collect_errors(array $run, $write)
    {
        $raw = self::string_list(isset($run['errors']) ? $run['errors'] : array());

        if (is_array($write) && isset($write['errors'])) {
            $raw = array_merge($raw, self::string_list($write['errors']));
        }

        $seen = array();
        $errors = array();

        foreach ($raw as $item) {
            $sentence = self::sentence($item);
            if ($sentence === '') {
                continue;
            }

            $key = strtolower($sentence);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $errors[] = $sentence;
        }

        return $errors;
    }

    /**
     * Missing steps, in the order the runner reported them.
     *
     * @param array $run Runner result.
     * @return array{classes: string[], labels: string[], lines: string[]}
     */
    private static function collect_missing(array $run)
    {
        $classes = self::string_list(isset($run['missing']) ? $run['missing'] : array());
        $labels = array();
        $lines = array();
        $seen = array();

        foreach ($classes as $class_name) {
            $label = self::step_label($class_name);
            if ($label === '') {
                continue;
            }

            $key = strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = $label;
            $lines[] = sprintf(
                self::text('The %s step is missing from this plugin.'),
                $label
            );
        }

        return array(
            'classes' => $classes,
            'labels' => $labels,
            'lines' => $lines,
        );
    }

    /**
     * Section keys whose exporter class was missing.
     *
     * @param string[] $class_names Missing class names.
     * @return array<string, bool>
     */
    private static function missing_section_keys(array $class_names)
    {
        $map = array(
            'Polylang_Languages' => 'languages',
            'Polylang_Posts' => 'posts',
            'Polylang_Terms' => 'terms',
            'Polylang_Strings' => 'strings',
            'Polylang_Menus' => 'menus',
            'Polylang_Media' => 'media',
        );
        $keys = array();

        foreach ($class_names as $class_name) {
            $base = self::class_basename($class_name);
            if (isset($map[$base])) {
                $keys[$map[$base]] = true;
            }
        }

        return $keys;
    }

    /**
     * Split counts into what was found and what was empty.
     *
     * Sections whose exporter is missing are left out of both lists. The
     * missing-step sentence tells the site owner those could not be read.
     *
     * @param array<string, int>  $counts      Section counts.
     * @param array<string, bool> $unavailable Sections that could not be read.
     * @return array{present: string, absent: string}
     */
    private static function describe_content(array $counts, array $unavailable)
    {
        $present = array();
        $absent = array();

        foreach (self::section_catalog() as $key => $meta) {
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            if (!empty($unavailable[$key]) && $count <= 0) {
                continue;
            }

            if ($count <= 0) {
                $absent[] = self::text($meta['group']);
                continue;
            }

            $present[] = self::phrase_for($meta, $count);
        }

        $absent_sentence = '';
        if (!empty($absent)) {
            $absent_sentence = sprintf(
                self::text('No %s were found.'),
                self::join_or($absent)
            );
        }

        return array(
            'present' => self::join_and($present),
            'absent' => $absent_sentence,
        );
    }

    /**
     * @param array $meta  Section copy.
     * @param int   $count Item count, at least 1.
     * @return string
     */
    private static function phrase_for(array $meta, $count)
    {
        if ((int) $count === 1) {
            return sprintf(self::text($meta['one']), self::number(1));
        }

        return sprintf(self::text($meta['many']), self::number($count));
    }

    /**
     * @param array<string, int> $counts Section counts.
     * @return int
     */
    private static function total_items(array $counts)
    {
        $total = 0;
        foreach ($counts as $count) {
            $total += (int) $count;
        }

        return $total;
    }

    /**
     * @param bool   $run_ok       Runner ok flag.
     * @param string $package      saved, failed, or unknown.
     * @param bool   $has_problems Errors or missing steps.
     * @param int    $total        Sum of section counts.
     * @return string complete|empty|unconfirmed|problems|failed
     */
    private static function decide($run_ok, $package, $has_problems, $total)
    {
        if ($package === 'failed') {
            return 'failed';
        }

        if (!$run_ok) {
            return ($package === 'saved') ? 'problems' : 'failed';
        }

        if ($has_problems) {
            return 'problems';
        }

        if ($total === 0) {
            return 'empty';
        }

        if ($package === 'unknown') {
            return 'unconfirmed';
        }

        return 'complete';
    }

    /**
     * @param string[] $errors Error sentences.
     * @return bool
     */
    private static function polylang_is_inactive(array $errors)
    {
        $needles = array(
            'not active',
            'inactive',
            'not installed',
            'not found',
            'deactivated',
            "isn't active",
            "isn't installed",
        );

        foreach ($errors as $error) {
            $haystack = strtolower($error);
            if (strpos($haystack, 'polylang') === false) {
                continue;
            }

            foreach ($needles as $needle) {
                if (strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What the site owner can do next, or an empty string when the run is done.
     *
     * @param string $status         Report status.
     * @param bool   $inactive       Polylang looks inactive.
     * @param int    $missing_count  Missing steps.
     * @param bool   $has_problems   Errors or missing steps.
     * @return string
     */
    private static function next_step($status, $inactive, $missing_count, $has_problems)
    {
        if ($status === 'complete') {
            return '';
        }

        if ($status === 'empty') {
            return self::text('If this site should have translations, check that Polylang has languages, then run the migration again.');
        }

        if ($status === 'unconfirmed') {
            return self::text('Run the migration again from Migrate to Tolka.');
        }

        if ($inactive && $missing_count > 0) {
            return self::text('Activate Polylang if it is turned off, and update the plugin so every step is installed. Then run the migration again from Migrate to Tolka.');
        }

        if ($inactive) {
            return self::text('Activate Polylang, then run the migration again from Migrate to Tolka.');
        }

        if ($missing_count > 0) {
            return self::text('Update the plugin, then run the migration again from Migrate to Tolka.');
        }

        if ($status === 'problems' && $has_problems) {
            return self::text('Review the problems below, then run the migration again if you want to retry.');
        }

        if ($status === 'failed' || $status === 'problems') {
            return self::text('Run the migration again from Migrate to Tolka.');
        }

        return '';
    }

    /**
     * @param string $status Report status.
     * @return string
     */
    private static function title_for($status)
    {
        if ($status === 'empty') {
            return self::text('Nothing to migrate');
        }

        if ($status === 'complete' || $status === 'unconfirmed') {
            return ($status === 'complete')
                ? self::text('Migration complete')
                : self::text('Migration finished');
        }

        if ($status === 'problems') {
            return self::text('Migration finished with problems');
        }

        return self::text('Migration did not finish');
    }

    /**
     * Finished sentences for the status the site owner is in.
     *
     * @param string               $status      Report status.
     * @param string               $package     Package state.
     * @param array<string, string> $description Present phrase and absent sentence.
     * @param int                  $total       Sum of counts.
     * @param string[]             $labels      Missing step labels.
     * @param string[]             $errors      Error sentences.
     * @param string               $next        Next step sentence.
     * @param bool                 $run_ok      Runner ok flag.
     * @return string
     */
    private static function summary_for($status, $package, array $description, $total, array $labels, array $errors, $next, $run_ok)
    {
        $closing = self::text('Your existing Polylang data was left in place.');
        $missing_sentence = self::missing_sentence($labels);
        $problem_lead = self::problem_lead($errors);

        if ($status === 'complete') {
            return self::join_sentences(array(
                self::packaged_lead($description),
                $description['absent'],
                self::text('The package is saved on this site.'),
                $closing,
            ));
        }

        if ($status === 'empty') {
            $parts = array(
                self::text('The migration finished, but there was nothing to package.'),
                self::text('No languages, posts, terms, strings, menus, or media were found.'),
                $next,
            );

            if ($package === 'saved') {
                $parts[] = self::text('An empty Tolka package was saved on this site.');
            }

            $parts[] = $closing;

            return self::join_sentences($parts);
        }

        if ($status === 'unconfirmed') {
            return self::join_sentences(array(
                self::packaged_lead($description),
                $description['absent'],
                self::text('The plugin could not confirm that the Tolka package was saved.'),
                $next,
                $closing,
            ));
        }

        if ($status === 'problems') {
            $parts = array();

            if ($total > 0) {
                $parts[] = self::packaged_lead($description);
                $parts[] = $description['absent'];
            } else {
                $parts[] = self::text('The migration reported a problem before any content was packaged.');
            }

            if ($package === 'saved') {
                $parts[] = self::text('The package is saved on this site.');
            } else {
                $parts[] = self::text('The plugin could not confirm that the Tolka package was saved.');
            }

            if (!$run_ok && $missing_sentence === '' && $problem_lead === '') {
                $parts[] = self::text('The migration did not report a clean finish.');
            }

            $parts[] = $missing_sentence;
            $parts[] = $problem_lead;
            $parts[] = $next;
            $parts[] = $closing;

            return self::join_sentences($parts);
        }

        $parts = array();
        if ($total > 0 && $description['present'] !== '') {
            $parts[] = sprintf(
                self::text('The migration did not finish. This site has %s, but that content was not saved into a Tolka package.'),
                $description['present']
            );
            $parts[] = $description['absent'];
        } else {
            $parts[] = self::text('The migration did not finish, so the Tolka package was not saved.');
        }

        $parts[] = $missing_sentence;
        $parts[] = $problem_lead;
        $parts[] = $next;
        $parts[] = $closing;

        return self::join_sentences($parts);
    }

    /**
     * @param array{present: string, absent: string} $description Count phrases.
     * @return string
     */
    private static function packaged_lead(array $description)
    {
        if ($description['present'] === '') {
            return self::text('Your Polylang content is packaged for Tolka.');
        }

        return sprintf(
            self::text('Your Polylang content is packaged for Tolka: %s.'),
            $description['present']
        );
    }

    /**
     * @param string[] $labels Missing step labels.
     * @return string
     */
    private static function missing_sentence(array $labels)
    {
        $count = count($labels);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return sprintf(self::text('The %s step is missing from this plugin.'), $labels[0]);
        }

        return sprintf(
            self::text('The %s steps are missing from this plugin.'),
            self::join_and($labels)
        );
    }

    /**
     * First problem, plus how many more are listed in the lines.
     *
     * @param string[] $errors Error sentences.
     * @return string
     */
    private static function problem_lead(array $errors)
    {
        $count = count($errors);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $errors[0];
        }

        $more = $count - 1;
        if ($more === 1) {
            $rest = self::text('1 more problem is listed below.');
        } else {
            $rest = sprintf(self::text('%s more problems are listed below.'), self::number($more));
        }

        return $errors[0] . ' ' . $rest;
    }

    /**
     * @param string                $title        Report title.
     * @param array<string, int>    $counts       Section counts.
     * @param array<string, bool>   $unavailable  Unreadable sections.
     * @param string                $package      Package state.
     * @param int                   $total        Sum of counts.
     * @param string                $next         Next step.
     * @param string[]              $missing_lines Per-step sentences.
     * @param string[]              $errors       Error sentences.
     * @return array<int, array{label: string, value: string}>
     */
    private static function lines_for($title, array $counts, array $unavailable, $package, $total, $next, array $missing_lines, array $errors)
    {
        $lines = array(
            self::line(self::text('Outcome'), $title),
        );

        foreach (self::section_catalog() as $key => $meta) {
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $lines[] = self::line(self::text($meta['label']), self::line_value($meta, $count, !empty($unavailable[$key])));
        }

        $lines[] = self::line(self::text('Tolka package'), self::package_value($package, $total));
        $lines[] = self::line(self::text('Polylang data'), self::text('Left in place'));

        if ($next !== '') {
            $lines[] = self::line(self::text('Next'), $next);
        }

        foreach ($missing_lines as $sentence) {
            $lines[] = self::line(self::text('Missing step'), $sentence);
        }

        foreach ($errors as $sentence) {
            $lines[] = self::line(self::text('Problem'), $sentence);
        }

        return $lines;
    }

    /**
     * @param array $meta        Section copy.
     * @param int   $count       Item count.
     * @param bool  $unavailable Exporter was missing and the count is empty.
     * @return string
     */
    private static function line_value(array $meta, $count, $unavailable)
    {
        if ($unavailable && $count <= 0) {
            return self::text('Not available');
        }

        if ($count <= 0) {
            return self::text($meta['none_line']);
        }

        if ($count === 1) {
            return sprintf(self::text($meta['one']), self::number(1));
        }

        return sprintf(self::text($meta['many']), self::number($count));
    }

    /**
     * @param string $package Package state.
     * @param int    $total   Sum of counts.
     * @return string
     */
    private static function package_value($package, $total)
    {
        if ($package === 'saved' && $total === 0) {
            return self::text('Saved, but it is empty');
        }

        if ($package === 'saved') {
            return self::text('Saved on this site');
        }

        if ($package === 'failed') {
            return self::text('Not saved');
        }

        return self::text('Not confirmed');
    }

    /**
     * @param string $label Line label.
     * @param string $value Line value.
     * @return array{label: string, value: string}
     */
    private static function line($label, $value)
    {
        return array(
            'label' => (string) $label,
            'value' => (string) $value,
        );
    }

    /**
     * Labels and plural copy for the six migration sections.
     *
     * @return array<string, array<string, string>>
     */
    private static function section_catalog()
    {
        return array(
            'languages' => array(
                'label' => 'Languages',
                'group' => 'languages',
                'none_line' => 'No languages',
                'one' => '%s language',
                'many' => '%s languages',
            ),
            'posts' => array(
                'label' => 'Posts',
                'group' => 'posts',
                'none_line' => 'No posts',
                'one' => '%s post',
                'many' => '%s posts',
            ),
            'terms' => array(
                'label' => 'Terms',
                'group' => 'terms',
                'none_line' => 'No terms',
                'one' => '%s term',
                'many' => '%s terms',
            ),
            'strings' => array(
                'label' => 'Strings',
                'group' => 'strings',
                'none_line' => 'No strings',
                'one' => '%s string',
                'many' => '%s strings',
            ),
            'menus' => array(
                'label' => 'Menus',
                'group' => 'menus',
                'none_line' => 'No menus',
                'one' => '%s menu',
                'many' => '%s menus',
            ),
            'media' => array(
                'label' => 'Media',
                'group' => 'media items',
                'none_line' => 'No media',
                'one' => '%s media item',
                'many' => '%s media items',
            ),
        );
    }

    /**
     * Plain name for a missing class.
     *
     * @param string $class_name Class name, with or without a namespace.
     * @return string
     */
    private static function step_label($class_name)
    {
        $base = self::class_basename($class_name);
        if ($base === '') {
            return '';
        }

        $map = array(
            'Polylang_Detector' => 'Polylang check',
            'Polylang_Languages' => 'languages',
            'Polylang_Posts' => 'posts',
            'Polylang_Terms' => 'terms',
            'Polylang_Strings' => 'strings',
            'Polylang_Menus' => 'menus',
            'Polylang_Media' => 'media',
            'Tolka_Schema' => 'package builder',
            'Tolka_Writer' => 'saving',
            'Migration_Runner' => 'migration',
            'Migration_Report' => 'report',
            'Speed_Loader' => 'speed check',
            'Speed_Metrics' => 'speed check',
            'Speed_Estimate' => 'speed estimate',
        );

        if (isset($map[$base])) {
            return self::text($map[$base]);
        }

        $base = str_replace('%', '', $base);
        if (strlen($base) > 80) {
            $base = substr($base, 0, 80);
        }

        return $base;
    }

    /**
     * @param string $class_name Class name.
     * @return string
     */
    private static function class_basename($class_name)
    {
        $class_name = trim((string) $class_name);
        if ($class_name === '') {
            return '';
        }

        $class_name = str_replace('/', '\\', $class_name);
        $parts = explode('\\', $class_name);
        $base = end($parts);

        return is_string($base) ? $base : '';
    }

    /**
     * @param mixed $value A list, a single string, or something to ignore.
     * @return string[]
     */
    private static function string_list($value)
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $value = array($value);
        }

        if (!is_array($value)) {
            return array();
        }

        $list = array();
        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
            } elseif (is_int($item) || is_float($item)) {
                $item = trim((string) $item);
            } else {
                continue;
            }

            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Make an error readable as one sentence.
     *
     * @param string $text Raw error text.
     * @return string
     */
    private static function sentence($text)
    {
        $text = self::plain($text);
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($collapsed)) {
            $collapsed = preg_replace('/\s+/', ' ', $text);
        }

        $text = trim((string) $collapsed);
        if ($text === '') {
            return '';
        }

        $last = substr($text, -1);
        if ($last !== '.' && $last !== '!' && $last !== '?') {
            $text .= '.';
        }

        return self::capitalize($text);
    }

    /**
     * @param string $text Raw text.
     * @return string
     */
    private static function plain($text)
    {
        $text = str_replace("\0", '', (string) $text);

        if (function_exists('wp_strip_all_tags')) {
            return wp_strip_all_tags($text);
        }

        return strip_tags($text);
    }

    /**
     * @param string $text Sentence.
     * @return string
     */
    private static function capitalize($text)
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            $first = mb_substr($text, 0, 1, 'UTF-8');
            $rest = mb_substr($text, 1, $length, 'UTF-8');
            if (!is_string($rest)) {
                $rest = '';
            }
            if (is_string($first) && $first !== '' && preg_match('/^\p{Ll}$/u', $first)) {
                return mb_strtoupper($first, 'UTF-8') . $rest;
            }

            return $text;
        }

        if (preg_match('/^[a-z]/', $text)) {
            return strtoupper(substr($text, 0, 1)) . substr($text, 1);
        }

        return $text;
    }

    /**
     * @param string[] $parts Sentences or fragments. Empty parts are skipped.
     * @return string
     */
    private static function join_sentences(array $parts)
    {
        $kept = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $kept[] = $part;
            }
        }

        return implode(' ', $kept);
    }

    /**
     * @param string[] $parts Items to join with "and".
     * @return string
     */
    private static function join_and(array $parts)
    {
        $parts = array_values($parts);
        $count = count($parts);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $parts[0];
        }

        if ($count === 2) {
            return sprintf(self::text('%1$s and %2$s'), $parts[0], $parts[1]);
        }

        $last = array_pop($parts);

        return sprintf(
            self::text('%1$s, and %2$s'),
            implode(', ', $parts),
            $last
        );
    }

    /**
     * @param string[] $parts Items to join with "or".
     * @return string
     */
    private static function join_or(array $parts)
    {
        $parts = array_values($parts);
        $count = count($parts);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $parts[0];
        }

        if ($count === 2) {
            return sprintf(self::text('%1$s or %2$s'), $parts[0], $parts[1]);
        }

        $last = array_pop($parts);

        return sprintf(
            self::text('%1$s, or %2$s'),
            implode(', ', $parts),
            $last
        );
    }

    /**
     * @param int $number Whole number.
     * @return string
     */
    private static function number($number)
    {
        $number = (int) $number;
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number);
        }

        return (string) $number;
    }

    /**
     * Translate when WordPress is loaded. Otherwise return the English sentence.
     *
     * @param string $message English message.
     * @return string
     */
    private static function text($message)
    {
        if (function_exists('__')) {
            return __($message, 'migration-manager');
        }

        return $message;
    }
}
