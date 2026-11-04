<?php

/**
 * PHPUnit bootstrap for Migrate to Tolka.
 *
 * The suite does not load WordPress. It reads includes/tolka/class-*.php
 * when those files are present. If includes/tolka does not exist, loading
 * is skipped and tests that need those classes skip. The suite still finishes.
 * A test that needs a class from a slice that has not landed yet should call
 * wmm_tolka_skip_without_class('Speed_Estimate') from the test method.
 * Run it from wp-migration-manager with: phpunit -c phpunit.xml.dist
 *
 * ABSPATH is defined only when it is missing, so class files that keep the
 * plugin's direct-access guard can load. This file does not load wp-load.php
 * and does not define WordPress functions such as wp_remote_get.
 *
 * PHPUnit 10 and 11 only accept a class whose name matches the file name.
 * For that process, each tests/tolka/test-*.php file is linked to a
 * temporary path named after its test class, then restored.
 *
 * Including this file a second time returns immediately, so helpers are
 * not declared again.
 *
 * @package MigrationManager
 */

if (defined('WMM_TOLKA_TEST_BOOTSTRAP')) {
    return;
}

define('WMM_TOLKA_TEST_BOOTSTRAP', true);

if (!defined('WMM_TOLKA_TEST_DIR')) {
    define('WMM_TOLKA_TEST_DIR', __DIR__);
}

if (!defined('WMM_TOLKA_PLUGIN_DIR')) {
    define('WMM_TOLKA_PLUGIN_DIR', dirname(__DIR__, 2));
}

if (!defined('ABSPATH')) {
    define('ABSPATH', WMM_TOLKA_PLUGIN_DIR . '/');
}

if (!function_exists('wmm_tolka_class_directory')) {
    /**
     * Directory that holds Tolka class files.
     *
     * @return string
     */
    function wmm_tolka_class_directory()
    {
        return WMM_TOLKA_PLUGIN_DIR . '/includes/tolka';
    }
}

if (!function_exists('wmm_tolka_normalize_class')) {
    /**
     * Fully qualified class name used by the suite.
     *
     * A short name such as Speed_Estimate becomes WMM\Tolka\Speed_Estimate.
     *
     * @param string $class Class name.
     * @return string
     */
    function wmm_tolka_normalize_class($class)
    {
        $class = trim((string) $class);
        $class = ltrim($class, '\\');

        if ($class === '') {
            return '';
        }

        if (strpos($class, '\\') === false) {
            return 'WMM\\Tolka\\' . $class;
        }

        return $class;
    }
}

if (!function_exists('wmm_tolka_class_basename')) {
    /**
     * File name for a Tolka class, for example class-speed-estimate.php.
     *
     * @param string $class Class name.
     * @return string
     */
    function wmm_tolka_class_basename($class)
    {
        $normalized = wmm_tolka_normalize_class($class);

        if ($normalized === '') {
            return '';
        }

        $short = $normalized;
        $separator = strrpos($normalized, '\\');

        if ($separator !== false) {
            $short = substr($normalized, $separator + 1);
        }

        $short = strtolower(str_replace('_', '-', $short));

        return 'class-' . $short . '.php';
    }
}

if (!function_exists('wmm_tolka_expected_class')) {
    /**
     * Class name a class-*.php file is expected to define.
     *
     * class-speed-estimate.php becomes WMM\Tolka\Speed_Estimate.
     *
     * @param string $basename File name.
     * @return string
     */
    function wmm_tolka_expected_class($basename)
    {
        $stem = strtolower((string) $basename);

        if (substr($stem, -4) === '.php') {
            $stem = substr($stem, 0, -4);
        }

        if (strpos($stem, 'class-') !== 0) {
            return '';
        }

        $stem = substr($stem, strlen('class-'));

        if ($stem === '') {
            return '';
        }

        $words = array();

        foreach (explode('-', $stem) as $part) {
            if ($part === '') {
                continue;
            }

            $words[] = ucfirst($part);
        }

        if (empty($words)) {
            return '';
        }

        return 'WMM\\Tolka\\' . implode('_', $words);
    }
}

if (!function_exists('wmm_tolka_undefined_classes')) {
    /**
     * Files that loaded but did not define the class their name promises.
     *
     * @return array<string, string>
     */
    function wmm_tolka_undefined_classes()
    {
        if (!isset($GLOBALS['wmm_tolka_undefined_classes']) || !is_array($GLOBALS['wmm_tolka_undefined_classes'])) {
            $GLOBALS['wmm_tolka_undefined_classes'] = array();
        }

        return $GLOBALS['wmm_tolka_undefined_classes'];
    }
}

if (!function_exists('wmm_tolka_load_errors')) {
    /**
     * Load failures recorded for class files that exist but did not finish.
     *
     * @return array<string, string>
     */
    function wmm_tolka_load_errors()
    {
        if (!isset($GLOBALS['wmm_tolka_load_errors']) || !is_array($GLOBALS['wmm_tolka_load_errors'])) {
            $GLOBALS['wmm_tolka_load_errors'] = array();
        }

        return $GLOBALS['wmm_tolka_load_errors'];
    }
}

if (!function_exists('wmm_tolka_skip_sentence')) {
    /**
     * A skip reason that names the class and stays inside the plugin.
     *
     * Absolute paths outside the plugin are removed. Paths inside the plugin
     * are rewritten so they start at the plugin root.
     *
     * @param string $message Reason the test cannot run yet.
     * @return string
     */
    function wmm_tolka_skip_sentence($message)
    {
        $message = (string) $message;
        $plugin = rtrim(str_replace('\\', '/', WMM_TOLKA_PLUGIN_DIR), '/');

        if ($plugin !== '') {
            $message = str_replace($plugin . '/', '', $message);
            $message = str_replace($plugin, '', $message);
        }

        $message = preg_replace('# in /[^\\s]+ on line \\d+#', '', $message);
        $message = preg_replace('# in /[^\\s]+:\\d+#', '', $message);
        $message = preg_replace('#phar://\\S+#', '', $message);
        $message = preg_replace('#[A-Za-z]:\\\\[^\\s]+#', '', $message);
        $message = preg_replace('#/(?:[^\\s/:]+/)+[^\\s/:]+#', '', $message);
        $message = preg_replace('#\\S*wmm-tolka-\\S*#', '', $message);
        $message = preg_replace('/[ \\t]{2,}/', ' ', $message);
        $message = preg_replace('/\\s+\\./', '.', $message);
        $message = trim($message);

        return wmm_tolka_finish_sentence($message);
    }
}

if (!function_exists('wmm_tolka_mark_skipped')) {
    /**
     * Skip the current test with a finished sentence.
     *
     * @param string $message Reason the test cannot run yet.
     * @return void
     */
    function wmm_tolka_mark_skipped($message)
    {
        $message = wmm_tolka_skip_sentence($message);

        if (class_exists('PHPUnit\\Framework\\TestCase')) {
            PHPUnit\Framework\TestCase::markTestSkipped($message);
        }

        throw new RuntimeException($message);
    }
}

if (!function_exists('wmm_tolka_skip_without_class')) {
    /**
     * Skip the current test when one or more Tolka classes are not loaded.
     *
     * Pass a short class name, a fully qualified name, or a list of either.
     * Call this from a test method or setUp(), not from a data provider.
     * A missing class is an empty state for this suite, not a failure.
     *
     * @param string|array $class Class name or list of class names.
     * @return void
     */
    function wmm_tolka_skip_without_class($class)
    {
        $classes = is_array($class) ? $class : array($class);
        $missing = array();
        $errors = array();

        foreach ($classes as $one) {
            $normalized = wmm_tolka_normalize_class($one);

            if ($normalized !== '' && class_exists($normalized, false)) {
                continue;
            }

            if ($normalized === '') {
                $missing[] = '(blank class name)';
                continue;
            }

            $missing[] = $normalized;
            $basename = wmm_tolka_class_basename($normalized);
            $load_errors = wmm_tolka_load_errors();
            $undefined = wmm_tolka_undefined_classes();

            if ($basename !== '' && isset($undefined[$basename])) {
                $errors[] = $undefined[$basename];
            } elseif ($basename !== '' && isset($load_errors[$basename])) {
                $errors[] = $load_errors[$basename];
            }
        }

        if (empty($missing)) {
            return;
        }

        if (count($classes) === 1 && $missing[0] === '(blank class name)') {
            wmm_tolka_mark_skipped('This test did not name a Tolka class, so it was skipped.');
        }

        $note = '';

        if (count($missing) === 1) {
            $basename = wmm_tolka_class_basename($missing[0]);
            $undefined = wmm_tolka_undefined_classes();

            if ($basename !== '' && isset($undefined[$basename])) {
                $message = $missing[0] . ' is not defined, so this test was skipped. ' . $undefined[$basename];
            } elseif (wmm_tolka_class_directory_is_missing() && empty($errors)) {
                $message = $missing[0] . ' is not loaded, so this test was skipped. The includes/tolka directory is missing, so the suite continues.';
            } else {
                $message = $missing[0] . ' is not loaded, so this test was skipped. The suite keeps running when that class file is missing.';
                $note = !empty($errors) ? ' ' . implode(' ', $errors) : '';
            }
        } elseif (wmm_tolka_class_directory_is_missing() && empty($errors)) {
            $message = 'These Tolka classes are not loaded, so this test was skipped: ' . implode(', ', $missing) . '. The includes/tolka directory is missing, so the suite continues.';
        } else {
            $message = 'These Tolka classes are not loaded, so this test was skipped: ' . implode(', ', $missing) . '. The suite keeps running when those class files are missing.';
            $note = !empty($errors) ? ' ' . implode(' ', $errors) : '';
        }

        if ($note !== '') {
            $message .= $note;
        }

        wmm_tolka_mark_skipped($message);
    }
}

if (!function_exists('wmm_tolka_finish_sentence')) {
    /**
     * Make sure a PHP engine message ends as a sentence.
     *
     * @param string $text Message text.
     * @return string
     */
    function wmm_tolka_finish_sentence($text)
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        $last = substr($text, -1);

        if ($last !== '.' && $last !== '!' && $last !== '?') {
            $text .= '.';
        }

        return $text;
    }
}

if (!function_exists('wmm_tolka_concrete_class_names')) {
    /**
     * Non-abstract class names declared in a PHP file.
     *
     * The file is not executed. PHPUnit 10 and 11 need one of these names
     * to match the real path of a test-*.php file.
     *
     * @param string $file PHP file path.
     * @return string[]
     */
    function wmm_tolka_concrete_class_names($file)
    {
        if (!function_exists('token_get_all')) {
            return array();
        }

        $code = file_get_contents($file);

        if ($code === false) {
            return array();
        }

        $tokens = token_get_all($code);
        $names = array();
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_CLASS) {
                continue;
            }

            $abstract = false;

            for ($back = $index - 1; $back >= 0; $back--) {
                if (!is_array($tokens[$back])) {
                    if (trim($tokens[$back]) === '') {
                        continue;
                    }

                    break;
                }

                $type = $tokens[$back][0];

                if ($type === T_ABSTRACT) {
                    $abstract = true;
                    break;
                }

                if ($type === T_WHITESPACE || $type === T_COMMENT || $type === T_DOC_COMMENT) {
                    continue;
                }

                break;
            }

            if ($abstract) {
                continue;
            }

            for ($forward = $index + 1; $forward < $count; $forward++) {
                if (!is_array($tokens[$forward])) {
                    break;
                }

                if ($tokens[$forward][0] === T_WHITESPACE) {
                    continue;
                }

                if ($tokens[$forward][0] === T_STRING) {
                    $names[] = $tokens[$forward][1];
                }

                break;
            }
        }

        return $names;
    }
}

if (!function_exists('wmm_tolka_primary_test_class')) {
    /**
     * Class PHPUnit 10 and 11 should run from a test file.
     *
     * A helper declared above the test case must not steal the file name.
     * Names ending in Test are preferred. Otherwise the first class is used.
     *
     * @param string[] $names Concrete class names, in source order.
     * @return string
     */
    function wmm_tolka_primary_test_class($names)
    {
        foreach ($names as $name) {
            if (strlen($name) >= 4 && substr($name, -4) === 'Test') {
                return $name;
            }
        }

        if (isset($names[0])) {
            return $names[0];
        }

        return '';
    }
}

if (!function_exists('wmm_tolka_rewire_test_files')) {
    /**
     * Give PHPUnit 10 and 11 a real path whose name matches the test class.
     *
     * Those releases only load a class whose short name ends with the file
     * name. test-harness.php is not a legal class name. The suite still scans
     * tests/tolka/test-*.php. Each of those files is linked, for this process
     * only, at a temporary path named after its test class.
     *
     * @return void
     */
    function wmm_tolka_rewire_test_files()
    {
        if (!class_exists('PHPUnit\\Runner\\Version')) {
            return;
        }

        $version = PHPUnit\Runner\Version::id();

        if ((int) $version < 10) {
            return;
        }

        $files = glob(WMM_TOLKA_TEST_DIR . '/test-*.php');

        if (!is_array($files)) {
            return;
        }

        $temp = rtrim(sys_get_temp_dir(), '/\\') . '/wmm-tolka-phpunit-' . getmypid();

        if (!is_dir($temp) && !mkdir($temp, 0700, true) && !is_dir($temp)) {
            throw new RuntimeException(
                'The Tolka test bootstrap could not create a temporary directory for PHPUnit ' . $version . '. The hyphenated test file names need that directory.'
            );
        }

        $restore = array();
        $restored = false;
        $put_back = function () use (&$restored, &$restore, $temp) {
            if ($restored) {
                return;
            }

            $restored = true;

            foreach ($restore as $pair) {
                if (is_link($pair[0])) {
                    unlink($pair[0]);
                }

                if (is_file($pair[1]) && !file_exists($pair[0])) {
                    rename($pair[1], $pair[0]);
                }
            }

            $copies = glob($temp . '/*.php');

            if (is_array($copies)) {
                foreach ($copies as $copy) {
                    unlink($copy);
                }
            }

            @rmdir($temp);
        };

        register_shutdown_function($put_back);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = function () use ($put_back) {
                $put_back();
                exit(1);
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        foreach ($files as $file) {
            if (is_link($file)) {
                continue;
            }

            $names = wmm_tolka_concrete_class_names($file);

            if (count($names) === 0) {
                continue;
            }

            $primary = wmm_tolka_primary_test_class($names);
            $others = array();

            foreach ($names as $name) {
                if ($name !== $primary) {
                    $others[] = $name;
                }
            }

            $hidden_tests = array();

            foreach ($others as $name) {
                if (strlen($name) >= 4 && substr($name, -4) === 'Test') {
                    $hidden_tests[] = $name;
                }
            }

            if (!empty($hidden_tests)) {
                fwrite(
                    STDERR,
                    $primary . ' is the test class PHPUnit ' . $version . ' will run from ' . basename($file) . '. These other test classes in that file will not run: ' . implode(', ', $hidden_tests) . '. Move their test methods onto ' . $primary . '. This PHPUnit release runs one test class from each test file.' . PHP_EOL
                );
            }

            $target = $temp . '/' . $primary . '.php';

            if (!copy($file, $target)) {
                throw new RuntimeException(
                    'The Tolka test bootstrap could not copy ' . basename($file) . ' for PHPUnit ' . $version . '.'
                );
            }

            $backup = $file . '.wmm-source';

            if (!rename($file, $backup)) {
                unlink($target);
                throw new RuntimeException(
                    'The Tolka test bootstrap could not prepare ' . basename($file) . ' for PHPUnit ' . $version . '.'
                );
            }

            if (!symlink($target, $file)) {
                rename($backup, $file);
                throw new RuntimeException(
                    'The Tolka test bootstrap could not link ' . basename($file) . ' for PHPUnit ' . $version . '. The file name contains a hyphen, which this PHPUnit release cannot use as a class name.'
                );
            }

            $restore[] = array($file, $backup);
        }
    }
}

if (!function_exists('wmm_tolka_class_directory_is_missing')) {
    /**
     * Whether the real includes/tolka directory was absent on the last look.
     *
     * @return bool
     */
    function wmm_tolka_class_directory_is_missing()
    {
        return !empty($GLOBALS['wmm_tolka_class_directory_missing']);
    }
}

if (!function_exists('wmm_tolka_record_load_error')) {
    /**
     * Remember a class file that could not be used, without stopping the suite.
     *
     * @param string $basename File name.
     * @param string $sentence Finished explanation.
     * @return void
     */
    function wmm_tolka_record_load_error($basename, $sentence)
    {
        if (!isset($GLOBALS['wmm_tolka_load_errors']) || !is_array($GLOBALS['wmm_tolka_load_errors'])) {
            $GLOBALS['wmm_tolka_load_errors'] = array();
        }

        $GLOBALS['wmm_tolka_load_errors'][$basename] = wmm_tolka_skip_sentence($sentence);
    }
}

if (!function_exists('wmm_tolka_restore_cwd')) {
    /**
     * Put the process back in the directory it started from.
     *
     * @param string|false $cwd Directory from getcwd(), or false when unknown.
     * @return void
     */
    function wmm_tolka_restore_cwd($cwd)
    {
        if ($cwd === false) {
            return;
        }

        if (getcwd() !== $cwd) {
            chdir($cwd);
        }
    }
}

if (!function_exists('wmm_tolka_load_classes')) {
    /**
     * Require class-*.php files from the Tolka include directory.
     *
     * A missing directory is an empty state and loads nothing. A file that
     * exists but cannot be read, or that has a syntax error, stops the suite
     * with a clear message. A class file that cannot be read is recorded, and
     * the test that needs it skips. A file that hits a missing WordPress API while it
     * is loading is recorded, and tests that need its class can skip. A file
     * that loads but does not define the class its name promises is recorded
     * the same way, and the suite keeps running.
     *
     * @param string|null $directory Optional directory. Tests pass a temp path.
     * @return string[] Loaded file paths.
     */
    function wmm_tolka_load_classes($directory = null)
    {
        if ($directory !== null && !is_string($directory)) {
            throw new RuntimeException('The Tolka class directory must be a path.');
        }

        $using_default = false;

        if ($directory === null || $directory === '') {
            $directory = wmm_tolka_class_directory();
            $using_default = true;
        }

        if (!is_dir($directory)) {
            if ($using_default) {
                $GLOBALS['wmm_tolka_class_directory_missing'] = true;
            }

            return array();
        }

        if ($using_default) {
            $GLOBALS['wmm_tolka_class_directory_missing'] = false;
        }

        if (!is_readable($directory)) {
            throw new RuntimeException(
                'The Tolka test bootstrap could not read the class directory ' . $directory . '. Make that directory readable and run the suite again.'
            );
        }

        $pattern = rtrim($directory, "/\\") . '/class-*.php';
        $files = glob($pattern);

        if (!is_array($files)) {
            throw new RuntimeException(
                'The Tolka test bootstrap could not list class files in ' . $directory . '. Make that directory readable and run the suite again.'
            );
        }

        sort($files, SORT_STRING);

        $loaded = array();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (!is_readable($file)) {
                wmm_tolka_record_load_error(
                    basename($file),
                    'The bootstrap could not read ' . basename($file) . '. Make that class file readable and run the suite again.'
                );
                continue;
            }

            $real = realpath($file);

            if ($real !== false) {
                $file = $real;
            }

            $cwd = getcwd();

            try {
                require_once $file;
            } catch (ParseError $error) {
                throw new RuntimeException(
                    'The Tolka test bootstrap could not load ' . basename($file) . ' because it has a PHP syntax error. ' . wmm_tolka_finish_sentence($error->getMessage()),
                    0,
                    $error
                );
            } catch (Throwable $error) {
                $detail = wmm_tolka_finish_sentence($error->getMessage());
                $sentence = 'The bootstrap could not finish loading ' . basename($file) . '.';

                if ($detail !== '') {
                    $sentence .= ' ' . $detail;
                }

                wmm_tolka_record_load_error(basename($file), $sentence);
                continue;
            } finally {
                wmm_tolka_restore_cwd($cwd);
            }

            $expected = wmm_tolka_expected_class(basename($file));

            if ($expected !== '' && !class_exists($expected, false)) {
                if (!isset($GLOBALS['wmm_tolka_undefined_classes']) || !is_array($GLOBALS['wmm_tolka_undefined_classes'])) {
                    $GLOBALS['wmm_tolka_undefined_classes'] = array();
                }

                $GLOBALS['wmm_tolka_undefined_classes'][basename($file)] = basename($file) . ' loaded, but it did not define ' . $expected . '.';
            }

            $loaded[] = $file;
        }

        return $loaded;
    }
}

$wmm_tolka_cwd = getcwd();
wmm_tolka_rewire_test_files();
wmm_tolka_load_classes();
wmm_tolka_restore_cwd($wmm_tolka_cwd);
unset($wmm_tolka_cwd);
