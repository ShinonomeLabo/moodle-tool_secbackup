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
use tool_secbackup\local\plugin_exception;

/**
 * A file of the temporary folder of a run that values are appended to, one JSON value on a line.
 *
 * A run keeps its lists in such files (the contents of the file store as the snapshot has them, the chunks that the entries
 * refer to, the files that are missing or could not be read), so that nothing holds a whole list in memory. The record of the
 * run keeps how many lines are committed; a step that goes on after a stop drops what was written after that with
 * truncate_to(), and a new journal drops a last line that was cut.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class journal {
    /** @var int Bytes read at a time. */
    private const PIECE = 65536;

    /** @var string The file. */
    private string $path;

    /** @var int Complete lines in the file. */
    private int $lines = 0;

    /** @var int Bytes of the complete lines. */
    private int $bytes = 0;

    /** @var resource|null The file, open for appending. */
    private $handle = null;

    /**
     * Constructor.
     *
     * Without figures it counts the lines of the file and drops a last line that was cut. With the figures that a run committed
     * it cuts the file to them at once, without reading it: a list of millions of lines is not read at every step.
     *
     * @param string $path The file; it may not exist yet.
     * @param int|null $lines The committed number of lines, or null to count them.
     * @param int|null $bytes The committed bytes of those lines; given with $lines.
     * @throws plugin_exception With temp_write_failed when a cut line cannot be dropped, or the file is shorter than the figures.
     */
    public function __construct(string $path, ?int $lines = null, ?int $bytes = null) {
        $this->path = $path;
        if ($lines === null || $bytes === null) {
            [$this->lines, $this->bytes, $size] = $this->scan(PHP_INT_MAX);
        } else {
            clearstatcache(true, $path);
            $size = is_file($path) ? (int) filesize($path) : 0;
            if ($size < $bytes) {
                throw new plugin_exception('temp_write_failed', null, 'journal_short');
            }
            $this->lines = $lines;
            $this->bytes = $bytes;
        }
        if ($size > $this->bytes) {
            self::cut($path, $this->bytes) || throw new plugin_exception('temp_write_failed', null, 'journal_truncate');
        }
    }

    /**
     * The number of complete lines.
     *
     * @return int
     */
    public function lines(): int {
        return $this->lines;
    }

    /**
     * The bytes of the complete lines.
     *
     * @return int
     */
    public function bytes(): int {
        return $this->bytes;
    }

    /**
     * Adds a value as a line.
     *
     * @param mixed $value A value that has a JSON form.
     * @throws \coding_exception When the value has no JSON form.
     * @throws plugin_exception With temp_write_failed when the file cannot be written.
     */
    public function append(mixed $value): void {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \coding_exception('A value of a journal has a JSON form.');
        }
        $line = $json . "\n";
        $handle = $this->open();
        $written = error_silencer::run(static fn() => fwrite($handle, $line), $warning);
        // Part of the line may be in the file when this fails: the next truncate_to() or a new journal takes it out.
        $written === strlen($line) || throw new plugin_exception('temp_write_failed', null, 'journal_write');
        $this->lines++;
        $this->bytes += strlen($line);
    }

    /**
     * Makes what was added so far safe on the disk.
     *
     * @return int The number of complete lines.
     * @throws plugin_exception With temp_write_failed when the file cannot be written.
     */
    public function commit(): int {
        if ($this->handle !== null) {
            $handle = $this->handle;
            error_silencer::run(static fn() => fflush($handle), $warning)
                || throw new plugin_exception('temp_write_failed', null, 'journal_flush');
            if (function_exists('fsync')) {
                error_silencer::run(static fn() => fsync($handle), $warning);
            }
        }
        return $this->lines;
    }

    /**
     * Keeps the first lines of the file and drops everything after them.
     *
     * @param int $committed The number of lines that the run has committed.
     * @throws plugin_exception With temp_write_failed when the file holds fewer complete lines than that.
     */
    public function truncate_to(int $committed): void {
        $this->close();
        if (!is_file($this->path)) {
            if ($committed !== 0) {
                throw new plugin_exception('temp_write_failed', null, 'journal_missing');
            }
            $this->lines = 0;
            $this->bytes = 0;
            return;
        }
        [$lines, $bytes] = $this->scan($committed);
        if ($lines < $committed) {
            throw new plugin_exception('temp_write_failed', null, 'journal_short');
        }
        self::cut($this->path, $bytes) || throw new plugin_exception('temp_write_failed', null, 'journal_truncate');
        $this->lines = $committed;
        $this->bytes = $bytes;
    }

    /**
     * The values from a byte offset on, each with the offset just after its line.
     *
     * What was appended is flushed first. Only complete lines are read.
     *
     * @param int $offset The offset of the first line to read: 0, or an offset that this method gave.
     * @return \Generator<int, mixed> Value by the offset after its line.
     * @throws plugin_exception With temp_write_failed when the file cannot be read or a line is not JSON.
     */
    public function read(int $offset): \Generator {
        $this->commit();
        if (!is_file($this->path)) {
            return;
        }
        $path = $this->path;
        $handle = error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
        $handle !== false && fseek($handle, $offset) === 0 || throw new plugin_exception('temp_write_failed', null, 'journal_read');
        try {
            $rest = '';
            $position = $offset;
            while (($piece = fread($handle, self::PIECE)) !== false && $piece !== '') {
                $rest .= $piece;
                while (($end = strpos($rest, "\n")) !== false) {
                    $line = substr($rest, 0, $end);
                    $rest = substr($rest, $end + 1);
                    $position += $end + 1;
                    $value = json_decode($line, true, 64);
                    if ($value === null && $line !== 'null') {
                        throw new plugin_exception('temp_write_failed', null, 'journal_line');
                    }
                    yield $position => $value;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every value, in order.
     *
     * @return \Generator<int, mixed>
     * @throws plugin_exception With temp_write_failed when the file cannot be read or a line is not JSON.
     */
    public function values(): \Generator {
        yield from $this->read(0);
    }

    /**
     * Closes the file.
     */
    public function close(): void {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Closes the file when the journal goes.
     */
    public function __destruct() {
        $this->close();
    }

    /**
     * Opens the file for appending.
     *
     * @return resource
     * @throws plugin_exception With temp_write_failed.
     */
    private function open() {
        if ($this->handle === null) {
            $path = $this->path;
            $handle = is_dir($path) ? false : error_silencer::run(static fn() => fopen($path, 'ab'), $warning);
            if ($handle === false) {
                throw new plugin_exception('temp_write_failed', null, 'journal_open');
            }
            $this->handle = $handle;
        }
        return $this->handle;
    }

    /**
     * Counts complete lines from the start of the file, up to a number.
     *
     * @param int $most The most lines to count.
     * @return int[] [lines counted, bytes of those lines, size of the file]
     */
    private function scan(int $most): array {
        $path = $this->path;
        $handle = is_file($path) ? error_silencer::run(static fn() => fopen($path, 'rb'), $warning) : false;
        if ($handle === false) {
            return [0, 0, 0];
        }
        $lines = 0;
        $bytes = 0;
        $position = 0;
        while ($lines < $most && ($piece = fread($handle, self::PIECE)) !== false && $piece !== '') {
            $offset = -1;
            while ($lines < $most && ($offset = strpos($piece, "\n", $offset + 1)) !== false) {
                $lines++;
                $bytes = $position + $offset + 1;
            }
            $position += strlen($piece);
        }
        fclose($handle);
        clearstatcache(true, $path);
        return [$lines, $bytes, (int) filesize($path)];
    }

    /**
     * Cuts a file to a length.
     *
     * @param string $path The file.
     * @param int $length The length.
     * @return bool Whether it worked.
     */
    private static function cut(string $path, int $length): bool {
        return (bool) error_silencer::run(static function () use ($path, $length): bool {
            $handle = fopen($path, 'r+b');
            $done = $handle !== false && ftruncate($handle, $length);
            $handle === false || fclose($handle);
            return $done;
        }, $warning);
    }
}
