<?php

/**
 * Read Polylang term translations for the Tolka migration.
 *
 * The class only reads. It does not delete terms, options, or Polylang rows.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists(__NAMESPACE__ . '\\Polylang_Terms', false)) {
    return;
}

class Polylang_Terms
{
    /**
     * Optional snapshot reader used by tests. Production leaves this unset.
     *
     * @var callable|null
     */
    private $reader = null;

    /**
     * How many terms to read per query.
     *
     * @var int
     */
    private $pageSize = 500;

    /**
     * @param callable|null $reader
     */
    public function __construct($reader = null)
    {
        $this->reader = $reader;
    }

    /**
     * Export term translation groups and terms Polylang never linked.
     *
     * Each item has taxonomy, group (`term:{id}`), and translations.
     * A translation is keyed by language slug and has id, name, slug, and parent.
     * parent is that language's WordPress parent term id, or 0 when the term has none.
     * The group id is the default-language term when that translation exists,
     * otherwise the lowest term id in the group.
     *
     * @return array{items: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function export()
    {
        try {
            $snapshot = $this->loadSnapshot();
            return $this->packageSnapshot(is_array($snapshot) ? $snapshot : array('state' => 'failed'));
        } catch (\Throwable $e) {
            return array(
                'items' => array(),
                'errors' => array($this->text('Term translations could not be read. Existing Polylang data was left unchanged.')),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSnapshot()
    {
        if (is_callable($this->reader)) {
            $snapshot = call_user_func($this->reader);
            return is_array($snapshot) ? $snapshot : array('state' => 'failed');
        }

        $default = $this->defaultLanguage();
        $api = null;

        if ($this->apiReady()) {
            $api = $this->readFromApi($default);
            if (is_array($api) && $this->hasSnapshotItems($api)) {
                return $api;
            }
        }

        if ($this->databaseReady()) {
            $stored = $this->readFromStorage($default);
            if (is_array($stored) && ($this->hasSnapshotItems($stored) || !is_array($api))) {
                return $stored;
            }
            if (!is_array($stored) && !is_array($api)) {
                return $this->emptySnapshot($default, 'failed');
            }
        } elseif (!is_array($api)) {
            $state = ($this->apiReady() || $this->polylangEvidence(array())) ? 'failed' : 'inactive';
            return $this->emptySnapshot($default, $state);
        }

        return $api;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{items: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    private function packageSnapshot($snapshot)
    {
        $errors = array();
        if (isset($snapshot['errors']) && is_array($snapshot['errors'])) {
            $errors = $this->uniqueStrings($snapshot['errors']);
        }

        $default = '';
        if (isset($snapshot['default_lang']) && is_string($snapshot['default_lang'])) {
            $default = trim($snapshot['default_lang']);
        }
        $fallback = '';
        if (isset($snapshot['fallback_lang']) && is_string($snapshot['fallback_lang'])) {
            $fallback = trim($snapshot['fallback_lang']);
        }
        $assignLanguage = $default !== '' ? $default : $fallback;
        $assignKind = $default !== '' ? 'default' : ($fallback !== '' ? 'stored' : '');

        $state = 'ok';
        if (isset($snapshot['state']) && is_string($snapshot['state']) && $snapshot['state'] !== '') {
            $state = $snapshot['state'];
        }

        $items = array();
        $used = array();
        $duplicate = false;
        $unassigned = 0;
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : array();

        foreach ($groups as $group) {
            $item = $this->itemFromGroup($group, $default, $used, $duplicate);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $loose = isset($snapshot['untranslated']) && is_array($snapshot['untranslated']) ? $snapshot['untranslated'] : array();
        foreach ($loose as $term) {
            $item = $this->itemFromUntranslated($term, $assignLanguage, $used, $unassigned);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($duplicate) {
            $errors[] = $this->text('A term is listed in more than one translation group. The extra copy was skipped.');
        }
        if ($unassigned > 0 && $assignKind === 'default') {
            $errors[] = $this->unassignedSentence($unassigned, $assignLanguage, 'default');
        } elseif ($unassigned > 0 && $assignKind === 'stored') {
            $errors[] = $this->unassignedSentence($unassigned, $assignLanguage, 'stored');
        } elseif ($unassigned > 0) {
            $errors[] = $this->countSentence(
                $unassigned,
                'A term has no Polylang language. It is included with a blank language so it is not dropped.',
                '%d terms have no Polylang language. They are included with a blank language so they are not dropped.'
            );
        }

        if ($items === array() && $state === 'inactive') {
            $errors[] = $this->text('Polylang is not active, so there are no term translations to migrate.');
        }
        if ($items === array() && $state === 'failed') {
            $errors[] = $this->text('Term translations could not be read. Existing Polylang data was left unchanged.');
        }

        usort($items, function ($left, $right) {
            return $this->compareItems($left, $right);
        });

        return array(
            'items' => array_values($items),
            'errors' => $this->uniqueStrings($errors),
        );
    }

    /**
     * @param mixed $group
     * @param array<int, bool> $used
     * @param bool $duplicate
     * @return array<string, mixed>|null
     */
    private function itemFromGroup($group, $default, array &$used, &$duplicate)
    {
        if (!is_array($group)) {
            return null;
        }

        $taxonomy = isset($group['taxonomy']) && is_string($group['taxonomy']) ? $group['taxonomy'] : '';
        if ($taxonomy === '' || $this->isInternalTaxonomy($taxonomy)) {
            return null;
        }

        $raw = isset($group['translations']) && is_array($group['translations']) ? $group['translations'] : array();
        $translations = array();
        $seenHere = array();

        foreach ($raw as $lang => $row) {
            $key = $this->languageKey($lang);
            $normalized = $this->normalizeTranslationRow($row);
            if ($key === null || $normalized === null) {
                continue;
            }
            if (isset($translations[$key]) || isset($used[$normalized['id']]) || isset($seenHere[$normalized['id']])) {
                $duplicate = true;
                continue;
            }
            $seenHere[$normalized['id']] = true;
            $translations[$key] = $normalized;
        }

        if ($translations === array()) {
            return null;
        }

        foreach ($translations as $row) {
            $used[$row['id']] = true;
        }

        $translations = $this->orderTranslations($translations, $default);
        $anchor = $this->anchorId($translations, $default);
        if ($anchor <= 0) {
            return null;
        }

        return array(
            'taxonomy' => $taxonomy,
            'group' => 'term:' . $anchor,
            'translations' => $translations,
        );
    }

    /**
     * @param mixed $term
     * @param array<int, bool> $used
     * @param int $unassigned
     * @return array<string, mixed>|null
     */
    private function itemFromUntranslated($term, $default, array &$used, &$unassigned)
    {
        if (!is_array($term)) {
            return null;
        }

        $taxonomy = isset($term['taxonomy']) && is_string($term['taxonomy']) ? $term['taxonomy'] : '';
        $id = isset($term['id']) ? (int) $term['id'] : 0;
        if ($taxonomy === '' || $this->isInternalTaxonomy($taxonomy) || $id <= 0 || isset($used[$id])) {
            return null;
        }

        $lang = '';
        if (isset($term['lang']) && is_string($term['lang'])) {
            $lang = trim($term['lang']);
        }
        if ($lang === '') {
            $unassigned++;
            $lang = $default;
        }

        $used[$id] = true;

        return array(
            'taxonomy' => $taxonomy,
            'group' => 'term:' . $id,
            'translations' => array(
                $lang => array(
                    'id' => $id,
                    'name' => $this->stringValue(isset($term['name']) ? $term['name'] : ''),
                    'slug' => $this->stringValue(isset($term['slug']) ? $term['slug'] : ''),
                    'parent' => $this->parentId(isset($term['parent']) ? $term['parent'] : 0),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFromApi($default)
    {
        $taxonomies = $this->translatedTaxonomies();
        $listed = $this->listTerms($taxonomies);
        if ($listed === null) {
            return null;
        }

        $errors = array();
        if (!empty($listed['failed'])) {
            $errors[] = $this->text('Some terms could not be listed and were skipped.');
        }

        $index = array();
        foreach ($listed['terms'] as $term) {
            $index[$term['id']] = $term;
        }

        $groups = array();
        $groupedIds = array();
        $seenMaps = array();
        $apiFailures = 0;
        $missing = 0;
        $mismatched = 0;

        foreach ($listed['terms'] as $term) {
            $termId = (int) $term['id'];
            if ($termId <= 0 || isset($groupedIds[$termId])) {
                continue;
            }

            $map = $this->apiTranslationMap($termId);
            if ($map === null) {
                $apiFailures++;
                continue;
            }
            if ($map === array()) {
                continue;
            }

            $clean = array();
            foreach ($map as $lang => $translatedId) {
                $key = $this->languageKey($lang);
                $translatedId = (int) $translatedId;
                if ($key === null || $translatedId <= 0) {
                    continue;
                }
                $clean[$key] = $translatedId;
            }
            if ($clean === array()) {
                continue;
            }

            ksort($clean);
            $signature = array();
            foreach ($clean as $key => $translatedId) {
                $signature[] = $key . ':' . $translatedId;
            }
            $signature = implode('|', $signature);
            if (isset($seenMaps[$signature])) {
                continue;
            }
            $seenMaps[$signature] = true;

            $built = $this->translationsFromMap($clean, $term['taxonomy'], $index, $groupedIds);
            if ($built['group'] !== null) {
                $groups[] = $built['group'];
            }
            $missing += $built['missing'];
            $mismatched += $built['mismatched'];
        }

        if ($missing > 0) {
            $errors[] = $this->countSentence(
                $missing,
                'A translated term no longer exists and was skipped.',
                '%d translated terms no longer exist and were skipped.'
            );
        }
        if ($mismatched > 0) {
            $errors[] = $this->countSentence(
                $mismatched,
                'A translated term belongs to a different taxonomy and was skipped.',
                '%d translated terms belong to a different taxonomy and were skipped.'
            );
        }
        if ($apiFailures > 0) {
            $errors[] = $this->countSentence(
                $apiFailures,
                'Polylang could not return one term translation. The export continued with the terms it could read.',
                'Polylang could not return %d term translations. The export continued with the terms it could read.'
            );
        }

        $languageMap = array();
        if ($this->databaseReady()) {
            $languageMap = $this->fetchLanguageMap();
        }

        $untranslated = array();
        foreach ($listed['terms'] as $term) {
            $termId = (int) $term['id'];
            if ($termId <= 0 || isset($groupedIds[$termId])) {
                continue;
            }
            $lang = $this->apiTermLanguage($termId);
            if ($lang === '' && isset($languageMap[$termId])) {
                $lang = $languageMap[$termId];
            }
            $untranslated[] = array(
                'taxonomy' => $term['taxonomy'],
                'lang' => $lang,
                'id' => $termId,
                'name' => $term['name'],
                'slug' => $term['slug'],
                'parent' => isset($term['parent']) ? $this->parentId($term['parent']) : 0,
            );
        }

        return array(
            'state' => 'ok',
            'default_lang' => $default,
            'fallback_lang' => $default === '' ? $this->fallbackLanguage() : '',
            'groups' => $groups,
            'untranslated' => $untranslated,
            'errors' => $this->uniqueStrings($errors),
        );
    }

    /**
     * @param array<string, int> $clean
     * @param array<int, array<string, mixed>> $index
     * @param array<int, bool> $groupedIds
     * @return array{group: array<string, mixed>|null, missing: int, mismatched: int}
     */
    private function translationsFromMap($clean, $fallbackTaxonomy, array $index, array &$groupedIds)
    {
        $translations = array();
        $taxonomy = is_string($fallbackTaxonomy) ? $fallbackTaxonomy : '';
        $missing = 0;
        $mismatched = 0;

        foreach ($clean as $lang => $translatedId) {
            $details = $this->detailsForId($translatedId, $index);
            if ($details === null) {
                $missing++;
                continue;
            }
            if ($this->isInternalTaxonomy($details['taxonomy'])) {
                $groupedIds[$translatedId] = true;
                continue;
            }
            if ($taxonomy === '') {
                $taxonomy = $details['taxonomy'];
            } elseif ($details['taxonomy'] !== $taxonomy) {
                $mismatched++;
                $groupedIds[$translatedId] = true;
                continue;
            }
            $groupedIds[$translatedId] = true;
            $translations[$lang] = array(
                'id' => $translatedId,
                'name' => $details['name'],
                'slug' => $details['slug'],
                'parent' => isset($details['parent']) ? $this->parentId($details['parent']) : 0,
            );
        }

        $group = null;
        if ($translations !== array() && $taxonomy !== '' && !$this->isInternalTaxonomy($taxonomy)) {
            $group = array(
                'taxonomy' => $taxonomy,
                'translations' => $translations,
            );
        }

        return array(
            'group' => $group,
            'missing' => $missing,
            'mismatched' => $mismatched,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFromStorage($default)
    {
        $groupRows = $this->fetchGroupRows();
        if ($groupRows === null) {
            return null;
        }

        $errors = array();
        $decodedGroups = array();
        $neededIds = array();
        $corrupt = 0;

        foreach ($groupRows as $row) {
            if (!is_array($row) || !array_key_exists('description', $row)) {
                $corrupt++;
                continue;
            }
            $map = $this->decodeMap($row['description']);
            if ($map === null) {
                $corrupt++;
                continue;
            }

            $clean = array();
            foreach ($map as $lang => $id) {
                $key = $this->languageKey($lang);
                $id = (int) $id;
                if ($key === null || $id <= 0) {
                    continue;
                }
                $clean[$key] = $id;
                $neededIds[$id] = $id;
            }
            if ($clean !== array()) {
                $decodedGroups[] = $clean;
            }
        }

        if ($corrupt > 0) {
            $errors[] = $this->countSentence(
                $corrupt,
                'A term translation group could not be read and was skipped.',
                '%d term translation groups could not be read and were skipped.'
            );
        }

        $details = array();
        if ($neededIds !== array()) {
            $fetched = $this->fetchTermsByIds(array_values($neededIds));
            if ($fetched === null) {
                return null;
            }
            foreach ($fetched as $row) {
                $normalized = $this->normalizeListedTerm($row, true);
                if ($normalized === null) {
                    continue;
                }
                $existing = isset($details[$normalized['id']]) ? $details[$normalized['id']] : null;
                if ($existing === null || ($this->isInternalTaxonomy($existing['taxonomy']) && !$this->isInternalTaxonomy($normalized['taxonomy']))) {
                    $details[$normalized['id']] = $normalized;
                }
            }
        }

        $evidence = $this->polylangEvidence($groupRows);
        $taxonomies = array();
        if ($evidence) {
            foreach ($this->translatedTaxonomies() as $taxonomy) {
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }

        $groups = array();
        $groupedIds = array();
        $missing = 0;
        $mismatched = 0;
        foreach ($decodedGroups as $clean) {
            $built = $this->translationsFromMap($clean, '', $details, $groupedIds);
            $missing += $built['missing'];
            $mismatched += $built['mismatched'];
            if ($built['group'] === null) {
                continue;
            }
            $groups[] = $built['group'];
            $taxonomies[$built['group']['taxonomy']] = $built['group']['taxonomy'];
        }
        if ($missing > 0) {
            $errors[] = $this->countSentence(
                $missing,
                'A translated term no longer exists and was skipped.',
                '%d translated terms no longer exist and were skipped.'
            );
        }
        if ($mismatched > 0) {
            $errors[] = $this->countSentence(
                $mismatched,
                'A translated term belongs to a different taxonomy and was skipped.',
                '%d translated terms belong to a different taxonomy and were skipped.'
            );
        }

        $untranslated = array();
        if ($evidence) {
            $languageMap = $this->fetchLanguageMap();
            foreach ($taxonomies as $taxonomy) {
                if ($this->isInternalTaxonomy($taxonomy)) {
                    continue;
                }
                $rows = $this->fetchTermsForTaxonomy($taxonomy);
                if ($rows === null) {
                    $errors[] = $this->text('Some terms could not be listed and were skipped.');
                    continue;
                }
                foreach ($rows as $term) {
                    if (!is_array($term) || empty($term['id']) || isset($groupedIds[(int) $term['id']])) {
                        continue;
                    }
                    $termId = (int) $term['id'];
                    $untranslated[] = array(
                        'taxonomy' => $term['taxonomy'],
                        'lang' => isset($languageMap[$termId]) ? $languageMap[$termId] : '',
                        'id' => $termId,
                        'name' => $term['name'],
                        'slug' => $term['slug'],
                        'parent' => isset($term['parent']) ? $this->parentId($term['parent']) : 0,
                    );
                }
            }
        }

        $state = 'ok';
        if (!$evidence && $groups === array() && $untranslated === array()) {
            $state = 'inactive';
        }

        return array(
            'state' => $state,
            'default_lang' => $default,
            'fallback_lang' => $default === '' ? $this->fallbackLanguage() : '',
            'groups' => $groups,
            'untranslated' => $untranslated,
            'errors' => $this->uniqueStrings($errors),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $index
     * @return array<string, mixed>|null
     */
    private function detailsForId($id, array $index)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        if (isset($index[$id])) {
            return $index[$id];
        }
        if (function_exists('get_term')) {
            try {
                $term = get_term($id);
            } catch (\Throwable $e) {
                $term = null;
            }
            $normalized = $this->normalizeListedTerm($term, true);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        if ($this->databaseReady()) {
            $rows = $this->fetchTermsByIds(array($id));
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $normalized = $this->normalizeListedTerm($row, true);
                    if ($normalized !== null && $normalized['id'] === $id && !$this->isInternalTaxonomy($normalized['taxonomy'])) {
                        return $normalized;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @return array{terms: array<int, array<string, mixed>>, failed: int}|null
     */
    private function listTerms(array $taxonomies)
    {
        $terms = array();
        $seen = array();
        $failed = 0;
        $queried = 0;

        foreach ($taxonomies as $taxonomy) {
            if (!is_string($taxonomy) || $taxonomy === '' || $this->isInternalTaxonomy($taxonomy)) {
                continue;
            }
            $queried++;
            $batch = $this->listTaxonomyTerms($taxonomy);
            if ($batch === null) {
                $failed++;
                continue;
            }
            foreach ($batch as $term) {
                if (isset($seen[$term['id']])) {
                    continue;
                }
                $seen[$term['id']] = true;
                $terms[] = $term;
            }
        }

        if ($queried > 0 && $failed === $queried) {
            return null;
        }

        return array(
            'terms' => $terms,
            'failed' => $failed,
        );
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function listTaxonomyTerms($taxonomy)
    {
        if (function_exists('get_terms')) {
            $listed = $this->listTaxonomyTermsWithApi($taxonomy);
            if ($listed !== null) {
                return $listed;
            }
        }
        if ($this->databaseReady()) {
            return $this->fetchTermsForTaxonomy($taxonomy);
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function listTaxonomyTermsWithApi($taxonomy)
    {
        $offset = 0;
        $all = array();
        $seen = array();
        $guard = 0;

        do {
            $guard++;
            if ($guard > 10000) {
                break;
            }

            try {
                $batch = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'lang' => '',
                    'number' => $this->pageSize,
                    'offset' => $offset,
                    'orderby' => 'term_id',
                    'order' => 'ASC',
                    'update_term_meta_cache' => false,
                    'fields' => 'all',
                ));
            } catch (\Throwable $e) {
                return $offset === 0 ? null : $all;
            }

            if (function_exists('is_wp_error') && is_wp_error($batch)) {
                return $offset === 0 ? null : $all;
            }
            if (!is_array($batch)) {
                return $offset === 0 ? null : $all;
            }

            $added = 0;
            foreach ($batch as $term) {
                $normalized = $this->normalizeListedTerm($term, false);
                if ($normalized === null || isset($seen[$normalized['id']])) {
                    continue;
                }
                $seen[$normalized['id']] = true;
                $all[] = $normalized;
                $added++;
            }
            if ($added === 0) {
                break;
            }
            $offset += $this->pageSize;
        } while (count($batch) === $this->pageSize);

        return $all;
    }

    /**
     * @return array<int, string>
     */
    protected function translatedTaxonomies()
    {
        $fromModel = $this->taxonomiesFromModel();
        if ($fromModel !== array()) {
            $names = $fromModel;
        } else {
            $names = array('category', 'post_tag');
            $names = array_merge($names, $this->taxonomiesFromOption());
        }

        $unique = array();
        foreach ($names as $taxonomy) {
            if (!is_string($taxonomy) || $taxonomy === '' || $this->isInternalTaxonomy($taxonomy)) {
                continue;
            }
            $unique[$taxonomy] = $taxonomy;
        }
        return array_values($unique);
    }

    /**
     * @return array<int, string>
     */
    private function taxonomiesFromModel()
    {
        if (!function_exists('PLL')) {
            return array();
        }
        try {
            $pll = PLL();
        } catch (\Throwable $e) {
            return array();
        }
        if (!is_object($pll) || !isset($pll->model->term) || !is_object($pll->model->term)) {
            return array();
        }
        if (!method_exists($pll->model->term, 'get_translated_object_types')) {
            return array();
        }
        try {
            $types = $pll->model->term->get_translated_object_types(false);
        } catch (\Throwable $e) {
            return array();
        }
        return $this->stringList($types);
    }

    /**
     * @return array<int, string>
     */
    private function taxonomiesFromOption()
    {
        if (!function_exists('get_option')) {
            return array();
        }
        $options = get_option('polylang');
        if (!is_array($options) || !isset($options['taxonomies']) || !is_array($options['taxonomies'])) {
            return array();
        }
        return $this->stringList($options['taxonomies']);
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function stringList($values)
    {
        if (!is_array($values)) {
            return array();
        }
        $names = array();
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }
        return $names;
    }

    /**
     * @return array<string, int>|null
     */
    protected function apiTranslationMap($termId)
    {
        if (!function_exists('pll_get_term_translations')) {
            return array();
        }
        try {
            $map = pll_get_term_translations((int) $termId);
        } catch (\Throwable $e) {
            return null;
        }
        if (function_exists('is_wp_error') && is_wp_error($map)) {
            return null;
        }
        if (!is_array($map)) {
            return array();
        }
        return $map;
    }

    /**
     * @return string
     */
    protected function apiTermLanguage($termId)
    {
        if (!function_exists('pll_get_term_language')) {
            return '';
        }
        try {
            $value = pll_get_term_language((int) $termId, 'slug');
        } catch (\Throwable $e) {
            return '';
        }
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }

    /**
     * @return string
     */
    protected function defaultLanguage()
    {
        if (function_exists('pll_default_language')) {
            try {
                $slug = pll_default_language('slug');
            } catch (\Throwable $e) {
                $slug = '';
            }
            if (is_string($slug) && trim($slug) !== '') {
                return trim($slug);
            }
            try {
                $slug = pll_default_language();
            } catch (\Throwable $e) {
                $slug = '';
            }
            if (is_string($slug) && trim($slug) !== '') {
                return trim($slug);
            }
        }

        if (!function_exists('get_option')) {
            return '';
        }
        $options = get_option('polylang');
        if (!is_array($options) || !isset($options['default_lang']) || !is_string($options['default_lang'])) {
            return '';
        }
        return trim($options['default_lang']);
    }

    /**
     * @param array<int, mixed> $groupRows
     * @return bool
     */
    private function polylangEvidence($groupRows)
    {
        if (function_exists('pll_languages_list') || function_exists('pll_get_term_translations') || function_exists('pll_default_language')) {
            return true;
        }
        if (class_exists('PLL', false) || class_exists('Polylang', false)) {
            return true;
        }
        if (function_exists('get_option')) {
            $options = get_option('polylang');
            if (is_array($options) && $options !== array()) {
                return true;
            }
        }
        if (is_array($groupRows) && $groupRows !== array()) {
            return true;
        }
        return $this->languageTaxonomyExists();
    }

    /**
     * @return bool
     */
    private function languageTaxonomyExists()
    {
        if (!$this->databaseReady()) {
            return false;
        }
        $table = $this->table('term_taxonomy');
        $rows = $this->dbResults(
            "SELECT tt.term_taxonomy_id FROM {$table} AS tt WHERE tt.taxonomy = 'language' LIMIT 1"
        );
        return is_array($rows) && $rows !== array();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchGroupRows()
    {
        $table = $this->table('term_taxonomy');
        if ($table === '') {
            return null;
        }
        return $this->dbResults(
            "SELECT tt.description FROM {$table} AS tt WHERE tt.taxonomy = 'term_translations'"
        );
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTermsByIds(array $ids)
    {
        $clean = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        if ($clean === array()) {
            return array();
        }

        $terms = $this->table('terms');
        $taxonomy = $this->table('term_taxonomy');
        if ($terms === '' || $taxonomy === '') {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($clean), '%d'));
        $sql = "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
            FROM {$terms} AS t
            INNER JOIN {$taxonomy} AS tt ON tt.term_id = t.term_id
            WHERE t.term_id IN ({$placeholders})";

        return $this->dbResults($sql, $clean);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTermsForTaxonomy($taxonomy)
    {
        if (!is_string($taxonomy) || $taxonomy === '' || $this->isInternalTaxonomy($taxonomy)) {
            return array();
        }
        $terms = $this->table('terms');
        $taxonomyTable = $this->table('term_taxonomy');
        if ($terms === '' || $taxonomyTable === '') {
            return null;
        }

        $offset = 0;
        $all = array();
        $seen = array();
        $guard = 0;
        $sql = "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
            FROM {$terms} AS t
            INNER JOIN {$taxonomyTable} AS tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = %s
            ORDER BY t.term_id ASC
            LIMIT %d OFFSET %d";

        do {
            $guard++;
            if ($guard > 10000) {
                break;
            }
            $rows = $this->dbResults($sql, array($taxonomy, $this->pageSize, $offset));
            if ($rows === null) {
                return $offset === 0 ? null : $all;
            }
            if ($rows === array()) {
                break;
            }

            $added = 0;
            foreach ($rows as $row) {
                $normalized = $this->normalizeListedTerm($row, false);
                if ($normalized === null || isset($seen[$normalized['id']])) {
                    continue;
                }
                $seen[$normalized['id']] = true;
                $all[] = $normalized;
                $added++;
            }
            if ($added === 0) {
                break;
            }
            $offset += $this->pageSize;
        } while (count($rows) === $this->pageSize);

        return $all;
    }

    /**
     * @return array<int, string>
     */
    private function fetchLanguageMap()
    {
        $terms = $this->table('terms');
        $taxonomy = $this->table('term_taxonomy');
        $relationships = $this->table('term_relationships');
        if ($terms === '' || $taxonomy === '' || $relationships === '') {
            return array();
        }

        $rows = $this->dbResults(
            "SELECT tr.object_id, pll_lang.slug
            FROM {$relationships} AS tr
            INNER JOIN {$taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$terms} AS pll_lang ON pll_lang.term_id = tt.term_id
            WHERE tt.taxonomy = 'term_language'"
        );
        if (!is_array($rows)) {
            return array();
        }

        $map = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['object_id']) ? (int) $row['object_id'] : 0;
            $slug = isset($row['slug']) ? $this->languageFromRelationshipSlug($row['slug']) : '';
            if ($id > 0 && $slug !== '') {
                $map[$id] = $slug;
            }
        }
        return $map;
    }

    /**
     * @param string $sql
     * @param array<int, mixed> $args
     * @return array<int, array<string, mixed>>|null
     */
    private function dbResults($sql, array $args = array())
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !is_string($sql) || $sql === '') {
            return null;
        }
        if ($args !== array()) {
            if (!method_exists($wpdb, 'prepare')) {
                return null;
            }
            $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $args));
            if (!is_string($prepared) || $prepared === '') {
                return null;
            }
            $sql = $prepared;
        }

        $errorBefore = (isset($wpdb->last_error) && is_string($wpdb->last_error)) ? $wpdb->last_error : '';
        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $wpdb->get_results($sql, $output);
        $errorAfter = (isset($wpdb->last_error) && is_string($wpdb->last_error)) ? $wpdb->last_error : '';
        if ($errorAfter !== '' && $errorAfter !== $errorBefore) {
            return null;
        }
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
     * @param mixed $description
     * @return array<mixed>|null
     */
    private function decodeMap($description)
    {
        if (is_array($description)) {
            return $description;
        }
        if ($description === null || $description === '') {
            return array();
        }
        if (!is_string($description)) {
            return null;
        }

        $value = $description;
        if (function_exists('maybe_unserialize')) {
            $value = maybe_unserialize($value);
        } else {
            $value = $this->unserializeValue($value);
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($value !== $description) {
            $nested = $this->decodeMap($value);
            if (is_array($nested)) {
                return $nested;
            }
        }

        $again = $this->unserializeValue($value);
        if (is_array($again)) {
            return $again;
        }
        return null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function unserializeValue($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (!preg_match('/^(a|s|i|b|d|N|O|C):/', $value)) {
            return $value;
        }
        $decoded = @unserialize($value, array('allowed_classes' => false));
        if ($decoded === false && $value !== 'b:0;') {
            return $value;
        }
        return $decoded;
    }

    /**
     * @param mixed $term
     * @param bool $allowInternal
     * @return array{id: int, name: string, slug: string, taxonomy: string}|null
     */
    private function normalizeListedTerm($term, $allowInternal)
    {
        if (is_object($term)) {
            $id = isset($term->term_id) ? (int) $term->term_id : 0;
            $name = $this->stringValue(isset($term->name) ? $term->name : '');
            $slug = $this->stringValue(isset($term->slug) ? $term->slug : '');
            $taxonomy = $this->stringValue(isset($term->taxonomy) ? $term->taxonomy : '');
            $parent = $this->parentId(isset($term->parent) ? $term->parent : 0);
        } elseif (is_array($term)) {
            if (isset($term['term_id'])) {
                $id = (int) $term['term_id'];
            } elseif (isset($term['id'])) {
                $id = (int) $term['id'];
            } else {
                $id = 0;
            }
            $name = $this->stringValue(isset($term['name']) ? $term['name'] : '');
            $slug = $this->stringValue(isset($term['slug']) ? $term['slug'] : '');
            $taxonomy = $this->stringValue(isset($term['taxonomy']) ? $term['taxonomy'] : '');
            $parent = $this->parentId(isset($term['parent']) ? $term['parent'] : 0);
        } else {
            return null;
        }

        if ($id <= 0 || $taxonomy === '') {
            return null;
        }
        if (!$allowInternal && $this->isInternalTaxonomy($taxonomy)) {
            return null;
        }

        return array(
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'taxonomy' => $taxonomy,
            'parent' => $parent,
        );
    }

    /**
     * @param mixed $row
     * @return array{id: int, name: string, slug: string}|null
     */
    private function normalizeTranslationRow($row)
    {
        if (is_array($row)) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $name = $this->stringValue(isset($row['name']) ? $row['name'] : '');
            $slug = $this->stringValue(isset($row['slug']) ? $row['slug'] : '');
            $parent = $this->parentId(isset($row['parent']) ? $row['parent'] : 0);
        } elseif (is_int($row) || (is_string($row) && is_numeric($row))) {
            $id = (int) $row;
            $name = '';
            $slug = '';
            $parent = 0;
        } else {
            return null;
        }
        if ($id <= 0) {
            return null;
        }
        return array(
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'parent' => $parent,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     * @return array<string, array<string, mixed>>
     */
    private function orderTranslations(array $translations, $default)
    {
        $ordered = array();
        if ($default !== '' && isset($translations[$default])) {
            $ordered[$default] = $translations[$default];
            unset($translations[$default]);
        }
        ksort($translations);
        foreach ($translations as $lang => $row) {
            $ordered[$lang] = $row;
        }
        return $ordered;
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     * @return int
     */
    private function anchorId(array $translations, $default)
    {
        if ($default !== '' && isset($translations[$default]['id'])) {
            return (int) $translations[$default]['id'];
        }
        $ids = array();
        foreach ($translations as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids === array()) {
            return 0;
        }
        sort($ids, SORT_NUMERIC);
        return (int) $ids[0];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return int
     */
    private function compareItems($left, $right)
    {
        $leftTaxonomy = isset($left['taxonomy']) ? (string) $left['taxonomy'] : '';
        $rightTaxonomy = isset($right['taxonomy']) ? (string) $right['taxonomy'] : '';
        $byTaxonomy = strcmp($leftTaxonomy, $rightTaxonomy);
        if ($byTaxonomy !== 0) {
            return $byTaxonomy;
        }
        $leftId = $this->groupNumber(isset($left['group']) ? $left['group'] : '');
        $rightId = $this->groupNumber(isset($right['group']) ? $right['group'] : '');
        if ($leftId === $rightId) {
            return 0;
        }
        return ($leftId < $rightId) ? -1 : 1;
    }

    /**
     * @param mixed $group
     * @return int
     */
    private function groupNumber($group)
    {
        if (!is_string($group) || strpos($group, 'term:') !== 0) {
            return 0;
        }
        return (int) substr($group, 5);
    }

    /**
     * @param mixed $lang
     * @return string|null
     */
    private function languageKey($lang)
    {
        if (is_int($lang) || is_float($lang)) {
            $lang = (string) $lang;
        }
        if (!is_string($lang)) {
            return null;
        }
        $lang = trim($lang);
        if ($lang === '') {
            return null;
        }
        return $lang;
    }

    /**
     * @param mixed $slug
     * @return string
     */
    private function languageFromRelationshipSlug($slug)
    {
        $slug = $this->stringValue($slug);
        if (strpos($slug, 'pll_') === 0) {
            return substr($slug, 4);
        }
        return $slug;
    }

    /**
     * @param mixed $taxonomy
     * @return bool
     */
    private function isInternalTaxonomy($taxonomy)
    {
        return in_array($taxonomy, array(
            'language',
            'term_language',
            'post_translations',
            'term_translations',
            'nav_menu',
        ), true);
    }

    /**
     * @return bool
     */
    protected function apiReady()
    {
        return function_exists('pll_get_term_translations') && function_exists('get_terms');
    }

    /**
     * @return bool
     */
    protected function databaseReady()
    {
        global $wpdb;
        return is_object($wpdb)
            && method_exists($wpdb, 'get_results')
            && $this->table('terms') !== ''
            && $this->table('term_taxonomy') !== '';
    }

    /**
     * @param string $property
     * @return string
     */
    private function table($property)
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->$property) || !is_string($wpdb->$property)) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $wpdb->$property)) {
            return '';
        }
        return $wpdb->$property;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return bool
     */
    private function hasSnapshotItems($snapshot)
    {
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : array();
        $loose = isset($snapshot['untranslated']) && is_array($snapshot['untranslated']) ? $snapshot['untranslated'] : array();
        return $groups !== array() || $loose !== array();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot($default, $state)
    {
        return array(
            'state' => $state,
            'default_lang' => $default,
            'groups' => array(),
            'untranslated' => array(),
            'errors' => array(),
        );
    }

    /**
     * @param int $count
     * @return string
     */
    private function unassignedSentence($count, $language, $kind)
    {
        if ($kind === 'stored') {
            if ((int) $count === 1) {
                return sprintf(
                    $this->text('A term has no Polylang language. It is included under %s, the first language stored by Polylang, so it is not dropped.'),
                    $language
                );
            }
            return sprintf(
                $this->text('%1$d terms have no Polylang language. They are included under %2$s, the first language stored by Polylang, so they are not dropped.'),
                (int) $count,
                $language
            );
        }

        if ((int) $count === 1) {
            return sprintf(
                $this->text('A term has no Polylang language. It is included under the default language (%s) so it is not dropped.'),
                $language
            );
        }
        return sprintf(
            $this->text('%1$d terms have no Polylang language. They are included under the default language (%2$s) so they are not dropped.'),
            (int) $count,
            $language
        );
    }

    /**
     * Language to use when a term has none and the site has no default.
     *
     * @return string
     */
    private function fallbackLanguage()
    {
        $fromApi = $this->firstApiLanguage();
        if ($fromApi !== '') {
            return $fromApi;
        }
        return $this->firstStoredLanguage();
    }

    /**
     * @return string
     */
    private function firstApiLanguage()
    {
        if (!function_exists('pll_languages_list')) {
            return '';
        }
        try {
            $list = pll_languages_list(array('fields' => 'slug'));
        } catch (\Throwable $e) {
            return '';
        }
        if (!is_array($list)) {
            return '';
        }
        foreach ($list as $slug) {
            if (is_string($slug) && trim($slug) !== '') {
                return trim($slug);
            }
        }
        return '';
    }

    /**
     * @return string
     */
    private function firstStoredLanguage()
    {
        if (!$this->databaseReady()) {
            return '';
        }
        $terms = $this->table('terms');
        $taxonomy = $this->table('term_taxonomy');
        if ($terms === '' || $taxonomy === '') {
            return '';
        }
        $rows = $this->dbResults(
            "SELECT lang.slug
            FROM {$terms} AS lang
            INNER JOIN {$taxonomy} AS tt ON tt.term_id = lang.term_id
            WHERE tt.taxonomy = 'language'
            ORDER BY lang.term_group ASC, lang.slug ASC
            LIMIT 1"
        );
        if (!is_array($rows) || $rows === array() || !isset($rows[0]['slug'])) {
            return '';
        }
        $slug = trim($this->stringValue($rows[0]['slug']));
        if (strpos($slug, 'pll_') === 0) {
            $slug = substr($slug, 4);
        }
        return $slug;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function parentId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            return (int) $value;
        }
        if (is_float($value) && is_finite($value) && $value > 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * @param int $count
     * @return string
     */
    private function countSentence($count, $one, $many)
    {
        if ((int) $count === 1) {
            return $this->text($one);
        }
        return sprintf($this->text($many), (int) $count);
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

    /**
     * @param mixed $value
     * @return string
     */
    private function stringValue($value)
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
     * @param array<int, mixed> $errors
     * @return array<int, string>
     */
    private function uniqueStrings(array $errors)
    {
        $unique = array();
        foreach ($errors as $error) {
            if (!is_string($error) || $error === '' || isset($unique[$error])) {
                continue;
            }
            $unique[$error] = $error;
        }
        return array_values($unique);
    }
}
