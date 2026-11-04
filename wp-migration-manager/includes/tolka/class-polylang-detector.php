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
        try {
            $active = static::is_active();
        } catch (\Throwable $throwable) {
            return self::unavailable_status();
        }

        try {
            $inspection = static::inspect();
        } catch (\Throwable $throwable) {
            $inspection = array(
                'version' => '',
                'header_found' => false,
                'version_missing' => false,
                'listed_active' => false,
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
        if (function_exists('pll_languages_list')) {
            return true;
        }

        return class_exists('PLL') || class_exists('Polylang');
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
        for ($depth = 0; $depth < 6; $depth++) {
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

        $sibling = dirname(__FILE__, 4);
        if (is_string($sibling) && $sibling !== '') {
            $candidates[] = $sibling;
        }

        $directories = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || !is_dir($candidate) || !is_readable($candidate)) {
                continue;
            }
            $resolved = realpath($candidate);
            $directories[] = is_string($resolved) && $resolved !== '' ? $resolved : $candidate;
        }

        return array_values(array_unique($directories));
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

        foreach (self::unique_files($files) as $file) {
            $parsed = static::inspect_plugin_file($file);
            if (empty($parsed['is_polylang'])) {
                continue;
            }

            $header_found = true;
            $version = '';
            if (isset($parsed['version']) && is_string($parsed['version'])) {
                $version = $parsed['version'];
            }

            $entry = array(
                'file' => $file,
                'version' => $version,
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
        foreach ($pool as $entry) {
            if (!isset($entry['version']) || !is_string($entry['version']) || $entry['version'] === '') {
                continue;
            }
            if (!isset($entry['file']) || !is_string($entry['file'])) {
                continue;
            }
            $rank = static::rank_plugin_file($entry['file']);
            if ($rank < $best_rank) {
                $best_rank = $rank;
                $best_version = $entry['version'];
            }
        }

        return array(
            'version' => $best_version,
            'header_found' => $header_found,
            'version_missing' => $header_found && $best_version === '',
            'listed_active' => !empty($listed),
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

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $rejected;
        }

        $contents = self::read_header_contents($path);
        $name = self::header_field($contents, 'Plugin Name');
        $version = self::sanitize_version(self::header_field($contents, 'Version'));
        $basename = strtolower(basename(str_replace('\\', '/', $path)));
        $official_file = ($basename === 'polylang.php' || $basename === 'polylang-pro.php');
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
        );
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
        $errors = array();

        if (!$active && $version !== '' && $listed_active) {
            $errors[] = self::fill_version(
                self::translate('Polylang %s is listed as active, but it is not loaded. Reload Migrate to Tolka. If Polylang is switched off, activate it and open this page again.'),
                $version
            );
        } elseif (!$active && $version !== '') {
            $errors[] = self::fill_version(
                self::translate('Polylang %s is installed but not active. Activate Polylang, then return to Migrate to Tolka.'),
                $version
            );
        } elseif (!$active && $header_found && $listed_active) {
            $errors[] = self::translate('Polylang is listed as active, but its plugin file has no readable Version header, and Polylang is not loaded. Reload Migrate to Tolka. If Polylang is switched off, activate it and open this page again.');
        } elseif (!$active && $header_found) {
            $errors[] = self::translate('A Polylang plugin file was found, but it has no readable Version header, and Polylang is not active. Activate Polylang, then return to Migrate to Tolka.');
        } elseif (!$active) {
            $errors[] = self::translate('Polylang is not active. Install and activate Polylang, then return to Migrate to Tolka.');
        } elseif ($version === '' && $header_found) {
            $errors[] = self::translate('Polylang is active, but its plugin file has no readable Version header. You can continue. The migration uses the Polylang data already loaded on this site.');
        } elseif ($version === '') {
            $errors[] = self::translate('Polylang is active, but its plugin header could not be read. You can continue. The migration uses the Polylang data already loaded on this site.');
        }

        return array(
            'active' => $active,
            'version' => $version,
            'errors' => array_values($errors),
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
            'errors' => array(
                self::translate('Polylang could not be checked on this site. Reload Migrate to Tolka and try again.'),
            ),
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

            $entries = scandir($directory);
            if (!is_array($entries)) {
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

                $inner = scandir($path);
                if (!is_array($inner)) {
                    continue;
                }

                $preferred_children = array();
                $other_children = array();
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
                    if (stripos($child, 'polylang') !== false) {
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

    private static function read_header_contents(string $path): string
    {
        $limit = static::header_byte_limit();
        if (!is_int($limit) || $limit < 1) {
            $limit = 8192;
        }

        $contents = file_get_contents($path, false, null, 0, $limit);
        if (!is_string($contents) || $contents === '') {
            return '';
        }
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }

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
                $resolved = realpath($file);
                if (is_string($resolved) && $resolved !== '') {
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
                if (!class_exists($name, false)) {
                    return '';
                }
                $reflection = new \ReflectionClass($name);
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
