<?php

/**
 * Tests for the Tolka PHPUnit harness.
 *
 * WordPress is not loaded. A test that needs a class which is not in this
 * checkout calls wmm_tolka_skip_without_class() and is skipped.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Shared temp-directory cleanup for harness tests.
 */
abstract class Tolka_Test_Case extends TestCase
{
    /**
     * Directories created by the current test.
     *
     * @var string[]
     */
    private $temp_directories = array();

    /**
     * Remove temporary class directories created by a test.
     */
    protected function tearDown(): void
    {
        foreach ($this->temp_directories as $directory) {
            $this->remove_directory($directory);
        }

        $this->temp_directories = array();
        parent::tearDown();
    }

    /**
     * Create a temporary directory the loader can scan.
     *
     * @return string
     */
    protected function make_temp_dir()
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/wmm-tolka-' . uniqid('', true);

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->fail('The test could not create a temporary directory for class files.');
        }

        $this->temp_directories[] = $directory;

        return $directory;
    }

    /**
     * Whether an exception is PHPUnit's skip signal.
     *
     * @param Throwable $error Caught failure.
     * @return bool
     */
    protected function is_skip_exception($error)
    {
        return strpos(get_class($error), 'Skip') !== false;
    }

    /**
     * Delete a temporary directory and anything the test wrote inside it.
     *
     * @param string $directory Directory path.
     */
    private function remove_directory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        @chmod($directory, 0755);
        $items = scandir($directory);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->remove_directory($path);
                continue;
            }

            @chmod($path, 0644);
            @unlink($path);
        }

        @rmdir($directory);
    }
}

/**
 * Proves the suite loads class files, skips missing slices, and stays free
 * of a WordPress install.
 */
class Harness_Test extends Tolka_Test_Case
{
    /**
     * The suite must not boot WordPress or pretend Polylang is installed.
     */
    public function test_wordpress_is_not_loaded()
    {
        $this->assertTrue(defined('ABSPATH'));
        $this->assertTrue(defined('WMM_TOLKA_TEST_BOOTSTRAP'));
        $this->assertFalse(is_file(ABSPATH . 'wp-load.php'));
        $this->assertFalse(is_file(ABSPATH . 'wp-settings.php'));
        $this->assertFalse(function_exists('add_action'));
        $this->assertFalse(function_exists('wp_remote_get'));
        $this->assertFalse(function_exists('get_option'));
        $this->assertFalse(function_exists('home_url'));
        $this->assertFalse(function_exists('pll_languages_list'));
        $this->assertFalse(class_exists('PLL', false));
        $this->assertFalse(class_exists('Polylang', false));
    }

    /**
     * phpunit.xml.dist scans this directory and does not point at WordPress.
     */
    public function test_phpunit_config_scans_the_tolka_directory()
    {
        $path = WMM_TOLKA_PLUGIN_DIR . '/phpunit.xml.dist';
        $this->assertFileExists($path);

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->load($path);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'phpunit.xml.dist is not valid XML.');
        $this->assertCount(0, $errors);

        $phpunit = $document->documentElement;
        $this->assertNotNull($phpunit);
        $this->assertSame('phpunit', $phpunit->nodeName);
        $this->assertSame('tests/tolka/bootstrap.php', $phpunit->getAttribute('bootstrap'));

        $suites = $phpunit->getElementsByTagName('testsuite');
        $this->assertSame(1, $suites->length);
        $this->assertSame('tolka', $suites->item(0)->getAttribute('name'));

        $directories = $suites->item(0)->getElementsByTagName('directory');
        $this->assertSame(1, $directories->length);
        $this->assertSame('tests/tolka', trim($directories->item(0)->textContent));
        $this->assertSame('test-', $directories->item(0)->getAttribute('prefix'));
        $this->assertSame('.php', $directories->item(0)->getAttribute('suffix'));

        $xml = file_get_contents($path);
        $this->assertStringNotContainsString('wp-load.php', $xml);
        $this->assertStringNotContainsString('WP_TESTS_DIR', $xml);
    }

    /**
     * A class that already loaded is not a reason to skip.
     */
    public function test_loaded_class_is_not_skipped()
    {
        wmm_tolka_skip_without_class('Harness_Test');
        wmm_tolka_skip_without_class('\\WMM\\Tolka\\Harness_Test');
        wmm_tolka_skip_without_class('  harness_test  ');
        wmm_tolka_skip_without_class(array('Harness_Test', 'WMM\\Tolka\\Harness_Test'));
        wmm_tolka_skip_without_class(array());

        $this->assertTrue(class_exists(__CLASS__, false));
    }

    /**
     * A missing slice skips with a sentence a developer can act on.
     */
    public function test_missing_class_skips_with_a_finished_sentence()
    {
        try {
            wmm_tolka_skip_without_class('Missing_From_The_Suite');
            $this->fail('A missing class should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('WMM\\Tolka\\Missing_From_The_Suite', $error->getMessage());
            $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
            $this->assertStringContainsString('missing', strtolower($error->getMessage()));
        }
    }

    /**
     * An empty name is an empty state, not a crash.
     */
    public function test_blank_class_name_skips_with_a_finished_sentence()
    {
        try {
            wmm_tolka_skip_without_class('   ');
            $this->fail('A blank class name should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('did not name a Tolka class', $error->getMessage());
            $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
        }
    }

    /**
     * One missing class in a list skips, and loaded classes stay out of the reason.
     */
    public function test_any_missing_class_in_a_list_skips()
    {
        try {
            wmm_tolka_skip_without_class(array('Harness_Test', 'Missing_From_The_Suite'));
            $this->fail('One missing class should skip the test.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('WMM\\Tolka\\Missing_From_The_Suite', $error->getMessage());
            $this->assertStringNotContainsString('Harness_Test', $error->getMessage());
        }
    }

    /**
     * A name that already includes a namespace is not rewritten.
     */
    public function test_class_outside_the_tolka_namespace_can_skip()
    {
        try {
            wmm_tolka_skip_without_class('Other\\Harness_Test');
            $this->fail('A class outside this suite should skip when it is not loaded.');
        } catch (Throwable $error) {
            $this->assertTrue($this->is_skip_exception($error), get_class($error));
            $this->assertStringContainsString('Other\\Harness_Test', $error->getMessage());
        }
    }

    /**
     * End-to-end skip: PHPUnit records the test as skipped, not failed.
     */
    public function test_missing_slice_is_skipped_instead_of_failed()
    {
        wmm_tolka_skip_without_class('Not_A_Real_Tolka_Class');
    }

    /**
     * Class files use the plugin's class-*.php names.
     */
    public function test_class_file_names_follow_the_plugin_pattern()
    {
        $this->assertSame('class-speed-estimate.php', wmm_tolka_class_basename('Speed_Estimate'));
        $this->assertSame('class-polylang-detector.php', wmm_tolka_class_basename('WMM\\Tolka\\Polylang_Detector'));
        $this->assertSame('class-tolka-schema.php', wmm_tolka_class_basename('\\WMM\\Tolka\\Tolka_Schema'));
        $this->assertSame('', wmm_tolka_class_basename('   '));
    }

    /**
     * Requiring the bootstrap twice must not redeclare its helpers.
     */
    public function test_bootstrap_can_be_loaded_twice()
    {
        require_once WMM_TOLKA_TEST_DIR . '/bootstrap.php';
        require WMM_TOLKA_TEST_DIR . '/bootstrap.php';

        $this->assertTrue(function_exists('wmm_tolka_skip_without_class'));
        $this->assertTrue(function_exists('wmm_tolka_load_classes'));
    }

    /**
     * A fresh process can boot the suite when no class files exist yet.
     */
    public function test_bootstrap_runs_in_a_fresh_process_without_wordpress()
    {
        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WMM_TOLKA_TEST_DIR . '/bootstrap.php') . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame('', trim(implode("\n", $output)));
    }

    /**
     * The direct-access guard used by plugin files must not exit the suite.
     */
    public function test_direct_access_guard_does_not_abort_the_process()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-guarded.php',
            "<?php\nif (!defined('ABSPATH')) {\n    exit(3);\n}\nclass Wmm_Tolka_Guarded_Probe {\n}\n"
        );

        $runner = $directory . '/runner.php';
        $script = "<?php\nrequire " . var_export(WMM_TOLKA_TEST_DIR . '/bootstrap.php', true) . ";\n"
            . "wmm_tolka_load_classes(" . var_export($directory, true) . ");\n"
            . "echo class_exists('Wmm_Tolka_Guarded_Probe', false) ? \"WMM_TOLKA_PROBE=loaded\\n\" : \"WMM_TOLKA_PROBE=missing\\n\";\n"
            . "\$wordpress = function_exists('add_action') || function_exists('wp_remote_get') || function_exists('get_option');\n"
            . "echo \$wordpress ? \"WMM_TOLKA_WP=1\\n\" : \"WMM_TOLKA_WP=0\\n\";\n";
        file_put_contents($runner, $script);

        $output = array();
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        $this->assertStringContainsString('WMM_TOLKA_PROBE=loaded', $text);
        $this->assertStringContainsString('WMM_TOLKA_WP=0', $text);
    }

    /**
     * The loader must not create includes/tolka when that slice is absent.
     */
    public function test_loader_does_not_create_the_class_directory()
    {
        $directory = WMM_TOLKA_PLUGIN_DIR . '/includes/tolka';
        $existed = is_dir($directory);
        $loaded = wmm_tolka_load_classes();

        $this->assertTrue(is_array($loaded));

        if (!$existed) {
            $this->assertFalse(is_dir($directory));
            $this->assertSame(array(), $loaded);
        }
    }

    /**
     * A blank path means the real include directory.
     */
    public function test_blank_directory_uses_the_plugin_class_directory()
    {
        $loaded = wmm_tolka_load_classes('');

        $this->assertTrue(is_array($loaded));
    }

    /**
     * A directory that has not been added yet is an empty state.
     */
    public function test_loader_accepts_a_missing_directory()
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/wmm-tolka-missing-' . uniqid('', true);

        $this->assertFalse(is_dir($directory));
        $this->assertSame(array(), wmm_tolka_load_classes($directory));
    }

    /**
     * An empty include directory loads nothing and does not fail.
     */
    public function test_loader_accepts_an_empty_directory()
    {
        $directory = $this->make_temp_dir();

        $this->assertSame(array(), wmm_tolka_load_classes($directory));
    }

    /**
     * Only a string path is a class directory.
     */
    public function test_loader_rejects_a_directory_that_is_not_a_path()
    {
        try {
            wmm_tolka_load_classes(array('not-a-path'));
            $this->fail('A class directory that is not a path should be rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('must be a path', $error->getMessage());
        }
    }

    /**
     * class-*.php files load in sorted order, and only once.
     */
    public function test_loader_requires_class_files_in_sorted_order_once()
    {
        $directory = $this->make_temp_dir();
        $GLOBALS['wmm_tolka_harness_order'] = array();

        try {
            file_put_contents($directory . '/class-m.php', "<?php\n\$GLOBALS['wmm_tolka_harness_order'][] = 'm';\n");
            file_put_contents($directory . '/class-a.php', "<?php\n\$GLOBALS['wmm_tolka_harness_order'][] = 'a';\n");

            $first = wmm_tolka_load_classes($directory);
            $second = wmm_tolka_load_classes($directory);

            $this->assertSame(array('a', 'm'), $GLOBALS['wmm_tolka_harness_order']);
            $this->assertCount(2, $first);
            $this->assertCount(2, $second);
        } finally {
            unset($GLOBALS['wmm_tolka_harness_order']);
        }
    }

    /**
     * Notes, text files, and nested class files are not part of the suite.
     */
    public function test_loader_ignores_files_that_are_not_top_level_class_files()
    {
        $directory = $this->make_temp_dir();
        mkdir($directory . '/nested');
        $GLOBALS['wmm_tolka_harness_seen'] = array();

        try {
            $record = "<?php\n\$GLOBALS['wmm_tolka_harness_seen'][] = '%s';\n";
            file_put_contents($directory . '/notes.php', sprintf($record, 'notes'));
            file_put_contents($directory . '/class-foo.txt', sprintf($record, 'txt'));
            file_put_contents($directory . '/aclass-foo.php', sprintf($record, 'aprefix'));
            file_put_contents($directory . '/nested/class-nested.php', sprintf($record, 'nested'));
            file_put_contents($directory . '/class-real.php', sprintf($record, 'real'));

            $loaded = wmm_tolka_load_classes($directory);
            $basenames = array();

            foreach ($loaded as $path) {
                $basenames[] = basename($path);
            }

            $this->assertSame(array('real'), $GLOBALS['wmm_tolka_harness_seen']);
            $this->assertSame(array('class-real.php'), $basenames);
        } finally {
            unset($GLOBALS['wmm_tolka_harness_seen']);
        }
    }

    /**
     * A syntax error names the file and stays a readable failure.
     */
    public function test_syntax_error_in_a_class_file_is_reported_clearly()
    {
        $directory = $this->make_temp_dir();
        file_put_contents($directory . '/class-broken.php', "<?php\nclass Broken {\n");

        try {
            wmm_tolka_load_classes($directory);
            $this->fail('A syntax error in a class file should stop the suite with a clear message.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('class-broken.php', $error->getMessage());
            $this->assertStringContainsString('syntax error', strtolower($error->getMessage()));
        }
    }

    /**
     * A class file that hits a missing API does not take the rest of the suite down.
     */
    public function test_load_error_lets_the_suite_skip_that_class()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-undefined-load.php',
            "<?php\nnamespace WMM\\Tolka;\n\nwmm_tolka_this_function_does_not_exist();\n\nclass Undefined_Load\n{\n}\n"
        );

        $previous = wmm_tolka_load_errors();

        try {
            $loaded = wmm_tolka_load_classes($directory);
            $basenames = array();

            foreach ($loaded as $path) {
                $basenames[] = basename($path);
            }

            $found_broken_file = false;

            foreach ($basenames as $basename) {
                if ($basename === 'class-undefined-load.php') {
                    $found_broken_file = true;
                }
            }

            $this->assertFalse($found_broken_file);
            $this->assertTrue(class_exists('WMM\\Tolka\\Undefined_Load', false));

            $errors = wmm_tolka_load_errors();
            $this->assertArrayHasKey('class-undefined-load.php', $errors);
            $this->assertStringContainsString('could not finish loading', $errors['class-undefined-load.php']);
            $this->assertStringContainsString('wmm_tolka_this_function_does_not_exist', $errors['class-undefined-load.php']);

            // PHP still registers the class, so a later test can use it.
            wmm_tolka_skip_without_class('Undefined_Load');
            $this->assertTrue(class_exists('WMM\\Tolka\\Undefined_Load', false));
        } finally {
            $GLOBALS['wmm_tolka_load_errors'] = $previous;
        }
    }

    /**
     * An unreadable class directory tells the reader how to fix it.
     */
    public function test_unreadable_class_directory_explains_how_to_fix_it()
    {
        $directory = $this->make_temp_dir();
        chmod($directory, 0000);

        if (is_readable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('This environment can still read a directory with mode 000, so the unreadable-directory test was skipped.');
        }

        try {
            wmm_tolka_load_classes($directory);
            $this->fail('An unreadable class directory should stop the suite with a clear message.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('could not read the class directory', $error->getMessage());
            $this->assertStringContainsString('readable', $error->getMessage());
        } finally {
            chmod($directory, 0755);
        }
    }

    /**
     * An unreadable class file tells the reader how to fix it.
     */
    public function test_unreadable_class_file_explains_how_to_fix_it()
    {
        $directory = $this->make_temp_dir();
        $file = $directory . '/class-secret.php';
        file_put_contents($file, "<?php\nclass Secret_Probe {}\n");
        chmod($file, 0000);

        if (is_readable($file)) {
            chmod($file, 0644);
            $this->markTestSkipped('This environment can still read a file with mode 000, so the unreadable-file test was skipped.');
        }

        try {
            wmm_tolka_load_classes($directory);
            $this->fail('An unreadable class file should stop the suite with a clear message.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('could not read', $error->getMessage());
            $this->assertStringContainsString('class-secret.php', $error->getMessage());
            $this->assertStringContainsString('readable', $error->getMessage());
        } finally {
            chmod($file, 0644);
        }
    }

    /**
     * A file that errors before it declares a class stays skippable.
     */
    public function test_load_error_without_a_class_is_included_in_the_skip_reason()
    {
        $directory = $this->make_temp_dir();
        file_put_contents(
            $directory . '/class-missing-body.php',
            "<?php\nnamespace WMM\\Tolka;\n\nwmm_tolka_body_missing();\n"
        );

        $previous = wmm_tolka_load_errors();

        try {
            wmm_tolka_load_classes($directory);
            $this->assertFalse(class_exists('WMM\\Tolka\\Missing_Body', false));

            try {
                wmm_tolka_skip_without_class('Missing_Body');
                $this->fail('A class that never loaded should skip.');
            } catch (Throwable $error) {
                $this->assertTrue($this->is_skip_exception($error), get_class($error));
                $this->assertStringContainsString('WMM\\Tolka\\Missing_Body', $error->getMessage());
                $this->assertStringContainsString('could not finish loading', $error->getMessage());
                $this->assertStringContainsString('skipped', strtolower($error->getMessage()));
            }
        } finally {
            $GLOBALS['wmm_tolka_load_errors'] = $previous;
        }
    }

    /**
     * PHPUnit 10 and 11 need a real path named after the test class.
     * The scanned file stays tests/tolka/test-harness.php.
     */
    public function test_hyphenated_test_file_is_part_of_the_suite()
    {
        $source = WMM_TOLKA_TEST_DIR . '/test-harness.php';
        $resolved = realpath($source);

        $this->assertFileExists($source);
        $this->assertNotFalse($resolved);
        $this->assertSame('Harness_Test', (new \ReflectionClass($this))->getShortName());

        if ((int) \PHPUnit\Runner\Version::id() >= 10) {
            $this->assertStringEndsWith('Harness_Test.php', $resolved);
            $this->assertTrue(is_link($source));
            return;
        }

        $this->assertStringEndsWith('test-harness.php', $resolved);
        $this->assertFalse(is_link($source));
    }
}
