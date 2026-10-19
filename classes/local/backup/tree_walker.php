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

use tool_secbackup\local\error_silencer;

/**
 * Walks a tree of folders and gives the regular files in it, in pages, in byte order of their paths.
 *
 * The order is that of strcmp() on the whole relative path with "/" as the separator, which is the order of the lists of the
 * backup service. A folder is walked with its entries sorted by the name for a file and by the name and "/" for a folder, so
 * that "a-c" comes before "a/b". A page starts after a path that the caller gives; the folders that hold only smaller paths
 * are not walked.
 *
 * What is left out: the excluded paths and what is under them; symbolic links, which are never followed and are listed
 * by skipped(); anything that is neither a regular file nor a folder, such as a socket, which is left out without a note. What
 * is listed by unreadable(): a folder that cannot be listed, an entry that is gone when it is looked at, and a name that a list
 * in JSON cannot hold (a control character or bytes that are not UTF-8), as "hex:" and the hexadecimal of its relative path.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tree_walker {
    /** @var string The root, without a trailing slash. */
    private string $root;

    /** @var bool[] The excluded relative paths as keys. */
    private array $excluded = [];

    /** @var \Closure|null Lists a folder: takes its path, gives its names, or false. */
    private ?\Closure $lister;

    /** @var bool[] Relative paths of symbolic links as keys, in the order they were found. */
    private array $skipped = [];

    /** @var bool[] Relative paths or "hex:" names that could not be read as keys. */
    private array $unreadable = [];

    /**
     * Constructor.
     *
     * @param string $root The folder to walk.
     * @param string[] $excluded Relative paths with "/" as the separator; a path is left out with what is under it.
     * @param \Closure|null $lister Lists a folder instead of scandir(), for tests: takes the path, gives names or false.
     */
    public function __construct(string $root, array $excluded, ?\Closure $lister = null) {
        $this->root = rtrim($root, '/');
        foreach ($excluded as $path) {
            $this->excluded[trim($path, '/')] = true;
        }
        $this->lister = $lister;
    }

    /**
     * The next files after a path.
     *
     * @param string|null $after The last path of the page before, or null to start at the beginning.
     * @param int $limit The most files to give.
     * @return array[] ['path' => relative path, 'size' => bytes by lstat() when the folder was listed], in byte order.
     */
    public function next(?string $after, int $limit): array {
        $items = [];
        if ($limit > 0) {
            $this->walk('', $after, $limit, $items);
        }
        return $items;
    }

    /**
     * The symbolic links that were found, which are not followed.
     *
     * @return string[] Relative paths.
     */
    public function skipped(): array {
        return array_map('strval', array_keys($this->skipped));
    }

    /**
     * What could not be read.
     *
     * @return string[] Relative paths, and "hex:" names for those that are no text.
     */
    public function unreadable(): array {
        return array_map('strval', array_keys($this->unreadable));
    }

    /**
     * Walks a folder.
     *
     * @param string $relative Relative path of the folder; empty for the root.
     * @param string|null $after Only paths after this one are given.
     * @param int $limit The most files to give.
     * @param array[] $items The files so far.
     * @return bool Whether the page is full.
     */
    private function walk(string $relative, ?string $after, int $limit, array &$items): bool {
        $names = $this->names($relative === '' ? $this->root : $this->root . '/' . $relative);
        if ($names === null) {
            $this->unreadable[$relative === '' ? '.' : $relative] = true;
            return false;
        }
        $entries = [];
        foreach ($names as $name) {
            $path = $relative === '' ? $name : $relative . '/' . $name;
            if (isset($this->excluded[$path])) {
                continue;
            }
            if (!self::valid_name($name)) {
                $this->unreadable['hex:' . bin2hex($path)] = true;
                continue;
            }
            if ($after !== null && strcmp($path, $after) <= 0 && !self::may_hold_later_paths($path, $after)) {
                // Not before the page and not a folder that holds it: not worth a call of lstat() in a folder of many names.
                continue;
            }
            $stat = error_silencer::run(fn() => lstat($this->root . '/' . $path), $warning);
            if ($stat === false) {
                $this->unreadable[$path] = true;
                continue;
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0120000) {
                $this->skipped[$path] = true;
            } else if ($type === 0040000) {
                $entries[] = [$name . '/', $path, null];
            } else if ($type === 0100000) {
                $entries[] = [$name, $path, $stat['size']];
            }
        }
        usort($entries, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        foreach ($entries as [, $path, $size]) {
            if ($size === null) {
                if (self::may_hold_later_paths($path, $after) && $this->walk($path, $after, $limit, $items)) {
                    return true;
                }
            } else if ($after === null || strcmp($path, $after) > 0) {
                $items[] = ['path' => $path, 'size' => $size];
                if (count($items) >= $limit) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether a folder can hold a path after a given one.
     *
     * Every path under a folder starts with its path and a slash. Such a path is after $after unless $after is greater and does
     * not start with that prefix too.
     *
     * @param string $folder Relative path of the folder.
     * @param string|null $after The last path of the page before.
     * @return bool
     */
    private static function may_hold_later_paths(string $folder, ?string $after): bool {
        $prefix = $folder . '/';
        return $after === null || strcmp($after, $prefix) < 0 || str_starts_with($after, $prefix);
    }

    /**
     * Whether a name can be in a list in JSON: no control character, and UTF-8.
     *
     * @param string $name The name.
     * @return bool
     */
    private static function valid_name(string $name): bool {
        return preg_match('/[\x00-\x1f\x7f]/', $name) !== 1 && preg_match('//u', $name) === 1;
    }

    /**
     * The names in a folder.
     *
     * @param string $path Path of the folder.
     * @return string[]|null The names without "." and "..", or null when the folder cannot be listed.
     */
    private function names(string $path): ?array {
        $lister = $this->lister;
        $names = $lister !== null ? $lister($path) : error_silencer::run(static fn() => scandir($path), $warning);
        if ($names === false) {
            return null;
        }
        return array_values(array_diff($names, ['.', '..']));
    }
}
