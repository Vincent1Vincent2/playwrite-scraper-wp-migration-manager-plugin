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
        if (!class_exists(Tolka_Schema::class) || !class_exists(Tolka_Writer::class)) {
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
        );
    }

    /**
     * Drop the stand-in so later tests use WordPress when it is loaded.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['wmm_tolka_schema_driver']);
    }

    public function test_missing_sections_become_empty_item_lists()
    {
        $package = Tolka_Schema::package(array());

        $this->assertSame('tolka-migration', $package['format']);
        $this->assertSame(1, $package['version']);
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
        $stamp = strtotime($package['generated_at']);
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
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

        $this->assertFalse($result['ok']);
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

        $this->assertFalse($result['ok']);
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
        $this->assertSame(5, $result['counts']['posts']);
        $this->assertSame(0, $result['counts']['terms']);
        $this->assertSame(
            array(
                'One post entry in the Tolka package was incomplete, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'One post entry in the Tolka package has translations Tolka cannot read, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'A post translation has no group id, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'A post translation in group "post:9" has no language code, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The terms part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
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

        $this->assertFalse($result['ok']);
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
                'WordPress could not save the Tolka migration package. Run the migration again. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        $this->assertSame(array(), $GLOBALS['wmm_tolka_schema_driver']['post_meta']);
        $this->assertSame(0, $this->call_count('update_post_meta'));
    }

    public function test_missing_option_storage_is_a_clear_error()
    {
        $GLOBALS['wmm_tolka_schema_driver']['has_update_option'] = false;

        $result = Tolka_Writer::write(Tolka_Schema::package(array()));

        $this->assertFalse($result['ok']);
        $this->assertSame(
            array(
                'WordPress could not save the Tolka migration package because this site cannot store options. Nothing was deleted, and your Polylang content is still in place.',
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

        $this->assertFalse($result['ok']);
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

        $this->assertFalse($result['ok']);
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

        $this->assertFalse($result['ok']);
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
        $this->assertSame(
            array(
                'The languages part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The posts part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The terms part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The strings part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The menus part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
                'The media part of the Tolka package was not a list, so Tolka skipped it. Nothing was deleted, and your Polylang content is still in place.',
            ),
            $result['errors']
        );
        foreach ($result['counts'] as $count) {
            $this->assertSame(0, $count);
        }
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
                'WordPress could not save the Tolka migration package because this site cannot store options. Nothing was deleted, and your Polylang content is still in place.',
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
                'WordPress could not save the Tolka migration package. It is too large for one database option. Ask your host to raise the maximum packet size, then run the migration again. Nothing was deleted, and your Polylang content is still in place.',
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

    public function test_ok_is_false_only_when_a_sentence_explains_why()
    {
        $cases = array(
            Tolka_Schema::package(array()),
            array(
                'posts' => array(
                    array(
                        'group' => 'post:1',
                        'type' => 'post',
                        'translations' => array(
                            'en' => array(
                                'id' => 1,
                                'title' => 'Hello',
                                'slug' => 'hello',
                                'status' => 'publish',
                                'excerpt' => '',
                                'content' => 'Body',
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'version' => 9,
                'posts' => array(
                    array(
                        'group' => 'post:1',
                        'translations' => array('en' => 1),
                    ),
                ),
            ),
            array('posts' => 'nope'),
        );

        foreach ($cases as $package) {
            $result = Tolka_Writer::write($package);
            $this->assertSame($result['errors'] === array(), $result['ok']);
            foreach ($result['errors'] as $error) {
                $this->assertMatchesRegularExpression('/^[A-Z].*\.$/', $error);
                $this->assertStringContainsString('Nothing was deleted, and your Polylang content is still in place.', $error);
            }
        }
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

        $this->assertFalse($skipped['ok']);
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
        $this->assertSame(
            0,
            preg_match(
                '/\b(delete_option|delete_post_meta|delete_term_meta|delete_metadata|wp_delete_post|wp_delete_term|wpdb)\b/',
                $writer . $schema
            )
        );
        $this->assertSame(0, preg_match('/\bupdate_option\s*\(/', $schema));
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
