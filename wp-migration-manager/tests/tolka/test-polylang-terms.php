<?php

/**
 * Polylang term export tests.
 *
 * The suite does not load WordPress. Storage reads are exercised with a
 * stand-in database, and item shaping is exercised with a snapshot reader.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka\Tests;

use PHPUnit\Framework\TestCase;
use WMM\Tolka\Polylang_Terms;

class Polylang_Terms_Test extends TestCase
{
    /**
     * @var mixed
     */
    private $savedWpdb;

    /**
     * @var bool
     */
    private $hadWpdb = false;

    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__);
        }
        if (!class_exists(Polylang_Terms::class)) {
            $file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-terms.php';
            if (!is_readable($file)) {
                return;
            }
            require_once $file;
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(Polylang_Terms::class)) {
            $this->markTestSkipped('Polylang_Terms is not loaded.');
        }
        $this->hadWpdb = array_key_exists('wpdb', $GLOBALS);
        $this->savedWpdb = $this->hadWpdb ? $GLOBALS['wpdb'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadWpdb) {
            $GLOBALS['wpdb'] = $this->savedWpdb;
            return;
        }
        unset($GLOBALS['wpdb']);
    }

    public function test_inactive_site_returns_an_empty_list_and_a_finished_sentence(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'inactive',
            'default_lang' => '',
            'groups' => array(),
            'untranslated' => array(),
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active. Term translations were not exported.'),
            $result['errors']
        );
        $this->assertSame(array('items', 'errors'), array_keys($result));
    }

    public function test_export_without_wordpress_does_not_fatal(): void
    {
        if ($this->wordpressIsLoaded()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        unset($GLOBALS['wpdb']);
        $result = (new Polylang_Terms())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active. Term translations were not exported.'),
            $result['errors']
        );
    }

    public function test_failed_read_leaves_polylang_data_unchanged_in_the_message(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'failed',
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Term translations could not be read. Polylang\'s translation data is missing or the database query failed. Existing terms were left unchanged.'),
            $result['errors']
        );
    }

    public function test_a_throwing_reader_is_reported_instead_of_escaping(): void
    {
        $exporter = new Polylang_Terms(function () {
            throw new \RuntimeException('database down');
        });

        $result = $exporter->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Term translations could not be read. Polylang\'s translation data is missing or the database query failed. Existing terms were left unchanged.'),
            $result['errors']
        );
    }

    public function test_active_site_with_no_terms_has_an_empty_state_and_no_error(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(),
            'untranslated' => array(),
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_translation_group_uses_the_default_language_term_id(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'sv',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array('id' => '10', 'name' => 'News', 'slug' => 'news', 'description' => 'Latest'),
                        'sv' => array('id' => 4, 'name' => 'Nyheter', 'slug' => 'nyheter'),
                    ),
                ),
            ),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array(
            array(
                'taxonomy' => 'category',
                'group' => 'term:4',
                'translations' => array(
                    'sv' => array('id' => 4, 'name' => 'Nyheter', 'slug' => 'nyheter', 'description' => '', 'parent' => 0),
                    'en' => array('id' => 10, 'name' => 'News', 'slug' => 'news', 'description' => 'Latest', 'parent' => 0),
                ),
            ),
        ), $result['items']);
        $this->assertSame(array('taxonomy', 'group', 'translations'), array_keys($result['items'][0]));
        $this->assertSame(array('id', 'name', 'slug', 'description', 'parent'), array_keys($result['items'][0]['translations']['sv']));
    }

    public function test_group_without_the_default_language_uses_the_lowest_term_id(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'de' => 30,
                        'fr' => array('id' => 12, 'name' => 'Actualités', 'slug' => 'actualites', 'parent' => 9),
                    ),
                ),
            ),
        ));

        $this->assertSame('term:12', $result['items'][0]['group']);
        $this->assertSame(
            array('id' => 30, 'name' => '', 'slug' => '', 'description' => '', 'parent' => 0),
            $result['items'][0]['translations']['de']
        );
        $this->assertSame(9, $result['items'][0]['translations']['fr']['parent']);
        $this->assertSame(array('de', 'fr'), array_keys($result['items'][0]['translations']));
        $this->assertContains(
            'Translation group term:12 does not include the default language (en). The group is named after term 12.',
            $result['errors']
        );
    }

    public function test_a_single_language_group_is_kept(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'post_tag',
                    'translations' => array(
                        'en' => array('id' => 8, 'name' => 'Solo', 'slug' => 'solo'),
                    ),
                ),
            ),
        ));

        $this->assertSame('term:8', $result['items'][0]['group']);
        $this->assertSame(array('en'), array_keys($result['items'][0]['translations']));
        $this->assertSame(array(), $result['errors']);
    }

    public function test_untranslated_terms_are_included_and_not_duplicated(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array('id' => 10, 'name' => 'News', 'slug' => 'news'),
                        'de' => array('id' => 11, 'name' => 'Nachrichten', 'slug' => 'nachrichten'),
                    ),
                ),
            ),
            'untranslated' => array(
                array(
                    'taxonomy' => 'category',
                    'lang' => 'en',
                    'id' => 10,
                    'name' => 'News',
                    'slug' => 'news',
                ),
                array(
                    'taxonomy' => 'post_tag',
                    'lang' => 'sv',
                    'id' => 40,
                    'name' => "Children's books",
                    'slug' => 'childrens-books',
                ),
            ),
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('term:10', 'term:40'), $this->groupIds($result['items']));
        $this->assertSame("Children's books", $result['items'][1]['translations']['sv']['name']);
        $this->assertSame('post_tag', $result['items'][1]['taxonomy']);
    }

    public function test_terms_with_no_language_are_kept_under_the_default_language(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'untranslated' => array(
                array(
                    'taxonomy' => 'category',
                    'lang' => '',
                    'id' => 50,
                    'name' => 'Shared',
                    'slug' => 'shared',
                ),
                array(
                    'taxonomy' => 'category',
                    'lang' => '   ',
                    'id' => 51,
                    'name' => 'Also shared',
                    'slug' => 'also-shared',
                ),
            ),
        ));

        $this->assertSame('term:50', $result['items'][0]['group']);
        $this->assertSame(array('en'), array_keys($result['items'][0]['translations']));
        $this->assertSame(51, $result['items'][1]['translations']['en']['id']);
        $this->assertSame(
            array('2 terms have no Polylang language. They are included under the default language (en) so they are not dropped.'),
            $result['errors']
        );
    }

    public function test_terms_with_no_language_and_no_default_keep_a_blank_language(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => '',
            'untranslated' => array(
                array(
                    'taxonomy' => 'category',
                    'lang' => '',
                    'id' => 50,
                    'name' => 'Shared',
                    'slug' => 'shared',
                ),
            ),
        ));

        $this->assertSame(array(''), array_keys($result['items'][0]['translations']));
        $this->assertSame(50, $result['items'][0]['translations']['']['id']);
        $this->assertSame(
            array('A term has no Polylang language. It is included with a blank language so it is not dropped.'),
            $result['errors']
        );
    }

    public function test_a_term_listed_twice_is_skipped_with_a_sentence(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array('id' => 10, 'name' => 'News', 'slug' => 'news'),
                        'de' => array('id' => 11, 'name' => 'Nachrichten', 'slug' => 'nachrichten'),
                    ),
                ),
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'fr' => array('id' => 11, 'name' => 'Nouvelles', 'slug' => 'nouvelles'),
                        'es' => array('id' => 12, 'name' => 'Noticias', 'slug' => 'noticias'),
                    ),
                ),
            ),
        ));

        $this->assertSame(array('term:10', 'term:12'), $this->groupIds($result['items']));
        $this->assertArrayNotHasKey('fr', $result['items'][1]['translations']);
        $this->assertSame(
            array('Term 11 belongs to more than one translation group. The extra copy was skipped.'),
            $result['errors']
        );
    }

    public function test_items_are_sorted_by_taxonomy_then_term_id(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'untranslated' => array(
                array('taxonomy' => 'post_tag', 'lang' => 'en', 'id' => 3, 'name' => 'Tag', 'slug' => 'tag'),
                array('taxonomy' => 'category', 'lang' => 'en', 'id' => 30, 'name' => 'Later', 'slug' => 'later'),
                array('taxonomy' => 'category', 'lang' => 'en', 'id' => 2, 'name' => 'Earlier', 'slug' => 'earlier'),
            ),
        ));

        $this->assertSame(array('term:2', 'term:30', 'term:3'), $this->groupIds($result['items']));
        $this->assertSame(array('category', 'category', 'post_tag'), array(
            $result['items'][0]['taxonomy'],
            $result['items'][1]['taxonomy'],
            $result['items'][2]['taxonomy'],
        ));
    }

    public function test_menus_and_polylang_bookkeeping_are_not_exported_as_terms(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'nav_menu',
                    'translations' => array(
                        'en' => array('id' => 70, 'name' => 'Main', 'slug' => 'main'),
                    ),
                ),
            ),
            'untranslated' => array(
                array('taxonomy' => 'term_translations', 'lang' => 'en', 'id' => 71, 'name' => 'Group', 'slug' => 'group'),
                array('taxonomy' => 'language', 'lang' => 'en', 'id' => 72, 'name' => 'English', 'slug' => 'en'),
            ),
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_names_with_apostrophes_are_preserved(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array('id' => 9, 'name' => "Children's books", 'slug' => 'childrens-books'),
                    ),
                ),
            ),
        ));

        $this->assertSame("Children's books", $result['items'][0]['translations']['en']['name']);
    }

    public function test_encoded_names_and_descriptions_are_shown_as_text(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('en' => 10)));
        $database->catalog = array(
            $this->term(10, 'Fish &amp; Chips', 'fish-chips', 'category', 'en', 0, 'Tom &amp; Jerry'),
            $this->term(20, 'Salt &amp; pepper', 'salt-pepper', 'post_tag', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $group = $this->itemByGroup($result['items'], 'term:10');
        $loose = $this->itemByGroup($result['items'], 'term:20');

        $this->assertSame('Fish &amp; Chips', $group['translations']['en']['name']);
        $this->assertSame('Tom &amp; Jerry', $group['translations']['en']['description']);
        $this->assertSame('fish-chips', $group['translations']['en']['slug']);
        $this->assertSame('Salt &amp; pepper', $loose['translations']['en']['name']);
        $this->assertSame('salt-pepper', $loose['translations']['en']['slug']);
    }

    public function test_html_names_survive_a_second_export_without_duplicate_groups(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(
            serialize(array('en' => 9, 'sv' => 10)),
            serialize(array('en' => 11)),
        );
        $database->catalog = array(
            $this->term(9, "<em>News</em>\0 &amp; Notes", "news\0-notes", 'category', 'en'),
            $this->term(10, 'Nyheter', 'nyheter', 'category', 'sv'),
            $this->term(11, 'Solo', 'solo', 'post_tag', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $news = $this->itemByGroup($second['items'], 'term:9');

        $this->assertSame('<em>News</em> &amp; Notes', $news['translations']['en']['name']);
        $this->assertSame('news-notes', $news['translations']['en']['slug']);
        $this->assertStringNotContainsString("\0", $news['translations']['en']['name']);
        $this->assertStringNotContainsString("\0", $news['translations']['en']['slug']);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertSame(
            $this->groupIds($second['items']),
            array_values(array_unique($this->groupIds($second['items'])))
        );
        $this->assertCount(2, $second['items']);
    }

    public function test_swedish_letters_are_kept_and_an_empty_name_is_exported(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(
            serialize(array('sv' => 21, 'en' => 22)),
        );
        $database->catalog = array(
            $this->term(21, "Höstmöte å\0äö", "höstmöte-å\0äö", 'category', 'sv', 0, 'Höstens första möte'),
            $this->term(22, '', 'unnamed-en', 'category', 'en'),
            $this->term(23, '', '', 'post_tag', 'sv'),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $meeting = $this->itemByGroup($second['items'], 'term:21');
        $blank = $this->itemByGroup($second['items'], 'term:23');

        $this->assertSame('Höstmöte åäö', $meeting['translations']['sv']['name']);
        $this->assertSame('höstmöte-åäö', $meeting['translations']['sv']['slug']);
        $this->assertStringNotContainsString("\0", $meeting['translations']['sv']['name']);
        $this->assertStringNotContainsString("\0", $meeting['translations']['sv']['slug']);
        $this->assertSame('Höstens första möte', $meeting['translations']['sv']['description']);
        $this->assertSame('', $meeting['translations']['en']['name']);
        $this->assertSame('unnamed-en', $meeting['translations']['en']['slug']);
        $this->assertArrayHasKey('en', $meeting['translations']);
        $this->assertSame('', $blank['translations']['sv']['name']);
        $this->assertSame('', $blank['translations']['sv']['slug']);
        $this->assertSame('post_tag', $blank['taxonomy']);
        $this->assertContains('term:21', $this->groupIds($second['items']));
        $this->assertContains('term:23', $this->groupIds($second['items']));
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertCount(2, $second['items']);
    }

    public function test_a_single_swedish_letter_and_an_empty_name_with_a_parent_are_exported(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(40, 'å', 'ä', 'category', 'sv'),
            $this->term(41, 'ö', 'ö', 'category', 'sv', 40),
            $this->term(42, '', 'tomt', 'category', 'sv', 40),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $result = $exporter->export();

        $letter = $this->itemByGroup($result['items'], 'term:40');
        $this->assertSame('å', $letter['translations']['sv']['name']);
        $this->assertSame('ä', $letter['translations']['sv']['slug']);

        $oe = $this->itemByGroup($result['items'], 'term:41');
        $this->assertSame('ö', $oe['translations']['sv']['name']);
        $this->assertSame('ö', $oe['translations']['sv']['slug']);
        $this->assertSame(40, $oe['translations']['sv']['parent']);

        $empty = $this->itemByGroup($result['items'], 'term:42');
        $this->assertSame('', $empty['translations']['sv']['name']);
        $this->assertSame('tomt', $empty['translations']['sv']['slug']);
        $this->assertSame(40, $empty['translations']['sv']['parent']);
        $this->assertCount(3, $result['items']);
    }

    public function test_uppercase_swedish_letters_are_not_folded(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(50, 'ÅÄÖ', 'Å-ä-ö', 'category', 'sv'),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $item = $this->itemByGroup($second['items'], 'term:50');

        $this->assertSame('ÅÄÖ', $item['translations']['sv']['name']);
        $this->assertSame('å-ä-ö', $item['translations']['sv']['slug']);
        $this->assertNotSame('åäö', $item['translations']['sv']['name']);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertCount(1, $second['items']);
    }

    public function test_slug_case_keeps_an_integer_parent_and_an_empty_description(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $html = '<p>Meny åäö</p> &amp; <em>News</em>';
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('SV' => 60, 'FR' => 70)));
        $database->catalog = array(
            array(
                'id' => 60,
                'name' => 'Höst',
                'slug' => 'HÖST-ÅÄÖ',
                'taxonomy' => 'category',
                'lang' => 'SV',
                'parent' => '  8  ',
            ),
            array(
                'id' => 61,
                'name' => '',
                'slug' => '  TOMT  ',
                'taxonomy' => 'category',
                'lang' => 'sv',
                'parent' => null,
                'description' => null,
            ),
            array(
                'id' => 62,
                'name' => 'Falskt',
                'slug' => 'FALSKT',
                'taxonomy' => 'category',
                'lang' => 'sv',
                'parent' => false,
                'description' => false,
            ),
            array(
                'id' => 63,
                'name' => 'Lista',
                'slug' => 'LISTA',
                'taxonomy' => 'post_tag',
                'lang' => 'sv',
                'parent' => array(1),
                'description' => array('html' => '<p>å</p>'),
            ),
            $this->term(70, 'Main', 'MAIN', 'nav_menu', 'FR', 0, $html),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $host = $this->itemByGroup($second['items'], 'term:60');
        $empty = $this->itemByGroup($second['items'], 'term:61');
        $encoded = json_encode($second['items']);

        $this->assertSame('Höst', $host['translations']['sv']['name']);
        $this->assertSame('höst-åäö', $host['translations']['sv']['slug']);
        $this->assertSame('', $host['translations']['sv']['description']);
        $this->assertIsString($host['translations']['sv']['description']);
        $this->assertNotNull($host['translations']['sv']['description']);
        $this->assertSame(8, $host['translations']['sv']['parent']);
        $this->assertIsInt($host['translations']['sv']['parent']);

        $this->assertSame('', $empty['translations']['sv']['name']);
        $this->assertSame('  tomt  ', $empty['translations']['sv']['slug']);
        $this->assertSame('', $empty['translations']['sv']['description']);
        $this->assertIsString($empty['translations']['sv']['description']);
        $this->assertSame(0, $empty['translations']['sv']['parent']);
        $this->assertIsInt($empty['translations']['sv']['parent']);

        $falseDescription = $this->itemByGroup($second['items'], 'term:62');
        $this->assertSame('', $falseDescription['translations']['sv']['description']);
        $this->assertSame('falskt', $falseDescription['translations']['sv']['slug']);
        $this->assertSame(0, $falseDescription['translations']['sv']['parent']);
        $this->assertIsInt($falseDescription['translations']['sv']['parent']);

        $arrayDescription = $this->itemByGroup($second['items'], 'term:63');
        $this->assertSame('', $arrayDescription['translations']['sv']['description']);
        $this->assertSame('lista', $arrayDescription['translations']['sv']['slug']);
        $this->assertSame(0, $arrayDescription['translations']['sv']['parent']);
        $this->assertIsInt($arrayDescription['translations']['sv']['parent']);

        $this->assertNotContains('term:70', $this->groupIds($second['items']));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertSame($first, $second);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertSame(
            $this->groupIds($second['items']),
            array_values(array_unique($this->groupIds($second['items'])))
        );
        $this->assertCount(4, $second['items']);
    }

    public function test_a_second_export_matches_after_slug_case_is_normalized(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $html = '<p>Meny åäö</p> &amp; <em>News</em>';
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(80, 'Höst', 'HÖST', 'category', 'sv', '3', $html),
            $this->term(81, 'Äpple', 'höst', 'category', 'sv', 0),
            $this->term(70, 'Main', 'MAIN', 'nav_menu', 'sv', 0, $html),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $third = $exporter->export();
        $encoded = json_encode($second['items']);

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
        $this->assertSame(array('term:80', 'term:81'), $this->groupIds($second['items']));
        $this->assertSame(
            $this->groupIds($second['items']),
            array_values(array_unique($this->groupIds($second['items'])))
        );
        $this->assertSame('höst', $this->itemByGroup($second['items'], 'term:80')['translations']['sv']['slug']);
        $this->assertSame('höst', $this->itemByGroup($second['items'], 'term:81')['translations']['sv']['slug']);
        $this->assertSame('Höst', $this->itemByGroup($second['items'], 'term:80')['translations']['sv']['name']);
        $this->assertSame($html, $this->itemByGroup($second['items'], 'term:80')['translations']['sv']['description']);
        $this->assertSame(3, $this->itemByGroup($second['items'], 'term:80')['translations']['sv']['parent']);
        $this->assertIsInt($this->itemByGroup($second['items'], 'term:80')['translations']['sv']['parent']);
        $this->assertSame(0, $this->itemByGroup($second['items'], 'term:81')['translations']['sv']['parent']);
        $this->assertSame('', $this->itemByGroup($second['items'], 'term:81')['translations']['sv']['description']);
        $this->assertIsString($this->itemByGroup($second['items'], 'term:81')['translations']['sv']['description']);
        $this->assertNotContains('term:70', $this->groupIds($second['items']));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertCount(2, $second['items']);
    }

    public function test_a_shared_term_id_stays_a_category_on_every_export(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $html = '<p>Meny åäö</p> &amp; <em>News</em>';
        $category = $this->term(12, 'Höst', 'HÖST', 'category', 'sv', '4', $html);
        $tag = $this->term(12, 'Tagg', 'TAGG-HÖST', 'post_tag', 'sv', 9, 'tagg');
        $menu = $this->term(12, 'Main', 'MAIN', 'nav_menu', 'sv', 0, $html);
        $english = $this->term(13, 'Autumn', 'AUTUMN', 'category', 'en', 0, $html);

        $menuFirst = $this->sharedTermExport(array($menu, $tag, $category, $english));
        $categoryFirst = $this->sharedTermExport(array($category, $english, $menu, $tag));

        $this->assertSame($menuFirst, $categoryFirst);
        $this->assertSame(array('term:12'), $this->groupIds($menuFirst['items']));

        $item = $this->itemByGroup($menuFirst['items'], 'term:12');
        $this->assertSame('category', $item['taxonomy']);
        $this->assertSame(array('en', 'sv'), array_keys($item['translations']));
        $this->assertSame('höst', $item['translations']['sv']['slug']);
        $this->assertSame('Höst', $item['translations']['sv']['name']);
        $this->assertSame($html, $item['translations']['sv']['description']);
        $this->assertSame(4, $item['translations']['sv']['parent']);
        $this->assertIsInt($item['translations']['sv']['parent']);
        $this->assertSame('autumn', $item['translations']['en']['slug']);
        $this->assertSame('Autumn', $item['translations']['en']['name']);
        $this->assertSame(0, $item['translations']['en']['parent']);
        $this->assertIsInt($item['translations']['en']['parent']);

        $encoded = json_encode($menuFirst['items']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertStringNotContainsString('post_tag', $encoded);
        $this->assertStringNotContainsString('Tagg', $encoded);
        $this->assertStringNotContainsString('MAIN', $encoded);
        $this->assertCount(1, $menuFirst['items']);
        $this->assertSame(array(), $menuFirst['errors']);
    }

    public function test_an_uppercase_stored_language_stays_lowercase_on_a_second_export(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->languages = array(
            array('slug' => 'PLL_SV', 'term_group' => 0),
        );
        $database->catalog = array(
            $this->term(9, 'Höst', 'HÖST', 'category', '', '3'),
            $this->term(70, 'Main', 'MAIN', 'nav_menu', 'SV', 0, '<p>Meny</p>'),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $encoded = json_encode($second['items']);

        $this->assertSame($first, $second);
        $this->assertSame(array('term:9'), $this->groupIds($second['items']));
        $item = $this->itemByGroup($second['items'], 'term:9');
        $this->assertSame(array('sv'), array_keys($item['translations']));
        $this->assertSame('höst', $item['translations']['sv']['slug']);
        $this->assertSame('Höst', $item['translations']['sv']['name']);
        $this->assertSame(3, $item['translations']['sv']['parent']);
        $this->assertIsInt($item['translations']['sv']['parent']);
        $this->assertSame('', $item['translations']['sv']['description']);
        $this->assertIsString($item['translations']['sv']['description']);
        $this->assertNotContains('term:70', $this->groupIds($second['items']));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertStringNotContainsString('PLL_SV', $encoded);
        $this->assertSame(
            array('A term has no Polylang language. It is included under sv, the first language stored by Polylang, so it is not dropped.'),
            $second['errors']
        );
    }

    public function test_a_whitespace_slug_becomes_empty_and_the_parent_stays_an_integer(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(60, '  åäö  ', " \t\n ", 'category', 'sv', '  6  '),
            $this->term(61, '', " \xC2\xA0 ", 'category', 'sv', 0),
            $this->term(62, 'Räkor', '  räkor-åäö  ', 'category', 'sv', 0),
            $this->term(63, 'Null slug', "\0 \t \0", 'post_tag', 'sv', null),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();

        $spaced = $this->itemByGroup($second['items'], 'term:60');
        $this->assertSame('  åäö  ', $spaced['translations']['sv']['name']);
        $this->assertSame('', $spaced['translations']['sv']['slug']);
        $this->assertSame(6, $spaced['translations']['sv']['parent']);
        $this->assertIsInt($spaced['translations']['sv']['parent']);

        $blank = $this->itemByGroup($second['items'], 'term:61');
        $this->assertSame('', $blank['translations']['sv']['name']);
        $this->assertSame('', $blank['translations']['sv']['slug']);
        $this->assertSame(0, $blank['translations']['sv']['parent']);
        $this->assertIsInt($blank['translations']['sv']['parent']);

        $kept = $this->itemByGroup($second['items'], 'term:62');
        $this->assertSame('Räkor', $kept['translations']['sv']['name']);
        $this->assertSame('  räkor-åäö  ', $kept['translations']['sv']['slug']);
        $this->assertSame(0, $kept['translations']['sv']['parent']);

        $nullSlug = $this->itemByGroup($second['items'], 'term:63');
        $this->assertSame('', $nullSlug['translations']['sv']['slug']);
        $this->assertSame('Null slug', $nullSlug['translations']['sv']['name']);
        $this->assertSame(0, $nullSlug['translations']['sv']['parent']);

        $this->assertCount(4, $second['items']);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertSame(
            $this->groupIds($second['items']),
            array_values(array_unique($this->groupIds($second['items'])))
        );
    }

    public function test_a_blank_slug_inside_a_group_does_not_drop_the_term(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('en' => 70, 'sv' => 71)));
        $database->catalog = array(
            $this->term(70, '', "\f\v", 'category', 'en', 0),
            $this->term(71, 'Höst', 'höst', 'category', 'sv', '0'),
            $this->term(72, 'Noll', '0', 'post_tag', 'sv', 0),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $group = $this->itemByGroup($second['items'], 'term:70');

        $this->assertSame('', $group['translations']['en']['name']);
        $this->assertSame('', $group['translations']['en']['slug']);
        $this->assertSame(0, $group['translations']['en']['parent']);
        $this->assertIsInt($group['translations']['en']['parent']);
        $this->assertSame('Höst', $group['translations']['sv']['name']);
        $this->assertSame('höst', $group['translations']['sv']['slug']);
        $this->assertSame(0, $group['translations']['sv']['parent']);
        $this->assertIsInt($group['translations']['sv']['parent']);

        $zero = $this->itemByGroup($second['items'], 'term:72');
        $this->assertSame('0', $zero['translations']['sv']['slug']);
        $this->assertSame(0, $zero['translations']['sv']['parent']);
        $this->assertCount(2, $second['items']);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
    }

    public function test_html_in_a_description_is_kept_and_null_bytes_are_removed(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('sv' => 80, 'en' => 81)));
        $database->catalog = array(
            $this->term(80, 'Höst', 'höst', 'category', 'sv', 4, "<p>Höst\0ens möte</p>\0 &amp; <em>åäö</em>"),
            $this->term(81, '', "   ", 'category', 'en', 0, "\0"),
            $this->term(82, 'Mellanrum', 'mellanrum', 'post_tag', 'sv', 0, '   '),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $item = $this->itemByGroup($second['items'], 'term:80');

        $this->assertSame('<p>Höstens möte</p> &amp; <em>åäö</em>', $item['translations']['sv']['description']);
        $this->assertStringNotContainsString("\0", $item['translations']['sv']['description']);
        $this->assertNotSame('<p>Höstens möte</p> & <em>åäö</em>', $item['translations']['sv']['description']);
        $this->assertSame('Höst', $item['translations']['sv']['name']);
        $this->assertSame('höst', $item['translations']['sv']['slug']);
        $this->assertSame(4, $item['translations']['sv']['parent']);
        $this->assertIsInt($item['translations']['sv']['parent']);
        $this->assertSame('', $item['translations']['en']['name']);
        $this->assertSame('', $item['translations']['en']['slug']);
        $this->assertSame('', $item['translations']['en']['description']);
        $this->assertSame(0, $item['translations']['en']['parent']);
        $spaces = $this->itemByGroup($second['items'], 'term:82');
        $this->assertSame('   ', $spaces['translations']['sv']['description']);
        $this->assertCount(2, $second['items']);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertSame(
            $this->groupIds($second['items']),
            array_values(array_unique($this->groupIds($second['items'])))
        );
    }

    public function test_a_long_description_is_not_truncated(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $body = str_repeat('<p>Höst åäö</p>', 400);
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(90, 'Lång', 'lång', 'category', 'sv', 0, $body . "\0"),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $result = $exporter->export();
        $item = $this->itemByGroup($result['items'], 'term:90');

        $this->assertSame($body, $item['translations']['sv']['description']);
        $this->assertSame(strlen($body), strlen($item['translations']['sv']['description']));
        $this->assertStringNotContainsString("\0", $item['translations']['sv']['description']);
        $this->assertSame('Lång', $item['translations']['sv']['name']);
        $this->assertSame('lång', $item['translations']['sv']['slug']);
        $this->assertSame(0, $item['translations']['sv']['parent']);
        $this->assertCount(1, $result['items']);
    }

    public function test_null_bytes_are_removed_from_term_text(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array(
                            'id' => 9,
                            'name' => "<em>News</em>\0 &amp; Notes",
                            'slug' => "news\0-notes",
                            'description' => "Hello\0 <em>there</em>",
                        ),
                    ),
                ),
            ),
        ));

        $this->assertSame('<em>News</em> &amp; Notes', $result['items'][0]['translations']['en']['name']);
        $this->assertNotSame('<em>News</em> & Notes', $result['items'][0]['translations']['en']['name']);
        $this->assertSame('news-notes', $result['items'][0]['translations']['en']['slug']);
        $this->assertStringNotContainsString("\0", $result['items'][0]['translations']['en']['name']);
        $this->assertStringNotContainsString("\0", $result['items'][0]['translations']['en']['slug']);
        $this->assertSame('Hello <em>there</em>', $result['items'][0]['translations']['en']['description']);
    }

    public function test_storage_reads_groups_untranslated_terms_and_skips_bad_rows(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->descriptions = array(
            serialize(array('en' => 10, 'de' => 11)),
            '{"fr":12,"en":13}',
            serialize(serialize(array('sv' => 21))),
            serialize(array('en' => 30, 'de' => 31)),
            serialize(array('en' => 99999)),
            'not-a-map',
            serialize(array('en' => 70)),
        );
        $database->catalog = array(
            $this->term(10, 'News', 'news', 'category', 'en', 0, 'Latest news'),
            $this->term(11, 'Nachrichten', 'nachrichten', 'category', 'de', 6),
            $this->term(15, 'Child', 'child', 'category', 'en', 10),
            $this->term(12, 'Actualités', 'actualites', 'product_cat', 'fr'),
            $this->term(13, 'Reports', 'reports', 'product_cat', 'en'),
            $this->term(21, 'Ensam', 'ensam', 'post_tag', 'sv'),
            $this->term(30, 'Alpha', 'alpha', 'category', 'en'),
            $this->term(31, 'Beta', 'beta', 'post_tag', 'de'),
            $this->term(40, "Children's books", 'childrens-books', 'post_tag', 'sv'),
            $this->term(50, 'Shared', 'shared', 'category', ''),
            $this->term(70, 'Main', 'main', 'nav_menu', 'en'),
            $this->term(80, 'Loose', 'loose', 'product_cat', 'fr'),
        );
        for ($id = 2000; $id <= 2500; $id++) {
            $database->catalog[] = $this->term($id, 'Tag ' . $id, 'tag-' . $id, 'post_tag', 'en');
        }

        $GLOBALS['wpdb'] = $database;
        $result = (new Polylang_Terms())->export();

        $this->assertNotContains('term:70', $this->groupIds($result['items']));
        $this->assertNotContains('term:99999', $this->groupIds($result['items']));
        $this->assertNotContains('term:31', $this->groupIds($result['items']));

        $news = $this->itemByGroup($result['items'], 'term:10');
        $this->assertSame('category', $news['taxonomy']);
        $this->assertSame(array(
            'de' => array('id' => 11, 'name' => 'Nachrichten', 'slug' => 'nachrichten', 'description' => '', 'parent' => 6),
            'en' => array('id' => 10, 'name' => 'News', 'slug' => 'news', 'description' => 'Latest news', 'parent' => 0),
        ), $news['translations']);

        $product = $this->itemByGroup($result['items'], 'term:12');
        $this->assertSame('product_cat', $product['taxonomy']);
        $this->assertSame(array('en', 'fr'), array_keys($product['translations']));
        $this->assertSame('Actualités', $product['translations']['fr']['name']);

        $single = $this->itemByGroup($result['items'], 'term:21');
        $this->assertSame(array('sv' => array('id' => 21, 'name' => 'Ensam', 'slug' => 'ensam', 'description' => '', 'parent' => 0)), $single['translations']);

        $alpha = $this->itemByGroup($result['items'], 'term:30');
        $this->assertSame(array('en'), array_keys($alpha['translations']));

        $loose = $this->itemByGroup($result['items'], 'term:80');
        $this->assertSame('product_cat', $loose['taxonomy']);
        $this->assertSame('fr', array_key_first($loose['translations']));

        $shared = $this->itemByGroup($result['items'], 'term:50');
        $this->assertSame(array(''), array_keys($shared['translations']));
        $this->assertSame('Shared', $shared['translations']['']['name']);

        $childrens = $this->itemByGroup($result['items'], 'term:40');
        $this->assertSame("Children's books", $childrens['translations']['sv']['name']);

        $firstPage = $this->itemByGroup($result['items'], 'term:2000');
        $secondPage = $this->itemByGroup($result['items'], 'term:2500');
        $this->assertSame('Tag 2000', $firstPage['translations']['en']['name']);
        $this->assertSame('tag-2500', $secondPage['translations']['en']['slug']);

        $postTags = array_values(array_filter($result['items'], function ($item) {
            return $item['taxonomy'] === 'post_tag';
        }));
        $child = $this->itemByGroup($result['items'], 'term:15');
        $this->assertSame(10, $child['translations']['en']['parent']);
        $this->assertSame('Child', $child['translations']['en']['name']);

        $this->assertCount(503, $postTags);
        $this->assertCount(509, $result['items']);

        $this->assertContains(
            'A term translation group\'s stored description was invalid and was ignored. Terms linked to that group are still included.',
            $result['errors']
        );
        $this->assertContains(
            'Term 99999 was listed in a translation group, but that term no longer exists. It was left out of the export.',
            $result['errors']
        );
        $this->assertContains(
            'Term 31 belongs to post_tag, so it was left out of the category translation group.',
            $result['errors']
        );
        $this->assertContains(
            'A term has no Polylang language. It is included with a blank language so it is not dropped.',
            $result['errors']
        );
        $this->assertNotContains('Polylang is not active. Term translations were not exported.', $result['errors']);
    }

    public function test_export_on_an_instance_keeps_parent_ids(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('en' => 4, 'sv' => 15)));
        $database->catalog = array(
            $this->term(4, 'News', 'news', 'category', 'en', '0'),
            array(
                'id' => 15,
                'name' => 'Local',
                'slug' => 'local',
                'taxonomy' => 'category',
                'lang' => 'sv',
                'parent' => ' 4 ',
                'description' => '',
            ),
            array(
                'id' => 16,
                'name' => 'Orphan',
                'slug' => 'orphan',
                'taxonomy' => 'category',
                'lang' => 'en',
                'description' => '',
            ),
            $this->term(17, 'Tag', 'tag', 'post_tag', 'en', null),
            $this->term(99, 'Missing parent target', 'dangling', 'category', 'en', 99),
            $this->term(18, 'Float parent', 'float-parent', 'category', 'en', 8.0),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $this->assertTrue(is_callable(array($exporter, 'export')));
        $result = $exporter->export();

        $news = $this->itemByGroup($result['items'], 'term:4');
        $this->assertSame(0, $news['translations']['en']['parent']);
        $this->assertSame(4, $news['translations']['sv']['parent']);
        $this->assertIsInt($news['translations']['en']['parent']);
        $this->assertIsInt($news['translations']['sv']['parent']);

        $orphan = $this->itemByGroup($result['items'], 'term:16');
        $this->assertSame(0, $orphan['translations']['en']['parent']);

        $tag = $this->itemByGroup($result['items'], 'term:17');
        $this->assertSame(0, $tag['translations']['en']['parent']);

        $dangling = $this->itemByGroup($result['items'], 'term:99');
        $this->assertSame(99, $dangling['translations']['en']['parent']);

        $floatParent = $this->itemByGroup($result['items'], 'term:18');
        $this->assertSame(8, $floatParent['translations']['en']['parent']);
    }

    public function test_the_same_instance_can_export_twice(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(2, 'Child', 'child', 'category', 'en', 1),
            $this->term(3, 'Root', 'root', 'category', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $method = new \ReflectionMethod(Polylang_Terms::class, 'export');
        $this->assertFalse($method->isStatic());

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $firstGroups = $this->groupIds($first['items']);
        $secondGroups = $this->groupIds($second['items']);

        $this->assertSame($first, $second);
        $this->assertSame($firstGroups, $secondGroups);
        $this->assertSame($firstGroups, array_values(array_unique($secondGroups)));
        $this->assertCount(count($firstGroups), $second['items']);
        $this->assertSame(1, $this->itemByGroup($second['items'], 'term:2')['translations']['en']['parent']);
        $this->assertSame(0, $this->itemByGroup($second['items'], 'term:3')['translations']['en']['parent']);
    }

    public function test_a_missing_parent_on_one_translation_is_zero(): void
    {
        $exporter = new Polylang_Terms(function () {
            return array(
                'state' => 'ok',
                'default_lang' => 'en',
                'groups' => array(
                    array(
                        'taxonomy' => 'category',
                        'translations' => array(
                            'en' => array('id' => 2, 'name' => 'Child', 'slug' => 'child', 'parent' => 1),
                            'sv' => array('id' => 3, 'name' => 'Root', 'slug' => 'root'),
                        ),
                    ),
                ),
            );
        });

        $result = $exporter->export();

        $this->assertSame(1, $result['items'][0]['translations']['en']['parent']);
        $this->assertSame(0, $result['items'][0]['translations']['sv']['parent']);
    }

    public function test_blank_and_fractional_parents_become_zero(): void
    {
        $exporter = new Polylang_Terms(function () {
            return array(
                'state' => 'ok',
                'default_lang' => 'en',
                'groups' => array(
                    array(
                        'taxonomy' => 'category',
                        'translations' => array(
                            'en' => array('id' => 7, 'name' => 'Kept', 'slug' => 'kept', 'parent' => '7'),
                            'de' => array('id' => 8, 'name' => 'Blank', 'slug' => 'blank', 'parent' => ''),
                            'fr' => array('id' => 9, 'name' => 'Flag', 'slug' => 'flag', 'parent' => true),
                            'es' => array('id' => 10, 'name' => 'Fraction', 'slug' => 'fraction', 'parent' => 4.5),
                        ),
                    ),
                ),
            );
        });

        $result = $exporter->export();
        $translations = $result['items'][0]['translations'];

        $this->assertSame(7, $translations['en']['parent']);
        $this->assertSame(0, $translations['de']['parent']);
        $this->assertSame(0, $translations['fr']['parent']);
        $this->assertSame(0, $translations['es']['parent']);
    }

    public function test_a_term_with_no_translation_is_exported_on_its_own(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->languages = array(
            array('slug' => 'en', 'term_group' => 0),
            array('slug' => 'sv', 'term_group' => 1),
        );
        $database->descriptions = array();
        $database->catalog = array(
            $this->term(8, 'Solo', 'solo', 'category', 'en', 0, 'Only in English'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $item = $this->itemByGroup($result['items'], 'term:8');

        $this->assertSame(array('term:8'), $this->groupIds($result['items']));
        $this->assertSame('category', $item['taxonomy']);
        $this->assertSame(array('en'), array_keys($item['translations']));
        $this->assertSame(array(
            'id' => 8,
            'name' => 'Solo',
            'slug' => 'solo',
            'description' => 'Only in English',
            'parent' => 0,
        ), $item['translations']['en']);
        $this->assertArrayNotHasKey('sv', $item['translations']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_polylang_inactive_exports_nothing_even_when_categories_exist(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = false;
        $database->languages = array();
        $database->descriptions = array();
        $database->catalog = array(
            $this->term(1, 'Uncategorized', 'uncategorized', 'category', ''),
            $this->term(2, 'News', 'news', 'category', ''),
            $this->term(70, 'Main', 'main', 'nav_menu', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active. Term translations were not exported.'),
            $result['errors']
        );
        $this->assertStringNotContainsString('Uncategorized', implode(' ', $this->groupIds($result['items'])));
        $encoded = json_encode($result['items']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Uncategorized', $encoded);
        $this->assertStringNotContainsString('News', $encoded);
    }

    public function test_a_nav_menu_term_is_left_out_of_the_term_export(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->languages = array(
            array('slug' => 'en', 'term_group' => 0),
        );
        $database->descriptions = array(
            serialize(array('en' => 8, 'fr' => 70)),
            serialize(array('en' => 71)),
        );
        $database->catalog = array(
            $this->term(8, 'News', 'news', 'category', 'en'),
            $this->term(9, 'Solo', 'solo', 'category', 'en'),
            $this->term(70, 'Main', 'main', 'nav_menu', 'en'),
            $this->term(71, 'Footer', 'footer', 'nav_menu', 'fr'),
            $this->term(72, 'Social', 'social', 'nav_menu', ''),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $groups = $this->groupIds($result['items']);

        $this->assertContains('term:8', $groups);
        $this->assertContains('term:9', $groups);
        $this->assertNotContains('term:70', $groups);
        $this->assertNotContains('term:71', $groups);
        $this->assertNotContains('term:72', $groups);
        foreach ($result['items'] as $item) {
            $this->assertNotSame('nav_menu', $item['taxonomy']);
            $this->assertStringNotContainsString('main', $item['translations'][array_key_first($item['translations'])]['slug']);
        }
        $news = $this->itemByGroup($result['items'], 'term:8');
        $this->assertSame(array('en'), array_keys($news['translations']));
        $this->assertArrayNotHasKey('fr', $news['translations']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_a_nav_menu_with_html_description_stays_out_and_the_category_stays_in(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $html = "<p>Meny åäö</p>\0 &amp; <em>News</em>";
        $kept = '<p>Meny åäö</p> &amp; <em>News</em>';
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(
            serialize(array('en' => 8, 'fr' => 70)),
            serialize(array('sv' => 71)),
        );
        $database->catalog = array(
            $this->term(8, 'Nyheter', 'nyheter', 'category', 'en', '4', $html),
            $this->term(70, 'Main', 'main', 'nav_menu', 'fr', 0, $html),
            $this->term(71, 'Footer', 'footer', 'nav_menu', 'sv', 0, $html),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $category = $this->itemByGroup($second['items'], 'term:8');
        $encoded = json_encode($second['items']);

        $this->assertSame(array('term:8'), $this->groupIds($second['items']));
        $this->assertSame('category', $category['taxonomy']);
        $this->assertSame($kept, $category['translations']['en']['description']);
        $this->assertStringNotContainsString("\0", $category['translations']['en']['description']);
        $this->assertSame('Nyheter', $category['translations']['en']['name']);
        $this->assertSame('nyheter', $category['translations']['en']['slug']);
        $this->assertSame(4, $category['translations']['en']['parent']);
        $this->assertIsInt($category['translations']['en']['parent']);
        $this->assertArrayNotHasKey('fr', $category['translations']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertStringNotContainsString('Footer', $encoded);
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertCount(1, $second['items']);
    }

    public function test_a_single_uppercase_language_slug_is_exported_as_lowercase(): void
    {
        $exporter = new Polylang_Terms(function () {
            return array(
                'state' => 'ok',
                'default_lang' => 'SV',
                'groups' => array(
                    array(
                        'taxonomy' => 'category',
                        'translations' => array(
                            'SV' => array(
                                'id' => 4,
                                'name' => 'Höst',
                                'slug' => 'höst',
                                'description' => '<p>åäö</p>',
                                'parent' => '4',
                            ),
                        ),
                    ),
                    array(
                        'taxonomy' => 'nav_menu',
                        'translations' => array(
                            'SV' => array(
                                'id' => 70,
                                'name' => 'Main',
                                'slug' => 'main',
                                'description' => '<p>åäö</p>',
                            ),
                        ),
                    ),
                ),
            );
        });

        $first = $exporter->export();
        $second = $exporter->export();
        $item = $second['items'][0];

        $this->assertSame(array('sv'), array_keys($item['translations']));
        $this->assertSame('Höst', $item['translations']['sv']['name']);
        $this->assertSame('höst', $item['translations']['sv']['slug']);
        $this->assertSame('<p>åäö</p>', $item['translations']['sv']['description']);
        $this->assertSame(4, $item['translations']['sv']['parent']);
        $this->assertIsInt($item['translations']['sv']['parent']);
        $this->assertSame('term:4', $item['group']);
        $this->assertSame(array('term:4'), $this->groupIds($second['items']));
        $this->assertSame($first, $second);
        $this->assertSame(array(), $second['errors']);
    }

    public function test_the_same_term_under_sv_and_uppercase_sv_is_one_row(): void
    {
        $exporter = new Polylang_Terms(function () {
            return array(
                'state' => 'ok',
                'default_lang' => 'sv',
                'groups' => array(
                    array(
                        'taxonomy' => 'category',
                        'translations' => array(
                            'SV' => array('id' => 4, 'name' => 'Höst', 'slug' => 'höst', 'parent' => 0),
                            'sv' => array('id' => 4, 'name' => 'Höst', 'slug' => 'höst', 'parent' => 0),
                        ),
                    ),
                ),
            );
        });

        $result = $exporter->export();

        $this->assertSame(array('sv'), array_keys($result['items'][0]['translations']));
        $this->assertSame(4, $result['items'][0]['translations']['sv']['id']);
        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $result['items']);
    }

    public function test_uppercase_language_slugs_share_one_lowercase_key_and_menus_stay_out(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $html = '<p>Meny åäö</p> &amp; <em>News</em>';
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->languages = array(
            array('slug' => 'SV', 'term_group' => 0),
        );
        $database->descriptions = array(serialize(array('SV' => 10, 'sv' => 11, 'FR' => 70)));
        $database->catalog = array(
            $this->term(10, 'Extra', 'extra', 'category', 'SV', 0, $html),
            $this->term(11, 'Höst', 'höst', 'category', 'sv', '4', $html),
            $this->term(70, 'Main', 'main', 'nav_menu', 'FR', 0, $html),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $item = $this->itemByGroup($second['items'], 'term:11');
        $encoded = json_encode($second['items']);

        $this->assertSame(array('sv'), array_keys($item['translations']));
        $this->assertArrayNotHasKey('SV', $item['translations']);
        $this->assertArrayNotHasKey('FR', $item['translations']);
        $this->assertArrayNotHasKey('fr', $item['translations']);
        $this->assertSame(11, $item['translations']['sv']['id']);
        $this->assertSame('Höst', $item['translations']['sv']['name']);
        $this->assertSame('höst', $item['translations']['sv']['slug']);
        $this->assertSame($html, $item['translations']['sv']['description']);
        $this->assertSame(4, $item['translations']['sv']['parent']);
        $this->assertIsInt($item['translations']['sv']['parent']);
        $this->assertSame(array('term:11'), $this->groupIds($second['items']));
        $this->assertNotContains('term:10', $this->groupIds($second['items']));
        $this->assertNotContains('term:70', $this->groupIds($second['items']));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('nav_menu', $encoded);
        $this->assertStringNotContainsString('Main', $encoded);
        $this->assertContains(
            'Term 11 and term 10 were both listed for the language sv. They share one key, and term 11 was kept.',
            $second['errors']
        );
        $this->assertSame($this->groupIds($first['items']), $this->groupIds($second['items']));
        $this->assertCount(1, $second['items']);
    }

    public function test_a_category_description_is_not_read_as_a_menu_or_a_group(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $menuMarkup = '<div class="nav_menu">åäö</div>';
        $storedMap = 'a:1:{s:2:"en";i:99;}';
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(8, 'Nyheter', 'nyheter', 'category', 'sv', 0, $storedMap),
            $this->term(9, 'Menytext', 'menytext', 'category', 'sv', 0, $menuMarkup),
            $this->term(70, 'Main', 'main', 'nav_menu', 'sv', 0, $menuMarkup),
            $this->term(71, 'Footer', 'footer', 'nav_menu', 'sv', 0, $storedMap),
        );
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $result = $exporter->export();

        $this->assertSame(array('term:8', 'term:9'), $this->groupIds($result['items']));
        $this->assertSame($storedMap, $this->itemByGroup($result['items'], 'term:8')['translations']['sv']['description']);
        $this->assertSame($menuMarkup, $this->itemByGroup($result['items'], 'term:9')['translations']['sv']['description']);
        $this->assertArrayNotHasKey('en', $this->itemByGroup($result['items'], 'term:8')['translations']);
        $this->assertNotContains('term:99', $this->groupIds($result['items']));
        $this->assertNotContains('term:70', $this->groupIds($result['items']));
        $this->assertNotContains('term:71', $this->groupIds($result['items']));
        foreach ($result['items'] as $item) {
            $this->assertSame('category', $item['taxonomy']);
        }
    }

    public function test_links_rebuild_a_group_when_its_description_is_invalid(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->descriptions = array('not-a-map');
        $database->links = array(1 => array(10, 11));
        $database->catalog = array(
            $this->term(10, 'News', 'news', 'category', 'en'),
            $this->term(11, 'Nyheter', 'nyheter', 'category', 'sv'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $item = $this->itemByGroup($result['items'], 'term:10');

        $this->assertSame(array('en', 'sv'), array_keys($item['translations']));
        $this->assertSame('Nyheter', $item['translations']['sv']['name']);
        $this->assertContains(
            'A term translation group\'s stored description was invalid and was ignored. Terms linked to that group are still included.',
            $result['errors']
        );
        $this->assertContains(
            'Term 10 is linked to a translation group but was missing from its stored description. It was added as en.',
            $result['errors']
        );
    }

    public function test_a_linked_term_missing_from_the_description_is_added(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->descriptions = array(serialize(array('en' => 10)));
        $database->links = array(1 => array(10, 11, 12));
        $database->catalog = array(
            $this->term(10, 'News', 'news', 'category', 'en'),
            $this->term(11, 'Nyheter', 'nyheter', 'category', 'sv'),
            $this->term(12, 'Extra', 'extra', 'category', 'sv'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $item = $this->itemByGroup($result['items'], 'term:10');

        $this->assertSame(array('en', 'sv'), array_keys($item['translations']));
        $this->assertSame(11, $item['translations']['sv']['id']);
        $this->assertSame('Nyheter', $item['translations']['sv']['name']);
        $extra = $this->itemByGroup($result['items'], 'term:12');
        $this->assertSame(array('sv'), array_keys($extra['translations']));
        $this->assertSame('Extra', $extra['translations']['sv']['name']);
        $this->assertContains(
            'Term 11 is linked to a translation group but was missing from its stored description. It was added as sv.',
            $result['errors']
        );
        $this->assertContains(
            'Term 12 is linked to a translation group but was missing from its stored description. It was left out because sv is already used in that group.',
            $result['errors']
        );
    }

    public function test_leftover_groups_are_exported_when_polylang_is_deactivated(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = false;
        $database->descriptions = array(serialize(array('en' => 10, 'sv' => 11)));
        $database->catalog = array(
            $this->term(10, 'News', 'news', 'category', 'en'),
            $this->term(11, 'Nyheter', 'nyheter', 'category', 'sv'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $item = $this->itemByGroup($result['items'], 'term:10');

        $this->assertSame('category', $item['taxonomy']);
        $this->assertSame(10, $item['translations']['en']['id']);
        $this->assertSame('Nyheter', $item['translations']['sv']['name']);
        $this->assertNotContains('Polylang is not active. Term translations were not exported.', $result['errors']);
    }

    public function test_a_serialized_object_in_a_group_is_skipped_without_a_fatal(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(
            serialize((object) array('en' => 10)),
            serialize(array('en' => 12)),
        );
        $database->catalog = array(
            $this->term(12, 'Kept', 'kept', 'category', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $this->assertSame(array('term:12'), $this->groupIds($result['items']));
        $this->assertContains(
            'A term translation group\'s stored description was invalid and was ignored. Terms linked to that group are still included.',
            $result['errors']
        );
    }

    public function test_language_rows_count_as_polylang_when_no_groups_exist_yet(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->catalog = array(
            $this->term(3, 'Uncategorized', 'uncategorized', 'category', 'en'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $this->assertSame('term:3', $result['items'][0]['group']);
        $this->assertSame('uncategorized', $result['items'][0]['translations']['en']['slug']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_stale_group_language_uses_the_language_stored_on_the_term(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->descriptions = array(serialize(array('xx' => 10, 'de' => 11)));
        $database->catalog = array(
            $this->term(10, 'News', 'news', 'category', 'en'),
            $this->term(11, 'Nachrichten', 'nachrichten', 'category', 'de'),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();
        $item = $this->itemByGroup($result['items'], 'term:10');

        $this->assertSame(array('de', 'en'), array_keys($item['translations']));
        $this->assertSame(10, $item['translations']['en']['id']);
        $this->assertSame('News', $item['translations']['en']['name']);
        $this->assertContains(
            'Term 10 is stored as xx in its translation group, but its language is en. It was exported as en.',
            $result['errors']
        );
    }

    public function test_large_translation_groups_are_read_in_batches(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $map = array();
        for ($i = 1; $i <= 501; $i++) {
            $map['l' . $i] = 4000 + $i;
        }
        $database->descriptions = array(serialize($map));
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $batches = 0;
        foreach ($database->queries as $sql) {
            if (strpos($sql, ' IN (') !== false) {
                $batches++;
            }
        }

        $this->assertSame(2, $batches);
        $this->assertSame(array(), $result['items']);
        $this->assertStringContainsString(
            'Term 4001 was listed in a translation group',
            implode("\n", $result['errors'])
        );
        $this->assertContains(
            '481 more term problems were left out of this list so it stays readable.',
            $result['errors']
        );
        $this->assertCount(21, $result['errors']);
    }

    public function test_a_database_failure_is_a_sentence_and_not_a_fatal(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->failQueries = true;
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Term translations could not be read. Polylang\'s translation data is missing or the database query failed. Existing terms were left unchanged.'),
            $result['errors']
        );
    }

    public function test_unassigned_term_uses_the_first_stored_language(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->languages = array(
            array('slug' => 'de', 'term_group' => 2),
            array('slug' => 'sv', 'term_group' => 0),
        );
        $database->catalog = array(
            $this->term(9, 'Shared', 'shared', 'category', ''),
        );
        $GLOBALS['wpdb'] = $database;

        $result = (new Polylang_Terms())->export();

        $this->assertSame(array('sv'), array_keys($result['items'][0]['translations']));
        $this->assertSame(9, $result['items'][0]['translations']['sv']['id']);
        $this->assertSame(
            array('A term has no Polylang language. It is included under sv, the first language stored by Polylang, so it is not dropped.'),
            $result['errors']
        );
    }

    public function test_polylang_api_exports_groups_untranslated_terms_and_parents(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $api = new Polylang_Terms_ApiDouble();
        $api->listed = array(
            'category' => array(
                array('id' => 10, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'parent' => 0),
                array('id' => 11, 'name' => 'Nachrichten', 'slug' => 'nachrichten', 'taxonomy' => 'category', 'parent' => 6),
                array('id' => 50, 'name' => 'Shared', 'slug' => 'shared', 'taxonomy' => 'category', 'parent' => 0),
            ),
            'post_tag' => array(
                array('id' => 40, 'name' => 'Solo', 'slug' => 'solo', 'taxonomy' => 'post_tag', 'parent' => 0),
                array('id' => 60, 'name' => 'Broken', 'slug' => 'broken', 'taxonomy' => 'post_tag', 'parent' => 0),
            ),
        );
        $api->maps = array(
            10 => array('de' => 11, 'en' => 10),
            11 => array('en' => 10, 'de' => 11),
            40 => array(),
            50 => array(),
            60 => null,
        );
        $api->langs = array(
            40 => 'sv',
            50 => '',
            60 => 'en',
        );
        $api->default = 'en';

        $result = $api->export();

        $news = $this->itemByGroup($result['items'], 'term:10');
        $this->assertSame('category', $news['taxonomy']);
        $this->assertSame(array('en', 'de'), array_keys($news['translations']));
        $this->assertSame(6, $news['translations']['de']['parent']);
        $this->assertSame('Nachrichten', $news['translations']['de']['name']);

        $solo = $this->itemByGroup($result['items'], 'term:40');
        $this->assertSame('sv', array_key_first($solo['translations']));

        $shared = $this->itemByGroup($result['items'], 'term:50');
        $this->assertSame('en', array_key_first($shared['translations']));

        $broken = $this->itemByGroup($result['items'], 'term:60');
        $this->assertSame('Broken', $broken['translations']['en']['name']);

        $this->assertCount(4, $result['items']);
        $this->assertContains(
            'Polylang could not return one term translation. The export continued with the terms it could read.',
            $result['errors']
        );
        $this->assertContains(
            'A term has no Polylang language. It is included under the default language (en) so it is not dropped.',
            $result['errors']
        );
    }

    public function test_api_listing_failure_does_not_claim_polylang_is_inactive(): void
    {
        if (!$this->canUseDatabaseStub()) {
            $this->markTestSkipped('WordPress functions are loaded.');
        }

        $api = new Polylang_Terms_ApiDouble();
        $api->failedLists = array('category', 'post_tag');
        $api->default = 'en';

        $result = $api->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Term translations could not be read. Polylang\'s translation data is missing or the database query failed. Existing terms were left unchanged.'),
            $result['errors']
        );
    }

    public function test_the_exporter_does_not_write_polylang_data(): void
    {
        $file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-terms.php';
        $source = file_get_contents($file);
        $this->assertIsString($source);

        $forbidden = array(
            'delete_option',
            'update_option',
            'delete_term(',
            'wp_delete_',
            'wp_insert_',
            'wp_update_',
            'update_term_meta(',
            'delete_term_meta(',
            'DELETE FROM',
            'UPDATE ',
            'INSERT INTO',
            'TRUNCATE ',
        );
        foreach ($forbidden as $token) {
            $this->assertStringNotContainsString($token, $source);
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{items: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    private function exportSnapshot(array $snapshot)
    {
        $exporter = new Polylang_Terms(function () use ($snapshot) {
            return $snapshot;
        });
        return $exporter->export();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function groupIds(array $items)
    {
        $groups = array();
        foreach ($items as $item) {
            $groups[] = $item['group'];
        }
        return $groups;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function itemByGroup(array $items, $group)
    {
        foreach ($items as $item) {
            if ($item['group'] === $group) {
                return $item;
            }
        }
        $this->fail('Missing group ' . $group);
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array{items: array<int, mixed>, errors: array<int, string>}
     */
    private function sharedTermExport(array $catalog)
    {
        $database = new Polylang_Terms_Database();
        $database->hasLanguages = true;
        $database->descriptions = array(serialize(array('en' => 13, 'sv' => 12)));
        $database->catalog = $catalog;
        $GLOBALS['wpdb'] = $database;

        $exporter = new Polylang_Terms();
        $first = $exporter->export();
        $second = $exporter->export();
        $third = $exporter->export();
        $this->assertSame($first, $second);
        $this->assertSame($second, $third);

        return $second;
    }

    /**
     * @return array<string, mixed>
     */
    private function term($id, $name, $slug, $taxonomy, $lang, $parent = 0, $description = '')
    {
        return array(
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'taxonomy' => $taxonomy,
            'lang' => $lang,
            'parent' => $parent,
            'description' => $description,
        );
    }

    /**
     * @return bool
     */
    private function wordpressIsLoaded()
    {
        return function_exists('get_option')
            || function_exists('get_terms')
            || function_exists('pll_get_term_translations')
            || function_exists('__');
    }

    /**
     * @return bool
     */
    private function canUseDatabaseStub()
    {
        return !$this->wordpressIsLoaded()
            && !function_exists('get_term')
            && !function_exists('pll_default_language')
            && !function_exists('pll_get_term_language')
            && !function_exists('PLL')
            && !class_exists('PLL', false)
            && !class_exists('Polylang', false);
    }
}

/**
 * Small stand-in for $wpdb used only by these tests.
 */
class Polylang_Terms_Database
{
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
    public $term_relationships = 'wp_term_relationships';

    /**
     * @var string
     */
    public $last_error = '';

    /**
     * @var array<int, string>
     */
    public $descriptions = array();

    /**
     * Translation-group term_taxonomy_id => linked term ids.
     *
     * @var array<int, array<int, int>>
     */
    public $links = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    public $catalog = array();

    /**
     * @var bool
     */
    public $hasLanguages = false;

    /**
     * Languages stored in the language taxonomy, ordered later by term_group.
     *
     * @var array<int, array<string, mixed>>
     */
    public $languages = array();

    /**
     * @var bool
     */
    public $failQueries = false;

    /**
     * @var array<int, string>
     */
    public $queries = array();

    /**
     * @param mixed ...$args
     * @return string
     */
    public function prepare($query, ...$args)
    {
        if (isset($args[0]) && is_array($args[0]) && count($args) === 1) {
            $args = $args[0];
        }
        $index = 0;
        $count = count($args);
        $built = preg_replace_callback('/(?<!%)%[dfs]/', function ($match) use (&$args, &$index, $count) {
            if ($index >= $count) {
                return $match[0];
            }
            $value = $args[$index];
            $index++;
            if ($match[0] === '%d' || $match[0] === '%f') {
                return (string) (int) $value;
            }
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, (string) $query);

        return is_string($built) ? $built : (string) $query;
    }

    /**
     * @param mixed $query
     * @param string $output
     * @return array<int, array<string, mixed>>|null
     */
    public function get_results($query, $output = 'OBJECT')
    {
        unset($output);
        $this->queries[] = is_string($query) ? $query : '';
        if ($this->failQueries) {
            $this->last_error = 'The term tables could not be read.';
            return null;
        }
        if (!is_string($query)) {
            $this->last_error = 'Query was not a string.';
            return null;
        }
        if (strpos($query, "taxonomy = 'term_translations'") !== false) {
            $rows = array();
            $termTaxonomyId = 1;
            foreach ($this->descriptions as $description) {
                $rows[] = array(
                    'description' => $description,
                    'term_taxonomy_id' => $termTaxonomyId,
                );
                $termTaxonomyId++;
            }
            return $rows;
        }
        if (strpos($query, 'term_relationships') !== false && strpos($query, 'object_id') !== false && strpos($query, "taxonomy = 'term_language'") === false) {
            $wanted = array();
            if (preg_match('/term_taxonomy_id = (\d+)/', $query, $match)) {
                $wanted[(int) $match[1]] = true;
            } elseif (preg_match('/IN \(([^)]*)\)/', $query, $match) && trim($match[1]) !== '') {
                foreach (explode(',', $match[1]) as $piece) {
                    $wanted[(int) trim($piece)] = true;
                }
            }
            $rows = array();
            foreach ($this->links as $termTaxonomyId => $ids) {
                if ($wanted !== array() && !isset($wanted[(int) $termTaxonomyId])) {
                    continue;
                }
                foreach ($ids as $id) {
                    $rows[] = array(
                        'term_taxonomy_id' => (int) $termTaxonomyId,
                        'object_id' => (int) $id,
                    );
                }
            }
            return $rows;
        }
        if (strpos($query, "taxonomy = 'term_language'") !== false) {
            $rows = array();
            foreach ($this->catalog as $term) {
                if ($term['lang'] === '') {
                    continue;
                }
                $rows[] = array(
                    'object_id' => $term['id'],
                    'slug' => 'pll_' . $term['lang'],
                );
            }
            return $rows;
        }
        if (strpos($query, "taxonomy = 'language'") !== false && strpos($query, 'lang.slug') !== false) {
            $languages = $this->languages;
            usort($languages, function ($left, $right) {
                $byGroup = (int) $left['term_group'] <=> (int) $right['term_group'];
                if ($byGroup !== 0) {
                    return $byGroup;
                }
                return strcmp((string) $left['slug'], (string) $right['slug']);
            });
            if ($languages === array()) {
                return array();
            }
            return array(array('slug' => $languages[0]['slug']));
        }
        if (strpos($query, "taxonomy = 'language'") !== false) {
            if (!$this->hasLanguages && $this->languages === array()) {
                return array();
            }
            return array(array('term_taxonomy_id' => 1));
        }
        if (preg_match('/IN \(([^)]*)\)/', $query, $matches)) {
            $ids = array();
            if (trim($matches[1]) !== '') {
                foreach (explode(',', $matches[1]) as $piece) {
                    $ids[(int) trim($piece)] = true;
                }
            }
            $rows = array();
            foreach ($this->catalog as $term) {
                if (!isset($ids[$term['id']])) {
                    continue;
                }
                $rows[] = $this->termRow($term);
            }
            return $rows;
        }
        if (preg_match("/taxonomy = '([^']+)'/", $query, $matches)) {
            $rows = array();
            foreach ($this->catalog as $term) {
                if ($term['taxonomy'] !== $matches[1]) {
                    continue;
                }
                $rows[] = $this->termRow($term);
            }
            usort($rows, function ($left, $right) {
                return $left['term_id'] <=> $right['term_id'];
            });
            if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $limit)) {
                $rows = array_slice($rows, (int) $limit[2], (int) $limit[1]);
            }
            return $rows;
        }

        $this->last_error = 'Unexpected query.';
        return null;
    }

    /**
     * @param array<string, mixed> $term
     * @return array<string, mixed>
     */
    private function termRow(array $term)
    {
        return array(
            'term_id' => $term['id'],
            'name' => $term['name'],
            'slug' => $term['slug'],
            'taxonomy' => $term['taxonomy'],
            'parent' => array_key_exists('parent', $term) ? $term['parent'] : null,
            'description' => array_key_exists('description', $term) ? $term['description'] : null,
        );
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!class_exists(Polylang_Terms::class, false)) {
    require_once dirname(__DIR__, 2) . '/includes/tolka/class-polylang-terms.php';
}

/**
 * Polylang API stand-in. It does not define global WordPress functions.
 */
class Polylang_Terms_ApiDouble extends Polylang_Terms
{
    /**
     * @var array<int, string>
     */
    public $taxonomies = array('category', 'post_tag');

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    public $listed = array();

    /**
     * @var array<int, array<string, int>|null>
     */
    public $maps = array();

    /**
     * @var array<int, string>
     */
    public $langs = array();

    /**
     * @var string
     */
    public $default = 'en';

    /**
     * @var array<int, string>
     */
    public $failedLists = array();

    protected function apiReady()
    {
        return true;
    }

    protected function databaseReady()
    {
        return false;
    }

    protected function defaultLanguage()
    {
        return $this->default;
    }

    protected function translatedTaxonomies()
    {
        return $this->taxonomies;
    }

    protected function listTaxonomyTerms($taxonomy)
    {
        if (in_array($taxonomy, $this->failedLists, true)) {
            return null;
        }
        if (!isset($this->listed[$taxonomy])) {
            return array();
        }
        return $this->listed[$taxonomy];
    }

    protected function apiTranslationMap($termId)
    {
        $termId = (int) $termId;
        if (!array_key_exists($termId, $this->maps)) {
            return array();
        }
        return $this->maps[$termId];
    }

    protected function apiTermLanguage($termId)
    {
        $termId = (int) $termId;
        if (!isset($this->langs[$termId])) {
            return '';
        }
        return $this->langs[$termId];
    }
}
