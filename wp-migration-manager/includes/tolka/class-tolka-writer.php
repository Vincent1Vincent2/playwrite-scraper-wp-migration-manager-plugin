<?php

/**
 * Tolka migration writer.
 *
 * Saves the Tolka package and tags translated posts and terms. Polylang
 * options, terms, and meta are left in place. A later run updates Tolka
 * tags that are still in the package and does not remove tags from posts
 * or terms that were left out.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes a Tolka package and language meta.
 */
class Tolka_Writer
{
    /**
     * Option that holds the package.
     */
    const OPTION = 'wmm_tolka_package';

    /**
     * Post and term meta for the Tolka language slug.
     */
    const META_LANG = '_wmm_tolka_lang';

    /**
     * Post and term meta for the Tolka translation group.
     */
    const META_GROUP = '_wmm_tolka_group';

    /**
     * How many problem sentences to show before summarizing the rest.
     */
    const MAX_ERRORS = 30;

    /**
     * Save a package and tag each translated post and term.
     *
     * `ok` is true when the package itself was stored. Skipped rows and tags
     * that could not be written are complete sentences in `errors`, and the
     * package can still be saved. This method does not delete existing meta
     * or Polylang rows. An empty package is a successful save with zero items.
     *
     * @param array $package Package from Tolka_Schema::package().
     * @return array
     */
    public static function write(array $package)
    {
        try {
            return self::write_package($package);
        } catch (\Throwable $throwable) {
            if (function_exists('error_log')) {
                error_log('Tolka migration writer: ' . $throwable->getMessage());
            }

            return array(
                'ok' => false,
                'counts' => self::empty_counts(),
                'errors' => array(
                    self::failure('Tolka could not finish saving this migration.'),
                ),
            );
        }
    }

    /**
     * Store the package, then tag posts and terms.
     *
     * @param array $package Package to store.
     * @return array
     */
    private static function write_package(array $package)
    {
        $hidden = 0;
        $errors = array();
        $version_ok = self::supported_version($package);

        if (!self::caller_may_write()) {
            self::add_error(
                $errors,
                $hidden,
                'You need administrator permission to migrate this site to Tolka.'
            );
            $collected = self::collect(self::prepare_package(self::clean_package($package)), $errors, $hidden);
            return self::result($collected['counts'], $errors, $hidden, false);
        }

        if (!$version_ok) {
            self::add_error(
                $errors,
                $hidden,
                'This Tolka package is version "%s", and this site can save version 1 only. Run the migration again from this site.',
                array(self::version_label(isset($package['version']) ? $package['version'] : null))
            );
            $collected = self::collect(self::prepare_package(self::clean_package($package)), $errors, $hidden);
            return self::result($collected['counts'], $errors, $hidden, false);
        }

        $package = self::prepare_package($package);
        $collected = self::collect($package, $errors, $hidden);

        if (!self::sections_are_lists($package)) {
            $errors[] = self::failure('The Tolka package was not saved because part of it was not a list.');
            return self::result($collected['counts'], $errors, $hidden, false);
        }

        $package = self::clean_package($package);

        if (!self::store_package($package, $errors, $hidden)) {
            return self::result($collected['counts'], $errors, $hidden, false);
        }

        self::write_meta('post', $collected['posts'], $errors, $hidden);
        self::write_meta('term', $collected['terms'], $errors, $hidden);

        return self::result($collected['counts'], $errors, $hidden, true);
    }

    /**
     * Count every section and collect post and term tags.
     *
     * @param array $package Package to read.
     * @param array $errors  Problem sentences.
     * @param int   $hidden  Problems kept out of the list.
     * @return array
     */
    private static function collect(array $package, array &$errors, &$hidden)
    {
        $counts = array();
        $posts = array();
        $terms = array();

        foreach (self::sections() as $section) {
            $items = self::items_for($package, $section, $errors, $hidden);
            $counts[$section] = count($items);

            if ($section === 'posts') {
                $posts = self::operations($items, 'post', $errors, $hidden);
            } elseif ($section === 'terms') {
                $terms = self::operations($items, 'term', $errors, $hidden);
            }
        }

        return array(
            'counts' => $counts,
            'posts' => self::resolve_conflicts($posts, 'post', $errors, $hidden),
            'terms' => self::resolve_conflicts($terms, 'term', $errors, $hidden),
        );
    }

    /**
     * Read one package section as a list.
     *
     * A missing section is an empty list. That is how an empty package saves
     * without a warning.
     *
     * @param array  $package Package to read.
     * @param string $section Section name.
     * @param array  $errors  Problem sentences.
     * @param int    $hidden  Problems kept out of the list.
     * @return array
     */
    private static function items_for(array $package, $section, array &$errors, &$hidden)
    {
        if (!array_key_exists($section, $package)) {
            return array();
        }

        if (is_array($package[$section])) {
            return $package[$section];
        }

        self::add_error($errors, $hidden, self::section_problem($section));
        return array();
    }

    /**
     * Customer sentence when a section is not a list.
     *
     * @param string $section Section name.
     * @return string
     */
    private static function section_problem($section)
    {
        switch ($section) {
            case 'languages':
                return 'The languages part of the Tolka package was not a list, so Tolka skipped it.';
            case 'posts':
                return 'The posts part of the Tolka package was not a list, so Tolka skipped it.';
            case 'terms':
                return 'The terms part of the Tolka package was not a list, so Tolka skipped it.';
            case 'strings':
                return 'The strings part of the Tolka package was not a list, so Tolka skipped it.';
            case 'menus':
                return 'The menus part of the Tolka package was not a list, so Tolka skipped it.';
            case 'media':
                return 'The media part of the Tolka package was not a list, so Tolka skipped it.';
        }

        return 'One part of the Tolka package was not a list, so Tolka skipped it.';
    }

    /**
     * Build language tags for posts or terms.
     *
     * @param array  $items  Section items.
     * @param string $kind   Either post or term.
     * @param array  $errors Problem sentences.
     * @param int    $hidden Problems kept out of the list.
     * @return array
     */
    private static function operations(array $items, $kind, array &$errors, &$hidden)
    {
        $ops = array();
        $copy = self::kind_copy($kind);

        foreach ($items as $item) {
            if (!is_array($item)) {
                self::add_error($errors, $hidden, $copy['incomplete']);
                continue;
            }

            if (!isset($item['translations'])) {
                continue;
            }

            if (!is_array($item['translations'])) {
                self::add_error($errors, $hidden, $copy['unreadable']);
                continue;
            }

            if ($item['translations'] === array()) {
                continue;
            }

            $group = self::group_id($item);
            if ($group === '') {
                self::add_error($errors, $hidden, $copy['no_group']);
                continue;
            }

            $positions = array();
            foreach ($item['translations'] as $lang => $translation) {
                $raw_lang = (string) $lang;
                $lang = self::stored_text($raw_lang);
                if ($lang === '') {
                    $visible = trim(str_replace("\0", '', $raw_lang));
                    $template = ($visible === '') ? 'no_lang' : 'bad_lang';
                    self::add_error($errors, $hidden, $copy[$template], array($group));
                    continue;
                }

                $id = self::entity_id($translation);
                if ($id < 1) {
                    self::add_error($errors, $hidden, $copy['no_id'], array($lang, $group));
                    continue;
                }

                $op = array(
                    'id' => $id,
                    'lang' => $lang,
                    'group' => $group,
                );

                if (isset($positions[$lang])) {
                    self::add_error($errors, $hidden, $copy['repeat_lang'], array($lang, $group));
                    $ops[$positions[$lang]] = $op;
                    continue;
                }

                $positions[$lang] = count($ops);
                $ops[] = $op;
            }
        }

        return array_values($ops);
    }

    /**
     * Sentences for post tags or term tags.
     *
     * @param string $kind Either post or term.
     * @return array
     */
    private static function kind_copy($kind)
    {
        if ($kind === 'term') {
            return array(
                'incomplete' => 'One term entry in the Tolka package was incomplete, so Tolka skipped it.',
                'unreadable' => 'One term entry in the Tolka package has translations Tolka cannot read, so Tolka skipped it.',
                'no_group' => 'A term translation has no group id, so Tolka skipped it.',
                'no_lang' => 'A term translation in group "%s" has no language code, so Tolka skipped it.',
                'bad_lang' => 'A term translation in group "%s" had a language code WordPress cannot store, so Tolka skipped it.',
                'repeat_lang' => 'Translation group "%2$s" lists the language "%1$s" more than once, so Tolka kept the last copy.',
                'no_id' => 'A term translation for "%1$s" in group "%2$s" has no id, so Tolka skipped it.',
                'conflict' => 'Term %1$d was listed as "%2$s" in group "%3$s" and as "%4$s" in group "%5$s", so Tolka kept "%4$s" in group "%5$s". Check that translation before you switch the site to Tolka.',
                'lang_failed' => 'Tolka could not save the language "%1$s" on term %2$d.',
                'group_failed' => 'Tolka could not save the group "%1$s" on term %2$d.',
                'unavailable' => 'WordPress could not tag translated terms for Tolka because this site cannot store term details.',
            );
        }

        return array(
            'incomplete' => 'One post entry in the Tolka package was incomplete, so Tolka skipped it.',
            'unreadable' => 'One post entry in the Tolka package has translations Tolka cannot read, so Tolka skipped it.',
            'no_group' => 'A post translation has no group id, so Tolka skipped it.',
            'no_lang' => 'A post translation in group "%s" has no language code, so Tolka skipped it.',
            'bad_lang' => 'A post translation in group "%s" had a language code WordPress cannot store, so Tolka skipped it.',
            'repeat_lang' => 'Translation group "%2$s" lists the language "%1$s" more than once, so Tolka kept the last copy.',
            'no_id' => 'A post translation for "%1$s" in group "%2$s" has no id, so Tolka skipped it.',
            'conflict' => 'Post %1$d was listed as "%2$s" in group "%3$s" and as "%4$s" in group "%5$s", so Tolka kept "%4$s" in group "%5$s". Check that translation before you switch the site to Tolka.',
            'lang_failed' => 'Tolka could not save the language "%1$s" on post %2$d.',
            'group_failed' => 'Tolka could not save the group "%1$s" on post %2$d.',
            'unavailable' => 'WordPress could not tag translated posts for Tolka because this site cannot store post details.',
        );
    }

    /**
     * Group id from a post or term item.
     *
     * @param array $item Section item.
     * @return string
     */
    private static function group_id(array $item)
    {
        if (!isset($item['group'])) {
            return '';
        }

        if (is_string($item['group'])) {
            return self::stored_text($item['group']);
        }

        if (is_int($item['group'])) {
            return (string) $item['group'];
        }

        return '';
    }

    /**
     * Positive id from a translation value.
     *
     * Accepts an integer id, a whole-number id, or an array with `id` or `ID`.
     *
     * @param mixed $translation Translation payload.
     * @return int
     */
    private static function entity_id($translation)
    {
        if (is_int($translation) || is_string($translation) || is_float($translation)) {
            return self::positive_int($translation);
        }

        if (!is_array($translation)) {
            return 0;
        }

        if (array_key_exists('id', $translation)) {
            return self::positive_int($translation['id']);
        }

        if (array_key_exists('ID', $translation)) {
            return self::positive_int($translation['ID']);
        }

        return 0;
    }

    /**
     * Cast a whole number to a positive integer, or return 0.
     *
     * @param mixed $value Candidate id.
     * @return int
     */
    private static function positive_int($value)
    {
        if (is_bool($value)) {
            return 0;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (is_float($value)) {
            if (!is_finite($value) || $value < 1 || $value > PHP_INT_MAX || floor($value) !== $value) {
                return 0;
            }
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $number = (int) $value;
            if ((string) $number === $value) {
                return $number;
            }
        }

        return 0;
    }

    /**
     * Keep one tag per id. The last listing wins, and a disagreement is reported.
     *
     * @param array  $ops    Tag operations.
     * @param string $kind   Either post or term.
     * @param array  $errors Problem sentences.
     * @param int    $hidden Problems kept out of the list.
     * @return array
     */
    private static function resolve_conflicts(array $ops, $kind, array &$errors, &$hidden)
    {
        $index = array();
        $order = array();
        $copy = self::kind_copy($kind);

        foreach ($ops as $op) {
            $id = $op['id'];
            if (!isset($index[$id])) {
                $index[$id] = $op;
                $order[] = $id;
                continue;
            }

            $previous = $index[$id];
            if ($previous['lang'] === $op['lang'] && $previous['group'] === $op['group']) {
                continue;
            }

            self::add_error(
                $errors,
                $hidden,
                $copy['conflict'],
                array(
                    $id,
                    $previous['lang'],
                    $previous['group'],
                    $op['lang'],
                    $op['group'],
                )
            );
            $index[$id] = $op;
        }

        $resolved = array();
        foreach ($order as $id) {
            $resolved[] = $index[$id];
        }

        return $resolved;
    }

    /**
     * Store the package in its option without autoloading it.
     *
     * The package can hold every translated post, so it stays out of the
     * options that WordPress loads on each request. Saving the same package
     * again is success, not an error.
     *
     * @param array $package Package to store.
     * @param array $errors  Problem sentences.
     * @param int   $hidden  Problems kept out of the list.
     * @return bool
     */
    private static function store_package(array $package, array &$errors, &$hidden)
    {
        $updated = self::call_update_option($package);

        if ($updated === null) {
            self::add_error(
                $errors,
                $hidden,
                'WordPress could not save the Tolka migration package because this site cannot store options.'
            );
            return false;
        }

        if ($updated) {
            return true;
        }

        $stored = self::call_get_option();
        if ($stored['available'] && self::same_package($stored['value'], $package)) {
            return true;
        }

        self::add_error($errors, $hidden, self::store_failed_message($package));
        return false;
    }

    /**
     * Copy the package into text WordPress can store.
     *
     * @param array $package Package or exporter parts.
     * @return array
     */
    private static function clean_package(array $package)
    {
        if (class_exists(__NAMESPACE__ . '\\Tolka_Schema', false)) {
            return Tolka_Schema::for_storage($package);
        }

        return $package;
    }

    /**
     * Text stored in Tolka meta.
     *
     * @param mixed $value Raw language or group.
     * @return string
     */
    private static function stored_text($value)
    {
        if (class_exists(__NAMESPACE__ . '\\Tolka_Schema', false)) {
            return trim(Tolka_Schema::text($value));
        }

        return trim(str_replace("\0", '', (string) $value));
    }

    /**
     * Turn exporter envelopes into a package. A finished package is unchanged.
     *
     * The runner normally builds the package first. If it passes envelopes
     * straight through, the saved option is still a version 1 package and
     * exporter error text is not stored.
     *
     * @param array $input Package or exporter parts.
     * @return array
     */
    private static function prepare_package(array $input)
    {
        if (!self::contains_envelopes($input)) {
            return $input;
        }

        $parts = self::as_envelopes($input);
        if (class_exists(__NAMESPACE__ . '\\Tolka_Schema', false)) {
            return Tolka_Schema::package($parts);
        }

        $package = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        );

        foreach (self::sections() as $section) {
            $package[$section] = array();
            if (isset($parts[$section]['items']) && is_array($parts[$section]['items'])) {
                $package[$section] = $parts[$section]['items'];
            }
        }

        return $package;
    }

    /**
     * Whether any section is still an exporter envelope.
     *
     * @param array $input Package or exporter parts.
     * @return bool
     */
    private static function contains_envelopes(array $input)
    {
        foreach (self::sections() as $section) {
            if (!isset($input[$section]) || !is_array($input[$section])) {
                continue;
            }

            if (self::is_envelope($input[$section])) {
                return true;
            }
        }

        return false;
    }

    /**
     * An envelope has items and is not itself a content record.
     *
     * @param array $value Section value.
     * @return bool
     */
    private static function is_envelope(array $value)
    {
        if (!array_key_exists('items', $value) || !is_array($value['items'])) {
            return false;
        }

        $content_keys = array(
            'group',
            'translations',
            'slug',
            'name',
            'string',
            'location',
            'attachment_id',
            'file',
            'taxonomy',
            'type',
        );

        foreach ($content_keys as $key) {
            if (array_key_exists($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wrap plain lists so the schema can read them next to envelopes.
     *
     * @param array $input Package or exporter parts.
     * @return array
     */
    private static function as_envelopes(array $input)
    {
        $parts = array();

        foreach (self::sections() as $section) {
            if (!array_key_exists($section, $input)) {
                continue;
            }

            $value = $input[$section];
            if (!is_array($value)) {
                continue;
            }

            if (self::is_envelope($value)) {
                $parts[$section] = $value;
                continue;
            }

            $parts[$section] = array(
                'items' => $value,
                'errors' => array(),
            );
        }

        return $parts;
    }

    /**
     * A package can be saved only when every present section is a list.
     *
     * Missing sections are empty, which is a valid migration. A section that
     * is some other type would replace a previously saved package with a
     * value Tolka cannot read.
     *
     * @param array $package Package to check.
     * @return bool
     */
    private static function sections_are_lists(array $package)
    {
        foreach (self::sections() as $section) {
            if (array_key_exists($section, $package) && !is_array($package[$section])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Version 1 is the only package this site saves. A missing version is allowed.
     *
     * @param array $package Package to check.
     * @return bool
     */
    private static function supported_version(array $package)
    {
        if (!array_key_exists('version', $package)) {
            return true;
        }

        $version = $package['version'];
        if (is_bool($version)) {
            return false;
        }

        if (is_int($version)) {
            return $version === 1;
        }

        if (is_float($version)) {
            return $version === 1.0;
        }

        if (is_string($version) && preg_match('/^[0-9]+$/', $version)) {
            return (int) $version === 1;
        }

        return false;
    }

    /**
     * Short text for a package version that cannot be saved.
     *
     * @param mixed $version Raw version value.
     * @return string
     */
    private static function version_label($version)
    {
        if (is_int($version) || is_float($version) || is_string($version)) {
            $label = trim((string) $version);
            if (strlen($label) > 40) {
                $label = substr($label, 0, 40);
            }
            if ($label !== '') {
                return $label;
            }
        }

        return 'unknown';
    }

    /**
     * Sentence used when the package option cannot be saved.
     *
     * @param array $package Package that failed to save.
     * @return string
     */
    private static function store_failed_message(array $package)
    {
        $bytes = strlen(serialize($package));
        if ($bytes > 900000) {
            return 'WordPress could not save the Tolka migration package. It is too large for one database option. Ask your host to raise the maximum packet size, then run the migration again.';
        }

        return 'WordPress could not save the Tolka migration package. Run the migration again.';
    }

    /**
     * Whether two package values are the same stored payload.
     *
     * @param mixed $left  Stored value.
     * @param mixed $right Value just written.
     * @return bool
     */
    private static function same_package($left, $right)
    {
        if ($left === $right) {
            return true;
        }

        return serialize($left) === serialize($right);
    }

    /**
     * Set language and group meta for one kind of object.
     *
     * @param string $kind   Either post or term.
     * @param array  $ops    Tag operations.
     * @param array  $errors Problem sentences.
     * @param int    $hidden Problems kept out of the list.
     * @return void
     */
    private static function write_meta($kind, array $ops, array &$errors, &$hidden)
    {
        if ($ops === array()) {
            return;
        }

        $copy = self::kind_copy($kind);

        if (!self::meta_available($kind)) {
            self::add_error($errors, $hidden, $copy['unavailable']);
            return;
        }

        foreach ($ops as $op) {
            if (!self::assign_meta($kind, $op['id'], self::META_LANG, $op['lang'])) {
                self::add_error(
                    $errors,
                    $hidden,
                    $copy['lang_failed'],
                    array($op['lang'], $op['id'])
                );
            }

            if (!self::assign_meta($kind, $op['id'], self::META_GROUP, $op['group'])) {
                self::add_error(
                    $errors,
                    $hidden,
                    $copy['group_failed'],
                    array($op['group'], $op['id'])
                );
            }
        }
    }

    /**
     * Whether this site can store meta for posts or terms.
     *
     * @param string $kind Either post or term.
     * @return bool
     */
    private static function meta_available($kind)
    {
        if (self::testing()) {
            $key = $kind === 'term' ? 'has_update_term_meta' : 'has_update_post_meta';
            return !empty($GLOBALS['wmm_tolka_schema_driver'][$key]);
        }

        $function = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';
        return function_exists($function);
    }

    /**
     * Set one meta value. The same value already stored is success.
     *
     * @param string $kind  Either post or term.
     * @param int    $id    Object id.
     * @param string $key   Meta key.
     * @param string $value Meta value.
     * @return bool
     */
    private static function assign_meta($kind, $id, $key, $value)
    {
        $updated = self::call_update_meta($kind, $id, $key, $value);
        if ($updated === null) {
            return false;
        }
        if ($updated) {
            return true;
        }

        $current = self::call_get_meta($kind, $id, $key);
        return $current['available'] && (string) $current['value'] === (string) $value;
    }

    /**
     * Whether the current user may store the migration.
     *
     * Outside WordPress there is no user to check. The admin screen checks
     * this capability again before it calls the writer.
     *
     * @return bool
     */
    private static function caller_may_write()
    {
        if (self::testing()) {
            if (!array_key_exists('can_manage', $GLOBALS['wmm_tolka_schema_driver'])) {
                return true;
            }
            return (bool) $GLOBALS['wmm_tolka_schema_driver']['can_manage'];
        }

        if (!function_exists('current_user_can')) {
            return true;
        }

        return current_user_can('manage_options');
    }

    /**
     * Section names in package order.
     *
     * @return string[]
     */
    private static function sections()
    {
        if (class_exists(__NAMESPACE__ . '\\Tolka_Schema', false)) {
            return Tolka_Schema::sections();
        }

        return array(
            'languages',
            'posts',
            'terms',
            'strings',
            'menus',
            'media',
        );
    }

    /**
     * Zero counts for every section.
     *
     * @return array
     */
    private static function empty_counts()
    {
        $counts = array();
        foreach (self::sections() as $section) {
            $counts[$section] = 0;
        }
        return $counts;
    }

    /**
     * Build the writer result. `ok` means the package was stored.
     *
     * @param array $counts Section counts.
     * @param array $errors Problem sentences.
     * @param int   $hidden Problems kept out of the list.
     * @param bool  $stored Whether the package option was saved.
     * @return array
     */
    private static function result(array $counts, array $errors, $hidden, $stored)
    {
        $errors = self::finish_errors($errors, $hidden);

        return array(
            'ok' => (bool) $stored,
            'counts' => $counts,
            'errors' => array_values($errors),
        );
    }

    /**
     * Add one problem sentence, up to the display limit.
     *
     * @param array  $errors   Problem sentences.
     * @param int    $hidden   Problems kept out of the list.
     * @param string $template Sentence before the safety line.
     * @param array  $values   Values for the sentence.
     * @return void
     */
    private static function add_error(array &$errors, &$hidden, $template, array $values = array())
    {
        if (count($errors) >= self::MAX_ERRORS) {
            $hidden++;
            return;
        }

        $errors[] = self::failure($template, $values);
    }

    /**
     * Append one sentence when some problems were left out.
     *
     * @param array $errors Problem sentences.
     * @param int   $hidden Problems kept out of the list.
     * @return array
     */
    private static function finish_errors(array $errors, $hidden)
    {
        if ($hidden > 0) {
            $errors[] = self::failure(
                '%d more problems were found. Review the preview, then run the migration again.',
                array($hidden)
            );
        }

        return $errors;
    }

    /**
     * Finish a problem sentence with the promise that Polylang was not removed.
     *
     * @param string $template Sentence template.
     * @param array  $values   Values for the template.
     * @return string
     */
    private static function failure($template, array $values = array())
    {
        $sentence = self::translate($template);

        if ($values !== array()) {
            try {
                $sentence = vsprintf($sentence, $values);
            } catch (\Throwable $throwable) {
                $sentence = $template;
            }
        }

        $sentence = rtrim($sentence);
        if ($sentence === '') {
            $sentence = self::translate('Tolka could not finish saving this migration.');
        }
        if (substr($sentence, -1) !== '.') {
            $sentence .= '.';
        }

        return $sentence . ' ' . self::translate('Nothing was deleted, and your Polylang content is still in place.');
    }

    /**
     * Translate a sentence when WordPress is loaded.
     *
     * @param string $text English sentence.
     * @return string
     */
    private static function translate($text)
    {
        if (function_exists('__')) {
            return __($text, 'migration-manager');
        }

        return $text;
    }

    /**
     * Whether this request is using the schema test double.
     *
     * The double exists only while the schema tests are running. A normal
     * site never defines the constant, and then these calls use WordPress.
     *
     * @return bool
     */
    private static function testing()
    {
        return defined('WMM_TOLKA_SCHEMA_TEST')
            && WMM_TOLKA_SCHEMA_TEST
            && isset($GLOBALS['wmm_tolka_schema_driver'])
            && is_array($GLOBALS['wmm_tolka_schema_driver']);
    }

    /**
     * Store the package option.
     *
     * @param array $package Package to store.
     * @return bool|null True, false, or null when storage is unavailable.
     */
    private static function call_update_option(array $package)
    {
        if (self::testing()) {
            $driver = &$GLOBALS['wmm_tolka_schema_driver'];
            if (empty($driver['has_update_option'])) {
                return null;
            }

            $driver['calls'][] = array(
                'fn' => 'update_option',
                'option' => self::OPTION,
                'autoload' => false,
            );

            if (!empty($driver['option_result'])) {
                $driver['stored_package'] = $package;
                return true;
            }

            return false;
        }

        if (!function_exists('update_option')) {
            return null;
        }

        return update_option(self::OPTION, $package, false);
    }

    /**
     * Read the package option.
     *
     * @return array
     */
    private static function call_get_option()
    {
        if (self::testing()) {
            $driver = &$GLOBALS['wmm_tolka_schema_driver'];
            if (empty($driver['has_get_option'])) {
                return array(
                    'available' => false,
                    'value' => null,
                );
            }

            $driver['calls'][] = array(
                'fn' => 'get_option',
                'option' => self::OPTION,
            );

            return array(
                'available' => true,
                'value' => isset($driver['stored_package']) ? $driver['stored_package'] : null,
            );
        }

        if (!function_exists('get_option')) {
            return array(
                'available' => false,
                'value' => null,
            );
        }

        return array(
            'available' => true,
            'value' => get_option(self::OPTION, null),
        );
    }

    /**
     * Set one post or term meta value.
     *
     * @param string $kind  Either post or term.
     * @param int    $id    Object id.
     * @param string $key   Meta key.
     * @param string $value Meta value.
     * @return bool|null True, false, or null when storage is unavailable.
     */
    private static function call_update_meta($kind, $id, $key, $value)
    {
        $function = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';

        if (self::testing()) {
            $driver = &$GLOBALS['wmm_tolka_schema_driver'];
            $flag = $kind === 'term' ? 'has_update_term_meta' : 'has_update_post_meta';
            if (empty($driver[$flag])) {
                return null;
            }

            $driver['calls'][] = array(
                'fn' => $function,
                'id' => $id,
                'key' => $key,
                'value' => $value,
            );

            $result = $kind === 'term' ? 'term_meta_result' : 'post_meta_result';
            if (!empty($driver[$result])) {
                $driver[$kind . '_meta'][$id][$key] = $value;
                return true;
            }

            return false;
        }

        if (!function_exists($function)) {
            return null;
        }

        return $function($id, $key, $value);
    }

    /**
     * Read one post or term meta value.
     *
     * @param string $kind Either post or term.
     * @param int    $id   Object id.
     * @param string $key  Meta key.
     * @return array
     */
    private static function call_get_meta($kind, $id, $key)
    {
        $function = $kind === 'term' ? 'get_term_meta' : 'get_post_meta';

        if (self::testing()) {
            $driver = &$GLOBALS['wmm_tolka_schema_driver'];
            $flag = $kind === 'term' ? 'has_get_term_meta' : 'has_get_post_meta';
            if (empty($driver[$flag])) {
                return array(
                    'available' => false,
                    'value' => null,
                );
            }

            $driver['calls'][] = array(
                'fn' => $function,
                'id' => $id,
                'key' => $key,
            );

            $value = null;
            if (isset($driver[$kind . '_meta'][$id][$key])) {
                $value = $driver[$kind . '_meta'][$id][$key];
            }

            return array(
                'available' => true,
                'value' => $value,
            );
        }

        if (!function_exists($function)) {
            return array(
                'available' => false,
                'value' => null,
            );
        }

        return array(
            'available' => true,
            'value' => $function($id, $key, true),
        );
    }
}
