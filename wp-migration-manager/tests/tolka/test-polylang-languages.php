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

    public function test_empty_states_can_be_translated()
    {
        $inactive = $this->export_isolated(<<<'PHP'
function __($text, $domain = 'default')
{
    if ($domain !== 'migration-manager') {
        throw new RuntimeException('unexpected text domain');
    }
    return 'Translated: ' . $text;
}
PHP
        );
        $this->assertSame(
            'Translated: Polylang is not active on this site, so there are no languages to migrate. Activate Polylang if the languages are still installed, then preview again.',
            $inactive['errors'][0]
        );

        $active = $this->export_isolated(<<<'PHP'
function __($text, $domain = 'default')
{
    return 'Translated: ' . $text;
}
function pll_languages_list($args = array())
{
    return array();
}
PHP
        );
        $this->assertSame(
            'Translated: Polylang is active, but no languages were found. Add a language under Languages in Polylang, then preview the migration again.',
            $active['errors'][0]
        );

        $failed = $this->export_isolated(<<<'PHP'
function __($text, $domain = 'default')
{
    return 'Translated: ' . $text;
}
$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';
    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }
    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return null;
        }
        return array();
    }
    public function get_var($query)
    {
        return '';
    }
};
PHP
        );
        $this->assertSame(
            'Translated: Polylang languages could not be read. Nothing was changed. Preview the migration again.',
            $failed['errors'][0]
        );
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
        $this->assertSame(array('fr', 'en', 'de', 'sv', 'pt'), array_keys($by_slug));
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

    public function test_mysql_string_columns_stay_typed_and_keep_empty_languages()
    {
        $empty = $this->language_term(9, 'Norsk', 'no', 'nb_NO', 'no', 2, 0);
        $empty->term_id = '9';
        $empty->term_group = '2';
        $empty->count = '0';
        $english = $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 3);
        $english->term_id = '4';
        $english->term_group = '0';
        $english->count = '3';
        $later = $this->language_term(11, 'Deutsch', 'de', 'de_DE', 'de', 10, 0);
        $later->term_id = '11';
        $later->term_group = '10';
        $later->count = '0';
        $GLOBALS['wpdb'] = $this->database_with_languages(array($later, $empty, $english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertIsBool($result['items'][0]['is_default']);
        $this->assertSame(4, $result['items'][0]['term_id']);
        $this->assertIsInt($result['items'][0]['term_id']);
        $this->assertSame('no', $result['items'][1]['slug']);
        $this->assertSame(9, $result['items'][1]['term_id']);
        $this->assertIsInt($result['items'][1]['term_id']);
        $this->assertFalse($result['items'][1]['is_default']);
        $this->assertSame('de', $result['items'][2]['slug']);
        $this->assertSame(11, $result['items'][2]['term_id']);
    }

    public function test_names_are_sorted_after_html_entities_are_decoded()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(2, '&Eacute;cole', 'eco', 'fr_FR', 'fr', 0, 0),
            $this->language_term(3, 'Zoo', 'zoo', 'en_US', 'us', 0, 1),
        ), '');

        $result = Polylang_Languages::export();

        $this->assertSame('Zoo', $result['items'][0]['name']);
        $this->assertSame('École', $result['items'][1]['name']);
        $this->assertSame('zoo', $result['items'][0]['slug']);
        $this->assertSame('eco', $result['items'][1]['slug']);
    }

    public function test_locales_use_underscores_and_garbage_locales_are_dropped()
    {
        $hyphen = $this->language_term(4, 'English', 'en', 'en-US', 'us', 0, 1);
        $garbage = $this->language_term(8, 'Svenska', 'sv', '<script>', 'se', 1, 0);
        $GLOBALS['wpdb'] = $this->database_with_languages(array($hyphen, $garbage), 'en');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame('en_US', $by_slug['en']['locale']);
        $this->assertSame('', $by_slug['sv']['locale']);
        $this->assertSame('Svenska', $by_slug['sv']['name']);
        $this->assertSame('se', $by_slug['sv']['flag']);
        $this->assertTrue($by_slug['en']['is_default']);
    }

    public function test_a_blank_entity_name_uses_the_language_slug()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(6, '&nbsp;', 'fr', 'fr_FR', 'fr', 0, 0),
            $this->language_term(4, '&nbsp;English&nbsp;', 'en', 'en_US', 'us', 1, 1),
        ), 'fr');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertSame('fr', $by_slug['fr']['name']);
        $this->assertTrue($by_slug['fr']['is_default']);
        $this->assertSame('English', $by_slug['en']['name']);
    }

    public function test_html_entities_in_a_language_name_are_decoded()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(6, 'Fran&ccedil;ais', 'fr', 'fr_FR', 'fr', 0, 0),
        ), 'fr');

        $result = Polylang_Languages::export();

        $this->assertSame('Français', $result['items'][0]['name']);
        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_a_right_to_left_language_is_exported()
    {
        $arabic = $this->language_term(12, 'العربية', 'ar', '', '', 1, 0);
        $arabic->description = serialize(array(
            'fallbacks' => array('ar', 'en_US'),
            'locale' => 'ar',
            'rtl' => 1,
            'flag_code' => 'sa',
            'active' => true,
        ));
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 2),
            $arabic,
        ), 'ar');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('ar', $result['items'][0]['slug']);
        $this->assertTrue($by_slug['ar']['is_default']);
        $this->assertSame('العربية', $by_slug['ar']['name']);
        $this->assertSame('ar', $by_slug['ar']['locale']);
        $this->assertSame('sa', $by_slug['ar']['flag']);
        $this->assertSame(12, $by_slug['ar']['term_id']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertArrayNotHasKey('rtl', $by_slug['ar']);
    }

    public function test_a_real_polylang_description_keeps_locale_and_flag()
    {
        $english = $this->language_term(7, 'English', 'en', '', '', 0, 0);
        $english->description = 'a:4:{s:9:"fallbacks";a:0:{}s:6:"locale";s:5:"en_US";s:3:"rtl";i:0;s:9:"flag_code";s:2:"us";}';
        $GLOBALS['wpdb'] = $this->database_with_languages(array($english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertSame(7, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertArrayNotHasKey('fallbacks', $result['items'][0]);
        $this->assertArrayNotHasKey('rtl', $result['items'][0]);
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

        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('fr', $result['items'][1]['slug']);
        $this->assertFalse($result['items'][0]['is_default']);
        $this->assertFalse($result['items'][1]['is_default']);
    }

    public function test_upper_and_lower_case_slugs_are_one_language()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'EN', 'en_US', 'us', 0, 1),
            $this->language_term(9, 'English', 'en', 'en_GB', 'gb', 1, 0),
        ), 'EN');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
    }

    public function test_the_only_language_is_default_when_the_saved_choice_is_missing()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 0),
        ), 'de');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertArrayNotHasKey('de', $this->index_by_slug($result['items']));
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

    public function test_a_huge_description_is_ignored()
    {
        $english = $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1);
        $english->description = 'a:' . str_repeat('x', 100001);
        $GLOBALS['wpdb'] = $this->database_with_languages(array($english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertSame('', $result['items'][0]['locale']);
        $this->assertSame('', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
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

    public function test_double_encoded_description_is_read_without_creating_objects()
    {
        $description = serialize(serialize(array(
            'locale' => 'en_US',
            'flag_code' => 'us',
        )));
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 0),
        ), 'en');
        $db->language_rows[0]->description = $description;
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_wordpress_unserialize_helper_is_not_used()
    {
        $result = $this->export_isolated(<<<'PHP'
function maybe_unserialize($value)
{
    throw new RuntimeException('unsafe unserialize');
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"en";}';
    }
};
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
    }

    public function test_a_bad_description_does_not_leave_the_error_handler_in_place()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->language_rows = array(
            (object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:1:{s:6:"locale";O:8:"stdClass":0:{}}',
                'count' => 0,
            ),
        );
        $GLOBALS['wpdb'] = $db;

        $seen = 0;
        set_error_handler(function () use (&$seen) {
            $seen++;
            return true;
        });

        try {
            $result = Polylang_Languages::export();
            trigger_error('probe', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $seen);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('', $result['items'][0]['locale']);
    }

    public function test_a_database_read_does_not_print_sql_errors()
    {
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 0),
        ), 'en');
        $db->fail_if_errors_visible = true;
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertTrue($db->show_errors);
        $this->assertFalse($db->suppress_errors);
        $this->assertGreaterThan(0, $db->hidden_errors);
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
            'Polylang languages could not be read. Nothing was changed. Preview the migration again.',
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

        $this->assertSame('Français', $second['items'][0]['name']);
        $this->assertTrue($second['items'][0]['is_default']);
        $this->assertSame('English', $second['items'][1]['name']);
        $this->assertSame(array(), $second['errors']);
    }

    public function test_menu_settings_do_not_invent_languages()
    {
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 2),
        ), '');
        $db->option_value = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'primary' => array(
                    'de' => 3,
                    'fr' => 4,
                ),
            ),
            'post_types' => array('post', 'page'),
            'version' => '3.6.4',
        ));
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array('en'), array_keys($by_slug));
        $this->assertTrue($by_slug['en']['is_default']);
        $this->assertArrayNotHasKey('de', $by_slug);
        $this->assertArrayNotHasKey('fr', $by_slug);
        $this->assertArrayNotHasKey('post', $by_slug);
        $this->assertArrayNotHasKey('page', $by_slug);
    }

    public function test_json_option_marks_the_default_language_first()
    {
        $db = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
            $this->language_term(6, 'Français', 'fr', 'fr_FR', 'fr', 1, 0),
        ), '');
        $db->option_value = '{"default_lang":"fr"}';
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertSame(6, $result['items'][0]['term_id']);
        $this->assertSame('en', $result['items'][1]['slug']);
        $this->assertFalse($result['items'][1]['is_default']);
    }

    public function test_an_empty_language_can_be_the_default()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 6),
            $this->language_term(9, 'Norsk', 'no', 'nb_NO', 'no', 1, 0),
        ), 'NO');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['no']['is_default']);
        $this->assertSame('Norsk', $by_slug['no']['name']);
        $this->assertSame(9, $by_slug['no']['term_id']);
    }

    public function test_term_language_rows_fill_a_missing_language_term()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->option_value = serialize(array('default_lang' => 'fr'));
        $db->term_language_rows = array(
            $this->language_term(21, 'Français', 'fr', 'fr_FR', 'fr', 0, 0),
        );
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertSame('Français', $result['items'][0]['name']);
        $this->assertSame('fr_FR', $result['items'][0]['locale']);
        $this->assertSame('fr', $result['items'][0]['flag']);
        $this->assertSame(21, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_translation_group_can_supply_the_default_language()
    {
        $db = new Polylang_Languages_Test_Db();
        $db->option_value = serialize(array('default_lang' => ' fr '));
        $db->post_translation_rows = array(
            (object) array(
                'description' => serialize(array(
                    'en' => '15',
                    'fr' => 16,
                )),
            ),
        );
        $db->term_translation_rows = array(
            (object) array(
                'description' => array(
                    'de' => 20,
                ),
            ),
        );
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array('fr', 'de', 'en'), array_keys($by_slug));
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['fr']['is_default']);
        $this->assertSame(0, $by_slug['fr']['term_id']);
        $this->assertSame('fr', $by_slug['fr']['name']);
    }

    public function test_duplicate_rows_keep_locale_name_and_flag()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, '', 'en', '', '', 0, 0),
            $this->language_term(7, 'English', 'en', 'en_GB', 'gb', 0, 2),
        ), 'en');

        $result = Polylang_Languages::export();

        $this->assertCount(1, $result['items']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertSame('en_GB', $result['items'][0]['locale']);
        $this->assertSame('gb', $result['items'][0]['flag']);
        $this->assertSame(7, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_a_blank_slug_is_left_out_of_the_list()
    {
        $blank = $this->language_term(3, 'Blank', '   ', 'en_US', 'us', 0, 0);
        $english = $this->language_term(4, 'English', 'en', 'en_US', 'us', 1, 1);
        $GLOBALS['wpdb'] = $this->database_with_languages(array($blank, $english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_description_already_decoded_as_an_array_is_read()
    {
        $english = $this->language_term(4, 'English', 'en', '', '', 0, 0);
        $english->description = array(
            'locale' => 'en_GB',
            'flag_code' => 'gb',
            'fallbacks' => array('en_US'),
        );
        $GLOBALS['wpdb'] = $this->database_with_languages(array($english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame('en_GB', $result['items'][0]['locale']);
        $this->assertSame('gb', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertArrayNotHasKey('fallbacks', $result['items'][0]);
    }

    public function test_json_description_and_equal_order_use_the_language_name()
    {
        $deutsch = $this->language_term(3, 'Deutsch', 'de', 'de_DE', 'de', 0, 1);
        $english = $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1);
        $deutsch->description = '{"locale":"de_DE","flag_code":"DE"}';
        $GLOBALS['wpdb'] = $this->database_with_languages(array($english, $deutsch), 'de');

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array('de', 'en'), array_keys($by_slug));
        $this->assertSame('de_DE', $by_slug['de']['locale']);
        $this->assertSame('de', $by_slug['de']['flag']);
        $this->assertTrue($by_slug['de']['is_default']);
    }

    public function test_instance_call_matches_the_static_export()
    {
        $GLOBALS['wpdb'] = $this->database_with_languages(array(
            $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 1),
        ), 'en');

        $static = Polylang_Languages::export();
        $instance = (new Polylang_Languages())->export();

        $this->assertSame($static, $instance);
    }

    public function test_string_booleans_still_mark_the_default_language()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array(
        (object) array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'term_id' => 4,
            'flag_code' => 'us',
            'is_default' => 'false',
        ),
        (object) array(
            'slug' => 'fr',
            'name' => 'Français',
            'locale' => 'fr_FR',
            'term_id' => 6,
            'flag_code' => 'fr',
            'is_default' => 'true',
        ),
    );
}
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertTrue($result['items'][0]['is_default']);
        $this->assertSame('en', $result['items'][1]['slug']);
        $this->assertFalse($result['items'][1]['is_default']);
    }

    public function test_a_broken_api_row_does_not_drop_the_other_languages()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array(
        null,
        false,
        '',
        (object) array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'term_id' => 4,
            'flag_code' => 'us',
            'is_default' => true,
        ),
    );
}

function pll_default_language($field = 'slug')
{
    return 'en';
}
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_active_polylang_with_no_languages_explains_what_to_do()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array();
}
PHP
        );

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            'Polylang is active, but no languages were found. Add a language under Languages in Polylang, then preview the migration again.',
            $result['errors'][0]
        );
    }

    public function test_empty_api_list_still_reads_stored_languages()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array();
}

function pll_default_language($field = 'slug')
{
    return false;
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array(
                (object) array(
                    'term_id' => 4,
                    'name' => 'English',
                    'slug' => 'en',
                    'term_group' => 0,
                    'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                    'count' => 2,
                ),
                (object) array(
                    'term_id' => 9,
                    'name' => 'Norsk',
                    'slug' => 'no',
                    'term_group' => 1,
                    'description' => 'a:2:{s:6:"locale";s:5:"nb_NO";s:9:"flag_code";s:2:"no";}',
                    'count' => 0,
                ),
            );
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"no";}';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertArrayHasKey('no', $by_slug);
        $this->assertTrue($by_slug['no']['is_default']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertSame('nb_NO', $by_slug['no']['locale']);
    }

    public function test_api_failure_still_reads_stored_languages()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    throw new RuntimeException('Polylang is not ready.');
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"en";}';
    }
};
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertSame(4, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_api_list_keeps_languages_with_no_posts_from_storage()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array((object) array(
        'slug' => 'en',
        'name' => 'English',
        'locale' => 'en_US',
        'term_id' => 4,
        'flag_code' => 'us',
        'flag' => '<img alt="" />',
        'is_default' => true,
    ));
}

function pll_default_language($field = 'slug')
{
    return 'sv';
}

function get_option($name, $default = false)
{
    if ($name === 'polylang') {
        return array('default_lang' => 'en');
    }
    return $default;
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array(
                (object) array(
                    'term_id' => 4,
                    'name' => 'English',
                    'slug' => 'en',
                    'term_group' => 0,
                    'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                    'count' => 3,
                ),
                (object) array(
                    'term_id' => 8,
                    'name' => 'Svenska',
                    'slug' => 'sv',
                    'term_group' => 1,
                    'description' => 'a:2:{s:6:"locale";s:5:"sv_SE";s:9:"flag_code";s:2:"se";}',
                    'count' => 0,
                ),
            );
        }
        return array();
    }

    public function get_var($query)
    {
        return '';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('sv', 'en'), array_keys($by_slug));
        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['sv']['is_default']);
        $this->assertSame('Svenska', $by_slug['sv']['name']);
        $this->assertSame('sv_SE', $by_slug['sv']['locale']);
        $this->assertSame(8, $by_slug['sv']['term_id']);
        $this->assertSame('se', $by_slug['sv']['flag']);
    }

    public function test_a_non_string_default_language_falls_back_to_the_saved_option()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_default_language($field = 'slug')
{
    return (object) array('slug' => 'en');
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array(
                (object) array(
                    'term_id' => 4,
                    'name' => 'English',
                    'slug' => 'en',
                    'term_group' => 0,
                    'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                    'count' => 1,
                ),
                (object) array(
                    'term_id' => 8,
                    'name' => 'Svenska',
                    'slug' => 'sv',
                    'term_group' => 1,
                    'description' => 'a:2:{s:6:"locale";s:5:"sv_SE";s:9:"flag_code";s:2:"se";}',
                    'count' => 0,
                ),
            );
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"sv";}';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('sv', $result['items'][0]['slug']);
        $this->assertTrue($by_slug['sv']['is_default']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertSame('Svenska', $by_slug['sv']['name']);
    }

    public function test_broken_default_language_call_falls_back_to_the_saved_option()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_default_language($field = 'slug')
{
    throw new RuntimeException('Polylang is not ready.');
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array(
                (object) array(
                    'term_id' => 4,
                    'name' => 'English',
                    'slug' => 'en',
                    'term_group' => 0,
                    'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                    'count' => 1,
                ),
                (object) array(
                    'term_id' => 8,
                    'name' => 'Svenska',
                    'slug' => 'sv',
                    'term_group' => 1,
                    'description' => 'a:2:{s:6:"locale";s:5:"sv_SE";s:9:"flag_code";s:2:"se";}',
                    'count' => 0,
                ),
            );
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"sv";}';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['sv']['is_default']);
        $this->assertSame('Svenska', $by_slug['sv']['name']);
    }

    public function test_duplicate_api_rows_keep_the_name_locale_and_flag()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array(
        (object) array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'term_id' => 4,
            'flag_code' => '',
            'is_default' => true,
        ),
        (object) array(
            'slug' => 'en',
            'name' => 'en',
            'locale' => '',
            'term_id' => 0,
            'flag_code' => 'us',
            'is_default' => false,
        ),
    );
}

function pll_default_language($field = 'slug')
{
    return 'en';
}
PHP
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame('English', $result['items'][0]['name']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertSame(4, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_a_later_duplicate_can_mark_the_default_language()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array(
        (object) array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'term_id' => 4,
            'flag_code' => 'us',
            'is_default' => false,
        ),
        (object) array(
            'slug' => 'fr',
            'name' => 'Français',
            'locale' => 'fr_FR',
            'term_id' => 6,
            'flag_code' => 'fr',
            'is_default' => false,
        ),
        (object) array(
            'slug' => 'fr',
            'name' => 'fr',
            'locale' => '',
            'term_id' => 0,
            'flag_code' => '',
            'is_default' => true,
        ),
    );
}
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['fr']['is_default']);
        $this->assertSame('Français', $by_slug['fr']['name']);
        $this->assertSame('fr_FR', $by_slug['fr']['locale']);
        $this->assertSame(6, $by_slug['fr']['term_id']);
    }

    public function test_default_language_matches_ignoring_case_and_array_records()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array(
        array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'term_id' => '4',
            'flag_code' => 'US',
            'flag' => '<img alt="" />',
            'is_default' => 1,
        ),
        array(
            'slug' => 'sv',
            'name' => 'Svenska',
            'locale' => 'sv_SE',
            'flag_code' => 'se',
            'is_default' => 0,
            'term_props' => array(
                'language' => array('term_id' => 8),
            ),
        ),
    );
}

function pll_default_language($field = 'slug')
{
    return 'SV';
}
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('sv', 'en'), array_keys($by_slug));
        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertSame(4, $by_slug['en']['term_id']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['sv']['is_default']);
        $this->assertSame(8, $by_slug['sv']['term_id']);
        $this->assertSame('se', $by_slug['sv']['flag']);
        $this->assertSame('Svenska', $by_slug['sv']['name']);
    }

    public function test_slug_only_api_is_filled_from_stored_languages()
    {
        $result = $this->export_isolated(<<<'PHP'
function pll_languages_list($args = array())
{
    return array('en', 'de');
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                'count' => 1,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"de";}';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame('English', $by_slug['en']['name']);
        $this->assertSame('en_US', $by_slug['en']['locale']);
        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertSame(4, $by_slug['en']['term_id']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertTrue($by_slug['de']['is_default']);
        $this->assertSame('de', $by_slug['de']['name']);
    }

    public function test_registered_taxonomy_includes_languages_with_no_posts()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_terms($args = array())
{
    if (!is_array($args) || !isset($args['hide_empty']) || $args['hide_empty'] !== false) {
        fwrite(STDERR, "hide_empty must be false so languages with no posts are kept\n");
        exit(1);
    }
    if ($args['taxonomy'] === 'language') {
        return array((object) array(
            'term_id' => 9,
            'name' => 'Norsk',
            'slug' => 'no',
            'term_group' => 0,
            'description' => 'a:2:{s:6:"locale";s:5:"nb_NO";s:9:"flag_code";s:2:"no";}',
            'count' => 0,
        ));
    }
    return array();
}

function get_option($name, $default = false)
{
    if ($name === 'polylang') {
        return array('default_lang' => 'no');
    }
    return $default;
}
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('no', $result['items'][0]['slug']);
        $this->assertSame('Norsk', $result['items'][0]['name']);
        $this->assertSame('nb_NO', $result['items'][0]['locale']);
        $this->assertSame('no', $result['items'][0]['flag']);
        $this->assertSame(9, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_an_inactive_language_with_no_posts_is_still_exported()
    {
        $english = $this->language_term(4, 'English', 'en', 'en_US', 'us', 0, 0);
        $english->description = serialize(array(
            'locale' => 'en_US',
            'flag_code' => 'us',
            'active' => false,
        ));
        $GLOBALS['wpdb'] = $this->database_with_languages(array($english), 'en');

        $result = Polylang_Languages::export();

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('en', $result['items'][0]['slug']);
        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_flag_markup_does_not_hide_the_stored_flag_code()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_terms($args = array())
{
    if (!is_array($args) || !isset($args['taxonomy']) || $args['taxonomy'] !== 'language') {
        return array();
    }
    return array((object) array(
        'term_id' => 4,
        'name' => 'English',
        'slug' => 'en',
        'term_group' => 0,
        'description' => serialize(array('flag' => '<img alt="" />')),
        'count' => 0,
    ));
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"en";}';
    }
};
PHP
        );

        $this->assertSame('en_US', $result['items'][0]['locale']);
        $this->assertSame('us', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_empty_serialized_description_is_replaced_by_the_stored_row()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_terms($args = array())
{
    if (!is_array($args) || !isset($args['taxonomy'])) {
        return array();
    }
    if ($args['taxonomy'] === 'language') {
        return array((object) array(
            'term_id' => 4,
            'name' => 'English',
            'slug' => 'en',
            'term_group' => 0,
            'description' => 'a:0:{}',
            'count' => 0,
        ));
    }
    return array();
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"en_GB";s:9:"flag_code";s:2:"gb";}',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"en";}';
    }
};
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en_GB', $result['items'][0]['locale']);
        $this->assertSame('gb', $result['items'][0]['flag']);
        $this->assertSame(4, $result['items'][0]['term_id']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_filtered_term_query_still_keeps_languages_with_no_posts()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_terms($args = array())
{
    if (!is_array($args) || !isset($args['hide_empty']) || $args['hide_empty'] !== false) {
        fwrite(STDERR, "hide_empty must be false\n");
        exit(1);
    }
    if ($args['taxonomy'] === 'language') {
        return array((object) array(
            'term_id' => 4,
            'name' => 'English',
            'slug' => 'en',
            'term_group' => 0,
            'description' => '',
            'count' => 4,
        ));
    }
    return array();
}

function get_option($name, $default = false)
{
    if ($name === 'polylang') {
        return array('default_lang' => 'no');
    }
    return $default;
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array(
                (object) array(
                    'term_id' => 4,
                    'name' => 'English',
                    'slug' => 'en',
                    'term_group' => 0,
                    'description' => 'a:2:{s:6:"locale";s:5:"en_US";s:9:"flag_code";s:2:"us";}',
                    'count' => 4,
                ),
                (object) array(
                    'term_id' => 9,
                    'name' => 'Norsk',
                    'slug' => 'no',
                    'term_group' => 1,
                    'description' => 'a:2:{s:6:"locale";s:5:"nb_NO";s:9:"flag_code";s:2:"no";}',
                    'count' => 0,
                ),
            );
        }
        if (strpos($query, "'post_translations'") !== false) {
            return array((object) array(
                'slug' => 'pll_group',
                'description' => 'a:1:{s:2:"pt";i:12;}',
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return '';
    }
};
PHP
        );

        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('en_US', $by_slug['en']['locale']);
        $this->assertSame('us', $by_slug['en']['flag']);
        $this->assertFalse($by_slug['en']['is_default']);
        $this->assertSame('Norsk', $by_slug['no']['name']);
        $this->assertSame('nb_NO', $by_slug['no']['locale']);
        $this->assertSame(9, $by_slug['no']['term_id']);
        $this->assertTrue($by_slug['no']['is_default']);
        $this->assertSame('pt', $by_slug['pt']['slug']);
    }

    public function test_term_meta_fills_locale_and_flag_when_the_description_is_empty()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_term_meta($term_id, $key, $single = false)
{
    if ((int) $term_id === 4 && $key === 'locale') {
        return 'en_GB';
    }
    if ((int) $term_id === 4 && $key === 'flag_code') {
        return 'GB';
    }
    return '';
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 4,
                'name' => 'English',
                'slug' => 'en',
                'term_group' => 0,
                'description' => '',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return '';
    }
};
PHP
        );

        $this->assertSame('en_GB', $result['items'][0]['locale']);
        $this->assertSame('gb', $result['items'][0]['flag']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_unregistered_taxonomy_falls_through_to_stored_rows()
    {
        $result = $this->export_isolated(<<<'PHP'
function get_terms($args = array())
{
    return (object) array('errors' => array('invalid_taxonomy'));
}

$GLOBALS['wpdb'] = new class {
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $options = 'wp_options';

    public function prepare($query, $value)
    {
        return str_replace('%s', "'" . $value . "'", $query);
    }

    public function get_results($query)
    {
        if (strpos($query, "'language'") !== false) {
            return array((object) array(
                'term_id' => 6,
                'name' => 'Français',
                'slug' => 'fr',
                'term_group' => 0,
                'description' => 'a:2:{s:6:"locale";s:5:"fr_FR";s:9:"flag_code";s:2:"fr";}',
                'count' => 0,
            ));
        }
        return array();
    }

    public function get_var($query)
    {
        return 'a:1:{s:12:"default_lang";s:2:"fr";}';
    }
};
PHP
        );

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('fr', $result['items'][0]['slug']);
        $this->assertSame('Français', $result['items'][0]['name']);
        $this->assertSame('fr_FR', $result['items'][0]['locale']);
        $this->assertTrue($result['items'][0]['is_default']);
    }

    public function test_older_description_flag_array_rows_and_translation_order()
    {
        $english = array(
            'term_id' => -5,
            'name' => 'English',
            'slug' => 'en',
            'term_group' => 0,
            'description' => serialize(array(
                'locale' => 'en_CA',
                'flag' => 'CA',
            )),
            'count' => 0,
        );
        $db = new Polylang_Languages_Test_Db();
        $db->option_value = serialize(array('default_lang' => 'en'));
        $db->language_rows = array($english);
        $db->post_translation_rows = array(
            (object) array(
                'description' => serialize(array(
                    'sv' => 3,
                    'de' => 2,
                    'fr' => 1,
                )),
            ),
        );
        $GLOBALS['wpdb'] = $db;

        $result = Polylang_Languages::export();
        $by_slug = $this->index_by_slug($result['items']);

        $this->assertSame(array('en', 'de', 'fr', 'sv'), array_keys($by_slug));
        $this->assertSame('en_CA', $by_slug['en']['locale']);
        $this->assertSame('ca', $by_slug['en']['flag']);
        $this->assertSame(0, $by_slug['en']['term_id']);
        $this->assertTrue($by_slug['en']['is_default']);
        $this->assertFalse($by_slug['de']['is_default']);
        $this->assertSame('de', $by_slug['de']['name']);
    }

    public function test_custom_flag_url_is_kept_when_no_flag_code_is_stored()
    {
        $language = (object) array(
            'slug' => 'cy',
            'name' => 'Cymraeg',
            'locale' => 'cy',
            'term_id' => 12,
            'flag_code' => '',
            'custom_flag_url' => 'https://example.com/flags/cy.png',
            'flag' => '<img alt="" />',
            'is_default' => false,
        );

        $item = $this->invoke('item_from_record', array($language, 'cy'));

        $this->assertSame('https://example.com/flags/cy.png', $item['flag']);
        $this->assertSame('Cymraeg', $item['name']);
        $this->assertFalse($item['is_default']);
        $this->assertSame(12, $item['term_id']);
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
     * Run export() in a separate process so Polylang stubs stay isolated.
     *
     * @param string $prelude PHP that defines stubs and fixtures.
     * @return array
     */
    private function export_isolated($prelude)
    {
        $class_file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-languages.php';
        $script = "<?php\n" . $prelude . "\nrequire " . var_export($class_file, true) . ";\n"
            . "echo json_encode(\\WMM\\Tolka\\Polylang_Languages::export());\n";
        $file = tempnam(sys_get_temp_dir(), 'wmm-tolka-lang');
        if ($file === false) {
            $this->fail('Could not create an isolated PHP script.');
        }
        file_put_contents($file, $script);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
        $process = proc_open(
            $command,
            array(
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ),
            $pipes
        );
        if (!is_resource($process)) {
            unlink($file);
            $this->fail('Could not start an isolated PHP process.');
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($file);

        $this->assertSame(0, $status, $errors . "\n" . $output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        return $decoded;
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

    public $term_language_rows = array();

    public $post_translation_rows = array();

    public $term_translation_rows = array();

    public $option_value = '';

    public $language_fails = false;

    public $show_errors = true;

    public $suppress_errors = false;

    public $fail_if_errors_visible = false;

    public $hidden_errors = 0;

    public function hide_errors()
    {
        $this->hidden_errors++;
        $previous = $this->show_errors;
        $this->show_errors = false;
        $this->suppress_errors = true;

        return $previous;
    }

    public function show_errors()
    {
        $this->show_errors = true;
    }

    public function prepare($query, $value)
    {
        $this->queries[] = $query;
        $quoted = "'" . str_replace("'", "''", (string) $value) . "'";
        return str_replace('%s', $quoted, $query);
    }

    public function get_results($query)
    {
        $this->queries[] = $query;
        if ($this->fail_if_errors_visible && $this->show_errors) {
            throw new RuntimeException('database error was visible');
        }

        if (strpos($query, "'term_language'") !== false) {
            return $this->term_language_rows;
        }
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
        if ($this->fail_if_errors_visible && $this->show_errors) {
            throw new RuntimeException('database error was visible');
        }
        return $this->option_value;
    }
}
