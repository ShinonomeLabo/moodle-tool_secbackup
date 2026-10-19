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
use tool_secbackup\local\remade_exception;

/**
 * Packs the files of the rest of the data directory ("dataroot", chunks of type 2) or of the code ("code", chunks of type 3)
 * into numbered chunks, and keeps the entry of the generation list for every file piece.
 *
 * The chunks are named by chunk_name::series() with the numbers 0, 1, 2 and so on, in the order they are started. A file of
 * less than FILE_LIMIT bytes is read whole into memory and is one item of the chunk that is open, which is finished when it
 * holds PIECE bytes of content; a file that does not fit in the room that is left starts a new chunk. A file of FILE_LIMIT
 * bytes or more is streamed, never into a chunk that holds other files, so that a failure can take back exactly the chunks of
 * that file: it is one item of a chunk of its own, or, from PIECE + 1 bytes, pieces of PIECE bytes each in a chunk of its own.
 *
 * The files of a data directory change while a run goes on, and there is no hash to expect. So a file that cannot be opened or
 * read, or whose length is not the one the open handle reports, is not an error: add() removes every chunk that held a piece
 * of it, records no entry for it, and lists it in unreadable(). The files that are already in the open chunk stay. A number
 * that a removed chunk had is not used again, so the numbers can have gaps.
 *
 * An exception is for what the writer cannot go on after (a chunk that cannot be written). It is made again in the catch block
 * (remade_exception) so that its trace holds nothing of a file, and every chunk that add() finished and did not hand out is
 * removed.
 *
 * The key for the names is kept the way packer and block_writer keep it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class series_writer {
    /** @var int From this size on (4 MiB), a file is streamed in a chunk of its own. */
    public const FILE_LIMIT = 4194304;

    /** @var int Bytes of content at which a chunk is finished, and in a piece of a big file (32 MiB). */
    public const PIECE = 33554432;

    /** @var int Bytes that one read of a small file asks for. */
    private const READ = 262144;

    /** @var string|null The key for the names of the chunks, until the object is destroyed. */
    private ?string $dedupkey;

    /** @var string "dataroot" or "code". */
    private string $label;

    /** @var string UUID of the run. */
    private string $runuuid;

    /** @var string Public key of the site, for encrypting. */
    private string $sitepublickey;

    /** @var string ID of the site. */
    private string $siteid;

    /** @var string Folder of the chunks. */
    private string $tempdir;

    /** @var int Type of the chunks. */
    private int $type;

    /** @var int Number for the next chunk. */
    private int $number = 0;

    /** @var chunk_writer|null The chunk that is open. */
    private ?chunk_writer $writer = null;

    /** @var int Bytes of content in the open chunk. */
    private int $chunkbytes = 0;

    /** @var array[] The files in the open chunk, in the order of its items: root, path, size and sha256. */
    private array $pending = [];

    /** @var array[] The entries of the files in the chunks that are finished. */
    private array $entries = [];

    /** @var chunk_info[] The chunks that add() or flush() has finished and not yet handed out. */
    private array $completed = [];

    /** @var string[] The files that could not be read, as root, a colon and the path. */
    private array $unreadable = [];

    /** @var \HashContext|null SHA-256 of the content of the big file that is being streamed. */
    private ?\HashContext $digest = null;

    /**
     * Constructor.
     *
     * @param #[|\SensitiveParameter|]|string $dedupkey The key for the names of the chunks (32 bytes).
     * @param string $label "dataroot" or "code".
     * @param string $runuuid UUID of the run.
     * @param string $sitepublickey Public key of the site (32 bytes).
     * @param string $siteid ID of the site.
     * @param string $tempdir Existing folder for the chunks.
     * @param int $firstnumber The number of the first chunk: a run that goes on in a new step gives the next_number() of the
     *     writer of the step before, so that no number is used twice in the run.
     * @throws \coding_exception For a key that does not have 32 bytes, or a label, a run UUID or a number that no chunk can
     *     have.
     */
    public function __construct(
        #[\SensitiveParameter] string $dedupkey,
        string $label,
        string $runuuid,
        string $sitepublickey,
        string $siteid,
        string $tempdir,
        int $firstnumber = 0
    ) {
        // The trace of an exception shows the parameters, and #[\SensitiveParameter] hides them only from PHP 8.2 on. So the
        // key moves to the property and the parameter is unset before anything below can throw.
        $this->dedupkey = $dedupkey;
        unset($dedupkey);
        $this->label = $label;
        $this->runuuid = $runuuid;
        $this->sitepublickey = $sitepublickey;
        $this->siteid = $siteid;
        $this->tempdir = $tempdir;
        $this->type = $label === 'code' ? chunk_writer::TYPE_CODE : chunk_writer::TYPE_FILES;
        $this->number = $firstnumber;
        try {
            if (strlen($this->dedupkey) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES) {
                throw new \coding_exception('The key for names has 32 bytes.');
            }
            // The first name checks the label, the run and the number, so that no later name can fail.
            $this->chunk_name($firstnumber);
        } catch (\coding_exception $e) {
            // A constructor that throws is not followed by the destructor.
            sodium_memzero($this->dedupkey);
            throw $e;
        }
    }

    /**
     * Adds a file.
     *
     * @param string $root Name of the root of the file: "dataroot", "secret", a component or "core".
     * @param string $base The folder of the root.
     * @param string $path The path of the file under the folder, with "/".
     * @param bool $smallonly Whether the caller listed the file as smaller than FILE_LIMIT and hands out big files with
     *     big_digests() and big_piece(): a file that has grown to FILE_LIMIT bytes or more is then unreadable (it changes while
     *     it is read), so that no file writes all its pieces at once.
     * @return chunk_info[] The chunks that became complete, in the order of their numbers.
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when a chunk cannot be written.
     * @throws \tool_secbackup\local\crypto\key_exception With publickey_invalid when the public key of the site cannot encrypt.
     * @throws \coding_exception For any other error of the writer of chunks; it names the class of that error only.
     */
    public function add(string $root, string $base, string $path, bool $smallonly = false): array {
        $this->completed = [];
        $mark = count($this->entries);
        try {
            $this->add_file($root, $base, $path, $smallonly);
        } catch (\Throwable $e) {
            // The caller never gets these chunks: take them back with their entries.
            $this->discard($this->completed);
            $this->completed = [];
            $this->entries = array_slice($this->entries, 0, $mark);
            throw $e;
        }
        $done = $this->completed;
        $this->completed = [];
        return $done;
    }

    /**
     * Finishes the chunk that is open, if there is one.
     *
     * @return chunk_info[] The chunk, or none.
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the chunk cannot be written; its files are lost.
     */
    public function flush(): array {
        $this->completed = [];
        $this->finish_open();
        $done = $this->completed;
        $this->completed = [];
        return $done;
    }

    /**
     * The entries of the generation list for the files that are in a finished chunk, in the order the files were added in.
     *
     * One entry for each file piece: {"t":label,"root","path","size" (of the whole file),"sha256" (of the whole file),"chunk",
     * "offset","length","piece","pieces"}. The offset and the length are those of the item in chunk_info::$items.
     *
     * @return array[]
     */
    public function entries(): array {
        return $this->entries;
    }

    /**
     * The number that the next chunk will have. A chunk that is open has taken its number.
     *
     * @return int
     */
    public function next_number(): int {
        return $this->number;
    }

    /**
     * The files that could not be read.
     *
     * @return string[] Each as the root, a colon and the path.
     */
    public function unreadable(): array {
        return $this->unreadable;
    }

    /**
     * Opens a file, checks that it is a regular file, and packs it.
     *
     * @param string $root Name of the root.
     * @param string $base Folder of the root.
     * @param string $path Path of the file.
     * @param bool $smallonly Whether a big file is unreadable.
     */
    private function add_file(string $root, string $base, string $path, bool $smallonly): void {
        [$handle, $stat] = self::open_regular($base . '/' . $path);
        if ($handle === false || ($smallonly && $stat['size'] >= self::FILE_LIMIT)) {
            $this->note_unreadable($root, $path);
        } else if ($stat['size'] < self::FILE_LIMIT) {
            $this->add_small($root, $path, $handle);
        } else {
            $this->add_big($root, $path, $handle, $stat['size']);
        }
        if ($handle !== false) {
            fclose($handle);
        }
    }

    /**
     * Opens a regular file: a path that is a link, a pipe, a socket or a folder is not opened (opening a pipe would wait for
     * ever).
     *
     * @param string $full The path.
     * @return array [the handle or false, the result of fstat()]
     */
    private static function open_regular(string $full): array {
        $lstat = error_silencer::run(static fn() => lstat($full), $warning);
        $handle = $lstat !== false && ($lstat['mode'] & 0170000) === 0100000
            ? error_silencer::run(static fn() => fopen($full, 'rb'), $warning) : false;
        $stat = $handle === false ? false : fstat($handle);
        if ($handle !== false && ($stat === false || ($stat['mode'] & 0170000) !== 0100000)) {
            fclose($handle);
            $handle = false;
        }
        return [$handle, $stat];
    }

    /**
     * Reads a big file once, without writing anything, for its size, its SHA-256 and the SHA-256 of each of its pieces.
     *
     * With big_piece(), a big file is handed out one piece at a time, so that the temporary folder never holds more than one
     * of its pieces: a piece is handed out only when it is the same as in this read.
     *
     * @param string $root Name of the root.
     * @param string $base Folder of the root.
     * @param string $path Path of the file.
     * @return array|null ['size', 'sha256', 'pieces' => SHA-256 of each piece], all in hexadecimal; null when the file cannot
     *     be opened or read, or changes its length while it is read: it is then in unreadable().
     */
    public function big_digests(string $root, string $base, string $path): ?array {
        [$handle, $stat] = self::open_regular($base . '/' . $path);
        // One flag for every way that the file cannot be read: it cannot be opened, a read fails or ends early, or there is
        // more than the length that the open handle reported.
        $ok = $handle !== false;
        $size = $ok ? (int) $stat['size'] : 0;
        $this->digest = hash_init('sha256');
        $pieces = [];
        for ($offset = 0; $ok && ($offset < $size || $offset === 0); $offset += self::PIECE) {
            $piece = hash_init('sha256');
            for ($left = min(self::PIECE, $size - $offset); $ok && $left > 0; $left -= strlen((string) $part)) {
                $part = error_silencer::run(static fn() => fread($handle, min(self::READ, $left)), $warning);
                $ok = is_string($part) && $part !== '' && $warning === '';
                hash_update($this->digest, (string) $part);
                hash_update($piece, (string) $part);
            }
            $pieces[] = hash_final($piece);
        }
        $digests = $ok && self::at_end($handle) ? ['size' => $size, 'sha256' => hash_final($this->digest), 'pieces' => $pieces]
            : null;
        $this->digest = null;
        if ($handle !== false) {
            fclose($handle);
        }
        if ($digests === null) {
            $this->note_unreadable($root, $path);
        }
        return $digests;
    }

    /**
     * Writes one piece of a big file in a chunk of its own with the next number, and hands it out when its content is the one
     * that big_digests() read.
     *
     * The entry of the piece is in entries() when it is handed out. A piece that is not handed out (the file is gone, has
     * another length or other content) leaves no file, and its number is not used again; the caller withdraws the file.
     *
     * @param string $root Name of the root.
     * @param string $base Folder of the root.
     * @param string $path Path of the file.
     * @param array $digests What big_digests() gave.
     * @param int $piece Number of the piece, from 0.
     * @return chunk_info|null The chunk, or null when the file has changed.
     * @throws \coding_exception When a chunk is open (the caller flushes first) or the file has no such piece.
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the chunk cannot be written.
     */
    public function big_piece(string $root, string $base, string $path, array $digests, int $piece): ?chunk_info {
        $count = count($digests['pieces']);
        if ($this->writer !== null || $piece < 0 || $piece >= $count) {
            throw new \coding_exception('A piece is written alone, and it is one that the file has.');
        }
        [$handle, $stat] = self::open_regular($base . '/' . $path);
        if ($handle === false || (int) $stat['size'] !== $digests['size']) {
            if ($handle !== false) {
                fclose($handle);
            }
            return null;
        }
        $this->digest = hash_init('sha256');
        try {
            $info = self::seek($handle, $piece * self::PIECE)
                ? $this->write_piece($handle, min(self::PIECE, $digests['size'] - $piece * self::PIECE)) : null;
            $same = $info !== null && hash_equals($digests['pieces'][$piece], hash_final($this->digest));
        } finally {
            $this->digest = null;
            fclose($handle);
        }
        if (!$same) {
            $this->discard($info === null ? [] : [$info]);
            return null;
        }
        $this->entries[] = $this->entry(
            $root,
            $path,
            $digests['size'],
            $digests['sha256'],
            $info->name,
            $info->items[0],
            $piece,
            $count
        );
        return $info;
    }

    /**
     * Moves a file to an offset.
     *
     * @param resource $handle The file.
     * @param int $offset The offset.
     * @return bool Whether it could.
     */
    private static function seek($handle, int $offset): bool {
        return error_silencer::run(static fn() => fseek($handle, $offset), $warning) === 0;
    }

    /**
     * Reads a small file whole and makes it an item of the open chunk.
     *
     * @param string $root Name of the root.
     * @param string $path Path of the file.
     * @param resource $handle The open file.
     */
    private function add_small(string $root, string $path, $handle): void {
        $data = self::read_small($handle);
        if ($data === null) {
            $this->note_unreadable($root, $path);
            return;
        }
        $length = strlen($data);
        if ($this->chunkbytes > 0 && $this->chunkbytes + $length > self::PIECE) {
            $this->finish_open();
        }
        if ($this->writer === null) {
            $this->start_chunk();
        }
        try {
            $this->writer->add_item($data, $length);
        } catch (\Throwable $e) {
            $this->drop_open();
            throw remade_exception::build(remade_exception::parts($e));
        }
        $this->pending[] = [
            'root' => $root, 'path' => $path, 'size' => $length, 'sha256' => hash('sha256', $data),
        ];
        $this->chunkbytes += $length;
        if ($this->chunkbytes >= self::PIECE) {
            $this->finish_open();
        }
    }

    /**
     * Reads a small file to its end, at most FILE_LIMIT + 1 bytes.
     *
     * @param resource $handle The open file.
     * @return string|null The content, or null when a read fails or the file has grown to FILE_LIMIT bytes or more.
     */
    private static function read_small($handle): ?string {
        $data = '';
        while (strlen($data) <= self::FILE_LIMIT) {
            $part = error_silencer::run(
                static fn() => fread($handle, min(self::READ, self::FILE_LIMIT + 1 - strlen($data))),
                $warning
            );
            if ($part === false || $warning !== '') {
                return null;
            }
            if ($part === '') {
                break;
            }
            $data .= $part;
        }
        return strlen($data) < self::FILE_LIMIT ? $data : null;
    }

    /**
     * Streams a big file as one piece or more, each in a chunk of its own.
     *
     * @param string $root Name of the root.
     * @param string $path Path of the file.
     * @param resource $handle The open file.
     * @param int $size Size of the file by the open handle.
     */
    private function add_big(string $root, string $path, $handle, int $size): void {
        $this->finish_open();
        $pieces = intdiv($size + self::PIECE - 1, self::PIECE);
        // Held here and not passed to write_piece(): a hash context in the arguments of a call is in the trace of an exception.
        $this->digest = hash_init('sha256');
        $mine = [];
        try {
            for ($piece = 0; $piece < $pieces; $piece++) {
                $info = $this->write_piece($handle, min(self::PIECE, $size - $piece * self::PIECE));
                if ($info === null) {
                    break;
                }
                $mine[] = $info;
            }
            $whole = $info !== null && self::at_end($handle);
            $sha256 = $whole ? hash_final($this->digest) : '';
        } catch (\Throwable $e) {
            $this->discard($mine);
            throw $e;
        } finally {
            $this->digest = null;
        }
        if (!$whole) {
            $this->discard($mine);
            $this->note_unreadable($root, $path);
            return;
        }
        foreach ($mine as $piece => $info) {
            $this->entries[] = $this->entry($root, $path, $size, $sha256, $info->name, $info->items[0], $piece, $pieces);
            $this->completed[] = $info;
        }
    }

    /**
     * Writes one piece of a big file in a chunk of its own.
     *
     * @param resource $handle The file, at the first byte of the piece.
     * @param int $length Bytes in the piece.
     * @return chunk_info|null The chunk, or null when the file ends early or a read fails.
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the chunk cannot be written.
     * @throws \tool_secbackup\local\crypto\key_exception With publickey_invalid when the public key of the site cannot encrypt.
     * @throws \coding_exception For any other error of the writer; it names the class of that error only.
     */
    private function write_piece($handle, int $length): ?chunk_info {
        $this->start_chunk();
        try {
            $this->writer->add_item($handle, $length, $this->digest);
            $info = $this->writer->finish();
        } catch (\Throwable $e) {
            $this->drop_open();
            $parts = remade_exception::parts($e);
            if (($parts['errorcode'] ?? '') === 'crypto_chunk_sourceshort') {
                return null;
            }
            throw remade_exception::build($parts);
        }
        $this->writer = null;
        $this->chunkbytes = 0;
        return $info;
    }

    /**
     * Whether the file has ended where it was expected to: there is no byte after the last piece and the read does not fail.
     *
     * @param resource $handle The file.
     * @return bool
     */
    private static function at_end($handle): bool {
        $more = error_silencer::run(static fn() => fread($handle, 1), $warning);
        return $more === '' && $warning === '';
    }

    /**
     * Opens the next chunk.
     *
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the file cannot be created.
     * @throws \tool_secbackup\local\crypto\key_exception With publickey_invalid when the public key of the site cannot encrypt.
     * @throws \coding_exception For any other error of the writer; it names the class of that error only.
     */
    private function start_chunk(): void {
        $name = $this->chunk_name($this->number++);
        try {
            $this->writer = new chunk_writer(
                $this->tempdir . '/' . $name,
                $this->type,
                $name,
                $this->sitepublickey,
                $this->siteid
            );
        } catch (\Throwable $e) {
            throw remade_exception::build(remade_exception::parts($e));
        }
        $this->chunkbytes = 0;
        $this->pending = [];
    }

    /**
     * Finishes the open chunk, records the entries of its files, and keeps the chunk for add() or flush() to hand out.
     *
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the chunk cannot be written.
     * @throws \coding_exception For any other error of the writer; it names the class of that error only.
     */
    private function finish_open(): void {
        if ($this->writer === null) {
            return;
        }
        try {
            $info = $this->writer->finish();
        } catch (\Throwable $e) {
            $this->drop_open();
            throw remade_exception::build(remade_exception::parts($e));
        }
        foreach ($this->pending as $index => $file) {
            $this->entries[] = $this->entry(
                $file['root'],
                $file['path'],
                $file['size'],
                $file['sha256'],
                $info->name,
                $info->items[$index],
                0,
                1
            );
        }
        $this->completed[] = $info;
        $this->writer = null;
        $this->chunkbytes = 0;
        $this->pending = [];
    }

    /**
     * The entry of a file piece.
     *
     * @param string $root Name of the root.
     * @param string $path Path of the file.
     * @param int $size Bytes of the whole file.
     * @param string $sha256 SHA-256 of the whole file in hexadecimal.
     * @param string $chunk Name of the chunk.
     * @param array $item Its offset and length in the chunk.
     * @param int $piece Number of the piece.
     * @param int $pieces Number of pieces.
     * @return array
     */
    private function entry(
        string $root,
        string $path,
        int $size,
        string $sha256,
        string $chunk,
        array $item,
        int $piece,
        int $pieces
    ): array {
        return [
            't' => $this->label, 'root' => $root, 'path' => $path, 'size' => $size, 'sha256' => $sha256, 'chunk' => $chunk,
            'offset' => $item['offset'], 'length' => $item['length'], 'piece' => $piece, 'pieces' => $pieces,
        ];
    }

    /**
     * Gives up the open chunk and the files in it.
     */
    private function drop_open(): void {
        if ($this->writer !== null) {
            $this->writer->abort();
        }
        $this->writer = null;
        $this->chunkbytes = 0;
        $this->pending = [];
    }

    /**
     * Removes finished chunks.
     *
     * @param chunk_info[] $infos The chunks.
     */
    private function discard(array $infos): void {
        foreach ($infos as $info) {
            $path = $info->path;
            error_silencer::run(static fn() => unlink($path), $warning);
        }
    }

    /**
     * Notes a file that could not be read.
     *
     * @param string $root Name of the root.
     * @param string $path Path of the file.
     */
    private function note_unreadable(string $root, string $path): void {
        $this->unreadable[] = $root . ':' . $path;
    }

    /**
     * The name of a numbered chunk.
     *
     * @param int $n Number of the chunk.
     * @return string
     * @throws \coding_exception For a label or a run UUID that no chunk can have.
     */
    private function chunk_name(int $n): string {
        try {
            return chunk_name::series($this->dedupkey, $this->label, $this->runuuid, $n);
        } catch (\coding_exception $e) {
            // Made again from the hint: the trace of the exception from chunk_name holds the key as an argument on PHP before
            // 8.2, and its message is not made into a hint again with its prefix.
            throw new \coding_exception($e->a);
        }
    }

    /**
     * Wipes the key and gives up the open chunk.
     */
    public function __destruct() {
        if ($this->writer !== null) {
            $this->writer->abort();
        }
        if ($this->dedupkey !== null) {
            sodium_memzero($this->dedupkey);
        }
    }

    /**
     * Keeps the key out of print_r(), var_dump() and debugging output.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['tempdir' => $this->tempdir, 'runuuid' => $this->runuuid, 'label' => $this->label];
    }

    /**
     * Refuses to be copied: the copy would share the key, and destroying it would wipe the key of the original.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        // The destructor of the copy must touch nothing of the original: not its key and not its open chunk.
        $this->dedupkey = null;
        $this->writer = null;
        throw new \coding_exception('A series writer must not be cloned.');
    }

    /**
     * Refuses to be serialised: the key would be kept.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A series writer must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A series writer must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A series writer must not be unserialised.');
    }
}
