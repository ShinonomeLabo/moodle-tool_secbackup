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

namespace tool_secbackup\local\backup\files;

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Lists the files of the file store by content hash and opens them for reading.
 *
 * The pieces of a big file are read from one stream that stays open between them, so that a file is read once in a step
 * even when its stream cannot seek; the caller closes it at the end of the step (close()).
 *
 * The file store is only ever read. A file is opened through the file API (get_content_file_handle() of its stored file,
 * after is_file_readable_remotely_by_storedfile() has said yes), which also works with an external file system class; that
 * path never recovers a file from the trash. A file that is not in the file directory but in the trash is read in place.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_source {
    /** @var int[] Seconds to wait before each new try of a file that cannot be read. */
    private const WAITS = [1, 5, 15];

    /** @var int Bytes read at a time to reach a piece in a stream that cannot seek. */
    private const SKIP_READ = 1048576;

    /** @var \moodle_database The connection that the list of files is read from. */
    private \moodle_database $db;

    /** @var callable Waits some seconds. */
    private $sleep;

    /** @var array|null The content of the big file in progress, open across its pieces: hash, handle and offset. */
    private ?array $held = null;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The connection that the list of files is read from: the one of the export, so that the
     *     list is that of the snapshot of the database.
     * @param callable|null $sleep Called with the seconds to wait; sleep() by default.
     */
    public function __construct(\moodle_database $db, ?callable $sleep = null) {
        $this->db = $db;
        $this->sleep = $sleep ?? 'sleep';
    }

    /**
     * The next contents of the file store, in the order of the content hash.
     *
     * @param string|null $after The contents with a larger content hash; null for the first.
     * @param int $limit The most contents to give.
     * @return array[] ['contenthash' => string, 'size' => int] for each content that a file of 1 byte or more has, once.
     */
    public function next(?string $after, int $limit): array {
        $params = [];
        $where = 'filesize > 0';
        if ($after !== null) {
            $where .= ' AND contenthash > ?';
            $params[] = $after;
        }
        $rows = $this->db->get_records_sql(
            'SELECT contenthash, MAX(filesize) AS size FROM {files} WHERE ' . $where
                . ' GROUP BY contenthash ORDER BY contenthash',
            $params,
            0,
            $limit
        );
        return array_values(array_map(
            static fn(\stdClass $row): array => ['contenthash' => $row->contenthash, 'size' => (int) $row->size],
            $rows
        ));
    }

    /**
     * Opens the content of a file for reading.
     *
     * A content whose rows are gone (the files were deleted after the snapshot of the run listed them) is looked for in the
     * trash only.
     *
     * @param string $contenthash Content hash.
     * @return array|null ['handle' => stream, 'where' => 'filedir' or 'trashdir'], or null when the content is in neither.
     * @throws plugin_exception With nfs_slow when the content is there but cannot be opened.
     */
    public function open(string $contenthash): ?array {
        $records = $this->db->get_records_select('files', 'contenthash = ? AND filesize > 0', [$contenthash], 'id', '*', 0, 1);
        if ($records === []) {
            $handle = $this->trash_handle($contenthash);
            return $handle === null ? null : ['handle' => $handle, 'where' => 'trashdir'];
        }
        $storage = get_file_storage();
        $file = $storage->get_file_instance(reset($records));
        if ($storage->get_file_system()->is_file_readable_remotely_by_storedfile($file)) {
            $handle = $this->handle_of($file);
            if ($handle === false) {
                throw new plugin_exception('nfs_slow');
            }
            return ['handle' => $handle, 'where' => 'filedir'];
        }
        $handle = $this->trash_handle($contenthash);
        return $handle === null ? null : ['handle' => $handle, 'where' => 'trashdir'];
    }

    /**
     * Opens a file and packs it, reading it again from its start when the read fails.
     *
     * After a failed read the file is tried again after 1, 5 and 15 seconds; when the fourth try fails too, the error is
     * nfs_slow. A content that is not the one that its hash names (tamper_detected) is not tried again.
     *
     * @param string $contenthash Content hash.
     * @param int $size Size in the files table.
     * @param packer $packer The packer.
     * @return chunk_info[]|null What packer::add() gives, or null when the content is neither in the file directory nor in the
     *     trash.
     * @throws plugin_exception With nfs_slow, tamper_detected or temp_write_failed.
     */
    public function pack(string $contenthash, int $size, packer $packer): ?array {
        return $this->with_content($contenthash, static fn($handle): array => $packer->add($contenthash, $handle, $size));
    }

    /**
     * Reads a big file once for the digests of its pieces (packer::big_digests()), reading it again from its start when the
     * read fails, as pack() does.
     *
     * @param string $contenthash Content hash.
     * @param int $size Size in the files table, packer::FILE_LIMIT or more.
     * @param packer $packer The packer.
     * @return string[]|null The SHA-256 of each piece, or null when the content is neither in the file directory nor in the
     *     trash.
     * @throws plugin_exception With nfs_slow or tamper_detected.
     */
    public function big_digests(string $contenthash, int $size, packer $packer): ?array {
        return $this->with_content($contenthash, static fn($handle): array => $packer->big_digests($contenthash, $handle, $size));
    }

    /**
     * Writes one piece of a big file (packer::big_piece()).
     *
     * The content stays open from one piece to the next: the next piece is read where the last one ended. A stream is
     * opened only for the first piece of a step (or after a failure), and moved to the piece by seeking or, when it cannot
     * seek, by reading forward. It is closed after the last piece, after any failure, and by close().
     *
     * @param string $contenthash Content hash.
     * @param int $size Size in the files table.
     * @param int $piece Number of the piece, from 0.
     * @param string $digest Its SHA-256 from big_digests().
     * @param packer $packer The packer.
     * @return chunk_info|null The piece, or null when the content is neither in the file directory nor in the trash any more.
     * @throws plugin_exception With nfs_slow, tamper_detected or temp_write_failed.
     */
    public function big_piece(string $contenthash, int $size, int $piece, string $digest, packer $packer): ?chunk_info {
        $offset = $piece * packer::PIECE;
        return $this->retrying(function () use ($contenthash, $size, $piece, $digest, $packer, $offset): ?chunk_info {
            try {
                $handle = $this->held_at($contenthash, $offset);
                if ($handle === null) {
                    return null;
                }
                $chunk = $packer->big_piece($contenthash, $handle, $size, $piece, $digest);
            } catch (\Throwable $e) {
                $this->close();
                throw $e;
            }
            $this->held['offset'] = min($size, $offset + packer::PIECE);
            if ($this->held['offset'] >= $size) {
                $this->close();
            }
            return $chunk;
        });
    }

    /**
     * Closes the content of the big file in progress, if one is open. The files stage calls it at the end of every step.
     */
    public function close(): void {
        $handle = $this->held['handle'] ?? null;
        $this->held = null;
        if (is_resource($handle)) {
            error_silencer::run(static fn() => fclose($handle), $warning);
        }
    }

    /**
     * The open content of a big file, at an offset: the one that is open when it is the same content and can get there
     * (it is there, it can seek, or the offset is ahead), else the content opened again.
     *
     * @param string $contenthash Content hash.
     * @param int $offset The offset.
     * @return resource|null Null when the content is neither in the file directory nor in the trash.
     * @throws plugin_exception With nfs_slow or tamper_detected (see skip()).
     */
    private function held_at(string $contenthash, int $offset) {
        if ($this->held !== null && ($this->held['hash'] !== $contenthash || !self::move($this->held, $offset))) {
            $this->close();
        }
        if ($this->held === null) {
            $opened = $this->open($contenthash);
            if ($opened === null) {
                return null;
            }
            $this->held = ['hash' => $contenthash, 'handle' => $opened['handle'], 'offset' => 0];
            if (!self::move($this->held, $offset)) {
                throw new \coding_exception('A new stream is always at its start.');
            }
        }
        return $this->held['handle'];
    }

    /**
     * Moves an open content to an offset: by seeking, or by reading forward in a stream that cannot seek.
     *
     * @param array $held The open content; its offset is updated.
     * @param int $offset The offset.
     * @return bool False when the stream cannot seek and the offset is behind it.
     * @throws plugin_exception With nfs_slow when a read fails, and with tamper_detected when the stream ends before it.
     */
    private static function move(array &$held, int $offset): bool {
        if ($held['offset'] === $offset) {
            return true;
        }
        $handle = $held['handle'];
        if (error_silencer::run(static fn() => fseek($handle, $offset), $warning) !== 0) {
            if ($offset < $held['offset']) {
                return false;
            }
            self::skip($handle, $offset - $held['offset']);
        }
        $held['offset'] = $offset;
        return true;
    }

    /**
     * Opens a content and works on it, opening it again after a failed read.
     *
     * @param string $contenthash Content hash.
     * @param callable $work Function (resource $handle) that reads the content.
     * @return mixed What $work gives, or null when the content is neither in the file directory nor in the trash.
     * @throws plugin_exception With nfs_slow, and what $work throws.
     */
    private function with_content(string $contenthash, callable $work): mixed {
        return $this->retrying(function () use ($contenthash, $work): mixed {
            $opened = $this->open($contenthash);
            if ($opened === null) {
                return null;
            }
            try {
                return $work($opened['handle']);
            } finally {
                if (is_resource($opened['handle'])) {
                    fclose($opened['handle']);
                }
            }
        });
    }

    /**
     * Tries a read of a content until it works.
     *
     * After a failed read the content is tried again after 1, 5 and 15 seconds; when the fourth try fails too, the error is
     * nfs_slow. A content that is not the one that its hash names (tamper_detected) is not tried again.
     *
     * @param callable $try The read; it opens the content itself.
     * @return mixed What $try gives.
     * @throws plugin_exception With nfs_slow, and what $try throws.
     */
    private function retrying(callable $try): mixed {
        for ($attempt = 0;; $attempt++) {
            try {
                return $try();
            } catch (plugin_exception $e) {
                if ($e->errorcode !== 'nfs_slow' || !isset(self::WAITS[$attempt])) {
                    throw $e;
                }
            }
            ($this->sleep)(self::WAITS[$attempt]);
        }
    }

    /**
     * Reads past some bytes of a stream that cannot seek.
     *
     * @param resource $handle The stream.
     * @param int $bytes How many bytes.
     * @throws plugin_exception With nfs_slow when a read fails, and with tamper_detected when the stream ends before it.
     */
    private static function skip($handle, int $bytes): void {
        for ($left = $bytes; $left > 0; $left -= strlen($part)) {
            $part = error_silencer::run(static fn() => fread($handle, min(self::SKIP_READ, $left)), $warning);
            if ($part === false || $warning !== '') {
                throw new plugin_exception('nfs_slow', null, $warning === '' ? null : $warning);
            }
            if ($part === '') {
                throw new plugin_exception('tamper_detected');
            }
        }
    }

    /**
     * Opens the content of a stored file in the file directory, without letting PHP report a warning.
     *
     * A test replaces this to make the reading fail.
     *
     * @param \stored_file $file The file.
     * @return resource|false
     */
    protected function handle_of(\stored_file $file) {
        return error_silencer::run(static fn() => $file->get_content_file_handle(), $warning);
    }

    /**
     * Opens a file of the trash for reading, without letting PHP report a warning.
     *
     * A test replaces this to make the opening fail.
     *
     * @param string $path The path.
     * @return resource|false
     */
    protected function open_path(string $path) {
        return error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
    }

    /**
     * Opens a content in the trash, where it is, without moving it.
     *
     * @param string $contenthash Content hash.
     * @return resource|null The stream, or null when the content is not in the trash.
     * @throws plugin_exception With nfs_slow when the content is there but cannot be opened.
     */
    private function trash_handle(string $contenthash): mixed {
        global $CFG;
        $trashdir = isset($CFG->trashdir) ? $CFG->trashdir : $CFG->dataroot . '/trashdir';
        $paths = [
            $trashdir . '/' . substr($contenthash, 0, 2) . '/' . substr($contenthash, 2, 2) . '/' . $contenthash,
            $trashdir . '/' . $contenthash,
        ];
        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $handle = $this->open_path($path);
            if ($handle === false) {
                throw new plugin_exception('nfs_slow');
            }
            return $handle;
        }
        return null;
    }
}
