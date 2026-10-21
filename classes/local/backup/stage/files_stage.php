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
use tool_secbackup\local\backup\monitor;
use tool_secbackup\local\backup\run_context;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;

/**
 * The stage "files": the contents of the file store that the snapshot listed (the journal "files"), in the order of their
 * content hash.
 *
 * One packer per step. The small files wait in the packer for a pack; before a big file the packer is flushed, so that
 * whenever no small file waits, the entries of the packer are the entries of every file added since they were last taken, in
 * order. The point that a later step goes on from is the last file after which nothing waits: it is committed after every pack
 * and every piece, and a step that ends while small files wait for a pack that there is no room for leaves them to the next
 * step.
 *
 * A big file is handed out one piece at a time, so that the temporary folder holds one of its pieces beside what waits within
 * the budget: a first read checks the file against its content hash and takes the SHA-256 of every piece (packer::big_digests()),
 * then each piece is made after needs_drain(), checked against that read, queued, committed with the point to go on from (the
 * file in progress and its next piece are in the cursor), and watched. A piece that is not the one of the first read stops the
 * run (tamper_detected); a file that is gone when a piece is read is withdrawn (its entries are cut back, at once committed)
 * and listed in missing_files, and the pieces made before stay in the index.
 *
 * A chunk that the present list holds (same name, same size) is not sent and stays in the index; a big file whose pieces are
 * all in the present list is not even read: its entries are made from the list (a piece is the one item of its chunk, at
 * offset 86 and to the end of the chunk), and so is every single piece that the list holds. A content that is neither in the
 * file directory nor in the trash goes to missing_files.
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

    /** @var bool Whether small files wait in the packer. */
    private bool $pending = false;

    /** @var int The offset in the journal after the last file after which nothing waits. */
    private int $safe;

    /** @var array|null The big file in progress: hash, size, digests, piece (the next one) and entries (before the file). */
    private ?array $big;

    /** @var string Why the work of the step stopped: more or window. */
    private string $stop = 'more';

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
            $context->folder,
            // The speed of the reads is the speed of the file storage only for the file directory of Moodle.
            monitor::local_store() ? $context->readspeed : null
        );
        $this->safe = (int) ($context->cursor()['offset'] ?? 0);
        $this->big = $context->cursor()['big'] ?? null;
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
        $stage = new self($context, ($seams['filesource'])($DB));
        try {
            return $stage->go();
        } finally {
            // The stream of a big file in progress is opened again by the next step.
            $stage->source->close();
        }
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
                $outcome = $this->stop;
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
        $this->commit();
        return $outcome === 'next' ? 'more' : $outcome;
    }

    /**
     * Commits the point to go on from, with the big file in progress.
     */
    private function commit(): void {
        $this->context->commit(['offset' => $this->safe, 'big' => $this->big]);
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
        $this->stop = 'more';
        if ($size >= packer::FILE_LIMIT) {
            $flushed = !$this->pending || $this->flush($before);
            return $flushed && $this->add_big($hash, $size, $before, $after);
        }
        if (!$this->context->make_room(run_context::chunk_bound(packer::PACK_AT + packer::FILE_LIMIT))) {
            return false;
        }
        $chunks = $this->source->pack($hash, $size, $this->packer);
        if ($chunks === null) {
            $this->context->journal('missing_files')->append($hash);
        } else if ($chunks === []) {
            $this->pending = true;
        } else {
            $this->take($chunks, $after);
        }
        if (!$this->pending) {
            $this->safe = $after;
        }
        return true;
    }

    /**
     * Adds a big file, or goes on with it, one piece at a time.
     *
     * @param string $hash Content hash.
     * @param int $size Size in the files table.
     * @param int $before The offset in the journal before this content.
     * @param int $after The offset in the journal after this content.
     * @return bool False when the step stops in the middle of the file (no room, no time, or the end of the window).
     */
    private function add_big(string $hash, int $size, int $before, int $after): bool {
        $this->safe = $before;
        if ($this->big === null || $this->big['hash'] !== $hash) {
            // A file whose pieces are all stored asks for no room.
            $digests = $this->from_present($hash, $size) ? [] : $this->source->big_digests($hash, $size, $this->packer);
            if ($digests === null) {
                $this->context->journal('missing_files')->append($hash);
            }
            if ($digests === null || $digests === []) {
                $this->safe = $after;
                return true;
            }
            $this->big = ['hash' => $hash, 'size' => $size, 'digests' => $digests, 'piece' => 0,
                'entries' => $this->context->entries->commit()];
        }
        $present = $this->context->present();
        $start = $this->big['piece'];
        $pieces = count($this->big['digests']);
        for ($piece = $start; $piece < $pieces; $piece++) {
            if ($piece > $start && !$this->can_go_on()) {
                return false;
            }
            $name = chunk_name::file($this->context->dedup_key(), $hash, $piece);
            $stored = $present->size_of($name);
            if ($stored !== null && $stored > chunk_writer::HEADER) {
                $this->context->known($name, $stored);
                $this->context->add_entries([self::stored_entry($hash, $size, $name, $stored, $piece, $pieces)]);
                $chunk = null;
            } else {
                if (!$this->context->make_room(run_context::chunk_bound(packer::PIECE))) {
                    return false;
                }
                $chunk = $this->source->big_piece($hash, $size, $piece, $this->big['digests'][$piece], $this->packer);
                if ($chunk === null) {
                    // The file is gone since its first read: what was written of it is withdrawn, and committed at once, so
                    // that the entries are never fewer than the commit says.
                    $this->context->entries->truncate_to($this->big['entries']);
                    $this->context->journal('missing_files')->append($hash);
                    $this->big = null;
                    $this->safe = $after;
                    $this->commit();
                    return true;
                }
                $this->context->object($chunk);
                $this->context->add_entries($this->packer->take_entries());
            }
            $this->big['piece'] = $piece + 1;
            if ($piece + 1 === $pieces) {
                $this->big = null;
                $this->safe = $after;
            }
            // Committed before the watch, as after every pack.
            $this->commit();
            if ($chunk !== null) {
                $this->context->watch();
            }
        }
        $this->big = null;
        $this->safe = $after;
        return true;
    }

    /**
     * Whether the step may make another piece: it has time, and the window has not ended.
     *
     * @return bool
     */
    private function can_go_on(): bool {
        $this->stop = $this->context->window_passed() ? 'window' : 'more';
        return $this->stop === 'more' && !$this->context->out_of_time();
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
            $this->take($this->packer->flush(), $safe);
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
            $entries[] = self::stored_entry($hash, $size, $name, $stored, $piece++, $pieces);
        }
        $this->context->add_entries($entries);
        return true;
    }

    /**
     * The entry of a piece that the present list holds: the one item of its chunk, from offset 86 to the end of the chunk.
     *
     * @param string $hash Content hash.
     * @param int $size Size of the file.
     * @param string $name Name of the chunk.
     * @param int $stored Size of the chunk in the storage.
     * @param int $piece Number of the piece.
     * @param int $pieces Number of pieces.
     * @return array
     */
    private static function stored_entry(string $hash, int $size, string $name, int $stored, int $piece, int $pieces): array {
        return [
            't' => 'file',
            'contenthash' => $hash,
            'size' => $size,
            'chunk' => $name,
            'offset' => chunk_writer::HEADER,
            'length' => $stored - chunk_writer::HEADER,
            'piece' => $piece,
            'pieces' => $pieces,
        ];
    }

    /**
     * Takes the chunks that the packer finished, writes the entries that came with them, and commits when a pack was finished.
     *
     * Nothing waits in the packer once it has finished a chunk: its entries are then complete and in order, and the packer
     * forgets them. The commit after every pack keeps what a step that stops (or runs out of memory) loses to one pack.
     *
     * @param chunk_info[] $chunks The chunks.
     * @param int $safe The offset in the journal after the last file in them.
     */
    private function take(array $chunks, int $safe): void {
        foreach ($chunks as $chunk) {
            $this->context->object($chunk);
        }
        $this->pending = false;
        $this->context->add_entries($this->packer->take_entries());
        $this->safe = $safe;
        if ($chunks !== []) {
            $this->commit();
            $this->context->watch();
        }
    }
}
