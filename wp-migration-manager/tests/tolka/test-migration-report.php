<?php

/**
 * Migration Manager - Tolka migration report tests
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

use PHPUnit\Framework\TestCase;

class Test_Migration_Report extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/');
        }

        if (!class_exists(__NAMESPACE__ . '\\Migration_Report')) {
            $file = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(Migration_Report::class)) {
            $this->markTestSkipped('Migration_Report is not loaded.');
        }
    }

    public function test_complete_summary_uses_plurals()
    {
        $report = $this->format_run(array(
            'languages' => 2,
            'posts' => 1,
            'terms' => 0,
            'strings' => 10,
            'menus' => 1,
            'media' => 2,
        ));

        $this->assertTrue($report['ok']);
        $this->assertSame('The migration is complete.', $report['title']);
        $this->assertSame(
            'Your Polylang content is packaged for Tolka: 2 languages, 1 post, 10 strings, 1 menu, and 2 media items. No terms were found. The package is saved on this site. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('2', $this->line($report, 'Languages'));
        $this->assertSame('1', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Terms'));
        $this->assertSame('2', $this->line($report, 'Media'));
        $this->assertSame('Saved on this site', $this->line($report, 'Tolka package'));
        $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));
        $this->assertArrayNotHasKey('Next', $this->line_map($report));
    }

    public function test_singular_counts()
    {
        $report = $this->format_run(array(
            'languages' => 1,
            'posts' => 1,
            'terms' => 1,
            'strings' => 1,
            'menus' => 1,
            'media' => 1,
        ));

        $this->assertSame(
            'Your Polylang content is packaged for Tolka: 1 language, 1 post, 1 term, 1 string, 1 menu, and 1 media item. The package is saved on this site. Polylang data was left in place.',
            $report['summary']
        );
    }

    public function test_empty_state_tells_the_owner_nothing_was_found()
    {
        $report = $this->format_run(array(
            'languages' => 0,
            'posts' => 0,
            'terms' => 0,
            'strings' => 0,
            'menus' => 0,
            'media' => 0,
        ));

        $this->assertTrue($report['ok']);
        $this->assertSame('Nothing was found on this site.', $report['title']);
        $this->assertSame(
            'Nothing was found on this site. No languages, posts, terms, strings, menus, or media were found. If this site should have translations, check that Polylang has languages, then run the migration again. An empty Tolka package was saved on this site. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('0', $this->line($report, 'Posts'));
        $this->assertSame('Saved, but it is empty', $this->line($report, 'Tolka package'));
        $this->assertSame(
            'If this site should have translations, check that Polylang has languages, then run the migration again.',
            $this->line($report, 'Next')
        );
        $this->assertStringContainsString('Nothing was found', $report['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
    }

    public function test_clean_run_says_polylang_data_was_left_in_place()
    {
        $report = $this->format_run(array(
            'languages' => 2,
            'posts' => 4,
            'terms' => 1,
        ));

        $this->assertTrue($report['ok']);
        $this->assertSame('The migration is complete.', $report['title']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));

        $omitted = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 1,
                'posts' => 2,
            ),
            'write' => array('ok' => true),
        ));
        $this->assertTrue($omitted['ok']);
        $this->assertSame('The migration is complete.', $omitted['title']);
        $this->assertStringContainsString('Polylang data was left in place.', $omitted['summary']);
    }

    public function test_empty_site_with_omitted_counts_says_nothing_was_found()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'write' => array('ok' => true),
        ));

        $this->assertTrue($report['ok']);
        $this->assertSame('Nothing was found on this site.', $report['title']);
        $this->assertStringContainsString('Nothing was found', $report['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertSame('0', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Terms'));
        $this->assertSame('0', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Menus'));
        $this->assertSame('0', $this->line($report, 'Media'));
    }

    public function test_several_missing_classes_are_named_in_one_sentence()
    {
        $report = Migration_Report::format(array(
            'missing' => array(
                'WMM\\Tolka\\Polylang_Languages',
                'WMM\\Tolka\\Polylang_Posts',
                'WMM\\Tolka\\Polylang_Terms',
                'WMM\\Tolka\\Tolka_Writer',
            ),
        ));

        $this->assertSame(1, preg_match_all(
            '/Required classes are missing, so those parts were skipped: ([^.]+)\./',
            $report['summary'],
            $matches
        ));
        $sentence = $matches[1][0];
        $this->assertStringContainsString('Polylang_Languages (languages)', $sentence);
        $this->assertStringContainsString('Polylang_Posts (posts)', $sentence);
        $this->assertStringContainsString('Polylang_Terms (terms)', $sentence);
        $this->assertStringContainsString('Tolka_Writer (saving)', $sentence);
        $this->assertStringNotContainsString('.', $sentence);
        $this->assertSame(
            'Required classes are missing, so those parts were skipped: Polylang_Languages (languages), Polylang_Posts (posts), Polylang_Terms (terms), and Tolka_Writer (saving).',
            $matches[0][0]
        );
        $this->assertSame($matches[0][0], $this->line($report, 'Missing classes'));
    }

    public function test_failure_when_polylang_is_inactive()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array(
                'languages' => 0,
                'posts' => 0,
                'terms' => 0,
                'strings' => 0,
                'menus' => 0,
                'media' => 0,
            ),
            'write' => array(
                'ok' => false,
                'errors' => array(),
            ),
            'errors' => array('Polylang is not active'),
            'missing' => array(),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertSame(
            'The migration did not finish, so the Tolka package was not saved. Polylang is not active. Activate Polylang, then run the migration again from Migrate to Tolka. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
        $this->assertSame('Polylang is not active.', $this->line($report, 'Problem'));
        $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));
    }

    public function test_missing_steps_are_named_in_plain_language()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array(),
            'errors' => array(),
            'missing' => array(
                'WMM\\Tolka\\Polylang_Languages',
                'WMM\\Tolka\\Tolka_Writer',
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame(
            'The migration did not finish, so the Tolka package was not saved. Required classes are missing, so those parts were skipped: Polylang_Languages (languages) and Tolka_Writer (saving). Update the plugin, then run the migration again from Migrate to Tolka. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertSame('0', $this->line($report, 'Posts'));
        $missing = $this->lines_named($report, 'Missing step');
        $this->assertSame(
            array(
                'Polylang_Languages (languages) is missing, so that part was skipped.',
                'Tolka_Writer (saving) is missing, so that part was skipped.',
            ),
            $missing
        );
    }

    public function test_two_problems_point_at_the_list()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array(
                'languages' => 2,
                'posts' => 4,
            ),
            'write' => array('ok' => false, 'errors' => array('could not read strings')),
            'errors' => array('Could not read menus.'),
            'missing' => array(),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame(
            'The migration did not finish. This site has 2 languages and 4 posts, but that content was not saved into a Tolka package. No terms, strings, menus, or media items were found. Could not read menus. 1 more problem is listed below. Run the migration again from Migrate to Tolka. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame(
            array(
                'Could not read menus.',
                'Could not read strings.',
            ),
            $this->lines_named($report, 'Problem')
        );
    }

    public function test_saved_package_with_a_problem_does_not_claim_a_clean_finish()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 3,
                'terms' => 0,
                'strings' => 0,
                'menus' => 0,
                'media' => 0,
            ),
            'write' => array('ok' => true, 'errors' => array()),
            'errors' => array('One menu had no items'),
            'missing' => array(),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertSame(
            'The migration finished with problems. Your Polylang content is packaged for Tolka: 2 languages and 3 posts. No terms, strings, menus, or media items were found. The package is saved on this site. One menu had no items. Review the problems below, then run the migration again if you want to retry. Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('Saved on this site', $this->line($report, 'Tolka package'));
    }

    public function test_saved_package_with_section_errors_does_not_use_the_clean_title()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 4,
                'menus' => 1,
            ),
            'package' => array(
                'posts' => array(
                    'items' => array(
                        array('group' => 'post:1'),
                        array('group' => 'post:2'),
                        array('group' => 'post:3'),
                        array('group' => 'post:4'),
                    ),
                    'errors' => array('One post had no title', 4, array('nested')),
                ),
                'menus' => array(
                    'items' => array(array('name' => 'Primary')),
                    'errors' => array('A menu item was skipped'),
                ),
            ),
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 4,
                    'menus' => 1,
                ),
                'deleted_polylang' => false,
                'errors' => array(
                    'languages' => array('A flag file was missing'),
                ),
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['summary']);
        $this->assertStringContainsString('The package is saved on this site.', $report['summary']);
        $this->assertStringContainsString('Languages: A flag file was missing.', $report['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));
        $this->assertSame('Saved on this site', $this->line($report, 'Tolka package'));
        $this->assert_section_order($report);
        $this->assertSame(
            array(
                'Languages: A flag file was missing.',
                'Posts: One post had no title.',
                'Menus: A menu item was skipped.',
            ),
            $this->lines_named($report, 'Problem')
        );
    }

    public function test_run_section_errors_block_the_clean_title_and_keep_polylang_data()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('strings' => 2),
            'strings' => array(
                'items' => array(
                    array('name' => 'footer', 'string' => 'Hello'),
                ),
                'errors' => array('One string had no translation'),
            ),
            'write' => array(
                'ok' => true,
                'counts' => array('strings' => 2),
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['summary']);
        $this->assertStringContainsString('The package is saved on this site.', $report['summary']);
        $this->assertStringContainsString('Strings: One string had no translation.', $report['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));
    }

    public function test_summary_says_polylang_data_was_left_in_place_when_the_writer_did_not_delete_it()
    {
        $explicit = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('posts' => 2),
            'write' => array(
                'ok' => true,
                'counts' => array('posts' => 2),
                'polylang_deleted' => false,
                'errors' => array(
                    'posts' => 'A post excerpt was empty',
                ),
            ),
        ));
        $this->assertNotSame('The migration is complete.', $explicit['title']);
        $this->assertStringContainsString('The package is saved on this site.', $explicit['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $explicit['summary']);
        $this->assertSame('Polylang data was left in place.', $this->line($explicit, 'Polylang data'));

        $unspecified = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('menus' => 1),
            'write' => array(
                'ok' => true,
                'counts' => array('menus' => 1),
                'errors' => array(
                    'menus' => array('Menu location was empty'),
                ),
            ),
        ));
        $this->assertNotSame('The migration is complete.', $unspecified['title']);
        $this->assertStringContainsString('Polylang data was left in place.', $unspecified['summary']);

        $deleted = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('posts' => 1),
            'write' => array(
                'ok' => true,
                'counts' => array('posts' => 1),
                'polylang_deleted' => true,
                'errors' => array(
                    'posts' => array('A post excerpt was empty'),
                ),
            ),
        ));
        $this->assertNotSame('The migration is complete.', $deleted['title']);
        $this->assertStringNotContainsString('Polylang data was left in place.', $deleted['summary']);
        $this->assertSame('Polylang data was removed.', $this->line($deleted, 'Polylang data'));
    }

    public function test_write_failure_overrides_a_successful_run_flag()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('languages' => 1, 'posts' => 2),
            'write' => array(
                'ok' => false,
                'errors' => array('The package could not be stored'),
            ),
            'errors' => array(),
            'missing' => array(),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertStringContainsString('The package could not be stored.', $report['summary']);
        $this->assertStringContainsString('was not saved into a Tolka package.', $report['summary']);
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
    }

    public function test_unavailable_section_is_not_described_as_empty_when_nothing_else_was_found()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array('posts' => 0),
            'missing' => array('Polylang_Posts'),
            'errors' => array(),
        ));

        $this->assertSame('0', $this->line($report, 'Posts'));
        $this->assertStringContainsString(
            'A required class is missing, so that part was skipped: Polylang_Posts (posts).',
            $report['summary']
        );
        $this->assertStringNotContainsString('nothing to package', strtolower($report['summary']));

        $partial = Migration_Report::format(array(
            'ok' => false,
            'counts' => array('languages' => 2, 'posts' => 0),
            'missing' => array('Polylang_Posts'),
            'errors' => array(),
        ));

        $this->assertStringContainsString(
            'This site has 2 languages, but that content was not saved into a Tolka package.',
            $partial['summary']
        );
        $this->assertStringContainsString('No terms, strings, menus, or media items were found.', $partial['summary']);
        $this->assertStringContainsString(
            'A required class is missing, so that part was skipped: Polylang_Posts (posts).',
            $partial['summary']
        );
        $this->assertStringNotContainsString('unavailable', $partial['summary']);
        $this->assertSame('0', $this->line($partial, 'Posts'));
    }

    public function test_garbage_input_still_returns_a_report()
    {
        $report = Migration_Report::format(array(
            'ok' => 'yes',
            'counts' => 'nope',
            'errors' => array(array('nested'), new \stdClass()),
            'missing' => null,
            'write' => 'nope',
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertStringEndsWith('Polylang data was left in place.', $report['summary']);
        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
    }

    public function test_duplicate_errors_are_shown_once()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'errors' => array('Polylang is not active.', 'polylang is not active'),
            'write' => array(
                'ok' => false,
                'errors' => array('Polylang is not active'),
            ),
        ));

        $this->assertSame(array('Polylang is not active.'), $this->lines_named($report, 'Problem'));
    }

    public function test_counts_fall_back_to_the_writer()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 1,
                ),
                'errors' => array(),
            ),
        ));

        $this->assertTrue($report['ok']);
        $this->assertStringContainsString('2 languages and 1 post', $report['summary']);
        $this->assertStringContainsString('No terms, strings, menus, or media items were found.', $report['summary']);
    }

    public function test_flat_writer_counts_are_used_when_the_run_has_none()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'write' => array(
                'ok' => true,
                'languages' => 3,
                'media' => '4',
                'errors' => array(),
            ),
        ));

        $this->assertSame('3', $this->line($report, 'Languages'));
        $this->assertSame('4', $this->line($report, 'Media'));
    }

    public function test_negative_counts_are_treated_as_none()
    {
        $report = $this->format_run(array(
            'languages' => -2,
            'posts' => '8',
            'terms' => 1.9,
        ));

        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertSame('8', $this->line($report, 'Posts'));
        $this->assertSame('1', $this->line($report, 'Terms'));
    }

    public function test_unconfirmed_package_does_not_claim_it_was_saved()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('languages' => 2, 'posts' => 2),
            'write' => array('errors' => array()),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished, but the save was not confirmed.', $report['title']);
        $this->assertStringContainsString('could not confirm that the Tolka package was saved.', $report['summary']);
        $this->assertSame('Not confirmed', $this->line($report, 'Tolka package'));
    }

    public function test_failed_run_with_null_write_is_not_called_complete()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array(
                'media' => 4,
                'languages' => 1,
                'posts' => 2,
            ),
            'write' => null,
            'errors' => array('Polylang is not active'),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['title']);
        $this->assertStringNotContainsString('The migration is complete', $report['summary']);
        $this->assertStringContainsString(
            'The writer did not return a result, so the Tolka package was not saved.',
            $report['summary']
        );
        $this->assertStringContainsString('Polylang is not active.', $report['summary']);
        $this->assert_section_order($report);
        $this->assertSame('1', $this->line($report, 'Languages'));
        $this->assertSame('2', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Terms'));
        $this->assertSame('0', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Menus'));
        $this->assertSame('4', $this->line($report, 'Media'));
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
        $this->assertSame('No result was returned.', $this->line($report, 'Writer'));
        $names = array();
        foreach ($report['lines'] as $line) {
            $names[] = $line['label'];
        }
        $start = array_search('Languages', $names, true);
        $this->assertSame(
            array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'),
            array_slice($names, $start, 6)
        );

        $marked_ok = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 3,
            ),
            'write' => null,
        ));
        $this->assertFalse($marked_ok['ok']);
        $this->assertSame('The migration did not finish.', $marked_ok['title']);
        $this->assertStringNotContainsString('The migration is complete', $marked_ok['title']);
        $this->assertStringNotContainsString('The migration is complete', $marked_ok['summary']);
        $this->assert_section_order($marked_ok);
        $this->assertSame('2', $this->line($marked_ok, 'Languages'));
        $this->assertSame('3', $this->line($marked_ok, 'Posts'));
        $this->assertSame('0', $this->line($marked_ok, 'Media'));

        $bare = Migration_Report::format(array(
            'ok' => false,
            'write' => null,
        ));
        $this->assertFalse($bare['ok']);
        $this->assertStringNotContainsString('The migration is complete', $bare['title']);
        $this->assertStringNotContainsString('The migration is complete', $bare['summary']);
        $this->assert_section_order($bare);
        $this->assertSame('0', $this->line($bare, 'Languages'));
        $this->assertSame('0', $this->line($bare, 'Posts'));
        $this->assertSame('0', $this->line($bare, 'Terms'));
        $this->assertSame('0', $this->line($bare, 'Strings'));
        $this->assertSame('0', $this->line($bare, 'Menus'));
        $this->assertSame('0', $this->line($bare, 'Media'));
    }

    public function test_titles_are_finished_sentences()
    {
        $clean = $this->format_run(array(
            'languages' => 2,
            'posts' => 1,
        ));
        $failed = Migration_Report::format(array(
            'ok' => false,
            'errors' => array('Polylang is not active'),
        ));

        $this->assertSame('The migration is complete.', $clean['title']);
        $this->assertSame('The migration did not finish.', $failed['title']);
        $this->assertMatchesRegularExpression('/^[A-Z].+\.$/', $clean['title']);
        $this->assertMatchesRegularExpression('/^[A-Z].+\.$/', $failed['title']);
        $this->assertSame($clean['title'], $this->line($clean, 'Outcome'));
        $this->assertSame($failed['title'], $this->line($failed, 'Outcome'));
    }

    public function test_non_string_errors_are_left_out_of_the_summary()
    {
        $stringable = new class {
            public function __toString()
            {
                return 'from tostring';
            }
        };

        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array('posts' => 2),
            'errors' => array(
                'The menu could not be read.',
                'Code 4099 was returned.',
                4099,
                3.25,
                array('nested'),
                null,
                true,
                $stringable,
            ),
            'write' => array(
                'ok' => false,
                'errors' => 404,
            ),
        ));

        $this->assertStringContainsString('The menu could not be read.', $report['summary']);
        $this->assertStringContainsString('1 more problem is listed below.', $report['summary']);
        $this->assertStringNotContainsString('4099', $report['summary']);
        $this->assertStringNotContainsString('3.25', $report['summary']);
        $this->assertStringNotContainsString('nested', $report['summary']);
        $this->assertStringNotContainsString('from tostring', $report['summary']);
        $this->assertStringNotContainsString('404', $report['summary']);
        $this->assertSame(
            array(
                'The menu could not be read.',
                'Code 4099 was returned.',
            ),
            $this->lines_named($report, 'Problem')
        );
    }

    public function test_only_non_string_errors_do_not_invent_a_problem()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('posts' => 1),
            'errors' => 7,
            'write' => array(
                'ok' => false,
                'errors' => array(false, array('hidden'), '   '),
            ),
        ));

        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertSame(array(), $this->lines_named($report, 'Problem'));
        $this->assertStringNotContainsString('hidden', $report['summary']);
        $this->assertStringNotContainsString('more problem', $report['summary']);
        $this->assertStringContainsString('was not saved into a Tolka package.', $report['summary']);
    }

    public function test_html_in_a_summary_is_kept_as_characters()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'errors' => array('<strong>Polylang is not active</strong>'),
        ));

        $this->assertStringContainsString('<strong>Polylang is not active</strong>', $report['summary']);
        $this->assertStringContainsString('<', $report['summary']);
        $this->assertStringContainsString('>', $report['summary']);
        $this->assertSame(
            array('<strong>Polylang is not active</strong>.'),
            $this->lines_named($report, 'Problem')
        );

        $with_null = Migration_Report::format(array(
            'ok' => false,
            'errors' => array("<em>Keep this</em>\0<script>alert(1)</script>"),
        ));
        $this->assertStringContainsString('<em>Keep this</em><script>alert(1)</script>', $with_null['summary']);
        $this->assertStringNotContainsString("\0", $with_null['summary']);
    }

    public function test_every_line_has_a_string_label_and_value()
    {
        $reports = array(
            $this->format_run(array('languages' => 1, 'posts' => 2)),
            Migration_Report::format(array(
                'ok' => false,
                'write' => null,
                'counts' => array('menus' => 2),
            )),
            Migration_Report::format(array()),
        );

        foreach ($reports as $report) {
            $this->assertNotEmpty($report['lines']);
            foreach ($report['lines'] as $line) {
                $this->assertIsString($line['label']);
                $this->assertIsString($line['value']);
                $this->assertNotSame('', $line['label']);
            }
        }
    }

    public function test_a_line_with_a_missing_label_is_dropped()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
function __($text, $domain = null) {
    if ($text === "Terms") {
        return "";
    }
    return $text;
}
require ' . var_export($class, true) . ';
$report = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => true,
    "counts" => array("languages" => 1, "terms" => 4),
    "write" => array("ok" => true),
));
echo json_encode($report);
';
        $tmp = tempnam(sys_get_temp_dir(), 'wmm-report');
        file_put_contents($tmp, $script);
        $output = array();
        $exit = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
        $report = json_decode(implode("\n", $output), true);
        $this->assertIsArray($report);
        $labels = array();
        foreach ($report['lines'] as $line) {
            $this->assertIsString($line['label']);
            $this->assertIsString($line['value']);
            $this->assertNotSame('', $line['label']);
            $labels[] = $line['label'];
        }
        $this->assertNotContains('Terms', $labels);
        $this->assertContains('Languages', $labels);
        $this->assertContains('Posts', $labels);
        $this->assertContains('Strings', $labels);
        $this->assertContains('Menus', $labels);
        $this->assertContains('Media', $labels);
        $this->assertSame('1', $this->line($report, 'Languages'));
        $this->assertSame('The migration is complete.', $report['title']);
    }

    public function test_every_outcome_keeps_polylang_data_and_a_finished_summary()
    {
        $cases = array(
            array('ok' => true, 'counts' => array('languages' => 2), 'write' => array('ok' => true)),
            array('ok' => true, 'counts' => array('languages' => 0), 'write' => array('ok' => true)),
            array('ok' => false, 'errors' => array('Something went wrong')),
            array('ok' => false, 'missing' => array('WMM\\Tolka\\Polylang_Menus')),
            array('ok' => true, 'counts' => array('posts' => 1), 'write' => array('ok' => true), 'errors' => array('A menu was skipped')),
            array(),
        );

        foreach ($cases as $run) {
            $report = Migration_Report::format($run);
            $this->assertIsBool($report['ok']);
            $this->assertNotSame('', $report['title']);
            $this->assertNotSame('', $report['summary']);
            $this->assertStringEndsWith('Polylang data was left in place.', $report['summary']);
            $this->assertStringNotContainsString('<', $report['title']);
            $this->assertSame('Polylang data was left in place.', $this->line($report, 'Polylang data'));
            $this->assertArrayHasKey('ok', $report);
            $this->assertArrayHasKey('title', $report);
            $this->assertArrayHasKey('summary', $report);
            $this->assertArrayHasKey('lines', $report);
            $this->assertArrayHasKey('errors', $report);
            $this->assertSame(array(), $report['errors']);
            $this->assertCount(5, $report);

            foreach ($report['lines'] as $line) {
                $this->assertIsString($line['label']);
                $this->assertIsString($line['value']);
                $this->assertNotSame('', $line['label']);
            }
        }
    }

    public function test_a_run_that_omits_keys_still_lists_every_section_count()
    {
        $report = Migration_Report::format(array());

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertSame('0', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Terms'));
        $this->assertSame('0', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Menus'));
        $this->assertSame('0', $this->line($report, 'Media'));
        $this->assertSame(array(), $this->lines_named($report, 'Missing step'));
        $this->assertStringNotContainsString('class is missing', $report['summary']);
        $this->assertStringEndsWith('Polylang data was left in place.', $report['summary']);

        $partial = Migration_Report::format(array(
            'counts' => array('posts' => 5),
        ));
        $this->assertSame('0', $this->line($partial, 'Languages'));
        $this->assertSame('5', $this->line($partial, 'Posts'));
        $this->assertSame('0', $this->line($partial, 'Terms'));
        $this->assertSame('0', $this->line($partial, 'Strings'));
        $this->assertSame('0', $this->line($partial, 'Menus'));
        $this->assertSame('0', $this->line($partial, 'Media'));

        $missing_only = Migration_Report::format(array(
            'missing' => array('WMM\\Tolka\\Polylang_Menus', 'Tolka_Schema'),
        ));
        $this->assertStringContainsString(
            'Required classes are missing, so those parts were skipped: Polylang_Menus (menus) and Tolka_Schema (package builder).',
            $missing_only['summary']
        );
        $this->assertSame('0', $this->line($missing_only, 'Menus'));
        $this->assertSame('0', $this->line($missing_only, 'Languages'));
        $this->assertSame(
            array(
                'Polylang_Menus (menus) is missing, so that part was skipped.',
                'Tolka_Schema (package builder) is missing, so that part was skipped.',
            ),
            $this->lines_named($missing_only, 'Missing step')
        );
    }

    public function test_missing_and_errors_can_be_a_single_string()
    {
        $report = Migration_Report::format(array(
            'missing' => 'WMM\\Tolka\\Polylang_Strings',
            'errors' => 'could not read the screen text',
        ));

        $this->assertSame('0', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Languages'));
        $this->assertStringContainsString(
            'A required class is missing, so that part was skipped: Polylang_Strings (strings).',
            $report['summary']
        );
        $this->assertStringContainsString('Could not read the screen text.', $report['summary']);
        $this->assertSame(
            array('Polylang_Strings (strings) is missing, so that part was skipped.'),
            $this->lines_named($report, 'Missing step')
        );

        $duplicates = Migration_Report::format(array(
            'missing' => array(
                'Polylang_Menus',
                'WMM\\Tolka\\Polylang_Menus',
            ),
        ));
        $this->assertSame(
            array('Polylang_Menus (menus) is missing, so that part was skipped.'),
            $this->lines_named($duplicates, 'Missing step')
        );
        $this->assertSame(1, substr_count($duplicates['summary'], 'Polylang_Menus'));

        $noisy = Migration_Report::format(array(
            'missing' => array(0, '', 'Polylang_Posts'),
        ));
        $this->assertSame(
            array('Polylang_Posts (posts) is missing, so that part was skipped.'),
            $this->lines_named($noisy, 'Missing step')
        );
        $this->assertStringNotContainsString('skipped: 0', $noisy['summary']);
    }

    public function test_post_sample_uses_the_excerpt_when_the_title_is_empty()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('posts' => 1),
            'write' => array('ok' => true),
            'sample' => array(
                'posts' => array(
                    array(
                        'type' => 'page',
                        'translations' => array(
                            'en' => array(
                                'id' => 4,
                                'title' => '',
                                'excerpt' => '<p>Summer hours start Monday.</p>',
                                'content' => 'A much longer page body that should not lead the report.',
                            ),
                        ),
                    ),
                ),
            ),
        ));

        $this->assertSame('Summer hours start Monday (en)', $this->line($report, 'Example post'));
        $this->assertStringNotContainsString('longer page body', $this->line($report, 'Example post'));
    }

    public function test_string_sample_shows_the_translated_text()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('strings' => 1),
            'write' => array('ok' => true),
            'sample' => array(
                'strings' => array(
                    array(
                        'name' => 'footer-copyright',
                        'context' => 'footer',
                        'translations' => array(
                            'en' => 'All rights reserved',
                            'sv' => 'Med ensamratt',
                        ),
                    ),
                ),
            ),
        ));

        $this->assertSame(
            'All rights reserved (en), Med ensamratt (sv)',
            $this->line($report, 'Example string')
        );
        $this->assertSame('1', $this->line($report, 'Strings'));
    }

    public function test_section_count_lines_stay_in_contract_order()
    {
        $report = Migration_Report::format(array(
            'counts' => array(
                'media' => 4,
                'languages' => 1,
            ),
        ));

        $labels = array();
        foreach ($report['lines'] as $line) {
            if (in_array($line['label'], array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'), true)) {
                $labels[] = $line['label'];
            }
        }

        $this->assertSame(
            array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'),
            $labels
        );
        $this->assertSame('1', $this->line($report, 'Languages'));
        $this->assertSame('0', $this->line($report, 'Posts'));
        $this->assertSame('4', $this->line($report, 'Media'));
    }

    public function test_menu_and_media_samples_use_visible_names()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'menus' => 1,
                'media' => 1,
            ),
            'write' => array('ok' => true),
            'sample' => array(
                'menus' => array(
                    array(
                        'location' => 'primary',
                        'translations' => array(
                            'en' => array(
                                'menu_id' => 3,
                                'items' => array(
                                    array(
                                        'title' => 'Contact',
                                        'url' => '/contact',
                                        'object' => 'page',
                                        'object_id' => 9,
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'media' => array(
                    array(
                        'attachment_id' => 12,
                        'title' => array('en' => 'Shop photo'),
                        'alt' => array('en' => 'Storefront', 'sv' => 'Butik'),
                    ),
                ),
            ),
        ));

        $this->assertSame('Contact', $this->line($report, 'Example menu'));
        $this->assertSame('Shop photo (en)', $this->line($report, 'Example file'));
    }

    public function test_saved_counts_that_differ_are_explained()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 10,
                'menus' => 1,
            ),
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 8,
                    'menus' => 0,
                ),
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertStringContainsString('The Tolka package on this site is incomplete.', $report['summary']);
        $this->assertStringNotContainsString('packaged for Tolka', $report['summary']);
        $this->assertStringContainsString(
            'Not everything that was found was saved. 8 of 10 posts were saved and the menu was not saved.',
            $report['summary']
        );
        $this->assertSame(
            'Not everything that was found was saved. 8 of 10 posts were saved and the menu was not saved.',
            $this->line($report, 'Saved counts')
        );
    }

    public function test_writer_stored_fewer_items_than_the_package_contained()
    {
        $posts = array();
        for ($i = 1; $i <= 5; $i++) {
            $posts[] = array('group' => 'post:' . $i);
        }

        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 5,
            ),
            'package' => array(
                'format' => 'tolka-migration',
                'version' => 1,
                'languages' => array(
                    array('slug' => 'en'),
                    array('slug' => 'sv'),
                ),
                'posts' => $posts,
                'terms' => array(),
                'strings' => array(),
                'menus' => array(),
                'media' => array(),
            ),
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 3,
                    'terms' => 0,
                    'strings' => 0,
                    'menus' => 0,
                    'media' => 0,
                ),
                'errors' => array(),
            ),
        ));

        $this->assert_not_a_clean_finish($report);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertStringContainsString(
            'The package contained more than was stored. 3 of 5 posts were saved.',
            $report['summary']
        );
        $this->assertStringContainsString('The Tolka package on this site is incomplete.', $report['summary']);
        $this->assertSame('Saved, but it is incomplete', $this->line($report, 'Tolka package'));
        $this->assertSame('5', $this->line($report, 'Posts'));
        $this->assertSame('3 of 5', $this->line($report, 'Posts stored'));
    }

    public function test_flat_writer_counts_with_a_failed_save_are_not_a_clean_finish()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'package' => array(
                'media' => array(
                    array('attachment_id' => 1, 'file' => 'a.jpg'),
                    array('attachment_id' => 2, 'file' => 'b.jpg'),
                ),
            ),
            'write' => array(
                'ok' => false,
                'media' => 1,
                'errors' => array('One file could not be stored'),
            ),
        ));

        $this->assert_not_a_clean_finish($report);
        $this->assert_partial_title($report);
        $this->assertSame('2', $this->line($report, 'Media'));
        $this->assertSame('1 of 2', $this->line($report, 'Media stored'));
        $this->assertStringContainsString(
            'Saving did not finish. The package contained more than was stored. 1 of 2 media items was saved.',
            $report['summary']
        );
        $this->assertStringContainsString('One file could not be stored.', $report['summary']);
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
    }

    public function test_exporter_envelope_counts_the_items_inside_the_package()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'package' => array(
                'posts' => array(
                    'items' => array(
                        array('group' => 'post:1'),
                        array('group' => 'post:2'),
                        array('group' => 'post:3'),
                    ),
                    'errors' => array(),
                ),
            ),
            'write' => array(
                'ok' => true,
                'counts' => array('posts' => 1),
            ),
        ));

        $this->assert_not_a_clean_finish($report);
        $this->assertSame('3', $this->line($report, 'Posts'));
        $this->assertStringContainsString(
            'The package contained more than was stored. 1 of 3 posts was saved.',
            $report['summary']
        );
        $this->assertSame('Saved, but it is incomplete', $this->line($report, 'Tolka package'));
    }

    public function test_failed_write_with_counts_is_not_a_clean_finish()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 6,
                'terms' => 1,
            ),
            'write' => array(
                'ok' => false,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 6,
                    'terms' => 1,
                    'strings' => 0,
                    'menus' => 0,
                    'media' => 0,
                ),
                'errors' => array('Saving stopped before the package was stored'),
            ),
        ));

        $this->assert_not_a_clean_finish($report);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertStringContainsString('6 posts', $report['summary']);
        $this->assertStringContainsString('Saving stopped before the package was stored.', $report['summary']);
        $this->assertStringContainsString('was not saved into a Tolka package.', $report['summary']);
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));

        $partial = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'posts' => 6,
            ),
            'package' => array(
                'posts' => array(
                    array('group' => 'post:1'),
                    array('group' => 'post:2'),
                    array('group' => 'post:3'),
                    array('group' => 'post:4'),
                    array('group' => 'post:5'),
                    array('group' => 'post:6'),
                ),
            ),
            'write' => array(
                'ok' => false,
                'counts' => array(
                    'posts' => 4,
                ),
                'errors' => array('Saving stopped before the package was stored'),
            ),
        ));

        $this->assert_not_a_clean_finish($partial);
        $this->assert_partial_title($partial);
        $this->assertSame('0', $this->line($partial, 'Languages'));
        $this->assertSame('0', $this->line($partial, 'Terms'));
        $this->assertSame('0', $this->line($partial, 'Strings'));
        $this->assertSame('0', $this->line($partial, 'Menus'));
        $this->assertSame('0', $this->line($partial, 'Media'));
        $this->assertNotSame('', $this->line($partial, 'Languages'));
        $this->assertStringContainsString(
            'Saving did not finish. The package contained more than was stored. 4 of 6 posts were saved.',
            $partial['summary']
        );
        $this->assertStringContainsString('That is not a finished migration.', $partial['summary']);
        $this->assertStringContainsString('Saving stopped before the package was stored.', $partial['summary']);
        $this->assertSame('Not saved', $this->line($partial, 'Tolka package'));
        $this->assertSame('4 of 6', $this->line($partial, 'Posts stored'));
    }

    public function test_matching_saved_counts_stay_a_clean_success()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('languages' => 2, 'posts' => 4),
            'write' => array(
                'ok' => true,
                'counts' => array('languages' => 2, 'posts' => 4),
            ),
        ));

        $this->assertTrue($report['ok']);
        $this->assertSame('The migration is complete.', $report['title']);
        $this->assertNotSame('The migration finished with problems.', $report['title']);
        $this->assertStringNotContainsString('The migration finished with problems.', $report['summary']);
        $this->assertArrayNotHasKey('Saved counts', $this->line_map($report));
    }

    public function test_partial_finish_title_and_section_order()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 5,
                'terms' => 1,
            ),
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 2,
                    'posts' => 5,
                    'terms' => 1,
                ),
                'errors' => array(
                    'posts' => array('One post had no title'),
                    'terms' => array('A term slug was changed'),
                ),
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assert_partial_title($report);
        $this->assertNotSame('The migration is complete.', $report['title']);
        $this->assertStringStartsWith('The migration finished with problems.', $report['summary']);
        $this->assertStringNotContainsString('The migration is complete', $report['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertStringContainsString('The package is saved on this site.', $report['summary']);
        $this->assert_section_order($report);
        $this->assertSame('2', $this->line($report, 'Languages'));
        $this->assertSame('5', $this->line($report, 'Posts'));
        $this->assertSame('1', $this->line($report, 'Terms'));
        $this->assertSame('0', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Menus'));
        $this->assertSame('0', $this->line($report, 'Media'));

        $clean = $this->format_run(array(
            'languages' => 2,
            'posts' => 5,
            'terms' => 1,
        ));
        $this->assertTrue($clean['ok']);
        $this->assertSame('The migration is complete.', $clean['title']);
        $this->assertSame('The migration is complete.', $this->line($clean, 'Outcome'));
        $this->assertNotSame('The migration finished with problems.', $clean['title']);
        $this->assert_section_order($clean);
    }

    public function test_nothing_stored_does_not_use_the_partial_title()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'counts' => array('posts' => 3),
            'write' => array(
                'ok' => false,
                'counts' => array('posts' => 0),
                'errors' => array('Nothing was stored'),
            ),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('The migration did not finish.', $report['title']);
        $this->assertNotSame('The migration finished with problems.', $report['title']);
        $this->assertStringNotContainsString('The migration finished with problems.', $report['summary']);
        $this->assertStringContainsString('Nothing was stored.', $report['summary']);
        $this->assert_section_order($report);
        $this->assertSame('3', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Languages'));
    }

    public function test_zero_counts_are_the_string_zero_not_blank()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 0,
                'posts' => 0.0,
                'terms' => '0',
                'strings' => '0.0',
                'menus' => false,
                'media' => '',
            ),
            'write' => array(
                'ok' => true,
                'counts' => array(
                    'languages' => 0,
                    'posts' => 0.0,
                    'terms' => '0',
                    'strings' => '0.0',
                    'menus' => 0,
                    'media' => 0,
                ),
                'errors' => array(
                    'posts' => 'A post was skipped',
                ),
            ),
        ));

        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertStringNotContainsString('complete', strtolower($report['title']));
        $this->assertSame('The migration finished with problems.', $this->line($report, 'Outcome'));
        $this->assert_section_order($report);

        foreach (array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media') as $label) {
            $value = $this->line($report, $label);
            $this->assertSame('0', $value);
            $this->assertNotSame('', $value);
            $this->assertIsString($value);
        }

        $clean = $this->format_run(array(
            'languages' => 2,
            'posts' => 0,
        ));
        $this->assertSame('The migration is complete.', $clean['title']);
        $this->assertNotSame('The migration finished with problems.', $clean['title']);
        $this->assertSame('0', $this->line($clean, 'Posts'));
        $this->assertNotSame('', $this->line($clean, 'Posts'));
    }

    public function test_section_counts_are_whole_number_strings()
    {
        $report = $this->format_run(array(
            'languages' => 2.0,
            'posts' => 1.9,
            'terms' => '8.0',
            'strings' => 1200,
            'menus' => -3,
            'media' => 0,
        ));

        $expected = array(
            'Languages' => '2',
            'Posts' => '1',
            'Terms' => '8',
            'Strings' => '1200',
            'Menus' => '0',
            'Media' => '0',
        );
        foreach ($expected as $label => $value) {
            $actual = $this->line($report, $label);
            $this->assertSame($value, $actual);
            $this->assertIsString($actual);
            $this->assertMatchesRegularExpression('/^(0|[1-9][0-9]*)$/', $actual);
            $this->assertStringNotContainsString('.', $actual);
        }
    }

    public function test_large_counts_are_grouped()
    {
        $report = $this->format_run(array(
            'languages' => 2,
            'posts' => 34000,
            'strings' => 1200,
        ));

        $this->assertSame('34000', $this->line($report, 'Posts'));
        $this->assertSame('1200', $this->line($report, 'Strings'));
        $this->assertStringContainsString('2 languages, 34,000 posts, and 1,200 strings', $report['summary']);
    }

    public function test_unknown_step_names_are_plain_language()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'missing' => array('WMM\\Tolka\\Custom_Exporter'),
        ));

        $this->assertStringContainsString(
            'A required class is missing, so that part was skipped: Custom_Exporter (custom exporter).',
            $report['summary']
        );
        $this->assertSame(
            array('Custom_Exporter (custom exporter) is missing, so that part was skipped.'),
            $this->lines_named($report, 'Missing step')
        );
    }

    public function test_sample_names_are_shown_to_the_owner()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array(
                'languages' => 2,
                'posts' => 2,
                'strings' => 1,
                'media' => 1,
            ),
            'write' => array('ok' => true),
            'sample' => array(
                'languages' => array(
                    array('slug' => 'en', 'name' => 'English', 'is_default' => true),
                ),
                'posts' => array(
                    array(
                        'translations' => array(
                            'en' => array('id' => 10, 'title' => 'Welcome', 'slug' => 'welcome'),
                            'sv' => array('id' => 11, 'title' => 'Valkommen', 'slug' => 'valkommen'),
                        ),
                    ),
                    array('title' => '<em>About</em>'),
                ),
                'strings' => array(
                    array('name' => 'Site title', 'string' => 'Hello'),
                ),
                'media' => array(
                    array('file' => '2024/08/photo.jpg'),
                ),
                'terms' => array('not-an-item'),
            ),
        ));

        $this->assertSame('English, the default language', $this->line($report, 'Example language'));
        $this->assertStringContainsString('English is the default language.', $report['summary']);
        $this->assertSame(
            array('Welcome (en), Valkommen (sv)', 'About'),
            $this->lines_named($report, 'Example post')
        );
        $this->assertSame('Hello', $this->line($report, 'Example string'));
        $this->assertSame('photo.jpg', $this->line($report, 'Example file'));
        $this->assertSame(array(), $this->lines_named($report, 'Example term'));
        $this->assertStringNotContainsString('<', $this->line($report, 'Example post'));
    }

    public function test_a_long_problem_is_shortened_in_the_summary_only()
    {
        $long = str_repeat('Could not save this menu. ', 20);
        $report = Migration_Report::format(array(
            'ok' => false,
            'errors' => array($long),
        ));

        $this->assertStringContainsString('...', $report['summary']);
        $this->assertLessThan(strlen($long), strlen($report['summary']));
        $problems = $this->lines_named($report, 'Problem');
        $this->assertStringStartsWith('Could not save this menu.', $problems[0]);
        $this->assertGreaterThan(180, strlen($problems[0]));
    }

    public function test_finished_time_is_shown_in_utc_without_wordpress()
    {
        if (function_exists('wp_date')) {
            $this->markTestSkipped('WordPress date formatting is loaded.');
        }

        $report = $this->format_run(array('languages' => 1));
        $this->assertMatchesRegularExpression(
            '/^[0-9]{1,2} [A-Za-z]+ [0-9]{4}, [0-9]{2}:[0-9]{2} UTC$/',
            $this->line($report, 'When')
        );
    }

    public function test_missing_update_option_does_not_fatal()
    {
        if (function_exists('update_option')) {
            $this->markTestSkipped('update_option is already loaded.');
        }

        $report = $this->format_run(array(
            'languages' => 1,
            'posts' => 1,
        ));

        $this->assertSame('The migration is complete.', $report['title']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertStringNotContainsString('could not be saved', $report['summary']);
        $this->assertSame(array(), $report['errors']);
        $this->assertSame('1', $this->line($report, 'Languages'));
        $this->assertSame('1', $this->line($report, 'Posts'));
    }

    public function test_update_option_false_keeps_a_complete_report()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
function update_option($option, $value, $autoload = null) {
    return false;
}
require ' . var_export($class, true) . ';
$clean = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => true,
    "counts" => array("languages" => 2, "posts" => 3),
    "write" => array("ok" => true),
));
$failed = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => false,
    "counts" => array("languages" => 1, "menus" => 2),
    "write" => null,
    "errors" => array("Polylang is not active"),
));
echo json_encode(array("clean" => $clean, "failed" => $failed));
';
        $tmp = tempnam(sys_get_temp_dir(), 'wmm-report');
        file_put_contents($tmp, $script);
        $output = array();
        $exit = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded);

        $clean = $decoded['clean'];
        $this->assertTrue($clean['ok']);
        $this->assertSame('The migration is complete.', $clean['title']);
        $this->assertStringContainsString('2 languages', $clean['summary']);
        $this->assertStringContainsString('Polylang data was left in place.', $clean['summary']);
        $this->assertStringContainsString('The report could not be saved.', $clean['summary']);
        $this->assertSame(array('The report could not be saved.'), $clean['errors']);
        $this->assert_saved_failure_keeps_sections($clean, array(
            'Languages' => '2',
            'Posts' => '3',
            'Terms' => '0',
            'Strings' => '0',
            'Menus' => '0',
            'Media' => '0',
        ));

        $failed = $decoded['failed'];
        $this->assertFalse($failed['ok']);
        $this->assertSame('The migration did not finish.', $failed['title']);
        $this->assertStringNotContainsString('The migration is complete', $failed['title']);
        $this->assertStringNotContainsString('The migration is complete', $failed['summary']);
        $this->assertStringContainsString('Polylang is not active.', $failed['summary']);
        $this->assertStringContainsString('The report could not be saved.', $failed['summary']);
        $this->assertSame(array('The report could not be saved.'), $failed['errors']);
        $this->assert_saved_failure_keeps_sections($failed, array(
            'Languages' => '1',
            'Posts' => '0',
            'Terms' => '0',
            'Strings' => '0',
            'Menus' => '2',
            'Media' => '0',
        ));
    }

    public function test_partial_title_section_order_and_clean_second_format()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
$GLOBALS["wmm_opt"] = null;
function update_option($option, $value, $autoload = null) {
    if ($GLOBALS["wmm_opt"] !== null && $GLOBALS["wmm_opt"] == $value) {
        $GLOBALS["wmm_unchanged_false"] = true;
        return false;
    }
    $GLOBALS["wmm_opt"] = $value;
    return true;
}
function get_option($option, $default = false) {
    return $GLOBALS["wmm_opt"] === null ? $default : $GLOBALS["wmm_opt"];
}
require ' . var_export($class, true) . ';
$partial_run = array(
    "ok" => true,
    "counts" => array(
        "media" => 8,
        "menus" => 3,
        "strings" => 0,
        "terms" => 1,
        "posts" => 4,
        "languages" => 2,
    ),
    "write" => array(
        "ok" => true,
        "counts" => array(
            "languages" => 2,
            "posts" => 4,
            "terms" => 1,
            "strings" => 0,
            "menus" => 3,
            "media" => 8,
        ),
        "errors" => array("posts" => array("One post had no title")),
    ),
);
$clean_run = array(
    "ok" => true,
    "counts" => array("posts" => 4, "languages" => 2),
    "write" => array("ok" => true),
);
$partial = \\WMM\\Tolka\\Migration_Report::format($partial_run);
$partial_again = \\WMM\\Tolka\\Migration_Report::format($partial_run);
$stored_partial_again = $GLOBALS["wmm_opt"];
$clean = \\WMM\\Tolka\\Migration_Report::format($clean_run);
$clean_again = \\WMM\\Tolka\\Migration_Report::format($clean_run);
$stored_clean_again = $GLOBALS["wmm_opt"];
echo json_encode(array(
    "unchanged_false" => !empty($GLOBALS["wmm_unchanged_false"]),
    "partial" => $partial,
    "partial_again" => $partial_again,
    "stored_partial_again" => $stored_partial_again,
    "clean" => $clean,
    "clean_again" => $clean_again,
    "stored_clean_again" => $stored_clean_again,
));
';
        $decoded = $this->run_report_script($script);
        $this->assertTrue($decoded['unchanged_false']);

        foreach (array('partial', 'partial_again') as $key) {
            $report = $decoded[$key];
            $this->assertFalse($report['ok']);
            $this->assertSame('The migration finished with problems.', $report['title']);
            $this->assertSame('The migration finished with problems.', $this->line($report, 'Outcome'));
            $this->assertStringNotContainsString('complete', strtolower($report['title']));
            $this->assertStringNotContainsString('could not be saved', $report['title']);
            $this->assertStringStartsWith('The migration finished with problems.', $report['summary']);
            $this->assertStringNotContainsString('could not be saved', $report['summary']);
            $this->assertSame(array(), $report['errors']);
            $this->assert_section_order($report);
            $this->assert_sections_are_contiguous($report);
            $this->assertSame('2', $this->line($report, 'Languages'));
            $this->assertSame('4', $this->line($report, 'Posts'));
            $this->assertSame('1', $this->line($report, 'Terms'));
            $this->assertSame('0', $this->line($report, 'Strings'));
            $this->assertSame('3', $this->line($report, 'Menus'));
            $this->assertSame('8', $this->line($report, 'Media'));
        }

        foreach (array('clean', 'clean_again') as $key) {
            $report = $decoded[$key];
            $this->assertTrue($report['ok']);
            $this->assertSame('The migration is complete.', $report['title']);
            $this->assertSame('The migration is complete.', $this->line($report, 'Outcome'));
            $this->assertNotSame('The migration finished with problems.', $report['title']);
            $this->assertStringNotContainsString('could not be saved', $report['title']);
            $this->assertStringNotContainsString('could not be saved', $report['summary']);
            $this->assertSame(array(), $report['errors']);
            $this->assert_section_order($report);
            $this->assert_sections_are_contiguous($report);
            $this->assertSame('2', $this->line($report, 'Languages'));
            $this->assertSame('4', $this->line($report, 'Posts'));
            $this->assertSame('0', $this->line($report, 'Terms'));
            $this->assertSame('0', $this->line($report, 'Strings'));
            $this->assertSame('0', $this->line($report, 'Menus'));
            $this->assertSame('0', $this->line($report, 'Media'));
            foreach ($report['lines'] as $line) {
                $this->assertStringNotContainsString('could not be saved', $line['label']);
                $this->assertStringNotContainsString('could not be saved', $line['value']);
            }
        }

        $this->assertSame($decoded['partial_again'], $decoded['stored_partial_again']);
        $this->assertSame($decoded['clean_again'], $decoded['stored_clean_again']);
        $this->assertSame('The migration finished with problems.', $decoded['stored_partial_again']['title']);
        $this->assertSame('The migration is complete.', $decoded['stored_clean_again']['title']);
        $this->assertSame(array(), $decoded['stored_clean_again']['errors']);
        $this->assert_section_order($decoded['stored_partial_again']);
        $this->assert_section_order($decoded['stored_clean_again']);
        $this->assert_sections_are_contiguous($decoded['stored_partial_again']);
        $this->assert_sections_are_contiguous($decoded['stored_clean_again']);
    }

    public function test_reordered_option_keys_do_not_add_a_save_error_on_the_second_format()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
$GLOBALS["wmm_opt"] = null;
function wmm_same_report($left, $right) {
    if (!is_array($left) || !is_array($right)) {
        return false;
    }
    foreach (array("ok", "title", "summary", "lines", "errors") as $key) {
        $a = array_key_exists($key, $left) ? $left[$key] : null;
        $b = array_key_exists($key, $right) ? $right[$key] : null;
        if ($a != $b) {
            return false;
        }
    }
    return true;
}
function update_option($option, $value, $autoload = null) {
    if (is_array($GLOBALS["wmm_opt"]) && wmm_same_report($GLOBALS["wmm_opt"], $value)) {
        $GLOBALS["wmm_unchanged_false"] = true;
        $reordered = array();
        foreach (array("errors", "lines", "summary", "title", "ok") as $key) {
            if (array_key_exists($key, $GLOBALS["wmm_opt"])) {
                $reordered[$key] = $GLOBALS["wmm_opt"][$key];
            }
        }
        $GLOBALS["wmm_opt"] = $reordered;
        return false;
    }
    $GLOBALS["wmm_opt"] = $value;
    return true;
}
function get_option($option, $default = false) {
    return $GLOBALS["wmm_opt"] === null ? $default : $GLOBALS["wmm_opt"];
}
require ' . var_export($class, true) . ';
$partial_run = array(
    "ok" => true,
    "counts" => array("media" => 1, "languages" => 2, "posts" => 3),
    "write" => array(
        "ok" => true,
        "errors" => array("A term slug was changed"),
    ),
);
$clean_run = array(
    "ok" => true,
    "counts" => array("menus" => 2, "strings" => 5, "languages" => 1),
    "write" => array("ok" => true),
);
$partial = \\WMM\\Tolka\\Migration_Report::format($partial_run);
$partial_again = \\WMM\\Tolka\\Migration_Report::format($partial_run);
$clean = \\WMM\\Tolka\\Migration_Report::format($clean_run);
$clean_again = \\WMM\\Tolka\\Migration_Report::format($clean_run);
echo json_encode(array(
    "unchanged_false" => !empty($GLOBALS["wmm_unchanged_false"]),
    "partial" => $partial,
    "partial_again" => $partial_again,
    "clean" => $clean,
    "clean_again" => $clean_again,
));
';
        $decoded = $this->run_report_script($script);
        $this->assertTrue($decoded['unchanged_false']);

        foreach (array('partial', 'partial_again') as $key) {
            $report = $decoded[$key];
            $this->assertSame('The migration finished with problems.', $report['title']);
            $this->assertSame('The migration finished with problems.', $this->line($report, 'Outcome'));
            $this->assertStringNotContainsString('could not be saved', $report['title']);
            $this->assertStringNotContainsString('could not be saved', $report['summary']);
            $this->assertSame(array(), $report['errors']);
            $this->assert_section_order($report);
            $this->assert_sections_are_contiguous($report);
            $this->assertSame('2', $this->line($report, 'Languages'));
            $this->assertSame('3', $this->line($report, 'Posts'));
            $this->assertSame('0', $this->line($report, 'Terms'));
            $this->assertSame('0', $this->line($report, 'Strings'));
            $this->assertSame('0', $this->line($report, 'Menus'));
            $this->assertSame('1', $this->line($report, 'Media'));
        }

        foreach (array('clean', 'clean_again') as $key) {
            $report = $decoded[$key];
            $this->assertSame('The migration is complete.', $report['title']);
            $this->assertNotSame('The migration finished with problems.', $report['title']);
            $this->assertStringNotContainsString('could not be saved', $report['title']);
            $this->assertStringNotContainsString('could not be saved', $report['summary']);
            $this->assertSame(array(), $report['errors']);
            $this->assert_section_order($report);
            $this->assert_sections_are_contiguous($report);
            $this->assertSame('1', $this->line($report, 'Languages'));
            $this->assertSame('0', $this->line($report, 'Posts'));
            $this->assertSame('0', $this->line($report, 'Terms'));
            $this->assertSame('5', $this->line($report, 'Strings'));
            $this->assertSame('2', $this->line($report, 'Menus'));
            $this->assertSame('0', $this->line($report, 'Media'));
        }
    }

    public function test_second_format_does_not_add_an_error_when_the_value_is_unchanged()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
$GLOBALS["wmm_opt"] = null;
function update_option($option, $value, $autoload = null) {
    if ($GLOBALS["wmm_opt"] !== null && $GLOBALS["wmm_opt"] == $value) {
        $GLOBALS["wmm_unchanged_false"] = true;
        return false;
    }
    $GLOBALS["wmm_opt"] = $value;
    return true;
}
function get_option($option, $default = false) {
    return $GLOBALS["wmm_opt"] === null ? $default : $GLOBALS["wmm_opt"];
}
require ' . var_export($class, true) . ';
$run = array(
    "ok" => true,
    "counts" => array("languages" => 2, "posts" => 1),
    "write" => array("ok" => true),
);
$first = \\WMM\\Tolka\\Migration_Report::format($run);
$second = \\WMM\\Tolka\\Migration_Report::format($run);
$third = \\WMM\\Tolka\\Migration_Report::format($run);
echo json_encode(array(
    "unchanged_false" => !empty($GLOBALS["wmm_unchanged_false"]),
    "first_errors" => $first["errors"],
    "second_errors" => $second["errors"],
    "third_errors" => $third["errors"],
    "first_summary" => $first["summary"],
    "second_summary" => $second["summary"],
    "third_summary" => $third["summary"],
    "first_title" => $first["title"],
    "second_title" => $second["title"],
    "third_title" => $third["title"],
    "second_lines" => $second["lines"],
    "stored_errors" => $GLOBALS["wmm_opt"]["errors"],
));
';
        $decoded = $this->run_report_script($script);
        $this->assertTrue($decoded['unchanged_false']);
        $this->assertSame(array(), $decoded['first_errors']);
        $this->assertSame(array(), $decoded['second_errors']);
        $this->assertSame(array(), $decoded['third_errors']);
        $this->assertStringNotContainsString('could not be saved', $decoded['first_summary']);
        $this->assertStringNotContainsString('could not be saved', $decoded['second_summary']);
        $this->assertStringNotContainsString('could not be saved', $decoded['third_summary']);
        $this->assertSame(array(), $decoded['stored_errors']);
        $this->assertSame('The migration is complete.', $decoded['first_title']);
        $this->assertSame('The migration is complete.', $decoded['second_title']);
        $this->assertSame('The migration is complete.', $decoded['third_title']);
        $this->assertNotSame('The migration finished with problems.', $decoded['second_title']);
        $this->assertStringNotContainsString('could not be saved', $decoded['first_title']);
        $this->assertStringNotContainsString('could not be saved', $decoded['second_title']);
        $this->assertStringNotContainsString('could not be saved', $decoded['third_title']);
        foreach ($decoded['second_lines'] as $line) {
            $this->assertStringNotContainsString('could not be saved', $line['label']);
            $this->assertStringNotContainsString('could not be saved', $line['value']);
        }
        $second = array('lines' => $decoded['second_lines']);
        $this->assertSame('The migration is complete.', $this->line($second, 'Outcome'));
        $this->assertStringNotContainsString('could not be saved', $this->line($second, 'Outcome'));
        $this->assertSame('2', $this->line($second, 'Languages'));
        $this->assertSame('1', $this->line($second, 'Posts'));
        $this->assertSame('0', $this->line($second, 'Terms'));
        $this->assertSame('0', $this->line($second, 'Strings'));
        $this->assertSame('0', $this->line($second, 'Menus'));
        $this->assertSame('0', $this->line($second, 'Media'));
        $this->assert_section_order($second);
    }

    public function test_partial_finish_keeps_its_title_when_the_report_is_not_stored()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
function update_option($option, $value, $autoload = null) {
    return false;
}
function get_option($option, $default = false) {
    return array("ok" => false, "title" => "old");
}
require ' . var_export($class, true) . ';
$report = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => true,
    "counts" => array(
        "languages" => 1,
        "posts" => 2,
        "terms" => 0,
        "strings" => 4,
        "menus" => 0,
        "media" => 5,
    ),
    "write" => array(
        "ok" => true,
        "errors" => array("media" => array("One file could not be stored")),
    ),
));
echo json_encode($report);
';
        $report = $this->run_report_script($script);
        $this->assertFalse($report['ok']);
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertSame('The migration finished with problems.', $this->line($report, 'Outcome'));
        $this->assertStringNotContainsString('complete', strtolower($report['title']));
        $this->assertStringNotContainsString('could not be saved', $report['title']);
        $this->assertStringStartsWith('The migration finished with problems.', $report['summary']);
        $this->assertStringContainsString('The report could not be saved.', $report['summary']);
        $this->assertSame(array('The report could not be saved.'), $report['errors']);
        $this->assert_section_order($report);
        $this->assert_sections_are_contiguous($report);
        $this->assertSame('1', $this->line($report, 'Languages'));
        $this->assertSame('2', $this->line($report, 'Posts'));
        $this->assertSame('0', $this->line($report, 'Terms'));
        $this->assertSame('4', $this->line($report, 'Strings'));
        $this->assertSame('0', $this->line($report, 'Menus'));
        $this->assertSame('5', $this->line($report, 'Media'));

        $media_at = null;
        $report_at = null;
        foreach ($report['lines'] as $index => $line) {
            if ($line['label'] === 'Media') {
                $media_at = $index;
            }
            if ($line['label'] === 'Report') {
                $report_at = $index;
            }
        }
        $this->assertNotNull($media_at);
        $this->assertNotNull($report_at);
        $this->assertGreaterThan($media_at, $report_at);
    }

    public function test_update_option_false_adds_an_error_only_when_the_value_is_not_stored()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
function update_option($option, $value, $autoload = null) {
    return false;
}
function get_option($option, $default = false) {
    return array("ok" => false, "title" => "old");
}
require ' . var_export($class, true) . ';
$report = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => true,
    "counts" => array("languages" => 2),
    "write" => array("ok" => true),
));
echo json_encode($report);
';
        $report = $this->run_report_script($script);
        $this->assertTrue($report['ok']);
        $this->assertSame('The migration is complete.', $report['title']);
        $this->assertStringContainsString('Polylang data was left in place.', $report['summary']);
        $this->assertStringContainsString('The report could not be saved.', $report['summary']);
        $this->assertSame(array('The report could not be saved.'), $report['errors']);
        $this->assertSame('2', $this->line($report, 'Languages'));
        $this->assertSame('0', $this->line($report, 'Posts'));
    }

    public function test_update_option_true_does_not_add_a_save_error()
    {
        $class = dirname(__DIR__, 2) . '/includes/tolka/class-migration-report.php';
        $script = '<?php
define("ABSPATH", "/tmp/");
function update_option($option, $value, $autoload = null) {
    $GLOBALS["wmm_saved_option"] = $option;
    $GLOBALS["wmm_saved_report"] = $value;
    return true;
}
require ' . var_export($class, true) . ';
$report = \\WMM\\Tolka\\Migration_Report::format(array(
    "ok" => true,
    "counts" => array("languages" => 2),
    "write" => array("ok" => true),
));
echo json_encode(array(
    "report" => $report,
    "option" => $GLOBALS["wmm_saved_option"],
    "stored" => $GLOBALS["wmm_saved_report"],
));
';
        $tmp = tempnam(sys_get_temp_dir(), 'wmm-report');
        file_put_contents($tmp, $script);
        $output = array();
        $exit = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertSame('wmm_tolka_last_report', $decoded['option']);
        $this->assertSame(array(), $decoded['report']['errors']);
        $this->assertStringNotContainsString('could not be saved', $decoded['report']['summary']);
        $this->assertSame('The migration is complete.', $decoded['report']['title']);
        $this->assertSame($decoded['report'], $decoded['stored']);
    }

    public function test_stores_the_report_when_wordpress_options_exist()
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            $this->markTestSkipped('WordPress option API is not loaded.');
        }

        $report = $this->format_run(array('languages' => 1));
        $this->assertSame($report, get_option(Migration_Report::OPTION_LAST_REPORT));
    }

    /**
     * @param array                $report   Formatted report.
     * @param array<string, string> $expected Section label to value.
     */
    private function assert_saved_failure_keeps_sections(array $report, array $expected)
    {
        $labels = array();
        foreach ($report['lines'] as $line) {
            if (isset($expected[$line['label']])) {
                $labels[] = $line['label'];
                $this->assertSame($expected[$line['label']], $line['value']);
            }
        }

        $this->assertSame(array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'), $labels);
        $values = array();
        foreach ($report['lines'] as $line) {
            if ($line['label'] === 'Report') {
                $values[] = $line['value'];
            }
        }
        $this->assertSame(array('The report could not be saved.'), $values);
    }

    /**
     * @param string $script PHP source without the opening tag.
     * @return array
     */
    private function run_report_script($script)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wmm-report');
        file_put_contents($tmp, $script);
        $output = array();
        $exit = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);
        $this->assertSame(0, $exit, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array $report Formatted report.
     */
    private function assert_partial_title(array $report)
    {
        $this->assertSame('The migration finished with problems.', $report['title']);
        $this->assertSame('The migration finished with problems.', $this->line($report, 'Outcome'));
        $this->assertStringNotContainsString('complete', strtolower($report['title']));
        $this->assert_section_order($report);
    }

    /**
     * @param array $report Formatted report.
     */
    private function assert_section_order(array $report)
    {
        $labels = array();
        foreach ($report['lines'] as $line) {
            if (in_array($line['label'], array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'), true)) {
                $labels[] = $line['label'];
            }
        }

        $this->assertSame(
            array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media'),
            $labels
        );
    }

    /**
     * @param array $report Formatted report.
     */
    private function assert_sections_are_contiguous(array $report)
    {
        $wanted = array('Languages', 'Posts', 'Terms', 'Strings', 'Menus', 'Media');
        $indexes = array();
        foreach ($report['lines'] as $index => $line) {
            if (in_array($line['label'], $wanted, true)) {
                $indexes[] = $index;
            }
        }

        $this->assertCount(6, $indexes);
        $this->assertSame(5, $indexes[5] - $indexes[0]);
        $labels = array();
        foreach ($indexes as $index) {
            $labels[] = $report['lines'][$index]['label'];
        }
        $this->assertSame($wanted, $labels);
    }

    /**
     * @param array $report Formatted report.
     */
    private function assert_not_a_clean_finish(array $report)
    {
        $this->assertFalse($report['ok']);
        $this->assertNotSame('The migration is complete.', $report['title']);
        $haystack = strtolower($report['title'] . ' ' . $report['summary']);
        $this->assertStringNotContainsString('migration complete', $haystack);
        $this->assertStringNotContainsString('clean finish', $haystack);
        $this->assertStringNotContainsString('packaged for tolka', $haystack);
    }

    /**
     * @param array<string, int|string> $counts Section counts.
     * @return array
     */
    private function format_run(array $counts)
    {
        return Migration_Report::format(array(
            'ok' => true,
            'counts' => $counts,
            'write' => array(
                'ok' => true,
                'errors' => array(),
            ),
            'errors' => array(),
            'missing' => array(),
        ));
    }

    /**
     * @param array  $report Formatted report.
     * @param string $label  Line label.
     * @return string
     */
    private function line(array $report, $label)
    {
        $map = $this->line_map($report);
        $this->assertArrayHasKey($label, $map);

        return $map[$label];
    }

    /**
     * @param array $report Formatted report.
     * @return array<string, string>
     */
    private function line_map(array $report)
    {
        $map = array();
        foreach ($report['lines'] as $line) {
            if (!isset($map[$line['label']])) {
                $map[$line['label']] = $line['value'];
            }
        }

        return $map;
    }

    /**
     * @param array  $report Formatted report.
     * @param string $label  Repeated line label.
     * @return string[]
     */
    private function lines_named(array $report, $label)
    {
        $values = array();
        foreach ($report['lines'] as $line) {
            if ($line['label'] === $label) {
                $values[] = $line['value'];
            }
        }

        return $values;
    }
}
