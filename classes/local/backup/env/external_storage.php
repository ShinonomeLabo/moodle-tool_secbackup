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

namespace tool_secbackup\local\backup\env;

use tool_secbackup\local\diagnostics\runner;

/**
 * Tells whether the file store of the site is on an external store.
 *
 * It is when the site has an alternative file system class, or when the data directory is on a file system of a FUSE mount
 * of a bucket (s3fs or mountpoint-s3). Reading such a store is slower and costs requests, which the backup takes into
 * account.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_storage {
    /** @var string[] Types of file system of a mounted bucket. */
    private const BUCKET_TYPES = ['fuse.s3fs', 'fuse.mountpoint-s3'];

    /** @var \Closure|null Gives the text of /proc/self/mountinfo, or null; null here means the file itself is read. */
    private ?\Closure $mountinfo;

    /**
     * Constructor.
     *
     * @param \Closure|null $mountinfo Gives the text of /proc/self/mountinfo, or null when it cannot be read; the file
     *     itself is read by default.
     */
    public function __construct(?\Closure $mountinfo = null) {
        $this->mountinfo = $mountinfo;
    }

    /**
     * Whether the file store is on an external store.
     *
     * @return bool
     */
    public function detected(): bool {
        global $CFG;
        $class = $CFG->alternative_file_system_class ?? '';
        if (is_string($class) && $class !== '') {
            return true;
        }
        $text = $this->mountinfo === null ? self::read_mountinfo() : ($this->mountinfo)();
        if ($text === null) {
            return false;
        }
        $mount = runner::mount_of(realpath($CFG->dataroot) ?: $CFG->dataroot, $text);
        return $mount !== null && in_array($mount['type'], self::BUCKET_TYPES, true);
    }

    /**
     * The text of /proc/self/mountinfo.
     *
     * @return string|null Null when it cannot be read (another system than Linux, or no permission); no warning is raised.
     */
    private static function read_mountinfo(): ?string {
        set_error_handler(static fn(): bool => true);
        try {
            $text = file_get_contents('/proc/self/mountinfo');
        } finally {
            restore_error_handler();
        }
        return $text === false ? null : $text;
    }
}
