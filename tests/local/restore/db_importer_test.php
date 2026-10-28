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

use tool_secbackup\local\backup\db\block_writer;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\db_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\import_failed_exception::class)]
/**
 * Tests for the import of the blocks of the database into the database of the restored site.
 *
 * The staging folder of a test is the one that the extractor leaves: db/<number>.jsonl.gz and db/manifest.json. The blocks of
 * the tables of the site are written by block_writer of the backup from the rows of the site of the tests, the other blocks
 * in the same form. The site of the tests has a prefix of its own, which no block names.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\db_importer
 * @covers     \tool_secbackup\local\restore\import_failed_exception
 */
final class db_importer_test extends \advanced_testcase {
    /** @var string The table that the tests make. */
    private const TABLE = 'secbk_import';

    /** @var string[] Its columns, with the types that a block gives them. */
    private const COLUMNS = ['id' => 'int', 'name' => 'text', 'note' => 'char', 'data' => 'binary'];

    /** @var string Site ID of the blocks. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Run ID of the blocks. */
    private const RUN = '22222222-2222-4222-8222-222222222222';

    /** @var int Flags of the JSON that the tests write, those of block_writer. */
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var string The staging folder. */
    private string $dir;

    /** @var int[] Rows by table, as the manifest lists them, in order. */
    private array $tables = [];

    /** @var array[] The blocks of the manifest, in order. */
    private array $blocks = [];

    /** @var int[][] The calls of the progress function: [done, total] each. */
    private array $calls = [];

    /** @var \Closure|null Run after each call of the progress function, with the same arguments. */
    private ?\Closure $onprogress = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->dir = make_request_directory();
        mkdir($this->dir . '/db');
    }

    protected function tearDown(): void {
        global $DB;
        $manager = $DB->get_manager();
        if ($manager->table_exists(self::TABLE)) {
            $manager->drop_table(new \xmldb_table(self::TABLE));
        }
        parent::tearDown();
    }

    /**
     * Makes the table of the tests: id, name (text that cannot be null and has no default), note (characters) and data
     * (binary).
     *
     * @param string[] $names Values of name: a row each.
     * @return \xmldb_table
     */
    private static function create_table(array $names = []): \xmldb_table {
        global $DB;
        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('note', XMLDB_TYPE_CHAR, '255');
        $table->add_field('data', XMLDB_TYPE_BINARY);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
        foreach ($names as $name) {
            $DB->insert_record(self::TABLE, ['name' => $name]);
        }
        return $table;
    }

    /**
     * Writes the blocks of a table from its rows on the site, as a backup run does, and lists the table.
     *
     * @param string|\xmldb_table $table A table of the site, or the description of the table of the tests.
     */
    private function export(string|\xmldb_table $table): void {
        global $DB;
        if (is_string($table)) {
            $table = $DB->get_manager()->get_install_xml_schema()->getTable($table);
        }
        $pair = sodium_crypto_box_seed_keypair(str_repeat("\x05", 32));
        $public = sodium_crypto_box_publickey($pair);
        $writer = new block_writer($DB, str_repeat("\x07", 32), self::RUN, $public, self::SITE, make_request_directory());
        $this->tables[$table->getName()] ??= 0;
        $after = 0;
        while (($block = $writer->next_block($table, $after)) !== null) {
            $chunk = $block['chunk'];
            $reader = new chunk_reader($chunk->path, sodium_crypto_box_secretkey($pair), $public, self::SITE, $chunk->name);
            $this->stage($table->getName(), $reader->read_item($chunk->items[0]['offset']), $block);
            $after = $block['to'];
        }
    }

    /**
     * Writes a block in the form of block_writer, a first line and then a line for each row, and lists it.
     *
     * @param string $table The table.
     * @param string[] $columns Type by name.
     * @param array $rows The rows: an array of values each (the first one the id), or a line as it is.
     * @param array|string $first Members of the first line in place of the ones made from the rows, or the line as it is.
     * @return string The file of the block.
     */
    private function block(string $table, array $columns, array $rows, array|string $first = []): string {
        $ids = array_map(static fn(array $row): int => (int) $row[0], array_filter($rows, 'is_array'));
        $header = [
            'table' => $table,
            'columns' => array_map(
                static fn(string $name, string $type): array => ['name' => $name, 'type' => $type],
                array_keys($columns),
                $columns
            ),
            'from' => $ids === [] ? 1 : reset($ids),
            'to' => $ids === [] ? 1 : end($ids),
        ];
        $lines = [is_string($first) ? $first : json_encode(array_merge($header, $first), self::JSON)];
        foreach ($rows as $row) {
            $lines[] = is_array($row) ? json_encode($row, self::JSON) : $row;
        }
        $plain = implode("\n", $lines) . "\n";
        return $this->stage($table, gzencode($plain, 6), [
            'from' => $header['from'], 'to' => $header['to'], 'rows' => count($rows), 'plainsha256' => hash('sha256', $plain),
        ]);
    }

    /**
     * Writes a block into the staging folder and lists it.
     *
     * @param string $table The table.
     * @param string $item The block, compressed with gzip.
     * @param array $block Its from, to, rows and plainsha256.
     * @return string The file of the block.
     */
    private function stage(string $table, string $item, array $block): string {
        $file = (count($this->blocks) + 1) . '.jsonl.gz';
        file_put_contents($this->dir . '/db/' . $file, $item);
        $this->tables[$table] = ($this->tables[$table] ?? 0) + $block['rows'];
        $this->blocks[] = [
            'file' => $file, 'table' => $table, 'from' => $block['from'], 'to' => $block['to'], 'rows' => $block['rows'],
            'plainsha256' => $block['plainsha256'],
        ];
        return $file;
    }

    /**
     * Writes db/manifest.json, as the extractor does, from the blocks staged.
     *
     * @param \Closure|null $edit Changes the manifest: takes it (the tables as an object) and gives the new one, or the text
     *     to write, or null to write no file.
     */
    private function manifest(?\Closure $edit = null): void {
        $manifest = ['v' => 1, 'family' => 'postgres', 'tables' => (object) $this->tables, 'blocks' => $this->blocks];
        if ($edit !== null) {
            $manifest = $edit($manifest);
        }
        if ($manifest !== null) {
            $text = is_string($manifest) ? $manifest : json_encode($manifest, self::JSON);
            file_put_contents($this->dir . '/db/manifest.json', $text);
        }
    }

    /**
     * An importer of the staging folder that records the calls of its progress function.
     *
     * @return db_importer
     */
    private function importer(): db_importer {
        global $DB;
        return new db_importer($this->dir, $DB, function (int $done, int $total): void {
            $this->calls[] = [$done, $total];
            if ($this->onprogress !== null) {
                ($this->onprogress)($done, $total);
            }
        });
    }

    /**
     * Runs a call that must fail with a plugin_exception and gives it.
     *
     * @param callable $call The call.
     * @return plugin_exception
     */
    private function exception_of(callable $call): plugin_exception {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }

    /**
     * Runs the import, which must fail, and gives its exception.
     *
     * @return plugin_exception
     */
    private function failure(): plugin_exception {
        $importer = $this->importer();
        return $this->exception_of(static fn() => $importer->import());
    }

    /**
     * Rows of a table, by id, in the order of the ids.
     *
     * @param string $table The table.
     * @param string $select The rows, as SQL; all by default.
     * @param array $params The values of the SQL.
     * @return array[]
     */
    private static function rows(string $table, string $select = '', array $params = []): array {
        global $DB;
        return array_map(static fn(\stdClass $row): array => (array) $row, $DB->get_records_select($table, $select, $params, 'id'));
    }

    /**
     * The largest id of the blocks of a table.
     *
     * @param string $table The table.
     * @return int
     */
    private function last_id(string $table): int {
        $ids = array_column(array_filter($this->blocks, static fn(array $block): bool => $block['table'] === $table), 'to');
        return max($ids);
    }

    public function test_the_rows_of_the_blocks_take_the_place_of_the_rows_of_the_tables_with_their_ids(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $biology = $generator->create_course(['fullname' => 'Biology']);
        $chemistry = $generator->create_course(['fullname' => 'Chemistry']);
        $generator->create_user(['username' => 'learner']);
        set_config('colour', 'blue', 'mod_forum');
        $DB->insert_record('task_adhoc', ['component' => 'mod_forum', 'classname' => '\mod_forum\task\source', 'nextruntime' => 1]);
        $selections = [
            'course' => ['', []],
            'user' => ['', []],
            'config_plugins' => ['plugin <> ?', ['tool_secbackup']],
            'task_adhoc' => ['component <> ?', ['tool_secbackup']],
        ];
        $source = [];
        foreach ($selections as $table => $where) {
            $source[$table] = self::rows($table, ...$where);
            $this->export($table);
        }
        $plugin = array_column(self::rows('config_plugins', 'plugin = ?', ['tool_secbackup']), 'value', 'name');
        $this->assertNotEmpty($plugin);
        $this->manifest();
        // The site changes after the backup.
        $DB->delete_records('course', ['id' => $biology->id]);
        $DB->set_field('course', 'fullname', 'Changed', ['id' => $chemistry->id]);
        $generator->create_course(['fullname' => 'Physics']);
        $generator->create_user(['username' => 'newcomer']);
        set_config('colour', 'red', 'mod_forum');
        set_config('size', 'large', 'mod_quiz');
        $DB->delete_records('task_adhoc', ['classname' => '\mod_forum\task\source']);
        $DB->insert_record('task_adhoc', ['component' => 'mod_quiz', 'classname' => '\mod_quiz\task\target', 'nextruntime' => 2]);

        $summary = $this->importer()->import();

        foreach ($selections as $table => $where) {
            $this->assertSame($source[$table], self::rows($table, ...$where), $table);
        }
        $this->assertSame('Biology', $source['course'][$biology->id]['fullname']);
        $this->assertSame(['tables' => 4, 'rows' => array_sum(array_map('count', $source))], $summary);
        // The plugin keeps its rows.
        $this->assertSame($plugin, array_column(self::rows('config_plugins', 'plugin = ?', ['tool_secbackup']), 'value', 'name'));
    }

    public function test_the_plugin_keeps_its_rows_and_tables_and_their_blocks_are_not_imported(): void {
        global $DB;
        set_config('marker', 'source', 'tool_secbackup');
        $DB->insert_record('task_adhoc', [
            'component' => 'tool_secbackup', 'classname' => '\tool_secbackup\task\source', 'nextruntime' => 1,
        ]);
        $forumtask = $DB->insert_record('task_adhoc', [
            'component' => 'mod_forum', 'classname' => '\mod_forum\task\source', 'nextruntime' => 1,
        ]);
        $DB->insert_record('tool_secbackup_runs', ['triggeredby' => 'cli', 'stage' => 'source']);
        $config = self::rows('config_plugins', 'plugin <> ?', ['tool_secbackup']);
        $tasks = self::rows('task_adhoc', 'component <> ?', ['tool_secbackup']);
        foreach (['config_plugins', 'task_adhoc', 'tool_secbackup_runs'] as $table) {
            $this->export($table);
        }
        // A table of the plugin that the restored site does not have.
        $this->block('tool_secbackup_gone', ['id' => 'int'], [['1']]);
        $this->manifest();
        // The plugin of the restored site has its own rows, also under ids that rows of the blocks have.
        set_config('marker', 'target', 'tool_secbackup');
        $DB->delete_records('task_adhoc', ['component' => 'tool_secbackup']);
        $taken = array_key_first($config);
        $DB->delete_records('config_plugins', ['id' => $taken]);
        $DB->set_field('config_plugins', 'id', $taken, ['plugin' => 'tool_secbackup', 'name' => 'marker']);
        $DB->delete_records('task_adhoc', ['id' => $forumtask]);
        $DB->import_record('task_adhoc', [
            'id' => $forumtask, 'component' => 'tool_secbackup', 'classname' => '\tool_secbackup\task\target', 'nextruntime' => 2,
        ]);
        // A row of the site right after the ids of the blocks: the rows of the plugin move after it.
        $DB->import_record('config_plugins', [
            'id' => $this->last_id('config_plugins') + 1, 'plugin' => 'mod_quiz', 'name' => 'later', 'value' => 'x',
        ]);
        $DB->set_field('tool_secbackup_runs', 'stage', 'target', []);
        $DB->insert_record('tool_secbackup_runs', ['triggeredby' => 'schedule', 'stage' => 'other']);
        $plugin = array_column(self::rows('config_plugins', 'plugin = ?', ['tool_secbackup']), 'value', 'name');
        $runs = self::rows('tool_secbackup_runs');

        $summary = $this->importer()->import();

        $this->assertSame($config, self::rows('config_plugins', 'plugin <> ?', ['tool_secbackup']));
        $this->assertSame($tasks, self::rows('task_adhoc', 'component <> ?', ['tool_secbackup']));
        $kept = self::rows('config_plugins', 'plugin = ?', ['tool_secbackup']);
        $this->assertSame($plugin, array_column($kept, 'value', 'name'));
        $this->assertSame('target', $plugin['marker']);
        $kepttasks = self::rows('task_adhoc', 'component = ?', ['tool_secbackup']);
        $this->assertSame(['\tool_secbackup\task\target'], array_column($kepttasks, 'classname'));
        // The kept rows moved after the ids of the blocks.
        $this->assertGreaterThan($this->last_id('config_plugins'), min(array_keys($kept)));
        $this->assertGreaterThan($this->last_id('task_adhoc'), min(array_keys($kepttasks)));
        $this->assertSame($runs, self::rows('tool_secbackup_runs'));
        $this->assertSame(['tables' => 2, 'rows' => count($config) + count($tasks)], $summary);
    }

    public function test_the_rows_made_after_the_import_get_ids_after_the_largest_imported_id(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $this->export('course');
        $this->export('user');
        $this->manifest();
        // The new install has fewer rows: its sequences are behind the ids of the backup.
        foreach (['course' => $course->id, 'user' => $user->id] as $table => $id) {
            $DB->delete_records($table, ['id' => $id]);
            $DB->get_manager()->reset_sequence($table);
        }

        $this->importer()->import();

        $this->assertSame($this->last_id('course'), (int) $course->id);
        $this->assertSame($this->last_id('user'), (int) $user->id);
        $this->assertGreaterThan((int) $course->id, (int) $generator->create_course()->id);
        $this->assertGreaterThan((int) $user->id, (int) $generator->create_user()->id);
    }

    public function test_a_binary_column_comes_back_from_base64(): void {
        global $DB;
        $table = self::create_table();
        $bytes = ["\x00\x01\xff\xfe\x00abc\x80", implode('', array_map('chr', range(0, 255))), '', "\n\"\\"];
        foreach ($bytes as $number => $value) {
            $DB->insert_record(self::TABLE, ['name' => 'row ' . $number, 'note' => 'a note', 'data' => $value]);
        }
        $DB->insert_record(self::TABLE, ['name' => 'no data']);
        $source = self::rows(self::TABLE);
        $this->export($table);
        $this->manifest();
        $DB->delete_records(self::TABLE);
        $DB->insert_record(self::TABLE, ['name' => 'after', 'data' => 'other']);

        $this->importer()->import();

        $this->assertSame($source, self::rows(self::TABLE));
        $this->assertSame($bytes, array_slice(array_column(self::rows(self::TABLE), 'data'), 0, 4));
    }

    /**
     * Blocks whose columns do not match their table.
     *
     * @return array[] The table, the columns of the block, a row, and the table and the column that are named.
     */
    public static function schema_mismatch_provider(): array {
        return [
            'a column that the table does not have' => [
                self::TABLE, ['id' => 'int', 'name' => 'text', 'colour' => 'char'], ['5', 'n', 'blue'], 'secbk_import.colour',
            ],
            'no column that cannot be null and has no default' => [
                self::TABLE, ['id' => 'int', 'note' => 'char'], ['5', 'n'], 'secbk_import.name',
            ],
            'binary for a column that is not' => [
                self::TABLE, ['id' => 'int', 'name' => 'text', 'note' => 'binary'], ['5', 'n', 'bm90ZQ=='], 'secbk_import.note',
            ],
            'characters for a binary column' => [
                self::TABLE, ['id' => 'int', 'name' => 'text', 'data' => 'char'], ['5', 'n', 'x'], 'secbk_import.data',
            ],
            'config_plugins without the value' => [
                'config_plugins', ['id' => 'int', 'plugin' => 'char', 'name' => 'char'], ['5', 'mod_x', 'y'],
                'config_plugins.value',
            ],
            'config_plugins without the plugin of a row' => [
                'config_plugins', ['id' => 'int', 'name' => 'char', 'value' => 'text'], ['5', 'y', 'z'], 'config_plugins.plugin',
            ],
            'task_adhoc without the component of a row' => [
                'task_adhoc', ['id' => 'int', 'classname' => 'char', 'nextruntime' => 'int'], ['5', '\x', '1'],
                'task_adhoc.component',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('schema_mismatch_provider')]
    /**
     * A block whose columns do not match the table is refused before anything is written, and the message names the table
     * and the column (Review Focus 4).
     *
     * @dataProvider schema_mismatch_provider
     * @param string $table The table.
     * @param string[] $columns The columns of the block.
     * @param array $row A row.
     * @param string $named The table and the column that the error names.
     */
    public function test_a_block_whose_columns_do_not_match_its_table_is_refused_before_anything_is_written(
        string $table,
        array $columns,
        array $row,
        string $named
    ): void {
        self::create_table(['before']);
        // Listed before the block, and emptied if anything were written.
        $this->tables[self::TABLE] = 0;
        $this->block($table, $columns, [$row]);
        $this->manifest();
        $before = [self::rows(self::TABLE), self::rows('config_plugins'), self::rows('task_adhoc')];

        $e = $this->failure();

        $this->assertSame('restore_import_schema_mismatch', $e->errorcode);
        $this->assertSame($named, $e->a);
        [$tablename, $column] = explode('.', $named);
        $this->assertStringContainsString($tablename, $e->message());
        $this->assertStringContainsString($column, $e->message());
        $this->assertSame($before, [self::rows(self::TABLE), self::rows('config_plugins'), self::rows('task_adhoc')]);
        $this->assertSame([], $this->calls);
    }

    /**
     * Whether the table that the site does not have has a block.
     *
     * @return array[]
     */
    public static function missing_table_provider(): array {
        return ['listed without rows' => [false], 'with a block' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missing_table_provider')]
    /**
     * A table that the site does not have is refused before anything is written, by its name.
     *
     * @dataProvider missing_table_provider
     * @param bool $block Whether it has a block.
     */
    public function test_a_table_that_the_site_does_not_have_is_refused_before_anything_is_written(bool $block): void {
        self::create_table(['before']);
        $this->tables[self::TABLE] = 0;
        if ($block) {
            $this->block('local_gone', ['id' => 'int'], [['1']]);
        } else {
            $this->tables['local_gone'] = 0;
        }
        $this->manifest();

        $e = $this->failure();

        $this->assertSame('restore_import_schema_mismatch', $e->errorcode);
        $this->assertSame('local_gone', $e->a);
        $this->assertStringContainsString('local_gone', $e->message());
        $this->assertSame(['before'], array_column(self::rows(self::TABLE), 'name'));
    }

    /**
     * Counts of rows that the blocks do not have.
     *
     * @return array[] The rows of the block, and a change of the manifest.
     */
    public static function count_mismatch_provider(): array {
        $three = [['1', 'a', null, null], ['2', 'b', null, null], ['3', 'c', null, null]];
        $rows = static function (int $rows): \Closure {
            return static function (array $manifest) use ($rows): array {
                $manifest['blocks'][0]['rows'] = $rows;
                $manifest['tables']->{self::TABLE} = $rows;
                return $manifest;
            };
        };
        return [
            'a row more than the manifest says' => [$three, $rows(2)],
            'a row less than the manifest says' => [[['1', 'a', null, null]], $rows(2)],
            'blocks that do not add up to the rows of the table' => [$three, static function (array $manifest): array {
                $manifest['tables']->{self::TABLE} = 5;
                return $manifest;
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('count_mismatch_provider')]
    /**
     * A block with other rows than the manifest says is refused, and a row after the count is not written.
     *
     * @dataProvider count_mismatch_provider
     * @param array $rows The rows of the block.
     * @param \Closure $edit The change of the manifest.
     */
    public function test_a_block_with_other_rows_than_the_manifest_says_is_refused(array $rows, \Closure $edit): void {
        global $DB;
        self::create_table();
        $this->block(self::TABLE, self::COLUMNS, $rows);
        $this->manifest($edit);

        $this->assertSame('restore_import_count_mismatch', $this->failure()->errorcode);
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => 3]));
    }

    public function test_a_row_that_another_writer_adds_during_the_import_is_found(): void {
        global $DB;
        self::create_table();
        $this->block(self::TABLE, self::COLUMNS, [['1', 'a', null, null], ['2', 'b', null, null]]);
        $this->manifest();
        $this->onprogress = static function (int $done) use ($DB): void {
            if ($done === 1) {
                $DB->import_record(self::TABLE, ['id' => 999, 'name' => 'another writer']);
            }
        };

        $e = $this->failure();

        $this->assertSame('restore_import_count_mismatch', $e->errorcode);
        $this->assertSame(self::TABLE, $e->debuginfo);
    }

    /**
     * The tables that the tests of running again import, with their rows.
     *
     * @return array[]
     */
    private static function state(): array {
        return [self::rows('config_plugins'), self::rows('course'), self::rows('task_adhoc')];
    }

    public function test_an_import_runs_again_on_the_same_staging_folder_with_the_same_result(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $generator->create_course(['fullname' => 'Biology']);
        set_config('marker', 'source', 'tool_secbackup');
        $DB->insert_record('task_adhoc', ['component' => 'mod_forum', 'classname' => '\mod_forum\task\source', 'nextruntime' => 1]);
        foreach (['config_plugins', 'course', 'task_adhoc'] as $table) {
            $this->export($table);
        }
        $this->manifest();
        set_config('marker', 'target', 'tool_secbackup');
        $importer = $this->importer();

        $first = $importer->import();
        $once = self::state();
        $second = $importer->import();

        $this->assertSame($once, self::state());
        $this->assertSame($first, $second);
        $this->assertSame('target', $DB->get_field('config_plugins', 'value', ['plugin' => 'tool_secbackup', 'name' => 'marker']));
    }

    public function test_an_import_that_stopped_part_of_the_way_gives_the_same_rows_when_it_runs_again(): void {
        $generator = $this->getDataGenerator();
        $generator->create_course(['fullname' => 'Biology']);
        set_config('marker', 'source', 'tool_secbackup');
        foreach (['config_plugins', 'course', 'task_adhoc'] as $table) {
            $this->export($table);
        }
        $this->manifest();
        set_config('marker', 'target', 'tool_secbackup');
        $this->importer()->import();
        $once = self::state();
        // The site changes, and the next import stops in the middle of the rows.
        $generator->create_course(['fullname' => 'Physics']);
        set_config('colour', 'red', 'mod_forum');
        $total = array_sum($this->tables);
        $this->onprogress = static function (int $done) use ($total): void {
            if ($done === intdiv($total, 2)) {
                throw new \RuntimeException('stopped');
            }
        };
        try {
            $this->importer()->import();
            $this->fail('The import did not stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('stopped', $e->getMessage());
        }
        $this->assertNotSame($once, self::state());
        $this->onprogress = null;

        $this->importer()->import();

        $this->assertSame($once, self::state());
    }

    /**
     * Changes of the file of a block after the manifest was written.
     *
     * @return array[] The change: takes the path and the plain text.
     */
    public static function changed_block_provider(): array {
        return [
            'another plain text' => [static function (string $path, string $plain): void {
                file_put_contents($path, gzencode(str_replace('first', 'firsT', $plain), 6));
            }],
            'no file' => [static function (string $path): void {
                unlink($path);
            }],
            'not compressed' => [static function (string $path, string $plain): void {
                file_put_contents($path, str_replace('first', 'firsT', $plain));
            }],
            'cut short' => [static function (string $path): void {
                $item = file_get_contents($path);
                file_put_contents($path, substr($item, 0, intdiv(strlen($item), 2)));
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changed_block_provider')]
    /**
     * A block whose plain text does not have its SHA-256 any more is refused before it is read.
     *
     * @dataProvider changed_block_provider
     * @param \Closure $change The change of the file.
     */
    public function test_a_block_whose_plain_text_changed_is_refused_before_it_is_read(\Closure $change): void {
        global $DB;
        self::create_table();
        $rows = [['1', 'first', null, null], ['2', 'second', null, null]];
        $file = $this->block(self::TABLE, self::COLUMNS, $rows);
        $this->manifest();
        $plain = gzdecode(file_get_contents($this->dir . '/db/' . $file));
        $change($this->dir . '/db/' . $file, $plain);

        $e = $this->failure();

        $this->assertSame('restore_content_mismatch', $e->errorcode);
        $this->assertSame($file, $e->debuginfo);
        $this->assertSame(0, $DB->count_records(self::TABLE));
    }

    /**
     * First lines of a block that are not in their form. The block has the columns id and name and one row, id 5.
     *
     * @return array[] The first line, as members in place of the right ones or as it is.
     */
    public static function header_provider(): array {
        $id = ['name' => 'id', 'type' => 'int'];
        $name = ['name' => 'name', 'type' => 'text'];
        return [
            'not JSON' => ['{"table":'],
            'a list' => ['["secbk_import",[],5,5]'],
            'a member less' => ['{"table":"secbk_import","columns":[{"name":"id","type":"int"}],"from":5}'],
            'a member more' => [['rows' => 1]],
            'another table' => [['table' => 'course']],
            'another first id' => [['from' => 4]],
            'another last id' => [['to' => 6]],
            'the first id as text' => [['from' => '5']],
            'columns that are not a list' => [['columns' => ['id' => 'int', 'name' => 'text']]],
            'columns that are text' => [['columns' => 'id,name']],
            'a column that is text' => [['columns' => ['id', 'name']]],
            'a column with a member more' => [['columns' => [$id, $name + ['length' => 10]]]],
            'a column with a member less' => [['columns' => [$id, ['name' => 'name']]]],
            'a name that is not one' => [['columns' => [$id, ['name' => 'Name', 'type' => 'text']]]],
            'a name that is a number' => [['columns' => [$id, ['name' => 5, 'type' => 'text']]]],
            'a type that is not text' => [['columns' => [$id, ['name' => 'name', 'type' => null]]]],
            'two columns with one name' => [['columns' => [$id, $name, $name]]],
            'no id' => [['columns' => [$name, ['name' => 'note', 'type' => 'char']]]],
            'too deep' => ['{"table":"secbk_import","columns":[{"name":{"a":[1]},"type":"int"}],"from":5,"to":5}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('header_provider')]
    /**
     * A first line that is not in its form is refused before anything is written.
     *
     * @dataProvider header_provider
     * @param array|string $first The first line.
     */
    public function test_a_first_line_not_in_its_form_is_refused_before_anything_is_written(array|string $first): void {
        self::create_table(['before']);
        $file = $this->block(self::TABLE, ['id' => 'int', 'name' => 'text'], [['5', 'n']], $first);
        $this->manifest();

        $e = $this->failure();

        $this->assertSame('restore_content_mismatch', $e->errorcode);
        $this->assertSame($file, $e->debuginfo);
        $this->assertSame(['before'], array_column(self::rows(self::TABLE), 'name'));
        $this->assertSame([], $this->calls);
    }

    /**
     * Rows that are not in their form, after a row that is. The block has the columns of the table of the tests.
     *
     * @return array[] The row, as a line.
     */
    public static function row_provider(): array {
        return [
            'not JSON' => ['["5","n",null'],
            'an empty line' => [''],
            'an object' => ['{"0":"5","1":"n","2":null,"3":null}'],
            'a value less' => ['["5","n",null]'],
            'a value more' => ['["5","n",null,null,null]'],
            'a number' => ['["5",7,null,null]'],
            'a value true' => ['["5","n",true,null]'],
            'an array' => ['["5",["n"],null,null]'],
            'an empty array as a value' => ['["5",[],null,null]'],
            'an object as a value' => ['["5",{"a":"n"},null,null]'],
            'an empty object as a value' => ['["5",{},null,null]'],
            'binary that is not base64' => ['["5","n",null,"!!"]'],
            'an id that is not a number' => ['["x","n",null,null]'],
            'an id of 0' => ['["0","n",null,null]'],
            'an id that starts with 0' => ['["05","n",null,null]'],
            'no id' => ['[null,"n",null,null]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('row_provider')]
    /**
     * A row that is not in its form is refused, and is not written.
     *
     * @dataProvider row_provider
     * @param string $line The row.
     */
    public function test_a_row_not_in_its_form_is_refused(string $line): void {
        global $DB;
        self::create_table();
        $file = $this->block(self::TABLE, self::COLUMNS, [['1', 'a', null, null], $line]);
        $this->manifest();

        $e = $this->failure();

        $this->assertSame('restore_content_mismatch', $e->errorcode);
        $this->assertSame($file, $e->debuginfo);
        $this->assertSame(['a'], array_column(self::rows(self::TABLE), 'name'));
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => 5]));
    }

    /**
     * Ids of a row that are not ids of its block, which are 3 to 5.
     *
     * @return array[] The row, as a line.
     */
    public static function row_id_provider(): array {
        return [
            'before the first id' => ['["2","n",null,null]'],
            'after the last id' => ['["6","n",null,null]'],
            'a 0 before the id' => ['["04","n",null,null]'],
            'a letter after the id' => ['["4x","n",null,null]'],
            'a space before the id' => ['[" 4","n",null,null]'],
            'a decimal id' => ['["4.0","n",null,null]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('row_id_provider')]
    /**
     * A row whose id is not a number from the first to the last id of its block is refused, and nothing after it is written.
     *
     * @dataProvider row_id_provider
     * @param string $line The row.
     */
    public function test_a_row_whose_id_is_not_one_of_its_block_is_refused(string $line): void {
        global $DB;
        self::create_table();
        $file = $this->block(self::TABLE, self::COLUMNS, [['3', 'a', null, null], $line, ['5', 'c', null, null]]);
        $this->manifest();

        $e = $this->failure();

        $this->assertSame('restore_content_mismatch', $e->errorcode);
        $this->assertSame($file, $e->debuginfo);
        $this->assertSame(['3' => 'a'], array_column(self::rows(self::TABLE), 'name', 'id'));
        $this->assertSame(1, $DB->count_records(self::TABLE));
    }

    /**
     * Manifests that are not in their form. The good one lists one block of the table of the tests.
     *
     * @return array[] The change of the manifest (see manifest()).
     */
    public static function manifest_provider(): array {
        $set = static function (string $member, mixed $value): \Closure {
            return static function (array $manifest) use ($member, $value): array {
                $manifest[$member] = $value;
                return $manifest;
            };
        };
        $block = static function (string $member, mixed $value): \Closure {
            return static function (array $manifest) use ($member, $value): array {
                $manifest['blocks'][0][$member] = $value;
                return $manifest;
            };
        };
        return [
            'no file' => [static fn(): ?array => null],
            'not JSON' => [static fn(): string => '{"v":1,'],
            'a list' => [static fn(): array => [1, 'postgres', [], []]],
            'too deep' => [$set('family', [[[[]]]])],
            'a member less' => [static function (array $manifest): array {
                unset($manifest['family']);
                return $manifest;
            }],
            'a member more' => [$set('extra', 1)],
            'version 2' => [$set('v', 2)],
            'the version as text' => [$set('v', '1')],
            'a family that is not text' => [$set('family', 5)],
            'tables that are text' => [$set('tables', 'secbk_import')],
            'tables as a list' => [$set('tables', [1])],
            'a listed table that is not a name' => [$set('tables', [self::TABLE => 1, 'Secbk' => 0])],
            'a listed table named by a number' => [$set('tables', [self::TABLE => 1, '7' => 0])],
            'the rows of a table that are negative' => [$set('tables', [self::TABLE => -1])],
            'the rows of a table as text' => [$set('tables', [self::TABLE => '1'])],
            'blocks that are text' => [$set('blocks', '1.jsonl.gz')],
            'blocks as an object' => [$set('blocks', ['a' => 1])],
            'a block that is text' => [$set('blocks', ['1.jsonl.gz'])],
            'a block with a member less' => [static function (array $manifest): array {
                unset($manifest['blocks'][0]['to']);
                return $manifest;
            }],
            'a block with a member more' => [$block('plainlength', 10)],
            'a file outside the folder' => [$block('file', '../1.jsonl.gz')],
            'a file in a folder' => [$block('file', 'db/1.jsonl.gz')],
            'a file of another name' => [$block('file', '1.json')],
            'a file whose number starts with 0' => [$block('file', '01.jsonl.gz')],
            'a file as a number' => [$block('file', 1)],
            'a block of a table that is not a name' => [$block('table', 'Secbk_import')],
            'a table that is not listed' => [$block('table', 'course')],
            'a table as a number' => [$block('table', 1)],
            'a first id as text' => [$block('from', '1')],
            'a last id as text' => [$block('to', '1')],
            'no rows' => [$block('rows', 0)],
            'the rows of a block as text' => [$block('rows', '1')],
            'a hash in capitals' => [$block('plainsha256', str_repeat('A', 64))],
            'a hash too short' => [$block('plainsha256', str_repeat('a', 63))],
            'a hash as a number' => [$block('plainsha256', 1)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manifest_provider')]
    /**
     * A manifest that is not in its form is refused before anything is written.
     *
     * @dataProvider manifest_provider
     * @param \Closure $edit The change of the manifest.
     */
    public function test_a_manifest_not_in_its_form_is_refused_before_anything_is_written(\Closure $edit): void {
        self::create_table(['before']);
        $this->block(self::TABLE, self::COLUMNS, [['1', 'a', null, null]]);
        $this->manifest($edit);

        $this->assertSame('restore_input_invalid', $this->failure()->errorcode);
        $this->assertSame(['before'], array_column(self::rows(self::TABLE), 'name'));
        $this->assertSame([], $this->calls);
    }

    /**
     * Staging folders that are not absolute paths.
     *
     * @return array[]
     */
    public static function staging_folder_provider(): array {
        return ['empty' => [''], 'relative' => ['staging/db'], 'with a NUL' => ["/var/staging\0/db"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staging_folder_provider')]
    /**
     * A staging folder that is not an absolute path is refused.
     *
     * @dataProvider staging_folder_provider
     * @param string $folder The folder.
     */
    public function test_a_staging_folder_that_is_not_an_absolute_path_is_refused(string $folder): void {
        global $DB;
        $e = $this->exception_of(static fn() => new db_importer($folder, $DB, static function (): void {
        }));
        $this->assertSame('restore_input_invalid', $e->errorcode);
    }

    public function test_a_write_that_the_database_refuses_fails_without_the_values_of_the_row(): void {
        // The database refuses the write: PostgreSQL would refuse anything after it in the transaction of the test.
        $this->preventResetByRollback();
        self::create_table();
        $this->block(self::TABLE, self::COLUMNS, [['7', 'the first secret value', null, null], ['7', 'the second', null, null]]);
        $this->manifest();

        $e = $this->failure();

        $this->assertInstanceOf(import_failed_exception::class, $e);
        $this->assertSame('restore_import_failed', $e->errorcode);
        $this->assertSame(self::TABLE, $e->debuginfo);
        $this->assertInstanceOf(\dml_exception::class, $e->getPrevious());
        $this->assertStringNotContainsString('secret', $e->getMessage());
        $this->assertStringNotContainsString('second', $e->getMessage());
        $this->assertStringNotContainsString('secret', $e->message());
    }

    public function test_the_progress_counts_every_row_of_the_manifest_once(): void {
        self::create_table();
        $this->block(self::TABLE, self::COLUMNS, [['1', 'a', null, null], ['2', 'b', null, null]]);
        $this->block(self::TABLE, self::COLUMNS, [['3', 'c', null, null]]);
        // Not read: the plugin keeps its table.
        $this->block('tool_secbackup_runs', ['id' => 'int', 'stage' => 'char'], [['1', 'x'], ['2', 'y']]);
        $this->manifest();

        $summary = $this->importer()->import();

        $this->assertSame([[0, 5], [1, 5], [2, 5], [3, 5], [5, 5]], $this->calls);
        $this->assertSame(['tables' => 1, 'rows' => 3], $summary);
    }

    public function test_a_table_listed_without_rows_is_emptied_and_a_table_not_listed_is_left_as_it_is(): void {
        self::create_table(['before', 'also before']);
        $courses = self::rows('course');
        $this->tables[self::TABLE] = 0;
        $this->manifest();

        $summary = $this->importer()->import();

        $this->assertSame([], self::rows(self::TABLE));
        $this->assertSame($courses, self::rows('course'));
        $this->assertSame(['tables' => 1, 'rows' => 0], $summary);
        $this->assertSame([[0, 0]], $this->calls);
    }

    /**
     * Adds rows to context_temp, the table of Moodle whose id is not a sequence: it takes the ids of the contexts.
     *
     * @param int[] $ids The ids.
     */
    private static function add_context_temp_rows(array $ids): void {
        global $DB;
        foreach ($ids as $id) {
            $DB->import_record('context_temp', ['id' => $id, 'path' => '/1/' . $id, 'depth' => 2, 'locked' => 0]);
        }
    }

    public function test_a_table_whose_id_is_not_a_sequence_is_emptied_when_it_has_no_rows(): void {
        global $DB;
        self::add_context_temp_rows([5]);
        $this->tables['context_temp'] = 0;
        $this->manifest();

        $summary = $this->importer()->import();

        $this->assertSame(0, $DB->count_records('context_temp'));
        $this->assertSame(['tables' => 1, 'rows' => 0], $summary);
    }

    public function test_the_rows_of_a_table_whose_id_is_not_a_sequence_come_back_with_their_ids(): void {
        self::add_context_temp_rows([5, 9]);
        $source = [self::rows('context_temp'), self::rows('course')];
        $this->export('context_temp');
        // A table with a sequence in the same import.
        $this->export('course');
        $this->manifest();
        self::add_context_temp_rows([7]);
        $this->getDataGenerator()->create_course();

        $summary = $this->importer()->import();

        $this->assertSame($source, [self::rows('context_temp'), self::rows('course')]);
        $this->assertSame([5, 9], array_keys($source[0]));
        $this->assertSame(['tables' => 2, 'rows' => 2 + count($source[1])], $summary);
    }
}
