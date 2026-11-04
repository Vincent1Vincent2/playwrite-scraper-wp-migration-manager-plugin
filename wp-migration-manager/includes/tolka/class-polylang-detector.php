<?php
/**
 * Polylang presence check for Migrate to Tolka.
 *
 * Loaded by the Tolka bootstrap. This file does not exit when ABSPATH is
 * missing: the shared test bootstrap requires every Tolka class without
 * WordPress, and exit would stop the whole suite.
 *
 * @package WMM\Tolka
 */

namespace WMM\Tolka;

/**
 * Reports whether Polylang is active and the version from its plugin header.
 *
 * status() always returns active, version, and errors. Failures are finished
 * sentences in errors. Polylang data is never changed.
 */
class Polylang_Detector
{
    /**
     * Warnings raised while status() is running. Cleared at the start of each call.
     *
     * @var string[]
     */
    private static $raised_warnings = array();

    /**
     * Active flag, header version, and customer-facing errors.
     *
     * Active when pll_languages_list() exists or the PLL or Polylang class
     * exists. Version comes from the Polylang plugin header, or '' when that
     * header cannot be read.
     *
     * @return array{active: bool, version: string, errors: string[]}
     */
    public static function status(): array
    {
        self::$raised_warnings = array();
        // Warnings must not print on the Migrate to Tolka screen. The handler
        // is always removed before status() returns.
        set_error_handler(
            static function ($severity, $message) {
                if (is_string($message) && $message !== '') {
                    self::$raised_warnings[] = $message;
                }

                return true;
            }
        );

        try {
            return self::collect_status();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Warnings produced by the most recent status() call.
     *
     * @return string[]
     */
    protected static function raised_warnings(): array
    {
        return self::$raised_warnings;
    }

    /**
     * @return array{active: bool, version: string, errors: string[]}
     */
    private static function collect_status(): array
    {
        try {
            $active = static::is_active();
        } catch (\Throwable $throwable) {
            return self::unavailable_status();
        }

        try {
            $inspection = static::inspect();
        } catch (\Throwable $throwable) {
            if (!$active) {
                return self::unavailable_status();
            }
            $inspection = array(
                'version' => '',
                'header_found' => false,
                'version_missing' => false,
                'listed_active' => false,
                'read_failed' => true,
            );
        }

        if (!is_array($inspection)) {
            $inspection = array();
        }

        try {
            return self::build_status($active, $inspection);
        } catch (\Throwable $throwable) {
            return self::unavailable_status();
        }
    }

    /**
     * Polylang is active when its public API or main class is loaded.
     */
    protected static function is_active(): bool
    {
        // Presence only. Calling pll_languages_list() can fatal on a partial load.
        if (function_exists('pll_languages_list')) {
            return true;
        }

        return class_exists('\\PLL') || class_exists('\\Polylang');
    }

    /**
     * Read the best Polylang plugin header this site can see.
     *
     * The loaded plugin file wins. A copy listed in the active plugins option
     * wins over a different copy on disk. Otherwise the free plugin file is
     * preferred, then Polylang Pro.
     *
     * @return array{version: string, header_found: bool, version_missing: bool, listed_active: bool}
     */
    protected static function inspect(): array
    {
        if (static::use_loaded_plugin()) {
            $closest = static::closest_loaded_plugin_file();
            if ($closest !== '') {
                $parsed = static::inspect_plugin_file($closest);
                if (!empty($parsed['is_polylang'])) {
                    $version = '';
                    if (isset($parsed['version']) && is_string($parsed['version'])) {
                        $version = $parsed['version'];
                    }

                    return array(
                        'version' => $version,
                        'header_found' => true,
                        'version_missing' => $version === '',
                        'listed_active' => false,
                        'unreadable' => $version === '' && !empty($parsed['unreadable']),
                        'label' => (isset($parsed['label']) && $parsed['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang',
                    );
                }
            }
        }

        $files = self::files_in_directories(static::plugin_directories());
        if (static::use_plugin_list()) {
            $files = array_merge($files, self::files_from_wordpress_list());
        }

        return static::choose_version($files, self::active_relative_files());
    }

    /**
     * Plugin file for the Polylang code that is already loaded, if it can be found.
     */
    protected static function closest_loaded_plugin_file(): string
    {
        $origin = static::loaded_origin_file();
        if (!is_string($origin) || $origin === '' || !is_file($origin)) {
            return '';
        }

        // Polylang and Polylang Pro both keep the plugin header in polylang.php.
        $names = array('polylang.php', 'polylang-pro.php');

        $directory = dirname($origin);
        for ($depth = 0; $depth < 8; $depth++) {
            if (!is_string($directory) || $directory === '' || self::is_walk_boundary($directory)) {
                break;
            }

            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\') . '/' . $name;
                $parsed = static::inspect_plugin_file($candidate);
                if (!empty($parsed['is_polylang'])) {
                    return $candidate;
                }
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return '';
    }

    /**
     * File that defined pll_languages_list(), PLL, or Polylang.
     */
    protected static function loaded_origin_file(): string
    {
        $origin = self::reflection_filename('function', 'pll_languages_list');
        if ($origin !== '') {
            return $origin;
        }

        $origin = self::reflection_filename('class', 'PLL');
        if ($origin !== '') {
            return $origin;
        }

        return self::reflection_filename('class', 'Polylang');
    }

    /**
     * Directories that can contain Polylang. Missing directories are skipped.
     *
     * @return string[]
     */
    protected static function plugin_directories(): array
    {
        $candidates = array();

        if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR)) {
            $candidates[] = WP_PLUGIN_DIR;
        }
        if (defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR)) {
            $candidates[] = WPMU_PLUGIN_DIR;
        }
        if (defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR)) {
            $content = rtrim(WP_CONTENT_DIR, '/\\');
            $candidates[] = $content . '/plugins';
            $candidates[] = $content . '/mu-plugins';
        }

        foreach (static::directories_beside_plugin(__FILE__) as $beside) {
            $candidates[] = $beside;
        }

        $directories = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || !is_dir($candidate) || !is_readable($candidate)) {
                continue;
            }
            $resolved = self::resolve_path($candidate);
            $directories[] = $resolved !== '' ? $resolved : $candidate;
        }

        return array_values(array_unique($directories));
    }

    /**
     * Plugin directory that contains this file, plus mu-plugins or plugins beside it.
     *
     * A normal install lives in wp-content/plugins, so the sibling of that
     * folder is wp-content/mu-plugins. The same idea runs in reverse when
     * this plugin itself is a must-use plugin.
     *
     * @return string[]
     */
    protected static function directories_beside_plugin(string $plugin_file): array
    {
        $plugin_file = str_replace('\\', '/', $plugin_file);
        if ($plugin_file === '') {
            return array();
        }

        $plugins = dirname($plugin_file, 4);
        if (!is_string($plugins) || $plugins === '' || $plugins === '.' || $plugins === '/') {
            return array();
        }

        $plugins = rtrim(str_replace('\\', '/', $plugins), '/');
        if ($plugins === '' || $plugins === '/') {
            return array();
        }

        $directories = array($plugins);
        $parent = dirname($plugins);
        if (!is_string($parent) || $parent === '' || $parent === '.' || $parent === $plugins) {
            return $directories;
        }

        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if ($parent === '' || $parent === '/') {
            return $directories;
        }

        $base_name = strtolower(basename($plugins));
        if ($base_name === 'plugins') {
            $directories[] = $parent . '/mu-plugins';
        } elseif ($base_name === 'mu-plugins') {
            $directories[] = $parent . '/plugins';
        }

        return $directories;
    }

    /**
     * Whether to prefer the plugin file next to the loaded Polylang code.
     */
    protected static function use_loaded_plugin(): bool
    {
        return true;
    }

    /**
     * Whether to read WordPress plugin lists and the active plugins option.
     */
    protected static function use_plugin_list(): bool
    {
        return true;
    }

    /**
     * Relative plugin files WordPress has listed as active.
     *
     * @return string[]
     */
    protected static function raw_active_plugins(): array
    {
        $files = array();

        if (function_exists('get_option')) {
            try {
                $active = get_option('active_plugins', array());
            } catch (\Throwable $throwable) {
                $active = array();
            }
            if (is_array($active)) {
                foreach ($active as $file) {
                    if (is_string($file) && $file !== '') {
                        $files[] = $file;
                    }
                }
            }
        }

        if (function_exists('get_site_option')) {
            try {
                $network = get_site_option('active_sitewide_plugins', array());
            } catch (\Throwable $throwable) {
                $network = array();
            }
            if (is_array($network)) {
                foreach ($network as $file => $timestamp) {
                    if (is_string($file) && $file !== '') {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Pick a Polylang version from plugin files.
     *
     * Files listed as active are a separate pool. Their missing header is
     * reported instead of borrowing a version from a different copy.
     *
     * @param string[] $files
     * @param string[] $active_relative
     * @return array{version: string, header_found: bool, version_missing: bool, listed_active: bool}
     */
    protected static function choose_version(array $files, array $active_relative): array
    {
        $listed = array();
        $others = array();
        $header_found = false;
        $saw_readable_header = false;
        $saw_unreadable = false;
        $unreadable_label = '';

        foreach (self::unique_files($files) as $file) {
            $parsed = static::inspect_plugin_file($file);
            if (empty($parsed['is_polylang'])) {
                continue;
            }

            $header_found = true;
            if (!empty($parsed['unreadable'])) {
                $saw_unreadable = true;
                $parsed_label = (isset($parsed['label']) && $parsed['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang';
                if ($unreadable_label === '' || $parsed_label === 'Polylang') {
                    $unreadable_label = $parsed_label;
                }
                continue;
            }
            $saw_readable_header = true;
            $version = '';
            if (isset($parsed['version']) && is_string($parsed['version'])) {
                $version = $parsed['version'];
            }

            $entry = array(
                'file' => $file,
                'version' => $version,
                'label' => (isset($parsed['label']) && $parsed['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang',
            );
            if (self::matches_any_relative($file, $active_relative)) {
                $listed[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $pool = !empty($listed) ? $listed : $others;
        $best_version = '';
        $best_rank = PHP_INT_MAX;
        $label = 'Polylang';
        $label_rank = PHP_INT_MAX;
        foreach ($pool as $entry) {
            if (!isset($entry['file']) || !is_string($entry['file'])) {
                continue;
            }
            $rank = static::rank_plugin_file($entry['file']);
            $entry_version = (isset($entry['version']) && is_string($entry['version'])) ? $entry['version'] : '';
            $entry_label = (isset($entry['label']) && $entry['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang';
            if ($entry_version !== '' && $rank < $best_rank) {
                $best_rank = $rank;
                $best_version = $entry_version;
                $label = $entry_label;
                $label_rank = $rank;
                continue;
            }
            if ($best_version === '' && $rank < $label_rank) {
                $label = $entry_label;
                $label_rank = $rank;
            }
        }

        $other_label = '';
        $other_version = '';
        $other_rank = PHP_INT_MAX;
        $other_missing_label = '';
        $other_missing_rank = PHP_INT_MAX;
        if ($best_version !== '') {
            foreach ($pool as $entry) {
                if (!isset($entry['file']) || !is_string($entry['file'])) {
                    continue;
                }
                $entry_version = (isset($entry['version']) && is_string($entry['version'])) ? $entry['version'] : '';
                $entry_label = (isset($entry['label']) && $entry['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang';
                if ($entry_label === $label) {
                    continue;
                }
                $rank = static::rank_plugin_file($entry['file']);
                if ($entry_version !== '' && $rank < $other_rank) {
                    $other_rank = $rank;
                    $other_label = $entry_label;
                    $other_version = $entry_version;
                    continue;
                }
                if ($entry_version === '' && $rank < $other_missing_rank) {
                    $other_missing_rank = $rank;
                    $other_missing_label = $entry_label;
                }
            }
        } else {
            foreach ($pool as $entry) {
                if (!isset($entry['file']) || !is_string($entry['file'])) {
                    continue;
                }
                $entry_label = (isset($entry['label']) && $entry['label'] === 'Polylang Pro') ? 'Polylang Pro' : 'Polylang';
                if ($entry_label === $label) {
                    continue;
                }
                $rank = static::rank_plugin_file($entry['file']);
                if ($rank < $other_missing_rank) {
                    $other_missing_rank = $rank;
                    $other_missing_label = $entry_label;
                }
            }
        }

        return array(
            'version' => $best_version,
            'header_found' => $header_found,
            'version_missing' => $header_found && $best_version === '',
            'listed_active' => !empty($listed),
            'label' => ($saw_unreadable && !$saw_readable_header && $unreadable_label !== '') ? $unreadable_label : $label,
            'other_label' => $other_label,
            'other_version' => $other_version,
            'other_missing_label' => $other_version === '' ? $other_missing_label : '',
            'unreadable' => $saw_unreadable && !$saw_readable_header,
        );
    }

    /**
     * Lower rank is a better Polylang main file.
     */
    protected static function rank_plugin_file(string $path): int
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        if (self::ends_with($normalized, '/polylang/polylang.php')) {
            return 0;
        }
        if (self::ends_with($normalized, '/polylang-pro/polylang.php')) {
            return 1;
        }
        if (self::ends_with($normalized, '/polylang.php')) {
            return 2;
        }
        if (self::ends_with($normalized, '/polylang-pro.php')) {
            return 3;
        }

        return 4;
    }

    /**
     * Read one PHP file's plugin header.
     *
     * Official filenames count when the Plugin Name is missing or is Polylang.
     * A different Plugin Name is ignored. Any other file counts only when its
     * Plugin Name is Polylang or Polylang Pro.
     *
     * @return array{is_polylang: bool, version: string}
     */
    protected static function inspect_plugin_file(string $path): array
    {
        $rejected = array(
            'is_polylang' => false,
            'version' => '',
        );

        if ($path === '' || !is_file($path)) {
            return $rejected;
        }

        $basename = strtolower(basename(str_replace('\\', '/', $path)));
        $official_file = ($basename === 'polylang.php' || $basename === 'polylang-pro.php');
        if (!static::can_read_plugin_file($path)) {
            if (!$official_file) {
                return $rejected;
            }

            $normalized = strtolower(str_replace('\\', '/', $path));
            $pro_file = $basename === 'polylang-pro.php'
                || self::ends_with($normalized, '/polylang-pro/polylang.php');

            return array(
                'is_polylang' => true,
                'version' => '',
                'label' => $pro_file ? 'Polylang Pro' : 'Polylang',
                'unreadable' => true,
            );
        }

        $contents = self::read_header_contents($path);
        $name = self::header_field($contents, 'Plugin Name');
        $version = self::sanitize_version(self::header_field($contents, 'Version'));
        $name_matches = self::name_is_polylang($name);

        // A different Plugin Name means this file is not Polylang, even when
        // it uses Polylang's filename. An official filename with no name is
        // still reported so a missing Version header can be explained.
        if ($name !== '' && !$name_matches) {
            return $rejected;
        }
        if (!$official_file && !$name_matches) {
            return $rejected;
        }

        return array(
            'is_polylang' => true,
            'version' => $version,
            'label' => self::plugin_label($name),
        );
    }

    private static function plugin_label(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name));
        if (is_string($collapsed) && strtolower($collapsed) === 'polylang pro') {
            return 'Polylang Pro';
        }

        return 'Polylang';
    }

    /**
     * Bytes of the plugin file header WordPress itself would read.
     */
    protected static function header_byte_limit(): int
    {
        return 8192;
    }

    /**
     * Finished sentences for the admin screen.
     *
     * @param array<string, mixed> $inspection
     * @return array{active: bool, version: string, errors: string[]}
     */
    private static function build_status(bool $active, array $inspection): array
    {
        $version = '';
        if (isset($inspection['version']) && is_string($inspection['version'])) {
            $version = self::sanitize_version($inspection['version']);
        }
        $header_found = !empty($inspection['header_found']);
        $listed_active = !empty($inspection['listed_active']);
        $pro = self::product_name($inspection) === 'Polylang Pro';
        $note = trim(self::also_installed($inspection) . self::also_missing($inspection));
        $errors = array();
        $situation = '';
        $action = '';
        $unreadable = $version === '' && !empty($inspection['unreadable']);

        if ($unreadable) {
            $errors[] = self::translate(
                $active
                    ? ($pro
                        ? 'Polylang Pro is active, but its plugin file could not be read.'
                        : 'Polylang is active, but its plugin file could not be read.')
                    : ($pro
                        ? 'A Polylang Pro plugin file was found, but it could not be read.'
                        : 'A Polylang plugin file was found, but it could not be read.')
            );
        } elseif (!$active && $version !== '' && $listed_active) {
            $situation = self::fill_version(
                self::translate(
                    $pro
                        ? 'Polylang Pro %s is listed as active, but it is not loaded yet.'
                        : 'Polylang %s is listed as active, but it is not loaded yet.'
                ),
                $version
            );
            $action = self::translate(
                $pro
                    ? 'Reload this screen. If Polylang Pro is switched off, activate it, then return to Migrate to Tolka.'
                    : 'Reload this screen. If Polylang is switched off, activate it, then return to Migrate to Tolka.'
            );
        } elseif (!$active && $version !== '') {
            $situation = self::fill_version(
                self::translate(
                    $pro
                        ? 'Polylang Pro %s is installed but not active.'
                        : 'Polylang %s is installed but not active.'
                ),
                $version
            );
            $action = self::translate(
                $pro
                    ? 'Activate Polylang Pro, then return to Migrate to Tolka.'
                    : 'Activate Polylang, then return to Migrate to Tolka.'
            );
        } elseif (!$active && $header_found && $listed_active) {
            $situation = self::translate(
                $pro
                    ? 'Polylang Pro is listed as active, but its plugin file has no readable Version header and Polylang Pro is not loaded yet.'
                    : 'Polylang is listed as active, but its plugin file has no readable Version header and Polylang is not loaded yet.'
            );
            $action = self::translate(
                $pro
                    ? 'Reload this screen. If Polylang Pro is switched off, activate it, then return to Migrate to Tolka.'
                    : 'Reload this screen. If Polylang is switched off, activate it, then return to Migrate to Tolka.'
            );
        } elseif (!$active && $header_found) {
            $situation = self::translate(
                $pro
                    ? 'A Polylang Pro plugin file was found, but it has no readable Version header, and Polylang Pro is not active.'
                    : 'A Polylang plugin file was found, but it has no readable Version header, and Polylang is not active.'
            );
            $action = self::translate(
                $pro
                    ? 'Activate Polylang Pro, then return to Migrate to Tolka.'
                    : 'Activate Polylang, then return to Migrate to Tolka.'
            );
        } elseif (!$active) {
            $errors[] = self::translate('Polylang is not installed, so install and activate it, then return to Migrate to Tolka.');
        } elseif ($version === '' && $header_found) {
            $errors[] = self::translate(
                $pro
                    ? 'Polylang Pro is active, but its plugin file has no readable Version header. You can continue. The migration uses the Polylang data already loaded on this site.'
                    : 'Polylang is active, but its plugin file has no readable Version header. You can continue. The migration uses the Polylang data already loaded on this site.'
            );
        } elseif ($version === '' && !empty($inspection['read_failed'])) {
            $errors[] = self::translate(
                $pro
                    ? 'Polylang Pro is active, but its plugin header could not be read. You can continue. The migration uses the Polylang data already loaded on this site.'
                    : 'Polylang is active, but its plugin header could not be read. You can continue. The migration uses the Polylang data already loaded on this site.'
            );
        } elseif ($version === '') {
            $errors[] = self::translate('Polylang is active, but no Polylang plugin file was found.');
        }

        if ($situation !== '') {
            $parts = array($situation);
            if ($note !== '') {
                $parts[] = $note;
            }
            if ($action !== '') {
                $parts[] = $action;
            }
            $errors[] = implode(' ', $parts);
        }

        if ($active) {
            $without_absent = array();
            foreach ($errors as $error) {
                if (!is_string($error) || stripos($error, 'not installed') === false) {
                    $without_absent[] = $error;
                }
            }
            $errors = $without_absent;
        }

        return array(
            'active' => $active,
            'version' => $version,
            'errors' => self::finished_errors($errors),
        );
    }

    /**
     * Generic failure that never includes exception text or filesystem paths.
     *
     * @return array{active: bool, version: string, errors: string[]}
     */
    private static function unavailable_status(): array
    {
        return array(
            'active' => false,
            'version' => '',
            'errors' => self::finished_errors(array(
                self::translate('Polylang could not be checked on this site. Reload Migrate to Tolka and try again.'),
            )),
        );
    }

    /**
     * @param string[] $directories
     * @return string[]
     */
    private static function files_in_directories(array $directories): array
    {
        $files = array();
        $preferred = array(
            'polylang/polylang.php',
            'polylang-pro/polylang.php',
            'polylang.php',
            'polylang-pro.php',
        );

        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory === '') {
                continue;
            }
            $directory = rtrim($directory, '/\\');
            if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
                continue;
            }

            foreach ($preferred as $relative) {
                $files[] = $directory . '/' . $relative;
            }

            $entries = self::list_directory($directory);
            if ($entries === array()) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..') {
                    continue;
                }
                if (stripos($entry, 'polylang') === false) {
                    continue;
                }

                $path = $directory . '/' . $entry;
                if (is_file($path)) {
                    if (substr(strtolower($entry), -4) === '.php') {
                        $files[] = $path;
                    }
                    continue;
                }
                if (!is_dir($path) || !is_readable($path)) {
                    continue;
                }

                $inner = self::list_directory($path);
                if ($inner === array()) {
                    continue;
                }

                $preferred_children = array();
                $other_children = array();
                $folder_bootstrap = strtolower(basename($path) . '.php');
                foreach ($inner as $child) {
                    if (!is_string($child) || $child === '.' || $child === '..') {
                        continue;
                    }
                    if (substr(strtolower($child), -4) !== '.php') {
                        continue;
                    }
                    $child_path = $path . '/' . $child;
                    if (!is_file($child_path)) {
                        continue;
                    }
                    $child_key = strtolower($child);
                    if (stripos($child, 'polylang') !== false || $child_key === 'plugin.php' || $child_key === $folder_bootstrap) {
                        $preferred_children[] = $child_path;
                    } else {
                        $other_children[] = $child_path;
                    }
                }

                foreach ($preferred_children as $child_path) {
                    $files[] = $child_path;
                }

                $remaining = 40;
                foreach ($other_children as $child_path) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $files[] = $child_path;
                    $remaining--;
                }
            }
        }

        return $files;
    }

    /**
     * Plugin files WordPress already indexed, when that API is loaded.
     *
     * @return string[]
     */
    private static function files_from_wordpress_list(): array
    {
        if (!function_exists('apply_filters')) {
            return array();
        }

        $files = array();
        $groups = array();

        if (function_exists('get_plugins')) {
            $base = defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) ? WP_PLUGIN_DIR : '';
            try {
                $plugins = get_plugins();
            } catch (\Throwable $throwable) {
                $plugins = array();
            }
            if (is_array($plugins)) {
                $groups[] = array(
                    'base' => $base,
                    'plugins' => $plugins,
                );
            }
        }

        if (function_exists('get_mu_plugins')) {
            $base = defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) ? WPMU_PLUGIN_DIR : '';
            try {
                $plugins = get_mu_plugins();
            } catch (\Throwable $throwable) {
                $plugins = array();
            }
            if (is_array($plugins)) {
                $groups[] = array(
                    'base' => $base,
                    'plugins' => $plugins,
                );
            }
        }

        foreach ($groups as $group) {
            $base = isset($group['base']) && is_string($group['base']) ? rtrim($group['base'], '/\\') : '';
            if ($base === '' || !isset($group['plugins']) || !is_array($group['plugins'])) {
                continue;
            }
            foreach ($group['plugins'] as $relative => $data) {
                if (!is_string($relative) || !is_array($data)) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', $relative), '/');
                if ($relative === '') {
                    continue;
                }
                $name = isset($data['Name']) ? (string) $data['Name'] : '';
                $basename = strtolower(basename($relative));
                $official_file = ($basename === 'polylang.php' || $basename === 'polylang-pro.php');
                if (!self::name_is_polylang($name) && !$official_file) {
                    continue;
                }
                $files[] = $base . '/' . $relative;
            }
        }

        return $files;
    }

    /**
     * @return string[]
     */
    private static function active_relative_files(): array
    {
        if (!static::use_plugin_list()) {
            return array();
        }

        $files = array();
        $listed = static::raw_active_plugins();
        if (!is_array($listed)) {
            return array();
        }
        foreach ($listed as $file) {
            if (!is_string($file)) {
                continue;
            }
            $file = trim(str_replace('\\', '/', $file));
            while (strpos($file, './') === 0) {
                $file = substr($file, 2);
            }
            $file = ltrim($file, '/');
            if ($file === '' || in_array('..', explode('/', $file), true)) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @param string[] $active_relative
     */
    private static function matches_any_relative(string $path, array $active_relative): bool
    {
        foreach ($active_relative as $relative) {
            if (!is_string($relative) || $relative === '') {
                continue;
            }
            if (self::path_matches_relative($path, $relative)) {
                return true;
            }
        }

        return false;
    }

    private static function path_matches_relative(string $path, string $relative): bool
    {
        $normalized_path = strtolower(str_replace('\\', '/', $path));
        $relative = strtolower(ltrim(str_replace('\\', '/', trim($relative)), '/'));
        if ($relative === '') {
            return false;
        }

        foreach (self::known_plugin_bases() as $base) {
            $candidate = strtolower(rtrim(str_replace('\\', '/', $base), '/') . '/' . $relative);
            if ($normalized_path === $candidate) {
                return true;
            }
        }

        if (strpos($relative, '/') === false) {
            if (strtolower(basename($normalized_path)) !== $relative) {
                return false;
            }
            $parent = strtolower(basename(dirname($normalized_path)));
            if ($parent === 'plugins' || $parent === 'mu-plugins') {
                return true;
            }
            foreach (self::known_plugin_bases() as $base) {
                $base_normalized = strtolower(rtrim(str_replace('\\', '/', $base), '/'));
                if (dirname($normalized_path) === $base_normalized) {
                    return true;
                }
            }

            return false;
        }

        return self::ends_with($normalized_path, '/' . $relative);
    }

    /**
     * @return string[]
     */
    private static function known_plugin_bases(): array
    {
        $bases = array();
        if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR)) {
            $bases[] = WP_PLUGIN_DIR;
        }
        if (defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR)) {
            $bases[] = WPMU_PLUGIN_DIR;
        }
        if (defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR)) {
            $content = rtrim(WP_CONTENT_DIR, '/\\');
            $bases[] = $content . '/plugins';
            $bases[] = $content . '/mu-plugins';
        }

        return $bases;
    }

    /**
     * Whether the plugin file can be opened. Tests can override this.
     */
    protected static function can_read_plugin_file(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path);
    }

    private static function read_header_contents(string $path): string
    {
        $limit = static::header_byte_limit();
        if (!is_int($limit) || $limit < 1) {
            $limit = 8192;
        }

        // A warning here would leak into the Migrate to Tolka screen.
        $contents = @file_get_contents($path, false, null, 0, $limit);
        if (!is_string($contents) || $contents === '') {
            return '';
        }
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }
        // Windows (\r\n) and old Mac (\r) endings become \n before the header
        // match, so a trailing space or \r cannot stick to a version such as 3.9.1.
        $contents = str_replace("\r\n", "\n", $contents);
        $contents = str_replace("\r", "\n", $contents);

        return $contents;
    }

    /**
     * Plugin header field, using the same shape as WordPress get_file_data().
     */
    private static function header_field(string $contents, string $field): string
    {
        if ($contents === '' || $field === '') {
            return '';
        }

        $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi';
        $matched = preg_match($pattern, $contents, $match);
        if ($matched !== 1 || !isset($match[1]) || !is_string($match[1])) {
            return '';
        }

        $cleaned = preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]);
        if (!is_string($cleaned)) {
            return '';
        }

        return trim($cleaned);
    }

    private static function name_is_polylang(string $name): bool
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name));
        if (!is_string($collapsed)) {
            return false;
        }
        $normalized = strtolower($collapsed);

        return $normalized === 'polylang' || $normalized === 'polylang pro';
    }

    /**
     * Keep a header version only when it is a single version token.
     *
     * "0" is a real version. Comparisons use === '' because empty("0") is true.
     * "3.9.1" is returned unchanged. Do not cast the token to float or int.
     */
    private static function sanitize_version(string $version): string
    {
        $version = trim($version);
        if ($version === '' || strlen($version) > 64) {
            return '';
        }
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]*$/', $version) !== 1) {
            return '';
        }

        return $version;
    }

    /**
     * @param array<mixed> $errors
     * @return string[]
     */
    private static function finished_errors(array $errors): array
    {
        $clean = array();
        $seen = array();
        foreach ($errors as $error) {
            if (!is_string($error)) {
                continue;
            }
            $error = trim($error);
            if ($error === '') {
                continue;
            }
            if (isset($seen[$error])) {
                continue;
            }
            $seen[$error] = true;
            $clean[] = $error;
        }

        return $clean;
    }

    /**
     * @return string[]
     */
    private static function list_directory(string $directory): array
    {
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return array();
        }

        return $entries;
    }

    private static function resolve_path(string $path): string
    {
        $resolved = @realpath($path);
        if (!is_string($resolved) || $resolved === '') {
            return '';
        }

        return $resolved;
    }

    private static function ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '' || strlen($haystack) < strlen($needle)) {
            return false;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private static function unique_files(array $files): array
    {
        $unique = array();
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $key = $file;
            if (is_file($file)) {
                $resolved = self::resolve_path($file);
                if ($resolved !== '') {
                    $key = $resolved;
                }
            }
            $unique[$key] = $key;
        }

        return array_values($unique);
    }

    private static function reflection_filename(string $kind, string $name): string
    {
        try {
            if ($kind === 'function') {
                if (!function_exists($name)) {
                    return '';
                }
                $reflection = new \ReflectionFunction($name);
            } else {
                $global = '\\' . ltrim($name, '\\');
                if (!class_exists($global, false)) {
                    return '';
                }
                $reflection = new \ReflectionClass($global);
            }
            $filename = $reflection->getFileName();
        } catch (\Throwable $throwable) {
            return '';
        }

        if (!is_string($filename) || $filename === '' || !is_file($filename)) {
            return '';
        }

        return $filename;
    }

    private static function is_walk_boundary(string $directory): bool
    {
        $normalized = strtolower(rtrim(str_replace('\\', '/', $directory), '/'));
        if ($normalized === '' || $normalized === '/' || $normalized === '.') {
            return true;
        }

        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            return false;
        }

        $boundary = dirname(WP_CONTENT_DIR);
        if (!is_string($boundary) || $boundary === '' || $boundary === '.' || $boundary === '/') {
            return false;
        }
        $boundary = strtolower(rtrim(str_replace('\\', '/', $boundary), '/'));

        return $boundary !== '' && $normalized === $boundary;
    }

    private static function product_name(array $inspection): string
    {
        if (isset($inspection['label']) && $inspection['label'] === 'Polylang Pro') {
            return 'Polylang Pro';
        }

        return 'Polylang';
    }

    /**
     * Second sentence when the other Polylang edition is also on disk.
     */
    private static function also_installed(array $inspection): string
    {
        if (!isset($inspection['other_version']) || !is_string($inspection['other_version'])) {
            return '';
        }
        $other_version = self::sanitize_version($inspection['other_version']);
        if ($other_version === '') {
            return '';
        }
        $other_label = (isset($inspection['other_label']) && $inspection['other_label'] === 'Polylang Pro')
            ? 'Polylang Pro'
            : 'Polylang';
        if ($other_label === self::product_name($inspection)) {
            return '';
        }

        $sentence = self::fill_version(
            self::translate(
                $other_label === 'Polylang Pro'
                    ? 'Polylang Pro %s is also installed.'
                    : 'Polylang %s is also installed.'
            ),
            $other_version
        );

        return ' ' . $sentence;
    }

    /**
     * Second sentence when the other edition is present but has no Version line.
     */
    private static function also_missing(array $inspection): string
    {
        if (isset($inspection['other_version']) && is_string($inspection['other_version']) && self::sanitize_version($inspection['other_version']) !== '') {
            return '';
        }
        $other_label = (isset($inspection['other_missing_label']) && $inspection['other_missing_label'] === 'Polylang Pro')
            ? 'Polylang Pro'
            : '';
        if ($other_label === '' && isset($inspection['other_missing_label']) && $inspection['other_missing_label'] === 'Polylang') {
            $other_label = 'Polylang';
        }
        if ($other_label === '' || $other_label === self::product_name($inspection)) {
            return '';
        }

        $sentence = self::translate(
            $other_label === 'Polylang Pro'
                ? 'A Polylang Pro plugin file was also found, but it has no readable Version header.'
                : 'A Polylang plugin file was also found, but it has no readable Version header.'
        );

        return ' ' . $sentence;
    }

    private static function fill_version(string $template, string $version): string
    {
        if (substr_count($template, '%s') !== 1) {
            return $template . ' (' . $version . ')';
        }

        $filled = sprintf($template, $version);
        if (!is_string($filled) || $filled === '') {
            return $template;
        }

        return $filled;
    }

    private static function translate(string $text): string
    {
        if ($text === '' || !function_exists('__')) {
            return $text;
        }

        $translated = __($text, 'migration-manager');
        if (!is_string($translated) || $translated === '') {
            return $text;
        }

        return $translated;
    }
}
