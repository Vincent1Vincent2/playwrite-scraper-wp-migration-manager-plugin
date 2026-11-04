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
        $this->assertSame('Migration complete', $report['title']);
        $this->assertSame(
            'Your Polylang content is packaged for Tolka: 2 languages, 1 post, no terms, 10 strings, 1 menu, and 2 media items. The package is saved on this site. Your existing Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('2 languages', $this->line($report, 'Languages'));
        $this->assertSame('1 post', $this->line($report, 'Posts'));
        $this->assertSame('No terms', $this->line($report, 'Terms'));
        $this->assertSame('2 media items', $this->line($report, 'Media'));
        $this->assertSame('Saved on this site', $this->line($report, 'Tolka package'));
        $this->assertSame('Left in place', $this->line($report, 'Polylang data'));
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
            'Your Polylang content is packaged for Tolka: 1 language, 1 post, 1 term, 1 string, 1 menu, and 1 media item. The package is saved on this site. Your existing Polylang data was left in place.',
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
        $this->assertSame('Nothing to migrate', $report['title']);
        $this->assertSame(
            'The migration finished, but there was nothing to package. No languages, posts, terms, strings, menus, or media were found. If this site should have translations, check that Polylang has languages, then run the migration again. An empty Tolka package was saved on this site. Your existing Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('No posts', $this->line($report, 'Posts'));
        $this->assertSame('Saved, but it is empty', $this->line($report, 'Tolka package'));
        $this->assertSame(
            'If this site should have translations, check that Polylang has languages, then run the migration again.',
            $this->line($report, 'Next')
        );
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
        $this->assertSame('Migration did not finish', $report['title']);
        $this->assertSame(
            'The migration did not finish, so the Tolka package was not saved. Polylang is not active. Activate Polylang, then run the migration again from Migrate to Tolka. Your existing Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('Not saved', $this->line($report, 'Tolka package'));
        $this->assertSame('Polylang is not active.', $this->line($report, 'Problem'));
        $this->assertSame('Left in place', $this->line($report, 'Polylang data'));
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
            'The migration did not finish, so the Tolka package was not saved. The languages and saving steps are missing from this plugin. Update the plugin, then run the migration again from Migrate to Tolka. Your existing Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('Not available', $this->line($report, 'Languages'));
        $this->assertSame('No posts', $this->line($report, 'Posts'));
        $missing = $this->lines_named($report, 'Missing step');
        $this->assertSame(
            array(
                'The languages step is missing from this plugin.',
                'The saving step is missing from this plugin.',
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
            'The migration did not finish. This site has 2 languages, 4 posts, no terms, no strings, no menus, and no media, but that content was not saved into a Tolka package. Could not read menus. 1 more problem is listed below. Run the migration again from Migrate to Tolka. Your existing Polylang data was left in place.',
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
        $this->assertSame('Migration finished with problems', $report['title']);
        $this->assertSame(
            'Your Polylang content is packaged for Tolka: 2 languages, 3 posts, no terms, no strings, no menus, and no media. The package is saved on this site. One menu had no items. Review the problems below, then run the migration again if you want to retry. Your existing Polylang data was left in place.',
            $report['summary']
        );
        $this->assertSame('Saved on this site', $this->line($report, 'Tolka package'));
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
        $this->assertSame('Migration did not finish', $report['title']);
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

        $this->assertSame('Not available', $this->line($report, 'Posts'));
        $this->assertStringContainsString('The posts step is missing from this plugin.', $report['summary']);
        $this->assertStringNotContainsString('nothing to package', strtolower($report['summary']));

        $partial = Migration_Report::format(array(
            'ok' => false,
            'counts' => array('languages' => 2, 'posts' => 0),
            'missing' => array('Polylang_Posts'),
            'errors' => array(),
        ));

        $this->assertStringContainsString('2 languages, posts unavailable, no terms, no strings, no menus, and no media', $partial['summary']);
        $this->assertSame('Not available', $this->line($partial, 'Posts'));
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
        $this->assertSame('Migration did not finish', $report['title']);
        $this->assertStringEndsWith('Your existing Polylang data was left in place.', $report['summary']);
        $this->assertSame('No languages', $this->line($report, 'Languages'));
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
        $this->assertStringContainsString('2 languages, 1 post, no terms, no strings, no menus, and no media', $report['summary']);
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

        $this->assertSame('3 languages', $this->line($report, 'Languages'));
        $this->assertSame('4 media items', $this->line($report, 'Media'));
    }

    public function test_negative_counts_are_treated_as_none()
    {
        $report = $this->format_run(array(
            'languages' => -2,
            'posts' => '8',
            'terms' => 1.9,
        ));

        $this->assertSame('No languages', $this->line($report, 'Languages'));
        $this->assertSame('8 posts', $this->line($report, 'Posts'));
        $this->assertSame('1 term', $this->line($report, 'Terms'));
    }

    public function test_unconfirmed_package_does_not_claim_it_was_saved()
    {
        $report = Migration_Report::format(array(
            'ok' => true,
            'counts' => array('languages' => 2, 'posts' => 2),
            'write' => array('errors' => array()),
        ));

        $this->assertFalse($report['ok']);
        $this->assertSame('Migration finished', $report['title']);
        $this->assertStringContainsString('could not confirm that the Tolka package was saved.', $report['summary']);
        $this->assertSame('Not confirmed', $this->line($report, 'Tolka package'));
    }

    public function test_html_in_an_error_is_not_left_in_the_summary()
    {
        $report = Migration_Report::format(array(
            'ok' => false,
            'errors' => array('<strong>Polylang is not active</strong>'),
        ));

        $this->assertStringNotContainsString('<', $report['summary']);
        $this->assertStringContainsString('Polylang is not active.', $report['summary']);
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
            $this->assertStringEndsWith('Your existing Polylang data was left in place.', $report['summary']);
            $this->assertStringNotContainsString('<', $report['title']);
            $this->assertSame('Left in place', $this->line($report, 'Polylang data'));
            $this->assertArrayHasKey('ok', $report);
            $this->assertArrayHasKey('title', $report);
            $this->assertArrayHasKey('summary', $report);
            $this->assertArrayHasKey('lines', $report);
            $this->assertCount(4, $report);

            foreach ($report['lines'] as $line) {
                $this->assertIsString($line['label']);
                $this->assertIsString($line['value']);
                $this->assertNotSame('', $line['label']);
            }
        }
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
