<?php

/**
 * Migration runner tests. They do not load WordPress.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka\RunnerTest {

    class Exporter
    {
        public static $items = array();
        public static $errors = array();
        public static $script = array();
        public static $throw_on = null;
        public static $throw_error = false;
        public static $throw_message = 'export blew up';
        public static $calls = 0;

        public static function export()
        {
            $index = self::$calls;
            self::$calls++;
            if (self::$throw_on !== null && $index === self::$throw_on) {
                if (self::$throw_error) {
                    throw new \Error(self::$throw_message);
                }
                throw new \RuntimeException(self::$throw_message);
            }
            if (array_key_exists($index, self::$script)) {
                return self::$script[$index];
            }
            return array(
                'items' => self::$items,
                'errors' => self::$errors,
            );
        }

        public static function reset()
        {
            self::$items = array();
            self::$errors = array();
            self::$script = array();
            self::$throw_on = null;
            self::$throw_error = false;
            self::$throw_message = 'export blew up';
            self::$calls = 0;
        }
    }

    class InstanceExporter
    {
        public static $calls = 0;
        public static $items = array();
        public static $throw = false;
        public static $constructed = 0;

        public function __construct()
        {
            self::$constructed++;
        }

        public function export()
        {
            self::$calls++;
            if (self::$throw) {
                throw new \RuntimeException('instance export blew up');
            }
            return array(
                'items' => self::$items,
                'errors' => array(),
            );
        }

        public static function reset()
        {
            self::$calls = 0;
            self::$items = array();
            self::$throw = false;
            self::$constructed = 0;
        }
    }

    class StaticExporter
    {
        public static $calls = 0;
        public static $constructed = 0;

        public function __construct()
        {
            self::$constructed++;
            throw new \RuntimeException('static exporter must not be constructed');
        }

        public static function export()
        {
            self::$calls++;
            return array(
                'items' => array(array('title' => 'From static', 'status' => 'publish')),
                'errors' => array(),
            );
        }

        public static function reset()
        {
            self::$calls = 0;
            self::$constructed = 0;
        }
    }

    class NoExport
    {
    }

    class PrivateExport
    {
        private function export()
        {
            return array(
                'items' => array(array('title' => 'hidden')),
                'errors' => array(),
            );
        }
    }

    class RequiredArgExport
    {
        public function export($required)
        {
            return array(
                'items' => array(array('title' => $required)),
                'errors' => array(),
            );
        }
    }

    class ConstructedExport
    {
        public function __construct($required)
        {
        }

        public function export()
        {
            return array(
                'items' => array(array('title' => 'constructed')),
                'errors' => array(),
            );
        }
    }

    abstract class AbstractExport
    {
        public function export()
        {
            return array(
                'items' => array(array('title' => 'abstract')),
                'errors' => array(),
            );
        }
    }

    class InstanceParent
    {
        public function export()
        {
            return array(
                'items' => array(array('title' => 'Inherited', 'group' => 'post:9')),
                'errors' => array(),
            );
        }
    }

    class InstanceChild extends InstanceParent
    {
        public static $constructed = 0;

        public function __construct()
        {
            self::$constructed++;
        }
    }

    class StaticParent
    {
        public static function export()
        {
            return array(
                'items' => array(array('title' => 'Static parent', 'status' => 'publish')),
                'errors' => array(),
            );
        }
    }

    class StaticChild extends StaticParent
    {
        public static $constructed = 0;

        public function __construct()
        {
            self::$constructed++;
            throw new \RuntimeException('do not construct static child');
        }
    }

    class ThrowingConstructor
    {
        public static $constructed = 0;
        public static $exports = 0;
        public static $throw_error = false;

        public function __construct()
        {
            self::$constructed++;
            if (self::$throw_error) {
                throw new \Error('constructor blew up');
            }
            throw new \RuntimeException('constructor blew up');
        }

        public function export()
        {
            self::$exports++;
            return array(
                'items' => array(array('title' => 'should not export')),
                'errors' => array(),
            );
        }

        public static function reset()
        {
            self::$constructed = 0;
            self::$exports = 0;
            self::$throw_error = false;
        }
    }

    class Schema
    {
        public static $parts = null;
        public static $result = null;
        public static $use_result = false;
        public static $throw = false;
        public static $calls = 0;

        public static function package($parts)
        {
            self::$calls++;
            self::$parts = $parts;
            if (self::$throw) {
                throw new \RuntimeException('package blew up');
            }
            if (self::$use_result) {
                return self::$result;
            }
            $package = array(
                'format' => 'tolka-migration',
                'version' => 1,
            );
            foreach ($parts as $key => $envelope) {
                $package[$key] = (isset($envelope['items']) && is_array($envelope['items'])) ? $envelope['items'] : array();
            }
            return $package;
        }

        public static function reset()
        {
            self::$parts = null;
            self::$result = null;
            self::$use_result = false;
            self::$throw = false;
            self::$calls = 0;
        }
    }

    class Writer
    {
        public static $package = null;
        public static $result = array('ok' => true, 'errors' => array());
        public static $use_result = false;
        public static $throw = false;
        public static $throw_error = false;
        public static $calls = 0;

        public static function write($package)
        {
            self::$calls++;
            self::$package = $package;
            if (self::$throw) {
                if (self::$throw_error) {
                    throw new \Error('write blew up');
                }
                throw new \RuntimeException('write blew up');
            }
            if (self::$use_result) {
                return self::$result;
            }
            return array(
                'ok' => true,
                'errors' => array(),
            );
        }

        public static function reset()
        {
            self::$package = null;
            self::$result = array('ok' => true, 'errors' => array());
            self::$use_result = false;
            self::$throw = false;
            self::$throw_error = false;
            self::$calls = 0;
        }
    }
}

namespace {

    use PHPUnit\Framework\TestCase;

    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . '/');
    }

    $wmm_tolka_runner_file = dirname(__DIR__, 2) . '/includes/tolka/class-migration-runner.php';
    if (!class_exists('WMM\\Tolka\\Migration_Runner', false) && is_readable($wmm_tolka_runner_file)) {
        require_once $wmm_tolka_runner_file;
    }

    class Migration_Runner_Test_Double extends \WMM\Tolka\Migration_Runner
    {
        public static $map = array();
        public static $schema = 'WMM\\Tolka\\RunnerTest\\Schema';
        public static $writer = 'WMM\\Tolka\\RunnerTest\\Writer';

        protected static function exporters()
        {
            return self::$map;
        }

        protected static function schema_class()
        {
            return self::$schema;
        }

        protected static function writer_class()
        {
            return self::$writer;
        }
    }

    class Migration_Runner_Map_Probe extends \WMM\Tolka\Migration_Runner
    {
        public static function map()
        {
            return static::exporters();
        }

        public static function schema()
        {
            return static::schema_class();
        }

        public static function writer()
        {
            return static::writer_class();
        }
    }

    class Test_Migration_Runner extends TestCase
    {
        private function reset_doubles()
        {
            \WMM\Tolka\RunnerTest\Exporter::reset();
            \WMM\Tolka\RunnerTest\InstanceExporter::reset();
            \WMM\Tolka\RunnerTest\StaticExporter::reset();
            \WMM\Tolka\RunnerTest\ThrowingConstructor::reset();
            \WMM\Tolka\RunnerTest\Schema::reset();
            \WMM\Tolka\RunnerTest\Writer::reset();
            $map = array();
            foreach (array('languages', 'posts', 'terms', 'strings', 'menus', 'media') as $section) {
                $map[$section] = 'WMM\\Tolka\\RunnerTest\\Exporter';
            }
            Migration_Runner_Test_Double::$map = $map;
            Migration_Runner_Test_Double::$schema = 'WMM\\Tolka\\RunnerTest\\Schema';
            Migration_Runner_Test_Double::$writer = 'WMM\\Tolka\\RunnerTest\\Writer';
        }

        public function test_contract_class_map()
        {
            $this->assertSame(
                array(
                    'languages' => 'WMM\\Tolka\\Polylang_Languages',
                    'posts' => 'WMM\\Tolka\\Polylang_Posts',
                    'terms' => 'WMM\\Tolka\\Polylang_Terms',
                    'strings' => 'WMM\\Tolka\\Polylang_Strings',
                    'menus' => 'WMM\\Tolka\\Polylang_Menus',
                    'media' => 'WMM\\Tolka\\Polylang_Media',
                ),
                Migration_Runner_Map_Probe::map()
            );
            $this->assertSame('WMM\\Tolka\\Tolka_Schema', Migration_Runner_Map_Probe::schema());
            $this->assertSame('WMM\\Tolka\\Tolka_Writer', Migration_Runner_Map_Probe::writer());
            $this->assertSame(20, \WMM\Tolka\Migration_Runner::SAMPLE_LIMIT);
        }

        public function test_runner_does_not_delete_or_write_directly()
        {
            $source = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/class-migration-runner.php');
            $this->assertIsString($source);
            $this->assertStringContainsString('namespace WMM\\Tolka;', $source);
            $this->assertStringNotContainsString('strict_types', $source);
            foreach (array(
                'delete_option(',
                'delete_post_meta(',
                'delete_term_meta(',
                'delete_metadata(',
                'wp_delete_post(',
                'wp_delete_term(',
                'wp_delete_attachment(',
                'unregister_',
                'update_option(',
                'update_post_meta(',
                'update_term_meta(',
            ) as $needle) {
                $this->assertStringNotContainsString($needle, $source);
            }
        }

        public function test_missing_collaborators_do_not_fatal()
        {
            foreach (array(
                'WMM\\Tolka\\Polylang_Languages',
                'WMM\\Tolka\\Polylang_Posts',
                'WMM\\Tolka\\Polylang_Terms',
                'WMM\\Tolka\\Polylang_Strings',
                'WMM\\Tolka\\Polylang_Menus',
                'WMM\\Tolka\\Polylang_Media',
                'WMM\\Tolka\\Tolka_Schema',
                'WMM\\Tolka\\Tolka_Writer',
            ) as $class) {
                if (class_exists($class, false)) {
                    $this->markTestSkipped('Collaborator classes are loaded; this test covers the missing-class path.');
                }
            }

            $preview = \WMM\Tolka\Migration_Runner::preview();
            $this->assertSame(array(
                'WMM\\Tolka\\Polylang_Languages',
                'WMM\\Tolka\\Polylang_Posts',
                'WMM\\Tolka\\Polylang_Terms',
                'WMM\\Tolka\\Polylang_Strings',
                'WMM\\Tolka\\Polylang_Menus',
                'WMM\\Tolka\\Polylang_Media',
            ), $preview['missing']);
            $this->assertSame(0, $preview['counts']['languages']);
            $this->assertSame(array(), $preview['sample']['posts']);
            $this->assertSame('missing', $preview['sections']['media']['status']);
            $this->assertSame(
                'Media items were skipped because Polylang_Media is not available.',
                $preview['sections']['media']['message']
            );
            $this->assertSame('missing', $preview['state']);
            $this->assertSame(
                'Nothing could be previewed because the Polylang exporters are not available. Activate the rest of the migration plugin and try again.',
                $preview['message']
            );
            $this->assertArrayNotHasKey('write', $preview);

            $collected = \WMM\Tolka\Migration_Runner::collect();
            $this->assertSame(
                array('languages', 'posts', 'terms', 'strings', 'menus', 'media', 'missing'),
                array_keys($collected)
            );
            $this->assertSame(array(), $collected['languages']['items']);

            $run = \WMM\Tolka\Migration_Runner::run();
            $this->assertFalse($run['ok']);
            $this->assertNull($run['write']);
            $this->assertContains('WMM\\Tolka\\Tolka_Schema', $run['missing']);
            $this->assertContains('WMM\\Tolka\\Tolka_Writer', $run['missing']);
            $this->assertContains(
                'The migration package could not be built because Tolka_Schema is not available. Nothing was written, and existing Polylang data was not changed.',
                $run['errors']
            );
            $this->assertContains(
                'Tolka_Writer is not available, so there is nowhere to save the migration.',
                $run['errors']
            );
            $this->assertSame('blocked', $run['state']);
            $this->assertSame(
                'The migration did not run. Nothing was written, and existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertNotFalse(json_encode($preview));
            $this->assertNotFalse(json_encode($run));
        }

        public function test_empty_sections_have_finished_sentences()
        {
            $this->reset_doubles();
            $preview = Migration_Runner_Test_Double::preview();
            $expected = array(
                'languages' => 'No languages were found.',
                'posts' => 'No posts were found.',
                'terms' => 'No terms were found.',
                'strings' => 'No strings were found.',
                'menus' => 'No menus were found.',
                'media' => 'No media items were found.',
            );
            foreach ($expected as $section => $message) {
                $this->assertSame('empty', $preview['sections'][$section]['status']);
                $this->assertSame(0, $preview['counts'][$section]);
                $this->assertSame(array(), $preview['sample'][$section]);
                $this->assertSame(array(), $preview['sections'][$section]['labels']);
                $this->assertSame(0, $preview['sections'][$section]['more']);
                $this->assertSame('', $preview['sections'][$section]['more_label']);
                $this->assertSame(0, $preview['sections'][$section]['error_count']);
                $this->assertSame($message, $preview['sections'][$section]['message']);
            }
            $this->assertSame(array(), $preview['errors']);
            $this->assertSame('', $preview['detail']);
            $this->assertSame(array(), $preview['missing']);
            $this->assertSame('empty', $preview['state']);
            $this->assertSame(
                'Nothing was found to migrate. There are no languages, posts, terms, strings, menus, or media items to move yet.',
                $preview['message']
            );
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);

            $run = Migration_Runner_Test_Double::run();
            $this->assertTrue($run['ok']);
            $this->assertSame('empty', $run['state']);
            $this->assertSame(
                'Migration finished, but there was nothing to move. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertTrue($run['write']['ok']);
        }

        public function test_singular_and_plural_section_sentences()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'only'));
            $one = Migration_Runner_Test_Double::preview();
            $this->assertSame('1 language is ready to migrate.', $one['sections']['languages']['message']);
            $this->assertSame('1 post is ready to migrate.', $one['sections']['posts']['message']);
            $this->assertSame('1 term is ready to migrate.', $one['sections']['terms']['message']);
            $this->assertSame('1 string is ready to migrate.', $one['sections']['strings']['message']);
            $this->assertSame('1 menu is ready to migrate.', $one['sections']['menus']['message']);
            $this->assertSame('1 media item is ready to migrate.', $one['sections']['media']['message']);
            $this->assertSame('ready', $one['sections']['languages']['status']);

            \WMM\Tolka\RunnerTest\Exporter::reset();
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'a'), array('slug' => 'b'));
            $two = Migration_Runner_Test_Double::preview();
            $this->assertSame('2 languages are ready to migrate.', $two['sections']['languages']['message']);
            $this->assertSame('2 posts are ready to migrate.', $two['sections']['posts']['message']);
            $this->assertSame('2 media items are ready to migrate.', $two['sections']['media']['message']);
        }

        public function test_preview_sample_is_capped_at_twenty_and_keeps_order()
        {
            $this->reset_doubles();
            $items = array();
            for ($i = 0; $i < 25; $i++) {
                $items[] = array('slug' => 's' . $i, 'title' => 'Item ' . $i);
            }
            \WMM\Tolka\RunnerTest\Exporter::$items = $items;

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(25, $preview['counts']['posts']);
            $this->assertCount(20, $preview['sample']['posts']);
            $this->assertSame('s0', $preview['sample']['posts'][0]['slug']);
            $this->assertSame('s19', $preview['sample']['posts'][19]['slug']);
            $this->assertSame('Item 19', $preview['sections']['posts']['labels'][19]);
            $this->assertSame(5, $preview['sections']['posts']['more']);
            $this->assertSame('5 more are not shown.', $preview['sections']['posts']['more_label']);
            $this->assertSame(25, $preview['sections']['posts']['count']);
            $this->assertSame(
                '25 posts are ready to migrate. The preview shows the first 20.',
                $preview['sections']['posts']['message']
            );
            $encoded = json_encode($preview['sample']);
            $this->assertNotFalse($encoded);
            $this->assertStringNotContainsString('s20', $encoded);
            $this->assertStringNotContainsString('s24', $encoded);
        }

        public function test_sample_shortens_display_copy_and_run_keeps_full_values()
        {
            $this->reset_doubles();
            $content = str_repeat('a', 450);
            $items = array(
                array(
                    'title' => "Hello\nworld",
                    'content' => $content,
                    'score' => INF,
                    'extra' => new \stdClass(),
                ),
            );
            \WMM\Tolka\RunnerTest\Exporter::$items = $items;

            $preview = Migration_Runner_Test_Double::preview();
            $sample = $preview['sample']['posts'][0];
            $this->assertSame('Hello world', $sample['title']);
            $this->assertSame(403, strlen($sample['content']));
            $this->assertSame(str_repeat('a', 400) . '...', $sample['content']);
            $this->assertNull($sample['score']);
            $this->assertSame('Unsupported value', $sample['extra']);
            $this->assertSame(450, strlen($items[0]['content']));
            $this->assertNotFalse(json_encode($preview));
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);

            $run = Migration_Runner_Test_Double::run();
            $this->assertSame(450, strlen($run['write'] === null ? '' : \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items'][0]['content']));
            $this->assertSame($content, \WMM\Tolka\RunnerTest\Writer::$package['posts'][0]['content']);
            $this->assertArrayNotHasKey('missing', \WMM\Tolka\RunnerTest\Schema::$parts);
            $this->assertSame(
                array('languages', 'posts', 'terms', 'strings', 'menus', 'media'),
                array_keys(\WMM\Tolka\RunnerTest\Schema::$parts)
            );
            $this->assertTrue($run['ok']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
        }

        public function test_sample_labels_for_each_section()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                array(
                    'items' => array(
                    array(
                        'name' => 'English',
                        'slug' => 'en',
                        'is_default' => true,
                    ),
                    array(
                        'name' => 'Svenska',
                        'slug' => 'sv',
                        'is_default' => false,
                    ),
                    array(
                        'name' => 'Deutsch',
                        'slug' => 'de',
                        'is_default' => '0',
                    ),
                ),
                    'errors' => array(),
                ),
                array(
                    'items' => array(array(
                        'title' => '<script>alert(1)</script>Hello',
                        'group' => 'post:10',
                        'status' => 'publish',
                    )),
                    'errors' => array(),
                ),
                array(
                    'items' => array(array(
                        'name' => 'News',
                        'taxonomy' => 'category',
                        'slug' => 'news',
                    )),
                    'errors' => array(),
                ),
                array(
                    'items' => array(array(
                        'name' => 'Site title',
                        'string' => 'Hello',
                        'context' => 'header',
                    )),
                    'errors' => array(),
                ),
                array(
                    'items' => array(array(
                        'name' => 'Primary',
                        'location' => 'footer',
                    )),
                    'errors' => array(),
                ),
                array(
                    'items' => array(array(
                        'attachment_id' => 12,
                    )),
                    'errors' => array(),
                ),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame('English (en), default', $preview['sections']['languages']['labels'][0]);
            $this->assertSame('Svenska (sv)', $preview['sections']['languages']['labels'][1]);
            $this->assertSame('Deutsch (de)', $preview['sections']['languages']['labels'][2]);
            $this->assertSame('alert(1)Hello', $preview['sections']['posts']['labels'][0]);
            $this->assertStringNotContainsString('<script', $preview['sections']['posts']['labels'][0]);
            $this->assertSame('News (category)', $preview['sections']['terms']['labels'][0]);
            $this->assertSame('Site title (header)', $preview['sections']['strings']['labels'][0]);
            $this->assertSame('Primary (footer)', $preview['sections']['menus']['labels'][0]);
            $this->assertSame('Attachment 12', $preview['sections']['media']['labels'][0]);
        }

        public function test_post_label_falls_back_to_group()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                0 => array(
                    'items' => array(array()),
                    'errors' => array(),
                ),
                1 => array(
                    'items' => array(array('group' => 'post:10', 'status' => 'draft')),
                    'errors' => array(),
                ),
            );
            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame('post:10 (draft)', $preview['sections']['posts']['labels'][0]);
            $this->assertSame('Untitled language', $preview['sections']['languages']['labels'][0]);
        }

        public function test_exporter_errors_are_prefixed_and_partial_save_is_not_ok()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
            \WMM\Tolka\RunnerTest\Exporter::$errors = array(
                'Polylang is not active on this site.',
                'Polylang is not active on this site.',
                'Languages already included.',
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(
                array(
                    'Languages: Polylang is not active on this site.',
                    'Languages already included.',
                ),
                array_slice($preview['errors'], 0, 2)
            );
            $this->assertSame('error', $preview['sections']['languages']['status']);
            $this->assertSame(2, $preview['sections']['languages']['error_count']);
            $this->assertSame(
                'Languages could not be fully read. Review the errors before migrating. 1 language was still found.',
                $preview['sections']['languages']['message']
            );
            $this->assertSame('error', $preview['state']);
            $this->assertSame(
                'The preview finished with errors in Languages, Posts, Terms, Strings, Menus, and Media. Review them before migrating. Existing Polylang data was not changed.',
                $preview['message']
            );

            $run = Migration_Runner_Test_Double::run();
            $this->assertFalse($run['ok']);
            $this->assertSame('partial', $run['state']);
            $this->assertTrue($run['write']['ok']);
            $this->assertSame(
                'The Tolka package was saved, but Languages, Posts, Terms, Strings, Menus, and Media could not be read. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
        }

        public function test_error_list_is_capped()
        {
            $this->reset_doubles();
            $errors = array();
            for ($i = 0; $i < 40; $i++) {
                $errors[] = 'Problem ' . $i;
            }
            \WMM\Tolka\RunnerTest\Exporter::$errors = $errors;
            $preview = Migration_Runner_Test_Double::preview();
            $limit = \WMM\Tolka\Migration_Runner::ERROR_LIMIT;
            $this->assertCount($limit + 1, $preview['errors']);
            $this->assertSame('Languages: Problem 0', $preview['errors'][0]);
            $this->assertSame(
                sprintf('%d more errors were omitted from this list.', (40 * 6) - $limit),
                $preview['errors'][$limit]
            );
        }

        public function test_single_omitted_error_uses_singular_copy()
        {
            $this->reset_doubles();
            $errors = array();
            $extra = \WMM\Tolka\Migration_Runner::ERROR_LIMIT + 1;
            for ($i = 0; $i < $extra; $i++) {
                $errors[] = 'Problem ' . $i;
            }
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                0 => array('items' => array(), 'errors' => $errors),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertCount($extra, $preview['errors']);
            $this->assertSame(
                '1 more error was omitted from this list.',
                $preview['errors'][\WMM\Tolka\Migration_Runner::ERROR_LIMIT]
            );
        }

        public function test_non_array_items_do_not_fatal()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                0 => array(
                    'items' => array('not-a-record', 12),
                    'errors' => array(),
                ),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(2, $preview['counts']['languages']);
            $this->assertSame('Untitled item', $preview['sections']['languages']['labels'][0]);
            $this->assertSame('Untitled item', $preview['sections']['languages']['labels'][1]);
            $this->assertSame('not-a-record', $preview['sample']['languages'][0]);
            $this->assertSame(12, $preview['sample']['languages'][1]);
            $this->assertNotFalse(json_encode($preview));
        }

        public function test_padded_class_names_still_resolve()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['languages'] = '  WMM\\Tolka\\RunnerTest\\Exporter  ';
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Exporter', $preview['missing']);
            $this->assertSame('ready', $preview['sections']['languages']['status']);
            $this->assertSame('English (en)', $preview['sections']['languages']['labels'][0]);
        }

        public function test_throw_invalid_and_unusable_exporters_do_not_fatal()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\NoExport';
            // Exporter calls skip the unusable posts class: 0 languages, 1 terms, 2 strings, 3 menus, 4 media.
            \WMM\Tolka\RunnerTest\Exporter::$throw_on = 1;
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                2 => 'not-an-envelope',
                3 => array('items' => array(), 'errors' => array(array('nope' => true))),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(
                'Posts could not be read because NoExport does not define export().',
                $preview['errors'][0]
            );
            $this->assertContains(
                'Posts could not be read because NoExport does not define export().',
                $preview['errors']
            );
            $this->assertContains('Terms could not be read (export blew up).', $preview['errors']);
            $this->assertContains(
                'Strings could not be read because the exporter returned an unexpected result.',
                $preview['errors']
            );
            $this->assertContains(
                'Menus reported an error that could not be displayed.',
                $preview['errors']
            );
            $this->assertSame('error', $preview['sections']['posts']['status']);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\NoExport', $preview['missing']);
            $this->assertNotFalse(json_encode($preview));
        }

        public function test_one_missing_exporter_keeps_the_other_sections()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['terms'] = 'WMM\\Tolka\\RunnerTest\\NotLoaded';
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(array('WMM\\Tolka\\RunnerTest\\NotLoaded'), $preview['missing']);
            $this->assertSame(0, $preview['counts']['terms']);
            $this->assertSame(1, $preview['counts']['languages']);
            $this->assertSame('missing', $preview['sections']['terms']['status']);
            $this->assertSame(
                'Terms were skipped because NotLoaded is not available.',
                $preview['sections']['terms']['message']
            );
            $this->assertSame('incomplete', $preview['state']);
            $this->assertSame(1, $preview['sections']['terms']['error_count']);
            $this->assertSame(
                'The preview is incomplete because Terms could not be read. Existing Polylang data was not changed.',
                $preview['message']
            );

            $run = Migration_Runner_Test_Double::run();
            $this->assertFalse($run['ok']);
            $this->assertSame('partial', $run['state']);
            $this->assertSame(
                'The Tolka package was saved, but Terms could not be read. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame(array(), \WMM\Tolka\RunnerTest\Schema::$parts['terms']['items']);
            $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['languages']['items']);
            $this->assertArrayNotHasKey('missing', \WMM\Tolka\RunnerTest\Schema::$parts);
        }

        public function test_several_skipped_sections_are_named_in_the_result()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['terms'] = 'WMM\\Tolka\\RunnerTest\\NotLoaded';
            Migration_Runner_Test_Double::$map['media'] = 'WMM\\Tolka\\RunnerTest\\AlsoNotLoaded';
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en'));

            $run = Migration_Runner_Test_Double::run();
            $this->assertSame('partial', $run['state']);
            $this->assertSame(
                'The Tolka package was saved, but Terms and Media could not be read. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame(
                array('WMM\\Tolka\\RunnerTest\\NotLoaded', 'WMM\\Tolka\\RunnerTest\\AlsoNotLoaded'),
                $run['missing']
            );
        }

        public function test_unregistered_section_is_explained()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['menus'] = '';
            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame(array(), $preview['missing']);
            $this->assertContains(
                'Menus were skipped because no exporter is registered.',
                $preview['errors']
            );
            $this->assertSame('error', $preview['sections']['menus']['status']);
        }

        public function test_schema_and_writer_failures()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en'));

            Migration_Runner_Test_Double::$schema = 'WMM\\Tolka\\RunnerTest\\NoSchema';
            Migration_Runner_Test_Double::$writer = 'WMM\\Tolka\\RunnerTest\\Writer';
            $missing_schema = Migration_Runner_Test_Double::run();
            $this->assertFalse($missing_schema['ok']);
            $this->assertNull($missing_schema['write']);
            $this->assertContains('WMM\\Tolka\\RunnerTest\\NoSchema', $missing_schema['missing']);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Writer', $missing_schema['missing']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertContains(
                'The migration package could not be built because NoSchema is not available. Nothing was written, and existing Polylang data was not changed.',
                $missing_schema['errors']
            );
            $this->assertSame(
                'The migration did not run. Nothing was written, and existing Polylang data was not changed.',
                $missing_schema['message']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en'));
            Migration_Runner_Test_Double::$schema = 'WMM\\Tolka\\RunnerTest\\NoSchema';
            Migration_Runner_Test_Double::$writer = 'WMM\\Tolka\\RunnerTest\\NoWriter';
            $both = Migration_Runner_Test_Double::run();
            $this->assertContains('WMM\\Tolka\\RunnerTest\\NoSchema', $both['missing']);
            $this->assertContains('WMM\\Tolka\\RunnerTest\\NoWriter', $both['missing']);
            $this->assertContains(
                'NoWriter is not available, so there is nowhere to save the migration.',
                $both['errors']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en'));
            Migration_Runner_Test_Double::$writer = 'WMM\\Tolka\\RunnerTest\\NoWriter';
            $missing_writer = Migration_Runner_Test_Double::run();
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertNull($missing_writer['write']);
            $this->assertContains(
                'The migration could not be saved because NoWriter is not available. Nothing was written, and existing Polylang data was not changed.',
                $missing_writer['errors']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Schema::$throw = true;
            $thrown_schema = Migration_Runner_Test_Double::run();
            $this->assertNull($thrown_schema['write']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertContains(
                'The migration package could not be built (package blew up). Nothing was written, and existing Polylang data was not changed.',
                $thrown_schema['errors']
            );
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Schema', $thrown_schema['missing']);

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Schema::$use_result = true;
            \WMM\Tolka\RunnerTest\Schema::$result = 'bad-package';
            $bad_schema = Migration_Runner_Test_Double::run();
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertContains(
                'The migration package could not be built because Schema returned an unexpected result. Nothing was written, and existing Polylang data was not changed.',
                $bad_schema['errors']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Schema::$use_result = true;
            \WMM\Tolka\RunnerTest\Schema::$result = array('format' => 'tolka-migration', 'marker' => 7);
            \WMM\Tolka\RunnerTest\Writer::$throw = true;
            $thrown_writer = Migration_Runner_Test_Double::run();
            $this->assertSame(7, \WMM\Tolka\RunnerTest\Writer::$package['marker']);
            $this->assertNull($thrown_writer['write']);
            $this->assertContains(
                'The migration could not be saved (write blew up). Existing Polylang data was not changed.',
                $thrown_writer['errors']
            );
            $this->assertSame('failed', $thrown_writer['state']);
            $this->assertFalse($thrown_writer['ok']);
            $this->assertStringNotContainsString('Migration finished', $thrown_writer['message']);
            $this->assertSame(
                'The migration could not be saved (write blew up). Existing Polylang data was not changed.',
                $thrown_writer['message']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Writer::$use_result = true;
            \WMM\Tolka\RunnerTest\Writer::$result = 'nope';
            $bad_writer = Migration_Runner_Test_Double::run();
            $this->assertNull($bad_writer['write']);
            $this->assertContains(
                'The migration could not be saved because Writer returned an unexpected result. Existing Polylang data was not changed.',
                $bad_writer['errors']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Writer::$use_result = true;
            \WMM\Tolka\RunnerTest\Writer::$result = array('ok' => false, 'errors' => array());
            $failed = Migration_Runner_Test_Double::run();
            $this->assertFalse($failed['ok']);
            $this->assertSame(array('ok' => false, 'errors' => array()), $failed['write']);
            $this->assertContains(
                'The migration could not be saved. Existing Polylang data was not changed.',
                $failed['errors']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Writer::$use_result = true;
            \WMM\Tolka\RunnerTest\Writer::$result = array(
                'ok' => false,
                'errors' => array('Could not update post 5.'),
                'counts' => array('posts' => 0),
            );
            $failed_with_errors = Migration_Runner_Test_Double::run();
            $this->assertSame(
                array(
                    'ok' => false,
                    'errors' => array('Could not update post 5.'),
                    'counts' => array('posts' => 0),
                ),
                $failed_with_errors['write']
            );
            $this->assertContains('Could not update post 5.', $failed_with_errors['errors']);
            $this->assertNotContains(
                'The migration could not be saved. Existing Polylang data was not changed.',
                $failed_with_errors['errors']
            );
        }

        public function test_success_message_lists_every_section()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                array('items' => array(array('slug' => 'en'), array('slug' => 'sv')), 'errors' => array()),
                array('items' => array(array('title' => 'Hello')), 'errors' => array()),
                array('items' => array(), 'errors' => array()),
                array('items' => array(
                    array('name' => 'A'),
                    array('name' => 'B'),
                    array('name' => 'C'),
                ), 'errors' => array()),
                array('items' => array(), 'errors' => array()),
                array('items' => array(
                    array('file' => 'a.jpg'),
                    array('file' => 'b.jpg'),
                    array('file' => 'c.jpg'),
                    array('file' => 'd.jpg'),
                ), 'errors' => array()),
            );

            $run = Migration_Runner_Test_Double::run();
            $this->assertTrue($run['ok']);
            $this->assertSame(
                array(
                    'languages' => 2,
                    'posts' => 1,
                    'terms' => 0,
                    'strings' => 3,
                    'menus' => 0,
                    'media' => 4,
                ),
                $run['counts']
            );
            $this->assertSame('saved', $run['state']);
            $this->assertSame('', $run['detail']);
            $this->assertSame(
                'Migration finished. Saved 2 languages, 1 post, 0 terms, 3 strings, 0 menus, and 4 media items for Tolka. Existing Polylang data was not changed.',
                $run['message']
            );
            \WMM\Tolka\RunnerTest\Exporter::$calls = 0;
            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame('ready', $preview['state']);
            $this->assertSame(
                'Preview ready. Found 2 languages, 1 post, 0 terms, 3 strings, 0 menus, and 4 media items.',
                $preview['message']
            );
        }

        public function test_inactive_polylang_style_export_still_calls_the_writer()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array();
            \WMM\Tolka\RunnerTest\Exporter::$errors = array('Polylang is not active on this site.');

            $run = Migration_Runner_Test_Double::run();
            $this->assertFalse($run['ok']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertSame(array(), \WMM\Tolka\RunnerTest\Writer::$package['languages']);
            $this->assertContains(
                'Languages: Polylang is not active on this site.',
                $run['errors']
            );
        }

        public function test_preview_can_be_repeated()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
            $first = Migration_Runner_Test_Double::preview();
            $second = Migration_Runner_Test_Double::preview();
            $this->assertSame($first, $second);
            $this->assertSame(12, \WMM\Tolka\RunnerTest\Exporter::$calls);
        }

        public function test_sample_survives_loops_and_duplicate_keys()
        {
            $this->reset_doubles();
            $loop = array('title' => 'Loop');
            $loop['again'] = &$loop;
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                1 => array(
                    'items' => array(
                        $loop,
                        array(
                            'Hello' => 'one',
                            "Hello\n" => 'two',
                        ),
                    ),
                    'errors' => array(),
                ),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $sample = $preview['sample']['posts'];
            $this->assertSame('Loop', $sample[0]['title']);
            $this->assertArrayHasKey('again', $sample[0]);
            $this->assertSame('one', $sample[1]['Hello']);
            $this->assertSame('two', $sample[1]['Hello 2']);
            $this->assertSame('Loop', $loop['title']);
            $this->assertNotFalse(json_encode($preview));
        }

        public function test_writer_errors_are_plain_sentences()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Writer::$use_result = true;
            \WMM\Tolka\RunnerTest\Writer::$result = array(
                'ok' => false,
                'errors' => array("<strong>Could not update post 5.</strong>\nTry again."),
            );

            $run = Migration_Runner_Test_Double::run();
            $this->assertContains('Could not update post 5. Try again.', $run['errors']);
            $this->assertStringNotContainsString('<strong>', implode("\n", $run['errors']));
            $this->assertSame('failed', $run['state']);
        }

        public function test_writer_returning_ok_false_is_stored_and_not_successful()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
            \WMM\Tolka\RunnerTest\Writer::$use_result = true;
            \WMM\Tolka\RunnerTest\Writer::$result = array(
                'ok' => false,
                'counts' => array(
                    'languages' => 0,
                    'posts' => 0,
                    'terms' => 0,
                    'strings' => 0,
                    'menus' => 0,
                    'media' => 0,
                ),
                'errors' => array('The Tolka package could not be stored.'),
            );

            $run = Migration_Runner_Test_Double::run();

            $this->assertFalse($run['ok']);
            $this->assertSame('failed', $run['state']);
            $this->assertSame(\WMM\Tolka\RunnerTest\Writer::$result, $run['write']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Schema', $run['missing']);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Writer', $run['missing']);
            $this->assertContains('The Tolka package could not be stored.', $run['errors']);
            $this->assertNotContains(
                'The migration could not be saved. Existing Polylang data was not changed.',
                $run['errors']
            );
            $this->assertSame(1, $run['counts']['languages']);
            $this->assertSame(
                'The migration could not be completed. Review the errors before trying again. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame('The Tolka package could not be stored.', $run['detail']);
            $this->assertArrayHasKey('languages', \WMM\Tolka\RunnerTest\Writer::$package);
            $this->assertArrayNotHasKey('missing', \WMM\Tolka\RunnerTest\Schema::$parts);
        }

        public function test_missing_schema_does_not_call_the_writer()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('title' => 'Hello'));
            Migration_Runner_Test_Double::$schema = 'WMM\\Tolka\\RunnerTest\\NoSchema';

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame('ready', $preview['state']);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\NoSchema', $preview['missing']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);

            $run = Migration_Runner_Test_Double::run();
            $this->assertFalse($run['ok']);
            $this->assertSame('blocked', $run['state']);
            $this->assertNull($run['write']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertContains('WMM\\Tolka\\RunnerTest\\NoSchema', $run['missing']);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Writer', $run['missing']);
            $this->assertSame(1, $run['counts']['posts']);
            $this->assertContains(
                'The migration package could not be built because NoSchema is not available. Nothing was written, and existing Polylang data was not changed.',
                $run['errors']
            );
            $this->assertSame(
                'The migration did not run. Nothing was written, and existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame(
                'The migration package could not be built because NoSchema is not available. Nothing was written, and existing Polylang data was not changed.',
                $run['detail']
            );
        }

        public function test_preview_caps_only_the_section_longer_than_twenty()
        {
            $this->reset_doubles();
            $posts = array();
            for ($i = 0; $i < 21; $i++) {
                $posts[] = array('title' => 'Post ' . $i, 'group' => 'post:' . $i);
            }
            $strings = array();
            for ($i = 0; $i < 20; $i++) {
                $strings[] = array('name' => 'String ' . $i);
            }
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                0 => array(
                    'items' => array(array('name' => 'English', 'slug' => 'en', 'is_default' => true)),
                    'errors' => array(),
                ),
                1 => array('items' => $posts, 'errors' => array()),
                2 => array('items' => array(), 'errors' => array()),
                3 => array('items' => $strings, 'errors' => array()),
                4 => array('items' => array(), 'errors' => array()),
                5 => array('items' => array(), 'errors' => array()),
            );

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(21, $preview['counts']['posts']);
            $this->assertCount(20, $preview['sample']['posts']);
            $this->assertSame('Post 0', $preview['sample']['posts'][0]['title']);
            $this->assertSame('Post 19', $preview['sample']['posts'][19]['title']);
            $this->assertSame(1, $preview['sections']['posts']['more']);
            $this->assertSame('1 more is not shown.', $preview['sections']['posts']['more_label']);
            $this->assertSame(
                '21 posts are ready to migrate. The preview shows the first 20.',
                $preview['sections']['posts']['message']
            );
            $titles = array();
            foreach ($preview['sample']['posts'] as $item) {
                $titles[] = $item['title'];
            }
            $this->assertNotContains('Post 20', $titles);

            $this->assertCount(1, $preview['sample']['languages']);
            $this->assertSame(0, $preview['sections']['languages']['more']);
            $this->assertSame('', $preview['sections']['languages']['more_label']);
            $this->assertSame('1 language is ready to migrate.', $preview['sections']['languages']['message']);

            $this->assertCount(20, $preview['sample']['strings']);
            $this->assertSame(20, $preview['counts']['strings']);
            $this->assertSame(0, $preview['sections']['strings']['more']);
            $this->assertSame('', $preview['sections']['strings']['more_label']);
            $this->assertSame('20 strings are ready to migrate.', $preview['sections']['strings']['message']);
            $this->assertSame(array(), $preview['sample']['terms']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
        }

        public function test_long_section_with_errors_still_caps_the_sample()
        {
            $this->reset_doubles();
            $posts = array();
            for ($i = 0; $i < 21; $i++) {
                $posts[] = array('title' => 'Draft ' . $i, 'status' => 'draft');
            }
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                1 => array(
                    'items' => $posts,
                    'errors' => array('One post could not be read.'),
                ),
            );

            $preview = Migration_Runner_Test_Double::preview();
            $this->assertSame('error', $preview['sections']['posts']['status']);
            $this->assertSame(21, $preview['counts']['posts']);
            $this->assertCount(20, $preview['sample']['posts']);
            $this->assertCount(20, $preview['sections']['posts']['labels']);
            $this->assertSame('Draft 0 (draft)', $preview['sections']['posts']['labels'][0]);
            $this->assertSame(1, $preview['sections']['posts']['more']);
            $this->assertSame('1 more is not shown.', $preview['sections']['posts']['more_label']);
            $this->assertSame(
                'Posts could not be fully read. Review the errors before migrating. 21 posts were still found. The preview shows the first 20.',
                $preview['sections']['posts']['message']
            );
            $this->assertContains('Posts: One post could not be read.', $preview['errors']);
            $this->assertSame('error', $preview['state']);

            \WMM\Tolka\RunnerTest\Exporter::$calls = 0;
            $run = Migration_Runner_Test_Double::run();
            $this->assertCount(21, \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items']);
            $this->assertCount(20, $run['sample']['posts']);
            $this->assertSame('Draft 20', \WMM\Tolka\RunnerTest\Writer::$package['posts'][20]['title']);
        }

        public function test_exporter_non_array_makes_the_run_not_ok()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('title' => 'Hello', 'slug' => 'hello'));
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                0 => null,
            );

            $run = Migration_Runner_Test_Double::run();

            $this->assertFalse($run['ok']);
            $this->assertNotSame('saved', $run['state']);
            $this->assertSame('partial', $run['state']);
            $this->assertSame('error', $run['sections']['languages']['status']);
            $this->assertSame(0, $run['counts']['languages']);
            $this->assertSame(1, $run['counts']['posts']);
            $this->assertSame(array(), \WMM\Tolka\RunnerTest\Schema::$parts['languages']['items']);
            $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Exporter', $run['missing']);
            $this->assertSame(
                'Languages could not be read because the exporter returned an unexpected result.',
                $run['detail']
            );
            $this->assertContains(
                'Languages could not be read because the exporter returned an unexpected result.',
                $run['errors']
            );
            $this->assertSame(
                'The Tolka package was saved, but Languages could not be read. Existing Polylang data was not changed.',
                $run['message']
            );

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$script = array(
                1 => 'not-an-envelope',
            );
            $posts = Migration_Runner_Test_Double::run();
            $this->assertFalse($posts['ok']);
            $this->assertSame('error', $posts['sections']['posts']['status']);
            $this->assertSame(
                'Posts could not be read. Review the errors before migrating.',
                $posts['sections']['posts']['message']
            );
            $this->assertContains(
                'Posts could not be read because the exporter returned an unexpected result.',
                $posts['errors']
            );
            $this->assertSame(0, $posts['counts']['posts']);
            $this->assertSame(array(), $posts['sample']['posts']);
            $this->assertSame(
                'The Tolka package was saved, but Posts could not be read. Existing Polylang data was not changed.',
                $posts['message']
            );
        }

        public function test_schema_non_array_does_not_call_the_writer()
        {
            foreach (array(null, false, 'tolka-migration') as $package) {
                $this->reset_doubles();
                \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
                \WMM\Tolka\RunnerTest\Schema::$use_result = true;
                \WMM\Tolka\RunnerTest\Schema::$result = $package;

                $run = Migration_Runner_Test_Double::run();

                $this->assertFalse($run['ok'], 'Run must not be ok when package is ' . gettype($package));
                $this->assertSame('blocked', $run['state']);
                $this->assertNull($run['write']);
                $this->assertSame(1, \WMM\Tolka\RunnerTest\Schema::$calls);
                $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
                $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Schema', $run['missing']);
                $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Writer', $run['missing']);
                $this->assertSame(
                    'The migration package could not be built because Schema returned an unexpected result. Nothing was written, and existing Polylang data was not changed.',
                    $run['detail']
                );
                $this->assertSame(
                    'The migration did not run. Nothing was written, and existing Polylang data was not changed.',
                    $run['message']
                );
            }
        }

        public function test_one_throwing_exporter_does_not_stop_the_others()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array(
                'slug' => 'en',
                'name' => 'English',
                'title' => 'Hello',
            ));
            \WMM\Tolka\RunnerTest\Exporter::$throw_on = 0;

            $collected = Migration_Runner_Test_Double::collect();
            $this->assertSame(
                array('languages', 'posts', 'terms', 'strings', 'menus', 'media', 'missing'),
                array_keys($collected)
            );
            $this->assertSame(array(), $collected['languages']['items']);
            $this->assertSame(
                array('Languages could not be read (export blew up).'),
                $collected['languages']['errors']
            );
            $this->assertCount(1, $collected['posts']['items']);
            $this->assertCount(1, $collected['media']['items']);
            $this->assertSame(array(), $collected['missing']);

            \WMM\Tolka\RunnerTest\Exporter::$calls = 0;
            $run = Migration_Runner_Test_Double::run();
            $this->assertFalse($run['ok']);
            $this->assertNotSame('saved', $run['state']);
            $this->assertStringNotContainsString('Migration finished', $run['message']);
            $this->assertContains('Languages could not be read (export blew up).', $run['errors']);
            $this->assertSame(
                'The Tolka package was saved, but Languages could not be read. Existing Polylang data was not changed.',
                $run['message']
            );
            $this->assertSame(array(), \WMM\Tolka\RunnerTest\Schema::$parts['languages']['items']);
            $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items']);
            $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['media']['items']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Exporter', $run['missing']);

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('title' => 'Still here'));
            \WMM\Tolka\RunnerTest\Exporter::$throw_on = 2;
            \WMM\Tolka\RunnerTest\Exporter::$throw_error = true;
            \WMM\Tolka\RunnerTest\Exporter::$throw_message = 'terms exploded';
            $again = Migration_Runner_Test_Double::collect();
            $this->assertSame(
                array('Terms could not be read (terms exploded).'),
                $again['terms']['errors']
            );
            $this->assertCount(1, $again['languages']['items']);
            $this->assertCount(1, $again['posts']['items']);
            $this->assertSame(array(), $again['terms']['items']);
            $this->assertCount(1, $again['menus']['items']);
        }

        public function test_writer_throw_is_not_a_finished_migration()
        {
            foreach (array(false, true) as $throw_error) {
                $this->reset_doubles();
                \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
                \WMM\Tolka\RunnerTest\Writer::$throw = true;
                \WMM\Tolka\RunnerTest\Writer::$throw_error = $throw_error;

                $run = Migration_Runner_Test_Double::run();

                $this->assertFalse($run['ok']);
                $this->assertNull($run['write']);
                $this->assertSame('failed', $run['state']);
                $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
                $this->assertStringNotContainsString('Migration finished', $run['message']);
                $this->assertStringNotContainsString('Migration finished', $run['detail']);
                $this->assertSame(
                    'The migration could not be saved (write blew up). Existing Polylang data was not changed.',
                    $run['message']
                );
                $this->assertSame($run['message'], $run['detail']);
                $this->assertNotContains('WMM\\Tolka\\RunnerTest\\Writer', $run['missing']);
            }
        }

        public function test_static_export_is_called_without_constructing()
        {
            $this->reset_doubles();
            Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\StaticExporter';

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(1, \WMM\Tolka\RunnerTest\StaticExporter::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\StaticExporter::$constructed);
            $this->assertSame('From static', $preview['sections']['posts']['labels'][0]);
            $this->assertSame('ready', $preview['sections']['posts']['status']);
            $this->assertSame(1, $preview['counts']['posts']);
            $this->assertSame(5, \WMM\Tolka\RunnerTest\Exporter::$calls);

            $run = Migration_Runner_Test_Double::run();
            $this->assertTrue($run['ok']);
            $this->assertSame('From static', \WMM\Tolka\RunnerTest\Writer::$package['posts'][0]['title']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\StaticExporter::$constructed);
        }

        public function test_instance_export_is_called_on_a_new_object()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\InstanceExporter::$items = array(array(
                'title' => 'From instance',
                'status' => 'draft',
            ));
            Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\InstanceExporter';
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(1, \WMM\Tolka\RunnerTest\InstanceExporter::$calls);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\InstanceExporter::$constructed);
            $this->assertSame('From instance (draft)', $preview['sections']['posts']['labels'][0]);
            $this->assertSame('ready', $preview['sections']['posts']['status']);
            $this->assertSame(1, $preview['counts']['languages']);
            $this->assertSame(5, \WMM\Tolka\RunnerTest\Exporter::$calls);

            \WMM\Tolka\RunnerTest\Exporter::$calls = 0;
            \WMM\Tolka\RunnerTest\InstanceExporter::$calls = 0;
            \WMM\Tolka\RunnerTest\InstanceExporter::$constructed = 0;
            $run = Migration_Runner_Test_Double::run();
            $this->assertTrue($run['ok']);
            $this->assertSame('From instance', \WMM\Tolka\RunnerTest\Writer::$package['posts'][0]['title']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\InstanceExporter::$constructed);

            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\InstanceExporter::$throw = true;
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('title' => 'Kept'));
            Migration_Runner_Test_Double::$map['terms'] = 'WMM\\Tolka\\RunnerTest\\InstanceExporter';
            $collected = Migration_Runner_Test_Double::collect();
            $this->assertSame(
                array('Terms could not be read (instance export blew up).'),
                $collected['terms']['errors']
            );
            $this->assertCount(1, $collected['posts']['items']);
            $this->assertSame(array(), $collected['terms']['items']);
            $this->assertSame(1, \WMM\Tolka\RunnerTest\InstanceExporter::$constructed);
        }

        public function test_missing_export_method_is_a_named_error()
        {
            $shapes = array(
                'WMM\\Tolka\\RunnerTest\\NoExport' => 'Posts could not be read because NoExport does not define export().',
                'WMM\\Tolka\\RunnerTest\\PrivateExport' => 'Posts could not be read because PrivateExport::export() cannot be called.',
                'WMM\\Tolka\\RunnerTest\\RequiredArgExport' => 'Posts could not be read because RequiredArgExport::export() cannot be called.',
                'WMM\\Tolka\\RunnerTest\\ConstructedExport' => 'Posts could not be read because ConstructedExport::export() cannot be called.',
                'WMM\\Tolka\\RunnerTest\\AbstractExport' => 'Posts could not be read because AbstractExport::export() cannot be called.',
            );

            foreach ($shapes as $class => $error) {
                $this->reset_doubles();
                \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
                Migration_Runner_Test_Double::$map['posts'] = $class;

                $run = Migration_Runner_Test_Double::run();

                $this->assertFalse($run['ok'], $class);
                $this->assertContains($error, $run['errors']);
                $this->assertSame($error, $run['detail']);
                $this->assertSame(
                    'Posts could not be read. Review the errors before migrating.',
                    $run['sections']['posts']['message']
                );
                $this->assertSame(
                    'The Tolka package was saved, but Posts could not be read. Existing Polylang data was not changed.',
                    $run['message']
                );
                $this->assertSame('error', $run['sections']['posts']['status']);
                $this->assertSame(0, $run['counts']['posts']);
                $this->assertSame(array(), $run['sample']['posts']);
                $this->assertSame(1, $run['counts']['languages']);
                $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['languages']['items']);
                $this->assertSame(array(), \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items']);
                $this->assertNotContains($class, $run['missing']);
                $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            }
        }

        public function test_inherited_export_uses_the_declared_shape()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\InstanceChild::$constructed = 0;
            \WMM\Tolka\RunnerTest\StaticChild::$constructed = 0;
            Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\InstanceChild';
            Migration_Runner_Test_Double::$map['terms'] = 'WMM\\Tolka\\RunnerTest\\StaticChild';

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(1, \WMM\Tolka\RunnerTest\InstanceChild::$constructed);
            $this->assertSame('Inherited', $preview['sections']['posts']['labels'][0]);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\StaticChild::$constructed);
            $this->assertSame('Static parent', $preview['sample']['terms'][0]['title']);
            $this->assertSame('ready', $preview['sections']['posts']['status']);
            $this->assertSame('ready', $preview['sections']['terms']['status']);

            $run = Migration_Runner_Test_Double::run();
            $this->assertTrue($run['ok']);
            $this->assertSame(2, \WMM\Tolka\RunnerTest\InstanceChild::$constructed);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\StaticChild::$constructed);
            $this->assertSame('Inherited', \WMM\Tolka\RunnerTest\Writer::$package['posts'][0]['title']);
            $this->assertSame('Static parent', \WMM\Tolka\RunnerTest\Writer::$package['terms'][0]['title']);
        }

        public function test_throwing_constructor_names_the_section_and_continues()
        {
            foreach (array(false, true) as $throw_error) {
                $this->reset_doubles();
                \WMM\Tolka\RunnerTest\ThrowingConstructor::$throw_error = $throw_error;
                \WMM\Tolka\RunnerTest\Exporter::$items = array(array(
                    'slug' => 'en',
                    'name' => 'English',
                    'title' => 'Kept',
                ));
                Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\ThrowingConstructor';

                $collected = Migration_Runner_Test_Double::collect();
                $this->assertSame(array(), $collected['posts']['items']);
                $this->assertSame(
                    array('Posts could not be read (constructor failed: constructor blew up).'),
                    $collected['posts']['errors']
                );
                $this->assertCount(1, $collected['languages']['items']);
                $this->assertCount(1, $collected['terms']['items']);
                $this->assertCount(1, $collected['media']['items']);
                $this->assertSame(1, \WMM\Tolka\RunnerTest\ThrowingConstructor::$constructed);
                $this->assertSame(0, \WMM\Tolka\RunnerTest\ThrowingConstructor::$exports);
                $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);

                \WMM\Tolka\RunnerTest\Exporter::$calls = 0;
                \WMM\Tolka\RunnerTest\ThrowingConstructor::$constructed = 0;
                $run = Migration_Runner_Test_Double::run();
                $this->assertFalse($run['ok']);
                $this->assertContains(
                    'Posts could not be read (constructor failed: constructor blew up).',
                    $run['errors']
                );
                $this->assertSame(
                    'Posts could not be read (constructor failed: constructor blew up).',
                    $run['detail']
                );
                $this->assertSame(
                    'The Tolka package was saved, but Posts could not be read. Existing Polylang data was not changed.',
                    $run['message']
                );
                $this->assertStringNotContainsString('Migration finished', $run['message']);
                $this->assertSame(array(), \WMM\Tolka\RunnerTest\Schema::$parts['posts']['items']);
                $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['languages']['items']);
                $this->assertCount(1, \WMM\Tolka\RunnerTest\Schema::$parts['media']['items']);
                $this->assertSame(0, \WMM\Tolka\RunnerTest\ThrowingConstructor::$exports);
                $this->assertNotContains('WMM\\Tolka\\RunnerTest\\ThrowingConstructor', $run['missing']);
            }
        }

        public function test_preview_does_not_call_the_writer()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array('slug' => 'en', 'name' => 'English'));
            \WMM\Tolka\RunnerTest\Writer::$throw = true;

            $ready = Migration_Runner_Test_Double::preview();
            $this->assertSame('ready', $ready['state']);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertArrayNotHasKey('write', $ready);

            Migration_Runner_Test_Double::$map['posts'] = 'WMM\\Tolka\\RunnerTest\\ThrowingConstructor';

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertArrayNotHasKey('write', $preview);
            $this->assertSame(1, $preview['counts']['languages']);
            $this->assertSame(0, $preview['counts']['posts']);
            $this->assertContains(
                'Posts could not be read (constructor failed: constructor blew up).',
                $preview['errors']
            );
            $this->assertSame('error', $preview['state']);

            $run = Migration_Runner_Test_Double::run();
            $this->assertSame(1, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertFalse($run['ok']);
            $this->assertNull($run['write']);
        }

        public function test_constructor_failures_leave_the_other_sections()
        {
            $this->reset_doubles();
            \WMM\Tolka\RunnerTest\Exporter::$items = array(array(
                'title' => 'Middle',
                'slug' => 'en',
                'name' => 'English',
            ));
            \WMM\Tolka\RunnerTest\Writer::$throw = true;
            Migration_Runner_Test_Double::$map['languages'] = 'WMM\\Tolka\\RunnerTest\\ThrowingConstructor';
            Migration_Runner_Test_Double::$map['media'] = 'WMM\\Tolka\\RunnerTest\\ThrowingConstructor';

            $preview = Migration_Runner_Test_Double::preview();

            $this->assertSame(0, \WMM\Tolka\RunnerTest\Writer::$calls);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\Schema::$calls);
            $this->assertSame(0, $preview['counts']['languages']);
            $this->assertSame(1, $preview['counts']['posts']);
            $this->assertSame(1, $preview['counts']['terms']);
            $this->assertSame(0, $preview['counts']['media']);
            $this->assertContains(
                'Languages could not be read (constructor failed: constructor blew up).',
                $preview['errors']
            );
            $this->assertContains(
                'Media items could not be read (constructor failed: constructor blew up).',
                $preview['errors']
            );
            $this->assertSame('Middle', $preview['sample']['posts'][0]['title']);
            $this->assertSame(2, \WMM\Tolka\RunnerTest\ThrowingConstructor::$constructed);
            $this->assertSame(0, \WMM\Tolka\RunnerTest\ThrowingConstructor::$exports);
            $this->assertSame(
                'The preview finished with errors in Languages and Media. Review them before migrating. Existing Polylang data was not changed.',
                $preview['message']
            );
        }
    }
}
