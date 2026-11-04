<?php

/**
 * Tests for Polylang media export.
 *
 * @package MigrationManager
 */

$wmm_tolka_media_class = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-media.php';
if (is_readable($wmm_tolka_media_class)) {
    require_once $wmm_tolka_media_class;
}

use PHPUnit\Framework\TestCase;
use WMM\Tolka\Polylang_Media;

class Test_Polylang_Media extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WMM\\Tolka\\Polylang_Media')) {
            self::markTestSkipped('Polylang_Media is not available.');
        }
    }

    public function test_export_without_polylang_returns_an_empty_list_and_a_finished_error()
    {
        if ($this->polylang_or_wordpress_is_loaded()) {
            $this->markTestSkipped('Polylang or WordPress is loaded.');
        }

        $result = (new Polylang_Media())->export();

        $this->assertSame(array('items', 'errors'), array_keys($result));
        $this->assertSame(array(), $result['items']);
        $this->assertCount(1, $result['errors']);
        $this->assertCustomerSentence($result['errors'][0]);
        $this->assertStringContainsString('Polylang', $result['errors'][0]);
    }

    public function test_export_is_callable_on_an_instance()
    {
        $media = new Polylang_Media();
        $method = new ReflectionMethod(Polylang_Media::class, 'export');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
        $this->assertTrue(is_callable(array($media, 'export')));

        if ($this->call('polylang_is_present', array())) {
            return;
        }

        $result = $media->export();
        $this->assertSame(array('items', 'errors'), array_keys($result));
        $this->assertIsArray($result['items']);
        $this->assertIsArray($result['errors']);
    }

    public function test_polylang_inactive()
    {
        if ($this->call('polylang_is_present', array())) {
            $this->markTestSkipped('Polylang is active or its data is still stored.');
        }

        $result = (new Polylang_Media())->export();

        $this->assertSame(array('items', 'errors'), array_keys($result));
        $this->assertSame(array(), $result['items']);
        $this->assertNotEmpty($result['errors']);
        foreach ($result['errors'] as $error) {
            $this->assertCustomerSentence($error);
        }
        $this->assertStringContainsString('Polylang', implode(' ', $result['errors']));
        $this->assertNotFalse(json_encode($result));
    }

    public function test_database_error_is_not_reported_as_polylang_being_inactive()
    {
        if (isset($GLOBALS['wpdb']) || function_exists('get_option') || function_exists('pll_languages_list')) {
            $this->markTestSkipped('WordPress or Polylang is already loaded.');
        }

        $GLOBALS['wpdb'] = new class {
            public $term_taxonomy = 'wp_term_taxonomy';
            public $last_error = '';

            public function prepare($query, ...$args)
            {
                unset($args);
                return $query;
            }

            public function get_var($query)
            {
                unset($query);
                $this->last_error = 'database read failed';
                return null;
            }
        };
        try {
            $result = (new Polylang_Media())->export();
        } finally {
            unset($GLOBALS['wpdb']);
        }

        $this->assertSame(array(), $result['items']);
        $this->assertCount(1, $result['errors']);
        $this->assertCustomerSentence($result['errors'][0]);
        $this->assertStringNotContainsString('not active', strtolower($result['errors'][0]));
        $this->assertStringContainsString('Nothing was changed', $result['errors'][0]);
        $this->assertSame(
            'Media could not be read from this site. Nothing was changed.',
            $result['errors'][0]
        );
    }

    public function test_empty_polylang_tables_are_reported_as_inactive()
    {
        if (isset($GLOBALS['wpdb']) || function_exists('get_option') || function_exists('pll_languages_list')) {
            $this->markTestSkipped('WordPress or Polylang is already loaded.');
        }

        $GLOBALS['wpdb'] = new class {
            public $term_taxonomy = 'wp_term_taxonomy';
            public $last_error = '';

            public function prepare($query, ...$args)
            {
                unset($args);
                return $query;
            }

            public function get_var($query)
            {
                unset($query);
                return null;
            }
        };
        try {
            $media = new Polylang_Media();
            $result = $media->export();
        } finally {
            unset($GLOBALS['wpdb']);
        }

        $this->assertSame(array(), $result['items']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not active', strtolower($result['errors'][0]));
    }

    public function test_two_attachments_that_share_one_file_are_one_item()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 11, 'lang' => 'fr'),
            array('id' => 10, 'lang' => 'en'),
        ), array(11, 10), array('en', 'fr'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 10, '2024/05/hero.jpg', array(
            'en' => 'Harbor',
            'fr' => 'Port',
        ), array(
            'en' => 'Hero',
            'fr' => 'Visuel',
        ));
    }

    public function test_shared_server_path_and_relative_path_are_one_item()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
        ), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array(
                'file' => '/var/www/html/wp-content/uploads/2024/05/hero.jpg',
                'alt' => 'Harbor',
                'title' => 'Hero',
            ),
            11 => array(
                'file' => '2024/05/hero.jpg',
                'alt' => '',
                'title' => 'Visuel',
            ),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame('2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame('Harbor', $result['items'][0]['alt']['en']);
        $this->assertSame('Visuel', $result['items'][0]['title']['fr']);
    }

    public function test_same_filename_in_different_months_stays_two_items()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'en'),
        ), array(10, 11), array('en'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => 'May', 'title' => 'May'),
            11 => array('file' => '2024/06/hero.jpg', 'alt' => 'June', 'title' => 'June'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame('2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame('2024/06/hero.jpg', $result['items'][1]['file']);
    }

    public function test_third_copy_of_a_shared_file_joins_the_group()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(
            array('id' => 12, 'lang' => 'de'),
        ), array(10, 11, 12), array('de', 'en', 'fr'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
            12 => array('file' => '2024/05/hero.jpg', 'alt' => 'Hafen', 'title' => 'Titel'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 10, '2024/05/hero.jpg', array(
            'de' => 'Hafen',
            'en' => 'Harbor',
            'fr' => 'Port',
        ), array(
            'de' => 'Titel',
            'en' => 'Hero',
            'fr' => 'Visuel',
        ));
    }

    public function test_shared_file_keeps_text_the_leading_copy_left_blank()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'en'),
        ), array(10, 11), array('en'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => '', 'title' => ''),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame(array('en' => 'Harbor'), $result['items'][0]['alt']);
        $this->assertSame(array('en' => 'Hero'), $result['items'][0]['title']);
    }

    public function test_remote_url_and_local_path_stay_separate_items()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
        ), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array(
                'file' => 'https://cdn.example/2024/05/hero.jpg',
                'alt' => 'Harbor',
                'title' => 'Hero',
            ),
            11 => array(
                'file' => '2024/05/hero.jpg',
                'alt' => 'Port',
                'title' => 'Visuel',
            ),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame('https://cdn.example/2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame('2024/05/hero.jpg', $result['items'][1]['file']);
    }

    public function test_attachments_with_different_files_stay_separate()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
        ), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/06/other.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame(11, $result['items'][1]['attachment_id']);
    }

    public function test_attachments_without_a_file_are_not_treated_as_the_same_file()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
        ), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => '', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame(array(10, 11), array(
            $result['items'][0]['attachment_id'],
            $result['items'][1]['attachment_id'],
        ));
    }

    public function test_untranslated_attachment_keeps_a_remote_file_url()
    {
        $url = 'https://cdn.example/photos/floor-plan.pdf?v=2';
        $result = $this->itemsFrom(array(), array(
            array('id' => 42, 'lang' => 'en'),
        ), array(42), array('en'), 'en', array(
            42 => array(
                'file' => $url,
                'alt' => 'Floor plan',
                'title' => 'Floor plan',
            ),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 42, $url, array(
            'en' => 'Floor plan',
        ), array(
            'en' => 'Floor plan',
        ));
    }

    public function test_two_language_group_keeps_a_protocol_relative_file_url()
    {
        $url = '//cdn.example/2024/05/hero.jpg';
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => $url, 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertSame($url, $result['items'][0]['file']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
    }

    public function test_two_language_group_keeps_the_canonical_file_url()
    {
        $url = 'https://cdn.example/photos/hero.jpg';
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => $url, 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame($url, $result['items'][0]['file']);
        $this->assertSame(array('en' => 'Harbor', 'fr' => 'Port'), $result['items'][0]['alt']);
    }

    public function test_attachment_with_no_translation()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 42, 'lang' => 'en'),
        ), array(42, 42), array('en', 'fr'), 'en', array(
            42 => array(
                'file' => '2024/05/scan.pdf',
                'alt' => 'Floor plan',
                'title' => 'Floor plan',
            ),
        ));

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 42, '2024/05/scan.pdf', array(
            'en' => 'Floor plan',
        ), array(
            'en' => 'Floor plan',
        ));
        $this->assertArrayNotHasKey('fr', $result['items'][0]['alt']);
        $this->assertArrayNotHasKey('fr', $result['items'][0]['title']);
    }

    public function test_group_with_two_languages()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => serialize(array(
                    'en' => 10,
                    'fr' => 11,
                )),
                'members' => array(
                    10 => 'en',
                    11 => 'fr',
                ),
            ),
        ), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
        ), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '2024/05/hero.jpg', 'alt' => 'Port', 'title' => 'Visuel'),
        ));

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 10, '2024/05/hero.jpg', array(
            'en' => 'Harbor',
            'fr' => 'Port',
        ), array(
            'en' => 'Hero',
            'fr' => 'Visuel',
        ));
        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['alt']));
        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['title']));
    }

    public function test_two_language_group_keeps_a_language_when_its_text_is_missing()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => '10'),
                'members' => array(
                    10 => 'en',
                    11 => 'fr',
                ),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => '2024/05/hero.jpg', 'alt' => null, 'title' => 0),
            11 => array('file' => '', 'alt' => array('Port'), 'title' => 'Visuel'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 10, '2024/05/hero.jpg', array(
            'en' => '',
            'fr' => 'Port',
        ), array(
            'en' => '0',
            'fr' => 'Visuel',
        ));
    }

    public function test_export_envelope_keeps_only_real_media_items()
    {
        $result = $this->call('envelope', array(
            array(
                array(
                    'attachment_id' => 0,
                    'file' => 'skip.jpg',
                    'alt' => array('en' => 'Skip'),
                    'title' => array('en' => 'Skip'),
                ),
                array(
                    'attachment_id' => 5,
                    'file' => 'keep.jpg',
                    'alt' => array('en' => 'Keep'),
                    'title' => array('fr' => 'Garder'),
                    'extra' => 'not part of the package',
                ),
                'not-an-item',
            ),
            array('', 'Media could not be read from this site. Nothing was changed.'),
        ));

        $this->assertSame(array('items', 'errors'), array_keys($result));
        $this->assertCount(1, $result['items']);
        $this->assertMediaItem($result['items'][0], 5, 'keep.jpg', array(
            'en' => 'Keep',
            'fr' => '',
        ), array(
            'en' => '',
            'fr' => 'Garder',
        ));
        $this->assertArrayNotHasKey('extra', $result['items'][0]);
        $this->assertSame(array(
            'Media could not be read from this site. Nothing was changed.',
        ), $result['errors']);
    }

    public function test_untranslated_attachment_is_not_folded_into_a_translation_group()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(
                    10 => 'en',
                    11 => 'fr',
                ),
            ),
        ), array(
            array('id' => 42, 'lang' => 'de'),
        ), array(10, 11, 42), array('en', 'fr', 'de'), 'en', array(
            10 => array('file' => 'hero.jpg', 'alt' => 'EN', 'title' => 'EN'),
            11 => array('file' => 'hero.jpg', 'alt' => 'FR', 'title' => 'FR'),
            42 => array('file' => 'scan.pdf', 'alt' => 'Plan', 'title' => 'Plan'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame(42, $result['items'][1]['attachment_id']);
        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['alt']));
        $this->assertSame(array('de' => 'Plan'), $result['items'][1]['title']);
        $this->assertArrayNotHasKey('en', $result['items'][1]['alt']);
    }

    public function test_translation_group_uses_the_default_language_attachment_and_shared_file()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => serialize(array(
                    'en' => 10,
                    'fr' => 11,
                    'note' => 'ignore me',
                )),
                'members' => array(
                    10 => 'en',
                    11 => 'fr',
                ),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array('file' => '2024/01/hero.jpg', 'alt' => 'Harbor', 'title' => 'Hero'),
            11 => array('file' => '', 'alt' => 'Port', 'title' => 'Visuel'),
            12 => array('file' => '2024/01/other.jpg', 'alt' => 'Other', 'title' => 'Other'),
        ));

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame(array('attachment_id', 'file', 'alt', 'title'), array_keys($item));
        $this->assertSame(10, $item['attachment_id']);
        $this->assertSame('2024/01/hero.jpg', $item['file']);
        $this->assertSame(array('en' => 'Harbor', 'fr' => 'Port'), $item['alt']);
        $this->assertSame(array('en' => 'Hero', 'fr' => 'Visuel'), $item['title']);
    }

    public function test_two_language_group_prefers_the_relative_path_for_the_same_file()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array(
                'file' => '/var/www/html/wp-content/uploads/2024/05/hero.jpg',
                'alt' => 'Harbor',
                'title' => 'Hero',
            ),
            11 => array(
                'file' => '2024/05/hero.jpg',
                'alt' => 'Port',
                'title' => 'Visuel',
            ),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame('2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['alt']));
    }

    public function test_windows_server_path_yields_to_the_same_relative_file()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'de' => 12),
                'members' => array(10 => 'en', 12 => 'de'),
            ),
        ), array(), array(10, 12), array('de', 'en'), 'en', array(
            10 => array(
                'file' => 'C:\\sites\\example\\wp-content\\uploads\\2024\\05\\hero.jpg',
                'alt' => 'Harbor',
                'title' => 'Hero',
            ),
            12 => array(
                'file' => '2024/05/hero.jpg',
                'alt' => 'Hafen',
                'title' => 'Titel',
            ),
        ));

        $this->assertSame('2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame(array('de', 'en'), array_keys($result['items'][0]['title']));
    }

    public function test_two_language_group_keeps_a_different_canonical_file()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(), array(10, 11), array('en', 'fr'), 'en', array(
            10 => array(
                'file' => '/var/www/html/wp-content/uploads/2024/05/hero.jpg',
                'alt' => 'Harbor',
                'title' => 'Hero',
            ),
            11 => array(
                'file' => '2024/06/other.jpg',
                'alt' => 'Port',
                'title' => 'Visuel',
            ),
        ));

        $this->assertSame('/var/www/html/wp-content/uploads/2024/05/hero.jpg', $result['items'][0]['file']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
    }

    public function test_file_falls_back_to_another_translation_when_the_canonical_file_is_empty()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('fr' => 2, 'en' => 4),
                'members' => array(2 => 'fr', 4 => 'en'),
            ),
        ), array(), array(2, 4), array('en', 'fr'), 'fr', array(
            2 => array('file' => '', 'alt' => 'FR', 'title' => 'FR title'),
            4 => array('file' => '2020/shared.png', 'alt' => 'EN', 'title' => 'EN title'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(2, $result['items'][0]['attachment_id']);
        $this->assertSame('2020/shared.png', $result['items'][0]['file']);
        $this->assertSame(array('en', 'fr'), array_keys($result['items'][0]['alt']));
    }

    public function test_canonical_id_is_the_lowest_when_the_default_language_is_missing()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('sv' => 30, 'de' => 12),
                'members' => array(30 => 'sv', 12 => 'de'),
            ),
        ), array(), array(30, 12), array('de', 'sv'), 'en', array(
            30 => array('file' => 'sv.jpg', 'alt' => '', 'title' => 'Svenska'),
            12 => array('file' => 'de.jpg', 'alt' => 'Alt', 'title' => 'Deutsch'),
        ));

        $this->assertSame(12, $result['items'][0]['attachment_id']);
        $this->assertSame('de.jpg', $result['items'][0]['file']);
        $this->assertSame('', $result['items'][0]['alt']['sv']);
        $this->assertArrayHasKey('de', $result['items'][0]['title']);
    }

    public function test_description_language_case_follows_the_known_slug()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('EN' => 5, 'zh_cn' => 2),
                'members' => array(5 => '', 2 => ''),
            ),
        ), array(), array(5, 2), array('en', 'zh_CN'), 'de', array(
            5 => array('file' => 'en.jpg', 'alt' => 'Alt', 'title' => 'English'),
            2 => array('file' => 'zh.jpg', 'alt' => 'Alt', 'title' => 'Chinese'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(2, $result['items'][0]['attachment_id']);
        $this->assertSame(array(
            'en' => 'English',
            'zh_CN' => 'Chinese',
        ), $result['items'][0]['title']);
    }

    public function test_member_language_outside_the_known_list_is_kept()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => '',
                'members' => array(5 => 'xx'),
            ),
            array(
                'description' => '',
                'members' => array(6 => 'EN'),
            ),
        ), array(), array(5, 6), array('en'), 'de', array(
            5 => array('file' => 'x.jpg', 'alt' => 'A', 'title' => 'Extra'),
            6 => array('file' => 'e.jpg', 'alt' => 'B', 'title' => 'English'),
        ));

        $by_id = array();
        foreach ($result['items'] as $item) {
            $by_id[$item['attachment_id']] = $item;
        }
        $this->assertSame(array('xx' => 'Extra'), $by_id[5]['title']);
        $this->assertSame(array('en' => 'English'), $by_id[6]['title']);
    }

    public function test_default_language_match_ignores_case()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 5, 'de' => 9),
                'members' => array(5 => 'en', 9 => 'de'),
            ),
        ), array(), array(5, 9), array('en', 'de'), 'EN', array(
            5 => array('file' => 'en.jpg', 'alt' => 'A', 'title' => 'T'),
            9 => array('file' => 'de.jpg', 'alt' => 'B', 'title' => 'U'),
        ));

        $this->assertSame(5, $result['items'][0]['attachment_id']);
    }

    public function test_language_only_attachment_is_its_own_item()
    {
        $result = $this->itemsFrom(array(), array(
            array('id' => 8, 'lang' => 'de'),
        ), array(8), array('de', 'en'), 'en', array(
            8 => array('file' => '2024/02/logo.svg', 'alt' => 'Logo', 'title' => 'Logo'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(8, $result['items'][0]['attachment_id']);
        $this->assertSame(array('de' => 'Logo'), $result['items'][0]['alt']);
        $this->assertSame(array('de' => 'Logo'), $result['items'][0]['title']);
    }

    public function test_attachment_already_in_a_group_is_not_repeated_as_a_language_row()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(
            array('id' => 10, 'lang' => 'en'),
            array('id' => 11, 'lang' => 'fr'),
            array('id' => 15, 'lang' => 'en'),
        ), array(10, 11, 15), array('en', 'fr'), 'en', array(
            10 => array('file' => 'a.jpg', 'alt' => 'A', 'title' => 'A'),
            11 => array('file' => 'a.jpg', 'alt' => 'B', 'title' => 'B'),
            15 => array('file' => 'c.jpg', 'alt' => 'C', 'title' => 'C'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame(15, $result['items'][1]['attachment_id']);
    }

    public function test_items_are_sorted_by_attachment_id()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 50),
                'members' => array(50 => 'en'),
            ),
            array(
                'description' => array('fr' => 3),
                'members' => array(3 => 'fr'),
            ),
        ), array(), array(50, 3), array('en', 'fr'), 'en', array(
            50 => array('file' => 'late.jpg', 'alt' => 'L', 'title' => 'Late'),
            3 => array('file' => 'early.jpg', 'alt' => 'E', 'title' => 'Early'),
        ));

        $this->assertSame(array(3, 50), array(
            $result['items'][0]['attachment_id'],
            $result['items'][1]['attachment_id'],
        ));
    }

    public function test_corrupt_description_falls_back_to_member_languages()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => 'not-serialized',
                'members' => array(4 => 'en', 5 => 'de'),
            ),
        ), array(), array(4, 5), array('en', 'de'), 'en', array(
            4 => array('file' => 'four.jpg', 'alt' => 'Four', 'title' => 'Four'),
            5 => array('file' => 'five.jpg', 'alt' => 'Five', 'title' => 'Five'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(4, $result['items'][0]['attachment_id']);
        $this->assertSame(array('de' => 'Five', 'en' => 'Four'), $result['items'][0]['title']);
    }

    public function test_slashed_description_still_decodes()
    {
        $stored = addslashes(serialize(array('pt-br' => 7, 'en' => 6)));
        $result = $this->itemsFrom(array(
            array(
                'description' => $stored,
                'members' => array(7 => '', 6 => ''),
            ),
        ), array(), array(6, 7), array('en', 'pt-br'), 'en', array(
            6 => array('file' => 'en.jpg', 'alt' => 'EN', 'title' => 'EN'),
            7 => array('file' => 'en.jpg', 'alt' => 'PT', 'title' => 'PT'),
        ));

        $this->assertSame(6, $result['items'][0]['attachment_id']);
        $this->assertSame(array('en' => 'EN', 'pt-br' => 'PT'), $result['items'][0]['alt']);
    }

    public function test_non_attachment_ids_in_the_description_are_ignored()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 99),
                'members' => array(10 => 'en'),
            ),
        ), array(), array(10), array('en', 'fr'), 'en', array(
            10 => array('file' => 'only.jpg', 'alt' => 'Only', 'title' => 'Only'),
            99 => array('file' => 'page.jpg', 'alt' => 'No', 'title' => 'No'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(array('en' => 'Only'), $result['items'][0]['title']);
        $this->assertArrayNotHasKey('fr', $result['items'][0]['alt']);
    }

    public function test_duplicate_language_in_one_group_becomes_its_own_item()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => '',
                'members' => array(10 => 'en', 11 => 'en'),
            ),
        ), array(), array(10, 11), array('en'), 'en', array(
            10 => array('file' => 'a.jpg', 'alt' => 'A', 'title' => 'A'),
            11 => array('file' => 'b.jpg', 'alt' => 'B', 'title' => 'B'),
        ));

        $this->assertCount(2, $result['items']);
        $this->assertSame(10, $result['items'][0]['attachment_id']);
        $this->assertSame(11, $result['items'][1]['attachment_id']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_attachments_with_no_translation_keep_their_text()
    {
        $result = $this->itemsFrom(array(), array(), array(9, 4, 4), array('en', 'de'), 'en', array(
            4 => array('file' => '2024/03/scan.pdf', 'alt' => '', 'title' => 'Scan'),
            9 => array('file' => '2024/03/photo.jpg', 'alt' => 'Door', 'title' => 'Door'),
        ));

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(4, $result['items'][0]['attachment_id']);
        $this->assertSame('2024/03/scan.pdf', $result['items'][0]['file']);
        $this->assertSame(array('en' => ''), $result['items'][0]['alt']);
        $this->assertSame(array('en' => 'Scan'), $result['items'][0]['title']);
        $this->assertSame(9, $result['items'][1]['attachment_id']);
        $this->assertSame(array('en' => 'Door'), $result['items'][1]['alt']);
        $this->assertSame(array('en' => 'Door'), $result['items'][1]['title']);
    }

    public function test_unassigned_file_is_listed_when_no_default_language_is_known()
    {
        $result = $this->itemsFrom(array(), array(), array(4), array('en', 'de'), '', array(
            4 => array('file' => 'plain.jpg', 'alt' => 'Alt', 'title' => 'Title'),
        ));

        $this->assertSame(4, $result['items'][0]['attachment_id']);
        $this->assertSame('plain.jpg', $result['items'][0]['file']);
        $this->assertSame(array(), $result['items'][0]['alt']);
        $this->assertSame(array(), $result['items'][0]['title']);
    }

    public function test_single_site_language_labels_unassigned_media()
    {
        $result = $this->itemsFrom(array(), array(), array(4), array('sv'), '', array(
            4 => array('file' => 'sv.jpg', 'alt' => 'Hej', 'title' => 'Hej'),
        ));

        $this->assertSame(array('sv' => 'Hej'), $result['items'][0]['alt']);
        $this->assertSame(array('sv' => 'Hej'), $result['items'][0]['title']);
    }

    public function test_translated_and_untranslated_media_are_both_exported()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 10, 'fr' => 11),
                'members' => array(10 => 'en', 11 => 'fr'),
            ),
        ), array(
            array('id' => 8, 'lang' => 'de'),
        ), array(11, 10, 3, 8), array('en', 'fr', 'de'), 'en', array(
            10 => array('file' => 'hero.jpg', 'alt' => 'EN', 'title' => 'EN'),
            11 => array('file' => 'hero.jpg', 'alt' => 'FR', 'title' => 'FR'),
            8 => array('file' => 'logo.svg', 'alt' => 'Logo', 'title' => 'Logo'),
            3 => array('file' => 'scan.pdf', 'alt' => '', 'title' => 'Scan'),
        ));

        $this->assertSame(array(3, 8, 10), array(
            $result['items'][0]['attachment_id'],
            $result['items'][1]['attachment_id'],
            $result['items'][2]['attachment_id'],
        ));
        $this->assertSame(array('en' => 'Scan'), $result['items'][0]['title']);
        $this->assertSame(array('de' => 'Logo'), $result['items'][1]['title']);
        $this->assertSame(array('en' => 'EN', 'fr' => 'FR'), $result['items'][2]['alt']);
        $this->assertCount(3, $result['items']);
    }

    public function test_group_member_without_a_language_is_still_exported()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => '',
                'members' => array(6 => ''),
            ),
        ), array(), array(6), array('en'), 'en', array(
            6 => array('file' => 'lost.jpg', 'alt' => 'Lost alt', 'title' => 'Lost'),
        ));

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(6, $result['items'][0]['attachment_id']);
        $this->assertSame('lost.jpg', $result['items'][0]['file']);
        $this->assertSame(array('en' => 'Lost'), $result['items'][0]['title']);
    }

    public function test_failed_attachment_is_not_reported_as_untranslated_media()
    {
        $result = $this->call('items_from_parts', array(
            array(),
            array(),
            array(9, 4),
            array('en'),
            'en',
            array(
                9 => array('file' => 'bad.jpg', 'alt' => 'Bad', 'title' => 'Bad'),
                4 => array('file' => 'ok.jpg', 'alt' => 'Ok', 'title' => 'Ok'),
            ),
            array(9),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(4, $result['items'][0]['attachment_id']);
    }

    public function test_skipped_media_message_is_a_finished_sentence()
    {
        $one = $this->call('messages_for', array(1));
        $this->assertCount(1, $one);
        $this->assertCustomerSentence($one[0]);
        $this->assertSame('One media file could not be read and was left out of this export. Nothing was changed.', $one[0]);

        $many = $this->call('messages_for', array(3));
        $this->assertCount(1, $many);
        $this->assertCustomerSentence($many[0]);
        $this->assertSame('3 media files could not be read and were left out of this export. Nothing was changed.', $many[0]);
        $this->assertSame(array(), $this->call('messages_for', array(0)));
    }

    public function test_unknown_description_keys_are_not_treated_as_languages()
    {
        $decoded = $this->call('decode_stored_array', array(serialize(array(
            'en' => 3,
            'note' => 3,
            '9bad' => 4,
        ))));
        $this->assertSame(array('en' => 3, 'note' => 3, '9bad' => 4), $decoded);

        $result = $this->itemsFrom(array(
            array(
                'description' => array('en' => 3, 'note' => 4, '9bad' => 5),
                'members' => array(3 => 'en'),
            ),
        ), array(), array(3), array('en'), 'en', array(
            3 => array('file' => 'ok.jpg', 'alt' => 'Ok', 'title' => 'Ok'),
        ));

        $this->assertCount(1, $result['items']);
        $this->assertSame(array('en'), array_keys($result['items'][0]['alt']));
    }

    public function test_language_slugs_reject_empty_and_unsafe_values()
    {
        $this->assertSame('', $this->call('language_slug', array('')));
        $this->assertSame('', $this->call('language_slug', array('en US')));
        $this->assertSame('', $this->call('language_slug', array('9en')));
        $this->assertSame('', $this->call('language_slug', array(null)));
        $this->assertSame('pt-br', $this->call('language_slug', array(' pt-br ')));
        $this->assertSame('zh_CN', $this->call('language_slug', array('zh_CN')));
    }

    public function test_garbage_stored_values_do_not_throw()
    {
        $this->assertSame(array(), $this->call('decode_stored_array', array('')));
        $this->assertSame(array(), $this->call('decode_stored_array', array('not an array')));
        $this->assertSame(array(), $this->call('decode_stored_array', array('b:0;')));
        $this->assertSame(array(), $this->call('decode_stored_array', array('N;')));
        $this->assertSame(array('en' => 1), $this->call('decode_stored_array', array(array('en' => 1))));
        $this->assertSame(array(), $this->call('decode_stored_array', array(str_repeat('a:1:{s:1:"e";i:1;}', 100000))));
    }

    public function test_empty_library_has_no_items_and_no_error_marker()
    {
        $result = $this->itemsFrom(array(), array(), array(), array('en'), 'en', array());
        $this->assertSame(array(), $result['items']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_serialized_meta_text_is_unpacked_without_wordpress()
    {
        if (function_exists('maybe_unserialize')) {
            $this->markTestSkipped('WordPress unserialize helper is loaded.');
        }

        $this->assertSame('Harbor', $this->call('unserialize_meta', array(serialize('Harbor'))));
        $this->assertSame('2024/01/a.jpg', $this->call('unserialize_meta', array('2024/01/a.jpg')));
        $this->assertSame('Door', $this->call('string_value', array($this->call('unserialize_meta', array(serialize('Door'))))));
    }

    public function test_object_meta_is_not_exported()
    {
        $this->assertSame('', $this->call('unserialize_meta', array('O:8:"stdClass":0:{}')));
        $this->assertSame('', $this->call('unserialize_meta', array('C:11:"ArrayObject":0:{}')));
        $this->assertSame('O: the origin', $this->call('unserialize_meta', array('O: the origin')));
    }

    public function test_titles_keep_unicode_and_drop_invalid_bytes()
    {
        $this->assertSame('Café 港', $this->call('string_value', array('Café 港')));
        $this->assertSame('Doorway', $this->call('string_value', array("Door\xFFway")));
        $this->assertSame('', $this->call('string_value', array("\xFF\xFE")));
        $encoded = json_encode(array(
            'title' => $this->call('string_value', array("Door\xFFway")),
            'alt' => $this->call('string_value', array('Café 港')),
        ));
        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertSame('Doorway', $decoded['title']);
        $this->assertSame('Café 港', $decoded['alt']);
    }

    public function test_file_paths_are_portable()
    {
        $base = '/var/www/html/wp-content/uploads';
        $this->assertSame('2024/01/a.jpg', $this->call('portable_file', array(
            $base . '/2024/01/a.jpg',
            $base,
        )));
        $this->assertSame('2024/01/a.jpg', $this->call('portable_file', array(
            '\\var\\www\\html\\wp-content\\uploads\\2024\\01\\a.jpg',
            $base,
        )));
        $this->assertSame('2024/01/a.jpg', $this->call('portable_file', array('2024/01/a.jpg', $base)));
        $this->assertSame('https://cdn.example/a.jpg', $this->call('portable_file', array('https://cdn.example/a.jpg', $base)));
        $this->assertSame('/var/www2/a.jpg', $this->call('portable_file', array('/var/www2/a.jpg', '/var/www')));
    }

    public function test_id_query_placeholders_match_the_batch_size()
    {
        $this->assertSame('%d, %d, %d', $this->call('int_placeholders', array(3)));
        $this->assertSame('0', $this->call('int_placeholders', array(0)));
    }

    public function test_term_reads_without_wordpress_are_empty()
    {
        if (function_exists('wp_get_object_terms') || function_exists('taxonomy_exists')) {
            $this->markTestSkipped('WordPress term API is loaded.');
        }

        $this->assertSame(array(), $this->call('object_terms', array(array(1, 2), 'language')));
        $this->assertSame(array(), $this->call('object_terms', array(array(), 'post_translations')));
    }

    public function test_record_lookup_without_wordpress_returns_empty_strings()
    {
        if (function_exists('get_post_meta') || function_exists('get_post') || isset($GLOBALS['wpdb'])) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $records = $this->call('records_for', array(array(4, 4, 0, -3)));
        $this->assertSame(array(
            4 => array(
                'file' => '',
                'alt' => '',
                'title' => '',
            ),
        ), $records);
    }

    public function test_source_only_reads_polylang_media()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/class-polylang-media.php');
        $this->assertIsString($source);
        $needles = array(
            'wp_delete_post',
            'wp_delete_attachment',
            'wp_update_post',
            'update_post_meta(',
            'delete_post_meta(',
            'update_option',
            'delete_option',
            'wp_insert_post',
            'wp_insert_attachment',
            '->update(',
            '->delete(',
            '->query(',
            'unlink(',
        );
        foreach ($needles as $needle) {
            $this->assertFalse(strpos($source, $needle), $needle);
        }
    }

    private function itemsFrom($translation_rows, $language_rows, $attachment_ids, $known_languages, $default_lang, $records)
    {
        return $this->call('items_from_parts', array(
            $translation_rows,
            $language_rows,
            $attachment_ids,
            $known_languages,
            $default_lang,
            $records,
        ));
    }

    private function call($method, array $args)
    {
        $media = new Polylang_Media();
        $reflection = new ReflectionMethod(Polylang_Media::class, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invokeArgs($media, $args);
    }

    private function assertMediaItem($item, $attachment_id, $file, array $alt, array $title)
    {
        $this->assertIsArray($item);
        $this->assertSame(array('attachment_id', 'file', 'alt', 'title'), array_keys($item));
        $this->assertSame($attachment_id, $item['attachment_id']);
        $this->assertIsInt($item['attachment_id']);
        $this->assertSame($file, $item['file']);
        $this->assertSame($alt, $item['alt']);
        $this->assertSame($title, $item['title']);
        $this->assertSame(array_keys($item['alt']), array_keys($item['title']));
        foreach ($item['alt'] as $value) {
            $this->assertIsString($value);
        }
        foreach ($item['title'] as $value) {
            $this->assertIsString($value);
        }
        $this->assertNotFalse(json_encode($item));
    }

    private function assertCustomerSentence($message)
    {
        $this->assertIsString($message);
        $this->assertMatchesRegularExpression('/^[A-Z0-9].+\.$/', $message);
        $this->assertStringNotContainsString('taxonomy', strtolower($message));
        $this->assertStringNotContainsString('wpdb', strtolower($message));
    }

    private function polylang_or_wordpress_is_loaded()
    {
        return function_exists('pll_languages_list')
            || function_exists('pll_get_post_translations')
            || function_exists('pll_get_post_language')
            || function_exists('pll_default_language')
            || function_exists('get_option')
            || function_exists('get_posts')
            || class_exists('PLL', false)
            || class_exists('Polylang', false)
            || isset($GLOBALS['wpdb']);
    }
}
