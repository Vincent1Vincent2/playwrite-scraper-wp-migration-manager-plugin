<?php

/**
 * Read Polylang post translation groups for Migrate to Tolka.
 *
 * Polylang data is only read. Nothing is deleted or rewritten.
 *
 * Each item is one translation group:
 *   group        "post:{id}" using the default-language post when that
 *                translation exists, otherwise the lowest post ID
 *   type         post type of that post
 *   translations language slug => id, title, slug, status, excerpt, content
 *
 * Drafts, pending posts, private posts, and scheduled posts are included.
 * A trashed translation stays in its group with status trash.
 * A post that has a language but no other translation is its own group.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

class Polylang_Posts
{
    const TAX_TRANSLATIONS = 'post_translations';
    const TAX_LANGUAGE = 'language';
    const OPTION_KEY = 'polylang';

    /**
     * Post records warmed for the current export.
     *
     * @var array<int, array|null>
     */
    protected $record_cache = array();

    /**
     * Post types looked up without loading post content.
     *
     * @var array<int, string>
     */
    protected $type_cache = array();

    /**
     * Locale to language slug, cached for the current export.
     *
     * @var array<string, string>|null
     */
    protected $locale_map = null;

    /**
     * Posts dropped because two rows resolved to the same language.
     *
     * @var array<int, array{kept: int, dropped: int, lang: string}>
     */
    protected $collapsed_languages = array();

    /**
     * Revision id => parent post id for this export.
     *
     * @var array<int, int>
     */
    protected $parent_cache = array();

    /**
     * Export post translation groups.
     *
     * Call this on an instance. Each call builds a new list, so a second
     * call does not append the groups from the first.
     *
     * @return array{items: array<int, array>, errors: string[]}
     */
    public function export()
    {
        $this->record_cache = array();
        $this->type_cache = array();
        $this->locale_map = null;
        $this->collapsed_languages = array();
        $this->parent_cache = array();

        try {
            if (!$this->polylang_present()) {
                return $this->envelope(array(), array(
                    $this->say('Polylang is not active. Post translations were not exported.'),
                ));
            }

            $errors = array();
            $maps = $this->collect_maps($errors);
            if ($maps === null) {
                return $this->envelope(array(), array(
                    $this->say('Post translations could not be read. Polylang\'s translation data is missing or the database query failed.'),
                ));
            }

            $items = $this->items_from_maps($maps, $this->default_language(), $errors);
            return $this->envelope($items, $errors);
        } catch (\Throwable $e) {
            $this->log_failure($e);
            return $this->envelope(array(), array(
                $this->say('Post translations could not be read. Polylang\'s translation data is missing or the database query failed.'),
            ));
        }
    }

    /**
     * @param array<int, array> $items
     * @param array<int, mixed> $errors
     * @return array{items: array<int, array>, errors: string[]}
     */
    private function envelope(array $items, array $errors)
    {
        $clean = array();
        foreach ($errors as $error) {
            if (is_string($error) && $error !== '') {
                $clean[] = $error;
            }
        }
        $clean = $this->limit_errors($clean);

        return array(
            'items' => array_values($items),
            'errors' => array_values($clean),
        );
    }

    /**
     * Keep the preview readable when a large catalog has many broken rows.
     *
     * @param string[] $errors
     * @return string[]
     */
    private function limit_errors(array $errors)
    {
        $limit = 20;
        if (count($errors) <= $limit) {
            return $errors;
        }
        $hidden = count($errors) - $limit;
        $errors = array_slice($errors, 0, $limit);
        if ($hidden === 1) {
            $errors[] = $this->say('1 more issue was found and is not listed here.');
        } else {
            $errors[] = $this->say_sprintf(
                '%d more issues were found and are not listed here.',
                array($hidden)
            );
        }
        return $errors;
    }

    /**
     * Polylang is present when its API, classes, options, or translation rows exist.
     * Stored rows still count after the plugin has been deactivated.
     */
    protected function polylang_present()
    {
        if (function_exists('pll_languages_list') || function_exists('pll_get_post_translations')) {
            return true;
        }
        if (class_exists('PLL') || class_exists('Polylang')) {
            return true;
        }
        $option = $this->polylang_option();
        if (is_array($option) && !empty($option)) {
            return true;
        }
        if ($this->taxonomy_present(self::TAX_LANGUAGE) || $this->taxonomy_present(self::TAX_TRANSLATIONS)) {
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    protected function default_language()
    {
        if (function_exists('pll_default_language')) {
            try {
                $slug = pll_default_language();
            } catch (\Throwable $e) {
                $slug = '';
            }
            if (is_string($slug) && trim($slug) !== '') {
                return $this->slug_for_language_key($slug);
            }
        }

        $option = $this->polylang_option();
        $slug = '';
        if (is_array($option) && isset($option['default_lang']) && is_string($option['default_lang'])) {
            $slug = trim($option['default_lang']);
        }
        return $this->slug_for_language_key($slug);
    }

    /**
     * @param string $slug
     * @return string
     */
    private function slug_for_language_key($slug)
    {
        $slug = $this->lowercase_slug(is_string($slug) ? $slug : '');
        if ($slug === '') {
            return '';
        }
        $locales = $this->locale_to_slug();
        if (isset($locales[$slug])) {
            return $locales[$slug];
        }
        $underscore = str_replace('-', '_', $slug);
        if ($underscore !== $slug && isset($locales[$underscore])) {
            return $locales[$underscore];
        }
        return $slug;
    }

    /**
     * Language slugs are compared and exported in lowercase, so SV and sv are one key.
     *
     * @param mixed $slug
     * @return string
     */
    private function lowercase_slug($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($slug, 'UTF-8');
        }
        return strtolower($slug);
    }

    /**
     * Use the public API only when calling it cannot take the exporter down.
     */
    protected function api_ready()
    {
        if (!function_exists('pll_get_post_translations')) {
            return false;
        }
        if (function_exists('PLL')) {
            try {
                $pll = PLL();
            } catch (\Throwable $e) {
                return false;
            }
            if (!is_object($pll)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int, string> $errors
     * @return array<int, array<string, int>>|null
     */
    protected function collect_maps(array &$errors)
    {
        $api = $this->api_ready();
        $rows = $this->translation_term_rows();

        if ($rows === null && !$api) {
            return null;
        }

        $maps = array();
        $invalid = 0;

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $term_taxonomy_id = isset($row['term_taxonomy_id']) ? (int) $row['term_taxonomy_id'] : 0;
                $decoded = $this->decode_map(isset($row['description']) ? $row['description'] : '');
                if (empty($decoded) && $term_taxonomy_id > 0) {
                    $decoded = $this->rebuild_map($term_taxonomy_id);
                }
                if (empty($decoded)) {
                    $invalid++;
                    continue;
                }

                if ($api) {
                    $sample = (int) reset($decoded);
                    $from_api = $this->map_for_post($sample);
                    if ($from_api === null) {
                        $api = false;
                        $maps[] = $this->merge_linked_posts($decoded, $term_taxonomy_id, $errors);
                        continue;
                    }
                    if (!empty($from_api)) {
                        // Keep a stored draft when the API answer is only a subset.
                        $maps[] = $this->union_maps($decoded, $from_api);
                        continue;
                    }
                }

                $maps[] = $this->merge_linked_posts($decoded, $term_taxonomy_id, $errors);
            }
            // No stored groups. Ask Polylang directly so untranslated posts
            // are still found when the API is the only index.
            if (empty($maps) && $api) {
                $scanned = $this->maps_by_scanning_posts($errors);
                if ($scanned === null && $invalid === 0) {
                    return null;
                }
                if (is_array($scanned)) {
                    $maps = $scanned;
                }
            }
        } else {
            $scanned = $this->maps_by_scanning_posts($errors);
            if ($scanned === null) {
                return null;
            }
            $maps = $scanned;
        }

        if ($invalid === 1) {
            $errors[] = $this->say('A post translation group could not be read because its stored description is invalid. That group was skipped.');
        } elseif ($invalid > 1) {
            $errors[] = $this->say_sprintf(
                '%d post translation groups could not be read because their stored descriptions are invalid. Those groups were skipped.',
                array($invalid)
            );
        }

        $known = array();
        foreach ($maps as $map) {
            foreach ($map as $post_id) {
                $known[(int) $post_id] = true;
            }
        }

        $singletons = $this->singleton_maps($known, $errors);
        if (is_array($singletons)) {
            foreach ($singletons as $map) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /**
     * @param array<int, string> $errors
     * @return array<int, array<string, int>>|null
     */
    protected function maps_by_scanning_posts(array &$errors)
    {
        $ids = $this->candidate_post_ids();
        if ($ids === null) {
            return null;
        }

        $maps = array();
        $seen = array();
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || isset($seen[$post_id])) {
                continue;
            }
            $map = $this->map_for_post($post_id);
            if ($map === null) {
                if (empty($maps)) {
                    return null;
                }
                $errors[] = $this->say('Polylang stopped returning post translations partway through the export. Posts found before that point are included.');
                break;
            }
            $seen[$post_id] = true;
            if (empty($map)) {
                continue;
            }
            foreach ($map as $mapped_id) {
                $seen[(int) $mapped_id] = true;
            }
            $maps[] = $map;
        }

        return $maps;
    }

    /**
     * Language slug => post ID for one post.
     * Null means the API itself failed and the caller should stop using it.
     *
     * @param int $post_id
     * @return array<string, int>|null
     */
    protected function map_for_post($post_id)
    {
        $post_id = (int) $post_id;
        try {
            $translations = pll_get_post_translations($post_id);
        } catch (\Throwable $e) {
            return null;
        }

        $map = $this->normalize_id_map($translations);
        if (!function_exists('pll_get_post_language')) {
            return $map;
        }

        try {
            $lang = pll_get_post_language($post_id, 'slug');
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($lang) || trim($lang) === '') {
            return $map;
        }
        $lang = $this->slug_for_language_key($lang);
        if ($lang !== '' && !$this->map_contains_id($map, $post_id) && !isset($map[$lang])) {
            $map[$lang] = $post_id;
        }
        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function translation_term_rows()
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
            self::TAX_TRANSLATIONS
        );
        return $this->normalize_rows($wpdb->get_results($sql, 'ARRAY_A'));
    }

    /**
     * Posts that have a language and are not already in a translation group.
     * These are the untranslated posts Polylang never paired with another language.
     *
     * @param array<int, bool> $known
     * @param array<int, string> $errors
     * @return array<int, array<string, int>>
     */
    protected function singleton_maps(array $known, array &$errors)
    {
        $rows = $this->language_relationship_rows();
        if ($rows === null) {
            $errors[] = $this->say('Some posts have a language but could not be checked for a translation group. They may be missing from the export.');
            return array();
        }

        $pending = array();
        foreach ($rows as $row) {
            $post_id = isset($row['object_id']) ? (int) $row['object_id'] : 0;
            $lang = isset($row['slug']) ? $this->slug_for_language_key($row['slug']) : '';
            if ($post_id <= 0 || $lang === '' || isset($known[$post_id]) || isset($pending[$post_id])) {
                continue;
            }
            $pending[$post_id] = $lang;
        }
        if (empty($pending)) {
            return array();
        }

        $this->warm_post_types(array_keys($pending));
        $skip_types = array_merge($this->ignored_post_types(), array('attachment'));
        $content_ids = array();
        foreach ($pending as $post_id => $lang) {
            $type = $this->post_type_of((int) $post_id);
            if ($type !== '' && in_array($type, $skip_types, true)) {
                unset($pending[$post_id]);
                continue;
            }
            $content_ids[] = (int) $post_id;
        }
        if (empty($pending)) {
            return array();
        }

        $records = $this->posts_by_ids($content_ids);
        $maps = array();
        $missing = array();
        foreach ($pending as $post_id => $lang) {
            if (!isset($records[$post_id])) {
                $missing[] = $post_id;
                continue;
            }
            if ($this->skip_reason($records[$post_id]) !== '') {
                continue;
            }
            $maps[] = array($lang => $post_id);
        }
        if (count($missing) === 1) {
            $errors[] = $this->say_sprintf(
                'Post %d has a language but no longer exists, so it was left out of the export.',
                array($missing[0])
            );
        } elseif (count($missing) > 1) {
            $errors[] = $this->say_sprintf(
                '%d posts have a language but no longer exist, so they were left out of the export.',
                array(count($missing))
            );
        }
        return $maps;
    }

    /**
     * @param array<int, array<string, int>> $maps
     * @param string $default_lang
     * @param array<int, string> $errors
     * @return array<int, array>
     */
    private function items_from_maps(array $maps, $default_lang, array &$errors)
    {
        $prepared = array();
        foreach ($maps as $map) {
            if (is_array($map)) {
                $this->note_duplicate_assignments($map, $errors);
            }
            $normalized = $this->normalize_id_map($map);
            $this->flush_collapsed_languages($errors);
            if (!empty($normalized)) {
                $prepared[] = $this->reconcile_languages($normalized, $errors);
            }
        }

        $default_lang = $this->slug_for_language_key(is_string($default_lang) ? $default_lang : '');
        $single_ids = array();
        foreach ($prepared as $map) {
            if (count($map) === 1) {
                $single_ids[] = (int) reset($map);
            }
        }
        $this->warm_post_types($single_ids);
        usort($prepared, function ($left, $right) use ($default_lang) {
            $left_id = $this->default_post_id($left, $default_lang);
            $right_id = $this->default_post_id($right, $default_lang);
            if ($left_id === $right_id) {
                return strcmp($this->signature($left), $this->signature($right));
            }
            return ($left_id < $right_id) ? -1 : 1;
        });

        $claimed = array();
        $items = array();
        foreach ($prepared as $map) {
            $fresh = array();
            $overlaps = array();
            foreach ($map as $lang => $post_id) {
                if (isset($claimed[$post_id])) {
                    $overlaps[$post_id] = $claimed[$post_id];
                    continue;
                }
                $fresh[$lang] = $post_id;
            }
            if (empty($fresh)) {
                continue;
            }

            foreach ($overlaps as $post_id => $kept_group) {
                $errors[] = $this->say_sprintf(
                    'Post %1$d belongs to more than one translation group. It was kept in %2$s and left out of the later group.',
                    array($post_id, $kept_group)
                );
            }
            $this->warm_post_types(array_values($fresh));
            $readable = array();
            $skipped = array();
            $replaced_revisions = array();
            foreach ($fresh as $lang => $post_id) {
                $reason = $this->skip_reason(array(
                    'type' => $this->post_type_of($post_id),
                    'status' => '',
                ));
                if ($reason !== '') {
                    $parent_id = $this->replacement_for_revision($post_id, $fresh, $readable);
                    if ($parent_id > 0) {
                        $readable[$lang] = $parent_id;
                        $replaced_revisions[] = array(
                            'revision' => $post_id,
                            'parent' => $parent_id,
                        );
                        continue;
                    }
                    $skipped[] = array(
                        'id' => $post_id,
                        'reason' => $reason,
                    );
                    continue;
                }
                $readable[$lang] = $post_id;
            }
            if ($readable === array()) {
                continue;
            }
            if (count($readable) === 1 && $this->post_type_of((int) reset($readable)) === 'attachment') {
                continue;
            }

            $records = $this->posts_by_ids(array_values($readable));
            $loaded = array();
            $missing = array();
            foreach ($readable as $lang => $post_id) {
                if (!isset($records[$post_id])) {
                    $missing[] = $post_id;
                    continue;
                }
                $reason = $this->skip_reason($records[$post_id]);
                if ($reason !== '') {
                    $skipped[] = array(
                        'id' => $post_id,
                        'reason' => $reason,
                    );
                    continue;
                }
                $loaded[$lang] = $records[$post_id];
            }

            if (empty($loaded)) {
                if (!empty($missing)) {
                    $errors[] = $this->missing_group_message($missing);
                }
                continue;
            }

            $survivor_ids = array();
            foreach ($loaded as $lang => $record) {
                $survivor_ids[$lang] = $record['id'];
            }
            $default_id = $this->default_post_id($survivor_ids, $default_lang);
            if ($default_id <= 0) {
                if (!empty($missing)) {
                    $errors[] = $this->missing_group_message($missing);
                }
                continue;
            }
            $group = $this->group_name($default_id);

            foreach ($missing as $post_id) {
                $errors[] = $this->say_sprintf(
                    'Post %1$d is part of translation group %2$s, but that post no longer exists. It was left out of the export.',
                    array($post_id, $group)
                );
            }
            foreach ($skipped as $skip) {
                $errors[] = $this->say_sprintf(
                    'Post %1$d is %2$s, so it was left out of translation group %3$s.',
                    array($skip['id'], $skip['reason'], $group)
                );
            }
            foreach ($replaced_revisions as $replacement) {
                $errors[] = $this->say_sprintf(
                    'Post %1$d is a revision of post %2$d. The export uses post %2$d instead.',
                    array($replacement['revision'], $replacement['parent'])
                );
            }
            if ($default_lang !== '' && !isset($loaded[$default_lang]) && count($loaded) > 1) {
                $errors[] = $this->say_sprintf(
                    'Translation group %1$s does not include the default language (%2$s). The group is named after post %3$d.',
                    array($group, $default_lang, $default_id)
                );
            }
            // One file with a language is media, not a post translation.
            // A group of attachment translations stays, so the files remain linked.
            if (count($loaded) === 1) {
                $only = reset($loaded);
                if (is_array($only) && isset($only['type']) && $only['type'] === 'attachment') {
                    continue;
                }
            }

            $translations = array();
            foreach ($loaded as $lang => $record) {
                $translations[$lang] = array(
                    'id' => $record['id'],
                    'title' => $this->clean_text(isset($record['title']) ? $record['title'] : ''),
                    'slug' => $this->clean_text(isset($record['slug']) ? $record['slug'] : ''),
                    'status' => isset($record['status']) ? (string) $record['status'] : '',
                    'excerpt' => $this->clean_text(isset($record['excerpt']) ? $record['excerpt'] : ''),
                    'content' => $this->clean_text(isset($record['content']) ? $record['content'] : ''),
                );
                $claimed[$record['id']] = $group;
            }

            $items[] = array(
                'group' => $group,
                'type' => $this->group_type($loaded, $default_lang, $default_id),
                'translations' => $translations,
            );
        }

        usort($items, function ($left, $right) {
            $left_id = $this->group_sort_id($left['group']);
            $right_id = $this->group_sort_id($right['group']);
            if ($left_id === $right_id) {
                return strcmp($left['group'], $right['group']);
            }
            return ($left_id < $right_id) ? -1 : 1;
        });

        return $this->unique_groups($items);
    }

    /**
     * Drop a repeated group key. Export never accumulates groups across calls.
     *
     * @param array<int, array> $items
     * @return array<int, array>
     */
    protected function unique_groups(array $items)
    {
        $unique = array();
        $seen = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['group'])) {
                continue;
            }
            $group = (string) $item['group'];
            if ($group === '') {
                continue;
            }
            if (isset($seen[$group])) {
                $index = $seen[$group];
                if (!isset($item['translations']) || !is_array($item['translations'])) {
                    continue;
                }
                if (!isset($unique[$index]['translations']) || !is_array($unique[$index]['translations'])) {
                    $unique[$index]['translations'] = array();
                }
                foreach ($item['translations'] as $lang => $translation) {
                    if (!isset($unique[$index]['translations'][$lang])) {
                        $unique[$index]['translations'][$lang] = $translation;
                    }
                }
                continue;
            }
            $seen[$group] = count($unique);
            $unique[] = $item;
        }
        return $unique;
    }

    /**
     * Keep markup and letters such as å, ä, and ö.
     * Remove null bytes. An empty string stays empty so a post with no body is still exported.
     *
     * @param mixed $value
     * @return string
     */
    private function clean_text($value)
    {
        if (!is_string($value)) {
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } else {
                return '';
            }
        }
        return str_replace("\0", '', $value);
    }

    /**
     * Post type only, so a lone media file can be skipped without reading its file contents.
     *
     * @param int $post_id
     * @return string
     */
    private function post_type_of($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }
        if (isset($this->type_cache[$post_id])) {
            return $this->type_cache[$post_id];
        }
        if (array_key_exists($post_id, $this->record_cache) && is_array($this->record_cache[$post_id])) {
            return (string) $this->record_cache[$post_id]['type'];
        }
        global $wpdb;
        if ($this->db_ready($wpdb) && method_exists($wpdb, 'get_var')) {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
                $post_id
            ));
            $type = is_string($type) ? $type : '';
            if ($type !== '') {
                $this->type_cache[$post_id] = $type;
                return $type;
            }
        }
        if (!function_exists('get_post')) {
            return '';
        }
        $post = get_post($post_id);
        if (!is_object($post) || !isset($post->post_type)) {
            return '';
        }
        $this->type_cache[$post_id] = (string) $post->post_type;
        return $this->type_cache[$post_id];
    }

    /**
     * Use the real draft when Polylang stored a revision id in the group.
     *
     * @param int $post_id
     * @param array<string, int> $fresh
     * @param array<string, int> $readable
     * @return int
     */
    private function replacement_for_revision($post_id, array $fresh, array $readable)
    {
        if ($this->post_type_of($post_id) !== 'revision') {
            return 0;
        }
        $parent_id = $this->parent_of($post_id);
        if ($parent_id <= 0 || $parent_id === (int) $post_id) {
            return 0;
        }
        if (in_array($parent_id, $fresh, true) || in_array($parent_id, $readable, true)) {
            return 0;
        }
        $parent_type = $this->post_type_of($parent_id);
        if ($parent_type === '' || $parent_type === 'attachment') {
            return 0;
        }
        if ($this->skip_reason(array('type' => $parent_type, 'status' => '')) !== '') {
            return 0;
        }
        $status = $this->post_status_of($parent_id);
        if ($status === '' || $status === 'auto-draft') {
            return 0;
        }
        return $parent_id;
    }

    /**
     * @param int $post_id
     * @return string
     */
    private function post_status_of($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }
        if (array_key_exists($post_id, $this->record_cache) && is_array($this->record_cache[$post_id])) {
            return (string) $this->record_cache[$post_id]['status'];
        }
        global $wpdb;
        if ($this->db_ready($wpdb) && method_exists($wpdb, 'get_var')) {
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d",
                $post_id
            ));
            if (is_string($status) && $status !== '') {
                return $status;
            }
        }
        if (!function_exists('get_post')) {
            return '';
        }
        $post = get_post($post_id);
        if (!is_object($post) || !isset($post->post_status)) {
            return '';
        }
        return (string) $post->post_status;
    }

    /**
     * @param int $post_id
     * @return int
     */
    private function parent_of($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return 0;
        }
        if (isset($this->parent_cache[$post_id])) {
            return $this->parent_cache[$post_id];
        }
        global $wpdb;
        if ($this->db_ready($wpdb)) {
            $rows = $this->normalize_rows($wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID = %d",
                    $post_id
                ),
                'ARRAY_A'
            ));
            if (is_array($rows) && isset($rows[0]['post_parent'])) {
                $this->parent_cache[$post_id] = (int) $rows[0]['post_parent'];
                return $this->parent_cache[$post_id];
            }
        }
        if (function_exists('get_post')) {
            $post = get_post($post_id);
            if (is_object($post) && isset($post->post_parent)) {
                $this->parent_cache[$post_id] = (int) $post->post_parent;
                return $this->parent_cache[$post_id];
            }
        }
        $this->parent_cache[$post_id] = 0;
        return 0;
    }

    /**
     * @param array<int, int> $ids
     */
    private function warm_post_types(array $ids)
    {
        $missing = array();
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !isset($this->type_cache[$post_id])) {
                $missing[$post_id] = true;
            }
        }
        if (empty($missing)) {
            return;
        }
        global $wpdb;
        if ($this->db_ready($wpdb)) {
            foreach (array_chunk(array_keys($missing), $this->page_size()) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
                $sql = "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
                $prepared = $wpdb->prepare($sql, ...array_map('intval', $chunk));
                $rows = $this->normalize_rows($wpdb->get_results($prepared, 'ARRAY_A'));
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
                    if ($post_id > 0 && isset($row['post_type'])) {
                        $this->type_cache[$post_id] = (string) $row['post_type'];
                        unset($missing[$post_id]);
                    }
                }
            }
        }
        if (empty($missing) || !function_exists('get_post')) {
            return;
        }
        foreach (array_keys($missing) as $post_id) {
            $post = get_post($post_id);
            if (is_object($post) && isset($post->post_type)) {
                $this->type_cache[$post_id] = (string) $post->post_type;
            }
        }
    }

    /**
     * @param array<string, int> $lang_to_id
     * @param string $default_lang
     * @return int
     */
    private function default_post_id(array $lang_to_id, $default_lang)
    {
        if ($default_lang !== '' && isset($lang_to_id[$default_lang])) {
            $preferred = (int) $lang_to_id[$default_lang];
            if ($preferred > 0) {
                return $preferred;
            }
        }
        $ids = array();
        foreach ($lang_to_id as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[] = $post_id;
            }
        }
        if ($ids === array()) {
            return 0;
        }
        return min($ids);
    }

    /**
     * Group id is post:{id}. The id is the default-language post, or the
     * lowest post id when that language is not in the group.
     *
     * @param int $post_id
     * @return string
     */
    private function group_name($post_id)
    {
        return 'post:' . (int) $post_id;
    }

    /**
     * @param array<string, array> $loaded
     * @param string $default_lang
     * @param int $default_id
     * @return string
     */
    private function group_type(array $loaded, $default_lang, $default_id)
    {
        if ($default_lang !== '' && isset($loaded[$default_lang]) && $loaded[$default_lang]['type'] !== '') {
            return $loaded[$default_lang]['type'];
        }
        foreach ($loaded as $record) {
            if ((int) $record['id'] === (int) $default_id && $record['type'] !== '') {
                return $record['type'];
            }
        }
        foreach ($loaded as $record) {
            if ($record['type'] !== '') {
                return $record['type'];
            }
        }
        return 'post';
    }

    /**
     * @param array $record
     * @return string
     */
    private function skip_reason(array $record)
    {
        $type = isset($record['type']) ? (string) $record['type'] : '';
        $ignored = array(
            'revision' => 'a revision',
            'nav_menu_item' => 'a menu item',
            'customize_changeset' => 'a customizer changeset',
            'oembed_cache' => 'an embed cache entry',
            'user_request' => 'a privacy request',
            'custom_css' => 'custom CSS',
            'wp_global_styles' => 'global styles',
            'wp_font_family' => 'a font family',
            'wp_font_face' => 'a font face',
        );
        if ($type !== '' && isset($ignored[$type])) {
            return $ignored[$type];
        }
        if (isset($record['status']) && $record['status'] === 'auto-draft') {
            return 'an automatic draft';
        }
        // private and trash are real posts. They stay in the group with that status.
        return '';
    }

    /**
     * Post types that are not translated content. Menu items belong to the menu export.
     *
     * @return string[]
     */
    private function ignored_post_types()
    {
        return array(
            'revision',
            'nav_menu_item',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'custom_css',
            'wp_global_styles',
            'wp_font_family',
            'wp_font_face',
        );
    }

    /**
     * @param array<int, int> $missing
     * @return string
     */
    private function missing_group_message(array $missing)
    {
        if (count($missing) === 1) {
            return $this->say_sprintf(
                'A translation group pointed at post %d, but that post no longer exists. That group was skipped.',
                array($missing[0])
            );
        }
        $labels = array();
        foreach ($missing as $post_id) {
            $labels[] = (string) (int) $post_id;
        }
        return $this->say_sprintf(
            'A translation group pointed at posts %s, but those posts no longer exist. That group was skipped.',
            array(implode(', ', $labels))
        );
    }

    /**
     * A stored description can list one post under two languages. Keep the first.
     *
     * @param mixed $map
     * @param array<int, string> $errors
     */
    private function note_duplicate_assignments($map, array &$errors)
    {
        if (!is_array($map)) {
            return;
        }
        $seen = array();
        foreach ($map as $lang => $post_id) {
            if (is_int($lang) || is_float($lang)) {
                $lang = (string) $lang;
            }
            if (!is_string($lang)) {
                continue;
            }
            $lang = $this->slug_for_language_key($lang);
            $post_id = $this->normalize_post_id($post_id);
            if ($lang === '' || $post_id <= 0) {
                continue;
            }
            if (isset($seen[$post_id]) && $seen[$post_id] !== $lang) {
                $errors[] = $this->say_sprintf(
                    'Post %1$d was listed as both %2$s and %3$s in the same translation group. Only one entry was kept.',
                    array($post_id, $seen[$post_id], $lang)
                );
                continue;
            }
            if (!isset($seen[$post_id])) {
                $seen[$post_id] = $lang;
            }
        }
    }

    /**
     * The language taxonomy is the post's real language. Prefer it when the
     * translation description has gone stale.
     *
     * @param array<string, int> $map
     * @param array<int, string> $errors
     * @return array<string, int>
     */
    private function reconcile_languages(array $map, array &$errors)
    {
        $actual = $this->actual_languages(array_values($map));
        if (!is_array($actual) || empty($actual)) {
            return $map;
        }

        $rebuilt = array();
        foreach ($map as $stored => $post_id) {
            $lang = $stored;
            $real = isset($actual[$post_id]) ? $this->slug_for_language_key($actual[$post_id]) : '';
            if ($real !== '' && $real !== $stored) {
                $taken = isset($map[$real]) || isset($rebuilt[$real]);
                if (!$taken) {
                    $errors[] = $this->say_sprintf(
                        'Post %1$d is stored as %2$s in its translation group, but its language is %3$s. It was exported as %3$s.',
                        array($post_id, $stored, $real)
                    );
                    $lang = $real;
                } else {
                    $errors[] = $this->say_sprintf(
                        'Post %1$d is stored as %2$s in its translation group, but its language is %3$s. The stored language was kept because %3$s is already used in that group.',
                        array($post_id, $stored, $real)
                    );
                }
            }
            if (!isset($rebuilt[$lang])) {
                $rebuilt[$lang] = $post_id;
            }
        }
        return $rebuilt;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    protected function actual_languages(array $ids)
    {
        $languages = array();
        $remaining = array();
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            if (function_exists('pll_get_post_language')) {
                try {
                    $lang = pll_get_post_language($post_id, 'slug');
                } catch (\Throwable $e) {
                    $lang = '';
                }
                if (is_string($lang) && trim($lang) !== '') {
                    $languages[$post_id] = $this->slug_for_language_key($lang);
                    continue;
                }
            }
            $remaining[] = $post_id;
        }
        if (empty($remaining)) {
            return $languages;
        }
        $from_db = $this->language_for_objects($remaining);
        if (!is_array($from_db)) {
            return $languages;
        }
        foreach ($from_db as $post_id => $lang) {
            if (!isset($languages[$post_id]) && $lang !== '') {
                $languages[(int) $post_id] = $this->slug_for_language_key($lang);
            }
        }
        return $languages;
    }

    /**
     * API languages win. Languages the API did not mention stay, so a draft is not dropped.
     *
     * @param array<string, int> $stored
     * @param array<string, int> $from_api
     * @return array<string, int>
     */
    private function union_maps(array $stored, array $from_api)
    {
        $union = $stored;
        foreach ($from_api as $lang => $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            $union[$lang] = $post_id;
        }
        return $union;
    }

    /**
     * Posts linked to the group in term relationships, but missing from the description.
     *
     * @param array<string, int> $map
     * @param int $term_taxonomy_id
     * @param array<int, string> $errors
     * @return array<string, int>
     */
    private function merge_linked_posts(array $map, $term_taxonomy_id, array &$errors)
    {
        $term_taxonomy_id = (int) $term_taxonomy_id;
        if ($term_taxonomy_id <= 0) {
            return $map;
        }
        $linked = $this->relationship_ids($term_taxonomy_id);
        if (!is_array($linked) || empty($linked)) {
            return $map;
        }

        $present = array();
        foreach ($map as $post_id) {
            $present[(int) $post_id] = true;
        }
        $missing = array();
        foreach ($linked as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !isset($present[$post_id])) {
                $missing[] = $post_id;
            }
        }
        if (empty($missing)) {
            return $map;
        }

        $languages = $this->language_for_objects($missing);
        if (!is_array($languages)) {
            return $map;
        }
        foreach ($missing as $post_id) {
            if (!isset($languages[$post_id]) || $languages[$post_id] === '') {
                continue;
            }
            $lang = $languages[$post_id];
            if (isset($map[$lang])) {
                if ((int) $map[$lang] !== $post_id) {
                    $errors[] = $this->say_sprintf(
                        'Post %1$d is linked to a translation group but was missing from its stored description. It was left out because %2$s is already used in that group.',
                        array($post_id, $lang)
                    );
                }
                continue;
            }
            $map[$lang] = $post_id;
            $errors[] = $this->say_sprintf(
                'Post %1$d is linked to a translation group but was missing from its stored description. It was added as %2$s.',
                array($post_id, $lang)
            );
        }
        return $map;
    }

    /**
     * @param mixed $map
     * @return array<string, int>
     */
    private function normalize_id_map($map)
    {
        if (!is_array($map)) {
            return array();
        }
        $pairs = array();
        foreach ($map as $lang => $post_id) {
            $pairs[] = array($lang, $post_id);
        }
        return $this->normalize_pairs($pairs);
    }

    /**
     * Walk language/post pairs in stored order. The first post for a language
     * stays. A later post for that same language is reported once per language.
     *
     * @param array<int, array{0:mixed,1:mixed}> $pairs
     * @return array<string, int>
     */
    private function normalize_pairs(array $pairs)
    {
        $clean = array();
        $used = array();
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !isset($pair[0])) {
                continue;
            }
            $lang = $pair[0];
            $post_id = isset($pair[1]) ? $pair[1] : 0;
            if (is_int($lang) || is_float($lang)) {
                $lang = (string) $lang;
            }
            if (!is_string($lang)) {
                continue;
            }
            $lang = trim($lang);
            if ($lang !== '') {
                $lang = $this->slug_for_language_key($lang);
            }
            $post_id = $this->normalize_post_id($post_id);
            if ($lang === '' || $post_id <= 0 || isset($used[$post_id])) {
                continue;
            }
            if (isset($clean[$lang]) && $clean[$lang] !== $post_id) {
                $this->collapsed_languages[] = array(
                    'kept' => $clean[$lang],
                    'dropped' => $post_id,
                    'lang' => $lang,
                );
                continue;
            }
            $clean[$lang] = $post_id;
            $used[$post_id] = true;
        }
        return $clean;
    }

    /**
     * @param array<int, string> $errors
     */
    private function flush_collapsed_languages(array &$errors)
    {
        $kept_by_lang = array();
        foreach ($this->collapsed_languages as $row) {
            $lang = $row['lang'];
            if (!isset($kept_by_lang[$lang])) {
                $kept_by_lang[$lang] = $row['kept'];
            }
        }
        foreach ($kept_by_lang as $lang => $kept) {
            $errors[] = $this->say_sprintf(
                'Language %1$s is listed more than once in this translation group. Post %2$d was kept.',
                array($lang, $kept)
            );
        }
        $this->collapsed_languages = array();
    }

    /**
     * @param mixed $post_id
     * @return int
     */
    private function normalize_post_id($post_id)
    {
        if (is_object($post_id) && isset($post_id->ID)) {
            $post_id = $post_id->ID;
        } elseif (is_array($post_id) && isset($post_id['id'])) {
            $post_id = $post_id['id'];
        }
        if (!is_numeric($post_id)) {
            return 0;
        }
        return (int) $post_id;
    }

    /**
     * @param array<string, int> $map
     * @param int $post_id
     * @return bool
     */
    private function map_contains_id(array $map, $post_id)
    {
        $post_id = (int) $post_id;
        foreach ($map as $mapped_id) {
            if ((int) $mapped_id === $post_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, int> $map
     * @return string
     */
    private function signature(array $map)
    {
        $copy = $map;
        ksort($copy);
        $parts = array();
        foreach ($copy as $lang => $post_id) {
            $parts[] = $lang . ':' . (int) $post_id;
        }
        return implode('|', $parts);
    }

    /**
     * @param mixed $description
     * @return array<string, int>
     */
    private function decode_map($description)
    {
        if (is_array($description)) {
            return $this->normalize_id_map($description);
        }
        if (!is_string($description)) {
            return array();
        }
        $description = trim($description);
        if ($description === '') {
            return array();
        }
        $pairs = $this->serialized_pairs($description);
        if (is_array($pairs)) {
            return $this->normalize_pairs($pairs);
        }
        $value = $this->unserialize_value($description);
        if (is_string($value)) {
            $pairs = $this->serialized_pairs($value);
            if (is_array($pairs)) {
                return $this->normalize_pairs($pairs);
            }
        }
        if (is_string($value)) {
            $again = $this->unserialize_value($value);
            if (is_array($again)) {
                $value = $again;
            }
        }
        if (!is_array($value)) {
            return array();
        }
        return $this->normalize_id_map($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function unserialize_value($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $first = $value[0];
        if ($first !== 'a' && $first !== 's') {
            return null;
        }
        set_error_handler(function () {
            return true;
        });
        try {
            $decoded = unserialize($value, array('allowed_classes' => false));
        } catch (\Throwable $e) {
            $decoded = false;
        }
        restore_error_handler();
        if (!is_array($decoded) && !is_string($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Read a serialized language map without letting a repeated key hide the first post.
     * Null means the string is not a simple map and the caller should use unserialize.
     *
     * @param string $value
     * @return array<int, array{0:string,1:mixed}>|null
     */
    private function serialized_pairs($value)
    {
        if (!is_string($value) || $value === '' || $value[0] !== 'a') {
            return null;
        }
        $offset = 0;
        if (!preg_match('/\Aa:(\d+):\{/', $value, $match)) {
            return null;
        }
        $count = (int) $match[1];
        $offset = strlen($match[0]);
        $pairs = array();
        for ($index = 0; $index < $count; $index++) {
            $key = $this->read_serialized_scalar($value, $offset);
            $item = $this->read_serialized_scalar($value, $offset);
            if ($key === null || $item === null || $key[0] !== 'string') {
                return null;
            }
            $pairs[] = array($key[1], $item[1]);
        }
        if (!isset($value[$offset]) || $value[$offset] !== '}') {
            return null;
        }
        $offset++;
        if (trim(substr($value, $offset)) !== '') {
            return null;
        }
        return $pairs;
    }

    /**
     * @param string $value
     * @param int $offset
     * @return array{0:string,1:mixed}|null
     */
    private function read_serialized_scalar($value, &$offset)
    {
        if (!isset($value[$offset])) {
            return null;
        }
        $type = $value[$offset];
        $rest = substr($value, $offset);
        if ($type === 'N' && strncmp($rest, 'N;', 2) === 0) {
            $offset += 2;
            return array('null', null);
        }
        if ($type === 'i' && preg_match('/\Ai:(-?\d+);/', $rest, $match)) {
            $offset += strlen($match[0]);
            return array('int', (int) $match[1]);
        }
        if ($type === 'b' && preg_match('/\Ab:[01];/', $rest, $match)) {
            $offset += strlen($match[0]);
            return array('bool', $match[0][2] === '1');
        }
        if ($type === 'd' && preg_match('/\Ad:([-+0-9.eE]+);/', $rest, $match)) {
            $offset += strlen($match[0]);
            return array('float', (float) $match[1]);
        }
        if ($type === 's' && preg_match('/\As:(\d+):"/', $rest, $match)) {
            $length = (int) $match[1];
            $start = $offset + strlen($match[0]);
            if ($start + $length + 2 > strlen($value)) {
                return null;
            }
            $text = substr($value, $start, $length);
            if (substr($value, $start + $length, 2) !== '";') {
                return null;
            }
            $offset = $start + $length + 2;
            return array('string', $text);
        }
        return null;
    }

    /**
     * @param int $term_taxonomy_id
     * @return array<string, int>
     */
    private function rebuild_map($term_taxonomy_id)
    {
        $object_ids = $this->relationship_ids($term_taxonomy_id);
        if (!is_array($object_ids) || empty($object_ids)) {
            return array();
        }
        $languages = $this->language_for_objects($object_ids);
        if (!is_array($languages)) {
            return array();
        }
        $map = array();
        foreach ($object_ids as $object_id) {
            $object_id = (int) $object_id;
            if ($object_id <= 0 || !isset($languages[$object_id])) {
                continue;
            }
            $lang = $languages[$object_id];
            if ($lang === '' || isset($map[$lang])) {
                continue;
            }
            $map[$lang] = $object_id;
        }
        return $map;
    }

    /**
     * @param int $term_taxonomy_id
     * @return array<int, int>|null
     */
    private function relationship_ids($term_taxonomy_id)
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return null;
        }
        $sql = $wpdb->prepare(
            "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
            (int) $term_taxonomy_id
        );
        $rows = $this->normalize_rows($wpdb->get_results($sql, 'ARRAY_A'));
        if ($rows === null) {
            return null;
        }
        $ids = array();
        foreach ($rows as $row) {
            if (isset($row['object_id'])) {
                $ids[] = (int) $row['object_id'];
            }
        }
        return $ids;
    }

    /**
     * @param array<int, int> $object_ids
     * @return array<int, string>|null
     */
    private function language_for_objects(array $object_ids)
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return null;
        }
        $object_ids = array_values(array_filter(array_map('intval', $object_ids)));
        if (empty($object_ids)) {
            return array();
        }
        $languages = array();
        foreach (array_chunk($object_ids, $this->page_size()) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT tr.object_id AS object_id, t.slug AS slug FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND tr.object_id IN ($placeholders)";
            $params = array_merge(array(self::TAX_LANGUAGE), $chunk);
            $prepared = $wpdb->prepare($sql, ...$params);
            $rows = $this->normalize_rows($wpdb->get_results($prepared, 'ARRAY_A'));
            if ($rows === null) {
                return null;
            }
            foreach ($rows as $row) {
                $object_id = isset($row['object_id']) ? (int) $row['object_id'] : 0;
                $slug = isset($row['slug']) ? $this->slug_for_language_key($row['slug']) : '';
                if ($object_id > 0 && $slug !== '' && !isset($languages[$object_id])) {
                    $languages[$object_id] = $slug;
                }
            }
        }
        return $languages;
    }

    /**
     * Polylang's language term description stores the locale. A translation
     * group sometimes names that locale instead of the slug.
     *
     * @return array<string, string>
     */
    private function locale_to_slug()
    {
        if (is_array($this->locale_map)) {
            return $this->locale_map;
        }
        $rows = $this->language_term_rows();
        if (!is_array($rows)) {
            return array();
        }
        $this->locale_map = array();
        foreach ($rows as $row) {
            $slug = isset($row['slug']) ? $this->lowercase_slug($row['slug']) : '';
            $raw = '';
            if (isset($row['term_description'])) {
                $raw = $row['term_description'];
            } elseif (isset($row['description'])) {
                $raw = $row['description'];
            }
            $decoded = $this->unserialize_value(is_string($raw) ? $raw : '');
            if ($slug === '' || !is_array($decoded) || empty($decoded['locale']) || !is_string($decoded['locale'])) {
                continue;
            }
            $locale = $this->lowercase_slug($decoded['locale']);
            if ($locale !== '' && $locale !== $slug) {
                $this->locale_map[$locale] = $slug;
            }
        }
        return $this->locale_map;
    }

    /**
     * @return array<int, array>|null
     */
    private function language_term_rows()
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return array();
        }
        $sql = $wpdb->prepare(
            "SELECT t.slug AS slug, tt.description AS term_description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
            self::TAX_LANGUAGE
        );
        return $this->normalize_rows($wpdb->get_results($sql, 'ARRAY_A'));
    }

    private function language_relationship_rows()
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return array();
        }
        $ignored = array_merge($this->ignored_post_types(), array('attachment'));
        $placeholders = implode(', ', array_fill(0, count($ignored), '%s'));
        $sql = "SELECT tr.object_id AS object_id, t.slug AS slug FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND (p.ID IS NULL OR (p.post_status <> %s AND p.post_type NOT IN ($placeholders)))";
        $params = array_merge(array(self::TAX_LANGUAGE, 'auto-draft'), $ignored);
        $prepared = $wpdb->prepare($sql, ...$params);
        return $this->normalize_rows($wpdb->get_results($prepared, 'ARRAY_A'));
    }

    /**
     * @param string $taxonomy
     * @return bool
     */
    private function taxonomy_present($taxonomy)
    {
        global $wpdb;
        if (!$this->db_ready($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
            $taxonomy
        ));
        return $found !== null && $found !== '' && $found !== false;
    }

    /**
     * @return array|null
     */
    private function polylang_option()
    {
        if (function_exists('get_option')) {
            $value = get_option(self::OPTION_KEY, null);
            if (is_string($value)) {
                $decoded = $this->unserialize_value($value);
                return is_array($decoded) ? $decoded : null;
            }
            return is_array($value) ? $value : null;
        }

        global $wpdb;
        if (!$this->db_ready($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            self::OPTION_KEY
        ));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = $this->unserialize_value($raw);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Statuses a site owner can still edit. Automatic editor shells are excluded
     * here; drafts, pending review, private, and scheduled posts stay.
     *
     * @return string[]
     */
    protected function export_statuses()
    {
        $statuses = array('publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash');
        if (function_exists('get_post_stati')) {
            $registered = get_post_stati(array(), 'names');
            if (is_array($registered)) {
                foreach ($registered as $status) {
                    if ($status === 'auto-draft') {
                        continue;
                    }
                    $statuses[] = $status;
                }
            }
        }
        return array_values(array_unique($statuses));
    }

    /**
     * @return string|string[]
     */
    private function export_post_types()
    {
        if (!function_exists('get_post_types')) {
            return 'any';
        }
        $types = get_post_types(array(), 'names');
        if (!is_array($types) || empty($types)) {
            return 'any';
        }
        foreach ($this->ignored_post_types() as $ignored) {
            unset($types[$ignored]);
        }
        $types = array_values($types);
        if (empty($types)) {
            return 'any';
        }
        return $types;
    }

    /**
     * @return int
     */
    protected function page_size()
    {
        return 200;
    }

    /**
     * @return array<int, int>|null
     */
    protected function candidate_post_ids()
    {
        if (function_exists('get_posts')) {
            $ids = $this->candidate_ids_from_posts();
            if (is_array($ids)) {
                return $ids;
            }
        }
        return $this->candidate_ids_from_db();
    }

    /**
     * @return array<int, int>|null
     */
    private function candidate_ids_from_posts()
    {
        $collected = array();
        $page = 1;
        $size = $this->page_size();
        $guard = 0;
        do {
            $batch = get_posts(array(
                'post_type' => $this->export_post_types(),
                'post_status' => $this->export_statuses(),
                'posts_per_page' => $size,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'suppress_filters' => true,
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'cache_results' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'lang' => '',
            ));
            if (!is_array($batch)) {
                return null;
            }
            if (empty($batch)) {
                break;
            }
            $added = 0;
            foreach ($batch as $post_id) {
                if (is_object($post_id) && isset($post_id->ID)) {
                    $post_id = $post_id->ID;
                }
                if (!is_numeric($post_id)) {
                    continue;
                }
                $post_id = (int) $post_id;
                if ($post_id <= 0 || isset($collected[$post_id])) {
                    continue;
                }
                $collected[$post_id] = true;
                $added++;
            }
            if ($added === 0) {
                break;
            }
            $page++;
            $guard++;
        } while (count($batch) === $size && $guard < 100000);

        return array_map('intval', array_keys($collected));
    }

    /**
     * @return array<int, int>|null
     */
    private function candidate_ids_from_db()
    {
        global $wpdb;
        if (!$this->db_ready($wpdb)) {
            return null;
        }

        $collected = array();
        $offset = 0;
        $size = $this->page_size();
        $guard = 0;
        do {
            $ignored = $this->ignored_post_types();
            $placeholders = implode(', ', array_fill(0, count($ignored), '%s'));
            $params = array_merge(array('auto-draft'), $ignored, array($size, $offset));
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status <> %s AND post_type NOT IN ($placeholders) ORDER BY ID ASC LIMIT %d OFFSET %d",
                ...$params
            );
            $rows = $this->normalize_rows($wpdb->get_results($sql, 'ARRAY_A'));
            if ($rows === null) {
                return empty($collected) ? null : array_map('intval', array_keys($collected));
            }
            if (empty($rows)) {
                break;
            }
            $added = 0;
            foreach ($rows as $row) {
                $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
                if ($post_id <= 0 || isset($collected[$post_id])) {
                    continue;
                }
                $collected[$post_id] = true;
                $added++;
            }
            if ($added === 0) {
                break;
            }
            $offset += count($rows);
            $guard++;
        } while (count($rows) === $size && $guard < 100000);

        return array_map('intval', array_keys($collected));
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array>
     */
    private function posts_by_ids(array $ids)
    {
        $this->warm_post_cache($ids);
        $records = array();
        foreach ($ids as $post_id) {
            $record = $this->post_record((int) $post_id);
            if (is_array($record) && isset($record['id'])) {
                $records[(int) $record['id']] = $record;
            }
        }
        return $records;
    }

    /**
     * @param array<int, int> $ids
     */
    private function warm_post_cache(array $ids)
    {
        global $wpdb;
        if (function_exists('get_post') || !$this->db_ready($wpdb)) {
            return;
        }
        $missing = array();
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && !array_key_exists($post_id, $this->record_cache)) {
                $missing[] = $post_id;
            }
        }
        if (empty($missing)) {
            return;
        }
        $found = array();
        foreach (array_chunk($missing, $this->page_size()) as $chunk) {
            $rows = $this->post_rows_for_ids($chunk);
            if (!is_array($rows)) {
                foreach ($chunk as $post_id) {
                    $this->record_cache[$post_id] = $this->post_record_from_db($post_id);
                    $found[$post_id] = true;
                }
                continue;
            }
            foreach ($rows as $row) {
                $record = $this->record_from_row($row);
                if (is_array($record)) {
                    $this->record_cache[$record['id']] = $record;
                    $found[$record['id']] = true;
                }
            }
        }
        foreach ($missing as $post_id) {
            if (!isset($found[$post_id])) {
                $this->record_cache[$post_id] = null;
            }
        }
    }

    /**
     * @param int $post_id
     * @return array|null
     */
    protected function post_record($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return null;
        }
        if (array_key_exists($post_id, $this->record_cache)) {
            $cached = $this->record_cache[$post_id];
            return is_array($cached) ? $cached : null;
        }
        if (function_exists('get_post')) {
            $post = get_post($post_id);
            $record = is_object($post) ? $this->record_from_object($post) : null;
            $this->record_cache[$post_id] = $record;
            return $record;
        }
        $record = $this->post_record_from_db($post_id);
        $this->record_cache[$post_id] = $record;
        return $record;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array>|null
     */
    private function post_rows_for_ids(array $ids)
    {
        global $wpdb;
        if (!$this->db_ready($wpdb) || empty($ids)) {
            return null;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = "SELECT ID, post_type, post_title, post_name, post_status, post_excerpt, post_content FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
        $prepared = $wpdb->prepare($sql, ...array_map('intval', $ids));
        return $this->normalize_rows($wpdb->get_results($prepared, 'ARRAY_A'));
    }

    /**
     * @param int $post_id
     * @return array|null
     */
    private function post_record_from_db($post_id)
    {
        global $wpdb;
        if (!$this->db_ready($wpdb) || !method_exists($wpdb, 'get_row')) {
            return null;
        }
        $sql = $wpdb->prepare(
            "SELECT ID, post_type, post_title, post_name, post_status, post_excerpt, post_content FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
            (int) $post_id
        );
        $row = $wpdb->get_row($sql, 'ARRAY_A');
        if (is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!is_array($row)) {
            return null;
        }
        return $this->record_from_row($row);
    }

    /**
     * @param object $post
     * @return array|null
     */
    private function record_from_object($post)
    {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            return null;
        }
        return array(
            'id' => $post_id,
            'type' => isset($post->post_type) ? (string) $post->post_type : '',
            'title' => $this->clean_text(isset($post->post_title) ? $post->post_title : ''),
            'slug' => $this->clean_text(isset($post->post_name) ? $post->post_name : ''),
            'status' => isset($post->post_status) ? (string) $post->post_status : '',
            'excerpt' => $this->clean_text(isset($post->post_excerpt) ? $post->post_excerpt : ''),
            'content' => $this->clean_text(isset($post->post_content) ? $post->post_content : ''),
        );
    }

    /**
     * @param array $row
     * @return array|null
     */
    private function record_from_row(array $row)
    {
        $post_id = 0;
        if (isset($row['ID'])) {
            $post_id = (int) $row['ID'];
        } elseif (isset($row['id'])) {
            $post_id = (int) $row['id'];
        }
        if ($post_id <= 0) {
            return null;
        }
        return array(
            'id' => $post_id,
            'type' => isset($row['post_type']) ? (string) $row['post_type'] : '',
            'title' => $this->clean_text(isset($row['post_title']) ? $row['post_title'] : ''),
            'slug' => $this->clean_text(isset($row['post_name']) ? $row['post_name'] : ''),
            'status' => isset($row['post_status']) ? (string) $row['post_status'] : '',
            'excerpt' => $this->clean_text(isset($row['post_excerpt']) ? $row['post_excerpt'] : ''),
            'content' => $this->clean_text(isset($row['post_content']) ? $row['post_content'] : ''),
        );
    }

    /**
     * @param mixed $rows
     * @return array<int, array>|null
     */
    private function normalize_rows($rows)
    {
        if (!is_array($rows)) {
            return null;
        }
        $normalized = array();
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }
        return $normalized;
    }

    /**
     * @param object|null $wpdb
     * @return bool
     */
    private function db_ready($wpdb)
    {
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return false;
        }
        foreach (array('posts', 'terms', 'term_taxonomy', 'term_relationships', 'options') as $property) {
            if (!isset($wpdb->$property) || !is_string($wpdb->$property) || $wpdb->$property === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $group
     * @return int
     */
    private function group_sort_id($group)
    {
        if (!is_string($group) || strpos($group, 'post:') !== 0) {
            return 0;
        }
        return (int) substr($group, 5);
    }

    /**
     * @param string $text
     * @return string
     */
    private function say($text)
    {
        if (function_exists('__')) {
            return __($text, 'migration-manager');
        }
        return $text;
    }

    /**
     * @param string $text
     * @param array<int, mixed> $args
     * @return string
     */
    private function say_sprintf($text, array $args)
    {
        try {
            $message = vsprintf($this->say($text), $args);
        } catch (\Throwable $e) {
            return $text;
        }
        if (!is_string($message) || $message === '') {
            return $text;
        }
        return $message;
    }

    /**
     * @param \Throwable $exception
     */
    private function log_failure($exception)
    {
        if (!function_exists('error_log')) {
            return;
        }
        error_log('Migration Manager: Tolka post export failed. ' . $exception->getMessage());
    }
}
