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
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\remade_exception;

/**
 * Packs the content of the files of the file store into encrypted chunks of type 2, and checks every file against the
 * content hash that names it.
 *
 * A file of FILE_LIMIT bytes or more (by the size in the files table, never by the size that the file system gives: that
 * can be stale on a network share) is written as pieces of PIECE bytes of content, each in a chunk of its own, named
 * after the content hash and the number of the piece. A smaller file is read into memory, checked, and joins the pending
 * small files; when they have PACK_AT bytes, or at flush(), they are written as one chunk with an item for each file. The
 * pending files and the one being read are in memory together: under PACK_AT + FILE_LIMIT, that is less than 36 MiB.
 *
 * Nothing of a file leaves add() before its whole content has been read and its SHA-1 equals the content hash: a chunk is
 * named by the hash that the file claims, so a piece of other content under that name would be skipped by later runs as
 * already stored. So add() is all or nothing for a file: when a read fails (nfs_slow), the content is not the one that the
 * hash names, or the length is not the size in the table (tamper_detected), or a chunk cannot be written
 * (temp_write_failed), every chunk file of that file is removed, no entry is recorded, and the pack that is pending is as
 * it was. Reading the file again is for the caller; file_source::pack() does that.
 *
 * The key for the names of the chunks is kept the way block_writer keeps it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class packer {
    /** @var int From this size on (4 MiB), a file is written as pieces and not packed with others. */
    public const FILE_LIMIT = 4194304;

    /** @var int Bytes of content in a piece of a big file (32 MiB). */
    public const PIECE = 33554432;

    /** @var int Pending bytes of small files at which a pack is written (32 MiB). */
    public const PACK_AT = 33554432;

    /** @var string Added to the name of a piece of a file until the content of the whole file is checked. */
    public const UNVERIFIED = '.unverified';

    /** @var int Bytes that one read of a small file asks for. */
    private const READ = 262144;

    /** @var string|null The key for the names of the chunks, until the object is destroyed. */
    private ?string $dedupkey;

    /** @var string UUID of the run. */
    private string $runuuid;

    /** @var string Public key of the site, for encrypting. */
    private string $sitepublickey;

    /** @var string ID of the site. */
    private string $siteid;

    /** @var string Folder of the chunks. */
    private string $tempdir;

    /** @var \HashContext|null SHA-1 of the content of the big file that is being packed. */
    private ?\HashContext $digest = null;

    /** @var array[] The small files that wait for a pack: 'hash', 'data' and 'seq' (their place in the entries). */
    private array $pending = [];

    /** @var int Bytes of content in the pending files. */
    private int $pendingbytes = 0;

    /** @var array[]|null[] The entries by the order the files were added in; null for a pending small file. */
    private array $entries = [];

    /** @var int Number for the next entry. */
    private int $sequence = 0;

    /**
     * Constructor.
     *
     * @param #[|\SensitiveParameter|]|string $dedupkey The key for the names of the chunks (32 bytes).
     * @param string $runuuid UUID of the run.
     * @param string $sitepublickey Public key of the site (32 bytes).
     * @param string $siteid ID of the site.
     * @param string $tempdir Existing folder for the chunks.
     * @throws \coding_exception When the key does not have 32 bytes.
     */
    public function __construct(
        #[\SensitiveParameter] string $dedupkey,
        string $runuuid,
        string $sitepublickey,
        string $siteid,
        string $tempdir
    ) {
        // The trace of an exception shows the parameters, and #[\SensitiveParameter] hides them only from PHP 8.2 on. So the
        // key moves to the property and the parameter is unset before anything below can throw. An assignment such as
        // $dedupkey = '' would not do: it is a dead store, which the optimiser of OPcache removes.
        $this->dedupkey = $dedupkey;
        unset($dedupkey);
        if (strlen($this->dedupkey) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES) {
            sodium_memzero($this->dedupkey);
            throw new \coding_exception('The key for names has 32 bytes.');
        }
        $this->runuuid = $runuuid;
        $this->sitepublickey = $sitepublickey;
        $this->siteid = $siteid;
        $this->tempdir = $tempdir;
    }

    /**
     * Packs a file.
     *
     * @param string $contenthash Content hash of the file (SHA-1, lower-case hexadecimal).
     * @param resource $handle An open stream at the first byte of the content; the caller closes it.
     * @param int $size Size of the file in the files table; it decides how the file is packed and what length it must have.
     * @return chunk_info[] The chunks that are ready to be sent: the pieces of a big file, or the pack that this file filled.
     *     Empty when the file waits for a pack.
     * @throws \coding_exception For a content hash that is not a SHA-1.
     * @throws plugin_exception With nfs_slow when the file cannot be read to its end, with tamper_detected when its content
     *     or length is not the one that the hash and the size name, and with temp_write_failed when a chunk cannot be written.
     */
    public function add(string $contenthash, $handle, int $size): array {
        // The name of the first piece is made for its check of the content hash only.
        $this->file_name($contenthash, 0);
        return $size < self::FILE_LIMIT
            ? $this->add_small($contenthash, $handle, $size)
            : $this->add_big($contenthash, $handle, $size);
    }

    /**
     * Writes the pending small files as a chunk, if there are any.
     *
     * @return chunk_info[] The chunk, or none.
     * @throws plugin_exception With temp_write_failed when the chunk cannot be written; the files stay pending.
     */
    public function flush(): array {
        return $this->pending === [] ? [] : [$this->write_pack()];
    }

    /**
     * The entries of the generation list for the files that are in a chunk, in the order the files were added in.
     *
     * A small file has its entry when its pack is written. The entry of a file of one piece or more:
     * {"t":"file","contenthash","size","chunk","offset","length","piece","pieces"}. For a small file, offset and length are
     * those of its item in the chunk (chunk_info::$items), and so are they for a piece of a big file; the length of an
     * item includes the padding that the last item of a chunk has.
     *
     * @return array[]
     */
    public function entries(): array {
        return array_values(array_filter($this->entries, static fn(?array $entry): bool => $entry !== null));
    }

    /**
     * Reads a small file, checks it, and adds it to the pending files.
     *
     * @param string $contenthash Content hash.
     * @param resource $handle The stream.
     * @param int $size Size in the files table.
     * @return chunk_info[] The pack, if this file filled it.
     */
    private function add_small(string $contenthash, $handle, int $size): array {
        $data = self::read_small($handle, $size);
        if (strlen($data) !== $size || sha1($data) !== $contenthash) {
            throw new plugin_exception('tamper_detected');
        }
        $sequence = $this->sequence++;
        $this->entries[$sequence] = null;
        $this->pending[] = ['hash' => $contenthash, 'data' => $data, 'seq' => $sequence];
        $this->pendingbytes += $size;
        if ($this->pendingbytes < self::PACK_AT) {
            return [];
        }
        try {
            return [$this->write_pack()];
        } catch (\Throwable $e) {
            // This file is not kept: the caller may add it again. The files that were pending before it are.
            array_pop($this->pending);
            $this->pendingbytes -= $size;
            unset($this->entries[$sequence]);
            throw $e;
        }
    }

    /**
     * Reads at most one byte more than the size of a small file.
     *
     * @param resource $handle The stream.
     * @param int $size Size in the files table.
     * @return string The content that was read: $size + 1 bytes at most.
     * @throws plugin_exception With nfs_slow when a read fails.
     */
    private static function read_small($handle, int $size): string {
        $data = '';
        while (strlen($data) <= $size) {
            $part = error_silencer::run(static fn() => fread($handle, min(self::READ, $size + 1 - strlen($data))), $warning);
            if ($part === false || $warning !== '') {
                throw new plugin_exception('nfs_slow', null, $warning === '' ? null : $warning);
            }
            if ($part === '') {
                break;
            }
            $data .= $part;
        }
        return $data;
    }

    /**
     * Writes the pending small files as one chunk.
     *
     * @return chunk_info
     */
    private function write_pack(): chunk_info {
        $name = $this->pack_name(array_column($this->pending, 'hash'));
        $writer = $this->new_writer($name);
        try {
            foreach ($this->pending as $file) {
                $writer->add_item($file['data'], strlen($file['data']));
            }
            $info = $writer->finish();
        } catch (\Throwable $e) {
            $writer->abort();
            throw remade_exception::build(remade_exception::parts($e));
        }
        foreach ($this->pending as $index => $file) {
            $this->entries[$file['seq']] = [
                't' => 'file',
                'contenthash' => $file['hash'],
                'size' => strlen($file['data']),
                'chunk' => $name,
                'offset' => $info->items[$index]['offset'],
                'length' => $info->items[$index]['length'],
                'piece' => 0,
                'pieces' => 1,
            ];
        }
        $this->pending = [];
        $this->pendingbytes = 0;
        return $info;
    }

    /**
     * Writes a big file as pieces, and keeps them only if its SHA-1 is the content hash.
     *
     * @param string $contenthash Content hash.
     * @param resource $handle The stream.
     * @param int $size Size in the files table.
     * @return chunk_info[] The pieces.
     */
    private function add_big(string $contenthash, $handle, int $size): array {
        $pieces = intdiv($size + self::PIECE - 1, self::PIECE);
        // Held here and not passed to write_piece(): a hash context in the arguments of a call is in the trace of an exception,
        // and it serialises the last bytes that were hashed.
        $this->digest = hash_init('sha1');
        $unverified = [];
        $infos = [];
        try {
            for ($piece = 0; $piece < $pieces; $piece++) {
                $length = min(self::PIECE, $size - $piece * self::PIECE);
                $unverified[] = $this->write_piece($contenthash, $piece, $handle, $length);
            }
            self::expect_end($handle);
            if (hash_final($this->digest) !== $contenthash) {
                throw new plugin_exception('tamper_detected');
            }
            // Only now do the pieces get the names that later runs trust.
            foreach ($unverified as $info) {
                $infos[] = $this->publish($info);
            }
        } catch (\Throwable $e) {
            foreach (array_merge($unverified, $infos) as $info) {
                error_silencer::run(static fn() => unlink($info->path), $warning);
            }
            throw $e;
        } finally {
            $this->digest = null;
        }
        foreach ($infos as $piece => $info) {
            $this->entries[$this->sequence++] = [
                't' => 'file',
                'contenthash' => $contenthash,
                'size' => $size,
                'chunk' => $info->name,
                'offset' => $info->items[0]['offset'],
                'length' => $info->items[0]['length'],
                'piece' => $piece,
                'pieces' => $pieces,
            ];
        }
        return $infos;
    }

    /**
     * Gives a piece that has been checked the name of its chunk.
     *
     * @param chunk_info $info The piece, in its file with the provisional name.
     * @return chunk_info The piece in the file with the name of the chunk.
     * @throws plugin_exception With temp_write_failed when the file cannot be renamed.
     */
    private function publish(chunk_info $info): chunk_info {
        $final = $this->tempdir . '/' . $info->name;
        $from = $info->path;
        $renamed = error_silencer::run(static fn() => rename($from, $final), $warning);
        if (!$renamed) {
            throw new plugin_exception('temp_write_failed', null, 'rename: ' . $warning);
        }
        return new chunk_info($final, $info->name, $info->size, $info->sha256base64, $info->items);
    }

    /**
     * Writes one piece of a big file.
     *
     * @param string $contenthash Content hash.
     * @param int $piece Number of the piece, from 0.
     * @param resource $handle The stream, at the first byte of the piece.
     * @param int $length Bytes of content in the piece.
     * @return chunk_info The piece, in a file with the provisional name (the name of the chunk and ".unverified").
     * @throws plugin_exception With nfs_slow when the read fails, with tamper_detected when the file ends early, and with
     *     temp_write_failed when the chunk cannot be written.
     * @throws key_exception With publickey_invalid when the public key of the site cannot encrypt.
     * @throws \coding_exception For any other error of the writer; it names the class of that error only.
     */
    private function write_piece(string $contenthash, int $piece, $handle, int $length): chunk_info {
        $name = $this->file_name($contenthash, $piece);
        $writer = $this->new_writer($name, self::UNVERIFIED);
        try {
            $writer->add_item($handle, $length, $this->digest);
            return $writer->finish();
        } catch (\Throwable $e) {
            $writer->abort();
            $parts = remade_exception::parts($e);
            if (($parts['errorcode'] ?? '') === 'crypto_chunk_sourceshort') {
                // A failed read leaves the warning of PHP in the debug information; the plain end of the file does not.
                $parts['errorcode'] = $parts['debuginfo'] === null ? 'tamper_detected' : 'nfs_slow';
                $parts['a'] = null;
            }
            throw remade_exception::build($parts);
        }
    }

    /**
     * Checks that there is nothing in the stream after the last piece.
     *
     * @param resource $handle The stream.
     * @throws plugin_exception With nfs_slow when the read fails, and with tamper_detected for more content than the size.
     */
    private static function expect_end($handle): void {
        $more = error_silencer::run(static fn() => fread($handle, 1), $warning);
        if ($more === false || $warning !== '') {
            throw new plugin_exception('nfs_slow', null, $warning === '' ? null : $warning);
        }
        if ($more !== '') {
            throw new plugin_exception('tamper_detected');
        }
    }

    /**
     * A writer of a chunk of files in the folder of the chunks.
     *
     * @param string $name Name of the chunk.
     * @param string $suffix Added to the name of the file.
     * @return chunk_writer
     */
    private function new_writer(string $name, string $suffix = ''): chunk_writer {
        $path = $this->tempdir . '/' . $name . $suffix;
        return new chunk_writer($path, chunk_writer::TYPE_FILES, $name, $this->sitepublickey, $this->siteid);
    }

    /**
     * The name of a piece of a file.
     *
     * @param string $contenthash Content hash.
     * @param int $piece Number of the piece.
     * @return string
     * @throws \coding_exception For a content hash that is not a SHA-1.
     */
    private function file_name(string $contenthash, int $piece): string {
        try {
            return chunk_name::file($this->dedupkey, $contenthash, $piece);
        } catch (\coding_exception $e) {
            // Made again from the hint: the trace of the exception from chunk_name holds the key as an argument on PHP before
            // 8.2, and its message is not made into a hint again with its prefix.
            throw new \coding_exception($e->a);
        }
    }

    /**
     * The name of a chunk of small files.
     *
     * @param string[] $contenthashes The content hashes of the files in it.
     * @return string
     */
    private function pack_name(array $contenthashes): string {
        // The key has 32 bytes (the constructor checked) and every content hash was checked by add(), so this cannot throw
        // and the trace of an exception cannot hold the key.
        return chunk_name::pack($this->dedupkey, $contenthashes);
    }

    /**
     * Wipes the key. The chunks of the pending files are not written: the caller flushes first.
     */
    public function __destruct() {
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
        return ['tempdir' => $this->tempdir, 'runuuid' => $this->runuuid];
    }

    /**
     * Refuses to be copied: the copy would share the key, and destroying it would wipe the key of the original.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        $this->dedupkey = null;
        throw new \coding_exception('A packer must not be cloned.');
    }

    /**
     * Refuses to be serialised: the key and the pending content would be kept.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A packer must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A packer must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A packer must not be unserialised.');
    }
}
