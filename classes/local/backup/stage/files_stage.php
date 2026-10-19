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

use tool_secbackup\local\backup\files\file_source;
use tool_secbackup\local\backup\files\packer;
use tool_secbackup\local\backup\run_context;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;

/**
 * The stage "files": the contents of the file store that the snapshot listed (the journal "files"), in the order of their
 * content hash.
 *
 * One packer per step. The small files wait in the packer for a pack; before a big file the packer is flushed, so that
 * whenever no small file waits, the entries of the packer are the entries of every file added so far, in order. The point that
 * a later step goes on from is the last file after which nothing waits: a step that ends while small files wait for a pack
 * that there is no room for leaves them to the next step.
 *
 * A chunk that the present list holds (same name, same size) is not sent and stays in the index; a big file whose pieces are
 * all in the present list is not even read: its entries are made from the list (a piece is the one item of its chunk, at
 * offset 86 and to the end of the chunk). A content that is neither in the file directory nor in the trash goes to
 * missing_files.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class files_stage {
    /** @var run_context The step. */
    private run_context $context;

    /** @var packer The packer of the step. */
    private packer $packer;

    /** @var file_source Where the contents are read from. */
    private file_source $source;

    /** @var int Entries of the packer that are written. */
    private int $drained = 0;

    /** @var bool Whether small files wait in the packer. */
    private bool $pending = false;

    /** @var int The offset in the journal after the last file after which nothing waits. */
    private int $safe;

    /**
     * Constructor.
     *
     * @param run_context $context The step.
     * @param file_source $source Where the contents are read from.
     */
    private function __construct(run_context $context, file_source $source) {
        $this->context = $context;
        $this->source = $source;
        $this->packer = new packer(
            $context->dedup_key(),
            $context->run_uuid(),
            $context->site_public_key(),
            $context->siteid,
            $context->folder
        );
        $this->safe = (int) ($context->cursor()['offset'] ?? 0);
    }

    /**
     * Runs the stage.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: filesource.
     * @return string next, more (the budget is used, or what waits could not be sent) or window.
     */
    public static function run(run_context $context, array $seams): string {
        global $DB;
        return (new self($context, ($seams['filesource'])($DB)))->go();
    }

    /**
     * Adds the contents from the committed point on.
     *
     * @return string next, more or window.
     */
    private function go(): string {
        $outcome = 'next';
        $first = true;
        $last = $this->safe;
        foreach ($this->context->journal('files')->read($this->safe) as $next => [$hash, $size]) {
            if (!$first && $this->context->out_of_time()) {
                $outcome = 'more';
                break;
            }
            if ($this->context->window_passed()) {
                $outcome = 'window';
                break;
            }
            $first = false;
            if (!$this->add($hash, (int) $size, $last, $next)) {
                $outcome = 'more';
                break;
            }
            $last = $next;
        }
        if ($this->pending && $outcome !== 'window') {
            $this->flush($last);
        }
        if ($outcome === 'next' && !$this->pending) {
            $this->context->next_stage('dataroot');
            return 'next';
        }
        $this->context->commit(['offset' => $this->safe]);
        return $outcome === 'next' ? 'more' : $outcome;
    }

    /**
     * Adds one content.
     *
     * @param string $hash Content hash.
     * @param int $size Size in the files table.
     * @param int $before The offset in the journal before this content.
     * @param int $after The offset in the journal after this content.
     * @return bool False when there was no room for it: it is left to the next step.
     */
    private function add(string $hash, int $size, int $before, int $after): bool {
        $big = $size >= packer::FILE_LIMIT;
        // The pack that waits goes first, alone, so that a budget of zero holds one chunk at a time.
        $flushed = !$big || !$this->pending || $this->flush($before);
        $bytes = $big ? $size : packer::PACK_AT + packer::FILE_LIMIT;
        if (!$flushed || !$this->context->make_room(run_context::chunk_bound($bytes))) {
            return false;
        }
        if ($big && $this->from_present($hash, $size)) {
            $this->safe = $after;
            return true;
        }
        $chunks = $this->source->pack($hash, $size, $this->packer);
        if ($chunks === null) {
            $this->context->journal('missing_files')->append($hash);
        } else if ($chunks === []) {
            $this->pending = true;
        } else {
            $this->take($chunks);
        }
        if (!$this->pending) {
            $this->safe = $after;
        }
        return true;
    }

    /**
     * Writes the small files that wait as a pack, when there is room for it.
     *
     * @param int $safe The offset in the journal after the last of them.
     * @return bool Whether there was room.
     */
    private function flush(int $safe): bool {
        $room = $this->context->make_room(run_context::chunk_bound(packer::PACK_AT + packer::FILE_LIMIT));
        if ($room) {
            $this->take($this->packer->flush());
            $this->safe = $safe;
        }
        return $room;
    }

    /**
     * Makes the entries of a big file from the present list, when the list holds every piece of it.
     *
     * @param string $hash Content hash.
     * @param int $size Size.
     * @return bool Whether it did.
     */
    private function from_present(string $hash, int $size): bool {
        $present = $this->context->present();
        $pieces = intdiv($size + packer::PIECE - 1, packer::PIECE);
        $sizes = [];
        for ($piece = 0; $piece < $pieces; $piece++) {
            $name = chunk_name::file($this->context->dedup_key(), $hash, $piece);
            $stored = $present->size_of($name);
            if ($stored === null || $stored <= chunk_writer::HEADER) {
                return false;
            }
            $sizes[$name] = $stored;
        }
        $entries = [];
        $piece = 0;
        foreach ($sizes as $name => $stored) {
            $this->context->known($name, $stored);
            $entries[] = [
                't' => 'file',
                'contenthash' => $hash,
                'size' => $size,
                'chunk' => $name,
                'offset' => chunk_writer::HEADER,
                'length' => $stored - chunk_writer::HEADER,
                'piece' => $piece++,
                'pieces' => $pieces,
            ];
        }
        $this->context->add_entries($entries);
        return true;
    }

    /**
     * Takes the chunks that the packer finished, and writes the entries that came with them.
     *
     * Nothing waits in the packer once it has finished a chunk: its entries are then complete and in order.
     *
     * @param chunk_info[] $chunks The chunks.
     */
    private function take(array $chunks): void {
        foreach ($chunks as $chunk) {
            $this->context->object($chunk);
        }
        $this->pending = false;
        $entries = $this->packer->entries();
        $this->context->add_entries(array_slice($entries, $this->drained));
        $this->drained = count($entries);
        if ($chunks !== []) {
            $this->context->watch();
        }
    }
}
