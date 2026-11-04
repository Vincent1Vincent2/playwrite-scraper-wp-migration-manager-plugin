<?php

/**
 * Languages exported from Polylang for the Tolka migration.
 *
 * @package MigrationManager
 */

use WMM\Tolka\Polylang_Languages;

$tolka_languages_file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-languages.php';
if (!class_exists(Polylang_Languages::class, false) && is_readable($tolka_languages_file)) {
    require_once $tolka_languages_file;
}

/**
 * @group tolka
 * @group tolka-languages
 */
class Test_Polylang_Languages extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Polylang_Languages::class, false)) {
            $this->markTestSkipped('Polylang_Languages is not loaded.');
        }

        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function test_inactive_site_returns_no_languages_and_an_explanation()
    {
        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['items']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(
            'Polylang is not active on this site, so there are no languages to migrate. Activate Polylang if the languages are still installed, then preview again.',
            $result['errors'][0]
        );
    }

    public function test_exports_stored_languages_default_and_translation_slugs()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->option_value = serialize(array(
            'default_lang' => 'fr',
            'version' => '3.6.4',
        ));
        $db->language_rows = array(
            $this->language_term(8, 'Svenska', 'sv', 'sv_SE', 'se', 5, 0),
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 3),
            $this->language_term(6, 'Français', 'fr', 'fr_FR', 'fr', 1, 2),
            $this->language_term(10, '', 'de', '', '', 2, 0),
        );
        $db->post_translation_rows = array(
            (object) array(
                'description' => serialize(array(
                    'en' => 10,
                    'fr' => 11,
                    'pt' => 12,
                )),
            ),
            (object) array(
                'description' => 'not-a-language-map',
            ),
        );
        $db->term_translation_rows = array(
            (object) array(
                'description' => serialize(array(
                    'fr' => 20,
                    0 => 'ignored',
                    '123abc' => 21,
                )),
            ),
        );
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('en', 'fr', 'de', 'sv', 'pt'), array_keys($by_slug));
        $this->assertSame('English', $by_slug['en']['name']);
        $this->assertSame('en_US', $by_slug['en']['locale']);
        $this->assertSame(4, $by_slug['en']['term_id']);
        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['fr']['is_default']);
        $this->assertSame('Français', $by_slug['fr']['name']);
        $this->assertSame('de', $by_slug['de']['name']);
        $this->assertSame('', $by_slug['de']['locale']);
        $this->assertSame('', $by_slug['de']['flag']);
        $this->assertSame(10, $by_slug['de']['term_id']);
        $this->assertFalse($by_slug['de']['is_default']);
        $this->assertSame('pt', $by_slug['pt']['name']);
        $this->assertSame(0, $by_slug['pt']['term_id']);
        $this->assertFalse($by_slug['pt']['is_default']);
        $this->assertTrue($by_slug['sv']['is_default'] === false);
        $this->assertSame(array('slug', 'name', 'locale', 'is_default', 'term_id', 'flag'), array_keys($by_slug['en']));
    }

    public function test_includes_languages_that_have_no_posts()
    {
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'US', 0, 4),
            $this->language_term(9, 'Norsk', 'no', 'nb_NO', 'no', 3, 0),
        ), '');
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertArrayHasKey('no', $by_slug);
        $this->assertSame('Norsk', $by_slug['no']['name']);
        $this->assertSame('nb_NO', $by_slug['no']['locale']);
        $this->assertSame(9, $by_slug['no']['term_id']);
        $this->assertSame('no', $by_slug['no']['flag']);
    }

    public function test_single_language_is_the_default_when_none_was_configured()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 0),
        ), '');

        $result = Polylang_Languages::export();

        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertSame('en', $result['items'][0]['slug']);
    }

    public function test_several_languages_stay_without_a_default_when_none_is_configured()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
            $this->language_term(6, 'Français', 'fr', 'fr_FR', 'fr', 1, 1),
        ), '');

        $result = Polylang_Languages::export();

        $this->assertFalse($result['items'][0]['is_default']);
        $this->assertFalse($result['items'][1]['is_default']);
    }

    public function test_configured_default_wins_and_a_missing_default_is_not_invented()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
            $this->language_term(6, 'Français', 'fr', 'fr_FR', 'fr', 1, 1),
        ), 'de');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array('en', 'fr'), array_keys($by_slug));
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertFalse($by_slug['fr']['is_default']);
        $this->assertArrayNotHasKey('de', $by_slug);
    }

    public function test_broken_rows_do_not_throw()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->option_value = 'a:1:{s:12:"default_lang";O:8:"stdClass":0:{}}';
        $db->language_rows = array(
            null,
            'not-a-term',
            (object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:1:{s:6:"locale";O:8:"stdClass":0:{}}',
                'count' => 1,
            ),
            (object) array(
                'term_id' => 5,
                'name' => 'Broken',
                'slug' => '',
                'term_group' => 1,
                'description' => '{',
                'count' => 0,
            ),
        );
        $db->post_translation_rows = array(
            (object) array(
                'description' => 'a:1:{s:6:"locale";O:8:"stdClass":0:{}}',
            ),
        );
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('', $result['items'][0]['locale']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_database_failure_explains_the_empty_list_and_changes_nothing()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->language_fails = true;
        $db->option_value = serialize(array('default_lang' => 'en'));
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            'Polylang languages could not be read. Nothing was changed. Preview the migration again once Polylang has finished loading.',
            $result['errors'][0]
        );
        $this->assertQueriesAreReadOnly($db->queries);
    }

    public function test_export_does_not_write_polylang_data()
    {
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
        ), 'en');
        $GLOBALS['wpdb'] = $db;

        Polylang_Languages::export();

        $this->assertQueriesAreReadOnly($db->queries);
        $joined = implode("\n", $db->queries);
        $this->assertStringContainsString('language', $joined);
        $this->assertStringContainsString('post_translations', $joined);
        $this->assertStringContainsString('term_translations', $joined);
        $this->assertStringContainsString('polylang', $joined);

        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/class-polylang-languages.php');
        $this->assertDoesNotMatchRegularExpression('/\b(DELETE|UPDATE|INSERT|TRUNCATE|DROP)\b/', $source);
        $this->assertStringNotContainsString('delete_option', $source);
        $this->assertStringNotContainsString('wp_delete_', $source);
    }

    public function test_export_can_be_called_twice_and_the_result_is_independent()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
            $this->language_term(6, 'Français', 'fr', 'fr_FR', 'fr', 1, 1),
        ), 'fr');

        $first = Polylang_Languages::export();
        $first['items'][0]['name'] = 'Changed';
        $first['errors'][] = 'Changed';
        $second = Polylang_Languages::export();

        $this->assertSame('English', $second['items'][0]['name']);
        $this->assertSame(array(), $second['errors']);
        $this->assertTrue($second['items'][1]['is_default']);
    }

    public function test_stored_flag_code_replaces_flag_markup()
    {
        $preferred = $this->invoke('make_item', array('en', 'en', 'en_US', true, 0, '<img alt="" />'));
        $fallback = $this->invoke('make_item', array('en', 'English', 'en_US', true, 7, 'us'));
        $extra = $this->invoke('make_item', array('sv', 'Svenska', 'sv_SE', false, 8, 'se'));

        $merged = $this->invoke('merge_items', array(array($preferred), array($fallback, $extra)));

        $this->assertSame('English', $merged[0]['name']);
        $this->assertSame('us', $merged[0]['flag']);
        $this->assertSame(7, $merged[0]['term_id']);
        $this->assertSame('sv', $merged[1]['slug']);
        $this->assertSame('Svenska', $merged[1]['name']);
    }

    public function test_api_record_uses_flag_code_term_props_and_default()
    {
        $language = (object) array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'flag_code' => 'us',
            'flag' => '<img alt="" />',
            'is_default' => true,
            'term_props' => array(
                'language' => array('term_id' => 15),
            ),
        );

        $item = $this->invoke('item_from_record', array($language, 'en'));

        $this->assertSame('en', $item['slug']);
        $this->assertSame('English', $item['name']);
        $this->assertSame('en_US', $item['locale']);
        $this->assertTrue($item['is_default']);
        $this->assertSame(15, $item['term_id']);
        $this->assertSame('us', $item['flag']);
    }

    /**
     * @param mixed $method Private method name.
     * @param array $args Arguments.
     * @return mixed
     */
    private function invoke($method, $args)
    {
        $exporter = new Polylang_Languages();
        $reflection = new ReflectionMethod($exporter, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($exporter, $args);
    }

    /**
     * @param array $items Language items.
     * @return array
     */
    private function index_by_slug($items)
    {
        $indexed = array();
        foreach ($items as $item) {
            $indexed[$item['slug']] = $item;
        }
        return $indexed;
    }

    /**
     * @param int $term_id Term id.
     * @param string $name Language name.
     * @param string $slug Language slug.
     * @param string $locale Locale.
     * @param string $flag Flag code.
     * @param int $term_group Polylang order.
     * @param int $count Assigned posts. Zero means the language has no posts.
     * @return object
     */
    private function language_term($term_id, $name, $slug, $locale, $flag, $term_group, $count)
    {
        $description = array();
        if ($locale !== '') {
            $description['locale'] = $locale;
        }
        if ($flag !== '') {
            $description['flag_code'] = $flag;
        }

        return (object) array(
            'term_id' => $term_id,
            'name' => $name,
            'slug' => $slug,
            'term_group' => $term_group,
            'description' => serialize($description),
            'count' => $count,
        );
    }

    /**
     * @param array $languages Language term rows.
     * @param string $default Default slug stored in the polylang option.
     * @return Polylang_Languages_Test_Db
     */
    private function database_with_languages($languages, $default)
    {
        $db = new Polylang_Languages_Test_Db();
        $db->language_rows = $languages;
        if ($default !== '') {
            $db->option_value = serialize(array('default_lang' => $default));
        }
        return $db;
    }

    /**
     * @param string[] $queries SQL sent by the exporter.
     */
    private function assertQueriesAreReadOnly($queries)
    {
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(DELETE|UPDATE|INSERT|TRUNCATE|DROP|REPLACE)\b/i', $query);
        }
    }
}

/**
 * Records read queries and returns fixture rows for language tests.
 */
class Polylang_Languages_Test_Db
{
    public $terms = 'wp_terms';

    public $term_taxonomy = 'wp_term_taxonomy';

    public $options = 'wp_options';

    public $queries = array();

    public $language_rows = array();

    public $post_translation_rows = array();

    public $term_translation_rows = array();

    public $option_value = '';

    public $language_fails = false;

    public function prepare($query, $value)
    {
        $this->queries[] = $query;
        $quoted = "'" . str_replace("'", "''", (string) $value) . "'";
        return str_replace('%s', $quoted, $query);
    }

    public function get_results($query)
    {
        $this->queries[] = $query;

        if (strpos($query, "'language'") !== false) {
            if ($this->language_fails) {
                return null;
            }
            return $this->language_rows;
        }
        if (strpos($query, "'post_translations'") !== false) {
            return $this->post_translation_rows;
        }
        if (strpos($query, "'term_translations'") !== false) {
            return $this->term_translation_rows;
        }

        return array();
    }

    public function get_var($query)
    {
        $this->queries[] = $query;
        return $this->option_value;
    }
}
