<?php

/**
 * Export Polylang languages for Migrate to Tolka.
 *
 * Reads the Polylang API when it is loaded. Otherwise reads the language
 * taxonomy, term_language, the post and term translation groups, and the
 * polylang option. Polylang data is never deleted or updated.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!class_exists(__NAMESPACE__ . '\\Polylang_Languages', false)) {

/**
 * Collects languages for the Tolka migration package.
 *
 * Call Polylang_Languages::export(). An instance call works as well, so a
 * missing static keyword in another slice cannot fatal.
 */
class Polylang_Languages
{
    /**
     * Whether the language taxonomy query failed during this export.
     *
     * @var bool
     */
    private $storage_failed = false;

    /**
     * Whether the Polylang API was present but returned something unusable.
     *
     * @var bool
     */
    private $api_failed = false;

    /**
     * Cached polylang option for the current export.
     *
     * @var array
     */
    private $options = array();

    /**
     * Whether the polylang option has already been loaded.
     *
     * @var bool
     */
    private $options_loaded = false;

    /**
     * Export languages.
     *
     * `items` is a list of languages. Each item has `slug`, `name`, `locale`,
     * `is_default` (bool), `term_id` (int), and `flag` (string). ASCII slugs
     * are lowercase. `flag` is the Polylang flag code when one is stored.
     * `locale` uses underscores, as in en_US.
     *
     * `errors` is empty when at least one language was read. Otherwise it
     * holds one sentence explaining what the site owner can do next.
     *
     * Languages that have no posts are included. The default language is
     * listed first. One language on its own is the default. Nothing is written.
     *
     * @return array
     */
    public static function export()
    {
        $exporter = new self();

        return $exporter->read();
    }

    /**
     * Read languages from the API and from stored Polylang data.
     *
     * @return array
     */
    private function read()
    {
        try {
            return $this->read_languages();
        } catch (\Throwable $throwable) {
            return $this->envelope(array(), array($this->message_failed()));
        }
    }

    /**
     * Build the language envelope.
     *
     * @return array
     */
    private function read_languages()
    {
        $this->storage_failed = false;
        $this->api_failed = false;
        $this->options_loaded = false;
        $this->options = array();

        $default = $this->default_slug();
        $api_items = array();
        $storage_items = array();

        if (function_exists('pll_languages_list')) {
            try {
                $api_items = $this->items_from_api($default);
            } catch (\Throwable $throwable) {
                $api_items = array();
                $this->api_failed = true;
            }
        }

        try {
            $storage_items = $this->items_from_storage($default);
        } catch (\Throwable $throwable) {
            $storage_items = array();
            $this->storage_failed = true;
        }

        // An empty API list is not the end of the read. Polylang can omit a
        // language that has no posts, while the language term is still stored.
        $items = $this->merge_items($api_items, $storage_items);

        if (!$this->storage_failed || !empty($items)) {
            $items = $this->ensure_single_default($items, $default);
        }

        if (!empty($items)) {
            return $this->envelope($items, array());
        }

        if ($this->api_failed || $this->storage_failed) {
            return $this->envelope(array(), array($this->message_failed()));
        }

        if (function_exists('pll_languages_list')) {
            return $this->envelope(array(), array($this->message_active_empty()));
        }

        return $this->envelope(array(), array($this->message_inactive()));
    }

    /**
     * Languages returned by the Polylang API.
     *
     * An empty `fields` value asks Polylang for language records. A list of
     * slugs is accepted too, for older callers that ignore that argument.
     *
     * @param string $default Default language slug.
     * @return array
     */
    private function items_from_api($default)
    {
        $raw = pll_languages_list(array(
            'hide_empty' => false,
            'fields' => '',
        ));

        if (!is_array($raw)) {
            $this->api_failed = true;
            return array();
        }

        if (!$this->list_is_records($raw)) {
            return $this->items_from_api_fields($default);
        }

        $items = array();
        foreach ($raw as $language) {
            $this->add_item($items, $this->item_from_record($language, $default));
        }

        return array_values($items);
    }

    /**
     * Read one Polylang field at a time when records were not returned.
     *
     * @param string $default Default language slug.
     * @return array
     */
    private function items_from_api_fields($default)
    {
        $names = array('slug', 'name', 'locale', 'term_id', 'flag_code', 'flag', 'is_default');
        $columns = array();

        foreach ($names as $name) {
            $values = pll_languages_list(array(
                'hide_empty' => false,
                'fields' => $name,
            ));
            $columns[$name] = is_array($values) ? array_values($values) : array();
        }

        $slugs = $columns['slug'];
        $fields_ignored = $columns['name'] === $slugs
            && $columns['locale'] === $slugs
            && $columns['flag_code'] === $slugs
            && $columns['flag'] === $slugs;

        $items = array();
        $count = count($slugs);

        for ($index = 0; $index < $count; $index++) {
            $slug = $this->string_value(isset($slugs[$index]) ? $slugs[$index] : '');
            if ($slug === '') {
                continue;
            }

            if ($fields_ignored) {
                $this->add_item($items, $this->make_item($slug, '', '', $slug === $default, 0, ''));
                continue;
            }

            $name = $this->column_string($columns['name'], $index);
            $locale = $this->column_string($columns['locale'], $index);
            $flag = $this->normalize_flag($this->column_string($columns['flag_code'], $index));
            if ($flag === '') {
                $flag = $this->column_string($columns['flag'], $index);
            }

            $is_default = ($slug === $default);
            if (array_key_exists($index, $columns['is_default'])) {
                $flag_default = $columns['is_default'][$index];
                if ($this->is_truthy_flag($flag_default)) {
                    $is_default = true;
                } elseif ($this->is_false_flag($flag_default)) {
                    $is_default = false;
                }
            }

            $term_id = 0;
            if (isset($columns['term_id'][$index])) {
                $term_id = $this->int_value($columns['term_id'][$index]);
            }

            $this->add_item($items, $this->make_item($slug, $name, $locale, $is_default, $term_id, $flag));
        }

        return array_values($items);
    }

    /**
     * Languages stored in taxonomies and translation groups.
     *
     * @param string $default Default language slug.
     * @return array
     */
    private function items_from_storage($default)
    {
        $terms = $this->terms_for_taxonomy('language');
        usort($terms, array($this, 'compare_terms'));

        $items = array();
        foreach ($terms as $term) {
            $this->add_item($items, $this->item_from_language_term($term, $default));
        }

        // term_language mirrors each language. Use it only to fill a language
        // whose language-taxonomy row is missing.
        foreach ($this->terms_for_taxonomy('term_language') as $term) {
            $this->add_item($items, $this->item_from_language_term($term, $default));
        }

        $extras = array();
        foreach ($this->slugs_from_translations() as $slug) {
            $item = $this->make_item($slug, '', '', strcasecmp($slug, $default) === 0, 0, '');
            if ($item['slug'] === '' || isset($items[$item['slug']])) {
                continue;
            }
            $extras[$item['slug']] = $item;
        }
        ksort($extras, SORT_STRING);
        foreach ($extras as $item) {
            $this->add_item($items, $item);
        }

        return array_values($items);
    }

    /**
     * Map one API language record, array, or slug.
     *
     * @param mixed $language Language record.
     * @param string $default Default language slug.
     * @return array
     */
    private function item_from_record($language, $default)
    {
        if (is_string($language)) {
            $slug = $this->string_value($language);
            return $this->make_item($slug, '', '', $slug !== '' && $slug === $default, 0, '');
        }

        if (!is_object($language) && !is_array($language)) {
            return $this->make_item('', '', '', false, 0, '');
        }

        $slug = $this->string_value($this->read_field($language, 'slug'));
        $name = $this->string_value($this->read_field($language, 'name'));
        $locale = $this->read_field($language, 'locale');
        $locale = is_string($locale) ? trim($locale) : '';

        $term_id = $this->int_value($this->read_field($language, 'term_id'));
        if ($term_id <= 0) {
            $props = $this->read_field($language, 'term_props');
            if (is_array($props) && isset($props['language']['term_id'])) {
                $term_id = $this->int_value($props['language']['term_id']);
            }
        }

        $flag = $this->normalize_flag($this->read_field($language, 'flag_code'));
        if ($flag === '') {
            $description = $this->decode_stored_array($this->read_field($language, 'description'));
            if (isset($description['flag_code'])) {
                $flag = $this->normalize_flag($description['flag_code']);
            }
        }
        if ($locale === '') {
            if (!isset($description)) {
                $description = $this->decode_stored_array($this->read_field($language, 'description'));
            }
            if (isset($description['locale']) && is_string($description['locale'])) {
                $locale = trim($description['locale']);
            }
        }
        if (!$this->is_flag_code($flag)) {
            $custom = $this->string_value($this->read_field($language, 'custom_flag_url'));
            $url = $this->string_value($this->read_field($language, 'flag_url'));
            $markup = $this->string_value($this->read_field($language, 'flag'));
            if ($custom !== '') {
                $flag = $custom;
            } elseif ($url !== '') {
                $flag = $url;
            } else {
                $flag = $markup;
            }
        }

        $is_default_prop = $this->read_field($language, 'is_default');
        if ($is_default_prop === null && $default !== '') {
            $is_default = ($slug !== '' && $slug === $default);
        } elseif ($this->is_truthy_flag($is_default_prop)) {
            $is_default = true;
        } elseif ($this->is_false_flag($is_default_prop)) {
            $is_default = false;
        } else {
            $is_default = ($slug !== '' && $slug === $default);
        }

        if ($locale === '' && $term_id > 0) {
            $locale = $this->term_meta_string($term_id, 'locale');
        }
        if ($flag === '' && $term_id > 0) {
            $flag = $this->normalize_flag($this->term_meta_string($term_id, 'flag_code'));
        }

        return $this->make_item($slug, $name, $locale, $is_default, $term_id, $flag);
    }

    /**
     * Map one language taxonomy term.
     *
     * Terms with no posts are kept. Polylang stores locale and flag_code in
     * the term description.
     *
     * @param mixed $term Term row.
     * @param string $default Default language slug.
     * @return array
     */
    private function item_from_language_term($term, $default)
    {
        $slug = $this->string_value($this->read_field($term, 'slug'));
        $name = $this->string_value($this->read_field($term, 'name'));
        $term_id = $this->int_value($this->read_field($term, 'term_id'));
        $data = $this->decode_stored_array($this->read_field($term, 'description'));

        $locale = '';
        if (isset($data['locale']) && is_string($data['locale'])) {
            $locale = trim($data['locale']);
        }
        if ($locale === '') {
            $locale = $this->term_meta_string($term_id, 'locale');
        }

        $flag = '';
        if (isset($data['flag_code'])) {
            $flag = $this->normalize_flag($data['flag_code']);
        }
        if ($flag === '' && isset($data['flag'])) {
            $flag = $this->normalize_flag($data['flag']);
        }
        if ($flag === '') {
            $flag = $this->normalize_flag($this->term_meta_string($term_id, 'flag_code'));
        }

        return $this->make_item(
            $slug,
            $name,
            $locale,
            $slug !== '' && $slug === $default,
            $term_id,
            $flag
        );
    }

    /**
     * Language slugs referenced by translation groups.
     *
     * @return string[]
     */
    private function slugs_from_translations()
    {
        $slugs = array();

        foreach (array('post_translations', 'term_translations') as $taxonomy) {
            $terms = $this->terms_for_taxonomy($taxonomy);
            foreach ($terms as $term) {
                $data = $this->decode_stored_array($this->read_field($term, 'description'));
                foreach ($data as $key => $value) {
                    // Translation groups map a language slug to a post or term id.
                    if (!$this->is_language_slug($key) || $this->int_value($value) <= 0) {
                        continue;
                    }
                    $slugs[$key] = $key;
                }
            }
        }

        return array_values($slugs);
    }

    /**
     * Terms for a Polylang taxonomy.
     *
     * get_terms is used when WordPress can answer. A direct read is used
     * when Polylang is inactive, and also to add rows get_terms left out.
     * A language with no posts can be filtered out of get_terms even when
     * hide_empty is false. Missing rows produce an empty list, not an exception.
     *
     * @param string $taxonomy language, term_language, post_translations, or term_translations.
     * @return array
     */
    private function terms_for_taxonomy($taxonomy)
    {
        $allowed = array('language', 'term_language', 'post_translations', 'term_translations');
        if (!in_array($taxonomy, $allowed, true)) {
            return array();
        }

        $terms = null;
        if (function_exists('get_terms')) {
            $queried = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'orderby' => 'term_group',
                'order' => 'ASC',
            ));
            if (is_array($queried)) {
                $terms = $queried;
            }
        }

        $stored = $this->terms_from_database($taxonomy);
        if ($terms === null) {
            return $stored;
        }

        return $this->merge_term_rows($terms, $stored);
    }

    /**
     * Add stored rows that get_terms did not return.
     *
     * A stored description replaces an empty one for the same slug.
     *
     * @param array $primary Rows from get_terms.
     * @param array $stored Rows from the database.
     * @return array
     */
    private function merge_term_rows($primary, $stored)
    {
        $known = array();
        foreach ($primary as $index => $term) {
            $slug = $this->string_value($this->read_field($term, 'slug'));
            if ($slug !== '') {
                $known[$slug] = $index;
            }
        }

        foreach ($stored as $term) {
            $slug = $this->string_value($this->read_field($term, 'slug'));
            if ($slug === '') {
                continue;
            }
            if (!isset($known[$slug])) {
                $primary[] = $term;
                $known[$slug] = count($primary) - 1;
                continue;
            }

            $index = $known[$slug];
            if (!$this->description_has_language_data($primary[$index]) && $this->description_has_language_data($term)) {
                $primary[$index] = $term;
            }
        }

        return $primary;
    }

    /**
     * Whether a term description includes a locale or flag code.
     *
     * An empty serialized array does not count. The stored row can replace it.
     *
     * @param mixed $term Term row.
     * @return bool
     */
    private function description_has_language_data($term)
    {
        $data = $this->decode_stored_array($this->read_field($term, 'description'));
        if (isset($data['locale']) && is_string($data['locale']) && trim($data['locale']) !== '') {
            return true;
        }
        if (isset($data['flag_code']) && is_string($data['flag_code']) && trim($data['flag_code']) !== '') {
            return true;
        }
        if (isset($data['flag']) && is_string($data['flag']) && $this->is_flag_code($this->normalize_flag($data['flag']))) {
            return true;
        }

        return false;
    }

    /**
     * Read taxonomy rows without requiring the taxonomy to be registered.
     *
     * @param string $taxonomy Taxonomy name.
     * @return array
     */
    private function terms_from_database($taxonomy)
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return array();
        }

        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            if ($taxonomy === 'language') {
                $this->storage_failed = true;
            }
            return array();
        }

        $sql = "SELECT t.term_id, t.name, t.slug, t.term_group, tt.description, tt.count
            FROM {$wpdb->terms} AS t
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s
            ORDER BY t.term_group ASC, t.name ASC, t.slug ASC";

        $prepared = $wpdb->prepare($sql, $taxonomy);
        if (!is_string($prepared) || $prepared === '') {
            if ($taxonomy === 'language') {
                $this->storage_failed = true;
            }
            return array();
        }

        $rows = $this->quiet_db($wpdb, function () use ($wpdb, $prepared) {
            return $wpdb->get_results($prepared);
        });
        if (!is_array($rows)) {
            if ($taxonomy === 'language') {
                $this->storage_failed = true;
            }
            return array();
        }

        return $rows;
    }

    /**
     * Order language terms the way Polylang lists them.
     *
     * @param mixed $left First term.
     * @param mixed $right Second term.
     * @return int
     */
    private function compare_terms($left, $right)
    {
        $left_group = $this->int_value($this->read_field($left, 'term_group'));
        $right_group = $this->int_value($this->read_field($right, 'term_group'));
        if ($left_group !== $right_group) {
            return ($left_group < $right_group) ? -1 : 1;
        }

        $by_name = strcasecmp(
            $this->plain_name($this->read_field($left, 'name')),
            $this->plain_name($this->read_field($right, 'name'))
        );
        if ($by_name !== 0) {
            return $by_name;
        }

        return strcmp(
            $this->normalized_slug($this->read_field($left, 'slug')),
            $this->normalized_slug($this->read_field($right, 'slug'))
        );
    }

    /**
     * Keep the first row for a slug and fill blanks from a later duplicate.
     *
     * @param array $items Rows keyed by slug.
     * @param array $item Row to add.
     * @return void
     */
    private function add_item(&$items, $item)
    {
        if (!is_array($item) || !isset($item['slug']) || $item['slug'] === '') {
            return;
        }

        $slug = $item['slug'];
        if (isset($items[$slug])) {
            $items[$slug] = $this->fill_item($items[$slug], $item);
            return;
        }

        $items[$slug] = $item;
    }

    /**
     * Prefer API rows, then fill blanks from stored terms.
     *
     * @param array $primary Rows that should keep their order.
     * @param array $secondary Rows used to fill missing values and languages.
     * @return array
     */
    private function merge_items($primary, $secondary)
    {
        $merged = array();

        foreach ($primary as $item) {
            $this->add_item($merged, $item);
        }
        foreach ($secondary as $item) {
            $this->add_item($merged, $item);
        }

        return array_values($merged);
    }

    /**
     * Fill an empty name, locale, term id, or flag from the stored row.
     *
     * A flag code replaces flag markup. The code is what Polylang stores and
     * what both read paths can produce.
     *
     * @param array $preferred Row kept on conflict.
     * @param array $fallback Row used for blanks.
     * @return array
     */
    private function fill_item($preferred, $fallback)
    {
        $name_is_slug = ($preferred['name'] === '' || strcasecmp($preferred['name'], $preferred['slug']) === 0);
        $fallback_is_slug = ($fallback['name'] === '' || strcasecmp($fallback['name'], $preferred['slug']) === 0);
        if ($name_is_slug && !$fallback_is_slug) {
            $preferred['name'] = $fallback['name'];
        }

        $fallback_is_richer = $fallback['locale'] !== '' && $preferred['locale'] === '';
        if ($fallback['term_id'] > 0 && ($preferred['term_id'] === 0 || $fallback_is_richer)) {
            $preferred['term_id'] = $fallback['term_id'];
        }

        if ($preferred['locale'] === '' && $fallback['locale'] !== '') {
            $preferred['locale'] = $fallback['locale'];
        }

        if ($this->should_replace_flag($preferred['flag'], $fallback['flag'])) {
            $preferred['flag'] = $fallback['flag'];
        }

        if ($fallback['is_default'] === true) {
            $preferred['is_default'] = true;
        }

        return $preferred;
    }

    /**
     * Mark the configured default language.
     *
     * A default slug that has no language row is not invented. Matching
     * ignores case, so a saved value of "NO" still marks "no". One language
     * on its own is the default, including when the saved choice is missing.
     *
     * @param array $items Language rows.
     * @param string $default Default language slug.
     * @return array
     */
    private function ensure_single_default($items, $default)
    {
        if ($items === array()) {
            return array();
        }

        if ($default !== '') {
            $canonical = '';
            foreach ($items as $item) {
                if (strcasecmp($item['slug'], $default) === 0) {
                    $canonical = $item['slug'];
                    break;
                }
            }
            if ($canonical === '' && count($items) === 1) {
                $items[0]['is_default'] = true;
                return $this->default_first($items);
            }
            foreach ($items as $index => $item) {
                $items[$index]['is_default'] = ($canonical !== '' && $item['slug'] === $canonical);
            }
            return $this->default_first($items);
        }

        if (count($items) === 1) {
            $items[0]['is_default'] = true;
            return $this->default_first($items);
        }

        $seen_default = false;
        foreach ($items as $index => $item) {
            if (!$seen_default && $item['is_default'] === true) {
                $seen_default = true;
                continue;
            }
            $items[$index]['is_default'] = false;
        }

        return $this->default_first($items);
    }

    /**
     * Put the default language first so a short preview still shows it.
     *
     * The other languages stay in Polylang's order.
     *
     * @param array $items Language rows.
     * @return array
     */
    private function default_first($items)
    {
        $first = array();
        $rest = array();
        foreach ($items as $item) {
            if ($first === array() && isset($item['is_default']) && $item['is_default'] === true) {
                $first[] = $item;
                continue;
            }
            $rest[] = $item;
        }

        return array_merge($first, $rest);
    }

    /**
     * Slug of the default language, or an empty string when it is not set.
     *
     * @return string
     */
    private function default_slug()
    {
        if (function_exists('pll_default_language')) {
            try {
                $slug = pll_default_language('slug');
            } catch (\Throwable $throwable) {
                $slug = '';
            }
            if (is_string($slug) && trim($slug) !== '') {
                return trim($slug);
            }
        }

        $options = $this->polylang_options();
        if (isset($options['default_lang']) && is_string($options['default_lang'])) {
            return trim($options['default_lang']);
        }

        return '';
    }

    /**
     * The polylang option array.
     *
     * @return array
     */
    private function polylang_options()
    {
        if ($this->options_loaded) {
            return $this->options;
        }

        $this->options_loaded = true;
        $this->options = $this->load_polylang_options();

        return $this->options;
    }

    /**
     * Load the polylang option from WordPress or from the options table.
     *
     * @return array
     */
    private function load_polylang_options()
    {
        if (function_exists('get_option')) {
            $options = get_option('polylang');
            if (is_array($options)) {
                return $options;
            }
            $decoded = $this->decode_stored_array($options);
            if ($decoded !== array()) {
                return $decoded;
            }
            return array();
        }

        $wpdb = $this->wpdb();
        if ($wpdb === null || !isset($wpdb->options) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return array();
        }

        $sql = "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1";
        $prepared = $wpdb->prepare($sql, 'polylang');
        if (!is_string($prepared) || $prepared === '') {
            return array();
        }

        $value = $this->quiet_db($wpdb, function () use ($wpdb, $prepared) {
            return $wpdb->get_var($prepared);
        });

        return $this->decode_stored_array($value);
    }

    /**
     * Run a database read without printing a SQL error on the screen.
     *
     * The previous error-display setting is restored. A failed read returns null.
     *
     * @param object $wpdb Database object.
     * @param callable $callback Read to run.
     * @return mixed
     */
    private function quiet_db($wpdb, $callback)
    {
        $previous_show = null;
        $previous_suppress = null;
        $had_suppress = is_object($wpdb) && isset($wpdb->suppress_errors);
        if ($had_suppress) {
            $previous_suppress = $wpdb->suppress_errors;
        }
        if (is_object($wpdb) && method_exists($wpdb, 'hide_errors')) {
            $previous_show = $wpdb->hide_errors();
        }

        try {
            return $callback();
        } catch (\Throwable $throwable) {
            return null;
        } finally {
            // hide_errors() also suppresses later queries. Put that back.
            if ($had_suppress) {
                $wpdb->suppress_errors = $previous_suppress;
            }
            if ($previous_show && method_exists($wpdb, 'show_errors')) {
                $wpdb->show_errors();
            }
        }
    }

    /**
     * ASCII slug in lowercase. Other slugs are trimmed and otherwise kept.
     *
     * @param mixed $slug Raw slug.
     * @return string
     */
    private function normalized_slug($slug)
    {
        $slug = $this->string_value($slug);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $slug) === 1) {
            return strtolower($slug);
        }

        return $slug;
    }

    /**
     * Build one language item with the contract fields.
     *
     * An empty name uses the slug so the preview is not a blank row.
     *
     * @param string $slug Language slug.
     * @param string $name Language name.
     * @param string $locale WordPress locale.
     * @param bool $is_default Whether this is the default language.
     * @param int $term_id Language term id.
     * @param string $flag Flag code, or flag markup when no code is stored.
     * @return array
     */
    private function make_item($slug, $name, $locale, $is_default, $term_id, $flag)
    {
        $slug = $this->normalized_slug($slug);
        $name = $this->plain_name($name);
        if ($name === '') {
            $name = $slug;
        }

        $term_id = $this->int_value($term_id);
        if ($term_id < 0) {
            $term_id = 0;
        }

        return array(
            'slug' => $slug,
            'name' => $name,
            'locale' => $this->plain_locale($locale),
            'is_default' => $this->is_truthy_flag($is_default),
            'term_id' => $term_id,
            'flag' => $this->plain_flag($flag),
        );
    }

    /**
     * Decode a Polylang description or option value.
     *
     * Objects are not instantiated. A value that is not an array becomes an
     * empty array so missing rows do not throw.
     *
     * @param mixed $value Stored value.
     * @param int $depth Recursion guard.
     * @return array
     */
    private function decode_stored_array($value, $depth = 0)
    {
        if ($depth > 2) {
            return array();
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return array();
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 100000) {
            return array();
        }

        if (isset($value[0]) && $value[0] === '{') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : array();
        }

        // Do not use maybe_unserialize(). It can create objects from a
        // description. Only arrays and strings are accepted here.
        if (strpos($value, 'a:') !== 0 && strpos($value, 's:') !== 0) {
            return array();
        }

        $decoded = $this->unserialize_value($value);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_string($decoded)) {
            return $this->decode_stored_array($decoded, $depth + 1);
        }

        return array();
    }

    /**
     * Unserialize without creating objects.
     *
     * @param string $value Serialized value.
     * @return mixed
     */
    private function unserialize_value($value)
    {
        $decoded = false;
        set_error_handler(function () {
            return true;
        });

        try {
            $decoded = unserialize($value, array('allowed_classes' => false));
        } catch (\Throwable $throwable) {
            $decoded = false;
        } finally {
            restore_error_handler();
        }

        return $decoded;
    }

    /**
     * Term meta string, when WordPress can read term meta.
     *
     * @param int $term_id Term id.
     * @param string $key Meta key.
     * @return string
     */
    private function term_meta_string($term_id, $key)
    {
        if ($term_id <= 0 || !function_exists('get_term_meta')) {
            return '';
        }

        $value = get_term_meta($term_id, $key, true);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * WordPress database object, or null when it is not available.
     *
     * @return object|null
     */
    private function wpdb()
    {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->terms) || !isset($wpdb->term_taxonomy)) {
            return null;
        }

        return $wpdb;
    }

    /**
     * Read a field from an object or array.
     *
     * @param mixed $source Row.
     * @param string $field Field name.
     * @return mixed|null
     */
    private function read_field($source, $field)
    {
        if (is_object($source) && (isset($source->$field) || property_exists($source, $field))) {
            return $source->$field;
        }

        if (is_array($source) && array_key_exists($field, $source)) {
            return $source[$field];
        }

        return null;
    }

    /**
     * WordPress locale, or an empty string when the value is not a locale.
     *
     * Hyphens are stored as underscores, which is the form WordPress uses.
     *
     * @param mixed $locale Raw locale.
     * @return string
     */
    private function plain_locale($locale)
    {
        $locale = str_replace('-', '_', $this->string_value($locale));
        if ($locale === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]+)*$/', $locale) !== 1) {
            return '';
        }

        return $locale;
    }

    /**
     * Language name with entities decoded.
     *
     * Sorting uses the same text the preview shows.
     *
     * @param mixed $name Raw name.
     * @return string
     */
    private function plain_name($name)
    {
        $name = html_entity_decode($this->string_value($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $name);

        return is_string($name) ? $name : '';
    }

    /**
     * String value with surrounding space removed.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function string_value($value)
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        return '';
    }

    /**
     * Integer value, or zero when the value is not numeric.
     *
     * @param mixed $value Raw value.
     * @return int
     */
    private function int_value($value)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Whether a stored flag means the language is the default.
     *
     * Polylang uses booleans. Some copies of the data use 1, "1", or "true".
     *
     * @param mixed $value Raw flag.
     * @return bool
     */
    private function is_truthy_flag($value)
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        return is_string($value) && strcasecmp($value, 'true') === 0;
    }

    /**
     * Whether a stored flag means the language is not the default.
     *
     * @param mixed $value Raw flag.
     * @return bool
     */
    private function is_false_flag($value)
    {
        if ($value === false || $value === 0 || $value === '0') {
            return true;
        }

        return is_string($value) && strcasecmp($value, 'false') === 0;
    }

    /**
     * Whether a translation-group key can be a language slug.
     *
     * @param mixed $slug Candidate key.
     * @return bool
     */
    private function is_language_slug($slug)
    {
        return is_string($slug) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,199}$/', $slug) === 1;
    }

    /**
     * Flag code, URL, or markup. Script and data URLs are dropped.
     *
     * @param mixed $flag Raw flag.
     * @return string
     */
    private function plain_flag($flag)
    {
        $flag = $this->normalize_flag($flag);
        if ($flag === '' || $this->is_flag_code($flag)) {
            return $flag;
        }
        if (preg_match('/javascript\s*:/i', $flag) === 1 || preg_match('/^(data|vbscript):/i', $flag) === 1) {
            return '';
        }

        return $flag;
    }

    /**
     * Flag code in the form Polylang stores, or the original markup or URL.
     *
     * @param mixed $value Raw flag value.
     * @return string
     */
    private function normalize_flag($value)
    {
        $flag = $this->string_value($value);
        if ($flag === '') {
            return '';
        }

        $code = strtolower($flag);
        if ($this->is_flag_code($code)) {
            return $code;
        }

        return $flag;
    }

    /**
     * Whether a flag value is a stored flag code rather than markup or a URL.
     *
     * @param string $flag Flag value.
     * @return bool
     */
    private function is_flag_code($flag)
    {
        return is_string($flag) && preg_match('/^[a-z0-9_-]{1,32}$/', $flag) === 1;
    }

    /**
     * Prefer a stored flag code over markup or a URL.
     *
     * @param string $preferred Current flag.
     * @param string $fallback Stored flag.
     * @return bool
     */
    private function should_replace_flag($preferred, $fallback)
    {
        if ($fallback === '') {
            return false;
        }
        if ($preferred === '') {
            return true;
        }
        return $this->is_flag_code($fallback) && !$this->is_flag_code($preferred);
    }

    /**
     * String from a plucked API column.
     *
     * @param array $column Column values.
     * @param int $index Row index.
     * @return string
     */
    private function column_string($column, $index)
    {
        if (!isset($column[$index]) || !is_string($column[$index])) {
            return '';
        }
        return trim($column[$index]);
    }

    /**
     * Whether the first API row is a language record.
     *
     * @param array $raw API response.
     * @return bool
     */
    private function list_is_records($raw)
    {
        foreach ($raw as $row) {
            if (is_object($row) || is_array($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Envelope returned by export().
     *
     * @param array $items Language items.
     * @param string[] $errors Site-owner messages.
     * @return array
     */
    private function envelope($items, $errors)
    {
        $clean_errors = array();
        foreach ($errors as $error) {
            if (is_string($error) && $error !== '') {
                $clean_errors[] = $error;
            }
        }

        return array(
            'items' => array_values($items),
            'errors' => array_values($clean_errors),
        );
    }

    /**
     * Polylang is not loaded and no languages were stored.
     *
     * @return string
     */
    private function message_inactive()
    {
        if (!function_exists('__')) {
            return 'Polylang is not active on this site, so there are no languages to migrate. Activate Polylang if the languages are still installed, then preview again.';
        }

        return __('Polylang is not active on this site, so there are no languages to migrate. Activate Polylang if the languages are still installed, then preview again.', 'migration-manager');
    }

    /**
     * Polylang is loaded and the language list is empty.
     *
     * @return string
     */
    private function message_active_empty()
    {
        if (!function_exists('__')) {
            return 'Polylang is active, but no languages were found. Add a language under Languages in Polylang, then preview the migration again.';
        }

        return __('Polylang is active, but no languages were found. Add a language under Languages in Polylang, then preview the migration again.', 'migration-manager');
    }

    /**
     * The language list could not be read.
     *
     * @return string
     */
    private function message_failed()
    {
        if (!function_exists('__')) {
            return 'Polylang languages could not be read. Nothing was changed. Preview the migration again.';
        }

        return __('Polylang languages could not be read. Nothing was changed. Preview the migration again.', 'migration-manager');
    }
}

}
