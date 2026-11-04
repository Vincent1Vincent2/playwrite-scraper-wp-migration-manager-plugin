<?php

/**
 * Export Polylang media for the Tolka migration.
 *
 * Translated media is a group of attachment posts that share one file.
 * Alt text and titles are stored on each attachment. Attachments that were
 * never given a translation are included too, so the media library is not
 * dropped when Polylang's media module is off. This class only reads that
 * data. It does not delete Polylang rows, options, files, or meta.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    exit;
}

/**
 * Reads Polylang attachment translations into migration items.
 */
class Polylang_Media
{
    /**
     * Preferred language order for one export() call.
     *
     * @var array|null
     */
    private $language_order = null;

    /**
     * Export media translation groups.
     *
     * Each item is one group: the default-language attachment when that
     * translation exists, the shared file, and alt and title maps keyed by
     * language slug. An attachment with no translations is its own item.
     *
     * @return array
     */
    public function export()
    {
        $this->language_order = null;

        try {
            if (!$this->polylang_is_present()) {
                return $this->envelope(array(), array(
                    $this->text('Polylang is not active on this site, so media could not be read.'),
                ));
            }

            $read = $this->read_library();
            if (!is_array($read)) {
                return $this->envelope(array(), array(
                    $this->text('Media could not be read from this site. Nothing was changed.'),
                ));
            }

            $parts = $this->items_from_parts(
                isset($read['translation_rows']) ? $read['translation_rows'] : array(),
                isset($read['language_rows']) ? $read['language_rows'] : array(),
                isset($read['attachment_ids']) ? $read['attachment_ids'] : array(),
                isset($read['known_languages']) ? $read['known_languages'] : array(),
                isset($read['default_lang']) ? $read['default_lang'] : '',
                isset($read['records']) ? $read['records'] : null,
                isset($read['excluded_ids']) ? $read['excluded_ids'] : array()
            );
        } catch (\Throwable $e) {
            return $this->envelope(array(), array(
                $this->text('Media could not be read from this site. Nothing was changed.'),
            ));
        }

        $skipped = (int) $parts['skipped'] + (isset($read['skipped']) ? (int) $read['skipped'] : 0);
        return $this->envelope(
            $parts['items'],
            $this->errors_for_result($skipped, $parts['items'])
        );
    }

    /**
     * @param int $skipped
     * @param array $items
     * @return array
     */
    private function errors_for_result($skipped, $items)
    {
        return array_merge(
            $this->messages_for($skipped),
            $this->missing_file_message($this->count_missing_files($items))
        );
    }

    /**
     * @param array $items
     * @return int
     */
    private function count_missing_files($items)
    {
        $count = 0;
        if (!is_array($items)) {
            return 0;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = isset($item['file']) ? $item['file'] : '';
            if (!is_string($file) || $file === '') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * One sentence when exported media has no stored file path.
     *
     * @param int $count
     * @return array
     */
    private function missing_file_message($count)
    {
        $count = (int) $count;
        if ($count <= 0) {
            return array();
        }
        if ($count === 1) {
            return array(
                $this->text('One media item has no file path and was exported with an empty file. Nothing was changed.'),
            );
        }

        $template = $this->text('%d media items have no file path and were exported with an empty file. Nothing was changed.');
        if (strpos($template, '%d') === false) {
            return array($template);
        }

        return array(sprintf($template, $count));
    }

    /**
     * @param int $skipped
     * @return array
     */
    private function messages_for($skipped)
    {
        $skipped = (int) $skipped;
        if ($skipped <= 0) {
            return array();
        }
        if ($skipped === 1) {
            return array(
                $this->text('One media file could not be read and was left out of this export. Nothing was changed.'),
            );
        }

        $template = $this->text('%d media files could not be read and were left out of this export. Nothing was changed.');
        if (strpos($template, '%d') === false) {
            return array($template);
        }

        return array(sprintf($template, $skipped));
    }

    /**
     * Build export items from already loaded Polylang rows.
     *
     * Records may be null when titles and file meta should be loaded here.
     *
     * @param array $translation_rows
     * @param array $language_rows
     * @param array $attachment_ids
     * @param array $known_languages
     * @param string $default_lang
     * @param array|null $records
     * @param array $excluded_ids
     * @return array
     */
    private function items_from_parts($translation_rows, $language_rows, $attachment_ids, $known_languages, $default_lang, $records, $excluded_ids = array())
    {
        $built = $this->groups_from_rows($translation_rows, $language_rows, $attachment_ids, $known_languages);
        $bare_ids = $this->unassigned_ids(
            $attachment_ids,
            isset($built['seen']) ? $built['seen'] : array(),
            $excluded_ids
        );
        if (!is_array($records)) {
            $record_ids = $this->ids_in_groups($built['groups']);
            foreach ($bare_ids as $bare_id) {
                $record_ids[] = $bare_id;
            }
            $records = $this->records_for($record_ids);
        }

        return array(
            'items' => $this->assemble_items($built['groups'], $bare_ids, $default_lang, $known_languages, $records),
            'skipped' => (int) $built['skipped'],
            'seen' => $built['seen'],
        );
    }

    /**
     * @param array $items
     * @param array $errors
     * @return array
     */
    private function envelope($items, $errors)
    {
        $clean_items = array();
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $attachment_id = isset($item['attachment_id']) ? (int) $item['attachment_id'] : 0;
                if ($attachment_id <= 0) {
                    continue;
                }
                $clean_items[] = $this->finalize_item(
                    $attachment_id,
                    isset($item['file']) ? $item['file'] : '',
                    (isset($item['alt']) && is_array($item['alt'])) ? $item['alt'] : array(),
                    (isset($item['title']) && is_array($item['title'])) ? $item['title'] : array()
                );
            }
        }

        $clean_errors = array();
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_string($error) && $error !== '') {
                    $clean_errors[] = $error;
                }
            }
        }

        return array(
            'items' => $clean_items,
            'errors' => $clean_errors,
        );
    }

    /**
     * Polylang is present when its API, classes, taxonomies, options, or rows exist.
     *
     * @return bool
     */
    private function polylang_is_present()
    {
        if (function_exists('pll_languages_list')
            || function_exists('pll_get_post_translations')
            || function_exists('pll_get_post_language')
            || function_exists('pll_default_language')
        ) {
            return true;
        }

        if (class_exists('PLL', false) || class_exists('Polylang', false)) {
            return true;
        }

        if (function_exists('taxonomy_exists')
            && (taxonomy_exists('language') || taxonomy_exists('post_translations') || taxonomy_exists('term_translations'))
        ) {
            return true;
        }

        if (function_exists('get_option')) {
            $options = get_option('polylang');
            if (is_array($options) && $options !== array()) {
                return true;
            }
        }

        $stored = $this->database_has_polylang();
        if ($stored === null) {
            throw new \RuntimeException('Polylang media lookup failed.');
        }

        return $stored;
    }

    /**
     * True when Polylang rows exist, false when they do not, null when the lookup failed.
     *
     * @return bool|null
     */
    private function database_has_polylang()
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->term_taxonomy)) {
            return false;
        }

        $table = $this->table_name($wpdb->term_taxonomy);
        if ($table === '' || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        $sql = $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$table} WHERE taxonomy IN (%s, %s, %s) LIMIT 1",
            'language',
            'post_translations',
            'term_translations'
        );
        if (!is_string($sql) || $sql === '') {
            return false;
        }

        $before = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        $found = $wpdb->get_var($sql);
        $after = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        if ($after !== '' && $after !== $before) {
            return null;
        }

        return $found !== null && $found !== '';
    }

    /**
     * @return array|null
     */
    private function read_library()
    {
        $default_lang = $this->default_language();
        $known = $this->known_language_slugs();
        $collected = null;

        if (function_exists('pll_get_post_translations') || function_exists('pll_get_post_language')) {
            try {
                $collected = $this->collect_from_api();
            } catch (\Throwable $e) {
                $collected = null;
            }
        }

        if (!is_array($collected)) {
            $collected = $this->collect_from_storage();
        }

        if (!is_array($collected)) {
            return null;
        }

        $collected['known_languages'] = $known;
        $collected['default_lang'] = $default_lang;
        if (!isset($collected['records']) || !is_array($collected['records'])) {
            $collected['records'] = null;
        }
        if (!isset($collected['skipped'])) {
            $collected['skipped'] = 0;
        }

        return $collected;
    }

    /**
     * @return array|null
     */
    private function collect_from_api()
    {
        $ids = $this->attachment_ids();
        if (!is_array($ids)) {
            return null;
        }
        $this->prime_attachment_cache($ids);

        $rows = array();
        $language_rows = array();
        $seen_groups = array();
        $claimed = array();
        $excluded = array();
        $skipped = 0;

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($claimed[$id])) {
                continue;
            }

            try {
                $map = $this->api_translation_map($id);
                if ($map !== array() && !$this->map_has_id($map, $id)) {
                    if (!$this->is_exportable_attachment($id)) {
                        $claimed[$id] = true;
                    } else {
                        $lang = $this->api_post_language($id);
                        if ($lang !== '' && !isset($map[$lang])) {
                            $map[$lang] = $id;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $skipped++;
                $excluded[] = $id;
                $claimed[$id] = true;
                continue;
            }

            if ($map === array()) {
                $lang = $this->api_post_language($id);
                if ($lang !== '') {
                    $language_rows[] = array(
                        'id' => $id,
                        'lang' => $lang,
                    );
                }
                continue;
            }

            $key = $this->id_key(array_values($map));
            if (isset($seen_groups[$key])) {
                $claimed[$id] = true;
                continue;
            }
            $seen_groups[$key] = true;

            $members = array();
            foreach ($map as $lang => $member_id) {
                $member_id = (int) $member_id;
                $claimed[$member_id] = true;
                if (!isset($members[$member_id])) {
                    $members[$member_id] = $lang;
                }
            }

            $rows[] = array(
                'description' => $map,
                'members' => $members,
            );
        }

        return array(
            'translation_rows' => $rows,
            'language_rows' => $language_rows,
            'attachment_ids' => $ids,
            'excluded_ids' => $excluded,
            'skipped' => $skipped,
        );
    }

    /**
     * @param int $id
     * @return array
     */
    private function api_translation_map($id)
    {
        if (!function_exists('pll_get_post_translations')) {
            return array();
        }

        try {
            $translations = pll_get_post_translations($id);
        } catch (\Throwable $e) {
            return array();
        }

        if (!is_array($translations)) {
            return array();
        }

        $map = array();
        foreach ($translations as $lang => $translated_id) {
            $slug = $this->language_slug($lang);
            $translated_id = (int) $translated_id;
            if ($slug === '' || $translated_id <= 0 || isset($map[$slug])) {
                continue;
            }
            if (!$this->is_exportable_attachment($translated_id)) {
                continue;
            }
            $map[$slug] = $translated_id;
        }

        return $map;
    }

    /**
     * @param int $id
     * @return string
     */
    private function api_post_language($id)
    {
        if (!function_exists('pll_get_post_language')) {
            return '';
        }

        try {
            $lang = pll_get_post_language($id, 'slug');
        } catch (\Throwable $e) {
            try {
                $lang = pll_get_post_language($id);
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $this->language_slug($lang);
    }

    /**
     * @return array|null
     */
    private function collect_from_storage()
    {
        try {
            $collected = $this->collect_from_database();
        } catch (\Throwable $e) {
            $collected = null;
        }

        if (is_array($collected)) {
            return $collected;
        }

        try {
            return $this->collect_from_term_api();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array|null
     */
    private function collect_from_database()
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return null;
        }

        $posts = $this->table_name(isset($wpdb->posts) ? $wpdb->posts : '');
        $terms = $this->table_name(isset($wpdb->terms) ? $wpdb->terms : '');
        $term_taxonomy = $this->table_name(isset($wpdb->term_taxonomy) ? $wpdb->term_taxonomy : '');
        $term_relationships = $this->table_name(isset($wpdb->term_relationships) ? $wpdb->term_relationships : '');
        if ($posts === '' || $terms === '' || $term_taxonomy === '' || $term_relationships === '') {
            return null;
        }

        $attachment_ids = $this->attachment_ids();
        if (!is_array($attachment_ids)) {
            return null;
        }

        $translation_rows = $this->translation_rows_from_db($wpdb, $posts, $terms, $term_taxonomy, $term_relationships);
        if (!is_array($translation_rows)) {
            return null;
        }

        $language_rows = $this->language_rows_from_db($wpdb, $posts, $terms, $term_taxonomy, $term_relationships);
        if (!is_array($language_rows)) {
            $language_rows = array();
        }

        return array(
            'translation_rows' => $translation_rows,
            'language_rows' => $language_rows,
            'attachment_ids' => $attachment_ids,
            'skipped' => 0,
        );
    }

    /**
     * @param object $wpdb
     * @param string $posts
     * @param string $terms
     * @param string $term_taxonomy
     * @param string $term_relationships
     * @return array|null
     */
    private function translation_rows_from_db($wpdb, $posts, $terms, $term_taxonomy, $term_relationships)
    {
        $sql = "SELECT tt.term_taxonomy_id, tt.description, tr.object_id, lang.slug AS language_slug
            FROM {$term_taxonomy} tt
            INNER JOIN {$term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$posts} p ON p.ID = tr.object_id
                AND p.post_type = 'attachment'
                AND p.post_status IN (" . $this->status_sql() . ")
            LEFT JOIN {$term_relationships} tr_lang ON tr_lang.object_id = tr.object_id
            LEFT JOIN {$term_taxonomy} tt_lang ON tt_lang.term_taxonomy_id = tr_lang.term_taxonomy_id
                AND tt_lang.taxonomy = 'language'
            LEFT JOIN {$terms} lang ON lang.term_id = tt_lang.term_id
            WHERE tt.taxonomy = %s";

        $rows = $this->query_rows($wpdb, $sql, array('post_translations'));
        if (!is_array($rows)) {
            return null;
        }

        $buckets = array();
        foreach ($rows as $row) {
            $term_id = (int) $this->row_value($row, 'term_taxonomy_id');
            $object_id = (int) $this->row_value($row, 'object_id');
            if ($term_id <= 0 || $object_id <= 0) {
                continue;
            }
            if (!isset($buckets[$term_id])) {
                $description = $this->row_value($row, 'description');
                $buckets[$term_id] = array(
                    'description' => $description === null ? '' : $description,
                    'members' => array(),
                );
            }
            $lang = $this->language_slug($this->row_value($row, 'language_slug'));
            if (!isset($buckets[$term_id]['members'][$object_id])) {
                $buckets[$term_id]['members'][$object_id] = $lang;
            } elseif ($buckets[$term_id]['members'][$object_id] === '' && $lang !== '') {
                $buckets[$term_id]['members'][$object_id] = $lang;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param object $wpdb
     * @param string $posts
     * @param string $terms
     * @param string $term_taxonomy
     * @param string $term_relationships
     * @return array|null
     */
    private function language_rows_from_db($wpdb, $posts, $terms, $term_taxonomy, $term_relationships)
    {
        $sql = "SELECT p.ID AS attachment_id, t.slug AS language_slug
            FROM {$posts} p
            INNER JOIN {$term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'language'
            INNER JOIN {$terms} t ON t.term_id = tt.term_id
            WHERE p.post_type = 'attachment'
                AND p.post_status IN (" . $this->status_sql() . ")";

        $rows = $this->query_rows($wpdb, $sql, array());
        if (!is_array($rows)) {
            return null;
        }

        $language_rows = array();
        $seen = array();
        foreach ($rows as $row) {
            $id = (int) $this->row_value($row, 'attachment_id');
            $lang = $this->language_slug($this->row_value($row, 'language_slug'));
            if ($id <= 0 || $lang === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $language_rows[] = array(
                'id' => $id,
                'lang' => $lang,
            );
        }

        return $language_rows;
    }

    /**
     * @return array|null
     */
    private function collect_from_term_api()
    {
        if (!function_exists('wp_get_object_terms')) {
            return null;
        }
        if (function_exists('taxonomy_exists')
            && !taxonomy_exists('language')
            && !taxonomy_exists('post_translations')
        ) {
            return null;
        }

        $ids = $this->attachment_ids();
        if (!is_array($ids)) {
            return null;
        }

        $language_terms = $this->object_terms($ids, 'language');
        $translation_terms = $this->object_terms($ids, 'post_translations');
        if ($language_terms === null || $translation_terms === null) {
            return null;
        }

        $languages = array();
        foreach ($language_terms as $term) {
            $id = (int) $this->row_value($term, 'object_id');
            $lang = $this->language_slug($this->row_value($term, 'slug'));
            if ($id > 0 && $lang !== '' && !isset($languages[$id])) {
                $languages[$id] = $lang;
            }
        }

        $language_rows = array();
        foreach ($languages as $id => $lang) {
            $language_rows[] = array(
                'id' => (int) $id,
                'lang' => $lang,
            );
        }

        $buckets = array();
        foreach ($translation_terms as $term) {
            $id = (int) $this->row_value($term, 'object_id');
            $term_key = (int) $this->row_value($term, 'term_taxonomy_id');
            if ($term_key <= 0) {
                $term_key = (int) $this->row_value($term, 'term_id');
            }
            if ($id <= 0 || $term_key <= 0) {
                continue;
            }
            if (!isset($buckets[$term_key])) {
                $description = $this->row_value($term, 'description');
                $buckets[$term_key] = array(
                    'description' => $description === null ? '' : $description,
                    'members' => array(),
                );
            }
            $lang = isset($languages[$id]) ? $languages[$id] : '';
            if (!isset($buckets[$term_key]['members'][$id]) || ($buckets[$term_key]['members'][$id] === '' && $lang !== '')) {
                $buckets[$term_key]['members'][$id] = $lang;
            }
        }

        return array(
            'translation_rows' => array_values($buckets),
            'language_rows' => $language_rows,
            'attachment_ids' => $ids,
            'skipped' => 0,
        );
    }

    /**
     * @param array $ids
     * @param string $taxonomy
     * @return array|null Null when the taxonomy exists but every read failed.
     */
    private function object_terms($ids, $taxonomy)
    {
        if (!function_exists('wp_get_object_terms') || !is_array($ids) || $ids === array()) {
            return array();
        }
        if (function_exists('taxonomy_exists') && !taxonomy_exists($taxonomy)) {
            return array();
        }

        $terms = array();
        $chunks = array_chunk($ids, 200);
        $errors = 0;
        foreach ($chunks as $chunk) {
            $found = wp_get_object_terms($chunk, $taxonomy, array(
                'fields' => 'all_with_object_id',
            ));
            if ((function_exists('is_wp_error') && is_wp_error($found)) || !is_array($found)) {
                $errors++;
                continue;
            }
            foreach ($found as $term) {
                $terms[] = $term;
            }
        }

        if ($chunks !== array() && $errors === count($chunks)) {
            return null;
        }

        return $terms;
    }

    /**
     * Turn Polylang rows into one map per media item.
     *
     * A translation description is the source of truth. Relationship rows fill
     * gaps when that description is missing or incomplete. Polylang does not
     * create a translation term for an attachment that has no translations,
     * so a language row becomes a one-language group.
     *
     * @param array $translation_rows
     * @param array $language_rows
     * @param array $attachment_ids
     * @param array $known_languages
     * @return array
     */
    private function groups_from_rows($translation_rows, $language_rows, $attachment_ids, $known_languages)
    {
        $lookup = array();
        if (is_array($attachment_ids)) {
            foreach ($attachment_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $lookup[$id] = true;
                }
            }
        }

        $known = array();
        if (is_array($known_languages)) {
            foreach ($known_languages as $slug) {
                $slug = $this->language_slug($slug);
                if ($slug !== '') {
                    $known[$slug] = true;
                }
            }
        }

        $groups = array();
        $seen = array();
        $skipped = 0;

        if (is_array($translation_rows)) {
            foreach ($translation_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parsed = $this->map_from_row($row, $lookup, $known);
                $skipped += (int) $parsed['skipped'];
                $this->append_maps($groups, $seen, $parsed['maps']);
            }
        }

        if (is_array($language_rows)) {
            foreach ($language_rows as $language_row) {
                if (!is_array($language_row)) {
                    continue;
                }
                $id = isset($language_row['id']) ? (int) $language_row['id'] : 0;
                $lang = $this->preferred_language_slug(
                    isset($language_row['lang']) ? $language_row['lang'] : '',
                    $known
                );
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                if ($lookup !== array() && !isset($lookup[$id])) {
                    continue;
                }
                if ($lang === '') {
                    continue;
                }
                $seen[$id] = true;
                $groups[] = $this->order_map(array($lang => $id));
            }
        }

        return array(
            'groups' => $groups,
            'seen' => $seen,
            'skipped' => $skipped,
        );
    }

    /**
     * @param array $row
     * @param array $lookup
     * @param array $known
     * @return array
     */
    private function map_from_row($row, $lookup, $known)
    {
        $members = array();
        if (isset($row['members']) && is_array($row['members'])) {
            foreach ($row['members'] as $member_id => $member_lang) {
                $member_id = (int) $member_id;
                if ($member_id <= 0) {
                    continue;
                }
                $slug = $this->preferred_language_slug($member_lang, $known);
                if (!isset($members[$member_id]) || ($members[$member_id] === '' && $slug !== '')) {
                    $members[$member_id] = $slug;
                }
            }
        }

        $decoded = $this->decode_stored_array(isset($row['description']) ? $row['description'] : null);
        $map = array();
        foreach ($decoded as $lang => $raw_id) {
            $slug = $this->language_slug($lang);
            $attachment_id = (int) $raw_id;
            if ($slug === '' || $attachment_id <= 0 || isset($map[$slug])) {
                continue;
            }
            if (!isset($members[$attachment_id]) && !isset($lookup[$attachment_id])) {
                continue;
            }
            if (!$this->description_slug_is_usable($slug, $attachment_id, $members, $decoded, $known)) {
                continue;
            }
            $map[$slug] = $attachment_id;
        }

        $placed = array();
        foreach ($map as $attachment_id) {
            $placed[(int) $attachment_id] = true;
        }

        $overflow = array();
        $skipped = 0;
        foreach ($members as $member_id => $slug) {
            if (isset($placed[$member_id])) {
                continue;
            }
            if ($slug === '') {
                continue;
            }
            if (isset($map[$slug])) {
                $overflow[] = array($slug => $member_id);
            } else {
                $map[$slug] = $member_id;
            }
            $placed[$member_id] = true;
        }

        $maps = array();
        if ($map !== array()) {
            $maps[] = $map;
        }
        foreach ($overflow as $extra) {
            $maps[] = $extra;
        }

        return array(
            'maps' => $maps,
            'skipped' => $skipped,
        );
    }

    /**
     * Keep a description slug that is this attachment's language.
     *
     * A slug that is not in the site language list is still usable. A metadata
     * key is ignored when the attachment already has a different known language.
     *
     * @param string $slug
     * @param int $attachment_id
     * @param array $members
     * @param array $decoded
     * @param array $known
     * @return bool
     */
    private function description_slug_is_usable($slug, $attachment_id, $members, $decoded, $known)
    {
        if ($known === array() || isset($known[$slug])) {
            return true;
        }
        $member_lang = isset($members[$attachment_id]) ? $members[$attachment_id] : '';
        if ($member_lang !== '' && $member_lang !== $slug) {
            return false;
        }
        if ($member_lang === $slug) {
            return true;
        }
        foreach ($decoded as $lang => $raw_id) {
            if ((int) $raw_id !== (int) $attachment_id) {
                continue;
            }
            $other = $this->language_slug($lang);
            if ($other !== '' && $other !== $slug && isset($known[$other])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array $groups
     * @param array $seen
     * @param array $maps
     * @return void
     */
    private function append_maps(&$groups, &$seen, $maps)
    {
        if (!is_array($maps)) {
            return;
        }

        foreach ($maps as $map) {
            if (!is_array($map)) {
                continue;
            }
            $fresh = array();
            foreach ($map as $lang => $id) {
                $slug = $this->language_slug($lang);
                $id = (int) $id;
                if ($slug === '' || $id <= 0 || isset($seen[$id]) || isset($fresh[$slug])) {
                    continue;
                }
                $fresh[$slug] = $id;
            }
            if ($fresh === array()) {
                continue;
            }
            foreach ($fresh as $id) {
                $seen[$id] = true;
            }
            $groups[] = $this->order_map($fresh);
        }
    }

    /**
     * @param array $groups
     * @param array $bare_ids
     * @param string $default_lang
     * @param array $known_languages
     * @param array $records
     * @return array
     */
    private function assemble_items($groups, $bare_ids, $default_lang, $known_languages, $records)
    {
        $items = array();
        if (!is_array($groups)) {
            $groups = array();
        }
        if (!is_array($records)) {
            $records = array();
        }

        foreach ($groups as $map) {
            if (!is_array($map)) {
                continue;
            }
            $normalized = array();
            foreach ($map as $lang => $id) {
                $slug = $this->language_slug($lang);
                $id = (int) $id;
                if ($slug === '' || $id <= 0 || isset($normalized[$slug])) {
                    continue;
                }
                $normalized[$slug] = $id;
            }
            if ($normalized === array()) {
                continue;
            }
            $normalized = $this->order_map($normalized);
            $attachment_id = $this->canonical_attachment_id($normalized, $default_lang);
            $items[] = $this->finalize_item(
                $attachment_id,
                $this->file_from_records($normalized, $attachment_id, $records),
                $this->string_map($normalized, $records, 'alt'),
                $this->string_map($normalized, $records, 'title')
            );
        }

        $bare_lang = $this->unassigned_language($default_lang, $known_languages);
        if (is_array($bare_ids)) {
            foreach ($bare_ids as $bare_id) {
                $items[] = $this->item_for_unassigned($bare_id, $bare_lang, $records);
            }
        }

        return $this->sort_items($this->merge_items_that_share_a_file($items, $default_lang));
    }

    /**
     * Two attachment posts that store the same file are one media item.
     *
     * @param array $items
     * @param string $default_lang
     * @return array
     */
    private function merge_items_that_share_a_file($items, $default_lang)
    {
        $merged = array();
        $files = array();
        $buckets = array();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = isset($item['file']) ? (string) $item['file'] : '';
            $match = -1;
            if ($file !== '') {
                $bucket = $this->file_bucket($file);
                if (isset($buckets[$bucket])) {
                    foreach ($buckets[$bucket] as $index) {
                        if (isset($files[$index]) && $this->same_media_file($files[$index], $file)) {
                            $match = $index;
                            break;
                        }
                    }
                }
            }
            if ($match < 0) {
                $merged[] = $item;
                $index = count($merged) - 1;
                $files[$index] = $file;
                if ($file !== '') {
                    $buckets[$this->file_bucket($file)][] = $index;
                }
                continue;
            }

            $merged[$match] = $this->combine_shared_items($merged[$match], $item, $default_lang);
            $files[$match] = isset($merged[$match]['file']) ? (string) $merged[$match]['file'] : $file;
        }

        return $merged;
    }

    /**
     * @param array $left
     * @param array $right
     * @param string $default_lang
     * @return array
     */
    private function combine_shared_items($left, $right, $default_lang)
    {
        $lead_left = $this->item_should_lead($left, $right, $default_lang);
        $primary = $lead_left ? $left : $right;
        $secondary = $lead_left ? $right : $left;
        $primary_alt = (isset($primary['alt']) && is_array($primary['alt'])) ? $primary['alt'] : array();
        $secondary_alt = (isset($secondary['alt']) && is_array($secondary['alt'])) ? $secondary['alt'] : array();
        $primary_title = (isset($primary['title']) && is_array($primary['title'])) ? $primary['title'] : array();
        $secondary_title = (isset($secondary['title']) && is_array($secondary['title'])) ? $secondary['title'] : array();

        return $this->finalize_item(
            isset($primary['attachment_id']) ? $primary['attachment_id'] : 0,
            $this->preferred_shared_file(
                isset($primary['file']) ? $primary['file'] : '',
                isset($secondary['file']) ? $secondary['file'] : ''
            ),
            $this->merge_string_maps($primary_alt, $secondary_alt),
            $this->merge_string_maps($primary_title, $secondary_title)
        );
    }

    /**
     * @param array $left
     * @param array $right
     * @param string $default_lang
     * @return bool
     */
    private function item_should_lead($left, $right, $default_lang)
    {
        $left_default = $this->item_has_language($left, $default_lang);
        $right_default = $this->item_has_language($right, $default_lang);
        if ($left_default !== $right_default) {
            return $left_default;
        }

        $left_id = isset($left['attachment_id']) ? (int) $left['attachment_id'] : 0;
        $right_id = isset($right['attachment_id']) ? (int) $right['attachment_id'] : 0;
        return $left_id <= $right_id;
    }

    /**
     * @param array $item
     * @param string $default_lang
     * @return bool
     */
    private function item_has_language($item, $default_lang)
    {
        $maps = array();
        if (isset($item['alt']) && is_array($item['alt'])) {
            $maps[] = $item['alt'];
        }
        if (isset($item['title']) && is_array($item['title'])) {
            $maps[] = $item['title'];
        }
        foreach ($maps as $map) {
            foreach (array_keys($map) as $lang) {
                if ($this->same_language($lang, $default_lang)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array $primary
     * @param array $secondary
     * @return array
     */
    private function merge_string_maps($primary, $secondary)
    {
        $merged = array();
        $keys = array();
        foreach (array_keys($primary) as $key) {
            $keys[$key] = true;
        }
        foreach (array_keys($secondary) as $key) {
            $keys[$key] = true;
        }
        foreach (array_keys($keys) as $key) {
            $first = array_key_exists($key, $primary) ? $this->string_value($primary[$key]) : '';
            $second = array_key_exists($key, $secondary) ? $this->string_value($secondary[$key]) : '';
            $merged[$key] = ($first !== '') ? $first : $second;
        }
        return $merged;
    }

    /**
     * @param string $primary
     * @param string $secondary
     * @return string
     */
    private function preferred_shared_file($primary, $secondary)
    {
        $primary = $this->portable_file($primary);
        $secondary = $this->portable_file($secondary);
        if ($primary === '') {
            return $secondary;
        }
        if ($secondary === '') {
            return $primary;
        }
        if ($this->is_server_path($primary) && !$this->is_server_path($secondary) && $this->same_media_file($primary, $secondary)) {
            return $secondary;
        }
        return $primary;
    }

    /**
     * @param string $file
     * @return string
     */
    private function file_bucket($file)
    {
        $base = $this->file_basename($file);
        if ($base === '' || $base === '.' || $base === '..') {
            return $this->ascii_lower($file);
        }
        return $this->ascii_lower($base);
    }

    /**
     * Basename without using the process locale, so å, ä, and ö stay intact.
     *
     * @param string $file
     * @return string
     */
    private function file_basename($file)
    {
        $file = str_replace('\\', '/', $file);
        $pos = strrpos($file, '/');
        if ($pos === false) {
            return $file;
        }
        return substr($file, $pos + 1);
    }

    /**
     * Lowercase A-Z only, so å, ä, and ö stay intact.
     *
     * @param string $value
     * @return string
     */
    private function ascii_lower($value)
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * @param array $items
     * @return array
     */
    private function sort_items($items)
    {
        usort($items, function ($left, $right) {
            $left_id = isset($left['attachment_id']) ? (int) $left['attachment_id'] : 0;
            $right_id = isset($right['attachment_id']) ? (int) $right['attachment_id'] : 0;
            if ($left_id !== $right_id) {
                return ($left_id < $right_id) ? -1 : 1;
            }
            $left_file = isset($left['file']) ? (string) $left['file'] : '';
            $right_file = isset($right['file']) ? (string) $right['file'] : '';
            return strcmp($left_file, $right_file);
        });

        return $items;
    }

    /**
     * Attachments that are not in a Polylang group and have no language.
     *
     * Ids that could not be read are excluded so a failure is not reported twice.
     *
     * @param array $attachment_ids
     * @param array $seen
     * @param array $excluded_ids
     * @return array
     */
    private function unassigned_ids($attachment_ids, $seen, $excluded_ids)
    {
        $used = array();
        if (is_array($seen)) {
            foreach (array_keys($seen) as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $used[$id] = true;
                }
            }
        }
        if (is_array($excluded_ids)) {
            foreach ($excluded_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $used[$id] = true;
                }
            }
        }

        $bare = array();
        if (!is_array($attachment_ids)) {
            return $bare;
        }
        foreach ($attachment_ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($used[$id])) {
                continue;
            }
            $used[$id] = true;
            $bare[] = $id;
        }
        sort($bare, SORT_NUMERIC);
        return $bare;
    }

    /**
     * Language used for text on an attachment Polylang never translated.
     *
     * The stored title and alt text are kept under the default language.
     * A site with one language uses that language when no default is set.
     *
     * @param string $default_lang
     * @param array $known_languages
     * @return string
     */
    private function unassigned_language($default_lang, $known_languages)
    {
        $lang = $this->language_slug($default_lang);
        if ($lang !== '') {
            return $lang;
        }

        $known = $this->clean_slugs(is_array($known_languages) ? $known_languages : array());
        if (count($known) === 1) {
            return $known[0];
        }

        return '';
    }

    /**
     * One library file that has no Polylang translation group.
     *
     * @param int $id
     * @param string $lang
     * @param array $records
     * @return array
     */
    private function item_for_unassigned($id, $lang, $records)
    {
        $id = (int) $id;
        $alt = array();
        $title = array();
        $lang = $this->language_slug($lang);
        if ($lang !== '') {
            $alt[$lang] = $this->record_string($records, $id, 'alt');
            $title[$lang] = $this->record_string($records, $id, 'title');
        }

        return $this->finalize_item(
            $id,
            $this->record_string($records, $id, 'file'),
            $alt,
            $title
        );
    }

    /**
     * Every exported row has the same four fields and string maps.
     *
     * Alt and title always use the same language keys, even when one of the
     * stored values is missing.
     *
     * @param int $attachment_id
     * @param string $file
     * @param array $alt
     * @param array $title
     * @return array
     */
    private function finalize_item($attachment_id, $file, $alt, $title)
    {
        $slugs = array();
        if (is_array($alt)) {
            foreach (array_keys($alt) as $lang) {
                $slug = $this->language_slug($lang);
                if ($slug !== '') {
                    $slugs[$slug] = $slug;
                }
            }
        }
        if (is_array($title)) {
            foreach (array_keys($title) as $lang) {
                $slug = $this->language_slug($lang);
                if ($slug !== '') {
                    $slugs[$slug] = $slug;
                }
            }
        }
        $slugs = $this->order_map($slugs);

        $clean_alt = array();
        $clean_title = array();
        foreach ($slugs as $slug) {
            $clean_alt[$slug] = $this->text_for_slug($alt, $slug);
            $clean_title[$slug] = $this->text_for_slug($title, $slug);
        }

        $attachment_id = (int) $attachment_id;
        if ($attachment_id < 0) {
            $attachment_id = 0;
        }

        return array(
            'attachment_id' => $attachment_id,
            'file' => $this->portable_file(is_string($file) ? $file : $this->string_value($file)),
            'alt' => $clean_alt,
            'title' => $clean_title,
        );
    }

    /**
     * Read text for a lowercase slug, even if the source key differed in case.
     *
     * @param array $map
     * @param string $slug
     * @return string
     */
    private function text_for_slug($map, $slug)
    {
        if (!is_array($map)) {
            return '';
        }
        $fallback = '';
        foreach ($map as $key => $value) {
            if ($this->language_slug($key) !== $slug) {
                continue;
            }
            $text = $this->string_value($value);
            if ($text === '') {
                continue;
            }
            if ((string) $key === $slug) {
                return $text;
            }
            if ($fallback === '') {
                $fallback = $text;
            }
        }
        return $fallback;
    }

    /**
     * The canonical attachment is the default language copy, otherwise the lowest ID.
     *
     * @param array $map
     * @param string $default_lang
     * @return int
     */
    private function canonical_attachment_id($map, $default_lang)
    {
        foreach ($map as $lang => $id) {
            if ($this->same_language($lang, $default_lang)) {
                return (int) $id;
            }
        }

        $ids = array_map('intval', array_values($map));
        sort($ids, SORT_NUMERIC);
        return (int) $ids[0];
    }

    /**
     * @param array $map
     * @param int $canonical_id
     * @param array $records
     * @return string
     */
    private function file_from_records($map, $canonical_id, $records)
    {
        $canonical_id = (int) $canonical_id;
        $canonical = $this->portable_file($this->record_string($records, $canonical_id, 'file'));
        $others = array();
        $ids = array_map('intval', array_values($map));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if ($id <= 0 || $id === $canonical_id) {
                continue;
            }
            $file = $this->portable_file($this->record_string($records, $id, 'file'));
            if ($file !== '') {
                $others[] = $file;
            }
        }

        if ($canonical !== '' && !$this->is_server_path($canonical)) {
            return $canonical;
        }

        if ($canonical !== '' && $this->is_server_path($canonical)) {
            foreach ($others as $file) {
                if (!$this->is_server_path($file) && $this->same_media_file($canonical, $file)) {
                    return $file;
                }
            }
            return $canonical;
        }

        foreach ($others as $file) {
            if (!$this->is_server_path($file)) {
                return $file;
            }
        }

        return $others === array() ? '' : $others[0];
    }

    /**
     * A filesystem path that would not survive a move to another server.
     *
     * @param string $file
     * @return bool
     */
    private function is_server_path($file)
    {
        if (!is_string($file) || $file === '') {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $file) === 1) {
            return false;
        }
        if (isset($file[1]) && $file[0] === '/' && $file[1] === '/') {
            return false;
        }
        if ($file[0] === '/') {
            return true;
        }
        return preg_match('#^[A-Za-z]:/#', $file) === 1;
    }

    /**
     * True when two stored values point at the same uploads file.
     *
     * @param string $left
     * @param string $right
     * @return bool
     */
    private function same_media_file($left, $right)
    {
        $left = str_replace('\\', '/', $this->file_text($left));
        $right = str_replace('\\', '/', $this->file_text($right));
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        $left_server = $this->is_server_path($left);
        $right_server = $this->is_server_path($right);
        if ($left_server === $right_server) {
            return false;
        }
        if ($this->file_basename($left) === '' || $this->file_basename($left) !== $this->file_basename($right)) {
            return false;
        }
        if ($left_server) {
            return $this->path_ends_with($left, $right);
        }
        return $this->path_ends_with($right, $left);
    }

    /**
     * @param string $path
     * @param string $suffix
     * @return bool
     */
    private function path_ends_with($path, $suffix)
    {
        $path = rtrim($path, '/');
        $suffix = rtrim($suffix, '/');
        if ($suffix === '' || strlen($suffix) > strlen($path)) {
            return false;
        }
        if (substr($path, -strlen($suffix)) !== $suffix) {
            return false;
        }
        $prefix_length = strlen($path) - strlen($suffix);
        return $prefix_length === 0 || substr($path, $prefix_length - 1, 1) === '/';
    }

    /**
     * A path that contains only spaces, tabs, or other whitespace is missing.
     *
     * Surrounding whitespace is removed from a real path. Spaces inside a
     * file name are kept.
     *
     * @param string $file
     * @return string
     */
    private function blank_if_only_whitespace($file)
    {
        if (!is_string($file) || $file === '') {
            return '';
        }
        $trimmed = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $file);
        if ($trimmed === null) {
            $trimmed = trim($file);
        }
        if (!is_string($trimmed) || $trimmed === '') {
            return '';
        }
        return $trimmed;
    }

    /**
     * Store a path Tolka can use on another server.
     *
     * Absolute uploads paths become the path relative to the uploads directory.
     * URLs and paths that are already relative stay as they are.
     *
     * @param string $file
     * @param string|null $uploads_basedir
     * @return string
     */
    private function portable_file($file, $uploads_basedir = null)
    {
        $file = $this->blank_if_only_whitespace(str_replace('\\', '/', $this->file_text($file)));
        if ($file === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $file) === 1) {
            return $file;
        }

        $base = $uploads_basedir;
        if ($base === null && function_exists('wp_get_upload_dir')) {
            try {
                $uploads = wp_get_upload_dir();
            } catch (\Throwable $e) {
                $uploads = array();
            }
            if (is_array($uploads) && isset($uploads['basedir'])) {
                $base = $uploads['basedir'];
            }
        }
        if (!is_string($base) || $base === '') {
            return $file;
        }

        $base = rtrim(str_replace('\\', '/', $base), '/');
        $prefix = $base . '/';
        if ($base !== '' && strncmp($file, $prefix, strlen($prefix)) === 0) {
            return substr($file, strlen($prefix));
        }

        return $file;
    }

    /**
     * @param array $map
     * @param array $records
     * @param string $field
     * @return array
     */
    private function string_map($map, $records, $field)
    {
        $out = array();
        foreach ($map as $lang => $id) {
            $out[$lang] = $this->record_string($records, $id, $field);
        }
        return $out;
    }

    /**
     * @param array $records
     * @param int $id
     * @param string $field
     * @return string
     */
    private function record_string($records, $id, $field)
    {
        $id = (int) $id;
        if (!isset($records[$id]) || !is_array($records[$id]) || !array_key_exists($field, $records[$id])) {
            return '';
        }
        if ($field === 'file') {
            return $this->file_text($records[$id][$field]);
        }
        return $this->string_value($records[$id][$field]);
    }

    /**
     * @param array $map
     * @return array
     */
    private function order_map($map)
    {
        $ordered = array();
        foreach ($this->language_order() as $slug) {
            if (isset($map[$slug])) {
                $ordered[$slug] = $map[$slug];
            }
        }

        $rest = array();
        foreach ($map as $slug => $id) {
            if (!isset($ordered[$slug])) {
                $rest[$slug] = $id;
            }
        }
        if ($rest !== array()) {
            ksort($rest, SORT_STRING);
            foreach ($rest as $slug => $id) {
                $ordered[$slug] = $id;
            }
        }

        return $ordered;
    }

    /**
     * @return array
     */
    private function language_order()
    {
        if (is_array($this->language_order)) {
            return $this->language_order;
        }

        $order = array();
        if (function_exists('pll_languages_list')) {
            try {
                $list = pll_languages_list(array('fields' => 'slug'));
            } catch (\Throwable $e) {
                $list = array();
            }
            if (is_array($list)) {
                foreach ($list as $slug) {
                    $slug = $this->language_slug($slug);
                    if ($slug !== '' && !in_array($slug, $order, true)) {
                        $order[] = $slug;
                    }
                }
            }
        }

        $this->language_order = $order;
        return $order;
    }

    /**
     * @return array
     */
    private function known_language_slugs()
    {
        if (function_exists('pll_languages_list')) {
            try {
                $list = pll_languages_list(array('fields' => 'slug'));
            } catch (\Throwable $e) {
                $list = null;
            }
            if (is_array($list)) {
                return $this->clean_slugs($list);
            }
        }

        $from_db = $this->language_slugs_from_db();
        if (is_array($from_db)) {
            return $from_db;
        }

        if (function_exists('get_terms') && function_exists('taxonomy_exists') && taxonomy_exists('language')) {
            $terms = get_terms(array(
                'taxonomy' => 'language',
                'hide_empty' => false,
                'fields' => 'slugs',
            ));
            if (is_array($terms)) {
                return $this->clean_slugs($terms);
            }
        }

        return array();
    }

    /**
     * @return array|null
     */
    private function language_slugs_from_db()
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->terms) || !isset($wpdb->term_taxonomy)) {
            return null;
        }

        $terms = $this->table_name($wpdb->terms);
        $term_taxonomy = $this->table_name($wpdb->term_taxonomy);
        if ($terms === '' || $term_taxonomy === '') {
            return null;
        }

        $rows = $this->query_rows(
            $wpdb,
            "SELECT t.slug AS language_slug
            FROM {$terms} t
            INNER JOIN {$term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = %s",
            array('language')
        );
        if (!is_array($rows)) {
            return null;
        }

        $slugs = array();
        foreach ($rows as $row) {
            $slugs[] = $this->row_value($row, 'language_slug');
        }
        return $this->clean_slugs($slugs);
    }

    /**
     * @param array $slugs
     * @return array
     */
    private function clean_slugs($slugs)
    {
        $clean = array();
        if (!is_array($slugs)) {
            return $clean;
        }
        foreach ($slugs as $slug) {
            $slug = $this->language_slug($slug);
            if ($slug !== '' && !in_array($slug, $clean, true)) {
                $clean[] = $slug;
            }
        }
        return $clean;
    }

    /**
     * @return string
     */
    private function default_language()
    {
        if (function_exists('pll_default_language')) {
            try {
                $slug = pll_default_language('slug');
            } catch (\Throwable $e) {
                $slug = '';
            }
            $slug = $this->language_slug($slug);
            if ($slug !== '') {
                return $slug;
            }
        }

        if (function_exists('get_option')) {
            $options = get_option('polylang');
            if (is_array($options) && isset($options['default_lang'])) {
                return $this->language_slug($options['default_lang']);
            }
        }

        return $this->default_language_from_db();
    }

    /**
     * @return string
     */
    private function default_language_from_db()
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->options)) {
            return '';
        }

        $table = $this->table_name($wpdb->options);
        if ($table === '' || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return '';
        }

        $sql = $wpdb->prepare(
            "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
            'polylang'
        );
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        $raw = $wpdb->get_var($sql);
        $options = $this->decode_stored_array($raw);
        if (isset($options['default_lang'])) {
            return $this->language_slug($options['default_lang']);
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function language_slug($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $slug = strtolower(trim($value));
        if ($slug === '' || strlen($slug) > 64) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $slug) !== 1) {
            return '';
        }
        if (ctype_digit($slug)) {
            return '';
        }
        return $slug;
    }

    /**
     * Use the site's slug when it differs only by case.
     *
     * A valid slug that is not one of the known languages is kept. Alt text for
     * that slug stays on the lowercase slug.
     *
     * @param mixed $value
     * @param array $known
     * @return string
     */
    private function preferred_language_slug($value, $known)
    {
        $slug = $this->language_slug($value);
        if ($slug === '') {
            return '';
        }
        $matched = $this->match_known_slug($slug, $known);
        if ($matched !== '') {
            return $matched;
        }
        return $slug;
    }

    /**
     * @param string $slug
     * @param array $known
     * @return string
     */
    private function match_known_slug($slug, $known)
    {
        $slug = $this->language_slug($slug);
        if ($slug === '' || !is_array($known) || $known === array()) {
            return '';
        }
        if (isset($known[$slug])) {
            return $slug;
        }
        $lower = strtolower($slug);
        foreach (array_keys($known) as $candidate) {
            if (is_string($candidate) && strtolower($candidate) === $lower) {
                return $this->language_slug($candidate);
            }
        }
        return '';
    }

    /**
     * @param string $left
     * @param string $right
     * @return bool
     */
    private function same_language($left, $right)
    {
        $left = $this->language_slug($left);
        $right = $this->language_slug($right);
        if ($left === '' || $right === '') {
            return false;
        }
        return strtolower($left) === strtolower($right);
    }

    /**
     * @param mixed $raw
     * @return array
     */
    private function decode_stored_array($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return array();
        }

        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 1000000) {
            return array();
        }

        if (function_exists('maybe_unserialize')) {
            $data = maybe_unserialize($raw);
            if (is_array($data)) {
                return $data;
            }
        }

        $data = $this->safe_unserialize($raw);
        if (is_array($data)) {
            return $data;
        }

        $stripped = stripslashes($raw);
        if ($stripped === $raw) {
            return array();
        }

        if (function_exists('maybe_unserialize')) {
            $data = maybe_unserialize($stripped);
            if (is_array($data)) {
                return $data;
            }
        }

        $data = $this->safe_unserialize($stripped);
        return is_array($data) ? $data : array();
    }

    /**
     * @param string $raw
     * @return mixed
     */
    private function safe_unserialize($raw)
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 1000000) {
            return null;
        }
        if ($raw !== 'N;' && preg_match('/^(a|s|i|b|d|O|C):/', $raw) !== 1) {
            return null;
        }

        set_error_handler(function () {
            return true;
        });
        try {
            $data = unserialize($raw, array('allowed_classes' => false));
        } catch (\Throwable $e) {
            $data = false;
        } finally {
            restore_error_handler();
        }

        if ($data === false && $raw !== 'b:0;') {
            return null;
        }

        return $data;
    }

    /**
     * @return array|null
     */
    private function attachment_ids()
    {
        if (function_exists('get_posts')) {
            $ids = $this->attachment_ids_from_posts();
            if (is_array($ids)) {
                return $ids;
            }
        }

        return $this->attachment_ids_from_db();
    }

    /**
     * @return array|null
     */
    private function attachment_ids_from_posts()
    {
        $ids = array();
        $seen = array();
        $page = 1;

        do {
            $batch = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => $this->exportable_statuses(),
                'posts_per_page' => 200,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'suppress_filters' => true,
                'lang' => '',
                'no_found_rows' => true,
                'cache_results' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'ignore_sticky_posts' => true,
            ));

            if (function_exists('is_wp_error') && is_wp_error($batch)) {
                return null;
            }
            if (!is_array($batch)) {
                return null;
            }
            if ($batch === array()) {
                break;
            }

            $added = 0;
            foreach ($batch as $id) {
                $id = (int) $id;
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $ids[] = $id;
                $added++;
            }

            if ($added === 0 || count($batch) < 200) {
                break;
            }

            $page++;
        } while ($page <= 5000);

        return $ids;
    }

    /**
     * @return array|null
     */
    private function attachment_ids_from_db()
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->posts)) {
            return null;
        }

        $posts = $this->table_name($wpdb->posts);
        if ($posts === '') {
            return null;
        }

        $rows = $this->query_rows(
            $wpdb,
            "SELECT ID FROM {$posts}
            WHERE post_type = 'attachment'
                AND post_status IN (" . $this->status_sql() . ")
            ORDER BY ID ASC",
            array()
        );
        if (!is_array($rows)) {
            return null;
        }

        $ids = array();
        foreach ($rows as $row) {
            $id = (int) $this->row_value($row, 'ID');
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param int $id
     * @return bool
     */
    private function is_exportable_attachment($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        if (function_exists('get_post_type') && function_exists('get_post_status')) {
            if (get_post_type($id) !== 'attachment') {
                return false;
            }
            $status = get_post_status($id);
            return is_string($status) && in_array($status, $this->exportable_statuses(), true);
        }

        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->posts)) {
            return false;
        }
        $posts = $this->table_name($wpdb->posts);
        if ($posts === '' || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_row')) {
            return false;
        }

        $sql = $wpdb->prepare(
            "SELECT post_type, post_status FROM {$posts} WHERE ID = %d",
            $id
        );
        if (!is_string($sql) || $sql === '') {
            return false;
        }

        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $row = $wpdb->get_row($sql, $output);
        if (!is_array($row) && !is_object($row)) {
            return false;
        }

        return $this->row_value($row, 'post_type') === 'attachment'
            && in_array((string) $this->row_value($row, 'post_status'), $this->exportable_statuses(), true);
    }

    /**
     * @return array
     */
    private function exportable_statuses()
    {
        return array('inherit', 'private', 'publish', 'draft', 'pending', 'future');
    }

    /**
     * @return string
     */
    private function status_sql()
    {
        return "'inherit', 'private', 'publish', 'draft', 'pending', 'future'";
    }

    /**
     * @param array $ids
     * @return array
     */
    private function records_for($ids)
    {
        $records = array();
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0 || isset($records[$id])) {
                    continue;
                }
                $records[$id] = array(
                    'file' => '',
                    'alt' => '',
                    'title' => '',
                );
            }
        }
        if ($records === array()) {
            return $records;
        }

        $record_ids = array_keys($records);
        if (function_exists('get_post_meta') || function_exists('get_post')) {
            $this->prime_attachment_cache($record_ids);
            foreach ($record_ids as $id) {
                $records[$id] = array(
                    'file' => $this->meta_string($id, '_wp_attached_file'),
                    'alt' => $this->meta_string($id, '_wp_attachment_image_alt'),
                    'title' => $this->attachment_title($id),
                );
            }
            return $records;
        }

        $this->fill_records_from_database($records);
        return $records;
    }

    /**
     * Prime post and meta caches in chunks so a large library does not query once per file.
     *
     * @param array $ids
     * @return void
     */
    private function prime_attachment_cache($ids)
    {
        try {
            if (function_exists('update_meta_cache')) {
                foreach (array_chunk($ids, 200) as $chunk) {
                    update_meta_cache('post', $chunk);
                }
            }
            if (function_exists('_prime_post_caches')) {
                foreach (array_chunk($ids, 200) as $chunk) {
                    _prime_post_caches($chunk, false, false);
                }
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @param array $records
     * @return void
     */
    private function fill_records_from_database(&$records)
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || $records === array()) {
            return;
        }

        $ids = array_keys($records);
        $posts = isset($wpdb->posts) ? $this->table_name($wpdb->posts) : '';
        $postmeta = isset($wpdb->postmeta) ? $this->table_name($wpdb->postmeta) : '';

        if ($posts !== '') {
            foreach (array_chunk($ids, 200) as $chunk) {
                $rows = $this->query_rows(
                    $wpdb,
                    "SELECT ID, post_title FROM {$posts} WHERE ID IN (" . $this->int_placeholders(count($chunk)) . ')',
                    $chunk
                );
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    $id = (int) $this->row_value($row, 'ID');
                    if (!isset($records[$id])) {
                        continue;
                    }
                    $records[$id]['title'] = $this->string_value($this->row_value($row, 'post_title'));
                }
            }
        }

        if ($postmeta === '') {
            return;
        }

        foreach (array_chunk($ids, 200) as $chunk) {
            $args = array_merge(
                array('_wp_attached_file', '_wp_attachment_image_alt'),
                $chunk
            );
            $rows = $this->query_rows(
                $wpdb,
                "SELECT post_id, meta_key, meta_value FROM {$postmeta}
                WHERE meta_key IN (%s, %s)
                    AND post_id IN (" . $this->int_placeholders(count($chunk)) . ')',
                $args
            );
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) $this->row_value($row, 'post_id');
                if (!isset($records[$id])) {
                    continue;
                }
                $key = $this->row_value($row, 'meta_key');
                $field = '';
                if ($key === '_wp_attached_file') {
                    $field = 'file';
                } elseif ($key === '_wp_attachment_image_alt') {
                    $field = 'alt';
                }
                if ($field === '' || $records[$id][$field] !== '') {
                    continue;
                }
                $stored = $this->unserialize_meta($this->row_value($row, 'meta_value'));
                $records[$id][$field] = ($field === 'file') ? $this->file_text($stored) : $this->string_value($stored);
            }
        }
    }

    /**
     * @param int $count
     * @return string
     */
    private function int_placeholders($count)
    {
        $count = (int) $count;
        if ($count < 1) {
            return '0';
        }
        return implode(', ', array_fill(0, $count, '%d'));
    }

    /**
     * @param int $id
     * @param string $key
     * @return string
     */
    private function meta_string($id, $key)
    {
        if (function_exists('get_post_meta')) {
            $stored = get_post_meta($id, $key, true);
        } else {
            $stored = $this->unserialize_meta($this->meta_from_db($id, $key));
        }
        if ($key === '_wp_attached_file') {
            return $this->file_text($stored);
        }

        return $this->string_value($stored);
    }

    /**
     * @param int $id
     * @param string $key
     * @return mixed
     */
    private function meta_from_db($id, $key)
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->postmeta) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return '';
        }

        $table = $this->table_name($wpdb->postmeta);
        if ($table === '') {
            return '';
        }

        $sql = $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            (int) $id,
            $key
        );
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        return $wpdb->get_var($sql);
    }

    /**
     * @param int $id
     * @return string
     */
    private function attachment_title($id)
    {
        if (function_exists('get_post')) {
            $post = get_post($id);
            if (is_object($post) && isset($post->post_title)) {
                return $this->string_value($post->post_title);
            }
        }

        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->posts) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return '';
        }

        $table = $this->table_name($wpdb->posts);
        if ($table === '') {
            return '';
        }

        $sql = $wpdb->prepare(
            "SELECT post_title FROM {$table} WHERE ID = %d",
            (int) $id
        );
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        return $this->string_value($wpdb->get_var($sql));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function unserialize_meta($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^(O|C):\d+:/', $value) === 1) {
            return '';
        }
        if (function_exists('maybe_unserialize')) {
            return maybe_unserialize($value);
        }
        $decoded = $this->safe_unserialize($value);
        if (is_string($decoded) || is_numeric($decoded) || is_array($decoded)) {
            return $decoded;
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function string_value($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_bool($value) || $value === null || is_object($value)) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }
        return $this->utf8_string($value);
    }

    /**
     * A file name stored as Latin-1 comes back as UTF-8.
     *
     * Bytes that already form UTF-8 stay as they are. A lone Latin-1 byte,
     * including å, ä, ö, é, and ü, is encoded as UTF-8. A null byte is removed.
     *
     * @param mixed $value
     * @return string
     */
    private function file_text($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_bool($value) || $value === null || is_object($value)) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }
        $value = str_replace("\0", '', $value);
        if ($value === '') {
            return '';
        }
        if ($this->is_utf8($value)) {
            return $value;
        }
        $value = $this->promote_latin1_bytes($value);
        if ($this->is_utf8($value)) {
            return $value;
        }
        return str_replace("\0", '', $this->drop_invalid_utf8($value));
    }

    /**
     * Turn bytes that are not already UTF-8 into the UTF-8 form of Latin-1.
     *
     * @param string $value
     * @return string
     */
    private function promote_latin1_bytes($value)
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            $width = $this->utf8_sequence_width($byte);
            if ($width > 1 && ($i + $width) <= $length) {
                $sequence = substr($value, $i, $width);
                if ($this->is_utf8_sequence($sequence)) {
                    $out .= $sequence;
                    $i += $width - 1;
                    continue;
                }
            }
            if ($byte >= 0x80) {
                $out .= chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3F));
                continue;
            }
            $out .= $value[$i];
        }
        return $out;
    }

    /**
     * Keep text that can be stored in the Tolka package.
     *
     * Markup and letters such as å, ä, and ö stay in place. A Swedish letter
     * stored as a single Latin-1 byte is turned into UTF-8. Null bytes and
     * other invalid UTF-8 are removed.
     *
     * @param string $value
     * @return string
     */
    private function utf8_string($value)
    {
        $value = str_replace("\0", '', $value);
        if ($value === '') {
            return '';
        }
        if ($this->is_utf8($value)) {
            return $value;
        }
        $value = $this->promote_swedish_letters($value);
        if ($this->is_utf8($value)) {
            return $value;
        }
        return str_replace("\0", '', $this->drop_invalid_utf8($value));
    }

    /**
     * @param string $value
     * @return bool
     */
    private function is_utf8($value)
    {
        return is_string($value) && $value !== '' && preg_match('//u', $value) === 1;
    }

    /**
     * @param string $value
     * @return string
     */
    private function drop_invalid_utf8($value)
    {
        if (function_exists('wp_check_invalid_utf8')) {
            $clean = wp_check_invalid_utf8($value, true);
            if (is_string($clean)) {
                return $clean;
            }
        }
        if (function_exists('iconv')) {
            $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($clean)) {
                return $clean;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($clean)) {
                return $clean;
            }
        }
        return $value;
    }

    /**
     * Turn lone Latin-1 å, ä, and ö bytes into UTF-8.
     *
     * Bytes that already belong to a UTF-8 character are left alone.
     *
     * @param string $value
     * @return string
     */
    private function promote_swedish_letters($value)
    {
        $swedish = array(
            0xC4 => "\xC3\x84",
            0xC5 => "\xC3\x85",
            0xD6 => "\xC3\x96",
            0xE4 => "\xC3\xA4",
            0xE5 => "\xC3\xA5",
            0xF6 => "\xC3\xB6",
        );
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            $width = $this->utf8_sequence_width($byte);
            if ($width > 1 && ($i + $width) <= $length) {
                $sequence = substr($value, $i, $width);
                if ($this->is_utf8_sequence($sequence)) {
                    $out .= $sequence;
                    $i += $width - 1;
                    continue;
                }
            }
            if (isset($swedish[$byte])) {
                $out .= $swedish[$byte];
                continue;
            }
            $out .= $value[$i];
        }
        return $out;
    }

    /**
     * @param int $byte
     * @return int
     */
    private function utf8_sequence_width($byte)
    {
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            return 4;
        }
        return 0;
    }

    /**
     * @param string $sequence
     * @return bool
     */
    private function is_utf8_sequence($sequence)
    {
        $length = strlen($sequence);
        if ($length < 2) {
            return false;
        }
        for ($i = 1; $i < $length; $i++) {
            if ((ord($sequence[$i]) & 0xC0) !== 0x80) {
                return false;
            }
        }
        return $this->is_utf8($sequence);
    }

    /**
     * @param array $groups
     * @return array
     */
    private function ids_in_groups($groups)
    {
        $ids = array();
        if (!is_array($groups)) {
            return $ids;
        }
        foreach ($groups as $map) {
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * @param array $ids
     * @return string
     */
    private function id_key($ids)
    {
        $clean = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        $list = array_keys($clean);
        sort($list, SORT_NUMERIC);
        return implode(',', $list);
    }

    /**
     * @param array $map
     * @param int $id
     * @return bool
     */
    private function map_has_id($map, $id)
    {
        $id = (int) $id;
        foreach ($map as $member_id) {
            if ((int) $member_id === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return object|null
     */
    private function wpdb()
    {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return null;
        }
        return $GLOBALS['wpdb'];
    }

    /**
     * @param string $name
     * @return string
     */
    private function table_name($name)
    {
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return '';
        }
        return $name;
    }

    /**
     * @param object $wpdb
     * @param string $sql
     * @param array $args
     * @return array|null
     */
    private function query_rows($wpdb, $sql, $args)
    {
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return null;
        }

        if (is_array($args) && $args !== array()) {
            if (!method_exists($wpdb, 'prepare')) {
                return null;
            }
            $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $args));
            if (!is_string($prepared) || $prepared === '') {
                return null;
            }
            $sql = $prepared;
        }

        $before = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $wpdb->get_results($sql, $output);
        $after = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        if (!is_array($rows) || ($after !== '' && $after !== $before)) {
            return null;
        }

        return $rows;
    }

    /**
     * @param mixed $row
     * @param string $key
     * @return mixed
     */
    private function row_value($row, $key)
    {
        if (is_array($row) && array_key_exists($key, $row)) {
            return $row[$key];
        }
        if (is_object($row) && isset($row->$key)) {
            return $row->$key;
        }
        return null;
    }

    /**
     * @param string $message
     * @return string
     */
    private function text($message)
    {
        if (function_exists('__')) {
            return __($message, 'migration-manager');
        }
        return $message;
    }
}
