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

namespace tool_secbackup\local\backup\stage;

use tool_secbackup\local\backup\db\block_writer;
use tool_secbackup\local\backup\db\connection_factory;
use tool_secbackup\local\backup\run_context;
use tool_secbackup\local\crypto\chunk_info;

/**
 * The stage "db": the whole database in one step, inside one snapshot (section 6.5 of the design of the plugin).
 *
 * When the expected size of the export fits in the budget of the temporary area beside what waits, the blocks stay in the
 * folder of the run until the snapshot ends, so that the transaction is open only while the database is read. Otherwise, and
 * as soon as the blocks that are held would pass the budget (the estimate was too small), a block is sent as soon as the next
 * one needs the room, and the transaction stays open while the data is sent; the watch looks at the response of the database
 * after every block.
 *
 * The snapshot also gives the list of the contents of the file store (the journal "files"): the files of a backup are those
 * of the files table of the snapshot (section 6.6).
 *
 * A step that cannot finish the stage (the uploader has to wait) gives it up: nothing is committed, and the next step reads
 * the database again in a new snapshot. The blocks that were queued are named by their content, so the same block of the
 * new snapshot is the same chunk.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class db_stage {
    /** @var int Contents of the file store that are listed at a time. */
    private const FILES_PAGE = 1000;

    /**
     * Runs the stage.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: tables, estimate, opendb and filesource.
     * @return string next, or more when the stage was given up for this step.
     */
    public static function run(run_context $context, array $seams): string {
        global $DB;
        $tables = ($seams['tables'])($context->settings->include_logs());
        // Measured with the connection of Moodle (size_estimate by default), before the snapshot: a query that fails inside a
        // snapshot of PostgreSQL breaks it.
        $estimate = ($seams['estimate'])($tables['export']);
        $budget = $context->temp->budget_bytes();
        $hold = $budget > 0 && $estimate > 0 && $estimate + $context->uploader->pending_bytes() <= $budget;
        $db = ($seams['opendb'])();
        $snapshot = connection_factory::snapshot_for($db);
        $context->hold_export($db, $snapshot);
        $snapshot->begin($db);
        self::list_files($context, ($seams['filesource'])($db));
        $writer = new block_writer(
            $db,
            $context->dedup_key(),
            $context->run_uuid(),
            $context->site_public_key(),
            $context->siteid,
            $context->folder
        );
        $rows = [];
        $bound = run_context::chunk_bound(block_writer::MAX_BYTES);
        foreach ($tables['export'] as $table) {
            $name = $table->getName();
            $rows[$name] = 0;
            $after = 0;
            while (true) {
                // Every block is queued as it is made (the queue only records), so the budget sees the blocks that are held:
                // an estimate that was too small ends the holding, and the blocks are sent while the database is read.
                $hold = $hold && !$context->uploader->needs_drain($bound);
                if (!$hold && !$context->make_room($bound)) {
                    return 'more';
                }
                $block = $writer->next_block($table, $after);
                if ($block === null) {
                    break;
                }
                $chunk = $block['chunk'];
                $context->known($chunk->name, $chunk->size);
                $context->add_entries([self::entry($name, $block, $chunk)]);
                $rows[$name] += $block['rows'];
                $context->object($chunk, false);
                $context->watch();
                $after = $block['to'];
            }
        }
        $context->end_export();
        // What is held is sent by requests of 256, and whenever the budget asks.
        $context->uploader->drain($context->run, false);
        $context->set('db', [
            'family' => $DB->get_dbfamily(),
            'tables' => array_keys($rows),
            'unknown_tables' => array_values($tables['unknown']),
            'excluded_tables' => array_values($tables['excluded']),
        ]);
        $context->set('tablerows', $rows);
        $context->next_stage('files');
        return 'next';
    }

    /**
     * Writes the contents of the file store, as the snapshot has them, to the journal "files".
     *
     * A content hash that is not a SHA-1 in lower-case hexadecimal names no content that Moodle can have stored: it is listed
     * as missing, so that a broken row of the table does not stop every step of every run.
     *
     * @param run_context $context The step.
     * @param \tool_secbackup\local\backup\files\file_source $source The list of the files, on the export connection.
     */
    private static function list_files(run_context $context, \tool_secbackup\local\backup\files\file_source $source): void {
        $journal = $context->journal('files');
        $missing = $context->journal('missing_files');
        $after = null;
        while (($page = $source->next($after, self::FILES_PAGE)) !== []) {
            foreach ($page as $content) {
                if (\tool_secbackup\local\backup\files\file_source::valid_hash($content['contenthash'])) {
                    $journal->append([$content['contenthash'], $content['size']]);
                } else {
                    $missing->append($content['contenthash']);
                }
                $after = $content['contenthash'];
            }
        }
    }

    /**
     * The entry of a block in the list of the generation.
     *
     * @param string $table The table, without the prefix.
     * @param array $block The block, as block_writer gives it.
     * @param chunk_info $chunk Its chunk.
     * @return array
     */
    private static function entry(string $table, array $block, chunk_info $chunk): array {
        return [
            't' => 'db',
            'table' => $table,
            'from' => $block['from'],
            'to' => $block['to'],
            'rows' => $block['rows'],
            'plainsha256' => $block['plainsha256'],
            'plainlength' => $block['length'],
            'chunk' => $chunk->name,
            'offset' => $chunk->items[0]['offset'],
            'length' => $chunk->items[0]['length'],
        ];
    }
}
