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
 * The roots of the data directory that go into a backup, and the folders under them that are left out.
 *
 * The file store (filedir) is packed by its own code from the files table; the folders that Moodle can build again (the caches,
 * the sessions, the locks, the temporary files, the trash) are not backed up. The rest of the data directory is, with the secret
 * directory, which is never left out.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dataroot_source {
    /** @var string[] Folders of the data directory that are always left out. */
    private const FIXED = ['cache', 'filedir', 'localcache', 'lock', 'sessions', 'temp', 'trashdir'];

    /**
     * The roots to walk.
     *
     * @return array[] 'dataroot' => [the data directory, relative paths left out], and 'secret' => [the secret directory, []]
     *     when it is set and outside the data directory.
     */
    public static function roots(): array {
        global $CFG;
        $real = realpath($CFG->dataroot);
        $excluded = self::FIXED;
        if ($real !== false) {
            $settings = [
                $CFG->tempdir ?? null, $CFG->cachedir ?? null, $CFG->localcachedir ?? null, $CFG->backuptempdir ?? null,
                $CFG->trashdir ?? null, limits::current()->tempdir,
            ];
            foreach ($settings as $path) {
                $inside = self::inside($real, $path);
                if ($inside !== null) {
                    $excluded[] = $inside;
                }
            }
        }
        $excluded = array_values(array_unique($excluded));
        sort($excluded, SORT_STRING);
        $roots = ['dataroot' => [$CFG->dataroot, $excluded]];
        $secret = isset($CFG->secretdataroot) ? realpath($CFG->secretdataroot) : false;
        if ($secret !== false && $real !== false && $secret !== $real && self::inside($real, $secret) === null) {
            $roots['secret'] = [$secret, []];
        }
        return $roots;
    }

    /**
     * The path of a folder relative to the data directory, if the folder exists and is under it.
     *
     * @param string $real The real path of the data directory.
     * @param string|null $path A path from the settings.
     * @return string|null The relative path with "/", or null when the folder does not exist, is the data directory, or is
     *     outside it.
     */
    private static function inside(string $real, ?string $path): ?string {
        $resolved = $path === null || $path === '' ? false : realpath($path);
        if ($resolved === false || !str_starts_with($resolved, rtrim($real, '/') . '/')) {
            return null;
        }
        return substr($resolved, strlen(rtrim($real, '/')) + 1);
    }
}
