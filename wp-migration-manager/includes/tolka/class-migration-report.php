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
        $report['errors'] = array();

        if (self::remember($report) === false) {
            $report = self::note_save_failure($report);
        }

        $report['lines'] = self::string_lines(isset($report['lines']) ? $report['lines'] : array());
        $report['lines'] = self::arrange_section_lines($report['lines']);
        $report = self::pin_titles($report);

        return $report;
    }

    /**
     * Keep the title that matches this run, and keep the outcome line in step.
     *
     * A partial finish stays “The migration finished with problems.” A clean
     * run stays “The migration is complete.” The save error is never written
     * into either title.
     *
     * @param array $report Formatted report.
     * @return array
     */
    private static function pin_titles(array $report)
    {
        $partial = self::partial_title();
        $clean = self::text('The migration is complete.');
        $outcome = self::outcome_value($report);

        if (is_string($outcome) && $outcome === $partial && empty($report['ok'])) {
            $report['title'] = $partial;
        }

        if (!empty($report['ok']) && isset($report['title']) && is_string($report['title'])) {
            if (stripos($report['title'], 'could not be saved') !== false) {
                $report['title'] = $clean;
            }
        }

        return self::sync_outcome($report);
    }

    /**
     * @param array $report Formatted report.
     * @return string|null
     */
    private static function outcome_value(array $report)
    {
        if (!isset($report['lines']) || !is_array($report['lines'])) {
            return null;
        }

        $label = self::text('Outcome');
        foreach ($report['lines'] as $line) {
            if (!is_array($line) || !isset($line['label']) || $line['label'] !== $label) {
                continue;
            }

            return isset($line['value']) && is_string($line['value']) ? $line['value'] : null;
        }

        return null;
    }

    /**
     * @param array $report Formatted report.
     * @return array
     */
    private static function sync_outcome(array $report)
    {
        if (!isset($report['title']) || !is_string($report['title']) || !isset($report['lines']) || !is_array($report['lines'])) {
            return $report;
        }

        $label = self::text('Outcome');
        foreach ($report['lines'] as $index => $line) {
            if (!is_array($line) || !isset($line['label']) || $line['label'] !== $label) {
                continue;
            }

            $report['lines'][$index]['value'] = $report['title'];
            break;
        }

        return $report;
    }

    /**
     * The six section lines stay in contract order, one of each.
     *
     * @param array $lines Report lines.
     * @return array<int, array{label: string, value: string}>
     */
    private static function arrange_section_lines(array $lines)
    {
        $order = array();
        foreach (self::section_catalog() as $meta) {
            $order[] = self::text($meta['label']);
        }

        $found = array();
        $seen_section = false;
        $before = 0;

        foreach ($lines as $line) {
            $label = (is_array($line) && isset($line['label']) && is_string($line['label'])) ? $line['label'] : '';
            if (!in_array($label, $order, true)) {
                if (!$seen_section) {
                    $before++;
                }
                continue;
            }

            $seen_section = true;
            if (!isset($found[$label])) {
                $found[$label] = $line;
            }
        }

        $rest = array();
        foreach ($lines as $line) {
            $label = (is_array($line) && isset($line['label']) && is_string($line['label'])) ? $line['label'] : '';
            if (in_array($label, $order, true)) {
                continue;
            }
            $rest[] = $line;
        }

        $section_lines = array();
        foreach ($order as $label) {
            if (isset($found[$label])) {
                $section_lines[] = $found[$label];
                continue;
            }

            $made = self::line($label, '0');
            if ($made !== null) {
                $section_lines[] = $made;
            }
        }

        if (!$seen_section) {
            $before = 0;
            foreach ($rest as $index => $line) {
                $label = (isset($line['label']) && is_string($line['label'])) ? $line['label'] : '';
                if ($label === self::text('Outcome') || $label === self::text('When')) {
                    $before = $index + 1;
                }
            }
        }

        array_splice($rest, $before, 0, $section_lines);

        return array_values($rest);
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
        $write_is_null = array_key_exists('write', $run) && $run['write'] === null;
        $write = (isset($run['write']) && is_array($run['write'])) ? $run['write'] : null;
        $package = self::package_state($run_ok, $write, $write_is_null);
        $counts = self::resolve_counts($run, $write);
        $errors = self::collect_errors($run, $write);
        $missing = self::collect_missing($run);
        $unavailable = self::missing_section_keys($missing['classes']);
        $description = self::describe_content($counts, $unavailable);
        $gap = self::storage_gap($run, $write, $counts, $package);
        $description['mismatch'] = $gap['sentence'];
        $description['shortfall'] = $gap['shortfall'];
        $description['writer_missing'] = $write_is_null;
        $description['polylang_kept'] = !self::writer_deleted_polylang($write);
        $total = self::total_items($counts);
        $has_problems = !empty($errors) || !empty($missing['labels']) || $description['mismatch'] !== '';
        $partial = self::is_partial_finish($package, $has_problems, $description['shortfall'], $write);
        $status = self::decide($run_ok, $package, $has_problems, $total);
        if ($partial) {
            $status = 'problems';
        }
        $inactive = self::polylang_is_inactive($errors);
        $next = self::next_step($status, $inactive, count($missing['labels']), $has_problems);
        $title = self::title_for($status);
        $default_language = '';
        if ($status === 'complete' || $status === 'unconfirmed' || ($status === 'problems' && $total > 0)) {
            $default_language = self::default_language_sentence($run);
        }
        $missing_sentence = self::missing_sentence($missing['labels'], $missing['bases']);
        $summary = self::summary_for($status, $package, $description, $total, $missing['labels'], $missing['bases'], $errors, $next, $run_ok, $default_language);
        $ok = ($status === 'complete' || $status === 'empty');
        if (!$ok) {
            $title = self::without_complete_claim($title);
            $summary = self::without_complete_claim($summary);
        }

        return array(
            'ok' => $ok,
            'title' => $title,
            'summary' => $summary,
            'lines' => self::lines_for($run, $title, $counts, $unavailable, $package, $total, $next, $missing['lines'], $errors, $description['mismatch'], $description['shortfall'], $missing_sentence, $description['polylang_kept']),
        );
    }

    /**
     * Store the report for the next time the site owner opens the screen.
     *
     * WordPress update_option returns false when the stored value is already
     * the same. That is still a successful save. The failure is only when the
     * value is not stored afterward. A missing update_option does not fatal.
     *
     * @param array $report Formatted report.
     * @return bool True when there is nothing to do or the option holds this report.
     */
    private static function remember(array $report)
    {
        if (!function_exists('update_option')) {
            return true;
        }

        $updated = update_option(self::OPTION_LAST_REPORT, $report);
        if ($updated !== false) {
            return true;
        }

        return self::option_has_report($report);
    }

    /**
     * @param array $report Formatted report.
     * @return bool
     */
    private static function option_has_report(array $report)
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $stored = get_option(self::OPTION_LAST_REPORT, null);

        return self::reports_match($stored, $report);
    }

    /**
     * @param mixed $stored Stored option value.
     * @param array $report Formatted report.
     * @return bool
     */
    private static function reports_match($stored, array $report)
    {
        if ($stored === $report) {
            return true;
        }

        if (function_exists('maybe_serialize')) {
            return maybe_serialize($stored) === maybe_serialize($report);
        }

        return serialize($stored) === serialize($report);
    }

    /**
     * Keep the migration result and record that this report was not stored.
     *
     * @param array $report Formatted report.
     * @return array
     */
    private static function note_save_failure(array $report)
    {
        $message = self::text('The report could not be saved.');
        if (!isset($report['errors']) || !is_array($report['errors'])) {
            $report['errors'] = array();
        }

        $report['errors'][] = $message;
        $report['summary'] = trim($report['summary'] . ' ' . $message);
        $report['lines'][] = self::line(self::text('Report'), $message);

        return $report;
    }

    /**
     * Saved, failed, or not included in the run.
     *
     * @param bool       $run_ok        Overall runner flag.
     * @param array|null $write         Writer result, when the runner included one.
     * @param bool       $write_is_null True when the run set write to null.
     * @return string saved|failed|unknown
     */
    private static function package_state($run_ok, $write, $write_is_null)
    {
        if ($write_is_null) {
            return 'failed';
        }

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

        $from_package = self::counts_from_package($run);
        if ($from_package['present']) {
            return $from_package['values'];
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
        $raw = self::gather_error_text(isset($run['errors']) ? $run['errors'] : array());

        if (is_array($write)) {
            if (isset($write['errors'])) {
                $raw = array_merge($raw, self::gather_error_text($write['errors']));
            }
        }

        foreach (array_keys(self::section_catalog()) as $section) {
            if (isset($run[$section])) {
                $raw = array_merge($raw, self::prefix_section(self::envelope_errors($run[$section]), $section));
            }
            if (isset($run['package']) && is_array($run['package']) && isset($run['package'][$section])) {
                $raw = array_merge($raw, self::prefix_section(self::envelope_errors($run['package'][$section]), $section));
            }
            if (is_array($write) && isset($write[$section])) {
                $raw = array_merge($raw, self::prefix_section(self::envelope_errors($write[$section]), $section));
            }
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
     * Strings from a list, plus strings nested under a section name.
     *
     * @param mixed $errors Error list or section map.
     * @return string[]
     */
    private static function gather_error_text($errors)
    {
        $raw = self::error_strings($errors);
        if (!is_array($errors)) {
            return $raw;
        }

        $sections = array_keys(self::section_catalog());
        foreach ($errors as $key => $value) {
            if (!is_string($key) || !in_array($key, $sections, true)) {
                continue;
            }
            $raw = array_merge($raw, self::prefix_section(self::error_strings($value), $key));
        }

        return $raw;
    }

    /**
     * Errors stored on one section envelope.
     *
     * @param mixed $section Section value.
     * @return string[]
     */
    private static function envelope_errors($section)
    {
        if (!is_array($section) || !isset($section['errors'])) {
            return array();
        }

        return self::error_strings($section['errors']);
    }

    /**
     * @param string[] $messages Error strings.
     * @param string   $section  Section key.
     * @return string[]
     */
    private static function prefix_section(array $messages, $section)
    {
        $catalog = self::section_catalog();
        if (!isset($catalog[$section]) || empty($messages)) {
            return $messages;
        }

        $label = self::text($catalog[$section]['label']);
        $prefixed = array();
        foreach ($messages as $message) {
            $prefixed[] = $label . ': ' . $message;
        }

        return $prefixed;
    }

    /**
     * True only when the writer says it deleted Polylang data.
     *
     * @param array|null $write Writer result.
     * @return bool
     */
    private static function writer_deleted_polylang($write)
    {
        if (!is_array($write)) {
            return false;
        }

        if (isset($write['polylang_deleted']) && $write['polylang_deleted'] === true) {
            return true;
        }

        if (isset($write['deleted_polylang']) && $write['deleted_polylang'] === true) {
            return true;
        }

        return false;
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

        $bases = array();

        foreach ($classes as $class_name) {
            $label = self::step_label($class_name);
            $base = self::class_basename($class_name);
            if ($label === '' || $base === '' || !preg_match('/[A-Za-z]/', $base)) {
                continue;
            }

            $key = strtolower($base);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = $label;
            $bases[] = $base;
            $lines[] = sprintf(
                self::text('%1$s (%2$s) is missing, so that part was skipped.'),
                $base,
                $label
            );
        }

        return array(
            'classes' => $classes,
            'labels' => $labels,
            'bases' => $bases,
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
     * Some items were saved, and something else still went wrong.
     *
     * @param string     $package     Package state.
     * @param bool       $has_problems Errors, missing steps, or a count gap.
     * @param bool       $shortfall   Stored fewer items than the package contained.
     * @param array|null $write       Writer result.
     * @return bool
     */
    private static function is_partial_finish($package, $has_problems, $shortfall, $write)
    {
        if ($package === 'saved' && $has_problems) {
            return true;
        }

        return $shortfall && self::any_positive_stored($write);
    }

    /**
     * @param array|null $write Writer result.
     * @return bool
     */
    private static function any_positive_stored($write)
    {
        if (!is_array($write)) {
            return false;
        }

        $source = (isset($write['counts']) && is_array($write['counts'])) ? $write['counts'] : $write;
        foreach (array_keys(self::section_catalog()) as $key) {
            $saved = self::count_if_set($source, $key);
            if ($saved !== null && $saved > 0) {
                return true;
            }
        }

        return false;
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
            return self::text('Nothing was found on this site.');
        }

        if ($status === 'complete') {
            return self::text('The migration is complete.');
        }

        if ($status === 'unconfirmed') {
            return self::text('The migration finished, but the save was not confirmed.');
        }

        if ($status === 'problems') {
            return self::partial_title();
        }

        return self::text('The migration did not finish.');
    }

    /**
     * Partial finishes use this title, which does not say the run is complete.
     *
     * @return string
     */
    private static function partial_title()
    {
        $title = 'The migration finished with problems.';
        $translated = self::text($title);
        if (!is_string($translated) || stripos($translated, 'complete') !== false) {
            return $title;
        }

        return $translated;
    }

    /**
     * A failed report must not use the clean-run sentence.
     *
     * @param string $text Title or summary.
     * @return string
     */
    private static function without_complete_claim($text)
    {
        $claim = self::text('The migration is complete.');
        $replacement = self::text('The migration did not finish.');
        if ($text === $claim) {
            return $replacement;
        }

        return str_replace($claim, $replacement, $text);
    }

    /**
     * Finished sentences for the status the site owner is in.
     *
     * @param string               $status      Report status.
     * @param string               $package     Package state.
     * @param array<string, string> $description Present phrase and absent sentence.
     * @param int                  $total       Sum of counts.
     * @param string[]             $labels      Missing step labels.
     * @param string[]             $bases       Missing class names, without a namespace.
     * @param string[]             $errors      Error sentences.
     * @param string               $next        Next step sentence.
     * @param bool                 $run_ok            Runner ok flag.
     * @param string               $default_language  Sentence naming the default language, or empty.
     * @return string
     */
    private static function summary_for($status, $package, array $description, $total, array $labels, array $bases, array $errors, $next, $run_ok, $default_language)
    {
        $closing = !isset($description['polylang_kept']) || $description['polylang_kept']
            ? self::text('Polylang data was left in place.')
            : self::text('Polylang data was removed.');
        $missing_sentence = self::missing_sentence($labels, $bases);
        $problem_lead = self::problem_lead($errors);

        if ($status === 'complete') {
            return self::join_sentences(array(
                self::packaged_lead($description),
                $description['absent'],
                $default_language,
                self::text('The package is saved on this site.'),
                $closing,
            ));
        }

        if ($status === 'empty') {
            $parts = array(
                self::text('Nothing was found on this site.'),
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
                $default_language,
                self::text('The plugin could not confirm that the Tolka package was saved.'),
                $next,
                $closing,
            ));
        }

        if ($status === 'problems') {
            $parts = array(
                self::partial_title(),
            );

            if (!empty($description['shortfall'])) {
                $parts[] = $description['mismatch'];
                $parts[] = self::text('The Tolka package on this site is incomplete.');
                if ($package !== 'saved') {
                    $parts[] = self::text('That is not a finished migration.');
                }
                $parts[] = $description['absent'];
                $parts[] = $default_language;
            } elseif ($total > 0) {
                $parts[] = self::packaged_lead($description);
                $parts[] = $description['absent'];
                $parts[] = $default_language;
            } else {
                $parts[] = self::text('The migration reported a problem before any content was packaged.');
            }

            if ($package === 'saved' && empty($description['shortfall'])) {
                $parts[] = self::text('The package is saved on this site.');
            } elseif ($package !== 'saved') {
                $parts[] = self::text('The plugin could not confirm that the Tolka package was saved.');
            }

            if (!$run_ok && $missing_sentence === '' && $problem_lead === '' && $description['mismatch'] === '') {
                $parts[] = self::text('The migration did not report a clean finish.');
            }

            $parts[] = $missing_sentence;
            if (empty($description['shortfall'])) {
                $parts[] = $description['mismatch'];
            }
            $parts[] = $problem_lead;
            $parts[] = $next;
            $parts[] = $closing;

            return self::join_sentences($parts);
        }

        $parts = array();
        if (!empty($description['writer_missing'])) {
            $parts[] = self::text('The writer did not return a result, so the Tolka package was not saved.');
            if ($total > 0 && $description['present'] !== '') {
                $parts[] = sprintf(self::text('This site has %s.'), $description['present']);
                $parts[] = $description['absent'];
            }
        } elseif (!empty($description['shortfall'])) {
            $parts[] = $description['mismatch'];
            $parts[] = self::text('That is not a finished migration.');
        } elseif ($total > 0 && $description['present'] !== '') {
            $parts[] = sprintf(
                self::text('The migration did not finish. This site has %s, but that content was not saved into a Tolka package.'),
                $description['present']
            );
            $parts[] = $description['absent'];
        } else {
            $parts[] = self::text('The migration did not finish, so the Tolka package was not saved.');
        }

        $parts[] = $missing_sentence;
        if (empty($description['shortfall'])) {
            $parts[] = $description['mismatch'];
        }
        $parts[] = $problem_lead;
        $parts[] = $next;
        $parts[] = $closing;

        return self::join_sentences($parts);
    }

    /**
     * Counts taken from the package item lists, when the run omitted counts.
     *
     * @param array $run Runner result.
     * @return array{present: bool, values: array<string, int>}
     */
    private static function counts_from_package(array $run)
    {
        $values = array();
        $present = false;

        foreach (array_keys(self::section_catalog()) as $key) {
            $count = self::package_section_count($run, $key);
            if ($count === null) {
                $values[$key] = 0;
                continue;
            }

            $values[$key] = $count;
            $present = true;
        }

        return array(
            'present' => $present,
            'values' => $values,
        );
    }

    /**
     * How many items a package section contains, or null when it is absent.
     *
     * @param array  $run Runner result.
     * @param string $key Section key.
     * @return int|null
     */
    private static function package_section_count(array $run, $key)
    {
        if (!isset($run['package']) || !is_array($run['package']) || !array_key_exists($key, $run['package'])) {
            return null;
        }

        $section = $run['package'][$key];
        if (is_array($section) && array_key_exists('items', $section) && is_array($section['items'])) {
            $section = $section['items'];
        }

        if (!is_array($section)) {
            return null;
        }

        return count($section);
    }

    /**
     * Say so when the writer stored fewer items than the package contained.
     *
     * @param array      $run       Runner result.
     * @param array|null $write     Writer result.
     * @param array      $collected Counts shown in the report.
     * @param string     $package   Package state.
     * @return array{sentence: string, shortfall: bool}
     */
    private static function storage_gap(array $run, $write, array $collected, $package)
    {
        $empty = array(
            'sentence' => '',
            'shortfall' => false,
        );
        $write_failed = is_array($write) && array_key_exists('ok', $write) && $write['ok'] !== true;
        if (!is_array($write) || (!$write_failed && $package !== 'saved')) {
            return $empty;
        }

        $run_counts = (isset($run['counts']) && is_array($run['counts'])) ? $run['counts'] : array();
        $package_present = isset($run['package']) && is_array($run['package']);
        if (!self::read_counts($run_counts)['present'] && !$package_present) {
            return $empty;
        }

        $source = (isset($write['counts']) && is_array($write['counts'])) ? $write['counts'] : $write;
        $short = array();
        $extra = array();
        $compared_package = false;

        foreach (self::section_catalog() as $key => $meta) {
            $saved = self::count_if_set($source, $key);
            if ($saved === null) {
                continue;
            }

            $package_count = self::package_section_count($run, $key);
            if ($package_count !== null) {
                $found = $package_count;
            } else {
                $found = isset($collected[$key]) ? (int) $collected[$key] : 0;
            }
            if ($saved === $found) {
                continue;
            }

            if ($saved < $found) {
                if ($package_count !== null) {
                    $compared_package = true;
                }
                if ($saved === 0 && $found === 1) {
                    $short[] = sprintf(
                        self::text('the %s was not saved'),
                        self::noun($meta, 1)
                    );
                } elseif ($saved === 0) {
                    $short[] = sprintf(
                        self::text('none of the %1$s %2$s were saved'),
                        self::number($found),
                        self::noun($meta, $found)
                    );
                } else {
                    $short[] = sprintf(
                        ($saved === 1)
                            ? self::text('%1$s of %2$s %3$s was saved')
                            : self::text('%1$s of %2$s %3$s were saved'),
                        self::number($saved),
                        self::number($found),
                        self::noun($meta, $found)
                    );
                }
                continue;
            }

            $extra[] = sprintf(
                ($saved === 1)
                    ? self::text('%1$s %2$s was saved from the %3$s that were found')
                    : self::text('%1$s %2$s were saved from the %3$s that were found'),
                self::number($saved),
                self::noun($meta, $saved),
                self::number($found)
            );
        }

        $parts = array();
        $shortfall = false;
        if (!empty($short)) {
            $shortfall = true;
            if ($write_failed && $compared_package) {
                $lead = self::text('Saving did not finish. The package contained more than was stored.');
            } elseif ($write_failed) {
                $lead = self::text('Saving did not finish.');
            } elseif ($compared_package) {
                $lead = self::text('The package contained more than was stored.');
            } else {
                $lead = self::text('Not everything that was found was saved.');
            }
            $parts[] = $lead . ' ' . self::capitalize(self::join_and($short)) . '.';
        }
        if (!empty($extra)) {
            $parts[] = self::text('The saved package includes more than was found.') . ' ' . self::capitalize(self::join_and($extra)) . '.';
        }

        return array(
            'sentence' => self::join_sentences($parts),
            'shortfall' => $shortfall,
        );
    }

    /**
     * @param array  $counts Count map.
     * @param string $key    Section key.
     * @return int|null Null when that section was not reported.
     */
    private static function count_if_set(array $counts, $key)
    {
        if (!isset($counts[$key]) || is_bool($counts[$key]) || !is_numeric($counts[$key])) {
            return null;
        }

        $count = (int) $counts[$key];

        return ($count > 0) ? $count : 0;
    }

    /**
     * @param array $meta  Section copy.
     * @param int   $count Count the noun should agree with.
     * @return string
     */
    private static function noun(array $meta, $count)
    {
        if ((int) $count === 1) {
            return self::text($meta['singular']);
        }

        return self::text($meta['group']);
    }

    /**
     * @param array $run Runner result.
     * @return string
     */
    private static function default_language_sentence(array $run)
    {
        if (!isset($run['sample']['languages']) || !is_array($run['sample']['languages'])) {
            return '';
        }

        foreach ($run['sample']['languages'] as $language) {
            if (!is_array($language) || empty($language['is_default'])) {
                continue;
            }

            $name = '';
            if (isset($language['name']) && is_string($language['name'])) {
                $name = self::present_name('languages', $language['name']);
            }
            if ($name === '' && isset($language['slug']) && is_string($language['slug'])) {
                $name = self::present_name('languages', $language['slug']);
            }
            if ($name === '') {
                continue;
            }

            return sprintf(self::text('%s is the default language.'), $name);
        }

        return '';
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
     * One sentence a site owner can read when classes were missing.
     *
     * @param string[] $labels Plain names, parallel to $bases.
     * @param string[] $bases  Class names without a namespace.
     * @return string
     */
    private static function missing_sentence(array $labels, array $bases)
    {
        $named = array();
        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $base = isset($bases[$i]) && $bases[$i] !== '' ? $bases[$i] : $labels[$i];
            $named[] = sprintf(self::text('%1$s (%2$s)'), $base, $labels[$i]);
        }

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return sprintf(
                self::text('A required class is missing, so that part was skipped: %s.'),
                $named[0]
            );
        }

        return sprintf(
            self::text('Required classes are missing, so those parts were skipped: %s.'),
            self::join_and($named)
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

        $first = self::excerpt($errors[0], 180);

        if ($count === 1) {
            return $first;
        }

        $more = $count - 1;
        if ($more === 1) {
            $rest = self::text('1 more problem is listed below.');
        } else {
            $rest = sprintf(self::text('%s more problems are listed below.'), self::number($more));
        }

        return $first . ' ' . $rest;
    }

    /**
     * Keep a summary sentence short. The full text stays in the lines.
     *
     * @param string $text  Sentence.
     * @param int    $limit Maximum characters.
     * @return string
     */
    private static function excerpt($text, $limit)
    {
        $text = trim((string) $text);
        $limit = (int) $limit;
        if ($limit < 8) {
            $limit = 8;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $limit - 3, 'UTF-8')) . '...';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, $limit - 3)) . '...';
    }

    /**
     * @param array                 $run          Runner result.
     * @param string                $title        Report title.
     * @param array<string, int>    $counts       Section counts.
     * @param array<string, bool>   $unavailable  Unreadable sections.
     * @param string                $package      Package state.
     * @param int                   $total        Sum of counts.
     * @param string                $next         Next step.
     * @param string[]              $missing_lines Per-step sentences.
     * @param string[]              $errors       Error sentences.
     * @param string                $mismatch     Saved-count warning, or empty.
     * @param bool                  $shortfall         Stored fewer items than the package contained.
     * @param string               $missing_sentence  One sentence naming every missing class.
     * @param bool                 $polylang_kept     Writer did not delete Polylang data.
     * @return array<int, array{label: string, value: string}>
     */
    private static function lines_for(array $run, $title, array $counts, array $unavailable, $package, $total, $next, array $missing_lines, array $errors, $mismatch, $shortfall, $missing_sentence, $polylang_kept)
    {
        $lines = array(
            self::line(self::text('Outcome'), $title),
            self::line(self::text('When'), self::finished_at()),
        );

        foreach (self::section_catalog() as $key => $meta) {
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $lines[] = self::line(self::text($meta['label']), self::whole_number($count));
        }

        if ($shortfall) {
            foreach (self::stored_count_lines($run, $counts) as $stored_line) {
                $lines[] = $stored_line;
            }
        }

        $sample = (isset($run['sample']) && is_array($run['sample'])) ? $run['sample'] : array();
        foreach (self::example_lines($sample) as $example) {
            $lines[] = $example;
        }

        $lines[] = self::line(self::text('Tolka package'), self::package_value($package, $total, $shortfall));
        if (array_key_exists('write', $run) && $run['write'] === null) {
            $lines[] = self::line(self::text('Writer'), self::text('No result was returned.'));
        }
        if ($mismatch !== '') {
            $lines[] = self::line(self::text('Saved counts'), $mismatch);
        }
        $lines[] = self::line(
            self::text('Polylang data'),
            $polylang_kept
                ? self::text('Polylang data was left in place.')
                : self::text('Polylang data was removed.')
        );

        if ($next !== '') {
            $lines[] = self::line(self::text('Next'), $next);
        }

        if (count($missing_lines) > 1 && $missing_sentence !== '') {
            $lines[] = self::line(self::text('Missing classes'), $missing_sentence);
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
     * One line per section the writer stored only in part.
     *
     * @param array              $run       Runner result.
     * @param array<string, int> $collected Counts shown for the package.
     * @return array<int, array{label: string, value: string}>
     */
    private static function stored_count_lines(array $run, array $collected)
    {
        if (!isset($run['write']) || !is_array($run['write'])) {
            return array();
        }

        $write = $run['write'];
        $source = (isset($write['counts']) && is_array($write['counts'])) ? $write['counts'] : $write;
        $lines = array();

        foreach (self::section_catalog() as $key => $meta) {
            $saved = self::count_if_set($source, $key);
            if ($saved === null) {
                continue;
            }

            $package_count = self::package_section_count($run, $key);
            if ($package_count !== null) {
                $found = $package_count;
            } else {
                $found = isset($collected[$key]) ? (int) $collected[$key] : 0;
            }

            if ($saved >= $found) {
                continue;
            }

            $lines[] = self::line(
                sprintf(self::text('%s stored'), self::text($meta['label'])),
                sprintf(self::text('%1$s of %2$s'), self::number($saved), self::number($found))
            );
        }

        return $lines;
    }

    /**
     * A section count is a string of a whole number, never a float.
     *
     * @param mixed $count Raw count.
     * @return string
     */
    private static function whole_number($count)
    {
        if (is_string($count)) {
            $count = trim($count);
        }

        if (is_bool($count) || !is_numeric($count)) {
            return '0';
        }

        $whole = (int) $count;
        if ($whole < 0) {
            $whole = 0;
        }

        return sprintf('%d', $whole);
    }

    /**
     * @param string $package   Package state.
     * @param int    $total     Sum of counts.
     * @param bool   $shortfall Stored fewer items than the package contained.
     * @return string
     */
    private static function package_value($package, $total, $shortfall)
    {
        if ($package === 'saved' && $shortfall) {
            return self::text('Saved, but it is incomplete');
        }

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
     * UTC time of this report, so a saved result still says when it ran.
     *
     * @return string
     */
    private static function finished_at()
    {
        if (function_exists('wp_date')) {
            return wp_date('j F Y, H:i T');
        }

        return gmdate('j F Y, H:i') . ' UTC';
    }

    /**
     * A few real names from the run sample, so the owner can recognize the content.
     *
     * @param array $sample Sample items keyed by section.
     * @return array<int, array{label: string, value: string}>
     */
    private static function example_lines(array $sample)
    {
        $labels = array(
            'languages' => 'Example language',
            'posts' => 'Example post',
            'terms' => 'Example term',
            'strings' => 'Example string',
            'menus' => 'Example menu',
            'media' => 'Example file',
        );
        $lines = array();
        $shown = 0;

        foreach ($labels as $section => $label) {
            if ($shown >= 8 || !isset($sample[$section]) || !is_array($sample[$section])) {
                continue;
            }

            $in_section = 0;
            foreach ($sample[$section] as $item) {
                if ($shown >= 8 || $in_section >= 2) {
                    break;
                }

                $name = self::example_name($section, $item);
                if ($name === '') {
                    continue;
                }

                $lines[] = self::line(self::text($label), $name);
                $in_section++;
                $shown++;
            }
        }

        return $lines;
    }

    /**
     * @param string $section languages, posts, terms, strings, menus, or media.
     * @param mixed  $item    One sample item.
     * @return string
     */
    private static function example_name($section, $item)
    {
        if (!is_array($item)) {
            return '';
        }

        if ($section === 'strings') {
            if (isset($item['string']) && is_string($item['string'])) {
                $source = self::present_name('strings', $item['string']);
                if ($source !== '') {
                    return $source;
                }
            }

            $translated = self::translation_names('strings', $item);
            if ($translated !== '') {
                return $translated;
            }

            if (isset($item['name']) && is_string($item['name'])) {
                return self::present_name('strings', $item['name']);
            }

            return '';
        }

        if ($section === 'posts' || $section === 'terms' || $section === 'menus') {
            $from_translations = self::translation_names($section, $item);
            if ($from_translations !== '') {
                return $from_translations;
            }
        }

        if ($section === 'menus') {
            $from_items = self::menu_item_name($item);
            if ($from_items !== '') {
                return $from_items;
            }
        }

        $fields = array(
            'languages' => array('name', 'slug'),
            'posts' => array('title', 'excerpt', 'slug'),
            'terms' => array('name', 'slug'),
            'strings' => array('string', 'name'),
            'menus' => array('name', 'location'),
            'media' => array('file', 'title'),
        );
        $keys = isset($fields[$section]) ? $fields[$section] : array('title', 'name');

        foreach ($keys as $key) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $name = self::present_name($section, $item[$key]);
                if ($name !== '') {
                    return self::with_default_language($section, $item, $name);
                }
            }
        }

        if ($section === 'media') {
            foreach (array('title', 'alt') as $map_key) {
                if (!isset($item[$map_key]) || !is_array($item[$map_key])) {
                    continue;
                }
                $from_map = self::language_map_name($item[$map_key]);
                if ($from_map !== '') {
                    return $from_map;
                }
            }
        }

        return self::translation_names($section, $item);
    }

    /**
     * First menu link title, including titles nested under a language.
     *
     * @param array $item Menu sample.
     * @return string
     */
    private static function menu_item_name(array $item)
    {
        $lists = array();
        if (isset($item['items']) && is_array($item['items'])) {
            $lists[] = $item['items'];
        }
        if (isset($item['translations']) && is_array($item['translations'])) {
            foreach ($item['translations'] as $translated) {
                if (is_array($translated) && isset($translated['items']) && is_array($translated['items'])) {
                    $lists[] = $translated['items'];
                }
            }
        }

        foreach ($lists as $list) {
            foreach ($list as $entry) {
                if (!is_array($entry) || !isset($entry['title']) || !is_string($entry['title'])) {
                    continue;
                }
                $name = self::present_name('menus', $entry['title']);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param array $map Language slug to string.
     * @return string
     */
    private static function language_map_name(array $map)
    {
        $bits = array();
        foreach ($map as $lang => $value) {
            if (!is_string($value)) {
                continue;
            }
            $name = self::present_name('media', $value);
            if ($name === '') {
                continue;
            }
            $slug = is_string($lang) ? trim($lang) : '';
            if ($slug !== '' && preg_match('/^[A-Za-z0-9_-]{2,12}$/', $slug)) {
                $bits[] = self::without_trailing_period($name) . ' (' . $slug . ')';
            } else {
                $bits[] = $name;
            }
            if (count($bits) >= 3) {
                break;
            }
        }

        if (empty($bits)) {
            return '';
        }

        return self::excerpt(implode(', ', $bits), 120);
    }

    /**
     * @param string $section Section key.
     * @param array  $item    Sample item.
     * @return string
     */
    private static function translation_names($section, array $item)
    {
        if (!isset($item['translations']) || !is_array($item['translations'])) {
            return '';
        }

        $bits = array();
        foreach ($item['translations'] as $lang => $translated) {
            $name = '';
            if (is_string($translated)) {
                $name = self::present_name($section, $translated);
            } elseif (is_array($translated)) {
                foreach (array('title', 'name', 'excerpt', 'string', 'slug') as $key) {
                    if (isset($translated[$key]) && is_string($translated[$key])) {
                        $name = self::present_name($section, $translated[$key]);
                        if ($name !== '') {
                            break;
                        }
                    }
                }
            }

            if ($name === '') {
                continue;
            }

            $slug = is_string($lang) ? trim($lang) : '';
            if ($slug !== '' && preg_match('/^[A-Za-z0-9_-]{2,12}$/', $slug)) {
                $bits[] = self::without_trailing_period($name) . ' (' . $slug . ')';
            } else {
                $bits[] = $name;
            }

            if (count($bits) >= 3) {
                break;
            }
        }

        if (empty($bits)) {
            return '';
        }

        return self::excerpt(implode(', ', $bits), 120);
    }

    /**
     * @param string $section Section key.
     * @param array  $item    Sample item.
     * @param string $name    Display name.
     * @return string
     */
    private static function with_default_language($section, array $item, $name)
    {
        if ($section !== 'languages' || empty($item['is_default'])) {
            return $name;
        }

        return sprintf(self::text('%s, the default language'), $name);
    }

    /**
     * @param string $section Section key.
     * @param string $name    Raw name.
     * @return string
     */
    private static function present_name($section, $name)
    {
        $name = trim(self::plain($name));
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        if (!is_string($collapsed)) {
            $collapsed = preg_replace('/\s+/', ' ', $name);
        }
        $name = trim((string) $collapsed);
        if ($name === '') {
            return '';
        }

        if ($section === 'media') {
            $name = basename(str_replace('\\', '/', $name));
        }

        return self::excerpt($name, 80);
    }

    /**
     * Drop one sentence period so a language tag does not sit after it.
     *
     * @param string $name Display name.
     * @return string
     */
    private static function without_trailing_period($name)
    {
        if (substr($name, -1) === '.') {
            return substr($name, 0, -1);
        }

        return $name;
    }

    /**
     * A line needs a real label. A missing label is dropped.
     *
     * @param mixed $label Line label.
     * @param mixed $value Line value.
     * @return array{label: string, value: string}|null
     */
    private static function line($label, $value)
    {
        if (!is_string($label)) {
            if (!is_scalar($label) || $label === null || $label === false) {
                return null;
            }
            $label = (string) $label;
        }

        $label = trim($label);
        if ($label === '') {
            return null;
        }

        if (!is_string($value)) {
            if (is_scalar($value) || $value === null) {
                $value = (string) $value;
            } else {
                $value = '';
            }
        }

        return array(
            'label' => $label,
            'value' => $value,
        );
    }

    /**
     * Keep only lines whose label and value are strings.
     *
     * @param mixed $lines Candidate lines.
     * @return array<int, array{label: string, value: string}>
     */
    private static function string_lines($lines)
    {
        if (!is_array($lines)) {
            return array();
        }

        $kept = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $normalized = self::line(
                array_key_exists('label', $line) ? $line['label'] : null,
                array_key_exists('value', $line) ? $line['value'] : ''
            );
            if ($normalized === null) {
                continue;
            }

            $normalized['value'] = self::section_count_value($normalized['label'], $normalized['value']);
            $kept[] = $normalized;
        }

        return $kept;
    }

    /**
     * Section counts stay the string 0 when the count is zero or blank.
     *
     * @param string $label Line label.
     * @param string $value Line value.
     * @return string
     */
    private static function section_count_value($label, $value)
    {
        $is_section = false;
        foreach (self::section_catalog() as $meta) {
            if ($label === self::text($meta['label'])) {
                $is_section = true;
                break;
            }
        }

        if (!$is_section) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return '0';
        }

        return self::whole_number($value);
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
                'singular' => 'language',
                'one' => '%s language',
                'many' => '%s languages',
            ),
            'posts' => array(
                'label' => 'Posts',
                'group' => 'posts',
                'singular' => 'post',
                'one' => '%s post',
                'many' => '%s posts',
            ),
            'terms' => array(
                'label' => 'Terms',
                'group' => 'terms',
                'singular' => 'term',
                'one' => '%s term',
                'many' => '%s terms',
            ),
            'strings' => array(
                'label' => 'Strings',
                'group' => 'strings',
                'singular' => 'string',
                'one' => '%s string',
                'many' => '%s strings',
            ),
            'menus' => array(
                'label' => 'Menus',
                'group' => 'menus',
                'singular' => 'menu',
                'one' => '%s menu',
                'many' => '%s menus',
            ),
            'media' => array(
                'label' => 'Media',
                'group' => 'media items',
                'singular' => 'media item',
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
        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $base);
        if (is_string($split)) {
            $base = $split;
        }
        $base = str_replace(array('_', '-'), ' ', $base);
        $base = strtolower(trim((string) preg_replace('/\s+/', ' ', $base)));
        if ($base === '') {
            return '';
        }
        if (strlen($base) > 80) {
            $base = rtrim(substr($base, 0, 80));
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
     * Error text only. Numbers, lists, and objects are not sentences.
     *
     * @param mixed $value A string, a list of values, or something to ignore.
     * @return string[]
     */
    private static function error_strings($value)
    {
        if (is_string($value)) {
            $value = array($value);
        }

        if (!is_array($value)) {
            return array();
        }

        $list = array();
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
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
        $text = str_replace("\0", '', (string) $text);
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

        return number_format($number);
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
