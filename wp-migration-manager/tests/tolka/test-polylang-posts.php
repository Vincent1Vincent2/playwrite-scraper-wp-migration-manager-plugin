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

class Polylang_Posts_Language_Fixture extends Polylang_Posts_Fixture
{
    public $actual = array();

    protected function actual_languages(array $ids)
    {
        return $this->actual;
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

class Polylang_Posts_Group_Probe extends Polylang_Posts
{
    public function dedupe(array $items)
    {
        return $this->unique_groups($items);
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
    public $language_terms = array();
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
        if (strpos($sql, 'SELECT post_status') !== false && preg_match('/ID = (\d+)/', $sql, $match)) {
            $id = (int) $match[1];
            return isset($this->posts_rows[$id]['post_status']) ? $this->posts_rows[$id]['post_status'] : null;
        }
        if (strpos($sql, 'SELECT post_type') !== false && preg_match('/ID = (\d+)/', $sql, $match)) {
            $id = (int) $match[1];
            return isset($this->posts_rows[$id]['post_type']) ? $this->posts_rows[$id]['post_type'] : null;
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
        if (strpos($sql, 'SELECT ID, post_parent FROM') !== false) {
            if (!preg_match('/ID = (\d+)/', $sql, $match)) {
                return array();
            }
            $id = (int) $match[1];
            if (!isset($this->posts_rows[$id])) {
                return array();
            }
            $parent = isset($this->posts_rows[$id]['post_parent']) ? (int) $this->posts_rows[$id]['post_parent'] : 0;
            return array(array('ID' => $id, 'post_parent' => $parent));
        }
        if (strpos($sql, 'SELECT ID, post_type FROM') !== false) {
            return $this->type_rows($sql);
        }
        if (strpos($sql, 'post_excerpt') !== false) {
            return $this->filter_posts($sql);
        }
        if (strpos($sql, 'SELECT ID FROM') !== false) {
            return $this->page_ids($sql);
        }
        if (strpos($sql, 'AS slug') !== false && strpos($sql, 'description') !== false) {
            return $this->language_terms;
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

    private function type_rows($sql)
    {
        $ids = array_keys($this->posts_rows);
        if (preg_match('/\bID IN \(([^)]+)\)/i', $sql, $match)) {
            $ids = array_map('intval', explode(',', $match[1]));
        }
        $rows = array();
        foreach ($ids as $id) {
            if (isset($this->posts_rows[$id])) {
                $rows[] = array(
                    'ID' => (int) $id,
                    'post_type' => $this->posts_rows[$id]['post_type'],
                );
            }
        }
        return $rows;
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
            $skip_types = array(
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
            if ($row['post_status'] === 'auto-draft' || in_array($row['post_type'], $skip_types, true)) {
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
        if (preg_match('/\bID IN \(([^)]+)\)/i', $sql, $match)) {
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
        if (preg_match('/object_id IN \(([^)]+)\)/', $sql, $match)) {
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

    public function test_post_with_no_translation_is_its_own_group()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(12 => 'en');
        $fake->posts_rows = array(
            12 => $this->db_post(12, 'publish', 'Only English', 'only-english', 'post', 'Standalone', 'No other language yet.'),
            13 => $this->db_post(13, 'publish', 'No language', 'no-language'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('post:12'), $this->groups($result));
        $item = $result['items'][0];
        $this->assertSame('post', $item['type']);
        $this->assertSame(array('en'), array_keys($item['translations']));
        $this->assertSame(12, $item['translations']['en']['id']);
        $this->assertSame('Only English', $item['translations']['en']['title']);
        $this->assertSame('only-english', $item['translations']['en']['slug']);
        $this->assertSame('publish', $item['translations']['en']['status']);
        $this->assertSame('Standalone', $item['translations']['en']['excerpt']);
        $this->assertSame('No other language yet.', $item['translations']['en']['content']);
    }

    public function test_draft_is_exported_with_its_status_and_content()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 20,
                'description' => serialize(array('en' => 30, 'sv' => 31)),
            ),
        );
        $fake->languages = array(30 => 'en', 31 => 'sv');
        $fake->posts_rows = array(
            30 => $this->db_post(30, 'publish', 'Published', 'published', 'page', '', 'Live page'),
            31 => $this->db_post(31, 'draft', '', '', 'page', 'Kort', 'Utkastet'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('post:30'), $this->groups($result));
        $draft = $result['items'][0]['translations']['sv'];
        $this->assertSame(31, $draft['id']);
        $this->assertSame('', $draft['title']);
        $this->assertSame('', $draft['slug']);
        $this->assertSame('draft', $draft['status']);
        $this->assertSame('Kort', $draft['excerpt']);
        $this->assertSame('Utkastet', $draft['content']);
        $this->assertSame('publish', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('page', $result['items'][0]['type']);
    }

    public function test_polylang_missing_returns_no_posts_and_an_error()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->posts_rows = array(
            1 => $this->db_post(1, 'publish', 'Ordinary', 'ordinary'),
            2 => $this->db_post(2, 'draft', 'Unfinished', 'unfinished'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array('items', 'errors'), array_keys($result));
        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active. Post translations were not exported.'),
            $result['errors']
        );
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Ordinary', $encoded);
        $this->assertStringNotContainsString('Unfinished', $encoded);
    }

    public function test_revision_is_left_out()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 21,
                'description' => serialize(array('en' => 40, 'de' => 41)),
            ),
            array(
                'term_taxonomy_id' => 22,
                'description' => serialize(array('en' => 42)),
            ),
        );
        $fake->languages = array(40 => 'en', 41 => 'de', 42 => 'en');
        $fake->posts_rows = array(
            40 => $this->db_post(40, 'publish', 'Live', 'live', 'post', '', 'Current text'),
            41 => $this->db_post(41, 'inherit', 'Live autosave', '40-autosave-v1', 'revision', '', 'Older text'),
            42 => $this->db_post(42, 'inherit', 'Orphan autosave', '42-revision-v1', 'revision'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array('post:40'), $this->groups($result));
        $this->assertSame(array('en'), array_keys($result['items'][0]['translations']));
        $this->assertSame('Current text', $result['items'][0]['translations']['en']['content']);
        $this->assertSame('publish', $result['items'][0]['translations']['en']['status']);
        $this->assertSame(
            array('Post 41 is a revision, so it was left out of translation group post:40.'),
            $result['errors']
        );
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Older text', $encoded);
        $this->assertStringNotContainsString('Orphan autosave', $encoded);
        foreach ($fake->queries as $sql) {
            if (strpos($sql, 'post_content') !== false) {
                $this->assertStringNotContainsString('41', $sql);
                $this->assertStringNotContainsString('42', $sql);
            }
        }
    }

    public function test_revision_of_an_automatic_draft_is_left_out()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 25,
                'description' => serialize(array('en' => 19)),
            ),
        );
        $revision = $this->db_post(19, 'inherit', 'Shell autosave', '18-revision-v1', 'revision', '', 'Empty shell text');
        $revision['post_parent'] = 18;
        $fake->posts_rows = array(
            18 => $this->db_post(18, 'auto-draft', '', '', 'post'),
            19 => $revision,
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Empty shell text', $encoded);
    }

    public function test_revision_group_exports_the_draft_parent_instead()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 24,
                'description' => serialize(array('en' => 9)),
            ),
        );
        $revision = $this->db_post(9, 'inherit', 'Autosave', '8-revision-v1', 'revision', '', 'Half a sentence');
        $revision['post_parent'] = 8;
        $fake->posts_rows = array(
            8 => $this->db_post(8, 'draft', 'Notes', '', 'post', '', 'Still writing'),
            9 => $revision,
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array('post:8'), $this->groups($result));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('', $result['items'][0]['translations']['en']['slug']);
        $this->assertSame('Still writing', $result['items'][0]['translations']['en']['content']);
        $this->assertSame(
            array('Post 9 is a revision of post 8. The export uses post 8 instead.'),
            $result['errors']
        );
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Half a sentence', $encoded);
    }

    public function test_draft_is_kept_when_its_autosave_also_has_a_language()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(8 => 'en', 9 => 'en');
        $fake->posts_rows = array(
            8 => $this->db_post(8, 'draft', 'Notes', '', 'post', '', 'Still writing'),
            9 => $this->db_post(9, 'inherit', 'Notes autosave', '8-revision-v1', 'revision', '', 'Half a sentence'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('post:8'), $this->groups($result));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Still writing', $result['items'][0]['translations']['en']['content']);
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Half a sentence', $encoded);
        foreach ($fake->queries as $sql) {
            if (strpos($sql, 'post_content') !== false) {
                $this->assertDoesNotMatchRegularExpression('/\b9\b/', $sql);
            }
        }
    }

    public function test_revision_in_the_default_language_does_not_name_the_group()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 23,
                'description' => serialize(array('en' => 4, 'de' => 9)),
            ),
        );
        $fake->languages = array(4 => 'en', 9 => 'de');
        $fake->posts_rows = array(
            4 => $this->db_post(4, 'inherit', 'Autosave', '4-revision-v1', 'revision', '', 'Stale English'),
            9 => $this->db_post(9, 'draft', 'Entwurf', '', 'post', '', 'Deutscher Entwurf'),
        );

        $result = (new Polylang_Posts())->export();

        $this->assertSame(array('post:9'), $this->groups($result));
        $this->assertSame(array('de'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('Deutscher Entwurf', $result['items'][0]['translations']['de']['content']);
        $this->assertSame(
            array('Post 4 is a revision, so it was left out of translation group post:9.'),
            $result['errors']
        );
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Stale English', $encoded);
        foreach ($fake->queries as $sql) {
            if (strpos($sql, 'post_content') !== false) {
                $this->assertDoesNotMatchRegularExpression('/\b4\b/', $sql);
            }
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

    public function test_menu_items_are_not_exported_as_posts()
    {
        $exporter = new Polylang_Posts_Fixture();
        $exporter->maps = array(
            array('en' => 50),
            array('en' => 8, 'de' => 51),
        );
        $exporter->posts = array(
            50 => $this->record(50, 'nav_menu_item', 'Home', 'home', 'publish'),
            8 => $this->record(8, 'page', 'About', 'about', 'draft', '', 'About us'),
            51 => $this->record(51, 'nav_menu_item', 'Start', 'start', 'publish'),
        );

        $result = $exporter->export();
        $this->assertSame(array('post:8'), $this->groups($result));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('About us', $result['items'][0]['translations']['en']['content']);
        $this->assertSame(
            array('Post 51 is a menu item, so it was left out of translation group post:8.'),
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
        $this->assertSame(array('en', 'de'), array_keys($result['items'][0]['translations']));
        $this->assertSame(12, $result['items'][0]['translations']['en']['id']);
        $this->assertSame(
            array('Post 12 was listed as both en and de-extra in the same translation group. Only one entry was kept.'),
            $result['errors']
        );
    }

    public function test_stale_stored_language_is_replaced_with_the_posts_language()
    {
        $exporter = new Polylang_Posts_Language_Fixture();
        $exporter->default_lang = 'sv';
        $exporter->maps = array(
            array('en' => 5, 'de' => 6),
        );
        $exporter->actual = array(5 => 'sv', 6 => 'de');
        $exporter->posts = array(
            5 => $this->record(5, 'post', 'Utkast', 'utkast', 'draft', '', 'Ej publicerad'),
            6 => $this->record(6, 'post', 'Hallo', 'hallo', 'publish'),
        );

        $result = $exporter->export();
        $this->assertSame('post:5', $result['items'][0]['group']);
        $this->assertSame(array('sv', 'de'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['sv']['status']);
        $this->assertSame(
            array('Post 5 is stored as en in its translation group, but its language is sv. It was exported as sv.'),
            $result['errors']
        );
    }

    public function test_stored_language_is_kept_when_the_real_language_is_already_used()
    {
        $exporter = new Polylang_Posts_Language_Fixture();
        $exporter->maps = array(
            array('en' => 5, 'sv' => 7),
        );
        $exporter->actual = array(5 => 'sv', 7 => 'sv');
        $exporter->posts = array(
            5 => $this->record(5, 'post', 'Five', 'five', 'draft'),
            7 => $this->record(7, 'post', 'Seven', 'seven', 'publish'),
        );

        $result = $exporter->export();
        $this->assertSame(array('en', 'sv'), array_keys($result['items'][0]['translations']));
        $this->assertSame(5, $result['items'][0]['translations']['en']['id']);
        $this->assertSame(
            array('Post 5 is stored as en in its translation group, but its language is sv. The stored language was kept because sv is already used in that group.'),
            $result['errors']
        );
    }

    public function test_a_long_error_list_keeps_the_first_twenty_and_a_count()
    {
        $result = $this->missing_translation_fixture(22)->export();
        $this->assertCount(22, $result['items']);
        $this->assertCount(21, $result['errors']);
        $this->assertSame('Post 1001 is part of translation group post:1, but that post no longer exists. It was left out of the export.', $result['errors'][0]);
        $this->assertSame('2 more issues were found and are not listed here.', $result['errors'][20]);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
    }

    public function test_twenty_errors_stay_listed_and_one_extra_is_named_in_the_singular()
    {
        $exact = $this->missing_translation_fixture(20)->export();
        $this->assertCount(20, $exact['errors']);
        $this->assertSame(
            'Post 1020 is part of translation group post:20, but that post no longer exists. It was left out of the export.',
            $exact['errors'][19]
        );

        $one_more = $this->missing_translation_fixture(21)->export();
        $this->assertCount(21, $one_more['errors']);
        $this->assertSame('1 more issue was found and is not listed here.', $one_more['errors'][20]);
        $this->assertSame('draft', $one_more['items'][20]['translations']['en']['status']);
    }

    public function test_swedish_letters_are_kept_and_empty_content_is_exported()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'sv'));
        $fake->languages = array(21 => 'sv', 22 => 'sv', 23 => 'en');
        $body = '<p>Här är äpplen, öl och fågel. Smörgås på Åland.</p>';
        $blank = $this->db_post(23, 'publish', 'Ä', 'a', 'post', '', '');
        $blank['post_content'] = null;
        $fake->posts_rows = array(
            21 => $this->db_post(21, 'draft', "Smörgås med äpplen på Å\0land", 'smorgas', 'post', 'Fågel', "\0" . $body),
            22 => $this->db_post(22, 'publish', 'Ö', 'o', 'page', '', ''),
            23 => $blank,
        );
        $exporter = new Polylang_Posts();
        $first = $exporter->export();
        $second = $exporter->export();

        $this->assertSame($first, $second);
        $this->assertSame(array('post:21', 'post:22', 'post:23'), $this->groups($second));
        $this->assertCount(3, $second['items']);

        $draft = $second['items'][0]['translations']['sv'];
        $this->assertSame('Smörgås med äpplen på Åland', $draft['title']);
        $this->assertSame('Fågel', $draft['excerpt']);
        $this->assertSame($body, $draft['content']);
        $this->assertSame('draft', $draft['status']);
        $this->assertStringContainsString('å', $draft['title']);
        $this->assertStringContainsString('ä', $draft['title']);
        $this->assertStringContainsString('ö', $draft['title']);
        $this->assertStringContainsString('å', $draft['content']);
        $this->assertStringContainsString('ä', $draft['content']);
        $this->assertStringContainsString('ö', $draft['content']);
        $this->assertStringContainsString('<p>', $draft['content']);
        $this->assertStringNotContainsString("\0", $draft['title']);
        $this->assertStringNotContainsString("\0", $draft['content']);

        $this->assertSame('Ö', $second['items'][1]['translations']['sv']['title']);
        $this->assertSame('', $second['items'][1]['translations']['sv']['content']);
        $this->assertSame('publish', $second['items'][1]['translations']['sv']['status']);
        $this->assertSame('page', $second['items'][1]['type']);

        $this->assertSame('Ä', $second['items'][2]['translations']['en']['title']);
        $this->assertSame('', $second['items'][2]['translations']['en']['content']);
        $this->assertSame('publish', $second['items'][2]['translations']['en']['status']);
    }

    public function test_empty_translation_stays_beside_swedish_content()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'sv'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 40,
                'description' => serialize(array('sv' => 41, 'en' => 42)),
            ),
        );
        $fake->languages = array(41 => 'sv', 42 => 'en');
        $fake->posts_rows = array(
            41 => $this->db_post(41, 'publish', 'Fågel', 'fagel', 'post', '', '<p>ä ö å</p>'),
            42 => $this->db_post(42, 'draft', 'Bird', 'bird', 'post', '', ''),
        );
        $result = (new Polylang_Posts())->export();
        $again = (new Polylang_Posts())->export();
        $this->assertSame($result, $again);
        $this->assertCount(1, $result['items']);
        $this->assertSame('post:41', $result['items'][0]['group']);
        $this->assertSame('<p>ä ö å</p>', $result['items'][0]['translations']['sv']['content']);
        $this->assertSame('Fågel', $result['items'][0]['translations']['sv']['title']);
        $this->assertSame('', $result['items'][0]['translations']['en']['content']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertArrayHasKey('en', $result['items'][0]['translations']);
    }

    public function test_blank_draft_zero_body_and_swedish_slug_are_exported()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'sv'));
        $fake->languages = array(31 => 'sv', 32 => 'sv', 33 => 'sv', 34 => 'sv');
        $only_nulls = $this->db_post(34, 'draft', 'Fågel', 'fagel', 'post', '', '');
        $only_nulls['post_content'] = "\0\0";
        $fake->posts_rows = array(
            31 => $this->db_post(31, 'draft', '', 'tom', 'post', '', ''),
            32 => $this->db_post(32, 'publish', 'Noll', 'noll', 'post', '', '0'),
            33 => $this->db_post(33, 'draft', 'Mellanrum', 'fågel-öl', 'post', '', " \n\t"),
            34 => $only_nulls,
        );
        $exporter = new Polylang_Posts();
        $first = $exporter->export();
        $second = $exporter->export();

        $this->assertSame($first, $second);
        $this->assertSame(array('post:31', 'post:32', 'post:33', 'post:34'), $this->groups($second));
        $this->assertSame('', $second['items'][0]['translations']['sv']['title']);
        $this->assertSame('', $second['items'][0]['translations']['sv']['content']);
        $this->assertSame('draft', $second['items'][0]['translations']['sv']['status']);
        $this->assertSame('0', $second['items'][1]['translations']['sv']['content']);
        $this->assertSame('fågel-öl', $second['items'][2]['translations']['sv']['slug']);
        $this->assertSame(" \n\t", $second['items'][2]['translations']['sv']['content']);
        $this->assertSame('Fågel', $second['items'][3]['translations']['sv']['title']);
        $this->assertSame('', $second['items'][3]['translations']['sv']['content']);
    }

    public function test_database_row_keeps_html_and_drops_null_bytes_on_a_second_export()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(7 => 'en');
        $html = '<div class="entry"><p>Keep <em>this</em></p></div>';
        $fake->posts_rows = array(
            7 => $this->db_post(7, 'draft', "Name\0", 'name', 'post', '', "Start\0" . $html . "\0end"),
        );
        $exporter = new Polylang_Posts();
        $first = $exporter->export();
        $second = $exporter->export();
        $this->assertSame($first, $second);
        $this->assertCount(1, $second['items']);
        $this->assertSame('post:7', $second['items'][0]['group']);
        $content = $second['items'][0]['translations']['en']['content'];
        $this->assertSame('Start' . $html . 'end', $content);
        $this->assertSame('Name', $second['items'][0]['translations']['en']['title']);
        $this->assertStringContainsString('<em>this</em>', $content);
        $this->assertStringNotContainsString("\0", $content);
        $this->assertStringNotContainsString("\0", $second['items'][0]['translations']['en']['title']);
    }

    public function test_title_of_only_null_bytes_is_empty_and_html_content_remains()
    {
        $exporter = new Polylang_Posts_Fixture();
        $html = '<figure><img src="photo.jpg" alt="Pier"></figure>';
        $exporter->maps = array(array('en' => 4));
        $exporter->posts = array(
            4 => $this->record(4, 'post', "\0\0", 'photo', 'draft', '', "\0" . $html),
        );
        $result = $exporter->export();
        $again = $exporter->export();
        $this->assertSame($result, $again);
        $this->assertCount(1, $again['items']);
        $this->assertSame('', $again['items'][0]['translations']['en']['title']);
        $this->assertSame($html, $again['items'][0]['translations']['en']['content']);
        $this->assertSame('draft', $again['items'][0]['translations']['en']['status']);
    }

    public function test_repeated_group_key_keeps_one_item_and_both_languages()
    {
        $html = '<p>Kept <strong>markup</strong></p>';
        $items = (new Polylang_Posts_Group_Probe())->dedupe(array(
            array(
                'group' => 'post:7',
                'type' => 'post',
                'translations' => array(
                    'en' => array(
                        'id' => 7,
                        'title' => 'Hello',
                        'slug' => 'hello',
                        'status' => 'draft',
                        'excerpt' => '',
                        'content' => $html,
                    ),
                ),
            ),
            array(
                'group' => 'post:7',
                'type' => 'post',
                'translations' => array(
                    'en' => array(
                        'id' => 7,
                        'title' => 'Hello again',
                        'slug' => 'hello',
                        'status' => 'draft',
                        'excerpt' => '',
                        'content' => '<p>duplicate</p>',
                    ),
                    'de' => array(
                        'id' => 8,
                        'title' => 'Hallo',
                        'slug' => 'hallo',
                        'status' => 'publish',
                        'excerpt' => '',
                        'content' => '<p>Hallo</p>',
                    ),
                ),
            ),
        ));
        $this->assertCount(1, $items);
        $this->assertSame('post:7', $items[0]['group']);
        $this->assertSame(array('en', 'de'), array_keys($items[0]['translations']));
        $this->assertSame($html, $items[0]['translations']['en']['content']);
        $this->assertSame('<p>Hallo</p>', $items[0]['translations']['de']['content']);
    }

    public function test_same_post_from_the_group_and_the_language_is_not_duplicated()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 30,
                'description' => serialize(array('en' => 12, 'de' => 13)),
            ),
        );
        $fake->languages = array(12 => 'en', 13 => 'de');
        $html = '<p>Published <a href="https://example.com">link</a></p>';
        $fake->posts_rows = array(
            12 => $this->db_post(12, 'publish', '<em>English</em>', 'english', 'post', '', $html),
            13 => $this->db_post(13, 'draft', "Entwurf\0", 'entwurf', 'post', '', '<p>Entwurf</p>'),
        );
        $exporter = new Polylang_Posts();
        $first = $exporter->export();
        $second = $exporter->export();
        $this->assertSame($first, $second);
        $this->assertCount(1, $second['items']);
        $this->assertSame('post:12', $second['items'][0]['group']);
        $this->assertSame(array('en', 'de'), array_keys($second['items'][0]['translations']));
        $this->assertSame($html, $second['items'][0]['translations']['en']['content']);
        $this->assertSame('<em>English</em>', $second['items'][0]['translations']['en']['title']);
        $this->assertSame('Entwurf', $second['items'][0]['translations']['de']['title']);
        $this->assertSame('draft', $second['items'][0]['translations']['de']['status']);
        $this->assertSame('<p>Entwurf</p>', $second['items'][0]['translations']['de']['content']);
    }

    public function test_instance_export_keeps_html_strips_null_bytes_and_does_not_duplicate_groups()
    {
        $exporter = new Polylang_Posts_Fixture();
        $this->assertTrue(is_callable(array($exporter, 'export')));
        $html = '<p>Hello <strong>world</strong></p><!--more-->';
        $exporter->maps = array(
            array('en' => 7),
            array('sv' => 8),
        );
        $exporter->posts = array(
            7 => $this->record(
                7,
                'post',
                "Draft\0 title",
                'draft-title',
                'draft',
                "Lead\0 in",
                "Before\0" . $html . "\0after"
            ),
            8 => $this->record(8, 'post', 'Andra', 'andra', 'publish', '', '<p>Oförändrad</p>'),
        );

        $first = $exporter->export();
        $second = $exporter->export();

        $this->assertCount(2, $first['items']);
        $this->assertCount(2, $second['items']);
        $this->assertSame($first, $second);
        $this->assertSame(array('post:7', 'post:8'), $this->groups($first));
        $this->assertSame(array('post:7', 'post:8'), $this->groups($second));
        $translation = $second['items'][0]['translations']['en'];
        $this->assertSame('draft', $translation['status']);
        $this->assertSame('Draft title', $translation['title']);
        $this->assertSame('Lead in', $translation['excerpt']);
        $this->assertSame('Before' . $html . 'after', $translation['content']);
        $this->assertStringContainsString('<p>Hello <strong>world</strong></p>', $translation['content']);
        $this->assertStringContainsString('<!--more-->', $translation['content']);
        $this->assertStringNotContainsString("\0", $translation['title']);
        $this->assertStringNotContainsString("\0", $translation['excerpt']);
        $this->assertStringNotContainsString("\0", $translation['content']);
        $this->assertSame('<p>Oförändrad</p>', $second['items'][1]['translations']['sv']['content']);
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

    public function test_incomplete_api_map_still_keeps_the_stored_draft()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows = array(
            array(
                'term_taxonomy_id' => 1,
                'description' => array('en' => 1, 'de' => 2),
            ),
        );
        $exporter->api_maps = array(
            1 => array('en' => 1),
        );
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'Hello', 'hello', 'publish'),
            2 => $this->record(2, 'post', 'Hallo', 'hallo', 'draft', '', 'Entwurf'),
        );

        $result = $exporter->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:1', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('Entwurf', $result['items'][0]['translations']['de']['content']);
    }

    public function test_empty_api_id_does_not_erase_a_stored_draft()
    {
        $exporter = new Polylang_Posts_Api_Fixture();
        $exporter->rows = array(
            array(
                'term_taxonomy_id' => 1,
                'description' => array('en' => 1, 'de' => 2),
            ),
        );
        $exporter->api_maps = array(
            1 => array('en' => 1, 'de' => 0),
        );
        $exporter->posts = array(
            1 => $this->record(1, 'post', 'Hello', 'hello', 'publish'),
            2 => $this->record(2, 'post', 'Hallo', '', 'draft'),
        );

        $result = $exporter->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(2, $result['items'][0]['translations']['de']['id']);
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('', $result['items'][0]['translations']['de']['slug']);
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

    public function test_linked_draft_missing_from_the_description_joins_the_group()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 8,
                'description' => serialize(array('en' => 4)),
            ),
        );
        $fake->relationships = array(8 => array(4, 6));
        $fake->languages = array(4 => 'en', 6 => 'de');
        $fake->posts_rows = array(
            4 => $this->db_post(4, 'publish', 'Hello', 'hello'),
            6 => $this->db_post(6, 'draft', 'Hallo', 'hallo', 'post', '', 'Entwurf'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertCount(1, $result['items']);
        $this->assertSame('post:4', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('Entwurf', $result['items'][0]['translations']['de']['content']);
        $this->assertSame(
            array('Post 6 is linked to a translation group but was missing from its stored description. It was added as de.'),
            $result['errors']
        );
    }

    public function test_untranslated_draft_stays_when_menu_items_have_languages()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(9 => 'sv', 50 => 'en');
        $fake->posts_rows = array(
            9 => $this->db_post(9, 'draft', 'Förslag', 'forslag', 'post', '', 'Ett utkast'),
            50 => $this->db_post(50, 'publish', 'Home', 'home', 'nav_menu_item'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array('post:9'), $this->groups($result));
        $this->assertSame('Förslag', $result['items'][0]['translations']['sv']['title']);
        $this->assertSame('forslag', $result['items'][0]['translations']['sv']['slug']);
        $this->assertSame('draft', $result['items'][0]['translations']['sv']['status']);
        $this->assertSame('Ett utkast', $result['items'][0]['translations']['sv']['content']);
        $this->assertSame(array(), $result['errors']);
        $filtered = false;
        foreach ($fake->queries as $sql) {
            if (strpos($sql, 'AS slug') !== false && strpos($sql, 'nav_menu_item') !== false && strpos($sql, 'p.ID IS NULL') !== false) {
                $filtered = true;
            }
        }
        $this->assertTrue($filtered);
    }

    public function test_attachment_and_whitespace_draft_are_exported_as_stored()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 9,
                'description' => serialize(array('en' => 15, 'de' => 17)),
            ),
        );
        $fake->languages = array(15 => 'en', 16 => 'de', 17 => 'de', 99 => 'en');
        $fake->posts_rows = array(
            15 => $this->db_post(15, 'inherit', 'Photo', 'photo', 'attachment', 'Pier', ''),
            16 => $this->db_post(16, 'draft', '  Untitled  ', '', 'post', 'Only the excerpt', ''),
            17 => $this->db_post(17, 'inherit', 'Foto', 'foto', 'attachment', 'Steg', ''),
            99 => $this->db_post(99, 'inherit', 'Alone', 'alone', 'attachment'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('post:15', 'post:16'), $this->groups($result));
        $this->assertSame('attachment', $result['items'][0]['type']);
        $this->assertSame('inherit', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Photo', $result['items'][0]['translations']['en']['title']);
        $this->assertSame('Pier', $result['items'][0]['translations']['en']['excerpt']);
        $this->assertSame('Foto', $result['items'][0]['translations']['de']['title']);
        $this->assertSame('  Untitled  ', $result['items'][1]['translations']['de']['title']);
        $this->assertSame('', $result['items'][1]['translations']['de']['slug']);
        $this->assertSame('draft', $result['items'][1]['translations']['de']['status']);
        $this->assertSame('Only the excerpt', $result['items'][1]['translations']['de']['excerpt']);
        $this->assertSame('', $result['items'][1]['translations']['de']['content']);
        foreach ($fake->queries as $sql) {
            if (strpos($sql, 'post_content') !== false) {
                $this->assertStringNotContainsString('99', $sql);
            }
        }
    }

    public function test_default_language_locale_still_names_the_draft_group()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en_US'));
        $fake->language_terms = array(
            array(
                'slug' => 'en',
                'term_description' => serialize(array('locale' => 'en_US')),
            ),
        );
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 13,
                'description' => serialize(array('de' => 2, 'en' => 9)),
            ),
        );
        $fake->languages = array(2 => 'de', 9 => 'en');
        $fake->posts_rows = array(
            2 => $this->db_post(2, 'publish', 'Hallo', 'hallo'),
            9 => $this->db_post(9, 'draft', 'Hello', 'hello'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:9', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
    }

    public function test_two_posts_with_the_same_locale_keep_the_draft_and_explain()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->language_terms = array(
            array(
                'slug' => 'en',
                'term_description' => serialize(array('locale' => 'en_US')),
            ),
        );
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 14,
                'description' => serialize(array('en' => 5, 'en_US' => 6)),
            ),
        );
        $fake->languages = array(5 => 'en', 6 => 'en');
        $fake->posts_rows = array(
            5 => $this->db_post(5, 'draft', 'Hello', 'hello', 'post', '', 'First'),
            6 => $this->db_post(6, 'publish', 'Hello again', 'hello-again'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame('post:5', $result['items'][0]['group']);
        $this->assertSame(array('en'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('First', $result['items'][0]['translations']['en']['content']);
        $this->assertSame(
            array('Posts 5 and 6 were both stored as en. Post 5 was kept.'),
            $result['errors']
        );
    }

    public function test_hyphenated_locale_is_rewritten_for_a_draft()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->language_terms = array(
            array(
                'slug' => 'en',
                'term_description' => serialize(array('locale' => 'en_US')),
            ),
        );
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 15,
                'description' => serialize(array('en-US' => 5)),
            ),
        );
        $fake->languages = array(5 => 'en');
        $fake->posts_rows = array(
            5 => $this->db_post(5, 'draft', 'Hello', '', 'page', '', 'Page draft'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:5', $result['items'][0]['group']);
        $this->assertSame('page', $result['items'][0]['type']);
        $this->assertSame(array('en'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Page draft', $result['items'][0]['translations']['en']['content']);
    }

    public function test_locale_keys_are_rewritten_to_language_slugs_for_a_draft()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->language_terms = array(
            array(
                'slug' => 'en',
                'term_description' => serialize(array('locale' => 'en_US', 'flag_code' => 'us')),
            ),
            array(
                'slug' => 'de',
                'term_description' => serialize(array('locale' => 'de_DE', 'flag_code' => 'de')),
            ),
        );
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 12,
                'description' => serialize(array('en_US' => 5, 'de_DE' => 6)),
            ),
        );
        $fake->languages = array(5 => 'en', 6 => 'de');
        $fake->posts_rows = array(
            5 => $this->db_post(5, 'draft', 'Hello', 'hello'),
            6 => $this->db_post(6, 'publish', 'Hallo', 'hallo'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:5', $result['items'][0]['group']);
        $this->assertSame(array('en', 'de'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
    }

    public function test_draft_product_is_exported()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(21 => 'en', 22 => 'de');
        $fake->posts_rows = array(
            21 => $this->db_post(21, 'draft', 'Sko', 'sko', 'product', '', 'Blå sko'),
            22 => $this->db_post(22, 'draft', 'Schuh', 'schuh', 'product', '', 'Blauer Schuh'),
        );
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 11,
                'description' => serialize(array('en' => 21, 'de' => 22)),
            ),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:21', $result['items'][0]['group']);
        $this->assertSame('product', $result['items'][0]['type']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('draft', $result['items'][0]['translations']['de']['status']);
        $this->assertSame('Blå sko', $result['items'][0]['translations']['en']['content']);
        $this->assertSame('Schuh', $result['items'][0]['translations']['de']['title']);
    }

    public function test_double_serialized_description_still_exports_the_draft()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 4,
                'description' => serialize(serialize(array('en' => 3, 'de' => 4))),
            ),
        );
        $fake->languages = array(3 => 'en', 4 => 'de');
        $fake->posts_rows = array(
            3 => $this->db_post(3, 'draft', 'Hello', 'hello'),
            4 => $this->db_post(4, 'publish', 'Hallo', 'hallo'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:3', $result['items'][0]['group']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Hallo', $result['items'][0]['translations']['de']['title']);
    }

    public function test_deleted_untranslated_post_is_reported_and_the_draft_remains()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'en'));
        $fake->languages = array(9 => 'sv', 44 => 'en');
        $fake->posts_rows = array(
            9 => $this->db_post(9, 'draft', '', '', 'post', '', 'Utkast'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame(array('post:9'), $this->groups($result));
        $this->assertSame('draft', $result['items'][0]['translations']['sv']['status']);
        $this->assertSame(
            array('Post 44 has a language but no longer exists, so it was left out of the export.'),
            $result['errors']
        );
    }

    public function test_database_language_overrides_a_stale_description()
    {
        $this->skip_when_wordpress_or_polylang_is_loaded();
        $fake = $this->use_fake_db();
        $fake->option_value = serialize(array('default_lang' => 'sv'));
        $fake->translation_terms = array(
            array(
                'term_taxonomy_id' => 3,
                'description' => serialize(array('en' => 5, 'de' => 6)),
            ),
        );
        $fake->languages = array(5 => 'sv', 6 => 'de');
        $fake->posts_rows = array(
            5 => $this->db_post(5, 'draft', 'Utkast', 'utkast'),
            6 => $this->db_post(6, 'publish', 'Hallo', 'hallo'),
        );

        $result = (new Polylang_Posts())->export();
        $this->assertSame('post:5', $result['items'][0]['group']);
        $this->assertSame(array('sv', 'de'), array_keys($result['items'][0]['translations']));
        $this->assertSame('draft', $result['items'][0]['translations']['sv']['status']);
        $this->assertSame(
            array('Post 5 is stored as en in its translation group, but its language is sv. It was exported as sv.'),
            $result['errors']
        );
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
            'strip_tags(',
            'utf8_decode(',
            'iconv(',
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

    public function test_post_objects_from_get_posts_still_export_a_draft()
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is not available.');
        }
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-posts.php';
        $script = tempnam(sys_get_temp_dir(), 'tolka-objects');
        $class_export = var_export($class, true);
        file_put_contents($script, <<<PHP
<?php
define('ABSPATH', '/tmp/tolka-posts/');
function pll_get_post_translations(\$id) {
    if ((int) \$id === 8) {
        return array('en' => 8);
    }
    return false;
}
function pll_get_post_language(\$id, \$field = 'slug') {
    return false;
}
function pll_default_language() {
    return 'en';
}
function get_posts(\$args) {
    static \$calls = 0;
    \$calls++;
    if (\$calls === 1) {
        return array((object) array('ID' => 8));
    }
    return array();
}
function get_post(\$id) {
    if ((int) \$id !== 8) {
        return null;
    }
    return (object) array(
        'ID' => 8,
        'post_type' => 'page',
        'post_title' => 'Draft page',
        'post_name' => '',
        'post_status' => 'draft',
        'post_excerpt' => '',
        'post_content' => 'Not published',
    );
}
require {$class_export};
echo '__JSON__' . json_encode((new WMM\\Tolka\\Polylang_Posts())->export());
PHP
        );
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        @unlink($script);
        $json = '';
        if (is_string($output) && strpos($output, '__JSON__') !== false) {
            $json = substr($output, strpos($output, '__JSON__') + 8);
        }
        $result = json_decode($json, true);
        $this->assertIsArray($result);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('post:8', $result['items'][0]['group']);
        $this->assertSame('page', $result['items'][0]['type']);
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('', $result['items'][0]['translations']['en']['slug']);
        $this->assertSame('Not published', $result['items'][0]['translations']['en']['content']);
    }

    public function test_media_type_lookup_does_not_load_attachment_content()
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is not available.');
        }
        $calls = tempnam(sys_get_temp_dir(), 'tolka-calls');
        file_put_contents($calls, '');
        $script = tempnam(sys_get_temp_dir(), 'tolka-types');
        $test_file = var_export(__FILE__, true);
        $calls_export = var_export($calls, true);
        file_put_contents($script, <<<PHP
<?php
define('ABSPATH', '/tmp/tolka-posts/');
function get_post(\$id) {
    file_put_contents({$calls_export}, (int) \$id . "\\n", FILE_APPEND);
    if ((int) \$id !== 16) {
        return null;
    }
    return (object) array(
        'ID' => 16,
        'post_type' => 'post',
        'post_title' => 'Draft',
        'post_name' => 'draft',
        'post_status' => 'draft',
        'post_excerpt' => '',
        'post_content' => 'Body',
    );
}
require {$test_file};
\$fake = new WMM\\Tolka\\Tolka_Posts_Fake_Db();
\$fake->option_value = serialize(array('default_lang' => 'en'));
\$fake->languages = array(16 => 'en', 99 => 'en');
\$fake->posts_rows = array(
    16 => array('ID' => 16, 'post_type' => 'post', 'post_title' => 'Draft', 'post_name' => 'draft', 'post_status' => 'draft', 'post_excerpt' => '', 'post_content' => 'Body'),
    99 => array('ID' => 99, 'post_type' => 'attachment', 'post_title' => 'Big', 'post_name' => 'big', 'post_status' => 'inherit', 'post_excerpt' => '', 'post_content' => 'HUGE'),
);
\$GLOBALS['wpdb'] = \$fake;
\$result = (new WMM\\Tolka\\Polylang_Posts())->export();
echo '__JSON__' . json_encode(\$result);
PHP
        );
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        $called = file_get_contents($calls);
        @unlink($script);
        @unlink($calls);
        $json = '';
        if (is_string($output) && strpos($output, '__JSON__') !== false) {
            $json = substr($output, strpos($output, '__JSON__') + 8);
        }
        $result = json_decode($json, true);
        $this->assertIsArray($result, is_string($output) ? $output : 'no output');
        $this->assertSame(array('post:16'), $this->groups($result));
        $this->assertSame('draft', $result['items'][0]['translations']['en']['status']);
        $this->assertSame('Body', $result['items'][0]['translations']['en']['content']);
        $this->assertStringNotContainsString('99', (string) $called);
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

    private function missing_translation_fixture($count)
    {
        $exporter = new Polylang_Posts_Fixture();
        $maps = array();
        $posts = array();
        for ($i = 1; $i <= $count; $i++) {
            $maps[] = array('en' => $i, 'de' => 1000 + $i);
            $posts[$i] = $this->record($i, 'post', 'Keep ' . $i, 'keep-' . $i, 'draft');
        }
        $exporter->maps = $maps;
        $exporter->posts = $posts;
        return $exporter;
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
