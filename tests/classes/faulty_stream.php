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
 * A stream wrapper for tests: works on the files of a real folder, and fails the operations that a test names, as a disk that
 * fills up or a file system that breaks would.
 *
 * The path is "secbk-faulty://" and the path of a real file. fail() names an operation and the end of the paths that it
 * applies to; the next calls of that operation on such a path fail, as many times as it says, and every other call works on
 * the real file.
 *
 * The operations: "open:<mode>" (fopen() with that mode gives false), "write" (fwrite() gives false), "flush" (fflush() gives
 * false), "truncate" (ftruncate() gives false) and "stat" (fstat() of an open file says that it is a folder).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class faulty_stream {
    /** @var string Name of the protocol. */
    public const PROTOCOL = 'secbk-faulty';

    /** @var array[] The faults that wait: each [operation, end of the path, times left]. */
    private static array $faults = [];

    /** @var resource|null Context that PHP sets. */
    public $context;

    /** @var resource The real file. */
    private $inner;

    /** @var string The real path of the open file. */
    private string $path = '';

    /**
     * Registers the protocol, if it is not, without faults.
     */
    public static function register(): void {
        self::$faults = [];
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /**
     * Unregisters the protocol and forgets the faults.
     */
    public static function unregister(): void {
        self::$faults = [];
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * The URL of a real path.
     *
     * @param string $path The real path.
     * @return string
     */
    public static function url(string $path): string {
        return self::PROTOCOL . '://' . $path;
    }

    /**
     * Makes an operation fail on the paths that end with a text.
     *
     * @param string $operation open:<mode>, write, flush, truncate or stat.
     * @param string $suffix The end of the paths.
     * @param int $times How many of the next calls fail.
     */
    public static function fail(string $operation, string $suffix, int $times = 1): void {
        self::$faults[] = [$operation, $suffix, $times];
    }

    /**
     * Whether every fault that a test named has been used.
     *
     * @return bool
     */
    public static function used(): bool {
        return self::$faults === [];
    }

    /**
     * Whether a call fails; a fault that applies is used up once.
     *
     * @param string $operation The operation.
     * @param string $path The real path.
     * @return bool
     */
    private static function faulty(string $operation, string $path): bool {
        foreach (self::$faults as $i => [$what, $suffix, $times]) {
            if ($what === $operation && str_ends_with($path, $suffix)) {
                if ($times <= 1) {
                    unset(self::$faults[$i]);
                } else {
                    self::$faults[$i][2]--;
                }
                return true;
            }
        }
        return false;
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
     * Opens the real file, unless the opening fails.
     *
     * @param string $path The URL.
     * @param string $mode The mode.
     * @param int $options Options of PHP.
     * @param string|null $opened Not used.
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
        $this->path = self::real($path);
        if (self::faulty('open:' . $mode, $this->path)) {
            return false;
        }
        $real = $this->path;
        $inner = \tool_secbackup\local\error_silencer::run(static fn() => fopen($real, $mode), $warning);
        if ($inner === false) {
            return false;
        }
        $this->inner = $inner;
        return true;
    }

    /**
     * Reads bytes.
     *
     * @param int $count How many.
     * @return string|false
     */
    public function stream_read(int $count): string|false {
        return fread($this->inner, $count);
    }

    /**
     * Writes bytes, unless the writing fails.
     *
     * @param string $data The bytes.
     * @return int|false
     */
    public function stream_write(string $data): int|false {
        return self::faulty('write', $this->path) ? false : fwrite($this->inner, $data);
    }

    /**
     * Flushes, unless the flushing fails.
     *
     * @return bool
     */
    public function stream_flush(): bool {
        return !self::faulty('flush', $this->path) && fflush($this->inner);
    }

    /**
     * Cuts the file, unless the cutting fails.
     *
     * @param int $size The new size.
     * @return bool
     */
    public function stream_truncate(int $size): bool {
        return !self::faulty('truncate', $this->path) && ftruncate($this->inner, $size);
    }

    /**
     * Moves in the file.
     *
     * @param int $offset The offset.
     * @param int $whence How.
     * @return bool
     */
    public function stream_seek(int $offset, int $whence): bool {
        return fseek($this->inner, $offset, $whence) === 0;
    }

    /**
     * The position in the file.
     *
     * @return int
     */
    public function stream_tell(): int {
        return (int) ftell($this->inner);
    }

    /**
     * Whether the end was read.
     *
     * @return bool
     */
    public function stream_eof(): bool {
        return feof($this->inner);
    }

    /**
     * The status of the open file; a folder when the stat fails.
     *
     * @return array|false
     */
    public function stream_stat(): array|false {
        $stat = fstat($this->inner);
        if ($stat !== false && self::faulty('stat', $this->path)) {
            $stat['mode'] = $stat[2] = 040755;
        }
        return $stat;
    }

    /**
     * Closes the real file.
     */
    public function stream_close(): void {
        fclose($this->inner);
    }

    /**
     * The status of a path, of the link itself when PHP asks for it.
     *
     * @param string $path The URL.
     * @param int $flags Flags of PHP.
     * @return array|false
     */
    public function url_stat(string $path, int $flags): array|false {
        $real = self::real($path);
        if (!file_exists($real) && !is_link($real)) {
            return false;
        }
        return ($flags & STREAM_URL_STAT_LINK) ? lstat($real) : stat($real);
    }

    /**
     * Removes a file.
     *
     * @param string $path The URL.
     * @return bool
     */
    public function unlink(string $path): bool {
        return unlink(self::real($path));
    }

    /**
     * Renames a file.
     *
     * @param string $from The URL of the file.
     * @param string $to The new URL.
     * @return bool
     */
    public function rename(string $from, string $to): bool {
        return rename(self::real($from), self::real($to));
    }
}
