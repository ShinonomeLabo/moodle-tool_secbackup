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

namespace tool_secbackup\tests;

/**
 * A write filter for tests: lets some writes through, then every write to the stream fails.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fail_after_filter extends \php_user_filter {
    /** @var string Name of the filter. */
    public const NAME = 'secbk.fail_after';

    /** @var int How many more writes go through. */
    public static int $allowed = 0;

    /**
     * Puts the filter on a stream.
     *
     * @param resource $stream The stream.
     * @param int $allowed How many writes go through before the writes fail.
     */
    public static function attach($stream, int $allowed): void {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
        self::$allowed = $allowed;
        stream_filter_append($stream, self::NAME, STREAM_FILTER_WRITE);
    }

    /**
     * Passes a write on, or fails it.
     *
     * @param resource $in Buckets of the write.
     * @param resource $out Buckets that go on.
     * @param int $consumed Bytes taken.
     * @param bool $closing Whether the stream is closing.
     * @return int PSFS_PASS_ON or PSFS_ERR_FATAL.
     */
    public function filter($in, $out, &$consumed, bool $closing): int {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (self::$allowed-- <= 0) {
                return PSFS_ERR_FATAL;
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
