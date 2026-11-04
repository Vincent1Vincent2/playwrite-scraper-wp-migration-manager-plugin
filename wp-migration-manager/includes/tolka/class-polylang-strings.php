<?php

/**
 * Reads Polylang string translations for the Tolka migration.
 *
 * Polylang does not keep one string table. Registrations live in memory
 * (pll_register_string) and in the polylang_wpml_strings option. Translations
 * are stored as pairs of original and translation:
 * - language term meta _pll_strings_translations (Polylang 3.4 and newer)
 * - polylang_mo post meta _pll_strings_translations (Polylang 2.1 to 3.3)
 * - polylang_mo post content, a serialized list of the same pairs (before 2.1)
 * - options named polylang_mo_{term_id} when an older copy left them behind
 * Sites that also have WPML's icl_strings tables are included so those
 * strings are not dropped. Nothing in this class writes to the database.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

/**
 * Exports Polylang string translations.
 */
class Polylang_Strings
{
    /**
     * Option rows read during the current export.
     *
     * @var array|null
     */
    private $saved_options = null;

    /**
     * The options query failed and registered strings may be missing.
     *
     * @var bool
     */
    private $option_read_failed = false;

    /**
     * The language taxonomy query failed.
     *
     * @var bool
     */
    private $language_list_failed = false;

    /**
     * A catalogue query failed and some stored strings may be missing.
     *
     * @var bool
     */
    private $catalogue_query_failed = false;

    /**
     * Looking up a language slug by term id failed.
     *
     * @var bool
     */
    private $slug_lookup_failed = false;

    /**
     * Collect string translations.
     *
     * @return array{items: array<int, array>, errors: string[]}
     */
    public function export()
    {
        try {
            return $this->build($this->read_snapshot());
        } catch (\Throwable $e) {
            return array(
                'items' => array(),
                'errors' => array(
                    $this->text('String translations could not be read. Existing Polylang data was left unchanged.'),
                ),
            );
        }
    }

    /**
     * Turn an already-read snapshot into the exporter envelope.
     *
     * @param array $snapshot Raw languages, registrations, and stored translations.
     * @return array{items: array<int, array>, errors: string[]}
     */
    protected function build(array $snapshot)
    {
        $errors = array();
        if (isset($snapshot['errors']) && is_array($snapshot['errors'])) {
            foreach ($snapshot['errors'] as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                }
            }
        }

        $languages = $this->language_list(isset($snapshot['languages']) ? $snapshot['languages'] : array());
        $registered = $this->normalize_registered(isset($snapshot['registered']) ? $snapshot['registered'] : array());
        $items = array();
        $index = array();
        $by_source = array();

        foreach ($registered as $row) {
            $this->add_item(
                $items,
                $index,
                $by_source,
                $row['name'],
                $row['string'],
                $row['context'],
                $languages,
                array()
            );
        }

        $catalogues = isset($snapshot['mo']) && is_array($snapshot['mo']) ? $snapshot['mo'] : array();
        $unreadable = false;
        foreach ($catalogues as $slug => $bucket) {
            $slug = $this->clean_slug($slug);
            if ($slug === '') {
                continue;
            }
            if (!isset($languages[$slug])) {
                $languages[$slug] = $slug;
            }
            $decoded = $this->decode_bucket($bucket);
            if ($decoded === null) {
                $unreadable = true;
                continue;
            }
            foreach ($decoded as $pair) {
                $known = isset($by_source[$pair['source']]) ? $by_source[$pair['source']] : array();
                if ($known) {
                    foreach ($known as $key) {
                        $position = $index[$key];
                        $items[$position]['translations'][$slug] = $pair['translation'];
                    }
                    continue;
                }
                $name = isset($pair['name']) && is_string($pair['name']) ? $pair['name'] : '';
                $context = isset($pair['context']) && is_string($pair['context']) ? $pair['context'] : '';
                $this->add_item(
                    $items,
                    $index,
                    $by_source,
                    $name,
                    $pair['source'],
                    $context,
                    $languages,
                    array($slug => $pair['translation'])
                );
            }
        }

        if ($unreadable) {
            $errors[] = $this->text('Some string translations could not be read and were skipped.');
        }
        if (!empty($snapshot['orphan_groups'])) {
            $errors[] = $this->text('String translations for a language that is no longer installed were skipped.');
        }

        $wpml_rows = isset($snapshot['wpml_rows']) && is_array($snapshot['wpml_rows']) ? $snapshot['wpml_rows'] : array();
        foreach ($wpml_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = $this->normalize_one_registered($row);
            if ($normalized === null) {
                continue;
            }
            $translations = array();
            if (isset($row['translations']) && is_array($row['translations'])) {
                foreach ($row['translations'] as $slug => $value) {
                    $slug = $this->clean_slug($slug);
                    $text = $this->to_text($value);
                    if ($slug === '' || $text === null || $text === '') {
                        continue;
                    }
                    if (!isset($languages[$slug])) {
                        $languages[$slug] = $slug;
                    }
                    $translations[$slug] = $text;
                }
            }
            $this->add_item(
                $items,
                $index,
                $by_source,
                $normalized['name'],
                $normalized['string'],
                $normalized['context'],
                $languages,
                $translations
            );
        }

        $active = !empty($snapshot['active']);
        if (!$items && !$active) {
            array_unshift(
                $errors,
                $this->text('Polylang is not active on this site, and no string translations were found.')
            );
        }

        return array(
            'items' => $this->align_languages($items, $languages),
            'errors' => array_values(array_unique($errors)),
        );
    }

    /**
     * Read every string store this site actually has.
     *
     * @return array
     */
    protected function read_snapshot()
    {
        $this->saved_options = null;
        $this->option_read_failed = false;
        $this->language_list_failed = false;
        $this->catalogue_query_failed = false;
        $this->slug_lookup_failed = false;
        $errors = array();
        $active = $this->polylang_is_active();
        $languages = $this->read_languages();
        if ($this->language_list_failed) {
            $errors[] = $this->text('The language list could not be read. String translations that could still be matched are included.');
        }
        $this->note_translation_taxonomies();

        $registered = $this->read_api_strings();
        if ($active) {
            $registered = array_merge($registered, $this->read_core_option_strings(), $this->read_widget_strings());
        }
        $registered = array_merge($registered, $this->read_saved_registry($errors));

        $stored = $this->read_stored_translations($languages, $errors);

        return array(
            'active' => $active,
            'languages' => $languages,
            'registered' => $registered,
            'mo' => $stored['mo'],
            'orphan_groups' => $stored['orphans'],
            'wpml_rows' => $this->read_wpml_tables($errors),
            'errors' => $errors,
        );
    }

    /**
     * Whether the Polylang plugin is loaded.
     *
     * @return bool
     */
    protected function polylang_is_active()
    {
        return function_exists('pll_languages_list')
            || class_exists('PLL', false)
            || class_exists('Polylang', false);
    }

    /**
     * Polylang's runtime object is safe to call.
     *
     * @return bool
     */
    protected function pll_booted()
    {
        return function_exists('PLL')
            && isset($GLOBALS['polylang'])
            && is_object($GLOBALS['polylang']);
    }

    /**
     * Languages in display order, with term ids when the database has them.
     *
     * @return array<int, array{slug: string, term_id: int}>
     */
    protected function read_languages()
    {
        $from_database = $this->read_language_terms();
        $by_slug = array();
        foreach ($from_database as $language) {
            $by_slug[$language['slug']] = $language;
        }

        $ordered = array();
        $api = $this->read_api_language_slugs();
        if (is_array($api)) {
            foreach ($api as $slug) {
                if (isset($by_slug[$slug])) {
                    $ordered[] = $by_slug[$slug];
                    unset($by_slug[$slug]);
                    continue;
                }
                $ordered[] = array(
                    'slug' => $slug,
                    'term_id' => 0,
                );
            }
        }
        foreach ($by_slug as $language) {
            $ordered[] = $language;
        }

        return $ordered;
    }

    /**
     * Language slugs from pll_languages_list() when that call is safe.
     *
     * @return string[]|null
     */
    protected function read_api_language_slugs()
    {
        if (!$this->pll_booted() || !function_exists('pll_languages_list')) {
            return null;
        }

        try {
            $list = pll_languages_list(array('fields' => 'slug'));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($list)) {
            return null;
        }

        $slugs = array();
        foreach ($list as $slug) {
            $slug = $this->clean_slug($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Language terms from the language taxonomy.
     *
     * @return array<int, array{slug: string, term_id: int}>
     */
    protected function read_language_terms()
    {
        $db = $this->database();
        if (!$db) {
            return array();
        }
        $terms = $this->table_name($db, 'terms');
        $taxonomy = $this->table_name($db, 'term_taxonomy');
        if ($terms === '' || $taxonomy === '') {
            return array();
        }

        $sql = $db->prepare(
            "SELECT t.term_id, t.slug FROM {$terms} t INNER JOIN {$taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.term_group ASC, t.term_id ASC",
            'language'
        );
        $rows = $this->query_rows($sql);
        if (!is_array($rows)) {
            $this->language_list_failed = true;
            return array();
        }

        $languages = array();
        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row)) {
                continue;
            }
            $slug = isset($row['slug']) ? $this->clean_slug($row['slug']) : '';
            if ($slug === '' || isset($languages[$slug])) {
                continue;
            }
            $languages[$slug] = array(
                'slug' => $slug,
                'term_id' => isset($row['term_id']) ? (int) $row['term_id'] : 0,
            );
        }

        return array_values($languages);
    }

    /**
     * Strings registered for this request, including the pll_get_strings filter.
     *
     * @return array
     */
    protected function read_api_strings()
    {
        $can_list = class_exists('PLL_Admin_Strings', false)
            && method_exists('PLL_Admin_Strings', 'get_strings')
            && function_exists('__')
            && function_exists('wp_get_sidebars_widgets')
            && function_exists('apply_filters');

        if ($can_list) {
            $result = $this->capture(function () {
                $strings = PLL_Admin_Strings::get_strings();
                return is_array($strings) ? $strings : null;
            });
            if (is_array($result['value'])) {
                return $result['value'];
            }
        }

        if (!function_exists('apply_filters')) {
            return array();
        }

        $filtered = $this->capture(function () {
            $strings = apply_filters('pll_get_strings', array());
            return is_array($strings) ? $strings : array();
        });

        return is_array($filtered['value']) ? $filtered['value'] : array();
    }

    /**
     * Site title, tagline, and the date formats Polylang registers as strings.
     *
     * @return array<int, array{name: string, string: string, context: string}>
     */
    protected function read_core_option_strings()
    {
        $rows = array();
        $names = array('blogname', 'blogdescription', 'date_format', 'time_format');
        foreach ($names as $name) {
            $value = $this->option($name, null);
            $text = $this->to_text($value);
            if ($text === null || $text === '') {
                continue;
            }
            $rows[] = array(
                'name' => $name,
                'string' => $text,
                'context' => 'WordPress',
            );
        }

        return $rows;
    }

    /**
     * Widget titles and text, matching Polylang's strings screen.
     *
     * Language-specific widgets (pll_lang) and inactive widgets are not
     * string translations. Custom HTML content is included.
     *
     * @return array<int, array{name: string, string: string, context: string}>
     */
    protected function read_widget_strings()
    {
        $sidebars = $this->option('sidebars_widgets', null);
        if (!is_array($sidebars)) {
            return array();
        }

        $title_name = function_exists('__') ? __('Widget title', 'polylang') : 'Widget title';
        $text_name = function_exists('__') ? __('Widget text', 'polylang') : 'Widget text';
        $rows = array();

        foreach ($sidebars as $sidebar => $widgets) {
            if ($sidebar === 'wp_inactive_widgets' || $sidebar === 'array_version' || !is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget_id) {
                if (!is_string($widget_id) || !preg_match('/^(.+)-(\d+)$/', $widget_id, $match)) {
                    continue;
                }
                $settings = $this->option('widget_' . $match[1], null);
                $number = (int) $match[2];
                if (!is_array($settings) || !isset($settings[$number]) || !is_array($settings[$number])) {
                    continue;
                }
                $instance = $settings[$number];
                if (!empty($instance['pll_lang'])) {
                    continue;
                }
                $title = isset($instance['title']) ? $this->to_text($instance['title']) : null;
                if ($title !== null && $title !== '') {
                    $rows[] = array(
                        'name' => $title_name,
                        'string' => $title,
                        'context' => 'Widget',
                    );
                }
                $text = isset($instance['text']) ? $this->to_text($instance['text']) : null;
                if ($text !== null && $text !== '') {
                    $rows[] = array(
                        'name' => $text_name,
                        'string' => $text,
                        'context' => 'Widget',
                    );
                }
                if ($match[1] === 'custom_html') {
                    $content = isset($instance['content']) ? $this->to_text($instance['content']) : null;
                    if ($content !== null && $content !== '') {
                        $rows[] = array(
                            'name' => $text_name,
                            'string' => $content,
                            'context' => 'Widget',
                        );
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Strings saved by icl_register_string in polylang_wpml_strings.
     *
     * @param string[] $errors Collected customer messages.
     * @return array
     */
    protected function read_saved_registry(&$errors)
    {
        $stored = $this->option('polylang_wpml_strings', null);
        if ($stored === null || $stored === false) {
            $stored = $this->saved_option('polylang_wpml_strings');
        }
        if ($stored === null || $stored === false || $stored === '') {
            if ($this->option_read_failed) {
                $errors[] = $this->text('The saved list of registered strings is damaged, so those strings were skipped.');
            }
            return array();
        }
        if (is_string($stored)) {
            $decoded = $this->decode_stored($stored);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        if (!is_array($stored)) {
            $errors[] = $this->text('The saved list of registered strings is damaged, so those strings were skipped.');
            return array();
        }

        return $stored;
    }

    /**
     * Translation catalogues from term meta, legacy posts, and legacy options.
     *
     * Term meta wins when the same original was also stored in an older copy.
     *
     * @param array    $languages Known languages.
     * @param string[] $errors    Collected customer messages.
     * @return array{mo: array, orphans: int}
     */
    protected function read_stored_translations(array $languages, &$errors)
    {
        $slug_by_term = array();
        foreach ($languages as $language) {
            if (!is_array($language) || empty($language['term_id'])) {
                continue;
            }
            $slug = isset($language['slug']) ? $this->clean_slug($language['slug']) : '';
            if ($slug !== '') {
                $slug_by_term[(int) $language['term_id']] = $slug;
            }
        }

        $buckets = array();
        $this->collect_legacy_option_buckets($buckets);
        $this->collect_legacy_post_buckets($buckets);
        $this->collect_term_meta_buckets($buckets);

        $unknown = array();
        foreach (array_keys($buckets) as $term_id) {
            if (!isset($slug_by_term[(int) $term_id])) {
                $unknown[] = (int) $term_id;
            }
        }
        $lookup_failed = false;
        if ($unknown) {
            foreach ($this->read_term_slugs($unknown) as $term_id => $slug) {
                $slug_by_term[(int) $term_id] = $slug;
            }
            $lookup_failed = $this->slug_lookup_failed;
        }

        $mo = array();
        $orphans = 0;
        $damaged = false;
        foreach ($buckets as $term_id => $raw_buckets) {
            $slug = isset($slug_by_term[(int) $term_id]) ? $slug_by_term[(int) $term_id] : '';
            if ($slug === '') {
                if (!$lookup_failed) {
                    $orphans++;
                }
                continue;
            }
            foreach ($raw_buckets as $raw) {
                $decoded = $this->decode_bucket($raw);
                if ($decoded === null) {
                    $damaged = true;
                    continue;
                }
                if (!isset($mo[$slug])) {
                    $mo[$slug] = array();
                }
                foreach ($decoded as $pair) {
                    $mo[$slug][$pair['source']] = $pair;
                }
            }
        }

        $catalogues = array();
        foreach ($mo as $slug => $pairs) {
            $catalogues[$slug] = array_values($pairs);
        }
        if ($damaged || $this->catalogue_query_failed || $lookup_failed) {
            $errors[] = $this->text('Some string translations could not be read and were skipped.');
        }

        return array(
            'mo' => $catalogues,
            'orphans' => $orphans,
        );
    }

    /**
     * @param array $buckets Term id to a list of raw catalogues, oldest first.
     * @return void
     */
    protected function collect_legacy_option_buckets(&$buckets)
    {
        foreach ($this->saved_options() as $name => $value) {
            if (!preg_match('/^polylang_mo_(\d+)$/', (string) $name, $match)) {
                continue;
            }
            $this->append_bucket($buckets, (int) $match[1], $value);
        }
    }

    /**
     * @param array $buckets Term id to a list of raw catalogues.
     * @return void
     */
    protected function collect_legacy_post_buckets(&$buckets)
    {
        $db = $this->database();
        if (!$db) {
            return;
        }
        $posts = $this->table_name($db, 'posts');
        $postmeta = $this->table_name($db, 'postmeta');
        if ($posts === '') {
            return;
        }

        $sql = $db->prepare(
            "SELECT ID, post_title, post_content FROM {$posts} WHERE post_type = %s AND post_status <> %s",
            'polylang_mo',
            'trash'
        );
        $rows = $this->query_rows($sql);
        if (!is_array($rows)) {
            $this->catalogue_query_failed = true;
            return;
        }

        $meta_by_post = array();
        if ($postmeta !== '') {
            $meta_sql = $db->prepare(
                "SELECT post_id, meta_value FROM {$postmeta} WHERE meta_key = %s",
                '_pll_strings_translations'
            );
            $meta_rows = $this->query_rows($meta_sql);
            if (!is_array($meta_rows)) {
                $this->catalogue_query_failed = true;
                $meta_rows = array();
            }
            if (is_array($meta_rows)) {
                foreach ($meta_rows as $meta_row) {
                    $meta_row = $this->row_array($meta_row);
                    if (!is_array($meta_row) || !isset($meta_row['post_id'])) {
                        continue;
                    }
                    $meta_by_post[(int) $meta_row['post_id']] = isset($meta_row['meta_value']) ? $meta_row['meta_value'] : '';
                }
            }
        }

        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row) || empty($row['post_title'])) {
                continue;
            }
            if (!preg_match('/^polylang_mo_(\d+)$/', (string) $row['post_title'], $match)) {
                continue;
            }
            $term_id = (int) $match[1];
            if (isset($row['post_content']) && $row['post_content'] !== '') {
                $this->append_bucket($buckets, $term_id, $row['post_content']);
            }
            $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
            if ($post_id && isset($meta_by_post[$post_id])) {
                $this->append_bucket($buckets, $term_id, $meta_by_post[$post_id]);
            }
        }
    }

    /**
     * @param array $buckets Term id to a list of raw catalogues.
     * @return void
     */
    protected function collect_term_meta_buckets(&$buckets)
    {
        $db = $this->database();
        if (!$db) {
            return;
        }
        $termmeta = $this->table_name($db, 'termmeta');
        if ($termmeta === '') {
            return;
        }

        $sql = $db->prepare(
            "SELECT term_id, meta_value FROM {$termmeta} WHERE meta_key = %s",
            '_pll_strings_translations'
        );
        $rows = $this->query_rows($sql);
        if (!is_array($rows)) {
            $this->catalogue_query_failed = true;
            return;
        }
        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row) || !isset($row['term_id'])) {
                continue;
            }
            $this->append_bucket(
                $buckets,
                (int) $row['term_id'],
                isset($row['meta_value']) ? $row['meta_value'] : ''
            );
        }
    }

    /**
     * @param array $buckets Term id to raw catalogues.
     * @param int   $term_id Language term id.
     * @param mixed $raw     Stored catalogue.
     * @return void
     */
    protected function append_bucket(&$buckets, $term_id, $raw)
    {
        if ($term_id <= 0 || $raw === null || $raw === '' || $raw === array()) {
            return;
        }
        if (!isset($buckets[$term_id])) {
            $buckets[$term_id] = array();
        }
        $buckets[$term_id][] = $raw;
    }

    /**
     * Slugs for term ids that were not in the language list.
     *
     * @param int[] $term_ids Term ids.
     * @return array<int, string>
     */
    protected function read_term_slugs(array $term_ids)
    {
        $db = $this->database();
        if (!$db || !$term_ids) {
            return array();
        }
        $terms = $this->table_name($db, 'terms');
        if ($terms === '') {
            return array();
        }

        $ids = array();
        foreach ($term_ids as $term_id) {
            $ids[] = (int) $term_id;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query = "SELECT term_id, slug FROM {$terms} WHERE term_id IN ({$placeholders})";
        $sql = call_user_func_array(array($db, 'prepare'), array_merge(array($query), $ids));
        $rows = $this->query_rows($sql);
        if (!is_array($rows)) {
            $this->slug_lookup_failed = true;
            return array();
        }

        $slugs = array();
        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row)) {
                continue;
            }
            $slug = isset($row['slug']) ? $this->clean_slug($row['slug']) : '';
            if ($slug !== '' && isset($row['term_id'])) {
                $slugs[(int) $row['term_id']] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Strings left in WPML's icl_strings tables, when those tables exist.
     *
     * @param string[] $errors Collected customer messages.
     * @return array<int, array>
     */
    protected function read_wpml_tables(&$errors)
    {
        $db = $this->database();
        if (!$db) {
            return array();
        }
        $strings_table = $this->prefixed_table('icl_strings');
        $translations_table = $this->prefixed_table('icl_string_translations');
        if ($strings_table === '' || !$this->table_exists($strings_table)) {
            return array();
        }

        $rows = $this->read_table_rows(
            "SELECT id, context, name, value, language FROM {$strings_table}",
            $strings_table
        );
        if (!is_array($rows)) {
            $errors[] = $this->text('A legacy string table could not be read, so those strings were skipped.');
            return array();
        }

        $translations = array();
        if ($translations_table !== '' && $this->table_exists($translations_table)) {
            $translation_rows = $this->read_table_rows(
                "SELECT string_id, language, value FROM {$translations_table}",
                $translations_table
            );
            if (!is_array($translation_rows)) {
                $errors[] = $this->text('A legacy string table could not be read, so those strings were skipped.');
                $translation_rows = array();
            }
            foreach ($translation_rows as $translation_row) {
                $translation_row = $this->row_array($translation_row);
                if (!is_array($translation_row)) {
                    continue;
                }
                $string_id = (int) $this->field($translation_row, array('string_id'));
                if ($string_id <= 0) {
                    continue;
                }
                if (!isset($translations[$string_id])) {
                    $translations[$string_id] = array();
                }
                $language = $this->clean_slug($this->field($translation_row, array('language', 'lang')));
                $value = $this->to_text($this->field($translation_row, array('value', 'mo_string', 'translation')));
                if ($language === '' || $value === null || $value === '') {
                    continue;
                }
                $translations[$string_id][$language] = $value;
            }
        }

        $items = array();
        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row)) {
                continue;
            }
            $string_id = (int) $this->field($row, array('id'));
            $items[] = array(
                'name' => $this->field($row, array('name')),
                'string' => $this->field($row, array('value', 'string_value')),
                'context' => $this->field($row, array('context', 'gettext_context')),
                'translations' => isset($translations[$string_id]) ? $translations[$string_id] : array(),
            );
        }

        return $items;
    }

    /**
     * Read a table, then try every column if the expected names are rejected.
     *
     * @param string $sql   Preferred query.
     * @param string $table Validated table name.
     * @return array|null
     */
    protected function read_table_rows($sql, $table)
    {
        $rows = $this->query_rows($sql);
        if (is_array($rows)) {
            return $rows;
        }
        if ($table === '') {
            return null;
        }

        return $this->query_rows('SELECT * FROM ' . $table);
    }

    /**
     * First non-empty column from a row, compared case-insensitively.
     *
     * @param array    $row   Database row.
     * @param string[] $names Column names, most specific first.
     * @return mixed|null
     */
    protected function field(array $row, array $names)
    {
        $lower = array();
        foreach ($row as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }
        foreach ($names as $name) {
            if (!array_key_exists($name, $lower)) {
                continue;
            }
            if ($lower[$name] === null || $lower[$name] === '') {
                continue;
            }

            return $lower[$name];
        }

        return null;
    }

    /**
     * Touch the post and term translation taxonomies without failing if they are missing.
     *
     * String translations are not stored on those taxonomies. The read only
     * makes sure a missing taxonomy cannot abort the export.
     *
     * @return void
     */
    protected function note_translation_taxonomies()
    {
        $db = $this->database();
        if (!$db) {
            return;
        }
        $taxonomy = $this->table_name($db, 'term_taxonomy');
        if ($taxonomy === '') {
            return;
        }

        foreach (array('post_translations', 'term_translations') as $name) {
            try {
                $sql = $db->prepare(
                    "SELECT tt.term_taxonomy_id FROM {$taxonomy} tt WHERE tt.taxonomy = %s LIMIT 1",
                    $name
                );
                $this->query_rows($sql);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * Run a callback and keep a warning from becoming a fatal error.
     *
     * @param callable $callback Reader that may warn when Polylang is half loaded.
     * @return array{failed: bool, value: mixed}
     */
    protected function capture($callback)
    {
        $failed = false;
        set_error_handler(function () use (&$failed) {
            $failed = true;
            return true;
        });
        try {
            $value = call_user_func($callback);
        } catch (\Throwable $e) {
            $failed = true;
            $value = null;
        }
        restore_error_handler();

        return array(
            'failed' => $failed,
            'value' => $value,
        );
    }

    /**
     * @param mixed $raw Languages from a snapshot.
     * @return array<string, string>
     */
    protected function language_list($raw)
    {
        $languages = array();
        if (!is_array($raw)) {
            return $languages;
        }
        foreach ($raw as $language) {
            $slug = '';
            if (is_array($language) && isset($language['slug'])) {
                $slug = $this->clean_slug($language['slug']);
            } elseif (is_object($language) && isset($language->slug)) {
                $slug = $this->clean_slug($language->slug);
            } else {
                $slug = $this->clean_slug($language);
            }
            if ($slug === '' || isset($languages[$slug])) {
                continue;
            }
            $languages[$slug] = $slug;
        }

        return $languages;
    }

    /**
     * @param mixed $raw Registered string rows.
     * @return array<int, array{name: string, string: string, context: string}>
     */
    protected function normalize_registered($raw)
    {
        if (!is_array($raw)) {
            return array();
        }
        $rows = array();
        foreach ($raw as $row) {
            $normalized = $this->normalize_one_registered($row);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * @param mixed $row One registered string.
     * @return array{name: string, string: string, context: string}|null
     */
    protected function normalize_one_registered($row)
    {
        if (is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!is_array($row)) {
            return null;
        }

        $name = isset($row['name']) ? $row['name'] : '';
        $context = isset($row['context']) ? $row['context'] : '';
        if (is_array($context)) {
            if (isset($context['context']) && is_scalar($context['context']) && !is_bool($context['context'])) {
                $prefix = is_scalar($name) && !is_bool($name) ? (string) $name : '';
                $name = $prefix . (string) $context['context'];
            }
            $context = isset($context['domain']) && is_scalar($context['domain']) && !is_bool($context['domain'])
                ? (string) $context['domain']
                : '';
        }

        $string = null;
        foreach (array('string', 'value', 'singular', 'original') as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $text = $this->to_text($row[$key]);
            if ($text !== null && $text !== '') {
                $string = $text;
                break;
            }
        }
        if ($string === null) {
            return null;
        }

        $name = $this->to_text($name);
        $context = $this->to_text($context);

        return array(
            'name' => $name === null ? '' : $name,
            'string' => $string,
            'context' => $context === null ? '' : $context,
        );
    }

    /**
     * Decode one language catalogue.
     *
     * Returns null only when the container itself cannot be read.
     * Empty originals and empty translations are skipped.
     *
     * @param mixed $raw Stored catalogue.
     * @return array<int, array>|null
     */
    protected function decode_bucket($raw)
    {
        if ($raw === null || $raw === false || $raw === '') {
            return array();
        }
        if (is_string($raw)) {
            $decoded = $this->decode_stored($raw);
            if ($decoded === null) {
                return null;
            }
            $raw = $decoded;
        }
        if (is_object($raw)) {
            $vars = get_object_vars($raw);
            if (isset($vars['entries'])) {
                return $this->decode_bucket($vars['entries']);
            }
            $raw = $vars;
        }
        if (!is_array($raw)) {
            return null;
        }
        if (!$raw) {
            return array();
        }
        if (isset($raw['entries']) && (is_array($raw['entries']) || is_object($raw['entries']))) {
            return $this->decode_bucket($raw['entries']);
        }

        if (!$this->is_list($raw)) {
            $pairs = array();
            foreach ($raw as $source => $translation) {
                $pair = null;
                if (is_array($translation) || is_object($translation)) {
                    $pair = $this->decode_pair($translation);
                }
                if ($pair === null) {
                    $pair = $this->pair_from_parts($source, $translation, null, null);
                }
                if ($pair !== null) {
                    $pairs[] = $pair;
                }
            }
            return $pairs;
        }

        if ($this->looks_like_pair($raw)) {
            $pair = $this->decode_pair($raw);
            return $pair === null ? array() : array($pair);
        }

        $pairs = array();
        foreach ($raw as $row) {
            if (is_string($row)) {
                $nested = $this->decode_stored($row);
                if (is_array($nested)) {
                    $row = $nested;
                }
            }
            $pair = $this->decode_pair($row);
            if ($pair !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @param mixed $row One stored translation row.
     * @return array|null
     */
    protected function decode_pair($row)
    {
        if (is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!is_array($row)) {
            return null;
        }

        if (!$this->is_list($row)) {
            $source = null;
            foreach (array('singular', 'source', 'string', 'msgid', 'original', 'value') as $key) {
                if (!isset($row[$key])) {
                    continue;
                }
                $text = $this->to_text($row[$key]);
                if ($text !== null && $text !== '') {
                    $source = $text;
                    break;
                }
            }
            $translation = null;
            foreach (array('translation', 'translated', 'target', 'msgstr') as $key) {
                if (!isset($row[$key])) {
                    continue;
                }
                $text = $this->translation_text($row[$key]);
                if ($text !== null && $text !== '') {
                    $translation = $text;
                    break;
                }
            }
            if (($translation === null || $translation === '') && isset($row['translations'])) {
                $translation = $this->translation_text($row['translations']);
            }
            $name = isset($row['name']) ? $this->to_text($row['name']) : null;
            $context = isset($row['context']) ? $this->to_text($row['context']) : null;
            return $this->pair_from_parts($source, $translation, $name, $context);
        }

        $values = array_values($row);
        if (count($values) < 2) {
            return null;
        }
        $translation = $this->translation_text($values[1]);
        if (($translation === null || $translation === '') && isset($values[2])) {
            $translation = $this->translation_text($values[2]);
        }

        return $this->pair_from_parts($values[0], $translation, null, null);
    }

    /**
     * @param mixed       $source      Original string.
     * @param mixed       $translation Translation.
     * @param string|null $name        Optional registration name.
     * @param string|null $context     Optional group.
     * @return array|null
     */
    protected function pair_from_parts($source, $translation, $name, $context)
    {
        $source = $this->to_text($source);
        $translation = $this->translation_text($translation);
        if ($source === null || $source === '' || $translation === null || $translation === '') {
            return null;
        }

        return array(
            'source' => $source,
            'translation' => $translation,
            'name' => $name,
            'context' => $context,
        );
    }

    /**
     * @param mixed $value Stored translation, or a list of plural forms.
     * @return string|null
     */
    protected function translation_text($value)
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                $text = $this->translation_text($part);
                if ($text !== null && $text !== '') {
                    return $text;
                }
            }
            return null;
        }

        return $this->to_text($value);
    }

    /**
     * Unserialize a catalogue without creating objects.
     *
     * A catalogue is sometimes serialized twice, or stored with slashes, after
     * an older export and import. Nested strings are unwrapped a few times.
     *
     * @param string $value Serialized or JSON text.
     * @param int    $depth How many wrappers have already been removed.
     * @return array|object|null
     */
    protected function decode_stored($value, $depth = 0)
    {
        if ($depth > 4 || !is_string($value) || $value === '') {
            return null;
        }
        $decoded = $this->safe_unserialize($value);
        if (is_string($decoded) && $decoded !== '') {
            $unwrapped = $this->decode_stored($decoded, $depth + 1);
            if ($unwrapped !== null) {
                return $unwrapped;
            }
        }
        if (is_array($decoded) || is_object($decoded)) {
            return $decoded;
        }
        $stripped = stripslashes($value);
        if ($stripped !== $value) {
            $unwrapped = $this->decode_stored($stripped, $depth + 1);
            if ($unwrapped !== null) {
                return $unwrapped;
            }
        }
        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        return null;
    }

    /**
     * @param string $value Serialized PHP.
     * @return mixed|null
     */
    protected function safe_unserialize($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!preg_match('/^[aOsibdN]:/', $value) && $value !== 'b:0;') {
            return null;
        }
        $decoded = @unserialize($value, array('allowed_classes' => false));
        if ($decoded === false && $value !== 'b:0;') {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array    $items         Built items.
     * @param array    $index         Key to item position.
     * @param array    $by_source     Original string to item keys.
     * @param string   $name          Registration name.
     * @param string   $string        Original string.
     * @param string   $context       Group.
     * @param array    $languages     Language slugs.
     * @param string[] $translations  Translations to fill when still empty.
     * @return void
     */
    protected function add_item(&$items, &$index, &$by_source, $name, $string, $context, array $languages, array $translations)
    {
        $key = $context . "\0" . $name . "\0" . $string;
        if (isset($index[$key])) {
            $position = $index[$key];
            foreach ($translations as $slug => $text) {
                if (!is_string($text) || $text === '') {
                    continue;
                }
                if (!isset($items[$position]['translations'][$slug]) || $items[$position]['translations'][$slug] === '') {
                    $items[$position]['translations'][$slug] = $text;
                }
            }
            return;
        }

        $map = array();
        foreach ($languages as $slug => $unused) {
            $map[$slug] = '';
        }
        foreach ($translations as $slug => $text) {
            if (!is_string($slug) || $slug === '' || !is_string($text) || $text === '') {
                continue;
            }
            $map[$slug] = $text;
        }

        $index[$key] = count($items);
        $items[] = array(
            'name' => $name,
            'string' => $string,
            'context' => $context,
            'translations' => $map,
        );
        if (!isset($by_source[$string])) {
            $by_source[$string] = array();
        }
        $by_source[$string][] = $key;
    }

    /**
     * Give every item the same language keys, in language order.
     *
     * @param array $items     Built items.
     * @param array $languages Ordered language slugs.
     * @return array<int, array>
     */
    protected function align_languages(array $items, array $languages)
    {
        $aligned = array();
        foreach ($items as $item) {
            $translations = array();
            foreach ($languages as $slug => $unused) {
                $current = '';
                if (isset($item['translations'][$slug]) && is_string($item['translations'][$slug])) {
                    $current = $item['translations'][$slug];
                }
                $translations[$slug] = $current;
            }
            $aligned[] = array(
                'name' => isset($item['name']) ? (string) $item['name'] : '',
                'string' => isset($item['string']) ? (string) $item['string'] : '',
                'context' => isset($item['context']) ? (string) $item['context'] : '',
                'translations' => $translations,
            );
        }

        return $aligned;
    }

    /**
     * @param mixed $slug Language slug.
     * @return string
     */
    protected function clean_slug($slug)
    {
        if (is_int($slug)) {
            return (string) $slug;
        }
        if (!is_string($slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * @param mixed $value Scalar value.
     * @return string|null
     */
    protected function to_text($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array $array Array that may be a list or a map.
     * @return bool
     */
    protected function is_list(array $array)
    {
        $expected = 0;
        foreach ($array as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /**
     * A Polylang pair is [original, translation], not a list of pairs.
     *
     * @param array $row Candidate row.
     * @return bool
     */
    protected function looks_like_pair(array $row)
    {
        $values = array_values($row);
        if (count($values) < 2 || is_array($values[0]) || is_object($values[0])) {
            return false;
        }

        return is_scalar($values[0]) && (is_scalar($values[1]) || is_array($values[1]));
    }

    /**
     * Read one named option from the options table.
     *
     * @param string $name Option name.
     * @return mixed|null
     */
    protected function saved_option($name)
    {
        $options = $this->saved_options();
        if (!array_key_exists($name, $options)) {
            return null;
        }

        return $options[$name];
    }

    /**
     * polylang_wpml_strings and any polylang_mo_* options.
     *
     * @return array<string, mixed>
     */
    protected function saved_options()
    {
        if (is_array($this->saved_options)) {
            return $this->saved_options;
        }

        $this->saved_options = array();
        $db = $this->database();
        if (!$db) {
            return $this->saved_options;
        }
        $table = $this->table_name($db, 'options');
        if ($table === '') {
            return $this->saved_options;
        }

        $like = $db->esc_like('polylang_mo_') . '%';
        $sql = $db->prepare(
            "SELECT option_name, option_value FROM {$table} WHERE option_name = %s OR option_name LIKE %s",
            'polylang_wpml_strings',
            $like
        );
        $rows = $this->query_rows($sql);
        if (!is_array($rows)) {
            $this->option_read_failed = true;
            return $this->saved_options;
        }
        foreach ($rows as $row) {
            $row = $this->row_array($row);
            if (!is_array($row) || !isset($row['option_name'])) {
                continue;
            }
            $this->saved_options[(string) $row['option_name']] = isset($row['option_value']) ? $row['option_value'] : '';
        }

        return $this->saved_options;
    }

    /**
     * @param string $sql Prepared SQL.
     * @return array|null Null when the query failed.
     */
    protected function query_rows($sql)
    {
        $db = $this->database();
        if (!$db || !is_string($sql) || $sql === '') {
            return null;
        }
        $db->last_error = '';
        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $db->get_results($sql, $output);
        if (!empty($db->last_error)) {
            return null;
        }
        if (!is_array($rows)) {
            return array();
        }

        return $rows;
    }

    /**
     * @param string $table Full table name.
     * @return bool
     */
    protected function table_exists($table)
    {
        $db = $this->database();
        if (!$db || $table === '') {
            return false;
        }
        $sql = $db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table));
        $found = $db->get_var($sql);
        if (!is_string($found) || $found === '') {
            return false;
        }

        return strtolower($found) === strtolower($table);
    }

    /**
     * @param object $db       Database handle.
     * @param string $property WordPress table property, such as posts.
     * @return string
     */
    protected function table_name($db, $property)
    {
        if (!is_object($db) || !isset($db->$property) || !is_string($db->$property)) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $db->$property)) {
            return '';
        }

        return $db->$property;
    }

    /**
     * @param string $suffix Table name without the blog prefix.
     * @return string
     */
    protected function prefixed_table($suffix)
    {
        $db = $this->database();
        if (!$db || !isset($db->prefix) || !is_string($db->prefix)) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $db->prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $suffix)) {
            return '';
        }

        return $db->prefix . $suffix;
    }

    /**
     * @param mixed $row Database row.
     * @return array|null
     */
    protected function row_array($row)
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row)) {
            return get_object_vars($row);
        }

        return null;
    }

    /**
     * WordPress database handle, or null when WordPress is not loaded.
     *
     * @return object|null
     */
    protected function database()
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return null;
        }
        foreach (array('get_results', 'get_var', 'prepare', 'esc_like') as $method) {
            if (!method_exists($wpdb, $method)) {
                return null;
            }
        }

        return $wpdb;
    }

    /**
     * Read an option without failing when WordPress is not loaded.
     *
     * @param string $name    Option name.
     * @param mixed  $default Value when the option cannot be read.
     * @return mixed
     */
    protected function option($name, $default = null)
    {
        if (!function_exists('get_option')) {
            return $default;
        }

        return get_option($name, $default);
    }

    /**
     * Customer-facing sentence, translated when WordPress is loaded.
     *
     * @param string $message English sentence.
     * @return string
     */
    protected function text($message)
    {
        if (function_exists('__')) {
            return __($message, 'migration-manager');
        }

        return $message;
    }
}
