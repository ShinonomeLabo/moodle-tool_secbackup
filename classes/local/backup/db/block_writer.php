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

namespace tool_secbackup\local\backup\db;

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;

/**
 * Writes the rows of a table as blocks: encrypted chunks of type 1 with the rows in JSON Lines, compressed.
 *
 * The plain text of a block: a first line {"table", "columns": [{"name", "type"}], "from", "to"} and one line, a JSON array
 * in the order of the columns, for each row. The columns are those of the install.xml description of the table, in its
 * order, and the type is the name that XMLDB gives to the type of the column: int, number, float, char, text, binary and
 * datetime, or unknown for a type that XMLDB has no name for. Every value is written as the text that the database layer
 * gives (a number that a driver gives as a number is made into text); NULL is null; the value of a binary column is a base64
 * string; the value of any other type is written as text, as it is.
 *
 * The text is compressed with gzip (level 6) and is the one item of the chunk.
 *
 * A block has at most MAX_ROWS rows and at most MAX_BYTES of plain text, but always one row at least: a row that is larger
 * than MAX_BYTES is a block of its own. The rows are in the order of their id; a block starts after the id that the caller
 * gives, so the caller asks again with the last id of the block until there are no rows.
 *
 * A block is read in two steps, so that what the driver holds is the block and not the page of 5,000 rows: a query measures
 * the rows after the id (the sizes of their columns, in bytes, on the server), the writer chooses the last row whose
 * running total is within MAX_BYTES less the room for the first line, and a second query fetches the rows up to that id.
 * The size of the lines, which JSON escapes and base64 make longer than the bytes, still decides the cut.
 *
 * Invalid UTF-8 in a row is not replaced: the JSON encoding throws, so the run stops rather than saves changed data.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_writer {
    /** @var int Most rows in a block. */
    public const MAX_ROWS = 5000;

    /** @var int Most bytes of plain text in a block, when it has more than one row (8 MiB). */
    public const MAX_BYTES = 8388608;

    /** @var int Level of the compression. */
    private const GZIP_LEVEL = 6;

    /** @var int Flags of every JSON encoding. */
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var string The mark that MySQL and MariaDB put around the name of a column (a grave accent). */
    private const MYSQL_QUOTE = "\x60";

    /** @var int Fewest rows that a measuring query asks for, after a block. */
    private const MIN_MEASURE = 16;

    /** @var int Bytes that the measuring query counts for a column that is not text, characters or bytes. */
    private const FIXED_COLUMN = 24;

    /** @var \moodle_database The connection of the export, inside its snapshot. */
    private \moodle_database $db;

    /** @var string|null The key for the names of the chunks, until the object is destroyed. */
    private ?string $dedupkey;

    /** @var string Name of the table that the last block was of. */
    private string $lasttable = '';

    /** @var int How many rows the next measuring query asks for. */
    private int $measurelimit = self::MAX_ROWS;

    /** @var string UUID of the run. */
    private string $runuuid;

    /** @var string Public key of the site, for encrypting. */
    private string $sitepublickey;

    /** @var string ID of the site. */
    private string $siteid;

    /** @var string Folder of the chunks. */
    private string $tempdir;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The connection of the export; the caller has started its snapshot.
     * @param #[|\SensitiveParameter|]|string $dedupkey The key for the names of the chunks (32 bytes).
     * @param string $runuuid UUID of the run.
     * @param string $sitepublickey Public key of the site (32 bytes).
     * @param string $siteid ID of the site.
     * @param string $tempdir Existing folder for the chunks.
     * @throws \coding_exception When the key does not have 32 bytes.
     */
    public function __construct(
        \moodle_database $db,
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
        $this->db = $db;
        $this->runuuid = $runuuid;
        $this->sitepublickey = $sitepublickey;
        $this->siteid = $siteid;
        $this->tempdir = $tempdir;
    }

    /**
     * The sizes of the rows after an id, as the database measures them.
     *
     * @param \xmldb_table $table The table, as its install.xml file describes it; it has an id column.
     * @param int $afterid The rows with a larger id.
     * @param int $limit The most rows to measure.
     * @return int[] Bytes by id, in the order of the ids.
     */
    private function measure(\xmldb_table $table, int $afterid, int $limit): array {
        $rows = $this->db->get_recordset_sql($this->measuring_sql($table, $this->fields($table)), [$afterid], 0, $limit);
        try {
            $sizes = [];
            foreach ($rows as $row) {
                $sizes[(int)$row->id] = (int)$row->sz;
            }
        } finally {
            $rows->close();
        }
        return $sizes;
    }

    /**
     * The last id of a block, from the sizes of the rows.
     *
     * The rows are counted from the first until the next one would take the total over the room; the first row is always
     * counted.
     *
     * @param int[] $sizes Bytes by id, in the order of the ids.
     * @param int $room Bytes that the rows of a block may have.
     * @return int|null The id of the last row, or null when there are no rows.
     */
    public static function choose_end(array $sizes, int $room): ?int {
        $total = 0;
        $end = null;
        foreach ($sizes as $id => $size) {
            if ($end !== null && $total + $size > $room) {
                break;
            }
            $total += $size;
            $end = $id;
        }
        return $end;
    }

    /**
     * Writes the next block of a table.
     *
     * @param \xmldb_table $table The table, as its install.xml file describes it; it has an id column.
     * @param int $afterid The block holds the rows with a larger id: 0 for the first block, the "to" of the last block after.
     * @return array|null The block: 'chunk' (chunk_info of the file, which has the name of the chunk in tempdir), 'from' and
     *     'to' (first and last id), 'rows', 'plainsha256' (hexadecimal) and 'length' (bytes) of the plain text before the
     *     compression. Null when no row has a larger id; then no file is made.
     * @throws \coding_exception For a table without an id column, or a name that a chunk cannot have.
     * @throws \JsonException For a value that is not valid UTF-8; the message tells the table and the id, not the value.
     * @throws \tool_secbackup\local\plugin_exception With temp_write_failed when the chunk cannot be written.
     */
    public function next_block(\xmldb_table $table, int $afterid): ?array {
        $fields = $this->fields($table);
        if ($table->getName() !== $this->lasttable || $afterid === 0) {
            $this->lasttable = $table->getName();
            $this->measurelimit = self::MAX_ROWS;
        }
        $reserve = strlen($this->header($table, $fields, PHP_INT_MAX, PHP_INT_MAX));
        $sizes = $this->measure($table, $afterid, $this->measurelimit);
        $end = self::choose_end($sizes, self::MAX_BYTES - $reserve);
        if ($end === null) {
            return null;
        }
        // The next block is about as large as this one: it needs to measure about as many rows, and no more than a page.
        $window = array_search($end, array_keys($sizes), true) + 1;
        $this->measurelimit = max(self::MIN_MEASURE, min(self::MAX_ROWS, 2 * $window));
        $length = $reserve;
        $lines = [];
        $first = null;
        $last = null;
        $rows = $this->db->get_recordset_sql(
            'SELECT ' . $this->column_list($fields) . ' FROM {' . $table->getName() . '} WHERE id > ? AND id <= ? ORDER BY id',
            [$afterid, $end]
        );
        try {
            foreach ($rows as $row) {
                try {
                    $line = $this->line($fields, $row);
                } catch (\JsonException) {
                    // New, from the id: the trace of the exception of json_encode() holds the values of the row.
                    throw new \JsonException('A value of ' . $table->getName() . ' id ' . (int)$row->id . ' is not valid text.');
                }
                if ($lines !== [] && $length + strlen($line) > self::MAX_BYTES) {
                    break;
                }
                $lines[] = $line;
                $length += strlen($line);
                $last = (int)$row->id;
                $first ??= $last;
            }
        } finally {
            $rows->close();
        }
        if ($lines === []) {
            // The rows were measured, so they are in the snapshot: they cannot be gone, and null would mean that the table is done.
            throw new \coding_exception('Rows that were measured are gone: the snapshot is broken.');
        }
        $count = count($lines);
        $plain = $this->header($table, $fields, $first, $last) . implode('', $lines);
        unset($lines);
        $sha256 = hash('sha256', $plain);
        $chunk = $this->write($table->getName(), $first, $last, $sha256, $plain);
        return [
            'chunk' => $chunk,
            'from' => $first,
            'to' => $last,
            'rows' => $count,
            'plainsha256' => $sha256,
            'length' => strlen($plain),
        ];
    }

    /**
     * The columns of a table to export.
     *
     * @param \xmldb_table $table The table.
     * @return \xmldb_field[]
     * @throws \coding_exception For a table without an id column.
     */
    private function fields(\xmldb_table $table): array {
        if ($table->getField('id') === null) {
            throw new \coding_exception('A table to export has an id column.');
        }
        return $table->getFields();
    }

    /**
     * A name of a column the way the database writes it, so that a reserved word (such as order) can be one.
     *
     * @param string $name The name.
     * @return string
     */
    private function quoted(string $name): string {
        return match ($this->db->get_dbfamily()) {
            'mysql' => self::MYSQL_QUOTE . $name . self::MYSQL_QUOTE,
            'postgres' => '"' . $name . '"',
            default => $name,
        };
    }

    /**
     * The columns of a table, in order, for a query.
     *
     * @param \xmldb_field[] $fields The columns.
     * @return string
     */
    private function column_list(array $fields): string {
        return implode(', ', array_map(fn(\xmldb_field $field): string => $this->quoted($field->getName()), $fields));
    }

    /**
     * The query that measures the rows: the id and the size in bytes of the data of the row.
     *
     * The size is the sum of the bytes of the columns of characters, text and binary data (null counts 0) and a fixed
     * allowance for every other column. It is a decimal number, so that a sum of large columns cannot overflow an integer.
     * OCTET_LENGTH counts bytes on MySQL, MariaDB and PostgreSQL, and PostgreSQL does not need to read a stored large value
     * for it.
     *
     * @param \xmldb_table $table The table.
     * @param \xmldb_field[] $fields The columns.
     * @return string
     */
    private function measuring_sql(\xmldb_table $table, array $fields): string {
        $sizes = ['0.0'];
        foreach ($fields as $field) {
            $variable = in_array($field->getType(), [XMLDB_TYPE_CHAR, XMLDB_TYPE_TEXT, XMLDB_TYPE_BINARY], true);
            $column = $this->quoted($field->getName());
            $sizes[] = $variable ? 'COALESCE(OCTET_LENGTH(' . $column . '), 0)' : (string)self::FIXED_COLUMN;
        }
        return 'SELECT id, (' . implode(' + ', $sizes) . ') AS sz FROM {' . $table->getName() . '} WHERE id > ? ORDER BY id';
    }

    /**
     * Encrypts the plain text of a block into its file.
     *
     * @param string $table Name of the table.
     * @param int $from First id.
     * @param int $to Last id.
     * @param string $sha256 SHA-256 of the plain text, in hexadecimal.
     * @param #[|\SensitiveParameter|]|string $plain The plain text.
     * @return chunk_info
     */
    private function write(
        string $table,
        int $from,
        int $to,
        string $sha256,
        #[\SensitiveParameter] string $plain
    ): chunk_info {
        try {
            $name = chunk_name::db($this->dedupkey, $this->runuuid, $table, $from, $to, $sha256);
        } catch (\coding_exception $e) {
            // Made again from the hint: the trace of the exception from chunk_name holds the key as an argument on PHP before
            // 8.2, and its message is not made into a hint again with its prefix.
            throw new \coding_exception($e->a);
        }
        $compressed = gzencode($plain, self::GZIP_LEVEL) ?: throw new \coding_exception('The block cannot be compressed.');
        $path = $this->tempdir . '/' . $name;
        $writer = new chunk_writer($path, chunk_writer::TYPE_DB, $name, $this->sitepublickey, $this->siteid);
        try {
            $writer->add_item($compressed, strlen($compressed));
            return $writer->finish();
        } catch (\Throwable $e) {
            $writer->abort();
            throw $e;
        }
    }

    /**
     * The first line of a block.
     *
     * @param \xmldb_table $table The table.
     * @param \xmldb_field[] $fields Its columns.
     * @param int $from First id.
     * @param int $to Last id.
     * @return string The line, with its line feed.
     */
    private function header(\xmldb_table $table, array $fields, int $from, int $to): string {
        $columns = array_map(static fn(\xmldb_field $field): array => [
            'name' => $field->getName(),
            'type' => $field->getXMLDBTypeName($field->getType()) ?: 'unknown',
        ], $fields);
        return json_encode(
            ['table' => $table->getName(), 'columns' => array_values($columns), 'from' => $from, 'to' => $to],
            self::JSON
        ) . "\n";
    }

    /**
     * The line of a row.
     *
     * @param \xmldb_field[] $fields The columns.
     * @param \stdClass $row The row.
     * @return string The line, with its line feed.
     */
    private function line(array $fields, \stdClass $row): string {
        $values = [];
        foreach ($fields as $field) {
            $value = $row->{$field->getName()};
            if ($value === null) {
                $values[] = null;
            } else if ($field->getType() === XMLDB_TYPE_BINARY) {
                $values[] = base64_encode((string)$value);
            } else {
                $values[] = (string)$value;
            }
        }
        return json_encode($values, self::JSON) . "\n";
    }

    /**
     * Wipes the key.
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
        throw new \coding_exception('A block writer must not be cloned.');
    }

    /**
     * Refuses to be serialised: the key would be kept.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A block writer must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A block writer must not be unserialised.');
    }
}
