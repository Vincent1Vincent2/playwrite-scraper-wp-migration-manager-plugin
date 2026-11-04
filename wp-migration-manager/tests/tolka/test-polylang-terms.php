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

    public function test_null_bytes_are_removed_from_term_text(): void
    {
        $result = $this->exportSnapshot(array(
            'state' => 'ok',
            'default_lang' => 'en',
            'groups' => array(
                array(
                    'taxonomy' => 'category',
                    'translations' => array(
                        'en' => array('id' => 9, 'name' => "News\0", 'slug' => "news\0"),
                    ),
                ),
            ),
        ));

        $this->assertSame('News', $result['items'][0]['translations']['en']['name']);
        $this->assertSame('news', $result['items'][0]['translations']['en']['slug']);
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
            'A term translation group could not be read because its stored description is invalid. That group was skipped.',
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
            foreach ($this->descriptions as $description) {
                $rows[] = array('description' => $description);
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
            'parent' => isset($term['parent']) ? (int) $term['parent'] : 0,
            'description' => isset($term['description']) ? $term['description'] : '',
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
