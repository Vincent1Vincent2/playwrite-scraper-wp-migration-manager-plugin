<?php

/**
 * Collects Polylang exports and runs the Tolka migration.
 *
 * Preview reads the site and does not write. Run builds a package and asks
 * Tolka_Writer to store it when that class is available. Polylang data is
 * left in place: this class never deletes options, posts, or terms.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

class Migration_Runner
{
    /**
     * Maximum number of items returned for each preview section.
     */
    const SAMPLE_LIMIT = 20;

    /**
     * Strings longer than this may be shortened in the preview sample.
     * The package passed to the writer keeps the original string.
     */
    const SAMPLE_TEXT_LIMIT = 400;

    /**
     * Maximum number of error sentences kept in one list.
     */
    const ERROR_LIMIT = 30;

    /**
     * Six exporter envelopes, plus `missing` class names.
     *
     * The six section keys are always present. A class that is not loaded
     * keeps an empty item list, a finished error sentence, and an entry in
     * `missing`. The section key is not removed.
     *
     * @return array
     */
    public static function collect()
    {
        $missing = array();
        $collected = array();
        $map = static::exporters();

        foreach (self::section_keys() as $section) {
            $collected[$section] = self::read_section($section, self::class_for($map, $section), $missing);
        }

        $collected['missing'] = self::dedupe_strings($missing);

        return $collected;
    }

    /**
     * Counts, a sample of at most 20 items per section, errors, and missing classes.
     *
     * Also returns a finished `message`, `detail` (the first error, or an
     * empty string), a `state` of missing, incomplete, error, empty, or ready,
     * and per section: status, message, labels, error_count, and how many
     * items were left out of the sample. `counts`, `sample`, and `sections`
     * always contain languages, posts, terms, strings, menus, and media.
     * Does not call Tolka_Writer::write.
     *
     * @return array
     */
    public static function preview()
    {
        $collected = static::collect();
        $view = self::view($collected);

        $state = self::preview_state($view);
        $errors = self::cap_list($view['errors']);

        return self::with_sections(array(
            'counts' => $view['counts'],
            'sample' => $view['sample'],
            'errors' => $errors,
            'missing' => $view['missing'],
            'sections' => $view['sections'],
            'state' => $state,
            'message' => self::preview_message($view, $state),
            'detail' => self::first_error($errors),
        ), $collected);
    }

    /**
     * Collect, build the Tolka package, and store the writer result.
     *
     * `ok` is true only when the writer returns ok true, no errors were
     * recorded, and no class was missing. `write` is the writer return value,
     * or null when the writer was not called or did not return an array.
     * `state` is saved, empty, partial, failed, or blocked. `detail` is the
     * first error sentence, or an empty string when there is nothing to fix.
     *
     * @return array
     */
    public static function run()
    {
        $collected = static::collect();
        $view = self::view($collected);
        $blocking = array();
        $write = null;
        $package = null;
        $schema_class = static::schema_class();
        $writer_class = static::writer_class();
        $all_exporters_missing = self::all_exporters_missing($view);
        $built = self::build_package($collected, $schema_class, $package, $blocking);
        $attempted = false;

        if ($all_exporters_missing) {
            $blocking[] = self::line('The migration did not run because none of the Polylang exporters are available. Nothing was written, and existing Polylang data was not changed.');
            if (!$built) {
                self::note_missing_writer($writer_class, $blocking);
            }
        } elseif ($built) {
            $attempted = self::write_package($package, $writer_class, $write, $blocking);
        } else {
            self::note_missing_writer($writer_class, $blocking);
        }

        $missing = $view['missing'];
        if (!$built && self::class_is_missing($schema_class)) {
            $missing[] = $schema_class;
        }
        if (self::class_is_missing($writer_class)) {
            $missing[] = $writer_class;
        }
        $missing = self::dedupe_strings($missing);

        $had_errors = !empty($blocking) || !empty($view['errors']);
        $saved = is_array($write) && isset($write['ok']) && $write['ok'] === true;
        // A saved package is not a full success when any section reported an error.
        $ok = $saved && !$had_errors && empty($missing);
        $errors = self::merge_errors($blocking, $view['errors']);

        return self::with_sections(array(
            'ok' => $ok,
            'counts' => $view['counts'],
            'write' => $write,
            'errors' => $errors,
            'missing' => $missing,
            'sample' => $view['sample'],
            'sections' => $view['sections'],
            'state' => self::run_state($ok, $saved, $attempted, $view['counts']),
            'message' => self::run_message($ok, $saved, $attempted, $view, $errors),
            'detail' => self::first_error($errors),
        ), $collected);
    }

    /**
     * Section key to exporter class.
     *
     * @return array<string, string>
     */
    protected static function exporters()
    {
        return array(
            'languages' => 'WMM\\Tolka\\Polylang_Languages',
            'posts' => 'WMM\\Tolka\\Polylang_Posts',
            'terms' => 'WMM\\Tolka\\Polylang_Terms',
            'strings' => 'WMM\\Tolka\\Polylang_Strings',
            'menus' => 'WMM\\Tolka\\Polylang_Menus',
            'media' => 'WMM\\Tolka\\Polylang_Media',
        );
    }

    /**
     * @return string
     */
    protected static function schema_class()
    {
        return 'WMM\\Tolka\\Tolka_Schema';
    }

    /**
     * @return string
     */
    protected static function writer_class()
    {
        return 'WMM\\Tolka\\Tolka_Writer';
    }

    /**
     * @param array  $map
     * @param string $section
     * @return string
     */
    private static function class_for($map, $section)
    {
        if (!isset($map[$section]) || !is_string($map[$section])) {
            return '';
        }
        return trim($map[$section]);
    }

    /**
     * @return string[]
     */
    private static function section_keys()
    {
        return array('languages', 'posts', 'terms', 'strings', 'menus', 'media');
    }

    /**
     * @param string $section
     * @param string $class
     * @param array  $missing
     * @return array
     */
    private static function read_section($section, $class, &$missing)
    {
        $meta = self::catalog($section);

        if ($class === '') {
            return self::envelope(array(), array(self::line($meta['unregistered'])));
        }

        if (self::class_is_missing($class)) {
            $missing[] = $class;
            return self::envelope(array(), array(
                sprintf(self::line($meta['missing']), self::short_class($class)),
            ));
        }

        $problem = self::export_problem($class);
        if ($problem !== '') {
            $template = ($problem === 'missing') ? $meta['no_method'] : $meta['unusable'];
            return self::envelope(array(), array(
                sprintf(self::line($template), self::short_class($class)),
            ));
        }

        try {
            $result = self::call_export($class);
        } catch (\Throwable $e) {
            return self::envelope(array(), array(
                sprintf(self::line($meta['threw']), self::safe_exception($e)),
            ));
        }

        return self::normalize_export($section, $result);
    }

    /**
     * @param string $section
     * @param mixed  $result
     * @return array
     */
    private static function normalize_export($section, $result)
    {
        $meta = self::catalog($section);

        if (is_object($result) && \function_exists('is_wp_error') && \is_wp_error($result)) {
            $text = '';
            if (\method_exists($result, 'get_error_message')) {
                $text = self::clean_error_text($result->get_error_message());
            }
            if ($text === '') {
                $text = self::line($meta['invalid']);
            } else {
                $text = self::present_error($section, $text);
            }
            return self::envelope(array(), array($text));
        }

        if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) {
            return self::envelope(array(), array(self::line($meta['invalid'])));
        }

        $raw_errors = array();
        if (isset($result['errors']) && is_array($result['errors'])) {
            $raw_errors = $result['errors'];
        } elseif (isset($result['errors']) && is_string($result['errors'])) {
            $raw_errors = array($result['errors']);
        } elseif (isset($result['errors']) && $result['errors'] !== array() && $result['errors'] !== null && $result['errors'] !== '') {
            $raw_errors = array($result['errors']);
        }

        $errors = array();
        $dropped = 0;
        foreach ($raw_errors as $error) {
            $candidate = $error;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                $candidate = $error['message'];
            }
            if (self::is_blank_error($candidate)) {
                continue;
            }
            $text = self::clean_error_text($error);
            if ($text === '') {
                if (is_string($error)) {
                    continue;
                }
                $dropped++;
                continue;
            }
            $errors[] = self::present_error($section, $text);
        }

        if ($dropped > 0 && empty($errors)) {
            $errors[] = self::line($meta['undisplayable']);
        }

        return self::envelope($result['items'], self::dedupe_strings($errors));
    }

    /**
     * @param array $items
     * @param array $errors
     * @return array
     */
    private static function envelope($items, $errors)
    {
        return array(
            'items' => $items,
            'errors' => array_values($errors),
        );
    }

    /**
     * Item count as an integer. This is the full list, not the sample.
     *
     * @param array $items
     * @return int
     */
    private static function full_count($items)
    {
        return (int) count($items);
    }

    /**
     * The first SAMPLE_LIMIT items, in exporter order. Not a sorted subset.
     *
     * @param array $items
     * @return array
     */
    private static function take_sample($items)
    {
        $limit = (int) self::SAMPLE_LIMIT;
        if ($limit < 1 || !is_array($items)) {
            return array();
        }
        $sample = array();
        // Walk insertion order. A numeric for-loop would follow the keys and
        // could pull a later post forward when those keys sort first.
        foreach ($items as $item) {
            if (count($sample) >= $limit) {
                break;
            }
            $sample[] = $item;
        }
        return $sample;
    }

    /**
     * Copy the six envelopes onto a result. Missing classes keep an empty item list.
     *
     * @param array $result
     * @param array $collected
     * @return array
     */
    private static function with_sections($result, $collected)
    {
        foreach (self::section_keys() as $section) {
            $result[$section] = self::section_envelope($collected, $section);
        }
        return $result;
    }

    /**
     * @param array  $collected
     * @param string $section
     * @return array
     */
    private static function section_envelope($collected, $section)
    {
        $items = array();
        $errors = array();
        if (isset($collected[$section]) && is_array($collected[$section])) {
            if (isset($collected[$section]['items']) && is_array($collected[$section]['items'])) {
                foreach ($collected[$section]['items'] as $item) {
                    $items[] = self::present_item($item, 0);
                }
            }
            if (isset($collected[$section]['errors']) && is_array($collected[$section]['errors'])) {
                foreach ($collected[$section]['errors'] as $error) {
                    if (is_string($error) && $error !== '') {
                        $errors[] = $error;
                    }
                }
            }
        }
        return array(
            'items' => $items,
            'errors' => $errors,
        );
    }

    /**
     * Exporter envelopes only. The missing list is not passed to the schema.
     *
     * @param array $collected
     * @return array
     */
    private static function parts_from($collected)
    {
        $parts = array();

        foreach (self::section_keys() as $section) {
            $envelope = array();
            if (isset($collected[$section]) && is_array($collected[$section])) {
                $envelope = $collected[$section];
            }
            $items = array();
            if (isset($envelope['items']) && is_array($envelope['items'])) {
                $items = $envelope['items'];
            }
            $errors = array();
            if (isset($envelope['errors']) && is_array($envelope['errors'])) {
                foreach ($envelope['errors'] as $error) {
                    if (is_string($error) && $error !== '') {
                        $errors[] = $error;
                    }
                }
            }
            $parts[$section] = array(
                'items' => $items,
                'errors' => $errors,
            );
        }

        return $parts;
    }

    /**
     * @param array $collected
     * @return array
     */
    private static function view($collected)
    {
        $map = static::exporters();
        $missing = array();
        if (isset($collected['missing']) && is_array($collected['missing'])) {
            $missing = self::dedupe_strings($collected['missing']);
        }

        $counts = array();
        $sample = array();
        $sections = array();
        $errors = array();

        foreach (self::section_keys() as $section) {
            $meta = self::catalog($section);
            $envelope = self::envelope(array(), array());
            if (isset($collected[$section]) && is_array($collected[$section])) {
                $envelope = $collected[$section];
            }
            $items = array();
            if (isset($envelope['items']) && is_array($envelope['items'])) {
                $items = $envelope['items'];
            }
            $section_errors = array();
            if (isset($envelope['errors']) && is_array($envelope['errors'])) {
                foreach ($envelope['errors'] as $error) {
                    if (is_string($error) && $error !== '') {
                        $section_errors[] = $error;
                    }
                }
            }

            $count = self::full_count($items);
            $presented = array();
            $labels = array();
            foreach (self::take_sample($items) as $item) {
                $presented[] = self::present_item($item, 0);
                $labels[] = self::item_label($section, $item);
            }
            $limit = (int) self::SAMPLE_LIMIT;
            if (count($presented) > $limit) {
                $presented = array_slice($presented, 0, $limit);
                $labels = array_slice($labels, 0, $limit);
            }

            $class = self::class_for($map, $section);
            $is_missing = ($class !== '' && in_array($class, $missing, true));

            if ($is_missing) {
                $status = 'missing';
                $message = sprintf(self::line($meta['missing']), self::short_class($class));
            } elseif (!empty($section_errors)) {
                $status = 'error';
                if ($count === 0) {
                    $message = self::line($meta['unread']);
                } else {
                    $message = self::line($meta['review']);
                    if ($count === 1) {
                        $message .= ' ' . self::line($meta['found_one']);
                    } else {
                        $message .= ' ' . sprintf(self::line($meta['found_many']), $count);
                    }
                    $message = self::with_sample_note($message, $count);
                }
            } elseif ($count === 0) {
                $status = 'empty';
                $message = self::line($meta['empty']);
            } elseif ($count === 1) {
                $status = 'ready';
                $message = self::line($meta['one']);
            } else {
                $status = 'ready';
                $message = self::with_sample_note(
                    sprintf(self::line($meta['many']), $count),
                    $count
                );
            }

            $counts[$section] = $count;
            $sample[$section] = $presented;
            $sections[$section] = array(
                'label' => self::line($meta['label']),
                'count' => $count,
                'status' => $status,
                'message' => $message,
                'labels' => $labels,
                'more' => (int) max(0, $count - count($presented)),
                'more_label' => self::more_label((int) max(0, $count - count($presented))),
                'error_count' => count($section_errors),
            );
            foreach ($section_errors as $error) {
                $errors[] = $error;
            }
        }

        return array(
            'counts' => $counts,
            'sample' => $sample,
            'errors' => $errors,
            'missing' => $missing,
            'sections' => $sections,
        );
    }

    /**
     * @param array $view
     * @return string missing|incomplete|error|empty|ready
     */
    private static function preview_state($view)
    {
        $all_missing = true;
        $any_missing = false;
        $total = 0;

        foreach (self::section_keys() as $section) {
            $total += (int) $view['counts'][$section];
            if ($view['sections'][$section]['status'] === 'missing') {
                $any_missing = true;
            } else {
                $all_missing = false;
            }
        }

        if ($all_missing) {
            return 'missing';
        }
        if ($any_missing) {
            return 'incomplete';
        }
        if (!empty($view['errors'])) {
            return 'error';
        }
        if ($total === 0) {
            return 'empty';
        }
        return 'ready';
    }

    /**
     * @param array  $view
     * @param string $state
     * @return string
     */
    private static function preview_message($view, $state)
    {
        if ($state === 'missing') {
            return self::line('Nothing could be previewed because the Polylang exporters are not available. Activate the rest of the migration plugin and try again.');
        }
        if ($state === 'incomplete') {
            return sprintf(
                self::line('The preview is incomplete because %s could not be read. Existing Polylang data was not changed.'),
                self::join_clauses(self::missing_labels($view))
            );
        }
        if ($state === 'error') {
            $labels = self::labels_for_status($view, 'error');
            if (!empty($labels)) {
                return sprintf(
                    self::line('The preview finished with errors in %s. Review them before migrating. Existing Polylang data was not changed.'),
                    self::join_clauses($labels)
                );
            }
            return self::line('The preview finished with errors. Review them before migrating. Existing Polylang data was not changed.');
        }
        if ($state === 'empty') {
            return self::line('Nothing was found to migrate. There are no languages, posts, terms, strings, menus, or media items to move yet.');
        }

        return sprintf(
            self::line('Preview ready. Found %s.'),
            self::join_clauses(self::count_phrases($view['counts']))
        );
    }

    /**
     * @param bool  $ok
     * @param bool  $saved
     * @param bool  $attempted
     * @param array $counts
     * @return string saved|empty|partial|failed|blocked
     */
    private static function run_state($ok, $saved, $attempted, $counts)
    {
        if ($ok && array_sum($counts) === 0) {
            return 'empty';
        }
        if ($ok) {
            return 'saved';
        }
        if ($saved) {
            return 'partial';
        }
        if ($attempted) {
            return 'failed';
        }
        return 'blocked';
    }

    /**
     * @param bool   $ok
     * @param bool   $saved
     * @param bool   $attempted
     * @param array  $view
     * @param array  $errors
     * @return string
     */
    private static function run_message($ok, $saved, $attempted, $view, $errors = array())
    {
        $counts = $view['counts'];
        if ($ok) {
            $total = array_sum($counts);
            if ($total === 0) {
                return self::line('Migration finished, but there was nothing to move. Existing Polylang data was not changed.');
            }
            return sprintf(
                self::line('Migration finished. Saved %s for Tolka. Existing Polylang data was not changed.'),
                self::join_clauses(self::count_phrases($counts))
            );
        }
        if ($saved) {
            $labels = self::missing_labels($view);
            if (!empty($labels)) {
                return sprintf(
                    self::line('The Tolka package was saved, but %s could not be read. Existing Polylang data was not changed.'),
                    self::join_clauses($labels)
                );
            }
            $failed = self::labels_for_status($view, 'error');
            if (!empty($failed)) {
                return sprintf(
                    self::line('The Tolka package was saved, but %s could not be read. Existing Polylang data was not changed.'),
                    self::join_clauses($failed)
                );
            }
            return self::line('The Tolka package was saved, but the migration is incomplete. Review the errors before using it. Existing Polylang data was not changed.');
        }
        if (!$attempted && self::all_exporters_missing($view)) {
            return self::line('The migration did not run because none of the Polylang exporters are available. Nothing was written, and existing Polylang data was not changed.');
        }
        if ($attempted) {
            $specific = self::first_error($errors);
            if ($specific !== '' && strpos($specific, 'could not be saved') !== false && stripos($specific, 'Migration finished') === false) {
                return $specific;
            }
            return self::line('The migration could not be completed. Review the errors before trying again. Existing Polylang data was not changed.');
        }
        return self::line('The migration did not run. Nothing was written, and existing Polylang data was not changed.');
    }

    /**
     * The first sentence a screen can show under the headline.
     *
     * @param array $errors
     * @return string
     */
    private static function first_error($errors)
    {
        if (!is_array($errors) || empty($errors) || !is_string($errors[0])) {
            return '';
        }
        return $errors[0];
    }

    /**
     * @param string $message
     * @param int    $count
     * @return string
     */
    private static function with_sample_note($message, $count)
    {
        if ($count > self::SAMPLE_LIMIT) {
            $message .= ' ' . sprintf(
                self::line('The preview shows the first %d, in the order they were exported.'),
                self::SAMPLE_LIMIT
            );
        }
        return $message;
    }

    /**
     * @param int $more
     * @return string
     */
    private static function more_label($more)
    {
        if ($more === 1) {
            return self::line('1 more is not shown.');
        }
        if ($more > 1) {
            return sprintf(self::line('%d more are not shown.'), $more);
        }
        return '';
    }

    /**
     * @param array $view
     * @return string[]
     */
    private static function missing_labels($view)
    {
        return self::labels_for_status($view, 'missing');
    }

    /**
     * True when every section's exporter class is missing.
     *
     * @param array $view
     * @return bool
     */
    private static function all_exporters_missing($view)
    {
        foreach (self::section_keys() as $section) {
            if (!isset($view['sections'][$section]['status']) || $view['sections'][$section]['status'] !== 'missing') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array  $view
     * @param string $status
     * @return string[]
     */
    private static function labels_for_status($view, $status)
    {
        $labels = array();
        foreach (self::section_keys() as $section) {
            if ($view['sections'][$section]['status'] === $status) {
                $labels[] = $view['sections'][$section]['label'];
            }
        }
        return $labels;
    }

    /**
     * @param array $counts
     * @return string[]
     */
    private static function count_phrases($counts)
    {
        $phrases = array();
        foreach (self::section_keys() as $section) {
            $count = isset($counts[$section]) ? (int) $counts[$section] : 0;
            $phrases[] = self::count_phrase($section, $count);
        }
        return $phrases;
    }

    /**
     * @param string $section
     * @param int    $count
     * @return string
     */
    private static function count_phrase($section, $count)
    {
        $meta = self::catalog($section);
        if ($count === 1) {
            return self::line($meta['noun_one']);
        }
        return sprintf(self::line($meta['noun_many']), $count);
    }

    /**
     * @param string $section
     * @param mixed  $item
     * @return string
     */
    private static function item_label($section, $item)
    {
        $meta = self::catalog($section);
        if (!is_array($item)) {
            return self::line('Untitled item');
        }

        if ($section === 'languages') {
            $name = self::field_text($item, 'name');
            $slug = self::field_text($item, 'slug');
            if ($name !== '' && $slug !== '') {
                $label = $name . ' (' . $slug . ')';
            } elseif ($name !== '') {
                $label = $name;
            } elseif ($slug !== '') {
                $label = $slug;
            } else {
                $label = self::line($meta['untitled']);
            }
            if (self::is_default_language($item)) {
                $label .= ', ' . self::line('default');
            }
            return $label;
        }

        if ($section === 'posts') {
            $label = self::field_text($item, 'title');
            if ($label === '') {
                $label = self::field_text($item, 'group');
            }
            if ($label === '') {
                return self::line($meta['untitled']);
            }
            $status = self::field_text($item, 'status');
            if ($status !== '' && strtolower($status) !== 'publish') {
                $label .= ' (' . $status . ')';
            }
            return $label;
        }

        if ($section === 'terms') {
            $name = self::field_text($item, 'name');
            $taxonomy = self::field_text($item, 'taxonomy');
            if ($name !== '' && $taxonomy !== '') {
                return $name . ' (' . $taxonomy . ')';
            }
            if ($name !== '') {
                return $name;
            }
            $slug = self::field_text($item, 'slug');
            if ($slug !== '') {
                return $slug;
            }
            return self::line($meta['untitled']);
        }

        if ($section === 'strings') {
            $name = self::field_text($item, 'name');
            $context = self::field_text($item, 'context');
            if ($name !== '' && $context !== '' && strtolower($context) !== strtolower($name)) {
                return $name . ' (' . $context . ')';
            }
            if ($name !== '') {
                return $name;
            }
            $string = self::field_text($item, 'string');
            if ($string !== '') {
                return $string;
            }
            return self::line($meta['untitled']);
        }

        if ($section === 'menus') {
            $name = self::field_text($item, 'name');
            $location = self::field_text($item, 'location');
            if ($name !== '' && $location !== '' && strtolower($name) !== strtolower($location)) {
                return $name . ' (' . $location . ')';
            }
            if ($name !== '') {
                return $name;
            }
            if ($location !== '') {
                return $location;
            }
            return self::line($meta['untitled']);
        }

        $file = self::field_text($item, 'file');
        if ($file !== '') {
            return $file;
        }
        if (isset($item['attachment_id']) && is_numeric($item['attachment_id'])) {
            return sprintf(self::line('Attachment %d'), (int) $item['attachment_id']);
        }
        return self::line($meta['untitled']);
    }

    /**
     * @param array  $collected
     * @param string $class
     * @param mixed  $package
     * @param array  $blocking
     * @return bool
     */
    private static function build_package($collected, $class, &$package, &$blocking)
    {
        if (!is_string($class) || $class === '' || self::class_is_missing($class)) {
            $name = (is_string($class) && $class !== '') ? self::short_class($class) : 'Tolka_Schema';
            $blocking[] = sprintf(
                self::line('The migration package could not be built because %s is not available. Nothing was written, and existing Polylang data was not changed.'),
                $name
            );
            return false;
        }

        if (!self::can_call($class, 'package', 1)) {
            $blocking[] = sprintf(
                self::line('The migration package could not be built because %s::package() cannot be called. Nothing was written, and existing Polylang data was not changed.'),
                self::short_class($class)
            );
            return false;
        }

        try {
            $built = $class::package(self::parts_from($collected));
        } catch (\Throwable $e) {
            $blocking[] = sprintf(
                self::line('The migration package could not be built (%s). Nothing was written, and existing Polylang data was not changed.'),
                self::safe_exception($e)
            );
            return false;
        }

        if (!is_array($built)) {
            $blocking[] = sprintf(
                self::line('The migration package could not be built because %s returned an unexpected result. Nothing was written, and existing Polylang data was not changed.'),
                self::short_class($class)
            );
            return false;
        }

        $package = $built;
        return true;
    }

    /**
     * @param array  $package
     * @param string $class
     * @param mixed  $write
     * @param array  $blocking
     * @return bool True when write() was called.
     */
    private static function write_package($package, $class, &$write, &$blocking)
    {
        if (!is_string($class) || $class === '' || self::class_is_missing($class)) {
            $name = (is_string($class) && $class !== '') ? self::short_class($class) : 'Tolka_Writer';
            $blocking[] = sprintf(
                self::line('The migration could not be saved because %s is not available. Nothing was written, and existing Polylang data was not changed.'),
                $name
            );
            return false;
        }

        if (!self::can_call($class, 'write', 1)) {
            $blocking[] = sprintf(
                self::line('The migration could not be saved because %s::write() cannot be called. Nothing was written, and existing Polylang data was not changed.'),
                self::short_class($class)
            );
            return false;
        }

        try {
            $returned = $class::write($package);
        } catch (\Throwable $e) {
            $blocking[] = sprintf(
                self::line('The migration could not be saved (%s). Existing Polylang data was not changed.'),
                self::safe_exception($e)
            );
            return true;
        }

        if (!is_array($returned)) {
            $blocking[] = sprintf(
                self::line('The migration could not be saved because %s returned an unexpected result. Existing Polylang data was not changed.'),
                self::short_class($class)
            );
            return true;
        }

        $write = $returned;
        $writer_errors = self::string_list(isset($returned['errors']) ? $returned['errors'] : array());
        foreach ($writer_errors as $text) {
            $blocking[] = $text;
        }
        if ((!isset($returned['ok']) || $returned['ok'] !== true) && empty($writer_errors)) {
            $blocking[] = self::line('The migration could not be saved. Existing Polylang data was not changed.');
        }

        return true;
    }

    /**
     * @param string $class
     * @param array  $blocking
     * @return void
     */
    private static function note_missing_writer($class, &$blocking)
    {
        if (!self::class_is_missing($class)) {
            return;
        }
        $name = (is_string($class) && $class !== '') ? self::short_class($class) : 'Tolka_Writer';
        $blocking[] = sprintf(
            self::line('%s is not available, so there is nowhere to save the migration.'),
            $name
        );
    }

    /**
     * @param string $class
     * @return bool
     */
    private static function class_is_missing($class)
    {
        if (!is_string($class) || $class === '') {
            return true;
        }
        try {
            return !\class_exists($class);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * '' when export() can be called, 'missing' when it is not defined,
     * 'unusable' when it cannot be called safely.
     *
     * @param string $class
     * @return string
     */
    private static function export_problem($class)
    {
        try {
            if (!\method_exists($class, 'export')) {
                return 'missing';
            }
            $method = new \ReflectionMethod($class, 'export');
            if (!$method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                return 'unusable';
            }
            if (!$method->isStatic() && !self::can_instantiate($class)) {
                return 'unusable';
            }
            return '';
        } catch (\Throwable $e) {
            return 'unusable';
        }
    }

    /**
     * Instance export() is called on a new object. Static export() is called
     * on the class and does not construct it.
     *
     * @param string $class
     * @return mixed
     */
    private static function call_export($class)
    {
        $method = new \ReflectionMethod($class, 'export');
        if ($method->isStatic()) {
            return $method->invoke(null);
        }
        try {
            $object = (new \ReflectionClass($class))->newInstance();
        } catch (\Throwable $e) {
            throw new \RuntimeException('constructor failed: ' . self::safe_exception($e));
        }
        return $method->invoke($object);
    }

    /**
     * @param string $class
     * @return bool
     */
    private static function can_instantiate($class)
    {
        try {
            $ref = new \ReflectionClass($class);
            if (!$ref->isInstantiable()) {
                return false;
            }
            $constructor = $ref->getConstructor();
            if ($constructor === null) {
                return true;
            }
            if (!$constructor->isPublic() || $constructor->getNumberOfRequiredParameters() > 0) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $class
     * @param string $method
     * @param int    $passed
     * @return bool
     */
    private static function can_call($class, $method, $passed)
    {
        try {
            if (!\class_exists($class) || !\method_exists($class, $method)) {
                return false;
            }
            $ref = new \ReflectionMethod($class, $method);
            if (!$ref->isPublic() || !$ref->isStatic()) {
                return false;
            }
            if ($ref->getNumberOfRequiredParameters() > $passed) {
                return false;
            }
            if ($ref->getNumberOfParameters() < $passed && !$ref->isVariadic()) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array $blocking
     * @param array $section_errors
     * @return string[]
     */
    private static function merge_errors($blocking, $section_errors)
    {
        return self::dedupe_strings(array_merge(
            self::cap_list($blocking),
            self::cap_list($section_errors)
        ));
    }

    /**
     * @param array $errors
     * @return string[]
     */
    private static function cap_list($errors)
    {
        $clean = self::dedupe_strings($errors);
        if (count($clean) <= self::ERROR_LIMIT) {
            return $clean;
        }
        $shown = array_slice($clean, 0, self::ERROR_LIMIT);
        $extra = count($clean) - self::ERROR_LIMIT;
        if ($extra === 1) {
            $shown[] = self::line('1 more error was omitted from this list.');
        } else {
            $shown[] = sprintf(
                self::line('%d more errors were omitted from this list.'),
                $extra
            );
        }
        return $shown;
    }

    /**
     * @param array $values
     * @return string[]
     */
    private static function dedupe_strings($values)
    {
        $out = array();
        $seen = array();
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function string_list($value)
    {
        if (is_string($value)) {
            $value = array($value);
        }
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $error) {
            $text = self::clean_error_text($error);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /**
     * An empty or whitespace-only section error is not a failure.
     *
     * @param mixed $error
     * @return bool
     */
    private static function is_blank_error($error)
    {
        if (!is_string($error)) {
            return false;
        }
        if (trim($error) === '') {
            return true;
        }
        $stripped = \preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $error);
        if (!is_string($stripped)) {
            return false;
        }
        return $stripped === '';
    }

    /**
     * @param string $section
     * @param string $text
     * @return string
     */
    private static function present_error($section, $text)
    {
        $label = self::line(self::catalog($section)['label']);
        if (\stripos($text, $label) === 0) {
            return $text;
        }
        return $label . ': ' . $text;
    }

    /**
     * Copy a sample value into scalars and arrays that can be JSON-encoded.
     *
     * Long strings are shortened. The package passed to the writer keeps the
     * original exporter values.
     *
     * @param mixed $value
     * @param int   $depth
     * @return mixed
     */
    private static function present_item($value, $depth)
    {
        if ($depth > 8) {
            return '';
        }
        if (is_string($value)) {
            return self::plain_string($value, self::SAMPLE_TEXT_LIMIT);
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_float($value)) {
            if (!\is_finite($value)) {
                return null;
            }
            return $value;
        }
        if (is_array($value)) {
            $out = array();
            $n = 0;
            foreach ($value as $key => $child) {
                if ($n >= 40) {
                    break;
                }
                if (is_string($key)) {
                    $key = self::plain_string($key, 80);
                    if ($key === '') {
                        $key = 'item';
                    }
                    $key = self::unique_key($out, $key);
                } elseif (!is_int($key)) {
                    continue;
                }
                $out[$key] = self::present_item($child, $depth + 1);
                $n++;
            }
            return $out;
        }
        return self::line('Unsupported value');
    }

    /**
     * @param array  $out
     * @param string $key
     * @return string
     */
    private static function unique_key($out, $key)
    {
        if (!array_key_exists($key, $out)) {
            return $key;
        }
        $suffix = 2;
        while (array_key_exists($key . ' ' . $suffix, $out) && $suffix < 100) {
            $suffix++;
        }
        return $key . ' ' . $suffix;
    }

    /**
     * Only a real default flag is labeled. The string "false" and "0" are not.
     *
     * @param array $item
     * @return bool
     */
    private static function is_default_language($item)
    {
        if (!isset($item['is_default'])) {
            return false;
        }
        $flag = $item['is_default'];
        return $flag === true || $flag === 1 || $flag === '1';
    }

    /**
     * @param array  $item
     * @param string $key
     * @return string
     */
    private static function field_text($item, $key)
    {
        if (!is_array($item) || !isset($item[$key]) || $item[$key] === null || is_bool($item[$key])) {
            return '';
        }
        $value = $item[$key];
        if (is_int($value) || (is_float($value) && \is_finite($value))) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }
        return self::plain_string($value, 120);
    }

    /**
     * @param string $value
     * @param int    $limit
     * @return string
     */
    private static function plain_string($value, $limit)
    {
        if (!is_string($value)) {
            return '';
        }
        if (\preg_match('/<\s*\/?\s*[a-z!]/i', $value)) {
            $value = \strip_tags($value);
        }
        return self::shorten(self::collapse_ws($value), $limit);
    }

    /**
     * @param mixed $error
     * @return string
     */
    private static function clean_error_text($error)
    {
        if (is_array($error) && isset($error['message'])) {
            $error = $error['message'];
        }
        if (is_bool($error) || $error === null) {
            return '';
        }
        if (is_int($error) || is_float($error)) {
            if (is_float($error) && !\is_finite($error)) {
                return '';
            }
            $error = (string) $error;
        }
        if (!is_string($error)) {
            return '';
        }
        if (\preg_match('/<\s*\/?\s*[a-z!]/i', $error)) {
            $error = \strip_tags($error);
        }
        $error = self::collapse_ws($error);
        if ($error === '') {
            return '';
        }
        return self::shorten($error, 500);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function collapse_ws($value)
    {
        $clean = \preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $value);
        if (!is_string($clean)) {
            $clean = $value;
        }
        $clean = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $clean);
        if (!is_string($clean)) {
            $clean = $value;
        }
        $collapsed = \preg_replace('/\s+/', ' ', $clean);
        if (!is_string($collapsed)) {
            return trim($clean);
        }
        return trim($collapsed);
    }

    /**
     * @param string $text
     * @param int    $limit
     * @return string
     */
    private static function shorten($text, $limit)
    {
        if (!is_string($text) || $text === '' || $limit < 1) {
            return '';
        }
        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            $length = \mb_strlen($text, 'UTF-8');
            if ($length === false) {
                $length = \strlen($text);
                if ($length <= $limit) {
                    return $text;
                }
                return rtrim(\substr($text, 0, $limit)) . '...';
            }
            if ($length <= $limit) {
                return $text;
            }
            return rtrim(\mb_substr($text, 0, $limit, 'UTF-8')) . '...';
        }
        if (\strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(\substr($text, 0, $limit)) . '...';
    }

    /**
     * @param \Throwable $e
     * @return string
     */
    private static function safe_exception($e)
    {
        $text = self::clean_error_text($e->getMessage());
        if ($text === '') {
            return self::line('unexpected error');
        }
        return self::shorten($text, 240);
    }

    /**
     * @param string $class
     * @return string
     */
    private static function short_class($class)
    {
        $class = ltrim((string) $class, '\\');
        $pos = strrpos($class, '\\');
        if ($pos === false) {
            return $class;
        }
        return substr($class, $pos + 1);
    }

    /**
     * @param string[] $parts
     * @return string
     */
    private static function join_clauses($parts)
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
            return $parts[0] . ' and ' . $parts[1];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ', and ' . $last;
    }

    /**
     * Translate when WordPress is loaded. Tests and previews without WordPress
     * keep the English sentence.
     *
     * @param string $text
     * @return string
     */
    private static function line($text)
    {
        if (\function_exists('__')) {
            return \__($text, 'migration-manager');
        }
        return $text;
    }

    /**
     * @param string $section
     * @return array
     */
    private static function catalog($section)
    {
        $all = array(
            'languages' => array(
                'label' => 'Languages',
                'missing' => 'Languages were skipped because %s is not available.',
                'no_method' => 'Languages could not be read because %s does not define export().',
                'unusable' => 'Languages could not be read because %s::export() cannot be called.',
                'unregistered' => 'Languages were skipped because no exporter is registered.',
                'invalid' => 'Languages could not be read because the exporter returned an unexpected result.',
                'threw' => 'Languages could not be read (%s).',
                'undisplayable' => 'Languages reported an error that could not be displayed.',
                'review' => 'Languages could not be fully read. Review the errors before migrating.',
                'unread' => 'Languages could not be read. Review the errors before migrating.',
                'found_one' => '1 language was still found.',
                'found_many' => '%d languages were still found.',
                'empty' => 'No languages were found.',
                'one' => '1 language is ready to migrate.',
                'many' => '%d languages are ready to migrate.',
                'noun_one' => '1 language',
                'noun_many' => '%d languages',
                'untitled' => 'Untitled language',
            ),
            'posts' => array(
                'label' => 'Posts',
                'missing' => 'Posts were skipped because %s is not available.',
                'no_method' => 'Posts could not be read because %s does not define export().',
                'unusable' => 'Posts could not be read because %s::export() cannot be called.',
                'unregistered' => 'Posts were skipped because no exporter is registered.',
                'invalid' => 'Posts could not be read because the exporter returned an unexpected result.',
                'threw' => 'Posts could not be read (%s).',
                'undisplayable' => 'Posts reported an error that could not be displayed.',
                'review' => 'Posts could not be fully read. Review the errors before migrating.',
                'unread' => 'Posts could not be read. Review the errors before migrating.',
                'found_one' => '1 post was still found.',
                'found_many' => '%d posts were still found.',
                'empty' => 'No posts were found.',
                'one' => '1 post is ready to migrate.',
                'many' => '%d posts are ready to migrate.',
                'noun_one' => '1 post',
                'noun_many' => '%d posts',
                'untitled' => 'Untitled post',
            ),
            'terms' => array(
                'label' => 'Terms',
                'missing' => 'Terms were skipped because %s is not available.',
                'no_method' => 'Terms could not be read because %s does not define export().',
                'unusable' => 'Terms could not be read because %s::export() cannot be called.',
                'unregistered' => 'Terms were skipped because no exporter is registered.',
                'invalid' => 'Terms could not be read because the exporter returned an unexpected result.',
                'threw' => 'Terms could not be read (%s).',
                'undisplayable' => 'Terms reported an error that could not be displayed.',
                'review' => 'Terms could not be fully read. Review the errors before migrating.',
                'unread' => 'Terms could not be read. Review the errors before migrating.',
                'found_one' => '1 term was still found.',
                'found_many' => '%d terms were still found.',
                'empty' => 'No terms were found.',
                'one' => '1 term is ready to migrate.',
                'many' => '%d terms are ready to migrate.',
                'noun_one' => '1 term',
                'noun_many' => '%d terms',
                'untitled' => 'Untitled term',
            ),
            'strings' => array(
                'label' => 'Strings',
                'missing' => 'Strings were skipped because %s is not available.',
                'no_method' => 'Strings could not be read because %s does not define export().',
                'unusable' => 'Strings could not be read because %s::export() cannot be called.',
                'unregistered' => 'Strings were skipped because no exporter is registered.',
                'invalid' => 'Strings could not be read because the exporter returned an unexpected result.',
                'threw' => 'Strings could not be read (%s).',
                'undisplayable' => 'Strings reported an error that could not be displayed.',
                'review' => 'Strings could not be fully read. Review the errors before migrating.',
                'unread' => 'Strings could not be read. Review the errors before migrating.',
                'found_one' => '1 string was still found.',
                'found_many' => '%d strings were still found.',
                'empty' => 'No strings were found.',
                'one' => '1 string is ready to migrate.',
                'many' => '%d strings are ready to migrate.',
                'noun_one' => '1 string',
                'noun_many' => '%d strings',
                'untitled' => 'Untitled string',
            ),
            'menus' => array(
                'label' => 'Menus',
                'missing' => 'Menus were skipped because %s is not available.',
                'no_method' => 'Menus could not be read because %s does not define export().',
                'unusable' => 'Menus could not be read because %s::export() cannot be called.',
                'unregistered' => 'Menus were skipped because no exporter is registered.',
                'invalid' => 'Menus could not be read because the exporter returned an unexpected result.',
                'threw' => 'Menus could not be read (%s).',
                'undisplayable' => 'Menus reported an error that could not be displayed.',
                'review' => 'Menus could not be fully read. Review the errors before migrating.',
                'unread' => 'Menus could not be read. Review the errors before migrating.',
                'found_one' => '1 menu was still found.',
                'found_many' => '%d menus were still found.',
                'empty' => 'No menus were found.',
                'one' => '1 menu is ready to migrate.',
                'many' => '%d menus are ready to migrate.',
                'noun_one' => '1 menu',
                'noun_many' => '%d menus',
                'untitled' => 'Untitled menu',
            ),
            'media' => array(
                'label' => 'Media',
                'missing' => 'Media items were skipped because %s is not available.',
                'no_method' => 'Media items could not be read because %s does not define export().',
                'unusable' => 'Media items could not be read because %s::export() cannot be called.',
                'unregistered' => 'Media items were skipped because no exporter is registered.',
                'invalid' => 'Media items could not be read because the exporter returned an unexpected result.',
                'threw' => 'Media items could not be read (%s).',
                'undisplayable' => 'Media items reported an error that could not be displayed.',
                'review' => 'Media items could not be fully read. Review the errors before migrating.',
                'unread' => 'Media items could not be read. Review the errors before migrating.',
                'found_one' => '1 media item was still found.',
                'found_many' => '%d media items were still found.',
                'empty' => 'No media items were found.',
                'one' => '1 media item is ready to migrate.',
                'many' => '%d media items are ready to migrate.',
                'noun_one' => '1 media item',
                'noun_many' => '%d media items',
                'untitled' => 'Untitled media item',
            ),
        );

        return $all[$section];
    }
}
