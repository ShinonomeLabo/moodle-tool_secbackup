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

/**
 * Tests for the blocks of the rows of a table, with a stand-in for sodium_memzero() in the namespace of the writer.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup\db;

use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\trace_strings;

defined('MOODLE_INTERNAL') || die();

/**
 * Wipes a buffer as libsodium does, and keeps a copy of it for the tests of block_writer_test.
 *
 * PHP looks up a function that is called without a namespace in the namespace of the caller first, so while the tests run,
 * the classes of this namespace call this function instead of the one of libsodium. PHPUnit loads this file, which declares
 * it, before any test runs.
 *
 * @package    tool_secbackup
 * @param string $buffer The buffer, which is wiped.
 */
function sodium_memzero(string &$buffer): void {
    block_writer_test::$wiped[] = $buffer;
    \sodium_memzero($buffer);
}

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\block_writer::class)]
/**
 * Tests for the blocks of the rows of a table.
 *
 * The rows are those of config_log: id, userid, timemodified, plugin (can be null), name, value (text) and oldvalue (text,
 * can be null). The export reads on its own connection, so what a test reads must have been committed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\db\block_writer
 */
final class block_writer_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string UUID of the run of the tests. */
    private const RUN = '6f1d0c52-97d8-4f1e-8d3b-0a5e2c7b9e11';

    /** @var string[] Copies of the buffers that sodium_memzero() wiped, in order. */
    public static array $wiped = [];

    /** @var string Folder of the chunks of a test. */
    private string $dir;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->dir = make_request_directory();
    }

    /**
     * The key for names.
     *
     * @return string
     */
    private static function key(): string {
        return str_repeat("\x11", 32);
    }

    /**
     * The encryption key pair of the site of the tests.
     *
     * @return string
     */
    private static function pair(): string {
        return sodium_crypto_box_seed_keypair(str_repeat("\x07", 32));
    }

    /**
     * A writer on a connection.
     *
     * @param \moodle_database $db The connection.
     * @param string|null $dir The folder of the chunks; the folder of the test by default.
     * @return block_writer
     */
    private function writer(\moodle_database $db, ?string $dir = null): block_writer {
        $public = sodium_crypto_box_publickey(self::pair());
        return new block_writer($db, self::key(), self::RUN, $public, self::SITE, $dir ?? $this->dir);
    }

    /**
     * The table config_log as install.xml describes it.
     *
     * @return \xmldb_table
     */
    private static function log_table(): \xmldb_table {
        global $DB;
        foreach ($DB->get_manager()->get_install_xml_schema()->getTables() as $table) {
            if ($table->getName() === 'config_log') {
                return $table;
            }
        }
        throw new \coding_exception('config_log is missing');
    }

    /**
     * Opens the connection of the export inside a snapshot.
     *
     * @return \moodle_database
     */
    private function export(): \moodle_database {
        $export = connection_factory::open();
        connection_factory::snapshot_for($export)->begin($export);
        return $export;
    }

    /**
     * The largest id in config_log.
     *
     * @return int
     */
    private static function last_id(): int {
        global $DB;
        return (int)$DB->get_field_sql('SELECT MAX(id) FROM {config_log}');
    }

    /**
     * The ids of the rows of config_log after an id, in order. A sequence does not continue from the largest id: a test
     * that has been reset leaves it far ahead.
     *
     * @param int $base The id.
     * @return int[]
     */
    private static function ids_after(int $base): array {
        global $DB;
        return array_map('intval', array_keys($DB->get_records_select('config_log', 'id > ?', [$base], 'id', 'id')));
    }

    /**
     * Adds rows to config_log.
     *
     * @param int $count Number of rows.
     * @param string $value The value of every row.
     * @param int $offset Number of the first row.
     */
    private static function add_rows(int $count, string $value = 'v', int $offset = 0): void {
        global $DB;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = (object)[
                'userid' => 0, 'timemodified' => 1, 'plugin' => 'p', 'name' => 'n' . ($offset + $i), 'value' => $value,
            ];
        }
        $DB->insert_records('config_log', $rows);
    }

    /**
     * The plain text of a block, read back.
     *
     * @param array $block What next_block() gave.
     * @return string
     */
    private function plain_text(array $block): string {
        $chunk = $block['chunk'];
        $reader = new chunk_reader(
            $chunk->path,
            sodium_crypto_box_secretkey(self::pair()),
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $chunk->name
        );
        $this->assertSame(chunk_writer::TYPE_DB, $reader->type());
        $this->assertCount(1, $chunk->items);
        $plain = gzdecode($reader->read_item($chunk->items[0]['offset']));
        $this->assertIsString($plain);
        return $plain;
    }

    /**
     * The lines of a plain text, decoded.
     *
     * @param string $plain The plain text.
     * @return array[]
     */
    private static function decode(string $plain): array {
        self::assertSame("\n", substr($plain, -1));
        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", substr($plain, 0, -1))
        );
    }

    public function test_twelve_thousand_rows_are_three_blocks(): void {
        global $DB;
        $base = self::last_id();
        self::add_rows(12000);
        $ids = self::ids_after($base);
        $this->assertCount(12000, $ids);
        $export = $this->export();
        try {
            $writer = $this->writer($export);
            $table = self::log_table();
            $after = $base;
            $seen = [];
            while (($block = $writer->next_block($table, $after)) !== null) {
                $seen[] = $block;
                $after = $block['to'];
            }
            $this->assertSame([5000, 5000, 2000], array_column($seen, 'rows'));
            $this->assertSame([$ids[0], $ids[4999]], [$seen[0]['from'], $seen[0]['to']]);
            $this->assertSame([$ids[5000], $ids[9999]], [$seen[1]['from'], $seen[1]['to']]);
            $this->assertSame([$ids[10000], $ids[11999]], [$seen[2]['from'], $seen[2]['to']]);
            // The files are in the folder, with the names of the chunks, and nothing else is.
            $names = array_map(static fn(array $block): string => $block['chunk']->name, $seen);
            $files = array_diff(scandir($this->dir), ['.', '..']);
            sort($files);
            sort($names);
            $this->assertSame($names, $files);
            foreach ($seen as $block) {
                $lines = self::decode($this->plain_text($block));
                $this->assertCount($block['rows'] + 1, $lines);
                $this->assertSame((string)$block['from'], $lines[1][0]);
                $this->assertSame((string)$block['to'], end($lines)[0]);
            }
        } finally {
            $export->dispose();
        }
    }

    public function test_the_block_holds_the_header_the_columns_and_the_values_as_text(): void {
        global $DB;
        $base = self::last_id();
        $DB->insert_record('config_log', (object)[
            'userid' => 3, 'timemodified' => 1700000000, 'plugin' => null, 'name' => 'a/b "c" é日本 \\',
            'value' => "line1\nline2\ttab", 'oldvalue' => null,
        ]);
        $DB->insert_record('config_log', (object)[
            'userid' => 4, 'timemodified' => 5, 'plugin' => 'core', 'name' => 'x', 'value' => '', 'oldvalue' => 'old',
        ]);
        [$id1, $id2] = self::ids_after($base);
        $export = $this->export();
        try {
            $block = $this->writer($export)->next_block(self::log_table(), $base);
            $this->assertSame(2, $block['rows']);
            $plain = $this->plain_text($block);
            [$header, $first, $second] = self::decode($plain);
            $this->assertSame(
                ['table' => 'config_log', 'columns' => [
                    ['name' => 'id', 'type' => 'int'],
                    ['name' => 'userid', 'type' => 'int'],
                    ['name' => 'timemodified', 'type' => 'int'],
                    ['name' => 'plugin', 'type' => 'char'],
                    ['name' => 'name', 'type' => 'char'],
                    ['name' => 'value', 'type' => 'text'],
                    ['name' => 'oldvalue', 'type' => 'text'],
                ], 'from' => $id1, 'to' => $id2],
                $header
            );
            $this->assertSame(
                [(string)$id1, '3', '1700000000', null, 'a/b "c" é日本 \\', "line1\nline2\ttab", null],
                $first
            );
            $this->assertSame([(string)$id2, '4', '5', 'core', 'x', '', 'old'], $second);
            // Slashes and non-ASCII characters are written as they are, not escaped.
            $this->assertStringContainsString('a/b \"c\" é日本 \\\\', $plain);
            $this->assertStringContainsString('"table":"config_log"', explode("\n", $plain)[0]);
        } finally {
            $export->dispose();
        }
    }

    public function test_a_binary_column_is_base64(): void {
        global $DB;
        $base = self::last_id();
        $row = ['userid' => 0, 'timemodified' => 1, 'plugin' => 'p', 'name' => 'n'];
        $DB->insert_record('config_log', (object)($row + ['value' => "\x01\xc3\xa9ab"]));
        $DB->insert_record('config_log', (object)($row + ['value' => 'x', 'oldvalue' => 'y']));
        $table = new \xmldb_table('config_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('value', XMLDB_TYPE_BINARY);
        $table->add_field('oldvalue', XMLDB_TYPE_BINARY);
        $table->add_field('userid', XMLDB_TYPE_NUMBER, '10, 2');
        $table->add_field('timemodified', XMLDB_TYPE_FLOAT);
        $export = $this->export();
        try {
            $block = $this->writer($export)->next_block($table, $base);
            [$header, $first, $second] = self::decode($this->plain_text($block));
            $this->assertSame([
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'value', 'type' => 'binary'],
                ['name' => 'oldvalue', 'type' => 'binary'],
                ['name' => 'userid', 'type' => 'number'],
                ['name' => 'timemodified', 'type' => 'float'],
            ], $header['columns']);
            $this->assertSame(base64_encode("\x01\xc3\xa9ab"), $first[1]);
            $this->assertNull($first[2]);
            $this->assertSame(base64_encode('x'), $second[1]);
            $this->assertSame(base64_encode('y'), $second[2]);
        } finally {
            $export->dispose();
        }
    }

    public function test_the_name_is_the_name_of_the_block(): void {
        $base = self::last_id();
        self::add_rows(7);
        $ids = self::ids_after($base);
        $export = $this->export();
        try {
            $block = $this->writer($export)->next_block(self::log_table(), $base);
            $plain = $this->plain_text($block);
            $this->assertSame(hash('sha256', $plain), $block['plainsha256']);
            $this->assertSame(strlen($plain), $block['length']);
            $this->assertSame(
                chunk_name::db(self::key(), self::RUN, 'config_log', $ids[0], $ids[6], $block['plainsha256']),
                $block['chunk']->name
            );
            $this->assertSame($this->dir . '/' . $block['chunk']->name, $block['chunk']->path);
            $this->assertFileExists($block['chunk']->path);
            $this->assertSame($block['chunk']->size, filesize($block['chunk']->path));
            // The same rows make the same block under the same name.
            $again = $this->writer($export, make_request_directory())->next_block(self::log_table(), $base);
            $this->assertSame($block['chunk']->name, $again['chunk']->name);
            $this->assertSame($block['plainsha256'], $again['plainsha256']);
        } finally {
            $export->dispose();
        }
    }

    public function test_the_block_is_cut_before_the_row_that_takes_it_over_8_mib(): void {
        $base = self::last_id();
        // Each line is a little over 3,000,000 bytes: two of them fit in 8 MiB, three do not.
        self::add_rows(3, str_repeat('a', 3000000));
        $ids = self::ids_after($base);
        $export = $this->export();
        try {
            $writer = $this->writer($export);
            $table = self::log_table();
            $first = $writer->next_block($table, $base);
            $this->assertSame(2, $first['rows']);
            $this->assertSame($ids[1], $first['to']);
            $this->assertLessThanOrEqual(block_writer::MAX_BYTES, $first['length']);
            $this->assertGreaterThan(6000000, $first['length']);
            $second = $writer->next_block($table, $first['to']);
            $this->assertSame(1, $second['rows']);
            $this->assertSame([$ids[2], $ids[2]], [$second['from'], $second['to']]);
            $this->assertNull($writer->next_block($table, $second['to']));
        } finally {
            $export->dispose();
        }
    }

    public function test_a_row_over_8_mib_is_a_block_of_its_own(): void {
        $base = self::last_id();
        self::add_rows(1, 'small');
        self::add_rows(1, str_repeat('b', 9000000), 1);
        self::add_rows(1, 'small', 2);
        $export = $this->export();
        try {
            $writer = $this->writer($export);
            $table = self::log_table();
            $rows = [];
            $after = $base;
            while (($block = $writer->next_block($table, $after)) !== null) {
                $rows[] = $block['rows'];
                $after = $block['to'];
            }
            $this->assertSame([1, 1, 1], $rows);
            $this->assertGreaterThan(block_writer::MAX_BYTES, strlen(str_repeat('b', 9000000)));
        } finally {
            $export->dispose();
        }
    }

    public function test_a_row_over_8_mib_alone_is_written(): void {
        $base = self::last_id();
        self::add_rows(1, str_repeat('b', 9000000));
        $export = $this->export();
        try {
            $block = $this->writer($export)->next_block(self::log_table(), $base);
            $this->assertSame(1, $block['rows']);
            $this->assertGreaterThan(block_writer::MAX_BYTES, $block['length']);
            $this->assertSame($block['length'], strlen($this->plain_text($block)));
        } finally {
            $export->dispose();
        }
    }

    public function test_no_rows_after_the_id_give_null_and_no_file(): void {
        $base = self::last_id();
        $export = $this->export();
        try {
            $this->assertNull($this->writer($export)->next_block(self::log_table(), $base));
            $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
        } finally {
            $export->dispose();
        }
    }

    public function test_rows_changed_during_the_export_are_not_in_the_blocks(): void {
        global $DB;
        $base = self::last_id();
        self::add_rows(12000);
        $ids = self::ids_after($base);
        $export = $this->export();
        try {
            $writer = $this->writer($export);
            $table = self::log_table();
            $blocks = [$writer->next_block($table, $base)];
            // Another connection adds rows, changes one that is not read yet and deletes another.
            self::add_rows(100, 'late', 20000);
            $DB->set_field('config_log', 'value', 'changed', ['id' => $ids[10500]]);
            $DB->delete_records('config_log', ['id' => $ids[11000]]);
            while (($block = $writer->next_block($table, end($blocks)['to'])) !== null) {
                $blocks[] = $block;
            }
            $this->assertSame([5000, 5000, 2000], array_column($blocks, 'rows'));
            $this->assertSame($ids[11999], end($blocks)['to']);
            $rows = 0;
            foreach ($blocks as $block) {
                $plain = $this->plain_text($block);
                $this->assertStringNotContainsString('late', $plain);
                $this->assertStringNotContainsString('changed', $plain);
                $rows += $block['rows'];
            }
            $this->assertSame(12000, $rows);
            $this->assertStringContainsString('["' . $ids[11000] . '",', $this->plain_text($blocks[2]));
            $this->assertSame(12099, $DB->count_records_select('config_log', 'id > ?', [$base]));
        } finally {
            $export->dispose();
        }
    }

    /**
     * A recordset of rows given in the test, which tells whether it was closed.
     *
     * @param array[] $rows The rows, as arrays.
     * @return \moodle_recordset
     */
    private static function rows_of(array $rows): \moodle_recordset {
        return new class (array_map(static fn(array $row): \stdClass => (object)$row, $rows)) extends \moodle_recordset {
            /** @var bool Whether close() has been called. */
            public bool $closed = false;

            /** @var int Rows given out so far. */
            public int $yielded = 0;

            /** @var \stdClass[] The rows. */
            private array $rows;

            /** @var int Position. */
            private int $position = 0;

            /**
             * Constructor.
             *
             * @param \stdClass[] $rows The rows.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * Part of the iterator.
             *
             * @return mixed
             */
            #[\ReturnTypeWillChange]
            public function current() {
                $this->yielded++;
                return $this->rows[$this->position];
            }

            /**
             * Part of the iterator.
             *
             * @return mixed
             */
            #[\ReturnTypeWillChange]
            public function key() {
                return $this->position;
            }

            /**
             * Part of the iterator.
             *
             * @return mixed
             */
            #[\ReturnTypeWillChange]
            public function next() {
                $this->position++;
            }

            /**
             * Part of the iterator.
             *
             * @return mixed
             */
            #[\ReturnTypeWillChange]
            public function valid() {
                return $this->position < count($this->rows);
            }

            /**
             * Notes that the recordset is closed.
             */
            public function close() {
                $this->closed = true;
            }
        };
    }

    /** @var string The family of database that the stand-in is. */
    private string $family = 'postgres';

    /** @var array[] The queries that the stand-in got: 'sql', 'params' and 'num' (the limit). */
    private array $queries = [];

    /** @var \moodle_recordset|null The last recordset of the measuring query. */
    private ?\moodle_recordset $measured = null;

    /** @var \moodle_recordset|null The last recordset of the query that fetches the rows. */
    private ?\moodle_recordset $fetched = null;

    /**
     * A connection that is a stand-in on PostgreSQL's way of writing: it answers the measuring query with the sizes it is
     * given (1 for a row that has none), and the query that fetches the rows with the rows between the ids of the query.
     *
     * @param array[] $rows The rows, as arrays with an id.
     * @param int[] $sizes The size that the measuring query gives, by id.
     * @param int[] $vanished Ids of rows that the measuring query gives and the fetching query does not.
     * @return \moodle_database
     */
    private function stand_in(array $rows, array $sizes = [], array $vanished = []): \moodle_database {
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_dbfamily')->willReturn($this->family);
        $db->method('get_recordset_sql')->willReturnCallback(
            function (string $sql, ?array $params, int $from = 0, int $num = 0) use ($rows, $sizes, $vanished): \moodle_recordset {
                $this->queries[] = ['sql' => $sql, 'params' => $params, 'num' => $num];
                $inside = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['id'] > $params[0] && $row['id'] <= ($params[1] ?? PHP_INT_MAX)
                ));
                if (str_contains($sql, ' AS sz')) {
                    $inside = array_slice($inside, 0, $num ?: count($inside));
                    $this->measured = self::rows_of(array_map(
                        static fn(array $row): array => ['id' => $row['id'], 'sz' => $sizes[$row['id']] ?? 1],
                        $inside
                    ));
                    return $this->measured;
                }
                $this->fetched = self::rows_of(array_values(array_filter(
                    $inside,
                    static fn(array $row): bool => !in_array($row['id'], $vanished, true)
                )));
                return $this->fetched;
            }
        );
        return $db;
    }

    /**
     * A row of config_log as the database layer gives it.
     *
     * @param int $id Id.
     * @param string $value Value.
     * @return array
     */
    private static function log_row(int $id, string $value = 'v'): array {
        return [
            'id' => $id, 'userid' => '0', 'timemodified' => '1', 'plugin' => 'p', 'name' => 'n', 'value' => $value,
            'oldvalue' => null,
        ];
    }

    /**
     * The length of the line of such a row.
     *
     * @param int $id Id.
     * @param int $bytes Length of the value.
     * @return int
     */
    private static function line_length(int $id, int $bytes): int {
        $row = self::log_row($id, str_repeat('x', $bytes));
        $values = array_map(static fn($value) => $value === null ? null : (string)$value, array_values($row));
        return strlen(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) + 1;
    }

    /**
     * The length of the first line of a block of config_log, for ids.
     *
     * @param int $from First id.
     * @param int $to Last id.
     * @return int
     */
    private static function header_length(int $from, int $to): int {
        $columns = [];
        $types = [
            'id' => 'int', 'userid' => 'int', 'timemodified' => 'int', 'plugin' => 'char', 'name' => 'char',
            'value' => 'text', 'oldvalue' => 'text',
        ];
        foreach ($types as $name => $type) {
            $columns[] = ['name' => $name, 'type' => $type];
        }
        return strlen(json_encode(['table' => 'config_log', 'columns' => $columns, 'from' => $from, 'to' => $to])) + 1;
    }

    public function test_the_block_is_measured_first_and_only_its_window_is_fetched(): void {
        $rows = array_map(static fn(int $id): array => self::log_row($id), range(1, 5));
        $sizes = array_fill_keys(range(1, 5), 3000000);
        $block = $this->writer($this->stand_in($rows, $sizes))->next_block(self::log_table(), 0);
        $this->assertCount(2, $this->queries);
        [$measure, $fetch] = $this->queries;
        $this->assertSame([0], $measure['params']);
        $this->assertSame(block_writer::MAX_ROWS, $measure['num']);
        $this->assertStringContainsString('id > ? ORDER BY id', $measure['sql']);
        // Two rows of 3,000,000 bytes fit in 8 MiB, a third does not: the rows are fetched up to the second.
        $this->assertSame([0, 2], $fetch['params']);
        $this->assertStringContainsString('id > ? AND id <= ? ORDER BY id', $fetch['sql']);
        $this->assertSame(0, $fetch['num']);
        $this->assertSame([2, 2], [$block['rows'], $block['to']]);
        // The window was the block: no row was fetched and thrown away.
        $this->assertSame(2, $this->fetched->yielded);
        $this->assertTrue($this->measured->closed);
        $this->assertTrue($this->fetched->closed);
    }

    public function test_the_measuring_query_sums_the_sizes_of_the_columns_that_can_be_large(): void {
        $this->writer($this->stand_in([self::log_row(1)]))->next_block(self::log_table(), 0);
        $sql = $this->queries[0]['sql'];
        foreach (['plugin', 'name', 'value', 'oldvalue'] as $column) {
            $this->assertStringContainsString('COALESCE(OCTET_LENGTH("' . $column . '"), 0)', $sql);
        }
        // The other columns (id, userid, timemodified) count a fixed size each, and the sum is not an integer that can overflow.
        $this->assertSame(3, substr_count($sql, '+ 24'));
        $this->assertStringContainsString('0.0 + ', $sql);
        $this->assertStringContainsString('"value"', $this->queries[1]['sql']);
    }

    public function test_the_columns_are_quoted_the_way_of_each_family(): void {
        $this->family = 'mysql';
        $this->writer($this->stand_in([self::log_row(1)]))->next_block(self::log_table(), 0);
        $this->assertStringContainsString('COALESCE(OCTET_LENGTH(' . "\x60" . 'value' . "\x60" . '), 0)', $this->queries[0]['sql']);
        $this->assertStringContainsString("\x60" . 'oldvalue' . "\x60", $this->queries[1]['sql']);
        $this->queries = [];
        $this->family = 'other';
        $this->writer($this->stand_in([self::log_row(1)]))->next_block(self::log_table(), 0);
        $columns = 'SELECT id, userid, timemodified, plugin, name, value, oldvalue FROM';
        $this->assertStringContainsString($columns, $this->queries[1]['sql']);
        $this->assertStringNotContainsString('"', $this->queries[1]['sql']);
    }

    public function test_the_window_ends_at_the_budget_exactly(): void {
        $rows = [self::log_row(1), self::log_row(2)];
        $writer = $this->writer($this->stand_in($rows, [1 => 1, 2 => 1]));
        $this->assertSame(2, $writer->window_end(self::log_table(), 0));
        // Rows that together are the budget fit, one byte more does not (the budget leaves room for the header).
        $reserve = self::header_length(PHP_INT_MAX, PHP_INT_MAX);
        $limit = block_writer::MAX_BYTES - $reserve;
        $this->assertSame(2, $this->writer($this->stand_in($rows, [1 => $limit - 10, 2 => 10]))->window_end(self::log_table(), 0));
        $this->assertSame(1, $this->writer($this->stand_in($rows, [1 => $limit - 10, 2 => 11]))->window_end(self::log_table(), 0));
    }

    public function test_a_row_larger_than_the_budget_is_a_window_of_one_row(): void {
        $rows = [self::log_row(1), self::log_row(2)];
        $writer = $this->writer($this->stand_in($rows, [1 => 9000000, 2 => 10]));
        $block = $writer->next_block(self::log_table(), 0);
        $this->assertSame([0, 1], $this->queries[1]['params']);
        $this->assertSame([1, 1, 1], [$block['rows'], $block['from'], $block['to']]);
        $this->assertNull($this->writer($this->stand_in([]))->window_end(self::log_table(), 0));
    }

    public function test_the_real_size_decides_the_cut_inside_the_window(): void {
        // The measured sizes say that the four rows fit; the lines, with the escapes of JSON, do not.
        $value = str_repeat('"', 1500000);
        $rows = array_map(static fn(int $id): array => self::log_row($id, $value), [1, 2, 3, 4]);
        $block = $this->writer($this->stand_in($rows, array_fill_keys([1, 2, 3, 4], 1500000)))->next_block(self::log_table(), 0);
        $this->assertSame([0, 4], $this->queries[1]['params']);
        $this->assertSame(2, $block['rows']);
        $this->assertLessThanOrEqual(block_writer::MAX_BYTES, $block['length']);
        $this->assertTrue($this->fetched->closed);
    }

    public function test_the_lines_alone_may_fit_but_the_header_must_fit_too(): void {
        $first = 1000000;
        // The lines of the two rows together are as long as 8 MiB less the header that the block really has, plus one.
        $second = block_writer::MAX_BYTES - self::header_length(1, 2) + 1 - self::line_length(1, $first) - self::line_length(2, 0);
        $rows = [self::log_row(1, str_repeat('x', $first)), self::log_row(2, str_repeat('x', $second))];
        $this->assertSame(
            block_writer::MAX_BYTES + 1 - self::header_length(1, 2),
            self::line_length(1, $first) + self::line_length(2, $second)
        );
        $block = $this->writer($this->stand_in($rows))->next_block(self::log_table(), 0);
        $this->assertSame(1, $block['rows']);
        $this->assertLessThanOrEqual(block_writer::MAX_BYTES, $block['length']);
    }

    public function test_rows_that_fill_the_budget_exactly_are_one_block(): void {
        $first = 1000000;
        $reserve = self::header_length(PHP_INT_MAX, PHP_INT_MAX);
        $second = block_writer::MAX_BYTES - $reserve - self::line_length(1, $first) - self::line_length(2, 0);
        $rows = [self::log_row(1, str_repeat('x', $first)), self::log_row(2, str_repeat('x', $second))];
        $block = $this->writer($this->stand_in($rows))->next_block(self::log_table(), 0);
        $this->assertSame(2, $block['rows']);
        $this->assertLessThanOrEqual(block_writer::MAX_BYTES, $block['length']);
    }

    public function test_the_types_that_are_not_special_get_the_name_of_core_and_their_values_as_text(): void {
        $table = new \xmldb_table('config_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('stamp', XMLDB_TYPE_DATETIME);
        $odd = new \xmldb_field('odd');
        $odd->setType(99);
        $table->addField($odd);
        $rows = [['id' => 1, 'stamp' => '2026-10-19 00:00:00', 'odd' => 7]];
        $block = $this->writer($this->stand_in($rows))->next_block($table, 0);
        [$header, $row] = array_slice(self::decode($this->plain_text($block)), 0, 2);
        $this->assertSame(
            [['name' => 'id', 'type' => 'int'], ['name' => 'stamp', 'type' => 'datetime'], ['name' => 'odd', 'type' => 'unknown']],
            $header['columns']
        );
        $this->assertSame(['1', '2026-10-19 00:00:00', '7'], $row);
        $this->assertStringNotContainsString('OCTET_LENGTH', $this->queries[0]['sql']);
        $this->assertStringContainsString('"stamp"', $this->queries[1]['sql']);
    }

    public function test_rows_that_are_gone_when_they_are_fetched_give_no_block(): void {
        $block = $this->writer($this->stand_in([self::log_row(1)], [], [1]))->next_block(self::log_table(), 0);
        $this->assertNull($block);
        $this->assertTrue($this->fetched->closed);
        $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function test_the_text_of_a_block_is_a_sensitive_parameter(): void {
        $parameters = (new \ReflectionMethod(block_writer::class, 'write'))->getParameters();
        $marked = array_filter(
            $parameters,
            static fn(\ReflectionParameter $parameter): bool => $parameter->getAttributes('SensitiveParameter') !== []
        );
        $names = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $marked);
        $this->assertContains('plain', $names);
    }

    /**
     * Creates a table for a test and gives its description.
     *
     * @param string $name Name of the table.
     * @param array $columns Name => [xmldb type, length].
     * @return \xmldb_table
     */
    private function create_table(string $name, array $columns): \xmldb_table {
        global $DB;
        $table = new \xmldb_table($name);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        foreach ($columns as $column => [$type, $length]) {
            $table->add_field($column, $type, $length);
        }
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
        return $table;
    }

    public function test_a_real_binary_column_round_trips_every_byte(): void {
        global $DB;
        $bytes = ['a' => "\x00\x01\xff\xfe\x00abc\x80", 'b' => implode('', array_map('chr', range(0, 255)))];
        $table = $this->create_table('secbk_bin', ['data' => [XMLDB_TYPE_BINARY, null]]);
        try {
            foreach ($bytes as $value) {
                $DB->insert_record('secbk_bin', (object)['data' => $value]);
            }
            $export = $this->export();
            try {
                $block = $this->writer($export)->next_block($table, 0);
                [$header, $first, $second] = self::decode($this->plain_text($block));
                $this->assertSame([['name' => 'id', 'type' => 'int'], ['name' => 'data', 'type' => 'binary']], $header['columns']);
                $this->assertSame($bytes['a'], base64_decode($first[1], true));
                $this->assertSame($bytes['b'], base64_decode($second[1], true));
            } finally {
                $export->dispose();
            }
        } finally {
            $DB->get_manager()->drop_table($table);
        }
    }

    public function test_a_column_named_with_a_reserved_word_is_read(): void {
        global $DB;
        $table = $this->create_table('secbk_reserved', ['order' => [XMLDB_TYPE_CHAR, '20'], 'note' => [XMLDB_TYPE_TEXT, null]]);
        try {
            $quoted = $DB->get_manager()->generator->getEncQuoted('order');
            $this->assertNotSame('order', $quoted);
            $DB->execute("INSERT INTO {secbk_reserved} ($quoted, note) VALUES (?, ?)", ['first', null]);
            $DB->execute("INSERT INTO {secbk_reserved} ($quoted, note) VALUES (?, ?)", ['second', 'text']);
            $export = $this->export();
            try {
                $writer = $this->writer($export);
                $block = $writer->next_block($table, 0);
                [$header, $first, $second] = self::decode($this->plain_text($block));
                $this->assertSame('order', $header['columns'][1]['name']);
                $this->assertSame('first', $first[1]);
                $this->assertSame('second', $second[1]);
                $this->assertSame((int)$first[0] + 1, (int)$second[0]);
                $this->assertSame((int)$second[0], $writer->window_end($table, 0));
            } finally {
                $export->dispose();
            }
        } finally {
            $DB->get_manager()->drop_table($table);
        }
    }

    public function test_a_column_with_no_value_counts_nothing_in_the_measure(): void {
        $base = self::last_id();
        // The large text is in one column and the other one is null: a sum with a null in it would be null.
        self::add_rows(3, str_repeat('a', 3000000));
        $ids = self::ids_after($base);
        $export = $this->export();
        try {
            $writer = $this->writer($export);
            $this->assertSame($ids[1], $writer->window_end(self::log_table(), $base));
            $this->assertSame($ids[2], $writer->window_end(self::log_table(), $ids[1]));
            $this->assertNull($writer->window_end(self::log_table(), $ids[2]));
        } finally {
            $export->dispose();
        }
    }

    public function test_a_value_that_is_not_utf8_stops_the_block_and_closes_the_recordset(): void {
        $rows = [self::log_row(1, 'ok'), self::log_row(2, "bad\xff")];
        try {
            $this->writer($this->stand_in($rows))->next_block(self::log_table(), 0);
            $this->fail('A value that is not UTF-8 was written');
        } catch (\JsonException $e) {
            $this->assertTrue($this->measured->closed);
            $this->assertTrue($this->fetched->closed);
        }
        $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function test_numbers_that_the_database_layer_gives_are_written_as_text(): void {
        $rows = [[
            'id' => 1, 'userid' => 5, 'timemodified' => 2.5, 'plugin' => 'p', 'name' => 'n', 'value' => 'ok', 'oldvalue' => null,
        ]];
        $block = $this->writer($this->stand_in($rows))->next_block(self::log_table(), 0);
        $this->assertSame(['1', '5', '2.5', 'p', 'n', 'ok', null], self::decode($this->plain_text($block))[1]);
        $this->assertTrue($this->measured->closed);
        $this->assertTrue($this->fetched->closed);
    }

    public function test_no_part_file_is_left_when_the_block_cannot_be_finished(): void {
        $base = self::last_id();
        self::add_rows(3);
        $export = $this->export();
        try {
            $name = $this->writer($export, make_request_directory())->next_block(self::log_table(), $base)['chunk']->name;
            // A folder with the name of the chunk: the part file is written in full, and the rename at the end fails.
            mkdir($this->dir . '/' . $name);
            try {
                $this->writer($export)->next_block(self::log_table(), $base);
                $this->fail('The chunk was written over a folder');
            } catch (plugin_exception $e) {
                $this->assertSame('temp_write_failed', $e->errorcode);
            }
            $this->assertSame([$name], array_values(array_diff(scandir($this->dir), ['.', '..'])));
            $this->assertTrue(is_dir($this->dir . '/' . $name));
        } finally {
            $export->dispose();
        }
    }

    public function test_no_file_is_left_when_the_folder_cannot_be_written(): void {
        $base = self::last_id();
        self::add_rows(2);
        $export = $this->export();
        try {
            $writer = $this->writer($export, $this->dir . '/no-such-folder');
            try {
                $writer->next_block(self::log_table(), $base);
                $this->fail('A chunk was written to a folder that does not exist');
            } catch (plugin_exception $e) {
                $this->assertSame('temp_write_failed', $e->errorcode);
            }
            $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
        } finally {
            $export->dispose();
        }
    }

    public function test_a_table_without_an_id_column_is_refused(): void {
        $table = new \xmldb_table('config_log');
        $table->add_field('name', XMLDB_TYPE_CHAR, '100');
        $this->expectException(\coding_exception::class);
        $this->writer($this->createMock(\moodle_database::class))->next_block($table, 0);
    }

    /**
     * Checks that the trace of an exception holds no argument with the key.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     */
    private function assert_trace_hides_the_key(\Throwable $exception): void {
        $this->assertNotEmpty(
            array_filter($exception->getTrace(), static fn(array $frame): bool => !empty($frame['args'])),
            'The trace keeps the arguments, so the check means something.'
        );
        foreach (trace_strings::of($exception) as $argument) {
            $this->assertStringNotContainsString(self::key(), $argument);
        }
    }

    public function test_a_key_of_the_wrong_size_is_refused_and_not_left_in_the_trace(): void {
        $key = str_repeat("\x2a", 31);
        self::$wiped = [];
        try {
            new block_writer(
                $this->createMock(\moodle_database::class),
                $key,
                self::RUN,
                sodium_crypto_box_publickey(self::pair()),
                self::SITE,
                $this->dir
            );
            $this->fail('A key of 31 bytes was accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('32 bytes', $e->getMessage());
            foreach (trace_strings::of($e) as $argument) {
                $this->assertStringNotContainsString($key, $argument);
            }
            $this->assertNotEmpty($e->getTrace());
        }
        $this->assertContains($key, self::$wiped);
    }

    public function test_a_name_that_a_chunk_cannot_have_is_refused_and_the_trace_has_no_key(): void {
        $table = new \xmldb_table('Not-A-Name');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        try {
            $this->writer($this->stand_in([['id' => 1]]))->next_block($table, 0);
            $this->fail('A table name that a chunk cannot have was accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('table name', $e->getMessage());
            // Made again from the hint: the prefix of the message of Moodle is not repeated.
            $this->assertSame(1, substr_count($e->getMessage(), 'Coding error detected'));
            $this->assert_trace_hides_the_key($e);
        }
        $this->assertTrue($this->measured->closed);
        $this->assertTrue($this->fetched->closed);
        $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function test_the_key_is_wiped_when_the_writer_is_destroyed(): void {
        $writer = $this->writer($this->createMock(\moodle_database::class));
        self::$wiped = [];
        unset($writer);
        $this->assertSame([self::key()], self::$wiped);
    }

    public function test_the_writer_does_not_show_the_key(): void {
        $writer = $this->writer($this->createMock(\moodle_database::class));
        ob_start();
        var_dump($writer);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString(self::key(), $dump);
        $this->assertStringNotContainsString(bin2hex(self::key()), $dump);
        $this->assertStringContainsString(self::RUN, $dump);
    }

    public function test_the_writer_cannot_be_copied_or_serialised(): void {
        $writer = $this->writer($this->createMock(\moodle_database::class));
        self::$wiped = [];
        try {
            $copy = clone $writer;
            $this->fail('A writer was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('cloned', $e->getMessage());
        }
        // The refused copy kept no key: had it kept one, destroying it would have wiped that key (a copy of the same bytes).
        $this->assertSame([], self::$wiped);
        try {
            serialize($writer);
            $this->fail('A writer was serialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('serialised', $e->getMessage());
        }
        $class = block_writer::class;
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}');
            $this->fail('A writer was built from a string');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('unserialised', $e->getMessage());
        }
        // The refused copy did not wipe the key of the original.
        self::$wiped = [];
        unset($writer);
        $this->assertSame([self::key()], self::$wiped);
    }
}
