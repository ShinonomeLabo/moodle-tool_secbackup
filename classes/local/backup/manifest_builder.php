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

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\remade_exception;

/**
 * The list of generations (the manifest): a header, one line for each item that a run stored, and a last line that counts
 * them.
 *
 * The items are appended to a file of the temporary folder as the run goes on, so nothing holds the whole list in memory,
 * not even for millions of files. commit() says how many lines are complete; a run that stopped in the middle goes on with
 * truncate_to(), which drops what was written after the last commit and a line that was cut. build() compresses the lines in
 * pieces and encrypts the result as one item of a chunk of type 4.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manifest_builder {
    /** @var string Name of the file of the lines, in the temporary folder. */
    public const FILE = 'entries.jsonl';

    /** @var int Bytes read and compressed at a time. */
    private const PIECE = 65536;

    /** @var string Folder of the run. */
    private string $tempdir;

    /** @var int Complete lines in the file. */
    private int $lines = 0;

    /** @var int Bytes of the complete lines. */
    private int $bytes = 0;

    /** @var resource|null The file, open for appending. */
    private $handle = null;

    /**
     * Constructor: counts the lines that a run left in the folder, and drops a last line that was cut.
     *
     * @param string $tempdir Temporary folder of the run.
     * @throws plugin_exception With temp_write_failed when a cut line cannot be dropped.
     */
    public function __construct(string $tempdir) {
        $this->tempdir = rtrim($tempdir, '/');
        $this->scan();
    }

    /**
     * Adds a line.
     *
     * @param array $entry The item: an object (not a list) that has a JSON form.
     * @throws \coding_exception When the item is not an object of JSON.
     * @throws plugin_exception With temp_write_failed when the file cannot be written.
     */
    public function append(array $entry): void {
        $line = self::encode($entry) . "\n";
        $handle = $this->open();
        $written = error_silencer::run(static fn() => fwrite($handle, $line), $warning);
        if ($written !== strlen($line)) {
            // Part of the line may be in the file: the next truncate_to() or a new builder takes it out.
            throw new plugin_exception('temp_write_failed', null, 'entries_write');
        }
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
            $flushed = error_silencer::run(static fn() => fflush($handle), $warning);
            if (!$flushed) {
                throw new plugin_exception('temp_write_failed', null, 'entries_flush');
            }
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
        $path = $this->path();
        if (!is_file($path)) {
            if ($committed !== 0) {
                throw new plugin_exception('temp_write_failed', null, 'entries_missing');
            }
            return;
        }
        $offset = $this->offset_after($committed);
        if ($offset === null) {
            throw new plugin_exception('temp_write_failed', null, 'entries_short');
        }
        $done = error_silencer::run(static fn() => self::cut($path, $offset), $warning);
        if (!$done) {
            throw new plugin_exception('temp_write_failed', null, 'entries_truncate');
        }
        $this->lines = $committed;
        $this->bytes = $offset;
    }

    /**
     * Writes the list as a chunk.
     *
     * @param array $header The first line: an object.
     * @param #[|\SensitiveParameter|]|string $dedupkey The key for names (32 bytes).
     * @param string $runuuid UUID of the run.
     * @param string $sitepublickey The X25519 public key of the site (32 bytes).
     * @param string $siteid Site ID at the backup service.
     * @return chunk_info The chunk, in the folder of the run.
     * @throws \coding_exception When the header is not an object of JSON.
     * @throws plugin_exception With temp_write_failed when a file cannot be written.
     */
    public function build(
        array $header,
        #[\SensitiveParameter] string $dedupkey,
        string $runuuid,
        string $sitepublickey,
        string $siteid
    ): chunk_info {
        // The key is a secret: it moves to a variable and the parameter is unset before anything can throw, as the trace of an
        // exception shows the parameters (and hides them only from PHP 8.2 on).
        $key = $dedupkey;
        unset($dedupkey);
        $name = chunk_name::manifest($key, $runuuid);
        unset($key);
        $headerline = self::encode($header) . "\n";
        $this->commit();
        $gzip = $this->tempdir . '/manifest.jsonl.gz';
        try {
            $this->compress($gzip, $headerline);
            $size = (int) filesize($gzip);
            $handle = error_silencer::run(static fn() => fopen($gzip, 'rb'), $warning);
            if ($handle === false) {
                throw new plugin_exception('temp_write_failed', null, 'manifest_read');
            }
            try {
                $writer = new chunk_writer(
                    $this->tempdir . '/manifest.chunk',
                    chunk_writer::TYPE_MANIFEST,
                    $name,
                    $sitepublickey,
                    $siteid
                );
                $writer->add_item($handle, $size);
                return $writer->finish();
            } catch (\Throwable $e) {
                throw remade_exception::build(remade_exception::parts($e));
            } finally {
                fclose($handle);
            }
        } finally {
            if (is_file($gzip)) {
                unlink($gzip);
            }
        }
    }

    /**
     * Compresses the header, the lines and the last line into a gzip file.
     *
     * @param string $gzip Path of the file.
     * @param string $headerline The first line.
     * @throws plugin_exception With temp_write_failed when a file cannot be read or written.
     */
    private function compress(string $gzip, string $headerline): void {
        $out = error_silencer::run(static fn() => fopen($gzip, 'wb'), $warning);
        $in = is_file($this->path()) ? error_silencer::run(fn() => fopen($this->path(), 'rb'), $warning) : null;
        if ($out === false || $in === false) {
            throw new plugin_exception('temp_write_failed', null, 'manifest_open');
        }
        try {
            $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
            $this->put($out, deflate_add($context, $headerline, ZLIB_NO_FLUSH));
            $left = $this->bytes;
            while ($in !== null && $left > 0) {
                $piece = fread($in, min(self::PIECE, $left));
                if ($piece === false || $piece === '') {
                    throw new plugin_exception('temp_write_failed', null, 'manifest_short');
                }
                $left -= strlen($piece);
                $this->put($out, deflate_add($context, $piece, ZLIB_NO_FLUSH));
            }
            $end = json_encode(['end' => true, 'entries' => $this->lines]) . "\n";
            $this->put($out, deflate_add($context, $end, ZLIB_FINISH));
        } finally {
            fclose($out);
            if ($in !== null) {
                fclose($in);
            }
        }
    }

    /**
     * Writes a piece to the compressed file.
     *
     * @param resource $out The file.
     * @param string|false $piece The piece.
     * @throws plugin_exception With temp_write_failed when it cannot be written.
     */
    private function put($out, string|false $piece): void {
        $written = $piece === '' ? 0 : error_silencer::run(static fn() => fwrite($out, (string) $piece), $warning);
        if ($piece === false || $written !== strlen($piece)) {
            throw new plugin_exception('temp_write_failed', null, 'manifest_write');
        }
    }

    /**
     * The path of the file of the lines.
     *
     * @return string
     */
    private function path(): string {
        return $this->tempdir . '/' . self::FILE;
    }

    /**
     * Opens the file for appending.
     *
     * @return resource
     * @throws plugin_exception With temp_write_failed.
     */
    private function open() {
        if ($this->handle === null) {
            $path = $this->path();
            $handle = error_silencer::run(static fn() => fopen($path, 'ab'), $warning);
            if ($handle === false) {
                throw new plugin_exception('temp_write_failed', null, 'entries_open');
            }
            $this->handle = $handle;
        }
        return $this->handle;
    }

    /**
     * Closes the file.
     */
    private function close(): void {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Counts the complete lines of the file, and their bytes.
     */
    private function scan(): void {
        $this->lines = 0;
        $this->bytes = 0;
        $path = $this->path();
        $handle = is_file($path) ? error_silencer::run(static fn() => fopen($path, 'rb'), $warning) : false;
        if ($handle === false) {
            return;
        }
        $position = 0;
        while (($piece = fread($handle, self::PIECE)) !== false && $piece !== '') {
            $count = substr_count($piece, "\n");
            if ($count > 0) {
                $this->lines += $count;
                $this->bytes = $position + strrpos($piece, "\n") + 1;
            }
            $position += strlen($piece);
        }
        fclose($handle);
        if ($position > $this->bytes) {
            // A line that was cut is not part of the list.
            $bytes = $this->bytes;
            if (!error_silencer::run(static fn() => self::cut($path, $bytes), $warning)) {
                throw new plugin_exception('temp_write_failed', null, 'entries_truncate');
            }
        }
    }

    /**
     * The byte offset just after a number of lines.
     *
     * @param int $lines Number of lines.
     * @return int|null Null when the file holds fewer complete lines.
     */
    private function offset_after(int $lines): ?int {
        if ($lines === 0) {
            return 0;
        }
        $path = $this->path();
        $handle = error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
        if ($handle === false) {
            return null;
        }
        $position = 0;
        $seen = 0;
        try {
            while (($piece = fread($handle, self::PIECE)) !== false && $piece !== '') {
                $offset = -1;
                while (($offset = strpos($piece, "\n", $offset + 1)) !== false) {
                    if (++$seen === $lines) {
                        return $position + $offset + 1;
                    }
                }
                $position += strlen($piece);
            }
        } finally {
            fclose($handle);
        }
        return null;
    }

    /**
     * Cuts a file to a length.
     *
     * @param string $path The file.
     * @param int $length The length.
     * @return bool Whether it worked.
     */
    private static function cut(string $path, int $length): bool {
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            return false;
        }
        $done = ftruncate($handle, $length);
        fclose($handle);
        return $done;
    }

    /**
     * The JSON of an object, in one line.
     *
     * @param array $value The object.
     * @return string
     * @throws \coding_exception When it is a list or has no JSON form.
     */
    private static function encode(array $value): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json = $value === [] || array_is_list($value) ? false : json_encode($value, $flags);
        if ($json === false) {
            throw new \coding_exception('A line of the list is a JSON object.');
        }
        return $json;
    }
}
