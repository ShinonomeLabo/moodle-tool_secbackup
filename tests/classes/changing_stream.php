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
 * A stream wrapper that reads a real file and, from a chosen moment on, gives bytes that are not the file's.
 *
 * It plays a file that is changed while a restore reads it. The bytes are changed (each one is inverted) either from the
 * Nth opening of the file, or, in a stream that is open, from the Nth time that a seek reaches the wrapper (a seek inside the
 * buffer that PHP holds does not reach it).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class changing_stream {
    /** @var string Protocol of the wrapper. */
    public const PROTOCOL = 'secbk-changing';

    /** @var int Openings so far. */
    private static int $opens = 0;

    /** @var int The opening from which the bytes are changed, or 0 for none. */
    private static int $fromopen = 0;

    /** @var int The seek from which the bytes are changed, or 0 for none. */
    private static int $fromseek = 0;

    /** @var resource|null The context, set by PHP. */
    public $context;

    /** @var resource The real file. */
    private $inner;

    /** @var bool Whether the bytes are changed. */
    private bool $changed = false;

    /** @var int Seeks that reached this stream. */
    private int $seeks = 0;

    /**
     * Registers the wrapper.
     *
     * @param int $fromopen The opening from which the bytes are changed, or 0.
     * @param int $fromseek The seek from which the bytes are changed, or 0.
     */
    public static function register(int $fromopen, int $fromseek): void {
        self::$opens = 0;
        self::$fromopen = $fromopen;
        self::$fromseek = $fromseek;
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /**
     * Removes the wrapper.
     */
    public static function unregister(): void {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * The URL of a real file through the wrapper.
     *
     * @param string $path The path.
     * @return string
     */
    public static function url(string $path): string {
        return self::PROTOCOL . '://' . $path;
    }

    /**
     * Opens the file.
     *
     * @param string $path URL.
     * @param string $mode Mode.
     * @param int $options Options.
     * @param string|null $opened Not used.
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
        $inner = fopen(substr($path, strlen(self::PROTOCOL . '://')), 'rb');
        if ($inner === false) {
            return false;
        }
        $this->inner = $inner;
        self::$opens++;
        $this->changed = self::$fromopen > 0 && self::$opens >= self::$fromopen;
        return true;
    }

    /**
     * Reads bytes.
     *
     * @param int $count Bytes.
     * @return string
     */
    public function stream_read(int $count): string {
        $bytes = (string) fread($this->inner, $count);
        return $this->changed ? ~$bytes : $bytes;
    }

    /**
     * Seeks.
     *
     * @param int $offset Offset.
     * @param int $whence Whence.
     * @return bool
     */
    public function stream_seek(int $offset, int $whence): bool {
        if (self::$fromseek > 0 && ++$this->seeks >= self::$fromseek) {
            $this->changed = true;
        }
        return fseek($this->inner, $offset, $whence) === 0;
    }

    /**
     * Position.
     *
     * @return int
     */
    public function stream_tell(): int {
        return (int) ftell($this->inner);
    }

    /**
     * End of the file.
     *
     * @return bool
     */
    public function stream_eof(): bool {
        return feof($this->inner);
    }

    /**
     * Status of the open file.
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

    /**
     * Status of a file by its URL.
     *
     * @param string $path URL.
     * @param int $flags Flags.
     * @return array|false
     */
    public function url_stat(string $path, int $flags): array|false {
        $real = substr($path, strlen(self::PROTOCOL . '://'));
        return file_exists($real) ? stat($real) : false;
    }
}
