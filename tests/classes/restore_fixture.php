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

use tool_secbackup\local\backup\files\packer;
use tool_secbackup\local\backup\manifest_builder;
use tool_secbackup\local\backup\series_writer;
use tool_secbackup\local\crypto\artifact_signature;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\site_keys;

/**
 * A generation of a site as a backup run stores it, made with the writers of a run, for the tests of a restore.
 *
 * The generation has two tables in three blocks, three small files of the file store in one chunk (packer), a big file in
 * three pieces of a chunk each, two files of the data root, one of them in the secret folder (series_writer), and the code of
 * two added plugins and three files of the Moodle code (series_writer), listed in the order of a run: blocks, files, data
 * root, then code with the plugins before the Moodle code. A test changes the public properties, then build() writes the
 * chunks into the folder of the chunks and the signed list (manifest_builder), and gives the input of a restore with empty
 * folders to write in. Pieces of the big file are a few bytes long: a restore does not depend on the size of a piece.
 *
 * Call it from a test that resets the site after the test.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_fixture {
    /** @var string Site ID. */
    public const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Run ID. */
    public const RUN = '22222222-2222-4222-8222-222222222222';

    /** @var string The folder of the chunks, as the restore host has downloaded them. */
    public string $chunksdir;

    /** @var string The folder that the code is written to. */
    public string $targetroot;

    /** @var string The folder that the data root is written to. */
    public string $targetdataroot;

    /** @var string The folder that the blocks and the header are written to. */
    public string $stagingdir;

    /** @var array[] The blocks of each table, in order: each a list of rows [id, name], ids in order. */
    public array $tables = [
        'course' => [[['1', 'Site'], ['2', 'Biology']], [['3', 'Chemistry']]],
        'user' => [[['1', 'guest'], ['2', 'admin']]],
    ];

    /** @var string[] The small files of the file store, packed in one chunk. */
    public array $smallfiles = ['The first small file.', 'The second small file.', 'The third small file.'];

    /** @var string[] The pieces of the big file of the file store, in order; the last is shorter. */
    public array $pieces = ['The first piece, ', 'the second piece', ', end.'];

    /** @var array Content by path, by root (dataroot or secret). */
    public array $datarootfiles = [
        'dataroot' => ['lang/ja/local.php' => "<?php\n\$string['sitename'] = 'Local';\n"],
        'secret' => ['key' => "\x00\x01\x02 key of the site \xff"],
    ];

    /** @var array Content by path, by root (a component, then core). */
    public array $codefiles;

    /** @var array The first line of the list. */
    public array $header;

    /** @var bool Whether the chunks of the file store are sealed with the old key of the site, as when a run shares them. */
    public bool $oldfiles = false;

    /** @var \Closure|null Changes the entries before the list is built: takes the entries and gives the new ones. */
    public ?\Closure $edit = null;

    /** @var array[] The entries of the list that build() made. */
    public array $entries = [];

    /** @var signing_keypair The signing key pair of the plugin of the site. */
    public signing_keypair $pair;

    /** @var string The folder of the fixture. */
    private string $dir;

    /** @var string The X25519 key pair of the site now. */
    private string $newpair;

    /** @var string The X25519 key pair that the site had before its key was made again. */
    private string $oldpair;

    /** @var string The key for the names of the chunks. */
    private string $dedupkey;

    /**
     * Makes the folders and the default content.
     */
    public function __construct() {
        $this->dir = make_request_directory();
        foreach (['chunks', 'root', 'dataroot', 'staging', 'source'] as $folder) {
            mkdir($this->dir . '/' . $folder);
        }
        $this->chunksdir = $this->dir . '/chunks';
        $this->targetroot = $this->dir . '/root';
        $this->targetdataroot = $this->dir . '/dataroot';
        $this->stagingdir = $this->dir . '/staging';
        $this->pair = signing_keypair::generate();
        $this->newpair = sodium_crypto_box_seed_keypair(str_repeat("\x05", 32));
        $this->oldpair = sodium_crypto_box_seed_keypair(str_repeat("\x06", 32));
        $this->dedupkey = str_repeat("\x07", 32);
        $this->codefiles = [
            'mod_attendance' => [
                'version.php' => "<?php\n\$plugin->component = 'mod_attendance';\n",
                'lang/en/attendance.php' => "<?php\n\$string['pluginname'] = 'Attendance';\n",
            ],
            'tool_secbackup' => ['version.php' => "<?php\n\$plugin->component = 'tool_secbackup';\n"],
            'core' => self::core_files(false),
        ];
        $this->header = [
            'v' => 1, 'site_id' => self::SITE, 'run_id' => self::RUN, 'kind' => 'full', 'created_at' => '2026-10-27T17:00:00Z',
            'encryption_key_fingerprint' => 'AAAA-BBBB-CCCC-DDDD',
            'moodle' => ['release' => '4.5.1 (Build: 20241104)', 'version' => '2024100701.00', 'build' => '20241104'],
            'db' => ['family' => 'postgres', 'tables' => ['course', 'tag', 'user'], 'unknown_tables' => [],
                'excluded_tables' => ['cache_flags', 'sessions']],
            'plugins' => [
                ['component' => 'mod_attendance', 'version' => '2025010100', 'standard' => false],
                ['component' => 'mod_forum', 'version' => '2024100700', 'standard' => true],
                ['component' => 'tool_secbackup', 'version' => '2026102800', 'standard' => false],
            ],
            'config' => ['prefix' => 'mdl_', 'dbtype' => 'pgsql', 'dblibrary' => 'native',
                'forced' => ['config' => ['theme' => 'boost'], 'plugins' => ['mod_attendance' => ['enabled' => '1']]]],
            'core_diff' => ['official' => false, 'modified' => [], 'added' => [], 'deleted' => []],
            'missing_files' => [sha1('a file that the site had lost')],
            'skipped_links' => [],
            'unreadable' => [],
        ];
    }

    /**
     * Three files of the Moodle code.
     *
     * @param bool $public Whether the code is of Moodle 5.1 or later: the web root in public/, lib/components.json at the root
     *     with folders that start with public/.
     * @return string[] Content by path.
     */
    public static function core_files(bool $public): array {
        $web = $public ? 'public/' : '';
        $components = ['plugintypes' => ['mod' => $web . 'mod', 'tool' => $web . 'admin/tool', 'local' => $web . 'local'],
            'subsystems' => ['course' => $web . 'course']];
        return [
            'lib/components.json' => json_encode($components, JSON_UNESCAPED_SLASHES),
            $web . 'index.php' => "<?php\n// The front page.\n",
            $web . 'lib/setup.php' => "<?php\n// The setup.\n",
        ];
    }

    /**
     * The keys of the site that a restore is given, the newest first.
     *
     * @param bool $old Whether the old key is given too.
     * @return site_keys
     */
    public function keys(bool $old = true): site_keys {
        $secrets = [sodium_crypto_box_secretkey($this->newpair)];
        if ($old) {
            $secrets[] = sodium_crypto_box_secretkey($this->oldpair);
        }
        return new site_keys($secrets);
    }

    /**
     * The secret keys of the site as text, the newest first, for the tests that they reach no output.
     *
     * @return string[]
     */
    public function secrets(): array {
        return [sodium_crypto_box_secretkey($this->newpair), sodium_crypto_box_secretkey($this->oldpair)];
    }

    /**
     * Writes the chunks and the signed list, and gives the input of a restore.
     *
     * @return array The input, as the restore host gives it.
     */
    public function build(): array {
        $entries = [
            ...$this->blocks(),
            ...$this->files(),
            ...$this->series('dataroot', $this->datarootfiles),
            ...$this->series('code', $this->codefiles),
        ];
        if ($this->edit !== null) {
            $entries = ($this->edit)($entries);
        }
        $this->entries = $entries;
        $builder = new manifest_builder(make_request_directory());
        foreach ($entries as $entry) {
            $builder->append($entry);
        }
        $chunk = $builder->build($this->header, self::RUN, sodium_crypto_box_publickey($this->newpair), self::SITE);
        return [
            'v' => 1,
            'site_id' => self::SITE,
            'run_id' => self::RUN,
            'manifest' => [
                'path' => $chunk->path,
                'sha256' => $chunk->sha256base64,
                'size' => $chunk->size,
                'signature' => artifact_signature::manifest($this->pair, self::SITE, self::RUN, $chunk->sha256base64),
                'signing_public_key' => base64_encode($this->pair->public_key()),
            ],
            'chunks_dir' => $this->chunksdir,
            'target_root' => $this->targetroot,
            'target_dataroot' => $this->targetdataroot,
            'staging_dir' => $this->stagingdir,
            'skip_components' => ['tool_secbackup'],
        ];
    }

    /**
     * Writes a chunk into the folder of the chunks, in place of one with the same name.
     *
     * @param string $name Name of the chunk.
     * @param int $type Type of the chunk.
     * @param string[] $payloads The items.
     * @param bool $old Whether it is sealed with the old key of the site.
     * @return chunk_info
     */
    public function chunk(string $name, int $type, array $payloads, bool $old = false): chunk_info {
        $writer = new chunk_writer(
            $this->chunksdir . '/' . $name,
            $type,
            $name,
            sodium_crypto_box_publickey($old ? $this->oldpair : $this->newpair),
            self::SITE
        );
        foreach ($payloads as $payload) {
            $writer->add_item($payload, strlen($payload));
        }
        return $writer->finish();
    }

    /**
     * The plain text of a block, in the form that block_writer writes.
     *
     * @param string $table The table.
     * @param array[] $rows The rows [id, name].
     * @return string
     */
    public static function block_text(string $table, array $rows): string {
        $first = [
            'table' => $table,
            'columns' => [['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'char']],
            'from' => (int) $rows[0][0],
            'to' => (int) $rows[array_key_last($rows)][0],
        ];
        $lines = [json_encode($first)];
        foreach ($rows as $row) {
            $lines[] = json_encode($row);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Writes the blocks of the tables, one chunk each.
     *
     * @return array[] Their entries.
     */
    private function blocks(): array {
        $entries = [];
        foreach ($this->tables as $table => $blocks) {
            foreach ($blocks as $rows) {
                $plain = self::block_text($table, $rows);
                $from = (int) $rows[0][0];
                $to = (int) $rows[array_key_last($rows)][0];
                $sha256 = hash('sha256', $plain);
                $name = chunk_name::db($this->dedupkey, self::RUN, $table, $from, $to, $sha256);
                $item = $this->chunk($name, chunk_writer::TYPE_DB, [gzencode($plain, 6)])->items[0];
                $entries[] = [
                    't' => 'db', 'table' => $table, 'from' => $from, 'to' => $to, 'rows' => count($rows),
                    'plainsha256' => $sha256, 'plainlength' => strlen($plain), 'chunk' => $name,
                    'offset' => $item['offset'], 'length' => $item['length'],
                ];
            }
        }
        return $entries;
    }

    /**
     * Writes the files of the file store: the small ones packed, the big one in pieces.
     *
     * @return array[] Their entries.
     */
    private function files(): array {
        $public = sodium_crypto_box_publickey($this->oldfiles ? $this->oldpair : $this->newpair);
        $packer = new packer($this->dedupkey, self::RUN, $public, self::SITE, $this->chunksdir);
        foreach ($this->smallfiles as $content) {
            $handle = fopen('php://memory', 'w+b');
            fwrite($handle, $content);
            rewind($handle);
            $packer->add(sha1($content), $handle, strlen($content));
            fclose($handle);
        }
        $packer->flush();
        $entries = $packer->entries();
        $big = implode('', $this->pieces);
        foreach ($this->pieces as $number => $piece) {
            $name = chunk_name::file($this->dedupkey, sha1($big), $number);
            $item = $this->chunk($name, chunk_writer::TYPE_FILES, [$piece], $this->oldfiles)->items[0];
            $entries[] = [
                't' => 'file', 'contenthash' => sha1($big), 'size' => strlen($big), 'chunk' => $name,
                'offset' => $item['offset'], 'length' => $item['length'], 'piece' => $number, 'pieces' => count($this->pieces),
            ];
        }
        return $entries;
    }

    /**
     * Writes the files of the data root or of the code from a source folder, as a run does.
     *
     * @param string $label dataroot or code.
     * @param array $roots Content by path, by root.
     * @return array[] Their entries.
     */
    private function series(string $label, array $roots): array {
        $writer = new series_writer(
            $this->dedupkey,
            $label,
            self::RUN,
            sodium_crypto_box_publickey($this->newpair),
            self::SITE,
            $this->chunksdir
        );
        foreach ($roots as $root => $files) {
            $base = $this->dir . '/source/' . $label . '/' . $root;
            foreach ($files as $path => $content) {
                $folder = dirname($base . '/' . $path);
                if (!is_dir($folder)) {
                    mkdir($folder, 0777, true);
                }
                file_put_contents($base . '/' . $path, $content);
                $writer->add($root, $base, $path);
            }
        }
        $writer->flush();
        return $writer->entries();
    }
}
