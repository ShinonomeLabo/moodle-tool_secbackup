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

use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Extracts a generation that the restore host has downloaded: decrypts its chunks with the keys of the site, checks every
 * file and block against the signed list of the generation, and writes them where the restored Moodle reads them.
 *
 * - The Moodle code (the root "core") goes to the target root as its paths say, the code of an added plugin to the folder that
 *   the restored code gives its component (plugin_dirs), except the components to skip.
 * - The data root (the root "dataroot") goes to the target data root, the secret folder (the root "secret") to its folder
 *   "secret", and a file of the file store to filedir/<2 characters>/<2 characters>/<content hash> there.
 * - A block of the database is kept as its compressed plain text in the staging folder: db/<number>.jsonl.gz, listed in
 *   db/manifest.json with every table of the header (a table without rows has 0), and the header goes to header.json, for
 *   the import, which runs without the keys.
 *
 * The list is read twice: first for the Moodle code alone, since lib/components.json in it tells where the plugins go, then
 * for everything else. A file in pieces is joined in the order of its pieces, which follow each other in the list, and gets
 * its name only after its last piece, when its size and its hash are the ones of the list: a piece that is missing, out of its
 * place, of another file, or of another length leaves no file, whole or part. Every write goes through tree_writer, so
 * nothing is written outside the folders of the input, and a file that fails is aborted.
 *
 * The keys never leave the site_keys and the readers of the chunks. A reader and the content of an item are local to the
 * method that reads the list, and are given only to calls that cannot throw, so no trace of an exception holds them; the
 * calls that can throw get strings, numbers and arrays of them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor {
    /** @var string[] The members of the input, in no order. */
    private const INPUT = [
        'v', 'site_id', 'run_id', 'manifest', 'chunks_dir', 'target_root', 'target_dataroot', 'staging_dir', 'skip_components',
    ];

    /** @var string[] The members of the manifest of the input. */
    private const MANIFEST = ['path', 'sha256', 'size', 'signature', 'signing_public_key'];

    /** @var string[] The members of the input that are absolute paths. */
    private const PATHS = ['chunks_dir', 'target_root', 'target_dataroot', 'staging_dir'];

    /** @var string Pattern of a UUID in lower case, as chunk names and the authenticated data have it. */
    private const UUID = '/\A[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

    /** @var string Pattern of a component of a plugin. */
    private const COMPONENT = '/\A[a-z][a-z0-9]*_[a-z][a-z0-9_]*\z/';

    /** @var string Pattern of a family of databases of Moodle. */
    private const FAMILY = '/\A[a-z]+\z/';

    /** @var string Pattern of a table name without the prefix. */
    private const TABLE = '/\A[a-z0-9_]+\z/';

    /** @var int[] The type of chunk of each kind of entry. */
    private const TYPES = [
        'db' => chunk_writer::TYPE_DB,
        'file' => chunk_writer::TYPE_FILES,
        'dataroot' => chunk_writer::TYPE_FILES,
        'code' => chunk_writer::TYPE_CODE,
    ];

    /** @var int[][] Mode of the files and of the folders that each writer makes. */
    private const MODES = ['code' => [0644, 0755], 'data' => [0660, 02770], 'staging' => [0640, 02750]];

    /** @var int Compressed bytes of a block that are decompressed at a time: at most about a thousand times that comes out. */
    private const INFLATE = 8192;

    /** @var int Flags of the JSON that is written. */
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var array The input. */
    private array $input;

    /** @var site_keys The keys of the site. */
    private site_keys $keys;

    /** @var \Closure Takes the entries done and the entries in all. */
    private \Closure $progress;

    /** @var tree_writer[] The writers: code (the target root), data (the target data root) and staging. */
    private array $writers = [];

    /** @var true[] The components to skip, as keys. */
    private array $skip;

    /** @var manifest_reader|null The list of the generation, while the extractor runs. */
    private ?manifest_reader $manifest = null;

    /** @var plugin_dirs|null The folders of the plugins in the restored code, once the Moodle code is written. */
    private ?plugin_dirs $plugins = null;

    /** @var string[] The folder of each component of the code, relative to the target root, once it is known. */
    private array $folders = [];

    /** @var array|null The file being written: its writer and path, what its pieces say, the next piece, the bytes so far. */
    private ?array $open = null;

    /** @var int Rows by table. */
    private array $tables = [];

    /** @var array[] The blocks written, in order. */
    private array $blocks = [];

    /** @var int Files of the file store written. */
    private int $files = 0;

    /** @var true[] The components whose code is written, as keys. */
    private array $components = [];

    /** @var int Entries done. */
    private int $done = 0;

    /** @var int Entries in the list. */
    private int $total = 0;

    /**
     * Constructor.
     *
     * @param array $input The input of the restore host: v, site_id, run_id, manifest (path, sha256, size, signature,
     *     signing_public_key), chunks_dir, target_root, target_dataroot, staging_dir, skip_components.
     * @param site_keys $keys The keys of the site, the newest first.
     * @param \Closure $progress Called as function (int $done, int $total): void, with no entry done when the extraction
     *     starts and after each entry.
     * @throws plugin_exception With restore_input_invalid when the input is not in that form.
     */
    public function __construct(array $input, site_keys $keys, \Closure $progress) {
        if (!self::input_ok($input)) {
            throw new plugin_exception('restore_input_invalid');
        }
        $this->input = $input;
        $this->keys = $keys;
        $this->progress = $progress;
        $this->skip = array_fill_keys($input['skip_components'], true);
        $bases = ['code' => 'target_root', 'data' => 'target_dataroot', 'staging' => 'staging_dir'];
        foreach ($bases as $name => $member) {
            $this->writers[$name] = new tree_writer($input[$member], self::MODES[$name][0], self::MODES[$name][1]);
        }
    }

    /**
     * Extracts the generation.
     *
     * @return array The summary: moodle (of the header), db_family, tables, rows, files, plugins (the components whose code
     *     was written, in byte order) and missing_files (the files that the site had lost, which are not written).
     * @throws plugin_exception With restore_manifest_invalid, restore_core_official_unsupported, restore_chunk_missing,
     *     restore_chunk_corrupt, restore_content_mismatch, restore_path_unsafe or restore_write_failed. A file that was being
     *     written is aborted; the files written before it stay.
     */
    public function run(): array {
        $manifest = $this->input['manifest'];
        $this->manifest = new manifest_reader(
            $manifest['path'],
            $this->keys,
            $this->input['site_id'],
            $this->input['run_id'],
            $manifest['size'],
            $manifest['sha256'],
            $manifest['signature'],
            $manifest['signing_public_key']
        );
        try {
            $header = $this->manifest->header();
            $this->check_header($header);
            $this->total = $this->manifest->count();
            ($this->progress)($this->done, $this->total);
            $this->pass(true);
            $this->plugins = new plugin_dirs($this->input['target_root']);
            $this->pass(false);
            $this->put('db/manifest.json', json_encode([
                'v' => 1,
                'family' => $header['db']['family'],
                'tables' => (object) $this->tables,
                'blocks' => $this->blocks,
            ], self::JSON));
            $this->put('header.json', json_encode($header, self::JSON));
        } catch (\Throwable $e) {
            $this->abandon();
            throw $e;
        } finally {
            $this->manifest->close();
            $this->manifest = null;
        }
        $plugins = array_keys($this->components);
        sort($plugins, SORT_STRING);
        return [
            'moodle' => $header['moodle'],
            'db_family' => $header['db']['family'],
            'tables' => count($this->tables),
            'rows' => array_sum($this->tables),
            'files' => $this->files,
            'plugins' => $plugins,
            'missing_files' => count($header['missing_files']),
        ];
    }

    /**
     * Whether the input is in its form.
     *
     * @param array $input The input.
     * @return bool
     */
    private static function input_ok(array $input): bool {
        if (!self::has_members($input, self::INPUT) || $input['v'] !== 1) {
            return false;
        }
        foreach (['site_id', 'run_id'] as $member) {
            if (!is_string($input[$member]) || preg_match(self::UUID, $input[$member]) !== 1) {
                return false;
            }
        }
        $manifest = $input['manifest'];
        if (!is_array($manifest) || !self::has_members($manifest, self::MANIFEST) || !self::absolute($manifest['path'])) {
            return false;
        }
        foreach (['sha256', 'signature', 'signing_public_key'] as $member) {
            if (!is_string($manifest[$member])) {
                return false;
            }
        }
        if (!is_int($manifest['size']) || $manifest['size'] < 0) {
            return false;
        }
        foreach (self::PATHS as $member) {
            if (!self::absolute($input[$member])) {
                return false;
            }
        }
        $skip = $input['skip_components'];
        return is_array($skip) && array_is_list($skip) && self::all_match($skip, self::COMPONENT);
    }

    /**
     * Whether an array has these members and no other.
     *
     * @param array $value The array.
     * @param string[] $members The members.
     * @return bool
     */
    private static function has_members(array $value, array $members): bool {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($members, SORT_STRING);
        return $keys === $members;
    }

    /**
     * Whether a value is an absolute path that PHP can open.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function absolute(mixed $value): bool {
        return is_string($value) && str_starts_with($value, '/') && !str_contains($value, "\0");
    }

    /**
     * Whether every value of a list is text that matches a pattern.
     *
     * @param array $values The values.
     * @param string $pattern The pattern.
     * @return bool
     */
    private static function all_match(array $values, string $pattern): bool {
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Refuses a header that does not say whether the Moodle code was compared with the official files, or what the database
     * has, and a generation that holds only the differences from the official files, whose other files cannot be fetched.
     *
     * @param array $header The header.
     * @throws plugin_exception With restore_manifest_invalid or restore_core_official_unsupported.
     */
    private function check_header(array $header): void {
        $official = $header['core_diff']['official'] ?? null;
        $family = $header['db']['family'] ?? null;
        $tables = $header['db']['tables'] ?? null;
        if (
            !is_bool($official) || !is_string($family) || preg_match(self::FAMILY, $family) !== 1
            || !is_array($tables) || !array_is_list($tables) || !self::all_match($tables, self::TABLE)
        ) {
            throw new plugin_exception('restore_manifest_invalid', null, 'header');
        }
        if ($official) {
            throw new plugin_exception('restore_core_official_unsupported');
        }
        $this->tables = array_fill_keys($tables, 0);
    }

    /**
     * Reads the list once and writes the entries of the pass: the Moodle code, or everything else.
     *
     * The reader of the chunk and the content of the item stay in this method (see the class).
     *
     * @param bool $core Whether this is the pass of the Moodle code.
     * @throws plugin_exception See run().
     */
    private function pass(bool $core): void {
        $reader = null;
        $name = '';
        foreach ($this->manifest->entries() as $entry) {
            $place = $this->place($entry, $core);
            if ($place === null) {
                continue;
            }
            if ($place !== false) {
                $this->begin($place, $entry);
                if ($entry['chunk'] !== $name) {
                    // The reader before is let go first: it closes its file and wipes its key.
                    $reader = null;
                    $name = $entry['chunk'];
                    $reader = $this->keys->reader($this->input['chunks_dir'] . '/' . $name, $this->input['site_id'], $name);
                }
                if ($reader->type() !== self::TYPES[$entry['t']]) {
                    throw new plugin_exception('restore_chunk_corrupt');
                }
                try {
                    $payload = $reader->read_item($entry['offset']);
                } catch (plugin_exception $e) {
                    // The reader throws crypto_chunk_corrupt or crypto_chunk_truncated: the item is not as it was written.
                    unset($e);
                    throw new plugin_exception('restore_chunk_corrupt');
                }
                if (($entry['t'] === 'db' && !self::holds_plain_text($payload, $entry)) || !$this->write($payload)) {
                    throw new plugin_exception('restore_content_mismatch');
                }
                unset($payload);
                $this->end($entry);
            }
            ($this->progress)(++$this->done, $this->total);
        }
    }

    /**
     * Where an entry is written.
     *
     * @param array $entry The entry.
     * @param bool $core Whether this is the pass of the Moodle code.
     * @return array|false|null Null when the entry is not of this pass; false when it is of this pass and is not written (the
     *     code of a component to skip); else the writer, the path, the hash algorithm and digest of the whole file (null for a
     *     block, which is checked as it is read) and its size.
     * @throws plugin_exception With restore_path_unsafe when the entry names no place, and with restore_manifest_invalid for a
     *     block of a table that the header does not list.
     */
    private function place(array $entry, bool $core): array|false|null {
        $kind = $entry['t'];
        if (($kind === 'code' && $entry['root'] === 'core') !== $core) {
            return null;
        }
        if ($kind === 'db') {
            if (!array_key_exists($entry['table'], $this->tables)) {
                throw new plugin_exception('restore_manifest_invalid', null, 'table');
            }
            $path = 'db/' . (count($this->blocks) + 1) . '.jsonl.gz';
            return ['writer' => 'staging', 'path' => $path, 'algo' => null, 'digest' => null, 'size' => null];
        }
        if ($kind === 'file') {
            $hash = $entry['contenthash'];
            $path = 'filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
            return ['writer' => 'data', 'path' => $path, 'algo' => 'sha1', 'digest' => $hash, 'size' => $entry['size']];
        }
        $root = $entry['root'];
        if ($kind === 'dataroot') {
            $folders = ['dataroot' => '', 'secret' => 'secret/'];
            if (!isset($folders[$root])) {
                throw new plugin_exception('restore_path_unsafe');
            }
            $writer = 'data';
            $path = $folders[$root] . $entry['path'];
        } else if ($core) {
            $writer = 'code';
            $path = $entry['path'];
        } else if (isset($this->skip[$root])) {
            return false;
        } else {
            $this->folders[$root] ??= $this->plugins->folder($root);
            $this->components[$root] = true;
            $writer = 'code';
            $path = $this->folders[$root] . '/' . $entry['path'];
        }
        return ['writer' => $writer, 'path' => $path, 'algo' => 'sha256', 'digest' => $entry['sha256'], 'size' => $entry['size']];
    }

    /**
     * Opens the file of an entry, or checks that the entry is the next piece of the file that is open.
     *
     * A file whose last piece the list does not have is still open when its pass ends: the next call, of the next pass or of
     * put(), which always follow, refuses it.
     *
     * @param array $place Where the entry is written (place()).
     * @param array $entry The entry.
     * @throws plugin_exception With restore_content_mismatch when a piece is missing or out of its place, and as tree_writer
     *     open() does.
     */
    private function begin(array $place, array $entry): void {
        $piece = $entry['piece'] ?? 0;
        // What every piece of a file says about the whole file.
        $whole = array_diff_key($entry, array_flip(['chunk', 'offset', 'length', 'piece']));
        if ($this->open !== null) {
            if ($this->open['whole'] !== $whole || $this->open['next'] !== $piece) {
                throw new plugin_exception('restore_content_mismatch');
            }
            return;
        }
        if ($piece !== 0) {
            throw new plugin_exception('restore_content_mismatch');
        }
        // Kept before the file is opened: when open() fails after it made the part file, abandon() aborts it.
        $this->open = $place + [
            'whole' => $whole, 'next' => 0, 'pieces' => $entry['pieces'] ?? 1, 'written' => 0, 'short' => false,
            'hash' => $place['algo'] === null ? null : hash_init($place['algo']), 'handle' => null,
        ];
        $this->open['handle'] = $this->writers[$place['writer']]->open($place['path']);
    }

    /**
     * Writes bytes to the open file and adds them to its hash. It does not throw, so the bytes are in no trace.
     *
     * @param string $bytes The bytes.
     * @return bool False when they would make the file longer than its size; nothing is written then. A write that fails is
     *     noted, and end() reports it.
     */
    private function write(string $bytes): bool {
        $length = strlen($bytes);
        if ($this->open['size'] !== null && $this->open['written'] + $length > $this->open['size']) {
            return false;
        }
        $handle = $this->open['handle'];
        $written = error_silencer::run(static fn() => fwrite($handle, $bytes), $warning);
        $this->open['short'] = $this->open['short'] || $written !== $length;
        $this->open['written'] += $length;
        if ($this->open['hash'] !== null) {
            hash_update($this->open['hash'], $bytes);
        }
        return true;
    }

    /**
     * Ends a piece: after the last one, checks the file and gives it its name, and counts it.
     *
     * @param array $entry The entry of the piece.
     * @throws plugin_exception With restore_write_failed when a write failed, with restore_content_mismatch when the file has
     *     another size or hash than the list says, and as tree_writer commit() does.
     */
    private function end(array $entry): void {
        $this->open['next']++;
        if ($this->open['next'] < $this->open['pieces']) {
            return;
        }
        $open = $this->open;
        $mismatch = $open['hash'] !== null
            && ($open['written'] !== $open['size'] || !hash_equals($open['digest'], hash_final($open['hash'])));
        if ($open['short'] || $mismatch) {
            // A write that failed comes first: it is why the content is not whole.
            throw new plugin_exception($open['short'] ? 'restore_write_failed' : 'restore_content_mismatch');
        }
        $this->writers[$open['writer']]->commit($open['path']);
        $this->open = null;
        if ($entry['t'] === 'file') {
            $this->files++;
        } else if ($entry['t'] === 'db') {
            $this->tables[$entry['table']] += $entry['rows'];
            $this->blocks[] = [
                'file' => basename($open['path']), 'table' => $entry['table'], 'from' => $entry['from'], 'to' => $entry['to'],
                'rows' => $entry['rows'], 'plainsha256' => $entry['plainsha256'],
            ];
        }
    }

    /**
     * Writes a file of the staging folder whole.
     *
     * @param string $path The path in the staging folder.
     * @param string $text The content.
     * @throws plugin_exception With restore_write_failed, and as tree_writer does.
     */
    private function put(string $path, string $text): void {
        $place = ['writer' => 'staging', 'path' => $path, 'algo' => null, 'digest' => null, 'size' => null];
        $this->begin($place, ['t' => 'staging', 'path' => $path]);
        $this->write($text);
        $this->end(['t' => 'staging']);
    }

    /**
     * Whether the item of a block is its plain text compressed with gzip, and nothing else.
     *
     * The text is decompressed a few bytes at a time and is not kept: only its length and its SHA-256 are. It does not throw,
     * so the item is in no trace.
     *
     * @param string $item The item.
     * @param array $entry The entry of the block: plainlength and plainsha256.
     * @return bool
     */
    private static function holds_plain_text(string $item, array $entry): bool {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $hash = hash_init('sha256');
        $length = 0;
        $size = strlen($item);
        for ($at = 0; $at < $size && inflate_get_status($context) !== ZLIB_STREAM_END; $at += self::INFLATE) {
            $text = error_silencer::run(static fn() => inflate_add($context, substr($item, $at, self::INFLATE)), $warning);
            if ($text === false) {
                return false;
            }
            $length += strlen($text);
            if ($length > $entry['plainlength']) {
                return false;
            }
            hash_update($hash, $text);
        }
        // One gzip stream that ends with the item: nothing is left out of the check, and nothing follows it.
        return inflate_get_status($context) === ZLIB_STREAM_END && inflate_get_read_len($context) === $size
            && $length === $entry['plainlength'] && hash_equals($entry['plainsha256'], hash_final($hash));
    }

    /**
     * Aborts the file that is open, if one is, after a failure.
     */
    private function abandon(): void {
        if ($this->open === null) {
            return;
        }
        $open = $this->open;
        $this->open = null;
        try {
            $this->writers[$open['writer']]->abort($open['path']);
        } catch (plugin_exception $e) {
            // A link on the way to the part file (tree_writer refuses to follow it). The failure that stopped the extraction
            // is the one to report.
            unset($e);
        }
    }
}
