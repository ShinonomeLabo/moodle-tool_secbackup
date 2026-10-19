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

use tool_secbackup\local\plugin_exception;

/**
 * The temporary folder of a run and the budget of the files in it.
 *
 * A run keeps the chunks that are being sent or wait for another try, and the material of the list of generations, in the
 * folder run-<ID> inside the temporary folder of the settings. Nothing in a message names more than that folder.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class temp_area {
    /** @var int One MiB. */
    private const MIB = 1048576;

    /** @var int Largest budget when none is set, in MB. */
    private const DEFAULT_CAP_MB = 2048;

    /** @var limits The limits. */
    private limits $limits;

    /** @var callable Function that gives [free bytes, total bytes] of the disk that holds a folder. */
    private $diskspace;

    /**
     * Constructor.
     *
     * @param limits $limits The limits.
     * @param callable|null $diskspace Function (string $folder): array{0: ?int, 1: ?int}; the default measures the disk.
     */
    public function __construct(limits $limits, ?callable $diskspace = null) {
        $this->limits = $limits;
        $this->diskspace = $diskspace ?? [self::class, 'disk_space'];
    }

    /**
     * The temporary area of the limits that are set.
     *
     * @return self
     */
    public static function create(): self {
        return new self(limits::current());
    }

    /**
     * The free and the total space of the disk that holds a folder.
     *
     * The folder may not exist yet: the nearest folder above it that does is measured. A relative name is not measured, because
     * it would be measured against the folder the process happens to be in.
     *
     * @param string $folder Absolute folder.
     * @return array{0: ?int, 1: ?int} Free and total bytes; null for what cannot be read.
     */
    public static function disk_space(string $folder): array {
        if (preg_match('~^(/|[A-Za-z]:[\\\\/])~', $folder) !== 1) {
            return [null, null];
        }
        while (!is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            $free = disk_free_space($folder);
            $total = disk_total_space($folder);
        } finally {
            restore_error_handler();
        }
        return [$free === false ? null : (int) $free, $total === false ? null : (int) $total];
    }

    /**
     * Makes the folder of a run.
     *
     * @param int $runid ID of the run.
     * @return string The folder.
     * @throws plugin_exception temp_write_failed when the folder cannot be made or written, or is a symbolic link.
     */
    public function for_run(int $runid): string {
        global $CFG;
        $path = $this->path($runid);
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            $usable = !is_link($path) && (is_dir($path) || mkdir($path, (int) $CFG->directorypermissions, true) || is_dir($path))
                && is_writable($path);
        } finally {
            restore_error_handler();
        }
        if (!$usable) {
            throw new plugin_exception('temp_write_failed', null, 'folder: ' . $path);
        }
        return $path;
    }

    /**
     * The budget of the temporary files of a run.
     *
     * @return int Bytes: the setting when it is above 0, otherwise the smaller of 10% of the free space and 2,048 MB.
     */
    public function budget_bytes(): int {
        if ($this->limits->tempbudgetmb > 0) {
            return $this->limits->tempbudgetmb * self::MIB;
        }
        $free = ($this->diskspace)($this->limits->tempdir)[0];
        return min(intdiv($free ?? 0, 10), self::DEFAULT_CAP_MB * self::MIB);
    }

    /**
     * The size of the regular files in the folder of a run (links are not followed or counted).
     *
     * @param int $runid ID of the run.
     * @return int Bytes.
     */
    public function used_bytes(int $runid): int {
        $path = $this->path($runid);
        if (is_link($path) || !is_dir($path)) {
            return 0;
        }
        $bytes = 0;
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            foreach ($this->files_below($path, \RecursiveIteratorIterator::LEAVES_ONLY) as $item) {
                $bytes += $item->isLink() || !$item->isFile() ? 0 : max(0, (int) filesize($item->getPathname()));
            }
        } finally {
            restore_error_handler();
        }
        return $bytes;
    }

    /**
     * Removes the folder of a run with everything in it. Nothing happens when it is missing.
     *
     * A symbolic link is removed as a link: what it points to is never entered or changed.
     *
     * @param int $runid ID of the run.
     */
    public function clear(int $runid): void {
        $path = $this->path($runid);
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            if (is_link($path)) {
                unlink($path);
            } else if (is_dir($path)) {
                foreach ($this->files_below($path, \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                    if ($item->isDir() && !$item->isLink()) {
                        rmdir($item->getPathname());
                    } else {
                        unlink($item->getPathname());
                    }
                }
                rmdir($path);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The folder of a run.
     *
     * @param int $runid ID of the run.
     * @return string
     */
    private function path(int $runid): string {
        return $this->limits->tempdir . '/run-' . $runid;
    }

    /**
     * Everything below a folder, without following symbolic links.
     *
     * @param string $path The folder.
     * @param int $mode A mode of RecursiveIteratorIterator.
     * @return \RecursiveIteratorIterator
     */
    private function files_below(string $path, int $mode): \RecursiveIteratorIterator {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            $mode
        );
    }
}
