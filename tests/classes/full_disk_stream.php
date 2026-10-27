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
 * A stream wrapper for tests: a folder where a file can be written only up to some bytes, like a disk that fills up.
 *
 * The path is "secbk-full://" and the path of a real folder and a name. Writes fail (false) once the files written through
 * the wrapper have had the number of bytes that limit() gave. Rename and unlink work on the real files.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class full_disk_stream {
    /** @var string Name of the protocol. */
    public const PROTOCOL = 'secbk-full';

    /** @var int Bytes that may still be written. */
    private static int $room = PHP_INT_MAX;

    /** @var resource|null Context that PHP sets. */
    public $context;

    /** @var resource The real file. */
    private $inner;

    /**
     * Registers the protocol, if it is not, with room for the given bytes.
     *
     * @param int $room Bytes that may be written.
     */
    public static function register(int $room): void {
        self::$room = $room;
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /**
     * Unregisters the protocol.
     */
    public static function unregister(): void {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * The real path of a URL.
     *
     * @param string $url The URL.
     * @return string
     */
    private static function real(string $url): string {
        return substr($url, strlen(self::PROTOCOL . '://'));
    }

    /**
     * Opens a real file.
     *
     * @param string $path The URL.
     * @param string $mode The mode.
     * @param int $options Options of PHP.
     * @param string|null $opened Not used.
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
        $inner = fopen(self::real($path), $mode);
        if ($inner === false) {
            return false;
        }
        $this->inner = $inner;
        return true;
    }

    /**
     * Writes bytes while there is room.
     *
     * @param string $data The bytes.
     * @return int|false
     */
    public function stream_write(string $data): int|false {
        if (strlen($data) > self::$room) {
            return false;
        }
        self::$room -= strlen($data);
        return fwrite($this->inner, $data);
    }

    /**
     * Closes the file.
     */
    public function stream_close(): void {
        fclose($this->inner);
    }

    /**
     * Renames a real file.
     *
     * @param string $from The URL.
     * @param string $to The URL.
     * @return bool
     */
    public function rename(string $from, string $to): bool {
        return rename(self::real($from), self::real($to));
    }

    /**
     * Removes a real file.
     *
     * @param string $path The URL.
     * @return bool
     */
    public function unlink(string $path): bool {
        return unlink(self::real($path));
    }
}
