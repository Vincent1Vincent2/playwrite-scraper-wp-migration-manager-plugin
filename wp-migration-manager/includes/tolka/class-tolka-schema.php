<?php

/**
 * Tolka migration package schema.
 *
 * Turns exporter envelopes into one plain package. Polylang data is not read
 * or changed. Text is stored without null bytes or invalid UTF-8 so the
 * database does not cut the package short.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the Tolka migration package.
 */
class Tolka_Schema
{
    /**
     * Package format stored for Tolka.
     */
    const FORMAT = 'tolka-migration';

    /**
     * Package version.
     */
    const VERSION = 1;

    /**
     * Current time as ISO-8601 GMT, with a Z suffix.
     *
     * @return string
     */
    public static function gmt_timestamp()
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Whether a timestamp is ISO-8601 and in GMT.
     *
     * Accepts a Z suffix or an explicit +00:00 offset.
     *
     * @param mixed $value Candidate timestamp.
     * @return bool
     */
    public static function is_gmt_timestamp($value)
    {
        if (!is_string($value)) {
            return false;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/', $value)) {
            return false;
        }

        $stamp = strtotime($value);
        if ($stamp === false) {
            return false;
        }

        $zulu = gmdate('Y-m-d\TH:i:s\Z', $stamp);
        $offset = gmdate('Y-m-d\TH:i:s\+00:00', $stamp);

        return $value === $zulu || $value === $offset;
    }

    /**
     * Section keys, in package order.
     *
     * @return string[]
     */
    public static function sections()
    {
        return array(
            'languages',
            'posts',
            'terms',
            'strings',
            'menus',
            'media',
        );
    }

    /**
     * Build a Tolka package from exporter envelopes.
     *
     * Each part is an exporter envelope with `items` and `errors`. Missing
     * parts and unreadable envelopes become empty item lists. Exporter error
     * strings are not copied into the package.
     *
     * @param array $parts Keys languages, posts, terms, strings, menus, media.
     * @return array
     */
    public static function package(array $parts)
    {
        $package = array(
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => self::gmt_timestamp(),
        );

        foreach (self::sections() as $section) {
            $package[$section] = self::items_from(
                isset($parts[$section]) ? $parts[$section] : null
            );
        }

        return $package;
    }

    /**
     * Copy the items list out of an exporter envelope.
     *
     * @param mixed $envelope Envelope, or anything else when the part is missing.
     * @return array
     */
    private static function items_from($envelope)
    {
        if (!is_array($envelope) || !isset($envelope['items']) || !is_array($envelope['items'])) {
            return array();
        }

        $seen = array();

        return self::snapshot($envelope['items'], $seen, 0);
    }

    /**
     * Copy values into plain data so the package is a stable snapshot.
     *
     * Objects become arrays of their public properties. Cycles and very deep
     * values are dropped instead of hanging the migration.
     *
     * @param mixed $value Value to copy.
     * @param array $seen  Object ids already visited.
     * @param int   $depth Current nesting depth.
     * @return mixed
     */
    private static function snapshot($value, array &$seen, $depth)
    {
        if ($depth > 32) {
            return null;
        }

        if (is_array($value)) {
            $copy = array();
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $key = trim(self::text($key));
                }
                $copy[$key] = self::snapshot($item, $seen, $depth + 1);
                if ($key === 'group' && is_string($copy[$key])) {
                    $copy[$key] = trim($copy[$key]);
                }
            }
            return $copy;
        }

        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            return self::snapshot(get_object_vars($value), $seen, $depth + 1);
        }

        if (is_string($value)) {
            return self::text($value);
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            return $value;
        }

        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /**
     * Copy a package into plain data that can be stored in the database.
     *
     * @param array $value Package or section value.
     * @return array
     */
    public static function for_storage(array $value)
    {
        $seen = array();
        $copy = self::snapshot($value, $seen, 0);

        return is_array($copy) ? $copy : array();
    }

    /**
     * Make text safe to store in a WordPress option or meta value.
     *
     * Null bytes are removed so MySQL does not cut the value short. Bytes
     * that are not UTF-8 are removed. Ordinary text, including accents and
     * HTML, is kept.
     *
     * @param mixed $value Raw text.
     * @return string
     */
    public static function text($value)
    {
        $text = str_replace("\0", '', (string) $value);

        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($clean)) {
                return $clean;
            }
        }

        return $text;
    }
}
