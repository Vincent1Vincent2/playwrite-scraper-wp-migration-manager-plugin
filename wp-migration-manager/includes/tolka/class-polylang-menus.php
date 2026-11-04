<?php

/**
 * Polylang navigation menu export for the Tolka migration.
 *
 * Each exported item is one theme location. `label` is the theme's name for
 * that location, or a sentence when the menu is not assigned. `translations`
 * maps a language slug to `menu_id`, `name`, `slug`, `link_count`, and
 * `items`. Locations with no menu use menu id 0. Menu links are a tree:
 * each item's `children` list holds its submenu. A language switcher has
 * `switcher` set, and the title "Languages" when none was stored.
 *
 * Polylang keeps assignments on the `polylang` option at
 * nav_menus[stylesheet][location][language] = menu term id. When the
 * Polylang functions are loaded they are used. Otherwise the same option,
 * the language taxonomies, and the menu tables are read directly.
 * Nothing is deleted or updated.
 *
 * The file does not exit when ABSPATH is missing. The shared test bootstrap
 * loads every class in this directory without a full WordPress install.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

/**
 * Reads Polylang menus into the Tolka package shape.
 */
class Polylang_Menus
{
    /**
     * Warnings collected for the current export.
     *
     * @var string[]
     */
    private $warnings = array();

    /**
     * How many missing-menu sentences have been recorded.
     *
     * @var int
     */
    private $missing_reports = 0;

    /**
     * Menu term id => array{name: string, slug: string}, or null until loaded.
     *
     * @var array<int, array{name: string, slug: string}>|null
     */
    private $catalog = null;

    /**
     * Registered location slug => human label.
     *
     * @var array<string, string>
     */
    private $location_labels = array();

    /**
     * Export every theme location and any menus that are not assigned.
     *
     * @return array{items: array<int, array>, errors: string[]}
     */
    public static function export()
    {
        $exporter = new static();
        return $exporter->collect();
    }

    /**
     * Build the exporter envelope.
     *
     * @return array{items: array<int, array>, errors: string[]}
     */
    protected function collect()
    {
        $this->warnings = array();
        $this->missing_reports = 0;
        $this->catalog = null;
        $this->location_labels = array();

        try {
            if (! $this->wordpress_loaded()) {
                return array(
                    'items' => array(),
                    'errors' => array($this->message_wordpress_missing()),
                );
            }

            $items = $this->build_items();
            $errors = $this->warnings;
            if (! $this->polylang_active() && ! $this->has_assigned_menu($items)) {
                if (empty($items)) {
                    $errors[] = $this->message_inactive_empty();
                } else {
                    $errors[] = $this->message_inactive_locations_only();
                }
            }

            return array(
                'items' => $items,
                'errors' => array_values($errors),
            );
        } catch (\Throwable $exception) {
            unset($exception);
            return array(
                'items' => array(),
                'errors' => array($this->message_read_failed()),
            );
        }
    }

    /**
     * Whether the WordPress option or database API can be called.
     *
     * @return bool
     */
    protected function wordpress_loaded()
    {
        return function_exists('get_option') || $this->db() !== null;
    }

    /**
     * Whether Polylang is loaded in this request.
     *
     * @return bool
     */
    protected function polylang_active()
    {
        try {
            return function_exists('pll_languages_list')
                || class_exists('PLL')
                || class_exists('Polylang');
        } catch (\Throwable $exception) {
            unset($exception);
            return false;
        }
    }

    /**
     * Polylang option array, or an empty array when it is missing.
     *
     * @return array
     */
    protected function read_polylang_options()
    {
        if (function_exists('PLL')) {
            try {
                $pll = PLL();
            } catch (\Throwable $exception) {
                unset($exception);
                $pll = null;
            }
            if (is_object($pll) && isset($pll->options) && is_array($pll->options)) {
                return $pll->options;
            }
        }

        if (function_exists('get_option')) {
            $options = get_option('polylang');
            if (is_array($options)) {
                return $options;
            }
        }

        $stored = $this->option_value_from_db('polylang');
        if (is_string($stored)) {
            $decoded = $this->unserialize_array($stored);
            if (! empty($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * Active theme stylesheet, used as the Polylang nav_menus key.
     *
     * @return string
     */
    protected function read_stylesheet()
    {
        if (function_exists('get_stylesheet')) {
            $stylesheet = get_stylesheet();
            if (is_string($stylesheet) && $stylesheet !== '') {
                return $stylesheet;
            }
        }

        if (function_exists('get_option')) {
            $stylesheet = get_option('stylesheet');
            if (is_string($stylesheet) && $stylesheet !== '') {
                return $stylesheet;
            }
        }

        $stored = $this->option_value_from_db('stylesheet');
        return is_string($stored) ? $stored : '';
    }

    /**
     * Theme locations that exist even when no menu is assigned.
     *
     * Combined Polylang customizer keys such as `primary___sv` collapse
     * back to `primary` so they are not exported as their own location.
     *
     * @return array<string, string> Slug => label.
     */
    protected function read_registered_locations()
    {
        if (! function_exists('get_registered_nav_menus')) {
            return array();
        }

        $registered = get_registered_nav_menus();
        if (! is_array($registered)) {
            return array();
        }

        $locations = array();
        foreach ($registered as $slug => $label) {
            $split = $this->split_location_key($slug);
            $location = $split['location'];
            if ($location === '' || isset($locations[$location])) {
                continue;
            }
            $locations[$location] = $this->text($label);
        }

        return $locations;
    }

    /**
     * Raw `nav_menu_locations` theme mod, without Polylang's current-language filter.
     *
     * @return array<string, mixed>
     */
    protected function read_theme_menu_locations()
    {
        $stylesheet = $this->read_stylesheet();
        if ($stylesheet !== '' && function_exists('get_option')) {
            $mods = get_option('theme_mods_' . $stylesheet);
            if (is_array($mods) && isset($mods['nav_menu_locations']) && is_array($mods['nav_menu_locations'])) {
                return $mods['nav_menu_locations'];
            }
        }

        if ($stylesheet !== '') {
            $stored = $this->option_value_from_db('theme_mods_' . $stylesheet);
            if (is_string($stored)) {
                $mods = $this->unserialize_array($stored);
                if (isset($mods['nav_menu_locations']) && is_array($mods['nav_menu_locations'])) {
                    return $mods['nav_menu_locations'];
                }
            }
        }

        if (function_exists('get_nav_menu_locations')) {
            $locations = get_nav_menu_locations();
            if (is_array($locations)) {
                return $locations;
            }
        }

        return array();
    }

    /**
     * Every nav menu term, including languages other than the current one.
     *
     * @return array<int, array<string, mixed>|object>
     */
    protected function read_nav_menus()
    {
        if ($this->tables() !== null) {
            return $this->nav_menus_from_db();
        }

        return $this->nav_menus_from_api();
    }

    /**
     * Language slugs in Polylang's stored order.
     *
     * @return string[]
     */
    protected function read_language_slugs()
    {
        if (function_exists('pll_languages_list')) {
            $list = pll_languages_list(array('fields' => 'slug'));
            if (is_array($list)) {
                return $list;
            }
        }

        if ($this->tables() !== null) {
            $rows = $this->db_get(
                'SELECT t.slug AS slug FROM ' . $this->tables()['terms'] . ' t'
                . ' INNER JOIN ' . $this->tables()['term_taxonomy'] . ' tt ON tt.term_id = t.term_id'
                . " WHERE tt.taxonomy = 'language' ORDER BY t.term_group ASC, t.term_id ASC",
                array()
            );
            if (! empty($rows)) {
                $slugs = array();
                foreach ($rows as $row) {
                    if (isset($row['slug'])) {
                        $slugs[] = $row['slug'];
                    }
                }
                return $slugs;
            }
        }

        if (function_exists('get_terms')) {
            $terms = get_terms(array(
                'taxonomy' => 'language',
                'hide_empty' => false,
                'orderby' => 'term_group',
                'lang' => '',
            ));
            if (function_exists('is_wp_error') && is_wp_error($terms)) {
                return array();
            }
            if (is_array($terms)) {
                $slugs = array();
                foreach ($terms as $term) {
                    if (is_object($term) && isset($term->slug)) {
                        $slugs[] = $term->slug;
                    } elseif (is_array($term) && isset($term['slug'])) {
                        $slugs[] = $term['slug'];
                    }
                }
                return $slugs;
            }
        }

        return array();
    }

    /**
     * Default language slug, or an empty string when it is not stored.
     *
     * @return string
     */
    protected function read_default_language()
    {
        if (function_exists('pll_default_language')) {
            $language = pll_default_language('slug');
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        $options = $this->read_polylang_options();
        if (isset($options['default_lang']) && is_string($options['default_lang'])) {
            return $options['default_lang'];
        }

        return '';
    }

    /**
     * Language slug of a menu term.
     *
     * @param int $term_id Menu term id.
     * @return string
     */
    protected function read_term_language($term_id)
    {
        $term_id = $this->to_int($term_id);
        if ($term_id === 0) {
            return '';
        }

        if (function_exists('pll_get_term_language')) {
            $language = pll_get_term_language($term_id, 'slug');
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        $from_terms = $this->language_slug_from_relationships($term_id, 'term_language');
        if ($from_terms !== '') {
            return $from_terms;
        }

        return $this->language_slug_from_relationships($term_id, 'language');
    }

    /**
     * Translation group for a menu term: language slug => menu term id.
     *
     * @param int $term_id Menu term id.
     * @return array<string, int>
     */
    protected function read_term_translations($term_id)
    {
        $term_id = $this->to_int($term_id);
        if ($term_id === 0) {
            return array();
        }

        if (function_exists('pll_get_term_translations')) {
            $translations = pll_get_term_translations($term_id);
            if (is_array($translations)) {
                return $this->normalize_id_map($translations);
            }
        }

        $groups = $this->object_terms($term_id, 'term_translations');
        foreach ($groups as $group) {
            $description = isset($group['description']) ? $group['description'] : '';
            $map = $this->unserialize_array($description);
            if (! empty($map)) {
                return $this->normalize_id_map($map);
            }
        }

        return array();
    }

    /**
     * How many links in this menu are not published and were left out.
     *
     * Trash and auto-drafts are ignored. The count is 0 when the database
     * tables are not available.
     *
     * @param int $menu_id Menu term id.
     * @return int
     */
    protected function read_skipped_item_count($menu_id)
    {
        $menu_id = $this->to_int($menu_id);
        $tables = $this->tables();
        if ($menu_id === 0 || $tables === null) {
            return 0;
        }

        $rows = $this->db_get(
            'SELECT COUNT(*) AS total FROM ' . $tables['posts'] . ' p'
            . ' INNER JOIN ' . $tables['term_relationships'] . ' tr ON tr.object_id = p.ID'
            . ' INNER JOIN ' . $tables['term_taxonomy'] . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . " WHERE tt.taxonomy = 'nav_menu' AND tt.term_id = %d"
            . " AND p.post_type = 'nav_menu_item'"
            . " AND p.post_status NOT IN ('publish', 'trash', 'auto-draft')",
            array($menu_id)
        );
        if (! isset($rows[0]['total'])) {
            return 0;
        }
        return $this->to_int($rows[0]['total']);
    }

    /**
     * Flat menu links for one menu. Nesting is applied by the exporter.
     *
     * @param int $menu_id Menu term id.
     * @return array<int, mixed>
     */
    protected function read_menu_items($menu_id)
    {
        $menu_id = $this->to_int($menu_id);
        if ($menu_id === 0) {
            return array();
        }

        if ($this->tables() !== null) {
            return $this->menu_items_from_db($menu_id);
        }

        return $this->menu_items_from_api($menu_id);
    }

    /**
     * Menu name, or null when that menu term does not exist.
     *
     * @param int $menu_id Menu term id.
     * @return string|null
     */
    protected function read_menu_name($menu_id)
    {
        $menu_id = $this->to_int($menu_id);
        if ($menu_id === 0) {
            return null;
        }

        if ($this->tables() !== null) {
            return $this->menu_name_from_db($menu_id);
        }

        return $this->menu_name_from_api($menu_id);
    }

    /**
     * Locations first, then menus that are not assigned to a location.
     *
     * @return array<int, array>
     */
    private function build_items()
    {
        $languages = $this->language_slugs();
        $registered = $this->normalize_registered_locations($this->read_registered_locations());
        $this->location_labels = $registered;

        $assignments = $this->saved_assignments();
        $slugs = array_keys($registered);
        foreach (array_keys($assignments) as $slug) {
            if (! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        $items = array();
        $used = array();
        foreach ($slugs as $slug) {
            $by_lang = isset($assignments[$slug]) && is_array($assignments[$slug])
                ? $assignments[$slug]
                : array();
            $item = $this->location_item((string) $slug, $by_lang, $languages);
            foreach ($item['translations'] as $translation) {
                if (! empty($translation['menu_id'])) {
                    $used[(int) $translation['menu_id']] = true;
                }
            }
            $items[] = $item;
        }

        $groups = $this->unassigned_groups($used);
        foreach ($groups as $group) {
            $translations = array();
            foreach ($group as $language => $menu_id) {
                $payload = $this->translation_payload($menu_id, '', $language);
                if ($payload['menu_id'] === 0) {
                    continue;
                }
                $translations[$language] = $payload;
            }
            if (empty($translations)) {
                continue;
            }
            $items[] = array(
                'location' => '',
                'label' => $this->location_label(''),
                'translations' => $translations,
            );
        }

        return $items;
    }

    /**
     * Collapse Polylang's combined customizer keys back to real theme locations.
     *
     * @param mixed $registered Slug => label, or anything the caller returned.
     * @return array<string, string>
     */
    private function normalize_registered_locations($registered)
    {
        if (! is_array($registered)) {
            return array();
        }

        $locations = array();
        foreach ($registered as $slug => $label) {
            $split = $this->split_location_key($slug);
            $location = $split['location'];
            if ($location === '' || isset($locations[$location])) {
                continue;
            }
            $locations[$location] = $this->text($label);
        }

        return $locations;
    }

    /**
     * One location, with a slot for every known language.
     *
     * A language with no menu uses menu id 0, an empty name, and an empty
     * item list so the preview can show the empty assignment.
     *
     * @param string               $location  Location slug.
     * @param array<string, mixed> $by_lang   Language => menu id.
     * @param string[]             $languages Known language slugs.
     * @return array
     */
    private function location_item($location, array $by_lang, array $languages)
    {
        $langs = $languages;
        foreach (array_keys($by_lang) as $language) {
            $language = $this->normalize_lang_slug($language);
            if ($language !== '' && ! in_array($language, $langs, true)) {
                $langs[] = $language;
            }
        }

        $translations = array();
        foreach ($langs as $language) {
            $menu_id = isset($by_lang[$language]) ? $this->to_int($by_lang[$language]) : 0;
            $translations[$language] = $this->translation_payload($menu_id, $location, $language);
        }

        return array(
            'location' => $location,
            'label' => $this->location_label($location),
            'translations' => $translations,
        );
    }

    /**
     * Menu id, name, slug, and nested items for one language slot.
     *
     * @param int    $menu_id  Menu term id. 0 means the location has no menu.
     * @param string $location Location slug, or an empty string.
     * @param string $language Language slug.
     * @return array{menu_id: int, name: string, slug: string, items: array}
     */
    private function translation_payload($menu_id, $location, $language)
    {
        $menu_id = $this->to_int($menu_id);
        if ($menu_id === 0) {
            return array(
                'menu_id' => 0,
                'name' => '',
                'slug' => '',
                'items' => array(),
                'link_count' => 0,
            );
        }

        $catalog = $this->catalog();
        $name = null;
        $slug = '';
        if (isset($catalog[$menu_id])) {
            $name = $catalog[$menu_id]['name'];
            $slug = $catalog[$menu_id]['slug'];
        } else {
            $loaded = $this->read_menu_name($menu_id);
            if (is_string($loaded)) {
                $name = $loaded;
            }
        }

        $flat = $this->read_menu_items($menu_id);
        if (! is_array($flat)) {
            $flat = array();
        }
        $items = $this->nest_items($flat);
        $skipped = $this->read_skipped_item_count($menu_id);
        if ($skipped > 0) {
            $menu_label = $name !== null && $name !== '' ? $this->entity_text($name) : ('menu ' . $menu_id);
            $this->warn($this->message_skipped_items($skipped, $menu_label, $language));
        }

        if ($name === null && empty($items)) {
            $this->warn_missing_menu($menu_id, $location, $language);
            $name = '';
        }
        if (! is_string($name)) {
            $name = '';
        }

        return array(
            'menu_id' => $menu_id,
            'name' => $this->entity_text($name),
            'slug' => $slug,
            'items' => $items,
            'link_count' => $this->link_count($items),
        );
    }

    /**
     * Human label for a theme location, or a sentence for menus with no location.
     *
     * @param string $location Location slug.
     * @return string
     */
    private function location_label($location)
    {
        if ($location === '') {
            return $this->message_unassigned_location();
        }
        if (isset($this->location_labels[$location]) && $this->location_labels[$location] !== '') {
            return $this->location_labels[$location];
        }
        return $location;
    }

    /**
     * Count this menu's links, including links nested in submenus.
     *
     * @param array<int, array> $items Nested items.
     * @return int
     */
    private function link_count(array $items)
    {
        $count = 0;
        foreach ($items as $item) {
            $count++;
            if (! empty($item['children']) && is_array($item['children'])) {
                $count += $this->link_count($item['children']);
            }
        }
        return $count;
    }

    /**
     * Assignments from the Polylang option, or the theme mod when that option has no theme entry.
     *
     * @return array<string, array<string, int>>
     */
    private function saved_assignments()
    {
        $options = $this->read_polylang_options();
        if (! is_array($options)) {
            $options = array();
        }
        if (array_key_exists('nav_menus', $options) && ! is_array($options['nav_menus'])) {
            $this->warn($this->message_corrupt_nav_menus());
        }

        $nav = (isset($options['nav_menus']) && is_array($options['nav_menus']))
            ? $options['nav_menus']
            : array();
        $theme = $this->read_stylesheet();
        $theme_is_known = $theme !== '' && isset($nav[$theme]) && is_array($nav[$theme]);

        $assignments = array();
        if ($theme_is_known) {
            $this->absorb_location_map($assignments, $nav[$theme]);
        } else {
            $this->absorb_theme_maps($assignments, $nav);
        }

        if (! $theme_is_known && empty($assignments)) {
            $raw = $this->read_theme_menu_locations();
            if (is_array($raw)) {
                $this->absorb_location_map($assignments, $raw);
            }
        }

        $this->fill_locations_from_theme_mod($assignments);
        $this->expand_with_translations($assignments);
        return $assignments;
    }

    /**
     * Add theme-mod locations that Polylang's map never mentioned.
     *
     * Existing locations are left alone, including a language slot that was
     * saved as "no menu".
     *
     * @param array<string, array<string, int>> $assignments Location => language => menu id.
     * @return void
     */
    private function fill_locations_from_theme_mod(array &$assignments)
    {
        $raw = $this->read_theme_menu_locations();
        if (! is_array($raw) || empty($raw)) {
            return;
        }

        $known = array_keys($assignments);
        $extra = array();
        foreach ($raw as $key => $value) {
            $split = $this->split_location_key($key);
            if ($split['location'] === '') {
                continue;
            }
            if (! in_array($split['location'], $known, true)) {
                $extra[$key] = $value;
                continue;
            }
            // primary___sv can supply a language the Polylang map never stored.
            // A bare primary value is not allowed to replace the saved menu.
            if ($split['lang'] !== '' && ! isset($assignments[$split['location']][$split['lang']])) {
                $extra[$key] = $value;
            }
        }
        if (! empty($extra)) {
            $this->absorb_location_map($assignments, $extra);
        }
    }

    /**
     * Keep every theme's locations when the active theme has no saved map.
     *
     * A location slug used by one theme stays as that slug. The same slug in
     * two themes becomes `stylesheet:location` so neither menu is dropped.
     *
     * @param array<string, array<string, int>> $assignments Location => language => menu id.
     * @param array<string, mixed>              $nav         Theme => location map.
     * @return void
     */
    private function absorb_theme_maps(array &$assignments, array $nav)
    {
        $per_theme = array();
        foreach ($nav as $theme_slug => $locations) {
            if (! is_array($locations)) {
                continue;
            }
            $theme_slug = $this->text($theme_slug);
            if ($theme_slug === '') {
                $theme_slug = 'theme';
            }
            if (! isset($per_theme[$theme_slug])) {
                $per_theme[$theme_slug] = array();
            }
            $this->absorb_location_map($per_theme[$theme_slug], $locations);
        }

        $slug_themes = array();
        foreach ($per_theme as $theme_slug => $map) {
            foreach (array_keys($map) as $location) {
                if (! isset($slug_themes[$location])) {
                    $slug_themes[$location] = array();
                }
                $slug_themes[$location][] = $theme_slug;
            }
        }

        foreach ($per_theme as $theme_slug => $map) {
            foreach ($map as $location => $slots) {
                $key = $location;
                if (isset($slug_themes[$location]) && count($slug_themes[$location]) > 1) {
                    $key = $theme_slug . ':' . $location;
                    $base = isset($this->location_labels[$location]) && $this->location_labels[$location] !== ''
                        ? $this->location_labels[$location]
                        : $location;
                    $this->location_labels[$key] = $base . ' (' . $theme_slug . ')';
                }
                if (! isset($assignments[$key])) {
                    $assignments[$key] = $slots;
                }
            }
        }
    }

    /**
     * Merge a Polylang location map or a flat theme-mod map into assignments.
     *
     * Explicit zeros are kept. An explicit empty language is not replaced later
     * by a translation sibling.
     *
     * @param array<string, array<string, int>> $assignments Location => language => menu id.
     * @param array<string, mixed>              $locations   Raw map.
     * @return void
     */
    private function absorb_location_map(array &$assignments, array $locations)
    {
        foreach ($locations as $key => $value) {
            $split = $this->split_location_key($key);
            $location = $split['location'];
            $language_from_key = $split['lang'];
            if ($location === '') {
                continue;
            }
            if (! isset($assignments[$location]) || ! is_array($assignments[$location])) {
                $assignments[$location] = array();
            }

            if (is_array($value)) {
                foreach ($value as $language => $menu_id) {
                    if (is_array($menu_id)) {
                        continue;
                    }
                    $language = $this->normalize_lang_slug($language);
                    if ($language === '') {
                        $language = $language_from_key;
                    }
                    $this->assign_menu($assignments[$location], $language, $menu_id);
                }
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $this->assign_menu($assignments[$location], $language_from_key, $value);
        }
    }

    /**
     * Record one language slot without overwriting an earlier assignment.
     *
     * @param array<string, int> $slots    Language => menu id.
     * @param string             $language Language slug. Resolved when empty.
     * @param mixed              $menu_id  Raw menu id.
     * @return void
     */
    private function assign_menu(array &$slots, $language, $menu_id)
    {
        $menu_id = $this->to_int($menu_id);
        $language = $this->normalize_lang_slug($language);
        if ($language === '' && $menu_id > 0) {
            $language = $this->normalize_lang_slug($this->language_of_menu($menu_id));
        }
        if ($language === '') {
            $language = $this->normalize_lang_slug($this->read_default_language());
        }
        if ($language === '' && $menu_id > 0) {
            $language = 'unknown';
            $this->warn($this->message_unknown_language());
        }
        if ($language === '') {
            return;
        }
        if (! isset($slots[$language])) {
            $slots[$language] = $menu_id;
        }
    }

    /**
     * Add translation siblings that are not already assigned to a location.
     *
     * @param array<string, array<string, int>> $assignments Location => language => menu id.
     * @return void
     */
    private function expand_with_translations(array &$assignments)
    {
        $used = array();
        foreach ($assignments as $slots) {
            foreach ($slots as $menu_id) {
                $menu_id = $this->to_int($menu_id);
                if ($menu_id > 0) {
                    $used[$menu_id] = true;
                }
            }
        }

        foreach ($assignments as $location => $slots) {
            foreach ($slots as $menu_id) {
                $menu_id = $this->to_int($menu_id);
                if ($menu_id === 0) {
                    continue;
                }
                $group = $this->read_term_translations($menu_id);
                if (! is_array($group)) {
                    continue;
                }
                foreach ($group as $language => $sibling_id) {
                    $language = $this->normalize_lang_slug($language);
                    $sibling_id = $this->to_int($sibling_id);
                    if ($language === '' || $sibling_id === 0) {
                        continue;
                    }
                    if (isset($assignments[$location][$language])) {
                        continue;
                    }
                    if (isset($used[$sibling_id])) {
                        continue;
                    }
                    $assignments[$location][$language] = $sibling_id;
                    $used[$sibling_id] = true;
                }
            }
        }
    }

    /**
     * Menus that are not sitting in a location, grouped by translation.
     *
     * @param array<int, bool> $used Menu ids already exported.
     * @return array<int, array<string, int>>
     */
    private function unassigned_groups(array $used)
    {
        $groups = array();
        foreach ($this->catalog() as $menu_id => $menu) {
            unset($menu);
            if (isset($used[$menu_id])) {
                continue;
            }
            $translations = $this->read_term_translations($menu_id);
            $ids = array($menu_id);
            if (is_array($translations)) {
                foreach ($translations as $id) {
                    $id = $this->to_int($id);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            $key = (string) $ids[0];
            if (! isset($groups[$key])) {
                $groups[$key] = array();
            }

            $language = $this->normalize_lang_slug($this->language_of_menu($menu_id));
            if ($language === '' && is_array($translations)) {
                foreach ($translations as $slug => $id) {
                    if ($this->to_int($id) === $menu_id) {
                        $language = $this->normalize_lang_slug($slug);
                        break;
                    }
                }
            }
            if ($language === '') {
                $language = 'unknown';
                $this->warn($this->message_unknown_language());
            }
            if (! isset($groups[$key][$language])) {
                $groups[$key][$language] = $menu_id;
            }
        }

        ksort($groups, SORT_NATURAL);
        return array_values($groups);
    }

    /**
     * Language of a menu term, from the API, the term language, or its group.
     *
     * @param int $menu_id Menu term id.
     * @return string
     */
    private function language_of_menu($menu_id)
    {
        $menu_id = $this->to_int($menu_id);
        if ($menu_id === 0) {
            return '';
        }

        $language = $this->normalize_lang_slug($this->read_term_language($menu_id));
        if ($language !== '') {
            return $language;
        }

        $group = $this->read_term_translations($menu_id);
        if (is_array($group)) {
            foreach ($group as $slug => $id) {
                if ($this->to_int($id) === $menu_id) {
                    return $this->normalize_lang_slug($slug);
                }
            }
        }

        return '';
    }

    /**
     * Known languages with the default language first.
     *
     * @return string[]
     */
    private function language_slugs()
    {
        $slugs = array();
        $listed = $this->read_language_slugs();
        if (is_array($listed)) {
            foreach ($listed as $slug) {
                $slug = $this->normalize_lang_slug($slug);
                if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
        }

        $default = $this->normalize_lang_slug($this->read_default_language());
        if ($default !== '') {
            $slugs = array_values(array_diff($slugs, array($default)));
            array_unshift($slugs, $default);
        }

        return $slugs;
    }

    /**
     * Turn a flat, possibly unordered item list into a tree.
     *
     * Items whose parent is missing, and items that point at each other in a
     * cycle, are kept. Cycles are broken so the export cannot recurse forever.
     * Sibling order follows menu position, then item id, then the original order.
     *
     * @param array<int, mixed> $flat Raw items.
     * @return array<int, array>
     */
    private function nest_items(array $flat)
    {
        $normalized = array();
        $position = 1;
        foreach ($flat as $item) {
            $ready = $this->normalize_item($item, $position);
            if ($ready === null) {
                continue;
            }
            if ($ready['parent'] === $ready['id'] && $ready['id'] > 0) {
                $ready['parent'] = 0;
            }
            $normalized[] = $ready;
            $position++;
        }

        $by_parent = array();
        foreach ($normalized as $item) {
            $parent = $item['parent'];
            if (! isset($by_parent[$parent])) {
                $by_parent[$parent] = array();
            }
            $by_parent[$parent][] = $item;
        }

        $visited = array();
        $build = function ($parent_id) use (&$build, &$by_parent, &$visited) {
            if (empty($by_parent[$parent_id])) {
                return array();
            }
            $nodes = array();
            foreach ($by_parent[$parent_id] as $item) {
                $id = $item['id'];
                if ($id > 0) {
                    if (isset($visited[$id])) {
                        continue;
                    }
                    $visited[$id] = true;
                    $item['children'] = $this->sort_siblings($build($id));
                } else {
                    $item['children'] = array();
                }
                $nodes[] = $item;
            }
            return $nodes;
        };

        $tree = $build(0);
        foreach ($normalized as $item) {
            $id = $item['id'];
            if ($id > 0 && isset($visited[$id])) {
                continue;
            }
            if ($id > 0) {
                $visited[$id] = true;
                $item['children'] = $this->sort_siblings($build($id));
            } else {
                $item['children'] = array();
            }
            $tree[] = $item;
        }

        return $this->sort_siblings($tree);
    }

    /**
     * Stable sibling order.
     *
     * @param array<int, array> $nodes Nodes.
     * @return array<int, array>
     */
    private function sort_siblings(array $nodes)
    {
        $indexed = array();
        $index = 0;
        foreach ($nodes as $node) {
            $indexed[] = array($node, $index);
            $index++;
        }

        usort($indexed, function ($left, $right) {
            if ($left[0]['position'] !== $right[0]['position']) {
                return $left[0]['position'] < $right[0]['position'] ? -1 : 1;
            }
            if ($left[0]['id'] !== $right[0]['id']) {
                return $left[0]['id'] < $right[0]['id'] ? -1 : 1;
            }
            if ($left[1] === $right[1]) {
                return 0;
            }
            return $left[1] < $right[1] ? -1 : 1;
        });

        $sorted = array();
        foreach ($indexed as $pair) {
            $sorted[] = $pair[0];
        }
        return $sorted;
    }

    /**
     * Contract fields plus the fields needed to rebuild the menu.
     *
     * @param mixed $item              Raw item.
     * @param int   $fallback_position Position used when the source has none.
     * @return array|null
     */
    private function normalize_item($item, $fallback_position)
    {
        if (is_object($item)) {
            $source = get_object_vars($item);
        } elseif (is_array($item)) {
            $source = $item;
        } else {
            return null;
        }

        $normalized = array(
            'id' => $this->first_set($source, array('id', 'ID', 'db_id'), 0),
            'parent' => $this->first_set($source, array('parent', 'menu_item_parent'), 0),
            'title' => $this->first_set($source, array('title'), ''),
            'url' => $this->first_set($source, array('url'), ''),
            'object' => $this->first_set($source, array('object'), ''),
            'object_id' => $this->first_set($source, array('object_id'), 0),
            'type' => $this->first_set($source, array('type'), ''),
            'attr_title' => $this->first_set($source, array('attr_title', 'post_excerpt'), ''),
            'description' => $this->first_set($source, array('description', 'post_content'), ''),
            'target' => $this->first_set($source, array('target'), ''),
            'classes' => array_key_exists('classes', $source) ? $source['classes'] : array(),
            'xfn' => $this->first_set($source, array('xfn'), ''),
            'position' => $this->first_set($source, array('position', 'menu_order'), $fallback_position),
        );

        if ($this->text($normalized['title']) === '' && isset($source['post_title'])) {
            $normalized['title'] = $source['post_title'];
        }

        $normalized['id'] = $this->to_int($normalized['id']);
        $normalized['parent'] = $this->to_int($normalized['parent']);
        $normalized['object_id'] = $this->to_int($normalized['object_id']);
        $normalized['position'] = $this->to_int($normalized['position']);
        if ($normalized['position'] === 0) {
            $normalized['position'] = $this->to_int($fallback_position);
        }
        $normalized['title'] = $this->entity_text($normalized['title']);
        $normalized['attr_title'] = $this->entity_text($normalized['attr_title']);
        $normalized['description'] = $this->entity_text($normalized['description']);
        $normalized['url'] = trim($this->text($normalized['url']));
        $normalized['object'] = $this->text($normalized['object']);
        $normalized['type'] = $this->text($normalized['type']);
        $normalized['target'] = $this->text($normalized['target']);
        $normalized['xfn'] = $this->text($normalized['xfn']);
        $normalized['classes'] = $this->normalize_classes($normalized['classes']);
        if ($normalized['object'] === '' && $normalized['type'] !== '') {
            $normalized['object'] = $normalized['type'];
        }

        if ($normalized['title'] === '' && $normalized['object_id'] > 0 && $normalized['type'] === 'post_type') {
            $normalized['title'] = $this->entity_text($this->lookup_post_title($normalized['object_id']));
        }
        if ($normalized['title'] === '' && $normalized['object_id'] > 0 && $normalized['type'] === 'taxonomy') {
            $normalized['title'] = $this->entity_text($this->lookup_term_name($normalized['object_id']));
        }
        if ($normalized['url'] === '') {
            $normalized['url'] = $this->resolve_url($normalized);
        }
        $normalized['switcher'] = ($normalized['url'] === '#pll_switcher');
        if ($normalized['title'] === '' && $normalized['switcher']) {
            $normalized['title'] = $this->message_switcher_title();
        }

        return $normalized;
    }

    /**
     * Permalink for a post or term item when WordPress can resolve it.
     *
     * @param array $item Normalized item with an empty url.
     * @return string
     */
    private function resolve_url(array $item)
    {
        if ($item['type'] === 'post_type' && $item['object_id'] > 0 && function_exists('get_permalink')) {
            $url = get_permalink($item['object_id']);
            if (is_string($url)) {
                return $url;
            }
        }

        if ($item['type'] === 'taxonomy' && $item['object_id'] > 0 && function_exists('get_term_link')) {
            $taxonomy = $item['object'] !== '' ? $item['object'] : '';
            $link = $taxonomy !== ''
                ? get_term_link($item['object_id'], $taxonomy)
                : get_term_link($item['object_id']);
            if (is_string($link)) {
                return $link;
            }
        }

        return '';
    }

    /**
     * @param array        $source  Raw fields.
     * @param string[]     $keys    Keys to try, in order.
     * @param mixed        $default Value when every key is missing.
     * @return mixed
     */
    private function first_set(array $source, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null) {
                return $source[$key];
            }
        }
        return $default;
    }

    /**
     * @param mixed $value Raw classes.
     * @return string[]
     */
    private function normalize_classes($value)
    {
        if (is_string($value)) {
            $decoded = $this->unserialize_array($value);
            if (! empty($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s+/', trim($value));
                if (! is_array($value)) {
                    $value = array();
                }
            }
        }
        if (! is_array($value)) {
            return array();
        }

        $classes = array();
        foreach ($value as $class) {
            if (! is_string($class) && ! is_numeric($class)) {
                continue;
            }
            $class = trim((string) $class);
            if ($class === '' || in_array($class, $classes, true)) {
                continue;
            }
            $classes[] = $class;
        }
        return $classes;
    }

    /**
     * @param mixed $map Language => id.
     * @return array<string, int>
     */
    private function normalize_id_map($map)
    {
        if (! is_array($map)) {
            return array();
        }
        $clean = array();
        foreach ($map as $language => $id) {
            $language = $this->normalize_lang_slug($language);
            $id = $this->to_int($id);
            if ($language === '' || $id === 0 || isset($clean[$language])) {
                continue;
            }
            $clean[$language] = $id;
        }
        return $clean;
    }

    /**
     * @param mixed $slug Raw slug.
     * @return string
     */
    private function normalize_lang_slug($slug)
    {
        if (! is_string($slug) && ! is_numeric($slug)) {
            return '';
        }
        return trim((string) $slug);
    }

    /**
     * Split a Polylang customizer location `primary___sv` into location and language.
     *
     * @param mixed $location Raw location key.
     * @return array{location: string, lang: string}
     */
    private function split_location_key($location)
    {
        $location = trim($this->text($location));
        $marker = '___';
        $pos = strpos($location, $marker);
        if ($pos === false || $pos === 0) {
            return array(
                'location' => $location,
                'lang' => '',
            );
        }

        $base = substr($location, 0, $pos);
        $language = substr($location, $pos + strlen($marker));
        if ($base === '' || $language === '' || strpos($language, $marker) !== false) {
            return array(
                'location' => $location,
                'lang' => '',
            );
        }

        return array(
            'location' => $base,
            'lang' => $language,
        );
    }

    /**
     * @param int    $term_id  Term id stored as the relationship object.
     * @param string $taxonomy `term_language` or `language`.
     * @return string
     */
    private function language_slug_from_relationships($term_id, $taxonomy)
    {
        $terms = $this->object_terms($term_id, $taxonomy);
        foreach ($terms as $term) {
            $slug = isset($term['slug']) ? trim((string) $term['slug']) : '';
            if ($taxonomy === 'term_language' && strpos($slug, 'pll_') === 0) {
                $slug = substr($slug, 4);
            }
            if ($slug !== '') {
                return $slug;
            }
        }
        return '';
    }

    /**
     * Terms linked to an object in one taxonomy.
     *
     * @param int    $object_id Object or term id.
     * @param string $taxonomy  Taxonomy name.
     * @return array<int, array{term_id: int, slug: string, name: string, description: string}>
     */
    private function object_terms($object_id, $taxonomy)
    {
        $object_id = $this->to_int($object_id);
        $taxonomy = $this->text($taxonomy);
        if ($object_id === 0 || $taxonomy === '') {
            return array();
        }

        if (function_exists('wp_get_object_terms')) {
            $terms = wp_get_object_terms($object_id, $taxonomy);
            if (function_exists('is_wp_error') && is_wp_error($terms)) {
                $terms = array();
            }
            if (is_array($terms) && ! empty($terms)) {
                return $this->term_rows_from_api($terms);
            }
        }

        $tables = $this->tables();
        if ($tables === null) {
            return array();
        }

        return $this->db_get(
            'SELECT t.term_id AS term_id, t.slug AS slug, t.name AS name, tt.description AS description'
            . ' FROM ' . $tables['term_relationships'] . ' tr'
            . ' INNER JOIN ' . $tables['term_taxonomy'] . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . ' INNER JOIN ' . $tables['terms'] . ' t ON t.term_id = tt.term_id'
            . ' WHERE tr.object_id = %d AND tt.taxonomy = %s',
            array($object_id, $taxonomy)
        );
    }

    /**
     * @param array<int, mixed> $terms Term objects or arrays.
     * @return array<int, array{term_id: int, slug: string, name: string, description: string}>
     */
    private function term_rows_from_api(array $terms)
    {
        $rows = array();
        foreach ($terms as $term) {
            if (is_object($term)) {
                $rows[] = array(
                    'term_id' => isset($term->term_id) ? $this->to_int($term->term_id) : 0,
                    'slug' => isset($term->slug) ? $this->text($term->slug) : '',
                    'name' => isset($term->name) ? $this->text($term->name) : '',
                    'description' => isset($term->description) ? $this->text($term->description) : '',
                );
            } elseif (is_array($term)) {
                $rows[] = array(
                    'term_id' => isset($term['term_id']) ? $this->to_int($term['term_id']) : 0,
                    'slug' => isset($term['slug']) ? $this->text($term['slug']) : '',
                    'name' => isset($term['name']) ? $this->text($term['name']) : '',
                    'description' => isset($term['description']) ? $this->text($term['description']) : '',
                );
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     */
    private function catalog()
    {
        if (is_array($this->catalog)) {
            return $this->catalog;
        }

        $this->catalog = array();
        $menus = $this->read_nav_menus();
        if (! is_array($menus)) {
            return $this->catalog;
        }

        foreach ($menus as $menu) {
            $id = 0;
            $name = '';
            $slug = '';
            if (is_object($menu)) {
                $id = isset($menu->term_id) ? $this->to_int($menu->term_id) : 0;
                if ($id === 0 && isset($menu->id)) {
                    $id = $this->to_int($menu->id);
                }
                $name = isset($menu->name) ? $this->text($menu->name) : '';
                $slug = isset($menu->slug) ? $this->text($menu->slug) : '';
            } elseif (is_array($menu)) {
                if (isset($menu['id'])) {
                    $id = $this->to_int($menu['id']);
                } elseif (isset($menu['term_id'])) {
                    $id = $this->to_int($menu['term_id']);
                }
                $name = isset($menu['name']) ? $this->text($menu['name']) : '';
                $slug = isset($menu['slug']) ? $this->text($menu['slug']) : '';
            }
            if ($id === 0 || isset($this->catalog[$id])) {
                continue;
            }
            $this->catalog[$id] = array(
                'name' => $name,
                'slug' => $slug,
            );
        }

        return $this->catalog;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nav_menus_from_db()
    {
        $tables = $this->tables();
        if ($tables === null) {
            return array();
        }

        return $this->db_get(
            'SELECT t.term_id AS id, t.name AS name, t.slug AS slug'
            . ' FROM ' . $tables['terms'] . ' t'
            . ' INNER JOIN ' . $tables['term_taxonomy'] . ' tt ON tt.term_id = t.term_id'
            . " WHERE tt.taxonomy = 'nav_menu' ORDER BY t.name ASC, t.term_id ASC",
            array()
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function nav_menus_from_api()
    {
        if (function_exists('wp_get_nav_menus')) {
            $menus = wp_get_nav_menus();
            if (is_array($menus)) {
                return $menus;
            }
        }

        if (! function_exists('get_terms')) {
            return array();
        }

        $terms = get_terms(array(
            'taxonomy' => 'nav_menu',
            'hide_empty' => false,
            'lang' => '',
        ));
        if (function_exists('is_wp_error') && is_wp_error($terms)) {
            return array();
        }
        return is_array($terms) ? $terms : array();
    }

    /**
     * @param int $menu_id Menu term id.
     * @return string|null
     */
    private function menu_name_from_db($menu_id)
    {
        $tables = $this->tables();
        if ($tables === null) {
            return null;
        }

        $rows = $this->db_get(
            'SELECT t.name AS name FROM ' . $tables['terms'] . ' t'
            . ' INNER JOIN ' . $tables['term_taxonomy'] . ' tt ON tt.term_id = t.term_id'
            . " WHERE t.term_id = %d AND tt.taxonomy = 'nav_menu'",
            array($menu_id)
        );
        if (! isset($rows[0]['name'])) {
            return null;
        }
        return $this->text($rows[0]['name']);
    }

    /**
     * @param int $menu_id Menu term id.
     * @return string|null
     */
    private function menu_name_from_api($menu_id)
    {
        if (function_exists('wp_get_nav_menu_object')) {
            $menu = wp_get_nav_menu_object($menu_id);
            if (is_object($menu) && isset($menu->name)) {
                return $this->text($menu->name);
            }
            if (is_array($menu) && isset($menu['name'])) {
                return $this->text($menu['name']);
            }
            return null;
        }

        if (! function_exists('get_term')) {
            return null;
        }

        $term = get_term($menu_id, 'nav_menu');
        if (function_exists('is_wp_error') && is_wp_error($term)) {
            return null;
        }
        if (is_object($term) && isset($term->name)) {
            return $this->text($term->name);
        }
        return null;
    }

    /**
     * @param int $menu_id Menu term id.
     * @return array<int, array<string, mixed>>
     */
    private function menu_items_from_db($menu_id)
    {
        $tables = $this->tables();
        if ($tables === null) {
            return array();
        }

        $rows = $this->db_get(
            'SELECT p.ID AS id, p.post_title AS title, p.post_excerpt AS attr_title,'
            . ' p.post_content AS description, p.menu_order AS position'
            . ' FROM ' . $tables['posts'] . ' p'
            . ' INNER JOIN ' . $tables['term_relationships'] . ' tr ON tr.object_id = p.ID'
            . ' INNER JOIN ' . $tables['term_taxonomy'] . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . " WHERE tt.taxonomy = 'nav_menu' AND tt.term_id = %d"
            . " AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'"
            . ' ORDER BY p.menu_order ASC, p.ID ASC',
            array($menu_id)
        );
        if (empty($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $id = isset($row['id']) ? $this->to_int($row['id']) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $meta = $this->menu_item_meta($ids);

        $items = array();
        foreach ($rows as $row) {
            $id = isset($row['id']) ? $this->to_int($row['id']) : 0;
            $fields = isset($meta[$id]) ? $meta[$id] : array();
            $type = isset($fields['_menu_item_type']) ? $this->text($fields['_menu_item_type']) : '';
            $object = isset($fields['_menu_item_object']) ? $this->text($fields['_menu_item_object']) : '';
            $items[] = array(
                'id' => $id,
                'parent' => isset($fields['_menu_item_menu_item_parent'])
                    ? $fields['_menu_item_menu_item_parent']
                    : 0,
                'title' => isset($row['title']) ? $row['title'] : '',
                'url' => isset($fields['_menu_item_url']) ? $fields['_menu_item_url'] : '',
                'object' => $object,
                'object_id' => isset($fields['_menu_item_object_id']) ? $fields['_menu_item_object_id'] : 0,
                'type' => $type,
                'attr_title' => isset($row['attr_title']) ? $row['attr_title'] : '',
                'description' => isset($row['description']) ? $row['description'] : '',
                'target' => isset($fields['_menu_item_target']) ? $fields['_menu_item_target'] : '',
                'classes' => isset($fields['_menu_item_classes']) ? $fields['_menu_item_classes'] : array(),
                'xfn' => isset($fields['_menu_item_xfn']) ? $fields['_menu_item_xfn'] : '',
                'position' => isset($row['position']) ? $row['position'] : 0,
            );
        }

        return $items;
    }

    /**
     * @param int[] $ids Menu item post ids.
     * @return array<int, array<string, string>>
     */
    private function menu_item_meta(array $ids)
    {
        $tables = $this->tables();
        $ids = array_values(array_filter(array_map(array($this, 'to_int'), $ids)));
        if ($tables === null || empty($ids)) {
            return array();
        }

        $keys = array(
            '_menu_item_type',
            '_menu_item_object',
            '_menu_item_object_id',
            '_menu_item_menu_item_parent',
            '_menu_item_url',
            '_menu_item_target',
            '_menu_item_classes',
            '_menu_item_xfn',
        );
        $id_marks = implode(', ', array_fill(0, count($ids), '%d'));
        $key_marks = implode(', ', array_fill(0, count($keys), '%s'));
        $rows = $this->db_get(
            'SELECT post_id, meta_key, meta_value FROM ' . $tables['postmeta']
            . ' WHERE post_id IN (' . $id_marks . ') AND meta_key IN (' . $key_marks . ')',
            array_merge($ids, $keys)
        );

        $meta = array();
        foreach ($rows as $row) {
            if (! isset($row['post_id'], $row['meta_key'])) {
                continue;
            }
            $post_id = $this->to_int($row['post_id']);
            if (! isset($meta[$post_id])) {
                $meta[$post_id] = array();
            }
            $meta[$post_id][(string) $row['meta_key']] = isset($row['meta_value'])
                ? $this->text($row['meta_value'])
                : '';
        }
        return $meta;
    }

    /**
     * @param int $menu_id Menu term id.
     * @return array<int, mixed>
     */
    private function menu_items_from_api($menu_id)
    {
        if (! function_exists('wp_get_nav_menu_items')) {
            return array();
        }

        $items = wp_get_nav_menu_items($menu_id);
        if (function_exists('is_wp_error') && is_wp_error($items)) {
            return array();
        }
        return is_array($items) ? $items : array();
    }

    /**
     * @param int $post_id Post id.
     * @return string
     */
    private function lookup_post_title($post_id)
    {
        $post_id = $this->to_int($post_id);
        if ($post_id === 0) {
            return '';
        }

        if (function_exists('get_post')) {
            $post = get_post($post_id);
            if (is_object($post) && isset($post->post_title)) {
                return $this->text($post->post_title);
            }
        }

        $tables = $this->tables();
        if ($tables === null) {
            return '';
        }

        $rows = $this->db_get(
            'SELECT post_title AS title FROM ' . $tables['posts'] . ' WHERE ID = %d',
            array($post_id)
        );
        if (! isset($rows[0]['title'])) {
            return '';
        }
        return $this->text($rows[0]['title']);
    }

    /**
     * @param int $term_id Term id.
     * @return string
     */
    private function lookup_term_name($term_id)
    {
        $term_id = $this->to_int($term_id);
        if ($term_id === 0) {
            return '';
        }

        if (function_exists('get_term')) {
            $term = get_term($term_id);
            if (is_object($term) && isset($term->name) && (! function_exists('is_wp_error') || ! is_wp_error($term))) {
                return $this->text($term->name);
            }
        }

        $tables = $this->tables();
        if ($tables === null) {
            return '';
        }

        $rows = $this->db_get(
            'SELECT t.name AS name FROM ' . $tables['terms'] . ' t WHERE t.term_id = %d',
            array($term_id)
        );
        if (! isset($rows[0]['name'])) {
            return '';
        }
        return $this->text($rows[0]['name']);
    }

    /**
     * @param string $name Option name.
     * @return string|null
     */
    private function option_value_from_db($name)
    {
        $name = $this->text($name);
        $wpdb = $this->db();
        if ($name === '' || $wpdb === null || ! isset($wpdb->options) || ! is_string($wpdb->options) || $wpdb->options === '') {
            return null;
        }

        $rows = $this->db_get(
            'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1',
            array($name)
        );
        if (! isset($rows[0]['option_value']) || ! is_string($rows[0]['option_value'])) {
            return null;
        }
        return $rows[0]['option_value'];
    }

    /**
     * @param string $sql  Query with placeholders.
     * @param array  $args Placeholder values. An empty list runs the query as written.
     * @return array<int, array<string, mixed>>
     */
    private function db_get($sql, array $args)
    {
        $wpdb = $this->db();
        if ($wpdb === null || ! is_string($sql) || $sql === '' || ! method_exists($wpdb, 'get_results')) {
            return array();
        }

        if (! empty($args)) {
            if (! method_exists($wpdb, 'prepare')) {
                return array();
            }
            $sql = $wpdb->prepare($sql, ...$args);
        }
        if (! is_string($sql) || $sql === '') {
            return array();
        }

        $mode = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $wpdb->get_results($sql, $mode);
        if (! is_array($rows)) {
            return array();
        }

        $clean = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean[] = $row;
            } elseif (is_object($row)) {
                $clean[] = get_object_vars($row);
            }
        }
        return $clean;
    }

    /**
     * @return object|null
     */
    private function db()
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            return $GLOBALS['wpdb'];
        }
        return null;
    }

    /**
     * Table names, or null when the database handle is incomplete.
     *
     * @return array<string, string>|null
     */
    private function tables()
    {
        $wpdb = $this->db();
        if ($wpdb === null) {
            return null;
        }

        $names = array('posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships');
        $tables = array();
        foreach ($names as $name) {
            if (! isset($wpdb->$name) || ! is_string($wpdb->$name) || $wpdb->$name === '') {
                return null;
            }
            $tables[$name] = $wpdb->$name;
        }
        return $tables;
    }

    /**
     * @param mixed $value Serialized array or a plain array.
     * @return array
     */
    private function unserialize_array($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return array();
        }
        $value = trim($value);
        if ($value === '' || strpos($value, 'a:') !== 0) {
            return array();
        }

        set_error_handler(function () {
            return true;
        });
        try {
            $decoded = unserialize($value, array('allowed_classes' => false));
        } finally {
            restore_error_handler();
        }

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<int, array> $items Exported items.
     * @return bool
     */
    private function has_assigned_menu(array $items)
    {
        foreach ($items as $item) {
            if (empty($item['translations']) || ! is_array($item['translations'])) {
                continue;
            }
            foreach ($item['translations'] as $translation) {
                if (is_array($translation) && ! empty($translation['menu_id'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param int    $menu_id  Missing menu id.
     * @param string $location Location slug.
     * @param string $language Language slug.
     * @return void
     */
    private function warn_missing_menu($menu_id, $location, $language)
    {
        $this->missing_reports++;
        if ($this->missing_reports > 20) {
            if ($this->missing_reports === 21) {
                $this->warn($this->message_missing_truncated());
            }
            return;
        }
        $this->warn($this->message_missing_menu($menu_id, $location, $language));
    }

    /**
     * @param string $message Finished sentence.
     * @return void
     */
    private function warn($message)
    {
        if (! is_string($message) || $message === '' || in_array($message, $this->warnings, true)) {
            return;
        }
        $this->warnings[] = $message;
    }

    /**
     * @return string
     */
    private function message_wordpress_missing()
    {
        $text = 'WordPress is not loaded, so menus could not be read.';
        if (function_exists('__')) {
            $text = __('WordPress is not loaded, so menus could not be read.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_read_failed()
    {
        $text = 'Menus could not be read. Nothing was changed, and the rest of the migration can continue.';
        if (function_exists('__')) {
            $text = __('Menus could not be read. Nothing was changed, and the rest of the migration can continue.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_inactive_empty()
    {
        $text = 'Polylang is not active, and no menus or theme locations were found. Activate Polylang and run the preview again.';
        if (function_exists('__')) {
            $text = __('Polylang is not active, and no menus or theme locations were found. Activate Polylang and run the preview again.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_inactive_locations_only()
    {
        $text = 'Polylang is not active. Theme locations were listed, and no menu is assigned to them yet.';
        if (function_exists('__')) {
            $text = __('Polylang is not active. Theme locations were listed, and no menu is assigned to them yet.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_corrupt_nav_menus()
    {
        $text = 'Polylang menu assignments could not be read because the stored nav_menus value is invalid. Theme locations are still listed.';
        if (function_exists('__')) {
            $text = __('Polylang menu assignments could not be read because the stored nav_menus value is invalid. Theme locations are still listed.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_switcher_title()
    {
        $text = 'Languages';
        if (function_exists('__')) {
            $text = __('Languages', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_unassigned_location()
    {
        $text = 'Not assigned to a theme location';
        if (function_exists('__')) {
            $text = __('Not assigned to a theme location', 'migration-manager');
        }
        return $text;
    }

    /**
     * @return string
     */
    private function message_unknown_language()
    {
        $text = 'A menu has no Polylang language, so it was exported under "unknown".';
        if (function_exists('__')) {
            $text = __('A menu has no Polylang language, so it was exported under "unknown".', 'migration-manager');
        }
        return $text;
    }

    /**
     * @param int    $count      Unpublished links.
     * @param string $menu_label Menu name, or "menu {id}" when the name is missing.
     * @param string $language   Language slug.
     * @return string
     */
    private function message_skipped_items($count, $menu_label, $language)
    {
        $count = $this->to_int($count);
        $menu_label = $this->text($menu_label);
        $language = $this->text($language);
        if ($count === 1) {
            $text = 'The "%1$s" menu (%2$s) has 1 link that is not published. That link was left out of the export.';
            if (function_exists('__')) {
                $text = __('The "%1$s" menu (%2$s) has 1 link that is not published. That link was left out of the export.', 'migration-manager');
            }
            return sprintf($text, $menu_label, $language);
        }

        $text = 'The "%1$s" menu (%2$s) has %3$d links that are not published. Those links were left out of the export.';
        if (function_exists('__')) {
            $text = __('The "%1$s" menu (%2$s) has %3$d links that are not published. Those links were left out of the export.', 'migration-manager');
        }
        return sprintf($text, $menu_label, $language, $count);
    }

    /**
     * @return string
     */
    private function message_missing_truncated()
    {
        $text = 'More than 20 assigned menus are missing. The rest were exported without items.';
        if (function_exists('__')) {
            $text = __('More than 20 assigned menus are missing. The rest were exported without items.', 'migration-manager');
        }
        return $text;
    }

    /**
     * @param int    $menu_id  Menu term id.
     * @param string $location Location slug.
     * @param string $language Language slug.
     * @return string
     */
    private function message_missing_menu($menu_id, $location, $language)
    {
        $menu_id = $this->to_int($menu_id);
        $location = $this->text($location);
        $language = $this->text($language);
        if ($location === '') {
            $text = 'Menu %1$d (%2$s) is not assigned to a theme location and no longer exists, so it was exported without items.';
            if (function_exists('__')) {
                $text = __('Menu %1$d (%2$s) is not assigned to a theme location and no longer exists, so it was exported without items.', 'migration-manager');
            }
            return sprintf($text, $menu_id, $language);
        }

        $text = 'Menu %1$d, assigned to the "%2$s" location for %3$s, no longer exists, so that language was exported without items.';
        if (function_exists('__')) {
            $text = __('Menu %1$d, assigned to the "%2$s" location for %3$s, no longer exists, so that language was exported without items.', 'migration-manager');
        }
        return sprintf($text, $menu_id, $location, $language);
    }

    /**
     * @param mixed $value Raw value.
     * @return string
     */
    private function text($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Decode stored entities once so titles match what editors see.
     *
     * @param mixed $value Raw text.
     * @return string
     */
    private function entity_text($value)
    {
        $text = $this->text($value);
        if ($text === '' || strpos($text, '&') === false) {
            return $text;
        }
        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Non-negative integer. Invalid values become 0.
     *
     * @param mixed $value Raw id or position.
     * @return int
     */
    private function to_int($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_float($value)) {
            $number = (int) $value;
            return $number > 0 ? $number : 0;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || ! is_numeric($value)) {
                return 0;
            }
            $number = (int) $value;
            return $number > 0 ? $number : 0;
        }
        return 0;
    }
}
