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
 * The list of the chunks that the storage holds (the "present list"), which the backup service keeps for the site.
 *
 * The list tells a run which chunks it need not send again. It can name millions of chunks, so it is not held in memory:
 * load() decompresses it in pieces, checks it, and writes the names and sizes as records of a fixed width (the 32 bytes of
 * the name and the size as 8 bytes) to a file of the temporary folder, in the order of the list. size_of() finds a name in
 * that file by a binary search. A list that is not this site's, or not in its form, is not trusted: the run stops with
 * signature_invalid, as it does for an answer whose signature is wrong.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class present_list {
    /** @var int Most chunks that are read from a list. */
    private const MAX_COUNT = 10000000;

    /** @var int Longest line of the list: a name, a space and a size of at most 16 digits. */
    private const MAX_LINE = 100;

    /** @var int Longest header. */
    private const MAX_HEADER = 1000;

    /** @var int Bytes of the file read and decompressed at a time. */
    private const PIECE = 8192;

    /** @var int Largest whole number that a JSON number holds exactly (2^53 - 1). */
    private const MAX_SIZE = 9007199254740991;

    /** @var int Bytes of a record: the name (32) and the size (8). */
    private const RECORD = 40;

    /** @var int Bytes of records that are kept before they are written. */
    private const WRITE_BUFFER = 65536;

    /** @var string|null The file of the records; null for an empty list. */
    private ?string $path;

    /** @var resource|null The file, open for reading. */
    private $handle;

    /** @var int Number of records. */
    private int $count;

    /**
     * Constructor.
     *
     * @param string|null $path The file of the records.
     * @param resource|null $handle The file, open for reading.
     * @param int $count Number of records.
     */
    private function __construct(?string $path, $handle, int $count) {
        $this->path = $path;
        $this->handle = $handle;
        $this->count = $count;
    }

    /**
     * Reads a list.
     *
     * The file is decompressed in pieces, and a list whose compressed data does not end where it should (a transfer that was
     * cut) is not accepted.
     *
     * @param string $gzippath The file that was fetched. A file that is not there is the list of a first run (the storage had
     *     no list, 404), and gives an empty list.
     * @param string $siteid Site ID at the backup service.
     * @param string $tempdir Folder of the temporary files, where the records are written.
     * @param callable|null $diskspace A seam for tests: function (string $folder): array{0: ?int, 1: ?int}, as
     *     temp_area::disk_space(); null for the real disk.
     * @return self
     * @throws \coding_exception When $siteid is not a UUID.
     * @throws plugin_exception With signature_invalid when the list is not this site's or not in its form, with disk_low when
     *     the folder has no room for the records, and with temp_write_failed when they cannot be written.
     */
    public static function load(string $gzippath, string $siteid, string $tempdir, ?callable $diskspace = null): self {
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $siteid) !== 1) {
            throw new \coding_exception('A site is a lower-case UUID.');
        }
        if (!is_file($gzippath)) {
            return new self(null, null, 0);
        }
        $in = error_silencer::run(static fn() => fopen($gzippath, 'rb'), $warning);
        if ($in === false) {
            throw self::invalid('open');
        }
        $path = null;
        $out = null;
        try {
            return self::read($in, $siteid, rtrim($tempdir, '/'), $diskspace ?? [temp_area::class, 'disk_space'], $path, $out);
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            if ($path !== null) {
                error_silencer::run(static fn() => unlink($path), $warning);
            }
            throw $e;
        } finally {
            fclose($in);
        }
    }

    /**
     * The number of chunks in the list.
     *
     * @return int
     */
    public function count(): int {
        return $this->count;
    }

    /**
     * The size of a chunk.
     *
     * @param string $name Name of the chunk.
     * @return int|null Null when the list does not have it.
     */
    public function size_of(string $name): ?int {
        if ($this->handle === null || preg_match('/^[0-9a-f]{64}\z/', $name) !== 1) {
            return null;
        }
        $wanted = (string) hex2bin($name);
        $low = 0;
        $high = $this->count - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            fseek($this->handle, $middle * self::RECORD);
            $record = (string) fread($this->handle, self::RECORD);
            $order = strcmp(substr($record, 0, 32), $wanted);
            if ($order === 0) {
                return (int) unpack('J', substr($record, 32))[1];
            }
            if ($order < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        return null;
    }

    /**
     * Removes the temporary file. The list is empty afterwards.
     */
    public function close(): void {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        if ($this->path !== null) {
            $path = $this->path;
            error_silencer::run(static fn() => unlink($path), $warning);
            $this->path = null;
        }
        $this->count = 0;
    }

    /**
     * Removes the temporary file when the list goes.
     */
    public function __destruct() {
        $this->close();
    }

    /**
     * Reads an open list.
     *
     * @param resource $in The compressed list.
     * @param string $siteid Site ID.
     * @param string $tempdir Folder of the temporary files.
     * @param callable $diskspace Measure of the disk.
     * @param string|null $path Set to the path of the file of the records as soon as it is made.
     * @param resource|null $out Set to the file of the records, open for writing.
     * @return self
     * @throws plugin_exception With signature_invalid, disk_low or temp_write_failed.
     */
    private static function read($in, string $siteid, string $tempdir, callable $diskspace, ?string &$path, &$out): self {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $buffer = '';
        $count = null;
        $previous = '';
        $seen = 0;
        $records = '';
        $given = 0;
        while (inflate_get_status($context) !== ZLIB_STREAM_END) {
            $raw = fread($in, self::PIECE);
            if ($raw === false || $raw === '') {
                // The file ends before the compressed data does.
                throw self::invalid('cut');
            }
            $given += strlen($raw);
            $text = error_silencer::run(static fn() => inflate_add($context, $raw), $warning);
            if ($text === false) {
                throw self::invalid('inflate');
            }
            $buffer .= $text;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                if ($count === null) {
                    $count = self::header($line, $siteid);
                    self::check_room($tempdir, $count, $diskspace);
                    $out = self::create($tempdir, $path);
                    continue;
                }
                $records .= self::record($line, $previous, $seen, $count);
                if (strlen($records) >= self::WRITE_BUFFER) {
                    self::write($out, $records);
                    $records = '';
                }
            }
            if (strlen($buffer) > ($count === null ? self::MAX_HEADER : self::MAX_LINE)) {
                throw self::invalid('line');
            }
        }
        // Nothing may follow the last line feed or the end of the compressed data.
        $trailing = $given !== inflate_get_read_len($context) || fread($in, 1) !== '';
        if ($buffer !== '' || $trailing || $count === null || $seen !== $count) {
            throw self::invalid('end');
        }
        self::write($out, $records);
        // The flush is checked on its own: fclose() says true even when the flush that it makes fails. The file is closed in
        // either case.
        $flushed = fflush($out);
        $closed = fclose($out);
        $out = null;
        if (!$flushed || !$closed) {
            throw new plugin_exception('temp_write_failed', null, 'present_close');
        }
        $handle = error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
        if ($handle === false) {
            throw new plugin_exception('temp_write_failed', null, 'present_reopen');
        }
        $list = new self($path, $handle, $count);
        $path = null;
        return $list;
    }

    /**
     * Checks the header line.
     *
     * @param string $line The line, without its line feed.
     * @param string $siteid Site ID.
     * @return int The number of chunks that follow.
     * @throws plugin_exception With signature_invalid.
     */
    private static function header(string $line, string $siteid): int {
        $data = strlen($line) > self::MAX_HEADER ? null : json_decode($line, true, 4);
        $keys = is_array($data) ? array_keys($data) : [];
        sort($keys);
        if (
            $keys !== ['count', 'generated_at', 'site_id', 'v']
            || $data['v'] !== 1 || $data['site_id'] !== $siteid
            || !is_string($data['generated_at']) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $data['generated_at']) !== 1
            || !is_int($data['count']) || $data['count'] < 0 || $data['count'] > self::MAX_COUNT
        ) {
            throw self::invalid('header');
        }
        return $data['count'];
    }

    /**
     * Checks that the folder has room for the records.
     *
     * @param string $tempdir The folder.
     * @param int $count Number of records.
     * @param callable $diskspace Measure of the disk.
     * @throws plugin_exception With disk_low. A space that cannot be measured is not a reason to stop.
     */
    private static function check_room(string $tempdir, int $count, callable $diskspace): void {
        $free = $diskspace($tempdir)[0];
        if ($free !== null && $free < $count * self::RECORD) {
            throw new plugin_exception('disk_low');
        }
    }

    /**
     * Makes the file of the records.
     *
     * @param string $tempdir The folder.
     * @param string|null $path Set to its path.
     * @return resource
     * @throws plugin_exception With temp_write_failed.
     */
    private static function create(string $tempdir, ?string &$path) {
        $name = $tempdir . '/present-' . bin2hex(random_bytes(8)) . '.records';
        $out = error_silencer::run(static fn() => fopen($name, 'xb'), $warning);
        if ($out === false) {
            throw new plugin_exception('temp_write_failed', null, 'present_open');
        }
        $path = $name;
        return $out;
    }

    /**
     * Writes records.
     *
     * @param resource $out The file.
     * @param string $records The records.
     * @throws plugin_exception With temp_write_failed.
     */
    private static function write($out, string $records): void {
        $written = $records === '' ? 0 : error_silencer::run(static fn() => fwrite($out, $records), $warning);
        if ($written !== strlen($records)) {
            throw new plugin_exception('temp_write_failed', null, 'present_write');
        }
    }

    /**
     * Checks one line of a chunk and makes its record.
     *
     * @param string $line The line, without its line feed.
     * @param string $previous The name of the line before, which is replaced.
     * @param int $seen Number of lines so far, which is raised.
     * @param int $count The number of lines that the header promised.
     * @return string The record.
     * @throws plugin_exception With signature_invalid.
     */
    private static function record(string $line, string &$previous, int &$seen, int $count): string {
        if (preg_match('/^([0-9a-f]{64}) ([1-9][0-9]{0,15})\z/', $line, $m) !== 1 || (int) $m[2] > self::MAX_SIZE) {
            throw self::invalid('line');
        }
        if (strcmp($m[1], $previous) <= 0 || $seen >= $count) {
            throw self::invalid('order');
        }
        $previous = $m[1];
        $seen++;
        return hex2bin($m[1]) . pack('J', (int) $m[2]);
    }

    /**
     * The failure.
     *
     * @param string $where Short detail without content.
     * @return plugin_exception
     */
    private static function invalid(string $where): plugin_exception {
        return new plugin_exception('signature_invalid', null, 'present_' . $where);
    }
}
