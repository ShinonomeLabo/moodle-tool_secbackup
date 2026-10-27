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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\crypto\artifact_signature;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Reads the list of generations (the manifest) of a run after it has checked that the backup service's site signed it.
 *
 * The constructor trusts nothing: the size and the SHA-256 of the chunk file must be the signed ones, the signature must be
 * valid for the public key of the site's plugin, the chunk must open with the keys of the site and be of type 4. Only then is
 * its one item, the compressed list, decrypted into a working file of the temporary folder. The list is read from that file a
 * line at a time, so a list of millions of entries does not sit in memory.
 *
 * The header is checked by header(). entries() checks every entry and the last line, which counts the entries; it gives an
 * entry as soon as the entry is in its form, so a caller must read it to the end before it trusts any of them: a list whose
 * last line is missing or counts wrongly makes entries() throw restore_manifest_invalid at the end.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manifest_reader {
    /** @var string Folder of the working files, in the temporary folder of Moodle. */
    private const FOLDER = 'tool_secbackup_restore';

    /** @var int Bytes of the working file that are decompressed at a time. */
    private const PIECE = 65536;

    /** @var int The longest line of the list that is accepted, in bytes. */
    private const MAX_LINE = 134217728;

    /** @var string[] The parts that every header has, which are lists or objects. */
    private const HEADER_PARTS = ['moodle', 'db', 'plugins', 'config', 'core_diff', 'missing_files'];

    /**
     * @var array[] The fields of each kind of entry: the names of those that are text and those that are whole numbers.
     */
    private const FIELDS = [
        'db' => [
            ['table', 'plainsha256', 'chunk'],
            ['from', 'to', 'rows', 'plainlength', 'offset', 'length'],
        ],
        'file' => [
            ['contenthash', 'chunk'],
            ['size', 'offset', 'length', 'piece', 'pieces'],
        ],
        'dataroot' => [
            ['root', 'path', 'sha256', 'chunk'],
            ['size', 'offset', 'length', 'piece', 'pieces'],
        ],
        'code' => [
            ['root', 'path', 'sha256', 'chunk'],
            ['size', 'offset', 'length', 'piece', 'pieces'],
        ],
    ];

    /** @var string Site ID. */
    private string $siteid;

    /** @var string Run ID. */
    private string $runid;

    /** @var string|null Path of the working file, or null once it is removed. */
    private ?string $workpath = null;

    /** @var array|null The header, once it has been read and checked. */
    private ?array $header = null;

    /**
     * Constructor.
     *
     * @param string $path Path of the chunk of the list.
     * @param site_keys $keys The keys of the site.
     * @param string $siteid Site ID at the backup service.
     * @param string $runid Run ID.
     * @param int $size Size of the chunk file that the backup service recorded.
     * @param string $sha256base64 SHA-256 of the chunk file, in standard base64, as it was signed.
     * @param string $signaturebase64url Signature of the list, in base64url without padding, as the service stored it.
     * @param string $signingpublickeybase64 Ed25519 public key of the site's plugin, in standard base64.
     * @throws plugin_exception With restore_chunk_missing when the file is not there, with restore_chunk_corrupt when the
     *     keys do not open it, with restore_manifest_invalid for any other check that fails, and with restore_write_failed
     *     when the working file cannot be written.
     */
    public function __construct(
        string $path,
        site_keys $keys,
        string $siteid,
        string $runid,
        int $size,
        string $sha256base64,
        string $signaturebase64url,
        string $signingpublickeybase64
    ) {
        $this->siteid = $siteid;
        $this->runid = $runid;
        if (!is_file($path)) {
            throw new plugin_exception('restore_chunk_missing');
        }
        clearstatcache(true, $path);
        $digest = hash_file('sha256', $path, true);
        if (filesize($path) !== $size || $digest === false || !hash_equals(base64_encode($digest), $sha256base64)) {
            throw self::invalid('digest');
        }
        $publickey = base64_decode($signingpublickeybase64, true);
        if (
            $publickey === false || base64_encode($publickey) !== $signingpublickeybase64
            || !artifact_signature::verify_manifest($publickey, $siteid, $runid, $sha256base64, $signaturebase64url)
        ) {
            throw self::invalid('signature');
        }
        $reader = $keys->reader($path, $siteid, chunk_name::manifest($runid));
        if ($reader->type() !== chunk_writer::TYPE_MANIFEST) {
            throw self::invalid('type');
        }
        try {
            $payload = $reader->read_item(chunk_writer::HEADER);
        } catch (plugin_exception $e) {
            unset($e);
            throw new plugin_exception('restore_chunk_corrupt');
        }
        $this->keep($payload);
    }

    /**
     * The first line of the list.
     *
     * @return array The header: an object whose `v` is 1, with the site and the run of the restore.
     * @throws plugin_exception With restore_manifest_invalid when the list is not readable or the header is not in its form.
     */
    public function header(): array {
        if ($this->header !== null) {
            return $this->header;
        }
        foreach ($this->lines() as $line) {
            $header = self::object($line);
            if (!$this->header_ok($header)) {
                throw self::invalid('header');
            }
            return $this->header = $header;
        }
        throw self::invalid('empty');
    }

    /**
     * The entries of the list, one at a time.
     *
     * @return \Generator Arrays: the entries as they were written, each of kind db, file, dataroot or code.
     * @throws plugin_exception With restore_manifest_invalid when the list is not readable, an entry is not in its form, or
     *     the last line is missing, is followed by something, or does not count the entries.
     */
    public function entries(): \Generator {
        $this->header();
        $count = 0;
        $first = true;
        $ended = false;
        foreach ($this->lines() as $line) {
            if ($first) {
                $first = false;
                continue;
            }
            if ($ended) {
                throw self::invalid('after_end');
            }
            $entry = self::object($line);
            if (array_key_exists('end', $entry)) {
                if ($entry !== ['end' => true, 'entries' => $count]) {
                    throw self::invalid('end');
                }
                $ended = true;
                continue;
            }
            if (!self::entry_ok($entry)) {
                throw self::invalid('entry');
            }
            $count++;
            yield $entry;
        }
        if (!$ended) {
            throw self::invalid('no_end');
        }
    }

    /**
     * Removes the working file. The reader reads nothing afterwards.
     */
    public function close(): void {
        if ($this->workpath !== null) {
            $path = $this->workpath;
            $this->workpath = null;
            error_silencer::run(static fn() => unlink($path), $warning);
        }
    }

    /**
     * Removes the working file.
     */
    public function __destruct() {
        $this->close();
    }

    /**
     * Writes the compressed list to a working file.
     *
     * @param string $payload The compressed list.
     * @throws plugin_exception With restore_write_failed.
     */
    private function keep(string $payload): void {
        $folder = make_temp_directory(self::FOLDER);
        $path = error_silencer::run(static fn() => tempnam($folder, 'manifest'), $warning);
        if ($path === false) {
            throw new plugin_exception('restore_write_failed');
        }
        $this->workpath = $path;
        $written = error_silencer::run(static fn() => file_put_contents($path, $payload), $warning);
        if ($written !== strlen($payload)) {
            $this->close();
            throw new plugin_exception('restore_write_failed');
        }
    }

    /**
     * The lines of the list, without their line feeds.
     *
     * The list is one gzip stream: a stream that is cut, that has bytes after its end, or that is not gzip is not a list.
     *
     * @return \Generator
     * @throws plugin_exception With restore_manifest_invalid.
     */
    private function lines(): \Generator {
        $path = $this->workpath;
        $handle = $path === null ? false : error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
        if ($handle === false) {
            throw self::invalid('closed');
        }
        try {
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            $fed = 0;
            $buffer = '';
            while (inflate_get_status($context) !== ZLIB_STREAM_END && !feof($handle)) {
                $piece = fread($handle, self::PIECE);
                if ($piece === false || $piece === '') {
                    break;
                }
                $fed += strlen($piece);
                $text = error_silencer::run(static fn() => inflate_add($context, $piece), $warning);
                if ($text === false) {
                    throw self::invalid('gzip');
                }
                $buffer .= $text;
                while (($end = strpos($buffer, "\n")) !== false) {
                    yield substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 1);
                }
                if (strlen($buffer) > self::MAX_LINE) {
                    throw self::invalid('long_line');
                }
            }
            if (inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== $fed || !feof($handle)) {
                throw self::invalid('gzip_end');
            }
            if (fread($handle, 1) !== '') {
                throw self::invalid('gzip_tail');
            }
            if ($buffer !== '') {
                throw self::invalid('last_line');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A line as an object of JSON.
     *
     * @param string $line The line.
     * @return array The object, which has at least one member.
     * @throws plugin_exception With restore_manifest_invalid when it is not one.
     */
    private static function object(string $line): array {
        $value = json_decode($line, true);
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw self::invalid('line');
        }
        return $value;
    }

    /**
     * Whether the first line is the header of this run.
     *
     * @param array $header The first line.
     * @return bool
     */
    private function header_ok(array $header): bool {
        if (($header['v'] ?? null) !== 1 || ($header['site_id'] ?? null) !== $this->siteid) {
            return false;
        }
        if (($header['run_id'] ?? null) !== $this->runid) {
            return false;
        }
        foreach (self::HEADER_PARTS as $part) {
            if (!is_array($header[$part] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether an entry has the fields of its kind.
     *
     * @param array $entry The entry.
     * @return bool
     */
    private static function entry_ok(array $entry): bool {
        $kind = $entry['t'] ?? null;
        if (!is_string($kind) || !isset(self::FIELDS[$kind])) {
            return false;
        }
        [$texts, $numbers] = self::FIELDS[$kind];
        foreach ($texts as $field) {
            if (!is_string($entry[$field] ?? null)) {
                return false;
            }
        }
        foreach ($numbers as $field) {
            if (!is_int($entry[$field] ?? null)) {
                return false;
            }
        }
        if ($kind === 'db') {
            return $entry['rows'] >= 0 && $entry['plainlength'] >= 0 && $entry['offset'] >= 0 && $entry['length'] >= 0;
        }
        return $entry['size'] >= 0 && $entry['offset'] >= 0 && $entry['length'] >= 0
            && $entry['pieces'] >= 1 && $entry['piece'] >= 0 && $entry['piece'] < $entry['pieces'];
    }

    /**
     * The exception for a list that cannot be trusted. The detail says which check failed and holds no content.
     *
     * @param string $detail Which check.
     * @return plugin_exception
     */
    private static function invalid(string $detail): plugin_exception {
        return new plugin_exception('restore_manifest_invalid', null, $detail);
    }
}
