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

use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Imports the blocks of the database that the extractor wrote into the database of the restored Moodle, in place of the rows
 * that its install made, each row with its id.
 *
 * The staging folder holds db/manifest.json, {"v": 1, "family", "tables": {<table>: rows}, "blocks": [{"file", "table",
 * "from", "to", "rows", "plainsha256"}]}, and the blocks that it lists, db/<number>.jsonl.gz: the plain text of block_writer of
 * the backup, compressed with gzip. Its first line is {"table", "columns": [{"name", "type"}], "from", "to"} and each other
 * line a row, a JSON array of text or null in the order of the columns, with the value of a binary column in base64. The names
 * of the tables have no prefix, and the queries name a table only as {table}: the rows go to the tables of the restored site
 * under its own prefix, whatever prefix the backed-up site had.
 *
 * Each table that the manifest lists is emptied, gets the rows of its blocks with their ids (import_record()), and gets its
 * sequence set after its largest id (reset_sequence()), so that the rows that the restored site makes later have larger ids.
 * A table that the manifest lists with no rows is emptied. A table of the database that the manifest does not list is left
 * as it is.
 *
 * Not restored, so that the plugin of the restored site keeps its own state: the tables tool_secbackup_* (their blocks are not
 * read, and the site need not have them), and the rows of the plugin in config_plugins (plugin = 'tool_secbackup') and in
 * task_adhoc (component = 'tool_secbackup'). Those of the blocks are left out; those of the database are kept, and one whose
 * id is not above the largest id of the blocks of its table first moves after the largest id of the table, so that the rows
 * of the blocks keep their ids.
 *
 * Nothing is written before the manifest, the tables and the first line of every block have been checked against the
 * database. A block is read as a stream, one line at a time, right after the SHA-256 of its plain text is checked again.
 *
 * The import can run again on the same staging folder, after a success or after a failure on the way, and gives the rows of
 * one run: it keeps no state of its own, empties every table before it writes it, and moves only the rows of the plugin that
 * are not yet after the ids of the blocks. So it needs no transaction, and uses none: on MySQL and MariaDB a transaction around
 * a large table would keep the undo of all its rows, and reset_sequence() there changes the table, which ends a transaction.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class db_importer {
    /** @var string The component whose rows are not restored. */
    private const COMPONENT = 'tool_secbackup';

    /** @var string The start of the names of the tables that are not restored. */
    private const OWN_TABLES = 'tool_secbackup_';

    /** @var string[] The tables that hold rows of the plugin, with the column that names the component of a row. */
    private const KEPT = ['config_plugins' => 'plugin', 'task_adhoc' => 'component'];

    /** @var string[] The members of the manifest. */
    private const MANIFEST = ['v', 'family', 'tables', 'blocks'];

    /** @var string[] The members of a block of the manifest. */
    private const BLOCK = ['file', 'table', 'from', 'to', 'rows', 'plainsha256'];

    /** @var string[] The members of the first line of a block. */
    private const HEADER = ['table', 'columns', 'from', 'to'];

    /** @var string[] The members of a column of the first line. */
    private const COLUMN = ['name', 'type'];

    /** @var string Pattern of a name of a table or of a column. */
    private const NAME = '/\A[a-z0-9_]+\z/';

    /** @var string Pattern of the name of the file of a block. */
    private const FILE = '/\A[1-9][0-9]*\.jsonl\.gz\z/';

    /** @var string Pattern of a SHA-256 in hexadecimal. */
    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    /** @var string Pattern of an id. */
    private const ID = '/\A[1-9][0-9]*\z/';

    /** @var int Bytes of plain text read at a time for the SHA-256 of a block. */
    private const READ = 65536;

    /** @var string The staging folder. */
    private string $stagingdir;

    /** @var \moodle_database The database of the restored site. */
    private \moodle_database $db;

    /** @var \Closure Takes the rows done and the rows in all. */
    private \Closure $progress;

    /**
     * Constructor.
     *
     * @param string $stagingdir The staging folder that the extractor wrote, an absolute path.
     * @param \moodle_database $db The database of the restored site.
     * @param \Closure $progress Called as function (int $done, int $total): void, where total is the sum of the rows of the
     *     tables of the manifest: with none done when the checks have passed, then after each row of a block that is read
     *     (imported or left out), and once after each block that is not read, with its rows.
     * @throws plugin_exception With restore_input_invalid when the staging folder is not an absolute path.
     */
    public function __construct(string $stagingdir, \moodle_database $db, \Closure $progress) {
        if (!str_starts_with($stagingdir, '/') || str_contains($stagingdir, "\0")) {
            throw new plugin_exception('restore_input_invalid');
        }
        $this->stagingdir = $stagingdir;
        $this->db = $db;
        $this->progress = $progress;
    }

    /**
     * Imports the blocks.
     *
     * @return int[] tables: the tables written (emptied and filled), rows: the rows imported.
     * @throws plugin_exception With restore_input_invalid when the manifest is not in its form, restore_import_count_mismatch
     *     when the rows of a block or of a table are not the ones that the manifest says, restore_import_schema_mismatch when
     *     the site does not have a table or a column of a block or has a column that a block must give, and
     *     restore_content_mismatch when a block cannot be read, is not in its form or has another SHA-256 than the manifest
     *     says. Nothing is written before a manifest not in its form, blocks that do not add up to the rows of their table, a
     *     table or a column that does not match, or a block whose first line cannot be read or is not in its form.
     * @throws import_failed_exception When the database refuses a step.
     */
    public function import(): array {
        [$counts, $blocks] = $this->manifest();
        $table = '';
        try {
            $existing = $this->db->get_tables(false);
            foreach (array_keys($counts) as $table) {
                if (!self::own($table) && !isset($existing[$table])) {
                    throw new plugin_exception('restore_import_schema_mismatch', $table);
                }
            }
            foreach ($blocks as $table => $list) {
                foreach (self::own($table) ? [] : $list as $block) {
                    $this->columns($table, $block, $this->first_line($block));
                }
            }
            $total = array_sum($counts);
            $done = 0;
            ($this->progress)($done, $total);
            $written = 0;
            $imported = 0;
            foreach ($blocks as $table => $list) {
                if (self::own($table)) {
                    foreach ($list as $block) {
                        $done += $block['rows'];
                        ($this->progress)($done, $total);
                    }
                    continue;
                }
                $imported += $this->write_table($table, $list, $done, $total);
                $written++;
            }
        } catch (\dml_exception | \ddl_exception $e) {
            throw new import_failed_exception($e, $table);
        }
        return ['tables' => $written, 'rows' => $imported];
    }

    /**
     * Whether a table is one of the plugin, which is not restored.
     *
     * @param string $table The table.
     * @return bool
     */
    private static function own(string $table): bool {
        return str_starts_with($table, self::OWN_TABLES);
    }

    /**
     * Reads and checks db/manifest.json.
     *
     * @return array The rows by table, in the order of the manifest, and the blocks by table, each in the order of the
     *     manifest, with a list for every table.
     * @throws plugin_exception With restore_input_invalid when the file cannot be read or is not in its form, and
     *     restore_import_count_mismatch when the rows of the blocks of a table do not add up to the rows of the table.
     */
    private function manifest(): array {
        $path = $this->stagingdir . '/db/manifest.json';
        $text = error_silencer::run(static fn() => file_get_contents($path), $warning);
        try {
            $manifest = json_decode((string) $text, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $manifest = null;
        }
        if (
            !is_array($manifest) || !self::has_members($manifest, self::MANIFEST) || $manifest['v'] !== 1
            || !is_string($manifest['family']) || !is_array($manifest['tables'])
            || !is_array($manifest['blocks']) || !array_is_list($manifest['blocks'])
        ) {
            throw new plugin_exception('restore_input_invalid', null, 'manifest');
        }
        $blocks = [];
        foreach ($manifest['tables'] as $table => $rows) {
            if (!is_string($table) || preg_match(self::NAME, $table) !== 1 || !is_int($rows) || $rows < 0) {
                throw new plugin_exception('restore_input_invalid', null, 'tables');
            }
            $blocks[$table] = [];
        }
        foreach ($manifest['blocks'] as $block) {
            if (!self::block_ok($block) || !isset($blocks[$block['table']])) {
                throw new plugin_exception('restore_input_invalid', null, 'blocks');
            }
            $blocks[$block['table']][] = $block;
        }
        foreach ($blocks as $table => $list) {
            if (array_sum(array_column($list, 'rows')) !== $manifest['tables'][$table]) {
                throw new plugin_exception('restore_import_count_mismatch', null, $table);
            }
        }
        return [$manifest['tables'], $blocks];
    }

    /**
     * Whether a block of the manifest is in its form.
     *
     * @param mixed $block The block.
     * @return bool
     */
    private static function block_ok(mixed $block): bool {
        return is_array($block) && self::has_members($block, self::BLOCK)
            && is_string($block['file']) && preg_match(self::FILE, $block['file']) === 1
            && is_string($block['table']) && preg_match(self::NAME, $block['table']) === 1
            && is_int($block['from']) && is_int($block['to']) && is_int($block['rows']) && $block['rows'] > 0
            && is_string($block['plainsha256']) && preg_match(self::SHA256, $block['plainsha256']) === 1;
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
     * Opens the file of a block.
     *
     * @param array $block The block.
     * @return resource The file, for the gz functions.
     * @throws plugin_exception With restore_content_mismatch when it cannot be opened.
     */
    private function open(array $block) {
        $path = $this->stagingdir . '/db/' . $block['file'];
        $handle = error_silencer::run(static fn() => gzopen($path, 'rb'), $warning);
        if ($handle === false) {
            throw new plugin_exception('restore_content_mismatch', null, $block['file']);
        }
        return $handle;
    }

    /**
     * The first line of a block, before its SHA-256 is checked.
     *
     * @param array $block The block.
     * @return string|false The line, or false when the block has none.
     * @throws plugin_exception With restore_content_mismatch when the block cannot be opened.
     */
    private function first_line(array $block): string|false {
        $handle = $this->open($block);
        $line = error_silencer::run(static fn() => gzgets($handle), $warning);
        gzclose($handle);
        return $line;
    }

    /**
     * Checks the first line of a block and its columns against the table.
     *
     * @param string $table The table.
     * @param array $block The block.
     * @param string|false $line The first line of the block, or false for none.
     * @return array[] The columns, in order: name and type each.
     * @throws plugin_exception With restore_content_mismatch when the line is not in its form, and with
     *     restore_import_schema_mismatch, which names the table and the column, when the table does not have a column of the
     *     block, a column is binary in one and not in the other, or the block does not have a column that the table cannot
     *     fill: one that cannot be null and has no default, or the one that names the component of a row of the plugin.
     */
    private function columns(string $table, array $block, string|false $line): array {
        try {
            $header = json_decode((string) $line, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $header = null;
        }
        if (!self::header_ok($header, $table, $block)) {
            throw new plugin_exception('restore_content_mismatch', null, $block['file']);
        }
        $target = $this->db->get_columns($table);
        foreach ($header['columns'] as $column) {
            $info = $target[$column['name']] ?? null;
            if ($info === null || ($column['type'] === 'binary') !== ($info->meta_type === 'B')) {
                throw new plugin_exception('restore_import_schema_mismatch', $table . '.' . $column['name']);
            }
        }
        $required = static fn(\database_column_info $info): bool => $info->not_null && !$info->has_default;
        $needed = array_keys(array_filter($target, $required));
        if (isset(self::KEPT[$table])) {
            $needed[] = self::KEPT[$table];
        }
        $missing = array_diff($needed, array_column($header['columns'], 'name'));
        if ($missing !== []) {
            throw new plugin_exception('restore_import_schema_mismatch', $table . '.' . reset($missing));
        }
        return $header['columns'];
    }

    /**
     * Whether the first line of a block is in its form: of the table and the ids of the block, with columns whose names are
     * names, one of them id, none twice.
     *
     * @param mixed $header The first line, decoded.
     * @param string $table The table of the block.
     * @param array $block The block.
     * @return bool
     */
    private static function header_ok(mixed $header, string $table, array $block): bool {
        if (
            !is_array($header) || !self::has_members($header, self::HEADER) || $header['table'] !== $table
            || $header['from'] !== $block['from'] || $header['to'] !== $block['to']
            || !is_array($header['columns']) || !array_is_list($header['columns'])
        ) {
            return false;
        }
        $names = [];
        foreach ($header['columns'] as $column) {
            if (
                !is_array($column) || !self::has_members($column, self::COLUMN) || !is_string($column['name'])
                || preg_match(self::NAME, $column['name']) !== 1 || !is_string($column['type'])
            ) {
                return false;
            }
            $names[] = $column['name'];
        }
        return in_array('id', $names, true) && count(array_unique($names)) === count($names);
    }

    /**
     * Empties a table and imports the rows of its blocks.
     *
     * @param string $table The table, which is not one of the plugin.
     * @param array[] $blocks Its blocks, in order.
     * @param int $done The rows done, which goes up.
     * @param int $total The rows in all.
     * @return int The rows imported.
     * @throws plugin_exception As import() does.
     */
    private function write_table(string $table, array $blocks, int &$done, int $total): int {
        $kept = self::KEPT[$table] ?? null;
        if ($kept === null) {
            $this->db->delete_records($table);
        } else {
            $this->move_kept_rows($table, $kept, max([0, ...array_column($blocks, 'to')]));
            $this->db->delete_records_select($table, $kept . ' <> ?', [self::COMPONENT]);
        }
        $imported = 0;
        foreach ($blocks as $block) {
            $imported += $this->write_block($table, $block, $kept, $done, $total);
        }
        $this->db->get_manager()->reset_sequence($table);
        $count = $kept === null
            ? $this->db->count_records($table)
            : $this->db->count_records_select($table, $kept . ' <> ?', [self::COMPONENT]);
        if ($count !== $imported) {
            throw new plugin_exception('restore_import_count_mismatch', null, $table);
        }
        return $imported;
    }

    /**
     * Moves the rows of the plugin whose ids the blocks may have after the largest id of the table, in the order of their ids.
     *
     * @param string $table The table.
     * @param string $column The column that names the component of a row.
     * @param int $last The largest id of the blocks of the table.
     */
    private function move_kept_rows(string $table, string $column, int $last): void {
        $ids = $this->db->get_fieldset_sql(
            'SELECT id FROM {' . $table . '} WHERE ' . $column . ' = ? AND id <= ? ORDER BY id',
            [self::COMPONENT, $last]
        );
        $next = max($last, (int) $this->db->get_field_sql('SELECT MAX(id) FROM {' . $table . '}'));
        foreach ($ids as $id) {
            $this->db->execute('UPDATE {' . $table . '} SET id = ? WHERE id = ?', [++$next, $id]);
        }
    }

    /**
     * Imports the rows of a block, after its SHA-256 is checked again.
     *
     * @param string $table The table.
     * @param array $block The block.
     * @param string|null $kept The column that names the component of a row of the plugin, or null for a table without them.
     * @param int $done The rows done, which goes up.
     * @param int $total The rows in all.
     * @return int The rows imported: those of the block but the ones of the plugin.
     * @throws plugin_exception As import() does.
     */
    private function write_block(string $table, array $block, ?string $kept, int &$done, int $total): int {
        $handle = $this->open($block);
        try {
            self::check_content($handle, $block);
            $columns = $this->columns($table, $block, gzgets($handle));
            $rows = 0;
            $imported = 0;
            while (($line = gzgets($handle)) !== false) {
                if (++$rows > $block['rows']) {
                    throw new plugin_exception('restore_import_count_mismatch', null, $table);
                }
                $record = self::record($line, $columns, $block['file']);
                if ($kept === null || $record[$kept] !== self::COMPONENT) {
                    $this->db->import_record($table, $record);
                    $imported++;
                }
                ($this->progress)(++$done, $total);
            }
            if ($rows !== $block['rows']) {
                throw new plugin_exception('restore_import_count_mismatch', null, $table);
            }
        } finally {
            gzclose($handle);
        }
        return $imported;
    }

    /**
     * Checks that the plain text of a block has the SHA-256 of the manifest, then goes back to its start.
     *
     * @param resource $handle The file of the block.
     * @param array $block The block.
     * @throws plugin_exception With restore_content_mismatch when it has another one or cannot be read to its end.
     */
    private static function check_content($handle, array $block): void {
        $hash = hash_init('sha256');
        $read = static fn() => gzread($handle, self::READ);
        while (is_string($bytes = error_silencer::run($read, $warning)) && $bytes !== '') {
            hash_update($hash, $bytes);
        }
        if (!hash_equals($block['plainsha256'], hash_final($hash)) || !gzrewind($handle)) {
            throw new plugin_exception('restore_content_mismatch', null, $block['file']);
        }
    }

    /**
     * The record of a line of a block.
     *
     * @param string $line The line.
     * @param array[] $columns The columns of the block.
     * @param string $file The file of the block.
     * @return array The values by column, of a binary column decoded.
     * @throws plugin_exception With restore_content_mismatch when the line is not a row of the columns: a JSON array of as
     *     many values, each text or null, the binary ones base64 and the id a positive number.
     */
    private static function record(string $line, array $columns, string $file): array {
        try {
            // The values of a row are not arrays or objects: a deeper line is not a row.
            $values = json_decode($line, false, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $values = null;
        }
        if (!is_array($values) || count($values) !== count($columns)) {
            throw new plugin_exception('restore_content_mismatch', null, $file);
        }
        $record = [];
        foreach ($columns as $number => $column) {
            $value = $values[$number];
            if ($value !== null && !is_string($value)) {
                throw new plugin_exception('restore_content_mismatch', null, $file);
            }
            if ($value !== null && $column['type'] === 'binary') {
                $value = base64_decode($value, true);
                if ($value === false) {
                    throw new plugin_exception('restore_content_mismatch', null, $file);
                }
            }
            $record[$column['name']] = $value;
        }
        if (!is_string($record['id']) || preg_match(self::ID, $record['id']) !== 1) {
            throw new plugin_exception('restore_content_mismatch', null, $file);
        }
        return $record;
    }
}
