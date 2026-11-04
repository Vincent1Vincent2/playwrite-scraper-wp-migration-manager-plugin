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
        ), array(), array(10, 11, 12), array('en', 'fr'), 'en', array(
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

    public function test_member_without_a_language_is_skipped_and_explained()
    {
        $result = $this->itemsFrom(array(
            array(
                'description' => '',
                'members' => array(6 => ''),
            ),
        ), array(), array(6), array('en'), 'en', array(
            6 => array('file' => 'lost.jpg', 'alt' => 'Lost', 'title' => 'Lost'),
        ));

        $this->assertSame(array(), $result['items']);
        $this->assertSame(1, $result['skipped']);
        $messages = $this->call('messages_for', array(1));
        $this->assertCount(1, $messages);
        $this->assertCustomerSentence($messages[0]);
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
        ), array(), array(3, 4, 5), array('en'), 'en', array(
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

    private function assertCustomerSentence($message)
    {
        $this->assertIsString($message);
        $this->assertMatchesRegularExpression('/^[A-Z].+\.$/', $message);
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
