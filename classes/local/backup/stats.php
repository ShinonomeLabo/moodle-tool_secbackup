<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_secbackup\local\backup;

/**
 * Makes the statistics of a backup run that are sent to the backup service: counts and sizes, never personal data.
 *
 * The service refuses a report whose statistics break its limits, and that would fail the whole run, so every value is
 * brought inside the limits here, or the caller's mistake is a coding_exception. The list of tables is returned as an
 * object (stdClass), so that it is a JSON object even when it is empty; use encode() or the same flags to write it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stats {
    /** @var int The most tables that are reported. */
    public const MAX_TABLES = 2000;

    /** @var int The most authentication methods that are reported. */
    public const MAX_AUTH_METHODS = 30;

    /** @var int The most seconds that a duration can have (30 days). */
    public const MAX_SECONDS = 2592000;

    /** @var int The largest number the service takes as a size (2^53 - 1). */
    public const MAX_BYTES = 9007199254740991;

    /** @var int The most characters of the release text. */
    private const MAX_RELEASE = 100;

    /** @var string[] The sizes that are reported. */
    private const BYTE_KEYS = ['db', 'files', 'dataroot', 'code'];

    /**
     * Collects the statistics.
     *
     * @param array $tablerows Rows by table name, without the prefix of the database.
     * @param array $bytes Sizes by db, files, dataroot and code (integers, 0 to 9007199254740991).
     * @param int $durationseconds How long the run took.
     * @param array $moodle ['release', 'version', 'build'], as core_diff::release_parts() gives them.
     * @param bool $coremodified Whether the files of the Moodle program differ from the official ones.
     * @param bool $externalstorage Whether the site keeps files outside the data folder.
     * @return array The statistics, as the complete report holds them.
     * @throws \coding_exception For sizes, a version or a build that the service cannot take.
     */
    public static function collect(
        array $tablerows,
        array $bytes,
        int $durationseconds,
        array $moodle,
        bool $coremodified,
        bool $externalstorage
    ): array {
        global $DB;
        $active = $DB->count_records_select('user', 'deleted = 0 AND lastaccess >= ?', [time() - 365 * DAYSECS]);
        return [
            'users_active_365d' => $active,
            'courses' => $DB->count_records_select('course', 'id <> ?', [SITEID]),
            'auth_methods' => self::auth_names(get_enabled_auth_plugins()),
            'tables' => self::tables($tablerows),
            'bytes' => self::bytes($bytes),
            'duration_seconds' => max(0, min(self::MAX_SECONDS, $durationseconds)),
            'moodle' => self::moodle($moodle),
            'core_modified' => $coremodified,
            'external_storage' => $externalstorage,
        ];
    }

    /**
     * Writes the statistics the way the API client writes a request body.
     *
     * @param array $stats The statistics.
     * @return string JSON.
     */
    public static function encode(array $stats): string {
        return (string) json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Removes tables, those with the fewest rows first, until the statistics fit in a size.
     *
     * Ties go by name, the name that sorts last first. Nothing else is removed; if the statistics do not fit even without
     * tables, they are returned with an empty list of tables.
     *
     * @param array $stats Statistics from collect().
     * @param int $maxjsonbytes The most bytes that encode() may give.
     * @return array
     */
    public static function fit(array $stats, int $maxjsonbytes): array {
        $tables = (array) $stats['tables'];
        $order = array_keys($tables);
        usort($order, static fn($a, $b) => $tables[$a] <=> $tables[$b] ?: strcmp((string) $b, (string) $a));
        $order = array_map('strval', $order);
        $with = static function (int $removed) use ($stats, $tables, $order): array {
            $stats['tables'] = (object) array_diff_key($tables, array_flip(array_slice($order, 0, $removed)));
            return $stats;
        };
        $low = 0;
        $high = count($order);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (strlen(self::encode($with($middle))) <= $maxjsonbytes) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }
        return $with($low);
    }

    /**
     * Keeps the names of authentication methods that the service can take: valid, unique, sorted, 30 at most.
     *
     * @param array $enabled The names of the enabled methods.
     * @return string[]
     */
    public static function auth_names(array $enabled): array {
        $names = array_unique(array_filter(
            array_map('strval', $enabled),
            static fn($name) => preg_match('/^[a-z0-9_]{1,40}\z/', $name) === 1
        ));
        sort($names, SORT_STRING);
        return array_slice($names, 0, self::MAX_AUTH_METHODS);
    }

    /**
     * Keeps the tables that the service can take: the ones with most rows, 2,000 at most.
     *
     * @param array $tablerows Rows by table name.
     * @return \stdClass Rows by table name, in the order of the names.
     */
    private static function tables(array $tablerows): \stdClass {
        $valid = [];
        foreach ($tablerows as $name => $rows) {
            if (is_int($rows) && $rows >= 0 && preg_match('/^[a-z0-9_]{1,64}\z/', (string) $name) === 1) {
                $valid[(string) $name] = $rows;
            }
        }
        $names = array_keys($valid);
        usort($names, static fn($a, $b) => [$valid[$b], (string) $a] <=> [$valid[$a], (string) $b]);
        $kept = [];
        foreach (array_slice($names, 0, self::MAX_TABLES) as $name) {
            $kept[$name] = $valid[$name];
        }
        ksort($kept, SORT_STRING);
        return (object) $kept;
    }

    /**
     * Checks the sizes.
     *
     * @param array $bytes The sizes.
     * @return int[]
     * @throws \coding_exception When a key is missing or extra, or a size is not an integer in range.
     */
    private static function bytes(array $bytes): array {
        $keys = array_keys($bytes);
        sort($keys);
        $expected = self::BYTE_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \coding_exception('The sizes must be exactly db, files, dataroot and code.');
        }
        $checked = [];
        foreach (self::BYTE_KEYS as $key) {
            if (!is_int($bytes[$key]) || $bytes[$key] < 0 || $bytes[$key] > self::MAX_BYTES) {
                throw new \coding_exception('A size must be an integer from 0 to 9007199254740991.');
            }
            $checked[$key] = $bytes[$key];
        }
        return $checked;
    }

    /**
     * Makes the description of the Moodle program.
     *
     * @param array $moodle ['release', 'version', 'build'].
     * @return string[]
     * @throws \coding_exception For a version or a build of another shape.
     */
    private static function moodle(array $moodle): array {
        global $DB;
        $version = (string) ($moodle['version'] ?? '');
        $build = (string) ($moodle['build'] ?? '');
        if (preg_match('/^\d{10}(\.\d{2})?\z/', $version) !== 1 || preg_match('/^\d{8}\z/', $build) !== 1) {
            throw new \coding_exception('The version or the build of Moodle is not in the shape that the service takes.');
        }
        $release = mb_substr((string) preg_replace('/\p{C}/u', '', (string) ($moodle['release'] ?? '')), 0, self::MAX_RELEASE);
        if ($DB->get_dbfamily() === 'postgres') {
            $family = 'postgres';
        } else {
            $family = $DB->get_dbvendor() === 'mariadb' ? 'mariadb' : 'mysql';
        }
        return [
            'release' => $release === '' ? $version : $release,
            'version' => $version,
            'build' => $build,
            'db_family' => $family,
        ];
    }
}
