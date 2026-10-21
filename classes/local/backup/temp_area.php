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
     * it would be measured against the folder the process happens to be in. Nothing is reported as a PHP warning, and a PHP
     * that has disabled the functions (disable_functions) gives null.
     *
     * @param string $folder Absolute folder.
     * @return array{0: ?int, 1: ?int} Free and total bytes; null for what cannot be read.
     */
    public static function disk_space(string $folder): array {
        if (preg_match('~^(/|[A-Za-z]:[\\\\/])~', $folder) !== 1) {
            return [null, null];
        }
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            while (!is_dir($folder) && dirname($folder) !== $folder) {
                $folder = dirname($folder);
            }
            // PHP 8 throws an Error for a function that disable_functions has turned off; function_exists() says it is not there.
            $free = function_exists('disk_free_space') ? disk_free_space($folder) : false;
            $total = function_exists('disk_total_space') ? disk_total_space($folder) : false;
        } finally {
            restore_error_handler();
        }
        return [$free === false ? null : (int) $free, $total === false ? null : (int) $total];
    }

    /**
     * Makes the folder of a run.
     *
     * The folder is resolved once with realpath() and must be the folder of the run inside the resolved temporary folder, so
     * that a link in the way is refused. PHP cannot close the race completely: between this check and a later use, a person who
     * can write to the temporary folder could still swap a folder for a link.
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
                && is_writable($path) && $this->resolved($runid) !== null;
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
     * Zero never means "unlimited": it means that there is no room, or that the free space is not known. A caller that gets 0
     * must not write a temporary file.
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
        $bytes = 0;
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            $real = $this->resolved($runid);
            foreach ($real === null ? [] : $this->files_below($real, \RecursiveIteratorIterator::LEAVES_ONLY) as $item) {
                $bytes += $item->isLink() || !$item->isFile() ? 0 : max(0, (int) filesize($item->getPathname()));
            }
        } finally {
            restore_error_handler();
        }
        return $bytes;
    }

    /**
     * Gives the folder of a run and everything in it the current time as the time of the last change.
     *
     * Moodle's cleanup of its temporary folder (\core\task\file_temp_cleanup_task, every six hours) removes every file that
     * was not changed for the hours of the setting "tempdatafoldercleanup", one at the least. Many files of a run are written
     * once and then only read (the journals, the present list, a chunk that waits for its next try), and a run that waits for
     * its next window does not change anything for most of a day, so every step and every status request refreshes them.
     * A symbolic link is left as it is: what it points to is never touched. Nothing happens when the folder is missing (the run
     * is on another server).
     *
     * @param int $runid ID of the run.
     */
    public function refresh(int $runid): void {
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            $real = $this->resolved($runid);
            if ($real === null) {
                return;
            }
            foreach ($this->files_below($real, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $item->isLink() || touch($item->getPathname());
            }
            touch($real);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Removes the folder of a run with everything in it. Nothing happens when it is missing.
     *
     * A symbolic link is removed as a link: what it points to is never entered or changed. The folder is resolved once and
     * everything is removed through the resolved path; before each removal the parent of the item is checked to still be
     * inside it. PHP cannot close the race completely: a person who can write to the temporary folder could still swap a folder
     * for a link between that check and the removal.
     *
     * @param int $runid ID of the run.
     * @return bool True when the folder of the run is gone; false when something is left.
     */
    public function clear(int $runid): bool {
        $path = $this->path($runid);
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            if (is_link($path)) {
                unlink($path);
            } else if (($real = $this->resolved($runid)) !== null) {
                foreach ($this->files_below($real, \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                    // An item whose parent is no longer inside the folder (swapped for a link) is left alone.
                    $this->is_inside($item->getPathname(), $real) && $this->remove($item);
                }
                rmdir($real);
            }
            return !file_exists($path) && !is_link($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Removes the folders of every run and the records of the present lists from the temporary folder, and nothing else of it
     * (a folder that an administrator named may hold other files). The uninstallation calls it.
     */
    public function clear_all(): void {
        foreach (glob($this->limits->tempdir . '/run-*', GLOB_NOSORT) ?: [] as $path) {
            if (preg_match('~/run-([1-9]\d{0,17})\z~', $path, $m) === 1) {
                $this->clear((int) $m[1]);
            }
        }
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            foreach (glob($this->limits->tempdir . '/present-*.records', GLOB_NOSORT) ?: [] as $path) {
                unlink($path);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Removes a file, a link or an empty folder.
     *
     * @param \SplFileInfo $item The item.
     * @return bool Whether it was removed.
     */
    private function remove(\SplFileInfo $item): bool {
        return $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
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
     * The resolved folder of a run, when it is a real folder (not a link) inside the resolved temporary folder.
     *
     * @param int $runid ID of the run.
     * @return string|null The resolved path, or null.
     */
    private function resolved(int $runid): ?string {
        $path = $this->path($runid);
        $base = realpath($this->limits->tempdir);
        $real = is_link($path) || !is_dir($path) ? false : realpath($path);
        return $base !== false && $real !== false && $real === rtrim($base, '/') . '/run-' . $runid ? $real : null;
    }

    /**
     * Whether the parent folder of an item is still inside the resolved folder of the run.
     *
     * @param string $item The item.
     * @param string $real The resolved folder of the run.
     * @return bool
     */
    private function is_inside(string $item, string $real): bool {
        $parent = realpath(dirname($item));
        return $parent !== false && ($parent === $real || str_starts_with($parent, $real . '/'));
    }

    /**
     * Everything below a folder, without following symbolic links and without stopping at a folder that cannot be read.
     *
     * @param string $path The folder.
     * @param int $mode A mode of RecursiveIteratorIterator.
     * @return \Traversable
     */
    private function files_below(string $path, int $mode): \Traversable {
        try {
            return new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                $mode,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\UnexpectedValueException $e) {
            return new \EmptyIterator();
        }
    }
}
