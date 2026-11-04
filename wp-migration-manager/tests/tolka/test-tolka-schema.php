<?php

/**
 * Tests for the Tolka package schema and writer.
 *
 * @package MigrationManager
 */

if (!defined('WMM_TOLKA_SCHEMA_TEST')) {
    define('WMM_TOLKA_SCHEMA_TEST', true);
}

use WMM\Tolka\Tolka_Schema;
use WMM\Tolka\Tolka_Writer;

/**
 * Package shape, empty packages, and writer failures.
 */
class Tolka_Schema_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Load the schema classes when the suite has not loaded them yet.
     */
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $directory = dirname(__DIR__, 2) . '/includes/tolka/';
        foreach (array('class-tolka-schema.php', 'class-tolka-writer.php') as $file) {
            if (is_readable($directory . $file)) {
                require_once $directory . $file;
            }
        }
    }

    /**
     * Start from a WordPress stand-in that can store the package.
     */
    protected function setUp(): void
    {
        if (function_exists('wmm_tolka_skip_without_class')) {
            wmm_tolka_skip_without_class(array('Tolka_Schema', 'Tolka_Writer'));
        } elseif (!class_exists(Tolka_Schema::class) || !class_exists(Tolka_Writer::class)) {
            $this->markTestSkipped('Tolka schema classes are not loaded.');
        }

        $GLOBALS['wmm_tolka_schema_driver'] = array(
            'calls' => array(),
            'can_manage' => true,
            'has_update_option' => true,
            'has_get_option' => true,
            'has_update_post_meta' => true,
            'has_get_post_meta' => true,
            'has_update_term_meta' => true,
            'has_get_term_meta' => true,
            'option_result' => true,
            'post_meta_result' => true,
            'term_meta_result' => true,
            'stored_package' => null,
            'post_meta' => array(),
            'term_meta' => array(),
            'polylang_option' => array(
                'default_lang' => 'en',
                'force_lang' => 1,
                'version' => '3.6.2',
            ),
        );
        $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'] = $GLOBALS['wmm_tolka_schema_driver']['polylang_option'];
    }

    /**
     * Drop the stand-in so later tests use WordPress when it is loaded.
     */
    protected function tearDown(): void
    {
        if (isset($GLOBALS['wmm_tolka_schema_driver']['polylang_option'], $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'])) {
            $this->assertSame(
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
            );
        }

        unset($GLOBALS['wmm_tolka_schema_driver']);
    }

    public function test_missing_sections_become_empty_item_lists()
    {
        $package = Tolka_Schema::package(array());

        $this->assertSame('tolka-migration', Tolka_Schema::FORMAT);
        $this->assertSame(1, Tolka_Schema::VERSION);
        $this->assertSame('tolka-migration', $package['format']);
        $this->assertSame(1, $package['version']);
        $this->assertSame('Z', substr($package['generated_at'], -1));
        $header = Tolka_Schema::header('2024-05-06T07:08:09+00:00');
        $this->assertSame('tolka-migration', $header['format']);
        $this->assertSame(1, $header['version']);
        $this->assertSame('2024-05-06T07:08:09Z', $header['generated_at']);
        $this->assertSame(
            array(
                'format',
                'version',
                'generated_at',
                'languages',
                'posts',
                'terms',
                'strings',
                'menus',
                'media',
            ),
            array_keys($package)
        );

        foreach (Tolka_Schema::sections() as $section) {
            $this->assertSame(array(), $package[$section]);
        }
    }

    public function test_generated_at_is_iso8601_gmt_when_php_timezone_is_not_utc()
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');

        try {
            $before = time();
            $package = Tolka_Schema::package(array());
            $after = time();
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $package['generated_at']));
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($package['generated_at']));
        $parsed = new DateTimeImmutable($package['generated_at']);
        $this->assertSame(0, $parsed->getOffset());
        $stamp = strtotime($package['generated_at']);
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
    }

    public function test_saved_header_is_tolka_migration_version_one_ending_in_z()
    {
        $cases = array(
            array('version' => '1.0', 'generated_at' => '2024-05-06T07:08:09z'),
            array('version' => 1.0, 'generated_at' => '2024-05-06T07:08:09Z'),
            array('version' => ' 1 ', 'generated_at' => '2024-05-06T07:08:09+00:00'),
        );

        foreach ($cases as $case) {
            $result = Tolka_Writer::write(array(
                'format' => 'Migration',
                'version' => $case['version'],
                'generated_at' => $case['generated_at'],
                'posts' => array(
                    array(
                        'group' => 'post:4',
                        'translations' => array(
                            'en' => array(
                                'id' => 4,
                                'title' => "A\0 <em>title</em>",
                                'content' => "<p>Hello\0</p>",
                            ),
                        ),
                    ),
                ),
            ));
            $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

            $this->assertTrue($result['ok']);
            $this->assertSame('tolka-migration', $stored['format']);
            $this->assertSame(1, $stored['version']);
            $this->assertSame('2024-05-06T07:08:09Z', $stored['generated_at']);
            $this->assertSame('Z', substr($stored['generated_at'], -1));
            $this->assertSame('A <em>title</em>', $stored['posts'][0]['translations']['en']['title']);
            $this->assertSame('<p>Hello</p>', $stored['posts'][0]['translations']['en']['content']);
            $this->assertSame(
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
            );
        }
    }

    public function test_gmt_offset_zero_is_kept_and_a_blank_timestamp_is_replaced()
    {
        $kept = '2024-05-06T07:08:09+00:00';
        $result = Tolka_Writer::write(array(
            'format' => 'other',
            'version' => '1',
            'generated_at' => $kept,
            'posts' => array(),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('2024-05-06T07:08:09Z', $stored['generated_at']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame(0, (new DateTimeImmutable($stored['generated_at']))->getOffset());

        $before = time();
        $replaced = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '',
        ));
        $after = time();
        $stamp = $replaced['ok'] ? strtotime($GLOBALS['wmm_tolka_schema_driver']['stored_package']['generated_at']) : false;

        $this->assertTrue($replaced['ok']);
        $this->assertSame('tolka-migration', $GLOBALS['wmm_tolka_schema_driver']['stored_package']['format']);
        $this->assertSame(1, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['version']);
        $this->assertSame('Z', substr($GLOBALS['wmm_tolka_schema_driver']['stored_package']['generated_at'], -1));
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($GLOBALS['wmm_tolka_schema_driver']['stored_package']['generated_at']));
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );

        $this->assertFalse(Tolka_Schema::is_gmt_timestamp('2020-13-40T00:00:00Z'));
        $this->assertFalse(Tolka_Schema::is_gmt_timestamp('2020-01-01T00:00:00.000Z'));
        $before_fraction = time();
        $fraction = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '2020-01-01T00:00:00.000Z',
        ));
        $after_fraction = time();
        $fraction_stamp = $GLOBALS['wmm_tolka_schema_driver']['stored_package']['generated_at'];
        $this->assertTrue($fraction['ok']);
        $this->assertSame('Z', substr($fraction_stamp, -1));
        $this->assertSame(1, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['version']);
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($fraction_stamp));
        $this->assertNotSame('2020-01-01T00:00:00.000Z', $fraction_stamp);
        $this->assertGreaterThanOrEqual($before_fraction, strtotime($fraction_stamp));
        $this->assertLessThanOrEqual($after_fraction, strtotime($fraction_stamp));
        $before_invalid = time();
        $invalid = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '2020-13-40T00:00:00Z',
        ));
        $after_invalid = time();
        $fixed = $GLOBALS['wmm_tolka_schema_driver']['stored_package']['generated_at'];

        $this->assertTrue($invalid['ok']);
        $this->assertSame('tolka-migration', $GLOBALS['wmm_tolka_schema_driver']['stored_package']['format']);
        $this->assertSame(1, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['version']);
        $this->assertSame('Z', substr($fixed, -1));
        $this->assertNotSame('2020-13-40T00:00:00Z', $fixed);
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($fixed));
        $this->assertGreaterThanOrEqual($before_invalid, strtotime($fixed));
        $this->assertLessThanOrEqual($after_invalid, strtotime($fixed));
    }

    public function test_writer_stores_gmt_timestamp_and_leaves_polylang_option()
    {
        $polylang = $GLOBALS['wmm_tolka_schema_driver']['polylang_option'];
        $before = time();
        $result = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '2020-01-01T12:00:00+12:00',
            'posts' => array(
                array(
                    'group' => 'post:9',
                    'translations' => array(
                        'en' => array('id' => 9, 'title' => 'Hello'),
                        'polylang' => array('id' => 19, 'title' => 'Not the option'),
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:3',
                    'translations' => array(
                        'en' => array('id' => 3, 'name' => 'News'),
                    ),
                ),
            ),
        ));
        $after = time();

        $this->assertTrue($result['ok']);
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($stored['generated_at']));
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $parsed = new DateTimeImmutable($stored['generated_at']);
        $this->assertSame(0, $parsed->getOffset());
        $stamp = strtotime($stored['generated_at']);
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
        $this->assertSame($polylang, $GLOBALS['wmm_tolka_schema_driver']['polylang_option']);

        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            if ($call['fn'] === 'update_option' || $call['fn'] === 'get_option') {
                $this->assertSame('wmm_tolka_package', $call['option']);
                $this->assertNotSame('polylang', $call['option']);
            }
            if ($call['fn'] === 'update_post_meta' || $call['fn'] === 'update_term_meta') {
                $this->assertContains($call['key'], array('_wmm_tolka_lang', '_wmm_tolka_group'));
            }
        }

        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:9',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][9]
        );
        $this->assertSame('polylang', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][19]['_wmm_tolka_lang']);
        $this->assertSame('post:9', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][19]['_wmm_tolka_group']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'term:3',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['term_meta'][3]
        );
    }

    public function test_package_copies_items_and_drops_exporter_errors()
    {
        $language = array(
            'slug' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'is_default' => true,
            'term_id' => 3,
            'flag' => 'us',
        );
        $package = Tolka_Schema::package(array(
            'languages' => array(
                'items' => array($language),
                'errors' => array('Polylang lookup failed in a way the package must not keep.'),
            ),
            'notes' => array('items' => array(array('ignored' => true))),
        ));

        $this->assertSame(array($language), $package['languages']);
        $this->assertSame(array(), $package['posts']);
        $this->assertArrayNotHasKey('errors', $package);
        $this->assertArrayNotHasKey('notes', $package);
        $this->assertStringNotContainsString(
            'Polylang lookup failed',
            json_encode($package)
        );
    }

    public function test_unreadable_envelopes_become_empty_lists()
    {
        $package = Tolka_Schema::package(array(
            'languages' => null,
            'posts' => 'posts',
            'terms' => array('errors' => array('missing items')),
            'strings' => array('items' => 'nope'),
            'menus' => array('items' => null),
            'media' => array('items' => array()),
        ));

        $this->assertSame(array(), $package['languages']);
        $this->assertSame(array(), $package['posts']);
        $this->assertSame(array(), $package['terms']);
        $this->assertSame(array(), $package['strings']);
        $this->assertSame(array(), $package['menus']);
        $this->assertSame(array(), $package['media']);
    }

    public function test_package_keeps_content_and_detaches_object_snapshots()
    {
        $translation = new stdClass();
        $translation->id = 10;
        $translation->title = 'Héllo <strong>there</strong>';
        $translation->slug = 'hello';
        $translation->status = 'publish';
        $translation->excerpt = '';
        $translation->content = "Line\n<tag> & \"quote\"";

        $package = Tolka_Schema::package(array(
            'posts' => array(
                'items' => array(
                    array(
                        'group' => 'post:10',
                        'type' => 'post',
                        'translations' => array(
                            'sv' => $translation,
                        ),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $translation->title = 'Changed';
        $saved = $package['posts'][0]['translations']['sv'];

        $this->assertIsArray($saved);
        $this->assertSame('Héllo <strong>there</strong>', $saved['title']);
        $this->assertSame("Line\n<tag> & \"quote\"", $saved['content']);
        $this->assertSame('', $saved['excerpt']);
        $this->assertSame(10, $saved['id']);
    }

    public function test_non_finite_numbers_do_not_make_the_package_unreadable()
    {
        $package = Tolka_Schema::package(array(
            'strings' => array(
                'items' => array(
                    array(
                        'name' => 'broken',
                        'string' => INF,
                        'context' => 'theme',
                        'translations' => array('en' => NAN),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $this->assertNull($package['strings'][0]['string']);
        $this->assertNull($package['strings'][0]['translations']['en']);
        $this->assertNotFalse(json_encode($package));
    }

    public function test_self_referential_item_does_not_hang()
    {
        $item = array(
            'group' => 'post:1',
            'type' => 'post',
            'translations' => array(
                'en' => array(
                    'id' => 1,
                    'title' => 'Hello',
                    'content' => 'Body',
                ),
            ),
        );
        $item['translations']['en']['loop'] = &$item;

        $package = Tolka_Schema::package(array(
            'posts' => array(
                'items' => array($item),
                'errors' => array(),
            ),
        ));

        $this->assertSame(1, $package['posts'][0]['translations']['en']['id']);
        $this->assertSame('Hello', $package['posts'][0]['translations']['en']['title']);
        $this->assertNotFalse(json_encode($package));

        $result = Tolka_Writer::write($package);
        $this->assertTrue($result['ok']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][1]['_wmm_tolka_lang']);
    }

    public function test_cyclic_exporter_object_does_not_hang()
    {
        $translation = new stdClass();
        $translation->id = 4;
        $translation->self = $translation;

        $package = Tolka_Schema::package(array(
            'terms' => array(
                'items' => array(
                    array(
                        'taxonomy' => 'category',
                        'group' => 'term:4',
                        'translations' => array('en' => $translation),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $saved = $package['terms'][0]['translations']['en'];
        $this->assertIsArray($saved);
        $this->assertSame(4, $saved['id']);
        $this->assertNull($saved['self']);
    }

    public function test_empty_package_is_saved_successfully()
    {
        $package = Tolka_Schema::package(array(
            'languages' => array(
                'items' => array(),
                'errors' => array('Polylang is not active.'),
            ),
        ));

        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(
            array(
                'languages' => 0,
                'posts' => 0,
                'terms' => 0,
                'strings' => 0,
                'menus' => 0,
                'media' => 0,
            ),
            $result['counts']
        );
        $this->assertSame(
            array('ok', 'counts', 'errors'),
            array_keys($result)
        );
        $this->assertSame($package, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(
            array(
                array(
                    'fn' => 'update_option',
                    'option' => 'wmm_tolka_package',
                    'autoload' => false,
                ),
            ),
            $GLOBALS['wmm_tolka_schema_driver']['calls']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
    }

    public function test_empty_package_replaces_the_snapshot_and_leaves_tags()
    {
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][5] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:5',
            '_pll_lang' => 'en',
        );
        $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'term:8',
        );

        $package = Tolka_Schema::package(array());
        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame($package, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:5',
                '_pll_lang' => 'en',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][5]
        );
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'sv',
                '_wmm_tolka_group' => 'term:8',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8]
        );
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
    }

    public function test_package_with_no_sections_counts_as_empty()
    {
        $result = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1,
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['counts']['posts']);
        $this->assertSame(0, $result['counts']['media']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_write_tags_posts_terms_and_leaves_other_sections_untagged()
    {
        $package = $this->sample_package();
        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['languages']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame(1, $result['counts']['terms']);
        $this->assertSame(1, $result['counts']['strings']);
        $this->assertSame(1, $result['counts']['menus']);
        $this->assertSame(1, $result['counts']['media']);

        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'sv',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][11]
        );
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'term:4',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]
        );
        $this->assertArrayNotHasKey(80, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(3, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_scalar_and_uppercase_ids_are_tagged()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:15',
                    'translations' => array(
                        'en' => 15,
                        'sv' => '16',
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:7',
                    'translations' => array(
                        'en' => array('ID' => 7, 'name' => 'News'),
                        'sv' => array('id' => 8.0),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][15]['_wmm_tolka_lang']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][16]['_wmm_tolka_lang']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][7]['_wmm_tolka_lang']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8]['_wmm_tolka_lang']);
        $this->assertSame('term:7', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8]['_wmm_tolka_group']);
    }

    public function test_missing_id_is_a_finished_error_and_valid_rows_are_kept()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array(
                        'en' => array('id' => 10, 'title' => 'Hello'),
                        'sv' => array('title' => 'Hej'),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame(
            array(
                'A post translation for "sv" in group "post:10" has no id, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertArrayNotHasKey(0, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_post_id_zero_is_skipped_with_a_finished_sentence()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array(
                        'en' => array('id' => 0, 'title' => '<em>Empty</em>'),
                        'sv' => array('id' => '0', 'title' => 'Noll'),
                        'de' => array('ID' => 0.0, 'title' => 'Null'),
                        'fr' => 0,
                        'it' => "0\0",
                        'es' => array('id' => 12, 'title' => 'Real'),
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:4',
                    'translations' => array(
                        'en' => array('id' => 0, 'name' => 'None'),
                        'sv' => array('id' => 4, 'name' => 'News'),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Post id 0, titled "Empty", for language "en" in group "post:10" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Post id 0, titled "Noll", for language "sv" in group "post:10" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Post id 0, titled "Null", for language "de" in group "post:10" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Post id 0 for language "fr" in group "post:10" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Post id 0 for language "it" in group "post:10" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Term id 0, named "None", for language "en" in group "term:4" was skipped because it is not a saved term. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertArrayNotHasKey(0, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(0, $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(0, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['posts'][0]['translations']['en']['id']);
        $this->assertSame('<em>Empty</em>', $GLOBALS['wmm_tolka_schema_driver']['stored_package']['posts'][0]['translations']['en']['title']);
        $this->assertSame('es', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12]['_wmm_tolka_lang']);
        $this->assertSame('post:10', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12]['_wmm_tolka_group']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_lang']);
        $this->assertSame('term:4', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_group']);
    }

    public function test_negative_post_id_is_skipped_with_a_finished_sentence()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:4',
                    'translations' => array(
                        'en' => array('id' => -3, 'title' => 'Draft'),
                        'sv' => '-8',
                        'de' => array('id' => 4, 'title' => 'Kept'),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Post id -3 for language "en" in group "post:4" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
                'Post id -8 for language "sv" in group "post:4" was skipped because it is not a saved post. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertArrayNotHasKey(-3, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame('de', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][4]['_wmm_tolka_lang']);
    }

    public function test_id_zero_title_stays_short_and_literal()
    {
        $long = str_repeat('A', 90);
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:3',
                    'translations' => array(
                        'en' => array('id' => 0, 'title' => $long),
                        'sv' => array('id' => 0, 'title' => '100% ready'),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('titled "' . str_repeat('A', 79) . '..."', $result['errors'][0]);
        $this->assertStringContainsString('titled "100% ready"', $result['errors'][1]);
        $this->assertArrayNotHasKey(0, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame($long, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['posts'][0]['translations']['en']['title']);
    }

    public function test_rejected_ids_do_not_tag_a_post()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:1',
                    'translations' => array(
                        'en' => array('id' => 0),
                        'sv' => array('id' => -3),
                        'de' => array('id' => true),
                        'fr' => array('id' => '08'),
                        'es' => array('id' => 1.5),
                        'it' => array('id' => false),
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertCount(6, $result['errors']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        foreach ($result['errors'] as $error) {
            $this->assertStringContainsString('Nothing was deleted, and your Polylang content is still in place.', $error);
        }
    }

    public function test_incomplete_groups_report_why_they_were_skipped()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                'not-a-group',
                array('translations' => 'bad'),
                array('translations' => array('sv' => 12)),
                array(
                    'group' => 'post:9',
                    'translations' => array('' => 9),
                ),
                array(
                    'group' => 'post:2',
                    'translations' => array(),
                ),
            ),
            'terms' => 'categories',
        ));

        $this->assertFalse($result['ok']);
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(5, $result['counts']['posts']);
        $this->assertSame(0, $result['counts']['terms']);
        $this->assertSame(
            array(
                'One post entry in the Tolka package was incomplete, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'One post entry in the Tolka package has translations Tolka cannot read, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'A post translation has no group id, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'A post translation in group "post:9" has no language code, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The terms part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The Tolka package was not saved because part of it was not a list. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
    }

    public function test_conflicting_tags_keep_the_last_listing()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
                array(
                    'group' => 'post:11',
                    'translations' => array('sv' => 10),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:4',
                    'translations' => array('en' => 4),
                ),
                array(
                    'group' => 'term:4',
                    'translations' => array('en' => 4),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Post 10 was listed as "en" in group "post:10" and as "sv" in group "post:11", so Tolka kept "sv" in group "post:11". Check that translation before you switch the site to Tolka. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertSame('post:11', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_group']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_lang']);
        $this->assertSame(2, $this->call_count('update_term_meta'));
    }

    public function test_saving_the_same_package_again_is_success()
    {
        $package = Tolka_Schema::package(array());
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $package;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:10',
        );
        $GLOBALS['wmm_tolka_schema_driver']['post_meta_result'] = false;

        $package['posts'] = array(
            array(
                'group' => 'post:10',
                'translations' => array('en' => 10),
            ),
        );
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $package;

        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_option_storage_failure_does_not_tag_posts()
    {
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame(
            array(
                'WordPress could not save the Tolka migration package, so existing Tolka language tags were left unchanged. Run the migration again. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_failed_package_save_leaves_existing_tolka_meta_unchanged()
    {
        $previous = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'languages' => array(),
            'posts' => array(),
            'terms' => array(),
            'strings' => array(),
            'menus' => array(),
            'media' => array(),
        );
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $previous;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'post:10',
            '_pll_lang' => 'sv',
        );
        $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'term:4',
            'polylang_note' => 'leave this',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:11',
                    'translations' => array(
                        'en' => 10,
                        'fr' => 11,
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:8',
                    'translations' => array('en' => 4),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame($previous, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'sv',
                '_wmm_tolka_group' => 'post:10',
                '_pll_lang' => 'sv',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertArrayNotHasKey(11, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'sv',
                '_wmm_tolka_group' => 'term:4',
                'polylang_note' => 'leave this',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]
        );
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_failed_save_reports_post_id_zero_without_retagging()
    {
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:12',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:12',
                    'translations' => array(
                        'en' => array('id' => 0, 'title' => 'Missing'),
                        'sv' => array('id' => 12, 'title' => 'Real'),
                    ),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString('Post id 0, titled "Missing", for language "en" in group "post:12" was skipped because it is not a saved post.', $joined);
        $this->assertStringContainsString('existing Tolka language tags were left unchanged', $joined);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:12',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12]
        );
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_option_exception_does_not_change_existing_tolka_meta()
    {
        $previous = array('format' => 'tolka-migration', 'version' => 1, 'posts' => array());
        $GLOBALS['wmm_tolka_schema_driver']['throw_option'] = true;
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $previous;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'post:10',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:11',
                    'translations' => array('en' => 11),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('existing Tolka language tags were left unchanged', $result['errors'][0]);
        $this->assertSame($previous, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertArrayNotHasKey(11, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_meta_exception_keeps_the_saved_package_and_earlier_tags()
    {
        $GLOBALS['wmm_tolka_schema_driver']['throw_meta'] = true;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'post:10',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:11',
                    'translations' => array('en' => 11),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Tolka saved the migration package, but it could not finish tagging posts and terms. Tags from an earlier run were left in place. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame('post:11', $GLOBALS['wmm_tolka_schema_driver']['stored_package']['posts'][0]['group']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertArrayNotHasKey(11, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
    }

    public function test_missing_option_storage_is_a_clear_error()
    {
        $GLOBALS['wmm_tolka_schema_driver']['has_update_option'] = false;

        $result = Tolka_Writer::write(Tolka_Schema::package(array()));

        $this->assertFalse($result['ok']);
        $this->assertSame(
            array(
                'WordPress could not save the Tolka migration package because this site cannot store options, so existing Tolka language tags were left unchanged. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['calls']);
    }

    public function test_missing_meta_storage_reports_one_error_per_kind()
    {
        $GLOBALS['wmm_tolka_schema_driver']['has_update_post_meta'] = false;
        $GLOBALS['wmm_tolka_schema_driver']['has_update_term_meta'] = false;

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array(
                        'en' => 10,
                        'sv' => 11,
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:4',
                    'translations' => array('en' => 4),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'WordPress could not tag translated posts for Tolka because this site cannot store post details. Nothing was deleted, and your Polylang content is still in place.',
                'WordPress could not tag translated terms for Tolka because this site cannot store term details. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
    }

    public function test_meta_write_failure_names_the_post_and_keeps_polylang_promise()
    {
        $GLOBALS['wmm_tolka_schema_driver']['post_meta_result'] = false;

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Tolka could not save the language "en" on post 10. Nothing was deleted, and your Polylang content is still in place.',
                'Tolka could not save the group "post:10" on post 10. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
    }

    public function test_administrator_permission_is_required()
    {
        $GLOBALS['wmm_tolka_schema_driver']['can_manage'] = false;

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame(
            array(
                'You need administrator permission to migrate this site to Tolka. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['calls']);
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);
    }

    public function test_many_problems_end_with_one_summary()
    {
        $posts = array();
        for ($index = 0; $index < 40; $index++) {
            $posts[] = array(
                'group' => 'post:' . $index,
                'translations' => array('en' => array('title' => 'Missing')),
            );
        }
        $posts[] = array(
            'group' => 'post:99',
            'translations' => array('en' => 99),
        );

        $result = Tolka_Writer::write(array('posts' => $posts));

        $this->assertTrue($result['ok']);
        $this->assertCount(31, $result['errors']);
        $this->assertSame(
            '10 more problems were found. Review the preview, then run the migration again. Nothing was deleted, and your Polylang content is still in place.',
            $result['errors'][30]
        );
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][99]['_wmm_tolka_lang']);
    }

    public function test_section_type_errors_name_each_part()
    {
        $result = Tolka_Writer::write(array(
            'languages' => 'en',
            'posts' => 1,
            'terms' => null,
            'strings' => 'hello',
            'menus' => 2,
            'media' => false,
        ));

        $this->assertFalse($result['ok']);
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(
            array(
                'The languages part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The posts part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The terms part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The strings part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The menus part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The media part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The Tolka package was not saved because part of it was not a list. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        foreach ($result['counts'] as $count) {
            $this->assertSame(0, $count);
        }
    }

    public function test_broken_section_does_not_replace_a_saved_package_or_tags()
    {
        $previous = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'posts' => array(
                array(
                    'group' => 'post:5',
                    'translations' => array('en' => 5),
                ),
            ),
        );
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $previous;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][5] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:5',
            '_pll_lang' => 'en',
        );

        $result = Tolka_Writer::write(array(
            'posts' => 'not-a-list',
            'terms' => array(
                array(
                    'group' => 'term:9',
                    'translations' => array('sv' => 9),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('The Tolka package was not saved because part of it was not a list.', implode("\n", $result['errors']));
        $this->assertSame($previous, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:5',
                '_pll_lang' => 'en',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][5]
        );
        $this->assertArrayNotHasKey(9, $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(0, $this->call_count('update_option'));
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
    }

    public function test_unsaved_package_is_explained_after_many_row_errors()
    {
        $posts = array();
        for ($index = 0; $index < 40; $index++) {
            $posts[] = array(
                'group' => 'post:' . $index,
                'translations' => array(
                    'en' => array('title' => 'Missing'),
                ),
            );
        }

        $result = Tolka_Writer::write(array(
            'posts' => $posts,
            'terms' => 'bad',
        ));

        $this->assertFalse($result['ok']);
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertStringContainsString(
            'The Tolka package was not saved because part of it was not a list.',
            implode("\n", $result['errors'])
        );
    }

    public function test_write_outside_wordpress_reports_a_clear_error()
    {
        unset($GLOBALS['wmm_tolka_schema_driver']);

        if (function_exists('update_option') || function_exists('current_user_can')) {
            $this->markTestSkipped('WordPress storage is loaded, so the empty runtime is covered by the stand-in.');
        }

        $result = Tolka_Writer::write(Tolka_Schema::package(array()));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['counts']['languages']);
        $this->assertSame(
            array(
                'WordPress could not save the Tolka migration package because this site cannot store options, so existing Tolka language tags were left unchanged. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
    }

    public function test_exporter_envelopes_are_saved_as_a_package()
    {
        $marker = 'EXPORTER-ERROR-MUST-NOT-BE-STORED';
        $result = Tolka_Writer::write(array(
            'languages' => array(
                'items' => array(
                    array(
                        'slug' => 'en',
                        'name' => 'English',
                        'locale' => 'en_US',
                        'is_default' => true,
                        'term_id' => 3,
                        'flag' => 'us',
                    ),
                ),
                'errors' => array($marker),
            ),
            'posts' => array(
                'items' => array(
                    array(
                        'group' => 'post:10',
                        'type' => 'post',
                        'translations' => array(
                            'en' => array('id' => 10, 'title' => 'Hello'),
                        ),
                    ),
                ),
                'errors' => array($marker),
            ),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['counts']['languages']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('en', $stored['languages'][0]['slug']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertStringNotContainsString($marker, json_encode($stored));
    }

    public function test_version_two_and_boolean_one_are_not_rewritten()
    {
        $previous = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '2024-01-01T00:00:00Z',
            'posts' => array(),
        );
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $previous;

        foreach (array(2, true, '2', 1.5) as $version) {
            $GLOBALS['wmm_tolka_schema_driver']['calls'] = array();
            $result = Tolka_Writer::write(array(
                'format' => 'tolka-migration',
                'version' => $version,
                'generated_at' => '2024-05-06T07:08:09Z',
                'posts' => array(
                    array(
                        'group' => 'post:4',
                        'translations' => array('en' => 4),
                    ),
                ),
            ));

            $this->assertFalse($result['ok']);
            $this->assertSame($previous, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
            $this->assertSame(0, $this->call_count('update_option'));
            $this->assertSame(0, $this->call_count('update_post_meta'));
            $this->assertSame(
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
                $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
            );
        }
    }

    public function test_unknown_package_version_is_not_saved()
    {
        $result = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 2,
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame(
            array(
                'This Tolka package is version "2", and this site can save version 1 only. Run the migration again from this site. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['calls']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
    }

    public function test_existing_meta_other_than_tolka_keys_is_left_in_place()
    {
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_locale' => 'sv_SE',
            'custom_field' => 'keep me',
        );
        $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4] = array(
            'polylang_note' => 'do not remove',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:4',
                    'translations' => array('en' => 4),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('sv_SE', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_locale']);
        $this->assertSame('keep me', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['custom_field']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertSame('do not remove', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['polylang_note']);
        $this->assertSame('term:4', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_group']);
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_oversized_package_explains_the_database_limit()
    {
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array(
                        'en' => array(
                            'id' => 10,
                            'content' => str_repeat('a', 900000),
                        ),
                    ),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertSame(
            array(
                'WordPress could not save the Tolka migration package because it is too large for one database option, so existing Tolka language tags were left unchanged. Ask your host to raise the maximum packet size, then run the migration again. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
    }

    public function test_a_later_migration_updates_tags_and_leaves_omitted_posts()
    {
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'post:10',
            '_edit_lock' => 'keep',
        );
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][11] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:11',
        );
        $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'term:4',
            'description_note' => 'keep',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('en' => 10),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:8',
                    'translations' => array('sv' => 8),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_lang']);
        $this->assertSame('post:10', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_wmm_tolka_group']);
        $this->assertSame('keep', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]['_edit_lock']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][11]['_wmm_tolka_lang']);
        $this->assertSame('post:11', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][11]['_wmm_tolka_group']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_lang']);
        $this->assertSame('keep', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['description_note']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8]['_wmm_tolka_lang']);
        $this->assertSame('term:8', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][8]['_wmm_tolka_group']);
        $this->assertFunctionsStayInsideTolka();
    }

    public function test_ok_means_the_package_was_stored()
    {
        $saved = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:1',
                    'translations' => array(
                        'en' => 1,
                        'sv' => array('title' => 'Missing id'),
                    ),
                ),
            ),
        ));
        $this->assertTrue($saved['ok']);
        $this->assertNotEmpty($saved['errors']);
        $this->assertNotNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);

        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = null;
        $GLOBALS['wmm_tolka_schema_driver']['calls'] = array();

        $blocked = Tolka_Writer::write(array(
            'version' => 9,
            'posts' => array(
                array(
                    'group' => 'post:1',
                    'translations' => array('en' => 1),
                ),
            ),
        ));
        $this->assertFalse($blocked['ok']);
        $this->assertNotEmpty($blocked['errors']);
        $this->assertNull($GLOBALS['wmm_tolka_schema_driver']['stored_package']);

        foreach (array($saved, $blocked) as $result) {
            foreach ($result['errors'] as $error) {
                $this->assertMatchesRegularExpression('/^[A-Z].*\.$/', $error);
                $this->assertStringContainsString('Nothing was deleted, and your Polylang content is still in place.', $error);
            }
        }
    }

    public function test_term_description_and_parent_are_kept_with_the_language_tag()
    {
        $item = array(
            'taxonomy' => 'category',
            'group' => 'term:4',
            'translations' => array(
                'en' => array(
                    'id' => 4,
                    'name' => 'News',
                    'slug' => 'news',
                    'description' => '  Daily news  ',
                    'parent' => 0,
                ),
                'sv' => array(
                    'id' => 5,
                    'name' => 'Nyheter',
                    'slug' => 'nyheter',
                    'description' => '',
                    'parent' => 4,
                ),
            ),
        );

        $package = Tolka_Schema::package(array(
            'terms' => array(
                'items' => array($item),
                'errors' => array(),
            ),
        ));

        $this->assertSame(array($item), $package['terms']);

        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame($package, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_lang']);
        $this->assertSame('term:4', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][4]['_wmm_tolka_group']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][5]['_wmm_tolka_lang']);
        $this->assertSame('term:4', $GLOBALS['wmm_tolka_schema_driver']['term_meta'][5]['_wmm_tolka_group']);
    }

    public function test_menu_trees_are_stored_without_tagging_menu_terms()
    {
        $item = array(
            'location' => 'primary',
            'label' => 'Primary',
            'translations' => array(
                'en' => array(
                    'menu_id' => 20,
                    'name' => 'Main',
                    'slug' => 'main',
                    'link_count' => 2,
                    'items' => array(
                        array(
                            'id' => 50,
                            'parent' => 0,
                            'title' => 'Home',
                            'url' => 'https://example.com/',
                            'object' => 'page',
                            'object_id' => 10,
                            'type' => 'post_type',
                            'classes' => array('home'),
                            'children' => array(
                                array(
                                    'id' => 51,
                                    'parent' => 50,
                                    'title' => 'About',
                                    'url' => 'https://example.com/about',
                                    'object' => 'page',
                                    'object_id' => 11,
                                    'type' => 'post_type',
                                    'classes' => array(),
                                    'children' => array(),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        $package = Tolka_Schema::package(array(
            'menus' => array(
                'items' => array($item),
                'errors' => array('A menu warning that must not be stored.'),
            ),
        ));

        $this->assertSame(array($item), $package['menus']);
        $this->assertStringNotContainsString('A menu warning', json_encode($package));

        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['menus']);
        $this->assertSame($package, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_menu_envelope_is_stored_without_term_meta_or_exporter_errors()
    {
        $item = array(
            'location' => 'primary',
            'label' => 'Primary',
            'translations' => array(
                'en' => array(
                    'menu_id' => 4,
                    'name' => 'Main',
                    'slug' => 'main',
                    'kind' => 'menu',
                    'items' => array(
                        array(
                            'title' => '<b>Home</b>',
                            'url' => '/',
                            'object' => 'custom',
                            'object_id' => 0,
                            'children' => array(),
                        ),
                    ),
                    'link_count' => 1,
                ),
            ),
        );

        $result = Tolka_Writer::write(array(
            'menus' => array(
                'items' => array($item),
                'errors' => array('A menu warning that must not be stored.'),
            ),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array($item), $stored['menus']);
        $this->assertStringNotContainsString('must not be stored', json_encode($stored));
        $this->assertSame('<b>Home</b>', $stored['menus'][0]['translations']['en']['items'][0]['title']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_empty_menu_slot_is_stored_without_term_meta()
    {
        $menu = array(
            'location' => 'footer',
            'label' => 'Footer',
            'translations' => array(
                'en' => array(
                    'menu_id' => 0,
                    'name' => '',
                    'slug' => '',
                    'kind' => 'menu',
                    'items' => array(),
                    'link_count' => 0,
                ),
                'sv' => array(
                    'menu_id' => 0,
                    'name' => '',
                    'slug' => '',
                    'kind' => 'menu',
                    'items' => array(),
                    'link_count' => 0,
                ),
            ),
        );

        $result = Tolka_Writer::write(array(
            'menus' => array($menu),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['menus']);
        $this->assertSame($menu, $stored['menus'][0]);
        $this->assertSame(0, $stored['menus'][0]['translations']['en']['menu_id']);
        $this->assertSame(array(), $stored['menus'][0]['translations']['sv']['items']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
    }

    public function test_menu_ids_are_not_term_meta_when_a_post_is_tagged()
    {
        $GLOBALS['wmm_tolka_schema_driver']['term_meta'][20] = array(
            'polylang_note' => 'classic menu term',
        );
        $menu = array(
            'location' => 'primary',
            'label' => 'Primary',
            'translations' => array(
                'en' => array(
                    'menu_id' => 20,
                    'id' => 20,
                    'name' => "Main\0",
                    'slug' => 'main',
                    'kind' => 'menu',
                    'link_count' => 2,
                    'items' => array(
                        array(
                            'id' => 50,
                            'parent' => 0,
                            'title' => "Home\0 <span>page</span>",
                            'url' => 'https://example.com/',
                            'object' => 'page',
                            'object_id' => 10,
                            'type' => 'post_type',
                            'classes' => array('home'),
                            'children' => array(
                                array(
                                    'id' => 51,
                                    'parent' => 50,
                                    'title' => 'About',
                                    'url' => 'https://example.com/about',
                                    'object' => 'page',
                                    'object_id' => 11,
                                    'type' => 'post_type',
                                    'classes' => array(),
                                    'children' => array(),
                                    'switcher' => false,
                                ),
                            ),
                            'switcher' => false,
                        ),
                    ),
                ),
                'sv' => array(
                    'menu_id' => 77,
                    'name' => 'Navigation',
                    'slug' => 'navigation',
                    'kind' => 'navigation',
                    'link_count' => 0,
                    'items' => array(),
                ),
            ),
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'type' => 'page',
                    'translations' => array(
                        'en' => array(
                            'id' => 10,
                            'title' => '<em>Home</em>',
                            'content' => "<p>Body\0</p>",
                        ),
                    ),
                ),
            ),
            'menus' => array($menu),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $saved_menu = $stored['menus'][0];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame(1, $result['counts']['menus']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame('primary', $saved_menu['location']);
        $this->assertSame('Primary', $saved_menu['label']);
        $this->assertSame(20, $saved_menu['translations']['en']['menu_id']);
        $this->assertSame(20, $saved_menu['translations']['en']['id']);
        $this->assertSame('Main', $saved_menu['translations']['en']['name']);
        $this->assertSame('menu', $saved_menu['translations']['en']['kind']);
        $this->assertSame('Home <span>page</span>', $saved_menu['translations']['en']['items'][0]['title']);
        $this->assertSame(50, $saved_menu['translations']['en']['items'][0]['id']);
        $this->assertSame(10, $saved_menu['translations']['en']['items'][0]['object_id']);
        $this->assertSame('About', $saved_menu['translations']['en']['items'][0]['children'][0]['title']);
        $this->assertSame(51, $saved_menu['translations']['en']['items'][0]['children'][0]['id']);
        $this->assertSame(77, $saved_menu['translations']['sv']['menu_id']);
        $this->assertSame('navigation', $saved_menu['translations']['sv']['kind']);
        $this->assertSame('<em>Home</em>', $stored['posts'][0]['translations']['en']['title']);
        $this->assertSame('<p>Body</p>', $stored['posts'][0]['translations']['en']['content']);
        $this->assertSame(false, strpos(serialize($stored), "\0"));

        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertArrayNotHasKey(20, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(50, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(51, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(77, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(
            array('polylang_note' => 'classic menu term'),
            $GLOBALS['wmm_tolka_schema_driver']['term_meta'][20]
        );
        $this->assertArrayNotHasKey(77, $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(0, $this->call_count('update_term_meta'));

        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            if ($call['fn'] === 'update_post_meta') {
                $this->assertContains($call['key'], array('_wmm_tolka_lang', '_wmm_tolka_group'));
                $this->assertSame(10, $call['id']);
            }
            if ($call['fn'] === 'update_option') {
                $this->assertSame('wmm_tolka_package', $call['option']);
            }
        }

        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_schema_package_is_stored_unchanged()
    {
        $package = $this->sample_package();
        $result = Tolka_Writer::write($package);

        $this->assertTrue($result['ok']);
        $this->assertSame($package, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
    }

    public function test_language_tags_are_trimmed_and_content_whitespace_is_kept()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => ' post:21 ',
                    'type' => 'post',
                    'translations' => array(
                        ' en ' => array(
                            'id' => 21,
                            'title' => 'Hello',
                            'content' => '  keep  ',
                        ),
                    ),
                ),
            ),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][21]['_wmm_tolka_lang']);
        $this->assertSame('post:21', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][21]['_wmm_tolka_group']);
        $this->assertSame('post:21', $stored['posts'][0]['group']);
        $this->assertSame('  keep  ', $stored['posts'][0]['translations']['en']['content']);
    }

    public function test_repeated_language_keeps_the_last_copy()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:30',
                    'translations' => array(
                        'en' => 30,
                        ' en ' => 31,
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'Translation group "post:30" lists the language "en" more than once, so Tolka kept the last copy. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertArrayNotHasKey(30, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][31]['_wmm_tolka_lang']);
        $this->assertSame('post:30', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][31]['_wmm_tolka_group']);
    }

    public function test_empty_strings_still_save_zulu_time_and_integer_version()
    {
        $result = Tolka_Writer::write(array(
            'version' => 1,
            'generated_at' => '2024-05-06T07:08:09+00:00',
            'strings' => array(),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array(), $stored['strings']);
        $this->assertSame(0, $result['counts']['strings']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame('2024-05-06T07:08:09Z', $stored['generated_at']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_string_envelope_keeps_zulu_time_without_post_meta()
    {
        $item = array(
            'name' => 'footer',
            'string' => '<em>Hello</em>',
            'context' => 'theme',
            'id' => 55,
            'translations' => array(
                'sv' => '<em>Hej</em>',
            ),
        );

        $result = Tolka_Writer::write(array(
            'version' => '1',
            'generated_at' => '2024-05-06T07:08:09z',
            'strings' => array(
                'items' => array($item),
                'errors' => array('do not store'),
            ),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['strings']);
        $this->assertSame(0, $result['counts']['posts']);
        $this->assertSame(array($item), $stored['strings']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame('2024-05-06T07:08:09Z', $stored['generated_at']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($stored['generated_at']));
        $this->assertArrayNotHasKey(55, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertStringNotContainsString('do not store', json_encode($stored));
    }

    public function test_saved_strings_keep_zulu_time_and_integer_version()
    {
        $item = array(
            'name' => 'updated',
            'string' => '2024-05-06T07:08:09+00:00',
            'context' => 'theme',
            'id' => 99,
            'generated_at' => '1999-01-01T00:00:00+00:00',
            'translations' => array(
                'en' => 10,
                'sv' => '<time>1999-01-01T00:00:00+00:00</time>',
            ),
        );

        $result = Tolka_Writer::write(array(
            'format' => 'tolka-migration',
            'version' => 1.0,
            'generated_at' => "2024-05-06T07:08:09+00:00\0",
            'strings' => array($item),
            'posts' => array(
                array(
                    'group' => 'post:4',
                    'translations' => array(
                        'en' => array(
                            'id' => 4,
                            'title' => '<em>Home</em>',
                        ),
                    ),
                ),
            ),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $saved = $stored['strings'][0];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['strings']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame('2024-05-06T07:08:09Z', $stored['generated_at']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertTrue(Tolka_Schema::is_gmt_timestamp($stored['generated_at']));
        $this->assertSame(0, (new DateTimeImmutable($stored['generated_at']))->getOffset());
        $this->assertSame('2024-05-06T07:08:09+00:00', $saved['string']);
        $this->assertSame('1999-01-01T00:00:00+00:00', $saved['generated_at']);
        $this->assertSame(10, $saved['translations']['en']);
        $this->assertSame('<time>1999-01-01T00:00:00+00:00</time>', $saved['translations']['sv']);
        $this->assertSame(99, $saved['id']);
        $this->assertArrayNotHasKey(99, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(10, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:4',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][4]
        );
        $this->assertSame(2, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_string_items_are_stored_without_post_meta()
    {
        $package = Tolka_Schema::package(array(
            'strings' => array(
                'items' => array(
                    array(
                        'name' => "footer\0",
                        'string' => "Read <em>more</em>\0",
                        'context' => 'theme',
                        'id' => 99,
                        'translations' => array(
                            'en' => '',
                            'sv' => "Läs <em>mer</em>\0",
                        ),
                    ),
                    array(
                        'name' => '',
                        'string' => 'Hello',
                        'context' => '',
                        'translations' => array(
                            'en' => 'Hello',
                            'sv' => array(
                                'id' => 88,
                                'title' => 'Do not tag',
                                'content' => '<p>Nope</p>',
                            ),
                        ),
                    ),
                ),
                'errors' => array('A string warning that must not be stored.'),
            ),
            'posts' => array(
                'items' => array(
                    array(
                        'group' => 'post:10',
                        'type' => 'page',
                        'translations' => array(
                            'en' => array(
                                'id' => 10,
                                'title' => '<em>Home</em>',
                                'content' => '<p>Body</p>',
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $this->assertSame(1, $package['version']);
        $this->assertTrue(is_int($package['version']));
        $this->assertSame('tolka-migration', $package['format']);
        $this->assertSame('Read <em>more</em>', $package['strings'][0]['string']);
        $this->assertSame('footer', $package['strings'][0]['name']);
        $this->assertSame('theme', $package['strings'][0]['context']);
        $this->assertSame(99, $package['strings'][0]['id']);
        $this->assertSame('', $package['strings'][0]['translations']['en']);
        $this->assertSame('Läs <em>mer</em>', $package['strings'][0]['translations']['sv']);
        $this->assertSame(88, $package['strings'][1]['translations']['sv']['id']);
        $this->assertSame('<p>Nope</p>', $package['strings'][1]['translations']['sv']['content']);
        $this->assertStringNotContainsString('must not be stored', json_encode($package));
        $this->assertSame(false, strpos(serialize($package), "\0"));

        $package['version'] = '1.0';
        $result = Tolka_Writer::write($package);
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(2, $result['counts']['strings']);
        $this->assertSame(1, $result['counts']['posts']);
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame($package['strings'], $stored['strings']);
        $this->assertSame('<em>Home</em>', $stored['posts'][0]['translations']['en']['title']);
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertArrayNotHasKey(99, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertArrayNotHasKey(88, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(2, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));

        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            if ($call['fn'] === 'update_post_meta') {
                $this->assertSame(10, $call['id']);
                $this->assertContains($call['key'], array('_wmm_tolka_lang', '_wmm_tolka_group'));
            }
            if ($call['fn'] === 'update_option') {
                $this->assertSame('wmm_tolka_package', $call['option']);
            }
        }

        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_string_translation_keys_are_not_treated_as_post_ids()
    {
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_locale' => 'keep',
        );
        $item = array(
            'name' => 'count',
            'string' => 'One',
            'context' => 'theme',
            'translations' => array(
                '10' => '<b>Ett</b>',
                'en' => 'One',
            ),
        );

        $result = Tolka_Writer::write(array(
            'version' => 1,
            'strings' => array($item),
            'posts' => array(
                array(
                    'group' => 'post:4',
                    'translations' => array('sv' => 4),
                ),
            ),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array($item), $stored['strings']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame(
            array('_locale' => 'keep'),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][4]['_wmm_tolka_lang']);
        $this->assertSame('post:4', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][4]['_wmm_tolka_group']);
        $this->assertSame(2, count($GLOBALS['wmm_tolka_schema_driver']['post_meta'][4]));

        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            if ($call['fn'] === 'update_post_meta') {
                $this->assertSame(4, $call['id']);
                $this->assertContains($call['key'], array('_wmm_tolka_lang', '_wmm_tolka_group'));
            }
        }
    }

    public function test_string_only_package_writes_no_post_meta()
    {
        $item = array(
            'name' => 'widget-title',
            'string' => '<span>Welcome</span>',
            'context' => 'widget',
            'translations' => array(
                'en' => '<span>Welcome</span>',
                'sv' => '<span>Välkommen</span>',
            ),
        );

        $result = Tolka_Writer::write(array(
            'version' => 1,
            'strings' => array(
                'items' => array($item),
                'errors' => array('Skip this warning.'),
            ),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(1, $result['counts']['strings']);
        $this->assertSame(0, $result['counts']['posts']);
        $this->assertSame(array($item), $stored['strings']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame('tolka-migration', $stored['format']);
        $this->assertSame('Z', substr($stored['generated_at'], -1));
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['term_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(0, $this->call_count('update_term_meta'));
        $this->assertStringNotContainsString('Skip this warning', json_encode($stored));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_string_object_is_stored_as_package_data_without_post_meta()
    {
        $item = new stdClass();
        $item->name = 'title';
        $item->string = '<b>Hi</b>';
        $item->context = 'theme';
        $item->id = 42;
        $item->translations = array('sv' => '<b>Hej</b>');

        $result = Tolka_Writer::write(array(
            'version' => '1',
            'strings' => array($item),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array(
                'name' => 'title',
                'string' => '<b>Hi</b>',
                'context' => 'theme',
                'id' => 42,
                'translations' => array('sv' => '<b>Hej</b>'),
            ),
            $stored['strings'][0]
        );
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertArrayNotHasKey(42, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_string_whitespace_and_html_stay_in_the_package()
    {
        $item = array(
            'name' => '  button  ',
            'string' => "  <a href=\"/go\">Go</a>\n",
            'context' => ' theme ',
            'translations' => array(
                ' en ' => "  <a href=\"/sv\">Gå</a>\n",
            ),
        );

        $result = Tolka_Writer::write(array(
            'version' => 1,
            'strings' => array($item),
        ));
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $saved = $stored['strings'][0];

        $this->assertTrue($result['ok']);
        $this->assertSame('  button  ', $saved['name']);
        $this->assertSame("  <a href=\"/go\">Go</a>\n", $saved['string']);
        $this->assertSame(' theme ', $saved['context']);
        $this->assertSame("  <a href=\"/sv\">Gå</a>\n", $saved['translations']['en']);
        $this->assertArrayNotHasKey(' en ', $saved['translations']);
        $this->assertSame(1, $stored['version']);
        $this->assertTrue(is_int($stored['version']));
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_unsaved_string_package_does_not_write_post_meta()
    {
        $previous = array(
            'format' => 'tolka-migration',
            'version' => 1,
            'generated_at' => '2024-05-06T07:08:09Z',
            'languages' => array(),
            'posts' => array(),
            'terms' => array(),
            'strings' => array(),
            'menus' => array(),
            'media' => array(),
        );
        $GLOBALS['wmm_tolka_schema_driver']['stored_package'] = $previous;
        $GLOBALS['wmm_tolka_schema_driver']['option_result'] = false;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10] = array(
            '_wmm_tolka_lang' => 'en',
            '_wmm_tolka_group' => 'post:10',
        );

        $result = Tolka_Writer::write(array(
            'version' => 1,
            'strings' => array(
                array(
                    'name' => 'footer',
                    'string' => '<em>Hello</em>',
                    'context' => 'theme',
                    'id' => 99,
                    'translations' => array('sv' => '<em>Hej</em>'),
                ),
            ),
            'posts' => array(
                array(
                    'group' => 'post:10',
                    'translations' => array('sv' => 10),
                ),
            ),
        ));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['strings']);
        $this->assertSame($previous, $GLOBALS['wmm_tolka_schema_driver']['stored_package']);
        $this->assertSame(1, $GLOBALS['wmm_tolka_schema_driver']['stored_package']['version']);
        $this->assertTrue(is_int($GLOBALS['wmm_tolka_schema_driver']['stored_package']['version']));
        $this->assertSame(
            array(
                '_wmm_tolka_lang' => 'en',
                '_wmm_tolka_group' => 'post:10',
            ),
            $GLOBALS['wmm_tolka_schema_driver']['post_meta'][10]
        );
        $this->assertArrayNotHasKey(99, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_invalid_byte_between_html_tags_is_removed()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:31',
                    'translations' => array(
                        'en' => array(
                            'id' => 31,
                            'title' => "Head\xFF<em>line</em>",
                            'content' => "<p>Before</p>\xFF<p>After</p>\0<em>End</em>",
                        ),
                    ),
                ),
            ),
            'strings' => array(
                array(
                    'name' => 'note',
                    'string' => "Go\xFF <strong>on</strong>\0",
                    'context' => 'theme',
                    'translations' => array(),
                ),
            ),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $post = $stored['posts'][0]['translations']['en'];

        $this->assertTrue($result['ok']);
        $this->assertSame('Head<em>line</em>', $post['title']);
        $this->assertSame('<p>Before</p><p>After</p><em>End</em>', $post['content']);
        $this->assertSame('Go <strong>on</strong>', $stored['strings'][0]['string']);
        $this->assertSame(false, strpos(serialize($stored), "\0"));
        $this->assertSame(false, strpos($post['content'], "\xFF"));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_clean_html_content_is_stored_unchanged()
    {
        $content = "<article>\n<h1>Title</h1>\n<p>Hello &amp; welcome</p>\n<ul><li>One</li></ul>\n<!-- comment -->\n</article>";
        $title = '<span class="kicker">Hello</span>';
        $string = '<a href="https://example.com/?a=1&amp;b=2">Go</a>';
        $package = Tolka_Schema::package(array(
            'posts' => array(
                'items' => array(
                    array(
                        'group' => 'post:30',
                        'type' => 'post',
                        'translations' => array(
                            'en' => array(
                                'id' => 30,
                                'title' => $title,
                                'content' => $content,
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
            'strings' => array(
                'items' => array(
                    array(
                        'name' => 'link',
                        'string' => $string,
                        'context' => 'theme',
                        'translations' => array('sv' => $string),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $this->assertSame($content, $package['posts'][0]['translations']['en']['content']);
        $this->assertSame($title, $package['posts'][0]['translations']['en']['title']);
        $this->assertSame($string, $package['strings'][0]['string']);

        $result = Tolka_Writer::write($package);
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];

        $this->assertTrue($result['ok']);
        $this->assertSame($content, $stored['posts'][0]['translations']['en']['content']);
        $this->assertSame($title, $stored['posts'][0]['translations']['en']['title']);
        $this->assertSame($string, $stored['strings'][0]['string']);
        $this->assertSame($string, $stored['strings'][0]['translations']['sv']);
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_null_bytes_inside_html_do_not_drop_the_tags()
    {
        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:21',
                    'translations' => array(
                        'en' => array(
                            'id' => 21,
                            'title' => "See\0 <a href=\"https://example.com/a\0b\">Link</a>",
                            'content' => "<div data-x=\"a\0b\">Hi\0</div>",
                        ),
                    ),
                ),
            ),
            'terms' => array(
                array(
                    'group' => 'term:6',
                    'translations' => array(
                        'en' => array(
                            'id' => 6,
                            'name' => "News\0 <em>desk</em>",
                            'slug' => "news\0-desk",
                            'description' => "<p>About\0</p>",
                        ),
                    ),
                ),
            ),
            'menus' => array(
                array(
                    'location' => 'primary',
                    'translations' => array(
                        'en' => array(
                            'menu_id' => 30,
                            'name' => "Main\0",
                            'items' => array(
                                array(
                                    'title' => "Home\0 <span>page</span>",
                                    'url' => "https://example.com/\0home",
                                    'object' => 'page',
                                    'object_id' => 21,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $post = $stored['posts'][0]['translations']['en'];
        $term = $stored['terms'][0]['translations']['en'];
        $item = $stored['menus'][0]['translations']['en']['items'][0];

        $this->assertTrue($result['ok']);
        $this->assertSame('See <a href="https://example.com/ab">Link</a>', $post['title']);
        $this->assertSame('<div data-x="ab">Hi</div>', $post['content']);
        $this->assertSame('News <em>desk</em>', $term['name']);
        $this->assertSame('news-desk', $term['slug']);
        $this->assertSame('<p>About</p>', $term['description']);
        $this->assertSame('Main', $stored['menus'][0]['translations']['en']['name']);
        $this->assertSame('Home <span>page</span>', $item['title']);
        $this->assertSame('https://example.com/home', $item['url']);
        $this->assertSame(false, strpos(serialize($stored), "\0"));
        $this->assertSame(
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'],
            $GLOBALS['wmm_tolka_schema_driver']['polylang_option']
        );
    }

    public function test_html_cleanup_leaves_polylang_rows_in_place()
    {
        $polylang = array(
            'default_lang' => 'sv',
            'force_lang' => 0,
            'version' => '3.6.2',
        );
        $GLOBALS['wmm_tolka_schema_driver']['polylang_option'] = $polylang;
        $GLOBALS['wmm_tolka_schema_driver']['polylang_option_before'] = $polylang;
        $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14] = array(
            '_pll_lang' => 'sv',
            '_wmm_tolka_lang' => 'sv',
            '_wmm_tolka_group' => 'post:14',
        );

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:14',
                    'translations' => array(
                        'en' => array(
                            'id' => 14,
                            'title' => "A\0 <em>title</em>",
                            'content' => "<p>One\0</p><!-- keep -->",
                        ),
                    ),
                ),
            ),
            'strings' => array(
                array(
                    'name' => 'label',
                    'string' => "Hello\0\0 <strong>there</strong>",
                    'context' => "wid\0get",
                    'translations' => array(
                        'sv' => "Hej\0 <strong>där</strong>",
                    ),
                ),
            ),
        ));

        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $post = $stored['posts'][0]['translations']['en'];

        $this->assertTrue($result['ok']);
        $this->assertSame('A <em>title</em>', $post['title']);
        $this->assertSame('<p>One</p><!-- keep -->', $post['content']);
        $this->assertSame('Hello <strong>there</strong>', $stored['strings'][0]['string']);
        $this->assertSame('widget', $stored['strings'][0]['context']);
        $this->assertSame('Hej <strong>där</strong>', $stored['strings'][0]['translations']['sv']);
        $this->assertSame($polylang, $GLOBALS['wmm_tolka_schema_driver']['polylang_option']);
        $this->assertSame('sv', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]['_pll_lang']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]['_wmm_tolka_lang']);
        $this->assertSame('post:14', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]['_wmm_tolka_group']);
        $this->assertSame(false, strpos(serialize($stored), "\0"));
    }

    public function test_saved_package_keeps_html_and_drops_null_bytes()
    {
        $polylang = $GLOBALS['wmm_tolka_schema_driver']['polylang_option'];
        $content = "<!-- note -->\0<p class=\"x\">A &amp; B</p>\0<script>keep()</script>";
        $title = "Ti\0tle <em>Keep</em>";
        $string = "Line\0 <strong>One</strong>";
        $translated = "Hej\0 <em>två</em>";
        $media_title = "Pho\0to <b>x</b>";

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:14',
                    'type' => 'post',
                    'translations' => array(
                        'en' => array(
                            'id' => 14,
                            'title' => $title,
                            'excerpt' => "Lead\0 <br/>",
                            'content' => $content,
                        ),
                    ),
                ),
            ),
            'strings' => array(
                array(
                    'name' => "greet\0ing",
                    'string' => $string,
                    'context' => 'theme',
                    'translations' => array(
                        'sv' => $translated,
                    ),
                ),
            ),
            'media' => array(
                array(
                    'attachment_id' => 80,
                    'file' => "photo\0.jpg",
                    'alt' => array('en' => "alt\0 <i>text</i>"),
                    'title' => array('en' => $media_title),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $post = $stored['posts'][0]['translations']['en'];
        $saved_string = $stored['strings'][0];
        $media = $stored['media'][0];

        $this->assertSame('<!-- note --><p class="x">A &amp; B</p><script>keep()</script>', $post['content']);
        $this->assertSame('Title <em>Keep</em>', $post['title']);
        $this->assertSame('Lead <br/>', $post['excerpt']);
        $this->assertSame('greeting', $saved_string['name']);
        $this->assertSame('Line <strong>One</strong>', $saved_string['string']);
        $this->assertSame('Hej <em>två</em>', $saved_string['translations']['sv']);
        $this->assertSame('photo.jpg', $media['file']);
        $this->assertSame('alt <i>text</i>', $media['alt']['en']);
        $this->assertSame('Photo <b>x</b>', $media['title']['en']);
        $this->assertSame(false, strpos(serialize($stored), "\0"));
        $this->assertStringContainsString('<script>keep()</script>', $post['content']);
        $this->assertStringContainsString('<em>Keep</em>', $post['title']);
        $this->assertStringContainsString('<strong>One</strong>', $saved_string['string']);
        $this->assertSame($polylang, $GLOBALS['wmm_tolka_schema_driver']['polylang_option']);
        $this->assertSame('en', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]['_wmm_tolka_lang']);
        $this->assertSame('post:14', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]['_wmm_tolka_group']);
        $this->assertArrayNotHasKey('_pll_lang', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][14]);
    }

    public function test_database_unsafe_text_is_removed_before_save()
    {
        $package = Tolka_Schema::package(array(
            'posts' => array(
                'items' => array(
                    array(
                        'group' => "post:10\0",
                        'type' => 'post',
                        'translations' => array(
                            "sv\0" => array(
                                'id' => 10,
                                'title' => 'Café',
                                'content' => "a\0b\xFFcafé <em>keep</em>",
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
        ));

        $saved = $package['posts'][0];
        $this->assertSame('post:10', $saved['group']);
        $this->assertSame('Café', $saved['translations']['sv']['title']);
        $this->assertSame('abcafé <em>keep</em>', $saved['translations']['sv']['content']);

        $result = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => "post:12\0",
                    'translations' => array(
                        "de\xFF" => 12,
                    ),
                ),
            ),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('de', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12]['_wmm_tolka_lang']);
        $this->assertSame('post:12', $GLOBALS['wmm_tolka_schema_driver']['post_meta'][12]['_wmm_tolka_group']);
        $stored = $GLOBALS['wmm_tolka_schema_driver']['stored_package'];
        $this->assertSame('post:12', $stored['posts'][0]['group']);
        $this->assertArrayHasKey('de', $stored['posts'][0]['translations']);
        $this->assertStringNotContainsString("\0", serialize($stored));

        $skipped = Tolka_Writer::write(array(
            'posts' => array(
                array(
                    'group' => 'post:13',
                    'translations' => array(
                        "\xFF" => 13,
                    ),
                ),
            ),
        ));

        $this->assertTrue($skipped['ok']);
        $this->assertSame(
            array(
                'A post translation in group "post:13" had a language code WordPress cannot store, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $skipped['errors']
        );
        $this->assertArrayNotHasKey(13, $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
    }

    public function test_source_does_not_delete_polylang_data()
    {
        $directory = dirname(__DIR__, 2) . '/includes/tolka/';
        $writer = file_get_contents($directory . 'class-tolka-writer.php');
        $schema = file_get_contents($directory . 'class-tolka-schema.php');

        $this->assertNotFalse($writer);
        $this->assertNotFalse($schema);
        $this->assertSame(1, preg_match_all('/\bupdate_option\s*\(/', $writer));
        $this->assertStringContainsString("'wmm_tolka_package'", $writer);
        $this->assertStringContainsString("'_wmm_tolka_lang'", $writer);
        $this->assertStringContainsString("'_wmm_tolka_group'", $writer);
        preg_match_all("/'(_wmm[^']*)'/", $writer, $meta_keys);
        $this->assertSame(
            array('_wmm_tolka_lang', '_wmm_tolka_group'),
            array_values(array_unique($meta_keys[1]))
        );
        $this->assertSame(
            0,
            preg_match(
                '/\b(delete_option|delete_post_meta|delete_term_meta|delete_metadata|wp_delete_post|wp_delete_term|wpdb)\b/',
                $writer . $schema
            )
        );
        $this->assertSame(0, preg_match('/\bupdate_option\s*\(/', $schema));
        $this->assertStringNotContainsString('strip_tags', $schema);
        $this->assertSame(0, preg_match('/[\'"]polylang[\'"]/', strtolower($writer . $schema)));
        $this->assertStringNotContainsString('pll_', strtolower($writer . $schema));
    }

    /**
     * A package that uses every section once.
     *
     * @return array
     */
    private function sample_package()
    {
        return Tolka_Schema::package(array(
            'languages' => array(
                'items' => array(
                    array(
                        'slug' => 'en',
                        'name' => 'English',
                        'locale' => 'en_US',
                        'is_default' => true,
                        'term_id' => 3,
                        'flag' => 'us',
                    ),
                ),
                'errors' => array(),
            ),
            'posts' => array(
                'items' => array(
                    array(
                        'group' => 'post:10',
                        'type' => 'page',
                        'translations' => array(
                            'en' => array(
                                'id' => 10,
                                'title' => 'Hello',
                                'slug' => 'hello',
                                'status' => 'publish',
                                'excerpt' => '',
                                'content' => 'Body',
                            ),
                            'sv' => array(
                                'id' => 11,
                                'title' => 'Hej',
                                'slug' => 'hej',
                                'status' => 'publish',
                                'excerpt' => '',
                                'content' => 'Text',
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
            'terms' => array(
                'items' => array(
                    array(
                        'taxonomy' => 'category',
                        'group' => 'term:4',
                        'translations' => array(
                            'en' => array(
                                'id' => 4,
                                'name' => 'News',
                                'slug' => 'news',
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
            'strings' => array(
                'items' => array(
                    array(
                        'name' => 'title',
                        'string' => 'Hello',
                        'context' => 'theme',
                        'translations' => array('sv' => 'Hej'),
                    ),
                ),
                'errors' => array(),
            ),
            'menus' => array(
                'items' => array(
                    array(
                        'location' => 'primary',
                        'translations' => array(
                            'en' => array(
                                'menu_id' => 20,
                                'name' => 'Main',
                                'items' => array(),
                            ),
                        ),
                    ),
                ),
                'errors' => array(),
            ),
            'media' => array(
                'items' => array(
                    array(
                        'attachment_id' => 80,
                        'file' => 'photo.jpg',
                        'alt' => array('en' => 'Photo'),
                        'title' => array('en' => 'Photo'),
                    ),
                ),
                'errors' => array(),
            ),
        ));
    }

    /**
     * Count stand-in calls by function name.
     *
     * @param string $function Function name.
     * @return int
     */
    private function call_count($function)
    {
        $count = 0;
        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            if ($call['fn'] === $function) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The writer may store only the Tolka package and the two Tolka meta keys.
     */
    private function assertFunctionsStayInsideTolka()
    {
        $allowed = array(
            'update_option',
            'get_option',
            'update_post_meta',
            'get_post_meta',
            'update_term_meta',
            'get_term_meta',
        );

        foreach ($GLOBALS['wmm_tolka_schema_driver']['calls'] as $call) {
            $this->assertContains($call['fn'], $allowed);
            if ($call['fn'] === 'update_option' || $call['fn'] === 'get_option') {
                $this->assertSame('wmm_tolka_package', $call['option']);
            }
            if (isset($call['key'])) {
                $this->assertContains($call['key'], array('_wmm_tolka_lang', '_wmm_tolka_group'));
            }
        }
    }
}
