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

namespace tool_secbackup\local\diagnostics;

/**
 * Reads files of /proc and /sys, which open_basedir, SELinux or a container may hide.
 *
 * Nothing is checked before reading: the warnings of the one read are dropped instead, so that an
 * unreadable file is reported as unknown and never as a PHP warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class system_files {
    /** @var string Prefix of every path; empty for the real files. */
    private string $root;

    /**
     * Constructor.
     *
     * @param string $root Prefix of every path; empty for the real files.
     */
    public function __construct(string $root = '') {
        $this->root = $root;
    }

    /**
     * Reads a file.
     *
     * @param string $path Absolute path, such as /proc/self/mountinfo.
     * @return string|null The content, or null when it cannot be read.
     */
    public function read(string $path): ?string {
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            $content = is_file($this->root . $path) ? file_get_contents($this->root . $path) : false;
        } finally {
            restore_error_handler();
        }
        return $content === false ? null : $content;
    }
}
