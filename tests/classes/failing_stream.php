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
 * A stream wrapper for tests: reads a local file, but a read can fail the way a read on a stale network share does.
 *
 * The path is "secbk-failing://" and the path of a local file. While fail() has counted some streams, the next streams
 * that are opened fail: after the given number of bytes a read raises the notice that PHP raises for a failed read of a
 * plain file and gives false.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class failing_stream {
    /** @var string Name of the protocol. */
    public const PROTOCOL = 'secbk-failing';

    /** @var int How many of the next streams fail. */
    private static int $failing = 0;

    /** @var int Bytes that a failing stream gives before its read fails. */
    private static int $after = 0;

    /** @var resource|null Context that PHP sets. */
    public $context;

    /** @var resource The local file. */
    private $inner;

    /** @var bool Whether this stream fails. */
    private bool $fails = false;

    /** @var int Bytes given so far. */
    private int $given = 0;

    /**
     * Registers the protocol, if it is not.
     */
    public static function register(): void {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /**
     * Unregisters the protocol and forgets the failures.
     */
    public static function unregister(): void {
        self::$failing = 0;
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * Makes the next streams fail.
     *
     * @param int $streams How many of the next streams fail.
     * @param int $after Bytes that each gives before its read fails.
     */
    public static function fail(int $streams, int $after = 0): void {
        self::$failing = $streams;
        self::$after = $after;
    }

    /**
     * Opens a stream on a local file.
     *
     * @param string $path The URL.
     * @param string $mode The mode.
     * @param int $options Options of PHP.
     * @param string|null $opened Not used.
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
        $inner = fopen(substr($path, strlen(self::PROTOCOL . '://')), 'rb');
        if ($inner === false) {
            return false;
        }
        $this->inner = $inner;
        if (self::$failing > 0) {
            self::$failing--;
            $this->fails = true;
        }
        return true;
    }

    /**
     * Reads bytes.
     *
     * @param int $count How many.
     * @return string|false
     */
    public function stream_read(int $count): string|false {
        if ($this->fails) {
            if ($this->given >= self::$after) {
                trigger_error('read of ' . $count . ' bytes failed with errno=116 Stale file handle', E_USER_NOTICE);
                return false;
            }
            $count = min($count, self::$after - $this->given);
        }
        $data = fread($this->inner, $count);
        $this->given += strlen((string) $data);
        return $data;
    }

    /**
     * Whether the file has ended.
     *
     * @return bool
     */
    public function stream_eof(): bool {
        return !$this->fails && feof($this->inner);
    }

    /**
     * Gives the status of the file.
     *
     * @return array|false
     */
    public function stream_stat(): array|false {
        return fstat($this->inner);
    }

    /**
     * Closes the file.
     */
    public function stream_close(): void {
        fclose($this->inner);
    }
}
