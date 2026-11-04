<?php

/**
 * Tests for the Polylang post export.
 *
 * The file runs under PHPUnit and, without PHPUnit, as `php test-polylang-posts.php`.
 *
 * @package MigrationManager
 */

namespace PHPUnit\Framework;

if (!class_exists(TestCase::class, false)) {
    class TestCase
    {
        public function markTestSkipped($message = '')
        {
            throw new \RuntimeException('SKIP ' . $message);
        }

        protected function assertSame($expected, $actual, $message = '')
        {
            if ($expected !== $actual) {
                throw new \RuntimeException($message . "\nexpected " . var_export($expected, true) . "\ngot " . var_export($actual, true));
            }
        }

        protected function assertTrue($condition, $message = '')
        {
            if ($condition !== true) {
                throw new \RuntimeException($message !== '' ? $message : 'failed asserting true');
            }
        }

        protected function assertCount($expected, $actual, $message = '')
        {
            if (!is_array($actual) && !$actual instanceof \Countable) {
                throw new \RuntimeException('assertCount expected an array');
            }
            $this->assertSame($expected, count($actual), $message);
        }

        protected function assertContains($needle, $haystack, $message = '')
        {
            if (!is_array($haystack) || !in_array($needle, $haystack, true)) {
                throw new \RuntimeException($message !== '' ? $message : 'missing ' . var_export($needle, true));
            }
        }

        protected function assertNotContains($needle, $haystack, $message = '')
        {
            if (is_array($haystack) && in_array($needle, $haystack, true)) {
                throw new \RuntimeException($message !== '' ? $message : 'unexpected ' . var_export($needle, true));
            }
        }

        protected function assertArrayHasKey($key, $array, $message = '')
        {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                throw new \RuntimeException($message !== '' ? $message : 'missing key ' . $key);
            }
        }

        protected function assertArrayNotHasKey($key, $array, $message = '')
        {
            if (is_array($array) && array_key_exists($key, $array)) {
                throw new \RuntimeException($message !== '' ? $message : 'unexpected key ' . $key);
            }
        }

        protected function assertIsArray($actual, $message = '')
        {
            if (!is_array($actual)) {
                throw new \RuntimeException($message !== '' ? $message : 'not an array');
            }
        }

        protected function assertIsString($actual, $message = '')
        {
            if (!is_string($actual)) {
                throw new \RuntimeException($message !== '' ? $message : 'not a string');
            }
        }

        protected function assertStringNotContainsString($needle, $haystack, $message = '')
        {
            if (!is_string($haystack) || strpos($haystack, $needle) !== false) {
                throw new \RuntimeException($message !== '' ? $message : 'found ' . $needle);
            }
        }

        protected function assertStringContainsString($needle, $haystack, $message = '')
        {
            if (!is_string($haystack) || strpos($haystack, $needle) === false) {
                throw new \RuntimeException($message !== '' ? $message : 'missing ' . $needle);
            }
        }

        protected function assertDoesNotMatchRegularExpression($pattern, $string, $message = '')
        {
            if (@preg_match($pattern, $string)) {
                throw new \RuntimeException($message !== '' ? $message : 'matched ' . $string);
            }
        }

        protected function assertLessThan($expected, $actual, $message = '')
        {
            if (!($actual < $expected)) {
                throw new \RuntimeException($message !== '' ? $message : var_export($actual, true) . ' is not < ' . var_export($expected, true));
            }
        }
    }
}

namespace WMM\Tolka;

use PHPUnit\Framework\TestCase;

if (!class_exists(__NAMESPACE__ . '\\Polylang_Posts')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . '/');
    }
    require_once dirname(__DIR__, 2) . '/includes/tolka/class-polylang-posts.php';
}

class Polylang_Posts_Fixture extends Polylang_Posts
{
    public $present = true;
    public $default_lang = 'en';
    public $maps = array();
    public $posts = array();
    public $fail_collect = false;

    protected function polylang_present()
    {
        return $this->present;
    }

    protected function default_language()
    {
        return $this->default_lang;
    }

    protected function collect_maps(array &$errors)
    {
        if ($this->fail_collect) {
            return null;
        }
        return $this->maps;
    }

    protected function post_record($post_id)
    {
        $post_id = (int) $post_id;
        if (!isset($this->posts[$post_id])) {
            return null;
        }
        return $this->posts[$post_id];
    }

    public function statuses()
    {
        return $this->export_statuses();
    }
}

class Polylang_Posts_Api_Fixture extends Polylang_Posts
{
    public $default_lang = 'en';
    public $rows = array();
    public $rows_failed = false;
    public $api_maps = array();
    public $api_broken_ids = array();
    public $ids = array();
    public $posts = array();
    public $singletons = array();

    protected function polylang_present()
    {
        return true;
    }

    protected function default_language()
    {
        return $this->default_lang;
    }

    protected function api_ready()
    {
        return true;
    }

    protected function translation_term_rows()
    {
        if ($this->rows_failed) {
            return null;
        }
        return $this->rows;
    }

    protected function map_for_post($post_id)
    {
        $post_id = (int) $post_id;
        if (!empty($this->api_broken_ids[$post_id])) {
            return null;
        }
        if (!isset($this->api_maps[$post_id])) {
            return array();
        }
        return $this->api_maps[$post_id];
    }

    protected function candidate_post_ids()
    {
        return $this->ids;
    }

    protected function singleton_maps(array $known, array &$errors)
    {
        return $this->singletons;
    }

    protected function post_record($post_id)
    {
        $post_id = (int) $post_id;
        if (!isset($this->posts[$post_id])) {
            return null;
        }
        return $this->posts[$post_id];
    }
}

class Polylang_Posts_Page_Probe extends Polylang_Posts
{
    protected function page_size()
    {
        return 2;
    }

    public function ids()
    {
        return $this->candidate_post_ids();
    }
}

class Tolka_Posts_Fake_Db
{
    public $posts = 'wp_posts';
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $term_relationships = 'wp_term_relationships';
    public $options = 'wp_options';
    public $prefix = 'wp_';
    public $posts_rows = array();
    public $translation_terms = array();
    public $relationships = array();
    public $languages = array();
    public $option_value = null;
    public $fail = false;
    public $fail_results = false;
    public $ignore_offset = false;
    public $queries = array();

    public function prepare($query, ...$args)
    {
        $index = 0;
        $sql = preg_replace_callback('/%[dfs]/', function ($match) use (&$args, &$index) {
            if (!isset($args[$index])) {
                return $match[0];
            }
            $value = $args[$index];
            $index++;
            if ($match[0] === '%d') {
                return (string) (int) $value;
            }
            if ($match[0] === '%f') {
                return (string) (float) $value;
            }
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
        $this->queries[] = $sql;
        return $sql;
    }

    public function get_var($sql)
    {
        $this->queries[] = $sql;
        if ($this->fail) {
            return null;
        }
        if (strpos($sql, 'option_name') !== false) {
            return $this->option_value;
        }
        if (strpos($sql, "taxonomy = 'post_translations'") !== false) {
            return empty($this->translation_terms) ? null : 1;
        }
        if (strpos($sql, "taxonomy = 'language'") !== false) {
            return empty($this->languages) ? null : 1;
        }
        return null;
    }

    public function get_results($sql, $output = null)
    {
        $this->queries[] = $sql;
        if ($this->fail || $this->fail_results) {
            return null;
        }
        if (strpos($sql, 'post_excerpt') !== false) {
            return $this->filter_posts($sql);
        }
        if (strpos($sql, 'SELECT ID FROM') !== false) {
            return $this->page_ids($sql);
        }
        if (strpos($sql, 'description') !== false) {
            return $this->translation_terms;
        }
        if (strpos($sql, 'AS slug') !== false) {
            return $this->language_rows($sql);
        }
        if (strpos($sql, 'term_taxonomy_id =') !== false) {
            return $this->relationship_rows($sql);
        }
        return array();
    }

    public function get_row($sql, $output = null)
    {
        $this->queries[] = $sql;
        if ($this->fail) {
            return null;
        }
        if (preg_match('/ID = (\d+)/', $sql, $match)) {
            $id = (int) $match[1];
            return isset($this->posts_rows[$id]) ? $this->posts_rows[$id] : null;
        }
        return null;
    }

    private function page_ids($sql)
    {
        $limit = 200;
        $offset = 0;
        if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $match)) {
            $limit = (int) $match[1];
            $offset = (int) $match[2];
        }
        if ($this->ignore_offset) {
            $offset = 0;
        }
        $ids = array_keys($this->posts_rows);
        sort($ids, SORT_NUMERIC);
        $filtered = array();
        foreach ($ids as $id) {
            $row = $this->posts_rows[$id];
            if ($row['post_status'] === 'auto-draft' || $row['post_type'] === 'revision') {
                continue;
            }
            $filtered[] = (int) $id;
        }
        $slice = array_slice($filtered, $offset, $limit);
        $rows = array();
        foreach ($slice as $id) {
            $rows[] = array('ID' => $id);
        }
        return $rows;
    }

    private function filter_posts($sql)
    {
        $ids = array_keys($this->posts_rows);
        if (preg_match('/IN \(([^)]+)\)/', $sql, $match)) {
            $ids = array_map('intval', explode(',', $match[1]));
        }
        $rows = array();
        foreach ($ids as $id) {
            if (isset($this->posts_rows[$id])) {
                $rows[] = $this->posts_rows[$id];
            }
        }
        return $rows;
    }

    private function language_rows($sql)
    {
        $only = null;
        if (preg_match('/IN \(([^)]+)\)/', $sql, $match)) {
            $only = array_map('intval', explode(',', $match[1]));
        }
        $rows = array();
        foreach ($this->languages as $object_id => $slug) {
            $object_id = (int) $object_id;
            if ($only !== null && !in_array($object_id, $only, true)) {
                continue;
            }
            $rows[] = array(
                'object_id' => $object_id,
                'slug' => $slug,
            );
        }
        return $rows;
    }

    private function relationship_rows($sql)
    {
        if (!preg_match('/term_taxonomy_id = (\d+)/', $sql, $match)) {
            return array();
        }
        $term_taxonomy_id = (int) $match[1];
        $ids = isset($this->relationships[$term_taxonomy_id]) ? $this->relationships[$term_taxonomy_id] : array();
        $rows = array();
        foreach ($ids as $id) {
            $rows[] = array('object_id' => (int) $id);
        }
        return $rows;
    }
}

class Polylang_Posts_Test extends TestCase
{
    private $previous_wpdb;
    private $had_wpdb = false;

    public function setUp(): void
    {
        $this->had_wpdb = array_key_exists('wpdb', $GLOBALS);
        $this->previous_wpdb = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
    }

    public function tearDown(): void
    {
        if ($this->had_wpdb) {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
    }

    public function test_inactive_polylang_returns_an_empty_list_and_a_sentence()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        unset($GLOBALS['wpdb']);
        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active. Post translations were not exported.'),
            $result['errors']
        );
    }

    public function test_unreadable_storage_returns_a_failure_sentence()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->fail_collect = true;
        $result = $exporter->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Post translations could not be read. Polylang\'s translation data is missing or the database query failed.'),
            $result['errors']
        );
    }

    public function test_empty_catalog_is_an_empty_state_without_an_error()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array();
        $result = $exporter->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_group_uses_the_default_language_post_and_keeps_each_translation()
    {
        $content = '<!-- wp:paragraph --><p>Hej &amp; co</p>';
        $exporter = new Polylang_Posts_Fixture();
        $exporter->default_lang = 'en';
        $exporter->maps = array(
            array('de' => 3, 'en' => 20),
        );
        $exporter->posts = array(
            20 => $this->record(20, 'page', 'Hello', 'hello', 'draft', 'Short', 'Hello body'),
            3 => $this->record(3, 'post', 'Hallo', 'hallo', 'publish', 'Kurz', $content),
        );

        $result = $exporter->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame('post:20', $item['group']);
        $this->assertSame('page', $item['type']);
        $this->assertSame('draft', $item['translations']['en']['status']);
        $this->assertSame('Hello', $item['translations']['en']['title']);
        $this->assertSame('hello', $item['translations']['en']['slug']);
        $this->assertSame('Short', $item['translations']['en']['excerpt']);
        $this->assertSame('Hello body', $item['translations']['en']['content']);
        $this->assertSame(3, $item['translations']['de']['id']);
        $this->assertSame($content, $item['translations']['de']['content']);
        $this->assertSame(array('de', 'en'), array_keys($item['translations']));
    }

    public function test_untranslated_draft_is_its_own_group()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->default_lang = 'en';
        $exporter->maps = array(
            array('sv' => 9),
        );
        $exporter->posts = array(
            9 => $this->record(9, 'post', '', '', 'draft', '', 'Utkast'),
        );

        $result = $exporter->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame('post:9', $item['group']);
        $this->assertSame('post', $item['type']);
        $this->assertSame(array(
            'id' => 9,
            'title' => '',
            'slug' => '',
            'status' => 'draft',
            'excerpt' => '',
            'content' => 'Utkast',
        ), $item['translations']['sv']);
    }

    public function test_pending_future_private_and_trash_keep_their_status()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 1),
            array('en' => 2),
            array('en' => 3),
            array('en' => 4),
        );
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'Pending', 'pending', 'pending'),
            2 => $this->record(2, 'post', 'Future', 'future', 'future'),
            3 => $this->record(3, 'post', 'Private', 'private', 'private'),
            4 => $this->record(4, 'post', 'Trash', 'trash', 'trash'),
        );

        $result = $exporter->export();
        $statuses = array();
        foreach ($result['items'] as $item) {
            $statuses[] = $item['translations']['en']['status'];
        }
        $this->assertSame(array('pending', 'future', 'private', 'trash'), $statuses);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_missing_post_is_omitted_with_a_sentence_and_the_group_remains()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 12, 'de' => 15),
        );
        $exporter->posts = array(
            12 => $this->record(12, 'post', 'Keep', 'keep', 'publish'),
        );

        $result = $exporter->export();

        $this->assertCount(1, $result['items']);
        $this->assertSame('post:12', $result['items'][0]['group']);
        $this->assertArrayNotHasKey('de', $result['items'][0]['translations']);
        $this->assertSame(
            array('Post 15 is part of translation group post:12, but that post no longer exists. It was left out of the export.'),
            $result['errors']
        );
    }

    public function test_group_of_missing_posts_is_skipped_with_a_sentence()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 100, 'de' => 101),
        );
        $result = $exporter->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('A translation group pointed at posts 100, 101, but those posts no longer exist. That group was skipped.'),
            $result['errors']
        );
    }

    public function test_duplicate_post_stays_in_the_first_group()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 4, 'de' => 5),
            array('en' => 5, 'fr' => 6),
        );
        $exporter->posts = array(
            4 => $this->record(4, 'post', 'A', 'a', 'publish'),
            5 => $this->record(5, 'post', 'B', 'b', 'publish'),
            6 => $this->record(6, 'post', 'C', 'c', 'draft'),
        );

        $result = $exporter->export();
        $groups = array();
        foreach ($result['items'] as $item) {
            $groups[$item['group']] = array_keys($item['translations']);
        }
        $this->assertSame(array('en', 'de'), $groups['post:4']);
        $this->assertSame(array('fr'), $groups['post:6']);
        $this->assertSame(
            array('Post 5 belongs to more than one translation group. It was kept in post:4 and left out of the later group.'),
            $result['errors']
        );
        $this->assertSame('draft', $result['items'][1]['translations']['fr']['status']);
    }

    public function test_revision_and_automatic_draft_are_left_out()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 8, 'de' => 9),
            array('en' => 11),
            array('en' => 12, 'de' => 13),
        );
        $exporter->posts = array(
            8 => $this->record(8, 'post', 'Live', 'live', 'publish'),
            9 => $this->record(9, 'revision', 'Old', 'old', 'inherit'),
            11 => $this->record(11, 'post', 'Shell', '', 'auto-draft'),
            12 => $this->record(12, 'post', 'Real', 'real', 'publish'),
            13 => $this->record(13, 'post', 'Shell sibling', '', 'auto-draft'),
        );

        $result = $exporter->export();
        $groups = array();
        foreach ($result['items'] as $item) {
            $groups[] = $item['group'];
        }
        $this->assertSame(array('post:8', 'post:12'), $groups);
        $this->assertSame(
            array(
                'Post 9 is a revision, so it was left out of translation group post:8.',
                'Post 13 is an automatic draft, so it was left out of translation group post:12.',
            ),
            $result['errors']
        );
    }

    public function test_multilingual_group_without_the_default_language_names_the_lowest_id()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->default_lang = 'en';
        $exporter->maps = array(
            array('de' => 40, 'sv' => 41),
        );
        $exporter->posts = array(
            40 => $this->record(40, 'post', 'DE', 'de', 'publish'),
            41 => $this->record(41, 'post', 'SV', 'sv', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame('post:40', $result['items'][0]['group']);
        $this->assertSame(
            array('Translation group post:40 does not include the default language (en). The group is named after post 40.'),
            $result['errors']
        );
    }

    public function test_string_ids_and_blank_languages_are_normalized()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array(
                'en' => '12',
                '' => 4,
                'sv' => 0,
                'de' => 15,
                'fr' => 'nope',
                'de-extra' => 12,
            ),
        );
        $exporter->posts = array(
            12 => $this->record(12, 'post', 'En', 'en', 'publish'),
            15 => $this->record(15, 'post', 'De', 'de', 'publish'),
        );

        $result = $exporter->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('en', 'de'), array_keys($result['items'][0]['translations']));
        $this->assertSame(12, $result['items'][0]['translations']['en']['id']);
    }

    public function test_repeated_export_is_identical()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(array('en' => 1, 'de' => 2));
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'One', 'one', 'draft'),
            2 => $this->record(2, 'post', 'Two', 'two', 'publish'),
        );
        $this->assertSame($exporter->export(), $exporter->export());
    }

    public function test_status_list_includes_drafts_and_skips_automatic_drafts()
    {
        $statuses = (new Polylang_Posts_Fixture())->statuses();
        $this->assertContains('draft', $statuses);
        $this->assertContains('pending', $statuses);
        $this->assertContains('future', $statuses);
        $this->assertContains('private', $statuses);
        $this->assertNotContains('auto-draft', $statuses);
    }

    public function test_api_translation_map_wins_over_a_stale_description()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows = array(
            array(
                'term_taxonomy_id' => 1,
                'description' => array('en' => 1, 'de' => 2),
            ),
        );
        $exporter->api_maps = array(
            1 => array('en' => 1, 'de' => 2, 'fr' => 3),
        );
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'EN', 'en', 'publish'),
            2 => $this->record(2, 'post', 'DE', 'de', 'publish'),
            3 => $this->record(3, 'post', 'FR', 'fr', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame(array('en', 'de', 'fr'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['fr']['status']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_empty_api_map_keeps_the_stored_description()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows = array(
            array(
                'term_taxonomy_id' => 1,
                'description' => array('en' => 1, 'de' => 2),
            ),
        );
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'EN', 'en', 'publish'),
            2 => $this->record(2, 'post', 'DE', 'de', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame('post:1', $result['items'][0]['group']);
        $this->assertArrayHasKey('de', $result['items'][0]['translations']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_broken_api_falls_back_to_the_stored_description()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows = array(
            array(
                'term_taxonomy_id' => 1,
                'description' => array('en' => 4, 'de' => 5),
            ),
            array(
                'term_taxonomy_id' => 2,
                'description' => array('en' => 6),
            ),
        );
        $exporter->api_broken_ids = array(4 => true);
        $exporter->posts = array(
            4 => $this->record(4, 'post', 'Four', 'four', 'publish'),
            5 => $this->record(5, 'post', 'Five', 'five', 'draft'),
            6 => $this->record(6, 'post', 'Six', 'six', 'pending'),
        );

        $result = $exporter->export();
        $this->assertCount(2, $result['items']);
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('pending', $result['items'][1]['translations']['en']['status']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_scan_uses_api_maps_when_stored_rows_cannot_be_read()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows_failed = true;
        $exporter->ids = array(4, 5, 7);
        $exporter->api_maps = array(
            4 => array('en' => 4, 'de' => 5),
            7 => array('sv' => 7),
        );
        $exporter->posts = array(
            4 => $this->record(4, 'post', 'Four', 'four', 'publish'),
            5 => $this->record(5, 'post', 'Five', 'five', 'draft'),
            7 => $this->record(7, 'post', 'Seven', '', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame(array('post:4', 'post:7'), $this->groups($result));
        $this->assertSame('Seven', $result['items'][1]['translations']['sv']['title']);
        $this->assertSame('', $result['items'][1]['translations']['sv']['slug']);
        $this->assertSame('draft', $result['items'][1]['translations']['sv']['status']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_scan_keeps_groups_found_before_the_api_stops()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows_failed = true;
        $exporter->ids = array(4, 8);
        $exporter->api_maps = array(
            4 => array('en' => 4),
        );
        $exporter->api_broken_ids = array(8 => true);
        $exporter->posts = array(
            4 => $this->record(4, 'post', 'Four', 'four', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame(array('post:4'), $this->groups($result));
        $this->assertSame(
            array('Polylang stopped returning post translations partway through the export. Posts found before that point are included.'),
            $result['errors']
        );
    }

    public function test_database_export_includes_groups_untranslated_drafts_and_other_statuses()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 3,
                'description' => serialize(array('de' => '2', 'en' => '9')),
            ),
        );
        $fake->languages = array(
            2 => 'de',
            9 => 'en',
            30 => 'sv',
            31 => 'en',
            32 => 'en',
            33 => 'en',
        );
        $html = '<!-- wp:paragraph --><p>Hej &amp; co</p>';
        $fake->posts_rows = array(
            2 => $this->db_post(2, 'publish', 'Hallo', 'hallo', 'post', 'Kurz', $html),
            9 => $this->db_post(9, 'draft', 'Hello', 'hello', 'page', 'Short', 'Hello body'),
            30 => $this->db_post(30, 'draft', '', '', 'post', '', 'Utkast'),
            31 => $this->db_post(31, 'pending', 'Review', 'review', 'post', '', 'Needs review'),
            32 => $this->db_post(32, 'future', 'Soon', 'soon', 'post', '', 'Scheduled'),
            33 => $this->db_post(33, 'private', 'Secret', 'secret', 'post', '', 'Hidden'),
        );

        $result = (new Polylang_Posts())->export();
        $second = (new Polylang_Posts())->export();

        $this->assertSame($result, $second);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('post:9', 'post:30', 'post:31', 'post:32', 'post:33'), $this->groups($result));
        $group = $result['items'][0];
        $this->assertSame('page', $group['type']);
        $this->assertSame('draft', $group['translations']['en']['status']);
        $this->assertSame(2, $group['translations']['de']['id']);
        $this->assertSame($html, $group['translations']['de']['content']);
        $this->assertSame('draft', $result['items'][1]['translations']['sv']['status']);
        $this->assertSame('', $result['items'][1]['translations']['sv']['title']);
        $this->assertSame('', $result['items'][1]['translations']['sv']['slug']);
        foreach ($fake->queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql);
        }
    }

    public function test_empty_description_is_rebuilt_from_language_relationships()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array('term_taxonomy_id' => 5, 'description' => ''),
        );
        $fake->relationships = array(5 => array(4, 6));
        $fake->languages = array(4 => 'en', 6 => 'de');
        $fake->posts_rows = array(
            4 => $this->db_post(4, 'draft', 'Hello', 'hello', 'post', '', 'Body'),
            6 => $this->db_post(6, 'publish', 'Hallo', 'hallo', 'post', '', 'Text'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:4', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('publish', $result['items'][0]['translations']['de']['status']);
    }

    public function test_invalid_descriptions_are_reported_once()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array('term_taxonomy_id' => 1, 'description' => 'not-a-map'),
            array('term_taxonomy_id' => 2, 'description' => 'still-bad'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('2 post translation groups could not be read because their stored descriptions are invalid. Those groups were skipped.'),
            $result['errors']
        );
    }

    public function test_option_without_posts_is_an_empty_state()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_database_failure_after_polylang_is_known_is_a_sentence()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->fail_results = true;

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Post translations could not be read. Polylang\'s translation data is missing or the database query failed.'),
            $result['errors']
        );
    }

    public function test_id_scan_pages_through_the_catalog_and_stops_when_a_page_repeats()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        for ($id = 1; $id <= 5; $id++) {
            $fake->posts_rows[$id] = $this->db_post($id, 'draft', 'Post ' . $id, 'post-' . $id);
        }
        $probe = new Polylang_Posts_Page_Probe();
        $this->assertSame(array(1, 2, 3, 4, 5), $probe->ids());

        $offsets = array();
        foreach ($fake->queries as $sql) {
            if (preg_match('/OFFSET (\d+)/', $sql, $match)) {
                $offsets[] = (int) $match[1];
            }
        }
        $this->assertSame(array(0, 2, 4), array_values(array_unique($offsets)));

        $fake->queries = array();
        $fake->ignore_offset = true;
        $stopped = $probe->ids();
        $this->assertSame(array(1, 2), $stopped);
        $this->assertLessThan(6, count($fake->queries));
    }

    public function test_source_only_reads_polylang_data()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/class-polylang-posts.php');
        $this->assertIsString($source);
        $forbidden = array(
            'update_post_meta(',
            'update_term_meta(',
            'update_option(',
            'delete_option(',
            'delete_post_meta(',
            'wp_delete_post(',
            'wp_insert_post(',
            'wp_update_post(',
            '->update(',
            '->delete(',
            '->insert(',
            '->query(',
            'unlink(',
            'file_put_contents(',
        );
        foreach ($forbidden as $token) {
            $this->assertStringNotContainsString($token, $source);
        }
        $this->assertStringContainsString('namespace WMM\\Tolka;', $source);
        $this->assertStringContainsString('function export()', $source);
    }

    public function test_real_api_exports_a_draft_translation_and_an_untranslated_post()
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is not available.');
        }
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-posts.php';
        $args_file = tempnam(sys_get_temp_dir(), 'tolka-args');
        $script = tempnam(sys_get_temp_dir(), 'tolka-api');
        file_put_contents($script, $this->api_subprocess($class, $args_file));
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        $args = json_decode((string) file_get_contents($args_file), true);
        @unlink($script);
        @unlink($args_file);

        $this->assertIsArray($args);
        $this->assertContains('draft', $args['post_status']);
        $this->assertContains('pending', $args['post_status']);
        $this->assertContains('future', $args['post_status']);
        $this->assertContains('private', $args['post_status']);
        $this->assertNotContains('auto-draft', $args['post_status']);
        $this->assertTrue($args['suppress_filters']);
        $this->assertSame('', $args['lang']);

        $json = '';
        if (is_string($output) && strpos($output, '__JSON__') !== false) {
            $json = substr($output, strpos($output, '__JSON__') + 8);
        }
        $result = json_decode($json, true);
        $this->assertIsArray($result);
        $this->assertSame(array(), $result['errors']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('post:4', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Hej', $result['items'][0]['translations']['de']['title']);
        $this->assertSame('post:9', $result['items'][1]['group']);
        $this->assertSame('', $result['items'][1]['translations']['sv']['title']);
        $this->assertSame('draft', $result['items'][1]['translations']['sv']['status']);
        $this->assertSame('Utkast', $result['items'][1]['translations']['sv']['content']);
    }

    private function skip_when_wordpress_or_polylang_is_loaded()
    {
        if (function_exists('get_post') || function_exists('get_option') || function_exists('get_posts') || function_exists('pll_get_post_translations') || function_exists('pll_default_language') || class_exists('PLL') || class_exists('Polylang')) {
            $this->markTestSkipped('This case runs without WordPress or Polylang loaded.');
        }
    }

    private function use_fake_db()
    {
        $fake = new Tolka_Posts_Fake_Db();
        $GLOBALS['wpdb'] = $fake;
        return $fake;
    }

    private function record($id, $type, $title, $slug, $status, $excerpt = '', $content = '')
    {
        return array(
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'excerpt' => $excerpt,
            'content' => $content,
        );
    }

    private function db_post($id, $status, $title, $slug, $type = 'post', $excerpt = '', $content = '')
    {
        return array(
            'ID' => $id,
            'post_type' => $type,
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => $status,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
        );
    }

    private function groups(array $result)
    {
        $groups = array();
        foreach ($result['items'] as $item) {
            $groups[] = $item['group'];
        }
        return $groups;
    }

    private function api_subprocess($class, $args_file)
    {
        $class = var_export($class, true);
        $args_file = var_export($args_file, true);
        return <<<PHP
<?php
define('ABSPATH', '/tmp/tolka-posts/');
function pll_get_post_translations(\$id) {
    if ((int) \$id === 4) {
        return array('en' => 4, 'de' => 5);
    }
    return false;
}
function pll_get_post_language(\$id, \$field = 'slug') {
    if ((int) \$id === 9) {
        return 'sv';
    }
    return false;
}
function pll_default_language() {
    return 'en';
}
function get_posts(\$args) {
    static \$calls = 0;
    \$calls++;
    if (\$calls === 1) {
        file_put_contents({$args_file}, json_encode(\$args));
        return array(4, 9);
    }
    return array();
}
function get_post(\$id) {
    \$posts = array(
        4 => array('ID' => 4, 'post_type' => 'post', 'post_title' => 'Hello', 'post_name' => 'hello', 'post_status' => 'draft', 'post_excerpt' => '', 'post_content' => 'Hi'),
        5 => array('ID' => 5, 'post_type' => 'post', 'post_title' => 'Hej', 'post_name' => 'hej', 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => 'Hej'),
        9 => array('ID' => 9, 'post_type' => 'post', 'post_title' => '', 'post_name' => '', 'post_status' => 'draft', 'post_excerpt' => '', 'post_content' => 'Utkast'),
    );
    \$id = (int) \$id;
    if (!isset(\$posts[\$id])) {
        return null;
    }
    return (object) \$posts[\$id];
}
require {$class};
\$result = (new WMM\\Tolka\\Polylang_Posts())->export();
echo "__JSON__" . json_encode(\$result);
PHP;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $test = new Polylang_Posts_Test();
    $methods = get_class_methods($test);
    $failed = 0;
    $passed = 0;
    $skipped = 0;
    foreach ($methods as $method) {
        if (strpos($method, 'test_') !== 0) {
            continue;
        }
        $case = new Polylang_Posts_Test();
        try {
            if (method_exists($case, 'setUp')) {
                $case->setUp();
            }
            $case->{$method}();
            if (method_exists($case, 'tearDown')) {
                $case->tearDown();
            }
            echo "ok {$method}\n";
            $passed++;
        } catch (\Throwable $e) {
            if (method_exists($case, 'tearDown')) {
                try {
                    $case->tearDown();
                } catch (\Throwable $ignored) {
                }
            }
            if (strpos($e->getMessage(), 'SKIP ') === 0) {
                echo "skip {$method}\n";
                $skipped++;
                continue;
            }
            echo "FAIL {$method}\n{$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n";
            $failed++;
        }
    }
    echo "{$passed} passed, {$skipped} skipped, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}
