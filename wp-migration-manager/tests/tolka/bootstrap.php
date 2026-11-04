<?php

/**
 * PHPUnit bootstrap for Migrate to Tolka.
 *
 * The suite does not load WordPress. It reads includes/tolka/class-*.php
 * when those files are present, and it leaves the suite up when they are not.
 * A test that needs a class from a slice that has not landed yet should call
 * wmm_tolka_skip_without_class('Speed_Estimate') from the test method.
 * Run it from wp-migration-manager with: phpunit -c phpunit.xml.dist
 *
 * ABSPATH is defined so class files that keep the plugin's direct-access
 * guard can load. WordPress functions are not defined.
 *
 * PHPUnit 10 and 11 only accept a class whose name matches the file name.
 * For that process, each tests/tolka/test-*.php file is linked to a
 * temporary path named after its test class, then restored.
 *
 * @package MigrationManager
 */

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

if (!function_exists('wmm_tolka_mark_skipped')) {
    /**
     * Skip the current test with a finished sentence.
     *
     * @param string $message Reason the test cannot run yet.
     * @return void
     */
    function wmm_tolka_mark_skipped($message)
    {
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

            if ($basename !== '' && isset($load_errors[$basename])) {
                $errors[] = $load_errors[$basename];
            }
        }

        if (empty($missing)) {
            return;
        }

        if (count($classes) === 1 && $missing[0] === '(blank class name)') {
            wmm_tolka_mark_skipped('This test did not name a Tolka class, so it was skipped.');
        }

        if (count($missing) === 1) {
            $message = $missing[0] . ' is not loaded, so this test was skipped. The suite keeps running when that class file is missing.';
        } else {
            $message = 'These Tolka classes are not loaded, so this test was skipped: ' . implode(', ', $missing) . '. The suite keeps running when those class files are missing.';
        }

        if (!empty($errors)) {
            $message .= ' ' . implode(' ', $errors);
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

        foreach ($files as $file) {
            if (is_link($file)) {
                continue;
            }

            $names = wmm_tolka_concrete_class_names($file);

            if (count($names) === 0) {
                continue;
            }

            if (count($names) > 1) {
                fwrite(
                    STDERR,
                    $names[0] . ' is the test class PHPUnit ' . $version . ' will run from ' . basename($file) . '. The other classes in that file are ' . implode(', ', array_slice($names, 1)) . '. Put each of those in its own test file if they should run too.' . PHP_EOL
                );
            }

            $target = $temp . '/' . $names[0] . '.php';

            if (!copy($file, $target)) {
                throw new RuntimeException(
                    'The Tolka test bootstrap could not copy ' . basename($file) . ' for PHPUnit ' . $version . '.'
                );
            }

            $backup = $file . '.wmm-source';

            if (!rename($file, $backup)) {
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

        if (empty($restore)) {
            return;
        }

        $restored = false;
        $put_back = function () use (&$restored, $restore, $temp) {
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
    }
}

if (!function_exists('wmm_tolka_load_classes')) {
    /**
     * Require class-*.php files from the Tolka include directory.
     *
     * A missing directory is an empty state and loads nothing. A file that
     * exists but cannot be read, or that has a syntax error, stops the suite
     * with a clear message. A file that hits a missing WordPress API while it
     * is loading is recorded, and tests that need its class can skip.
     *
     * @param string|null $directory Optional directory. Tests pass a temp path.
     * @return string[] Loaded file paths.
     */
    function wmm_tolka_load_classes($directory = null)
    {
        if ($directory !== null && !is_string($directory)) {
            throw new RuntimeException('The Tolka class directory must be a path.');
        }

        if ($directory === null || $directory === '') {
            $directory = wmm_tolka_class_directory();
        }

        if (!is_dir($directory)) {
            return array();
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
                throw new RuntimeException(
                    'The Tolka test bootstrap could not read ' . $file . '. Make that class file readable and run the suite again.'
                );
            }

            $real = realpath($file);

            if ($real !== false) {
                $file = $real;
            }

            try {
                require_once $file;
            } catch (ParseError $error) {
                throw new RuntimeException(
                    'The Tolka test bootstrap could not load ' . basename($file) . ' because it has a PHP syntax error. ' . wmm_tolka_finish_sentence($error->getMessage()),
                    0,
                    $error
                );
            } catch (Throwable $error) {
                if (!isset($GLOBALS['wmm_tolka_load_errors']) || !is_array($GLOBALS['wmm_tolka_load_errors'])) {
                    $GLOBALS['wmm_tolka_load_errors'] = array();
                }

                $detail = wmm_tolka_finish_sentence($error->getMessage());
                $sentence = 'The bootstrap could not finish loading ' . basename($file) . '.';

                if ($detail !== '') {
                    $sentence .= ' ' . $detail;
                }

                $GLOBALS['wmm_tolka_load_errors'][basename($file)] = $sentence;
                continue;
            }

            $loaded[] = $file;
        }

        return $loaded;
    }
}

if (!defined('WMM_TOLKA_TEST_BOOTSTRAP')) {
    define('WMM_TOLKA_TEST_BOOTSTRAP', true);
    wmm_tolka_rewire_test_files();
    wmm_tolka_load_classes();
}
