<?php

/**
 * String export behavior, including older Polylang catalogues and WPML tables.
 *
 * @package MigrationManager
 */

use WMM\Tolka\Polylang_Strings;

if (!class_exists(Polylang_Strings::class)) {
    require_once dirname(__DIR__, 2) . '/includes/tolka/class-polylang-strings.php';
}

/**
 * Reads options and the database through test doubles.
 */
class WMM_Tolka_Strings_Probe extends Polylang_Strings
{
    /**
     * @var array
     */
    public $options = array();

    /**
     * @var object|null
     */
    public $db = null;

    /**
     * @var bool|null
     */
    public $active_override = null;

    /**
     * @var string[]|null
     */
    public $api_slugs = null;

    /**
     * @var array|null
     */
    public $api_strings = null;

    /**
     * @param array $snapshot Already-read rows.
     * @return array
     */
    public function build_snapshot(array $snapshot)
    {
        return $this->build($snapshot);
    }

    /**
     * @return bool
     */
    protected function polylang_is_active()
    {
        if ($this->active_override !== null) {
            return (bool) $this->active_override;
        }

        return parent::polylang_is_active();
    }

    /**
     * @param string $name    Option name.
     * @param mixed  $default Fallback.
     * @return mixed
     */
    protected function option($name, $default = null)
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }

        return $default;
    }

    /**
     * @return object|null
     */
    protected function database()
    {
        if ($this->db) {
            return $this->db;
        }

        return parent::database();
    }

    /**
     * @return string[]|null
     */
    protected function read_api_language_slugs()
    {
        if ($this->api_slugs !== null) {
            return $this->api_slugs;
        }

        return parent::read_api_language_slugs();
    }

    /**
     * @return array
     */
    protected function read_api_strings()
    {
        if ($this->api_strings !== null) {
            return $this->api_strings;
        }

        return parent::read_api_strings();
    }
}

/**
 * Exporter whose reader fails before any data is touched.
 */
class WMM_Tolka_Strings_Broken extends Polylang_Strings
{
    /**
     * @return array
     */
    protected function read_snapshot()
    {
        throw new RuntimeException('disk failed');
    }
}

/**
 * Minimal stand-in for $wpdb. It only answers the reads the exporter performs.
 */
class WMM_Tolka_Strings_Db
{
    /**
     * @var string
     */
    public $prefix = 'wp_';

    /**
     * @var string
     */
    public $terms = 'wp_terms';

    /**
     * @var string
     */
    public $term_taxonomy = 'wp_term_taxonomy';

    /**
     * @var string
     */
    public $termmeta = 'wp_termmeta';

    /**
     * @var string
     */
    public $posts = 'wp_posts';

    /**
     * @var string
     */
    public $postmeta = 'wp_postmeta';

    /**
     * @var string
     */
    public $options = 'wp_options';

    /**
     * @var string
     */
    public $last_error = '';

    /**
     * @var array
     */
    public $language_rows = array();

    /**
     * @var array
     */
    public $term_meta_rows = array();

    /**
     * @var array
     */
    public $post_rows = array();

    /**
     * @var array
     */
    public $post_meta_rows = array();

    /**
     * @var array
     */
    public $option_rows = array();

    /**
     * @var array
     */
    public $term_slug_rows = array();

    /**
     * @var array
     */
    public $icl_string_rows = array();

    /**
     * @var array
     */
    public $icl_translation_rows = array();

    /**
     * @var bool
     */
    public $has_icl_strings = false;

    /**
     * @var bool
     */
    public $has_icl_translations = false;

    /**
     * @var bool
     */
    public $fail_options = false;

    /**
     * @var bool
     */
    public $fail_icl_strings = false;

    /**
     * @var bool
     */
    public $fail_icl_translations = false;

    /**
     * @var bool
     */
    public $fail_languages = false;

    /**
     * @var bool
     */
    public $fail_term_meta = false;

    /**
     * @var bool
     */
    public $fail_posts = false;

    /**
     * @var bool
     */
    public $fail_post_meta = false;

    /**
     * @var bool
     */
    public $fail_term_lookup = false;

    /**
     * @var bool
     */
    public $fail_named_icl_columns = false;

    /**
     * @var bool
     */
    public $throw_on_translation_taxonomy = false;

    /**
     * @var string[]
     */
    public $queries = array();

    /**
     * @param string $text Text to escape for LIKE.
     * @return string
     */
    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    /**
     * @param string $query SQL with placeholders.
     * @return string
     */
    public function prepare($query)
    {
        $args = func_get_args();
        array_shift($args);
        $index = 0;

        return preg_replace_callback('/(?<!%)%[sdf]/', function ($match) use (&$args, &$index) {
            $value = $args[$index];
            $index++;
            if ($match[0] === '%d' || $match[0] === '%f') {
                return (string) (int) $value;
            }

            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
    }

    /**
     * @param string $query SQL.
     * @return string|null
     */
    public function get_var($query)
    {
        $this->queries[] = (string) $query;
        $plain = str_replace('\\', '', (string) $query);
        if (strpos($plain, 'icl_string_translations') !== false) {
            return $this->has_icl_translations ? $this->prefix . 'icl_string_translations' : null;
        }
        if (strpos($plain, 'icl_strings') !== false) {
            return $this->has_icl_strings ? $this->prefix . 'icl_strings' : null;
        }

        return null;
    }

    /**
     * @param string $query  SQL.
     * @param string $output Requested row shape. Ignored; rows are already arrays or objects.
     * @return array|null
     */
    public function get_results($query, $output = null)
    {
        unset($output);
        $this->queries[] = (string) $query;
        $plain = str_replace('\\', '', (string) $query);

        if ($this->throw_on_translation_taxonomy && (strpos($plain, 'post_translations') !== false || strpos($plain, 'term_translations') !== false)) {
            throw new RuntimeException('missing taxonomy');
        }
        if (strpos($plain, "taxonomy = 'language'") !== false) {
            if ($this->fail_languages) {
                $this->last_error = 'language taxonomy is unreadable';
                return null;
            }
            return $this->language_rows;
        }
        if (strpos($plain, 'post_translations') !== false || strpos($plain, 'term_translations') !== false) {
            return array();
        }
        if (strpos($plain, '_pll_strings_translations') !== false && strpos($plain, 'post_id') !== false) {
            if ($this->fail_post_meta) {
                $this->last_error = 'post meta is unreadable';
                return null;
            }
            return $this->post_meta_rows;
        }
        if (strpos($plain, '_pll_strings_translations') !== false) {
            if ($this->fail_term_meta) {
                $this->last_error = 'term meta is unreadable';
                return null;
            }
            return $this->term_meta_rows;
        }
        if (strpos($plain, 'polylang_mo') !== false && strpos($plain, 'post_type') !== false) {
            if ($this->fail_posts) {
                $this->last_error = 'posts are unreadable';
                return null;
            }
            return $this->post_rows;
        }
        if (strpos($plain, 'option_name') !== false) {
            if ($this->fail_options) {
                $this->last_error = 'options table is unreadable';
                return null;
            }
            return $this->option_rows;
        }
        if (strpos($plain, 'icl_string_translations') !== false) {
            if ($this->fail_icl_translations || ($this->fail_named_icl_columns && stripos($plain, 'select *') === false)) {
                $this->last_error = 'translation table is unreadable';
                return null;
            }
            return $this->icl_translation_rows;
        }
        if (strpos($plain, 'icl_strings') !== false) {
            if ($this->fail_icl_strings || ($this->fail_named_icl_columns && stripos($plain, 'select *') === false)) {
                $this->last_error = 'string table is unreadable';
                return null;
            }
            return $this->icl_string_rows;
        }
        if (strpos($plain, 'term_id IN') !== false) {
            if ($this->fail_term_lookup) {
                $this->last_error = 'term lookup failed';
                return null;
            }
            return $this->term_slug_rows;
        }

        return array();
    }
}

/**
 * @group tolka
 * @group tolka-strings
 */
class Test_Polylang_Strings extends PHPUnit\Framework\TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['polylang']);
    }

    /**
     * @return void
     */
    public function test_class_is_loaded_for_the_suite()
    {
        if (!class_exists(Polylang_Strings::class)) {
            $this->markTestSkipped('Polylang_Strings is not loaded.');
        }
        $this->assertTrue(class_exists(Polylang_Strings::class));
    }

    /**
     * @return void
     */
    public function test_export_without_polylang_returns_an_empty_list_and_an_error()
    {
        $result = (new Polylang_Strings())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active on this site, and no string translations were found.'),
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_a_reader_failure_keeps_polylang_data_and_hides_the_internal_error()
    {
        $result = (new WMM_Tolka_Strings_Broken())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('String translations could not be read. Existing Polylang data was left unchanged.'),
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_registered_string_keeps_a_real_translation_and_leaves_the_rest_blank()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array(
                array('slug' => 'en', 'term_id' => 10),
                array('slug' => 'fr', 'term_id' => 11),
                array('slug' => 'de', 'term_id' => 12),
            ),
            'registered' => array(
                array(
                    'name' => 'Hours',
                    'string' => 'Open daily',
                    'context' => 'Widget',
                    'multiline' => false,
                ),
            ),
            'mo' => array(
                'fr' => array(array('Open daily', 'Ouvert tous les jours')),
            ),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame(array('name', 'string', 'context', 'translations'), array_keys($item));
        $this->assertSame('Hours', $item['name']);
        $this->assertSame('Open daily', $item['string']);
        $this->assertSame('Widget', $item['context']);
        $this->assertSame(
            array(
                'en' => '',
                'fr' => 'Ouvert tous les jours',
                'de' => '',
            ),
            $item['translations']
        );
    }

    /**
     * @return void
     */
    public function test_two_names_for_one_source_share_the_stored_translation()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en', 'fr'),
            'registered' => array(
                array('name' => 'Hours', 'string' => 'Open', 'context' => 'Widget'),
                array('name' => 'Footer note', 'string' => 'Open', 'context' => 'theme'),
            ),
            'mo' => array(
                'fr' => array(array('Open', 'Ouvert')),
            ),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame('Ouvert', $result['items'][0]['translations']['fr']);
        $this->assertSame('Ouvert', $result['items'][1]['translations']['fr']);
        $this->assertSame('Hours', $result['items'][0]['name']);
        $this->assertSame('Footer note', $result['items'][1]['name']);
    }

    /**
     * @return void
     */
    public function test_a_translation_without_a_registration_is_still_exported()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => false,
            'languages' => array(
                array('slug' => 'en'),
                array('slug' => 'fr'),
            ),
            'mo' => array(
                'fr' => array(array('Site title', 'Titre du site')),
            ),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('', $result['items'][0]['name']);
        $this->assertSame('', $result['items'][0]['context']);
        $this->assertSame('Site title', $result['items'][0]['string']);
        $this->assertSame('Titre du site', $result['items'][0]['translations']['fr']);
    }

    /**
     * @return void
     */
    public function test_an_explicit_translation_that_matches_the_source_is_kept()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('fr'),
            'mo' => array(
                'fr' => array(array('Hello', 'Hello')),
            ),
        ));

        $this->assertSame('Hello', $result['items'][0]['translations']['fr']);
    }

    /**
     * @return void
     */
    public function test_empty_rows_are_skipped_without_an_error()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('fr'),
            'mo' => array(
                'fr' => array(
                    array('', ''),
                    array('Hello', ''),
                    array('Kept', 'Garde'),
                ),
            ),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Kept', $result['items'][0]['string']);
        $this->assertSame('Garde', $result['items'][0]['translations']['fr']);
    }

    /**
     * @param mixed  $bucket        One stored catalogue.
     * @param string $expected_name Name carried by that shape, if any.
     * @return void
     *
     * @dataProvider catalogueShapes
     */
    public function test_catalogue_shapes($bucket, $expected_name)
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en', 'fr'),
            'mo' => array('fr' => $bucket),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('Hello', $result['items'][0]['string']);
        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
        $this->assertSame('', $result['items'][0]['translations']['en']);
        $this->assertSame($expected_name, $result['items'][0]['name']);
    }

    /**
     * @return array<string, array>
     */
    public static function catalogueShapes()
    {
        $serialized = serialize(array(array('Hello', 'Bonjour')));

        return array(
            'pairs' => array(array(array('Hello', 'Bonjour')), ''),
            'single pair' => array(array('Hello', 'Bonjour'), ''),
            'serialized' => array($serialized, ''),
            'slashed serialized' => array(addslashes($serialized), ''),
            'json map' => array('{"Hello":"Bonjour"}', ''),
            'plural forms' => array(array(array('Hello', array('', 'Bonjour'))), ''),
            'entry with a name' => array(
                array(array(
                    'singular' => 'Hello',
                    'translations' => array('Bonjour'),
                    'name' => 'greet',
                    'context' => 'theme',
                )),
                'greet',
            ),
        );
    }

    /**
     * @return void
     */
    public function test_an_unreadable_catalogue_is_skipped_and_other_languages_remain()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en', 'fr'),
            'mo' => array(
                'en' => 'this is not a catalogue',
                'fr' => array(array('Hello', 'Bonjour')),
            ),
        ));

        $this->assertSame(
            array('String translations for one language could not be read and were skipped.'),
            $result['errors']
        );
        $this->assertCount(1, $result['items']);
        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
    }

    /**
     * @return void
     */
    public function test_removed_language_is_reported_and_not_invented()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en'),
            'orphan_groups' => 1,
            'mo' => array(
                'en' => array(array('Hello', 'Hello')),
            ),
        ));

        $this->assertSame(
            array('String translations for a language that is no longer installed were skipped.'),
            $result['errors']
        );
        $this->assertSame('en', key($result['items'][0]['translations']));
    }

    /**
     * @return void
     */
    public function test_active_site_with_no_strings_is_an_empty_success()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en', 'fr'),
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    /**
     * @return void
     */
    public function test_wpml_array_context_is_flattened_the_way_polylang_does()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en'),
            'registered' => array(
                array(
                    'name' => '',
                    'string' => 'My taxononomy',
                    'context' => array(
                        'domain' => 'Types-TAX',
                        'context' => 'taxonomy singular name',
                    ),
                ),
            ),
        ));

        $this->assertSame('taxonomy singular name', $result['items'][0]['name']);
        $this->assertSame('Types-TAX', $result['items'][0]['context']);
        $this->assertSame('My taxononomy', $result['items'][0]['string']);
    }

    /**
     * @return void
     */
    public function test_wpml_rows_fill_blank_translations_and_do_not_replace_polylang()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('en', 'fr'),
            'registered' => array(
                array('name' => 'footer', 'string' => 'Call us', 'context' => 'theme'),
            ),
            'mo' => array(
                'fr' => array(array('Call us', 'Appelez')),
            ),
            'wpml_rows' => array(
                array(
                    'name' => 'footer',
                    'string' => 'Call us',
                    'context' => 'theme',
                    'translations' => array('fr' => 'From WPML', 'de' => 'Rufen Sie an'),
                ),
                array(
                    'name' => 'banner',
                    'string' => 'Welcome',
                    'context' => 'plugin',
                    'translations' => array('fr' => 'Bienvenue'),
                ),
            ),
        ));

        $this->assertSame('Appelez', $result['items'][0]['translations']['fr']);
        $this->assertSame('Rufen Sie an', $result['items'][0]['translations']['de']);
        $this->assertSame('Welcome', $result['items'][1]['string']);
        $this->assertSame('Bienvenue', $result['items'][1]['translations']['fr']);
        $this->assertArrayHasKey('de', $result['items'][1]['translations']);
        $this->assertSame('', $result['items'][1]['translations']['de']);
    }

    /**
     * @return void
     */
    public function test_widgets_and_core_options_are_included_when_polylang_is_active()
    {
        $probe = $this->probe(null, array(
            'blogname' => 'Acme',
            'blogdescription' => 'Parts and service',
            'date_format' => '',
            'time_format' => 'g:i a',
            'sidebars_widgets' => array(
                'wp_inactive_widgets' => array('text-9'),
                'array_version' => 3,
                'sidebar-1' => array('text-2', 'custom_html-3', 'recent-posts-1', 'text-4'),
            ),
            'widget_text' => array(
                2 => array('title' => 'Hours', 'text' => 'Open daily'),
                4 => array('title' => 'French only', 'text' => 'Bonjour', 'pll_lang' => 'fr'),
                9 => array('title' => 'Hidden', 'text' => 'Nope'),
                '_multiwidget' => 1,
            ),
            'widget_custom_html' => array(
                3 => array('title' => 'Note', 'content' => '<p>Hello</p>'),
            ),
            'widget_recent-posts' => array(
                1 => array('title' => 'News'),
            ),
        ), true);
        $result = $probe->export();

        $strings = array();
        foreach ($result['items'] as $item) {
            $strings[] = $item['context'] . '|' . $item['name'] . '|' . $item['string'];
        }

        $this->assertSame(array(), $result['errors']);
        $this->assertContains('WordPress|blogname|Acme', $strings);
        $this->assertContains('WordPress|blogdescription|Parts and service', $strings);
        $this->assertContains('WordPress|time_format|g:i a', $strings);
        $this->assertNotContains('WordPress|date_format|', $strings);
        $this->assertContains('Widget|Widget title|Hours', $strings);
        $this->assertContains('Widget|Widget text|Open daily', $strings);
        $this->assertContains('Widget|Widget title|Note', $strings);
        $this->assertContains('Widget|Widget text|<p>Hello</p>', $strings);
        $this->assertContains('Widget|Widget title|News', $strings);
        $this->assertNotContains('Widget|Widget title|Hidden', $strings);
        $this->assertNotContains('Widget|Widget title|French only', $strings);
        $this->assertNotContains('Widget|Widget text|Bonjour', $strings);
    }

    /**
     * @return void
     */
    public function test_widgets_are_not_invented_when_polylang_is_inactive()
    {
        $probe = $this->probe(null, array(
            'blogname' => 'Acme',
            'sidebars_widgets' => array(
                'sidebar-1' => array('text-2'),
            ),
            'widget_text' => array(
                2 => array('title' => 'Hours', 'text' => 'Open daily'),
            ),
        ), false);
        $result = $probe->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active on this site, and no string translations were found.'),
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_duplicate_widget_registration_is_kept_once()
    {
        $probe = $this->probe(null, array(
            'sidebars_widgets' => array(
                'sidebar-1' => array('text-2'),
            ),
            'widget_text' => array(
                2 => array('title' => 'Hours'),
            ),
        ), true);
        $probe->api_strings = array(
            array('name' => 'Widget title', 'string' => 'Hours', 'context' => 'Widget'),
        );
        $result = $probe->export();

        $this->assertCount(1, $result['items']);
        $this->assertSame('Hours', $result['items'][0]['string']);
    }

    /**
     * @return void
     */
    public function test_database_catalogues_merge_and_current_term_meta_wins()
    {
        $db = $this->mergedDatabase();
        $GLOBALS['wpdb'] = $db;
        $result = (new Polylang_Strings())->export();

        $by_string = array();
        foreach ($result['items'] as $item) {
            $by_string[$item['string']] = $item;
        }

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('Bonjour', $by_string['Hello']['translations']['fr']);
        $this->assertSame('', $by_string['Hello']['translations']['en']);
        $this->assertSame('Option', $by_string['Option only']['translations']['fr']);
        $this->assertSame('Ancien', $by_string['Only legacy']['translations']['fr']);
        $this->assertSame('Meta', $by_string['Meta only']['translations']['fr']);
        $this->assertSame('Heures', $by_string['Hours']['translations']['fr']);
        $this->assertSame('footer', $by_string['Call us']['name']);
        $this->assertSame('theme', $by_string['Call us']['context']);
        $this->assertSame('Appelez-nous', $by_string['Call us']['translations']['fr']);
        $this->assertSame('Bienvenue', $by_string['Welcome']['translations']['fr']);
        $this->assertArrayNotHasKey('Ghost', $by_string);
        $this->assertDoesNotWrite($db);
    }

    /**
     * @return void
     */
    public function test_language_order_follows_the_polylang_api_when_it_is_available()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
            array('term_id' => 10, 'slug' => 'en'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'Bonjour'))),
            ),
        );
        $probe = $this->probe($db, array(), true, array('en', 'fr'));
        $result = $probe->export();

        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['translations']));
    }

    /**
     * @return void
     */
    public function test_a_term_outside_the_language_list_can_still_be_matched_by_slug()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 10, 'slug' => 'en'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 12,
                'meta_value' => serialize(array(array('Hello', 'Hallo'))),
            ),
        );
        $db->term_slug_rows = array(
            array('term_id' => 12, 'slug' => 'de'),
        );
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('Hallo', $result['items'][0]['translations']['de']);
        $this->assertSame('', $result['items'][0]['translations']['en']);
    }

    /**
     * @return void
     */
    public function test_translations_for_a_deleted_language_are_reported()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 10, 'slug' => 'en'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 99,
                'meta_value' => serialize(array(array('Ghost', 'Fantome'))),
            ),
            array(
                'term_id' => 10,
                'meta_value' => serialize(array(array('Hello', 'Hello'))),
            ),
        );
        $result = $this->probe($db, array(), true)->export();

        $strings = array();
        foreach ($result['items'] as $item) {
            $strings[] = $item['string'];
        }
        $this->assertNotContains('Ghost', $strings);
        $this->assertContains('Hello', $strings);
        $this->assertContains(
            'String translations for a language that is no longer installed were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_a_damaged_saved_registry_does_not_drop_other_strings()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            (object) array('term_id' => '11', 'slug' => 'fr'),
        );
        $db->option_rows = array(
            array(
                'option_name' => 'polylang_wpml_strings',
                'option_value' => 'broken-registry',
            ),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'Bonjour'))),
            ),
        );
        $result = $this->probe($db)->export();

        $this->assertSame('Hello', $result['items'][0]['string']);
        $this->assertContains(
            'The saved list of registered strings is damaged, so those strings were skipped.',
            $result['errors']
        );
        $this->assertNotContains(
            'Polylang is not active on this site, and no string translations were found.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_a_missing_legacy_string_table_is_not_an_error()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 10, 'slug' => 'en'),
        );
        $db->has_icl_strings = false;
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    /**
     * @return void
     */
    public function test_an_unreadable_legacy_string_table_is_reported()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'Bonjour'))),
            ),
        );
        $db->has_icl_strings = true;
        $db->fail_icl_strings = true;
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
        $this->assertContains(
            'A legacy string table could not be read, so those strings were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_missing_translation_taxonomies_do_not_abort_the_export()
    {
        $db = $this->mergedDatabase();
        $db->throw_on_translation_taxonomy = true;
        $result = $this->probe($db)->export();

        $this->assertNotEmpty($result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    /**
     * @return void
     */
    public function test_a_stored_mo_object_is_read_from_its_entries()
    {
        $entry = new stdClass();
        $entry->singular = 'Hello';
        $entry->translations = array('Bonjour');
        $entry->name = 'greet';
        $entry->context = 'theme';
        $mo = new stdClass();
        $mo->entries = array($entry);

        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('fr'),
            'mo' => array('fr' => serialize($mo)),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('greet', $result['items'][0]['name']);
        $this->assertSame('theme', $result['items'][0]['context']);
        $this->assertSame('Hello', $result['items'][0]['string']);
        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
    }

    /**
     * @return void
     */
    public function test_later_term_meta_row_replaces_an_older_copy_of_the_same_string()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'First'))),
            ),
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(
                    array('Hello', 'Second'),
                    array('Extra', 'Plus'),
                )),
            ),
        );
        $result = $this->probe($db, array(), true)->export();
        $by_string = array();
        foreach ($result['items'] as $item) {
            $by_string[$item['string']] = $item['translations']['fr'];
        }

        $this->assertSame('Second', $by_string['Hello']);
        $this->assertSame('Plus', $by_string['Extra']);
        $this->assertSame(array(), $result['errors']);
    }

    /**
     * @return void
     */
    public function test_strings_are_kept_when_the_language_list_query_fails()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->fail_languages = true;
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'Bonjour'))),
            ),
        );
        $db->term_slug_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
        $this->assertContains(
            'The language list could not be read. String translations that could still be matched are included.',
            $result['errors']
        );
        $this->assertNotContains(
            'String translations for a language that is no longer installed were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_legacy_posts_remain_when_term_meta_cannot_be_read()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->fail_term_meta = true;
        $db->language_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $db->post_rows = array(
            array(
                'ID' => 5,
                'post_title' => 'polylang_mo_11',
                'post_content' => serialize(array(array('Hello', 'Salut'))),
            ),
        );
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame('Salut', $result['items'][0]['translations']['fr']);
        $this->assertContains(
            'String translations for one language could not be read and were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_term_meta_remains_when_legacy_posts_cannot_be_read()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->fail_posts = true;
        $db->language_rows = array(
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(array('Hello', 'Bonjour'))),
            ),
        );
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame('Bonjour', $result['items'][0]['translations']['fr']);
        $this->assertContains(
            'String translations for one language could not be read and were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_a_failed_slug_lookup_is_not_described_as_a_removed_language()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->fail_term_lookup = true;
        $db->language_rows = array(
            array('term_id' => 10, 'slug' => 'en'),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 99,
                'meta_value' => serialize(array(array('Ghost', 'Fantome'))),
            ),
        );
        $result = $this->probe($db, array(), true)->export();
        $strings = array();
        foreach ($result['items'] as $item) {
            $strings[] = $item['string'];
        }

        $this->assertNotContains('Ghost', $strings);
        $this->assertContains(
            'String translations for one language could not be read and were skipped.',
            $result['errors']
        );
        $this->assertNotContains(
            'String translations for a language that is no longer installed were skipped.',
            $result['errors']
        );
    }

    /**
     * @return void
     */
    public function test_wpml_rows_are_read_when_the_usual_column_names_are_rejected()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->has_icl_strings = true;
        $db->has_icl_translations = true;
        $db->fail_named_icl_columns = true;
        $db->language_rows = array(
            array('term_id' => 10, 'slug' => 'en'),
            array('term_id' => 11, 'slug' => 'fr'),
        );
        $db->icl_string_rows = array(
            array(
                'id' => 7,
                'context' => '',
                'gettext_context' => 'plugin',
                'name' => 'banner',
                'value' => 'Welcome',
                'language' => 'en',
            ),
        );
        $db->icl_translation_rows = array(
            array(
                'string_id' => 7,
                'language' => 'fr',
                'value' => '',
                'mo_string' => 'Bienvenue',
            ),
        );
        $result = $this->probe($db, array(), true)->export();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('banner', $result['items'][0]['name']);
        $this->assertSame('plugin', $result['items'][0]['context']);
        $this->assertSame('Welcome', $result['items'][0]['string']);
        $this->assertSame('Bienvenue', $result['items'][0]['translations']['fr']);
        $this->assertSame('', $result['items'][0]['translations']['en']);
    }

    /**
     * @return void
     */
    public function test_string_zero_is_not_dropped()
    {
        $result = $this->probe()->build_snapshot(array(
            'active' => true,
            'languages' => array('fr'),
            'registered' => array(
                array('name' => 'count', 'string' => '0', 'context' => 'plugin'),
            ),
            'mo' => array(
                'fr' => array(array('0', 'zéro')),
            ),
        ));

        $this->assertSame('0', $result['items'][0]['string']);
        $this->assertSame('zéro', $result['items'][0]['translations']['fr']);
    }

    /**
     * @param object|null $db         Database double.
     * @param array       $options    Option values.
     * @param bool|null   $active     Forced Polylang state.
     * @param string[]|null $api_slugs Language order from the API.
     * @return WMM_Tolka_Strings_Probe
     */
    private function probe($db = null, array $options = array(), $active = null, $api_slugs = null)
    {
        $probe = new WMM_Tolka_Strings_Probe();
        $probe->db = $db;
        $probe->options = $options;
        $probe->active_override = $active;
        $probe->api_slugs = $api_slugs;

        return $probe;
    }

    /**
     * Every historical catalogue for French, plus a WPML table.
     *
     * @return WMM_Tolka_Strings_Db
     */
    private function mergedDatabase()
    {
        $db = new WMM_Tolka_Strings_Db();
        $db->language_rows = array(
            (object) array('term_id' => '10', 'slug' => 'en'),
            (object) array('term_id' => '11', 'slug' => 'fr'),
        );
        $db->option_rows = array(
            array(
                'option_name' => 'polylang_wpml_strings',
                'option_value' => serialize(array(
                    'abc' => array(
                        'context' => 'theme',
                        'name' => 'footer',
                        'string' => 'Call us',
                        'multiline' => true,
                        'icl' => true,
                    ),
                )),
            ),
            array(
                'option_name' => 'polylang_mo_11',
                'option_value' => serialize(array(
                    array('Hello', 'From option'),
                    array('Option only', 'Option'),
                )),
            ),
        );
        $db->post_rows = array(
            array(
                'ID' => 5,
                'post_title' => 'polylang_mo_11',
                'post_content' => serialize(array(
                    array('Hello', 'Salut'),
                    array('Only legacy', 'Ancien'),
                )),
            ),
        );
        $db->post_meta_rows = array(
            array(
                'post_id' => 5,
                'meta_value' => serialize(array(
                    array('Hello', 'From meta'),
                    array('Meta only', 'Meta'),
                )),
            ),
        );
        $db->term_meta_rows = array(
            array(
                'term_id' => 11,
                'meta_value' => serialize(array(
                    array('Hello', 'Bonjour'),
                    array('Hours', 'Heures'),
                    array('', ''),
                )),
            ),
        );
        $db->has_icl_strings = true;
        $db->has_icl_translations = true;
        $db->icl_string_rows = array(
            array(
                'id' => 1,
                'context' => 'theme',
                'name' => 'footer',
                'value' => 'Call us',
                'language' => 'en',
            ),
            array(
                'id' => 2,
                'context' => 'plugin',
                'name' => 'banner',
                'value' => 'Welcome',
                'language' => 'en',
            ),
        );
        $db->icl_translation_rows = array(
            array('string_id' => 1, 'language' => 'fr', 'value' => 'Appelez-nous'),
            array('string_id' => 2, 'language' => 'fr', 'value' => 'Bienvenue'),
            array('string_id' => 2, 'language' => 'de', 'value' => ''),
        );

        return $db;
    }

    /**
     * @param WMM_Tolka_Strings_Db $db Database double.
     * @return void
     */
    private function assertDoesNotWrite(WMM_Tolka_Strings_Db $db)
    {
        $this->assertNotEmpty($db->queries);
        foreach ($db->queries as $query) {
            $this->assertSame(
                0,
                preg_match('/\b(DELETE|UPDATE|INSERT|REPLACE|DROP|ALTER)\b/i', $query)
            );
        }
    }
}
