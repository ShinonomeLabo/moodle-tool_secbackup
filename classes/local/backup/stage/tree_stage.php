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

use tool_secbackup\local\backup\core_diff;
use tool_secbackup\local\backup\run_context;
use tool_secbackup\local\backup\series_writer;
use tool_secbackup\local\backup\tree_walker;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * The stages "dataroot" and "code": the files of folders, packed into numbered chunks.
 *
 * The sources of a stage are walked one after the other: for "dataroot" the data root (without what Moodle can build again)
 * and the secret folder when it is elsewhere; for "code" the folder of every added plugin, then the Moodle program. The
 * program is compared once with the list of the official files: only the files that were changed or added are sent, the
 * deleted ones are listed (without the paths that could not be read, which would otherwise be taken for deleted). Without a
 * list to compare with (none for this build, or one of another build) the whole program is sent, and the list of the
 * generation says that it was not compared.
 *
 * One tree_walker per source and one series_writer per step; the writer goes on with the number that the last step
 * committed. A chunk is queued after the commit that holds the entries of its files, and the commit is made as soon as a
 * chunk is finished. The point to go on from is the last file whose entry is written; the files that wait in the open chunk
 * when a step ends without room for it are added again by the next step (their numbers are not used again). The links and
 * the names that the walker could not read are taken after every page; a step that goes on may list a name again, and the
 * list of the generation has each name once.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tree_stage {
    /** @var int Files that are asked of a walker at a time. */
    private const PAGE = 200;

    /** @var run_context The step. */
    private run_context $context;

    /** @var array The seams of the runner. */
    private array $seams;

    /** @var series_writer The writer of the step. */
    private series_writer $writer;

    /** @var array[] The sources: name, folder, what is left out, and how the files are listed (walk, diff or full). */
    private array $sources;

    /** @var array The point to go on from: source (index), position (a path, or an offset in the journal core_send). */
    private array $safe;

    /** @var array[] The files that were added and whose entries are not written: source, position, root and path. */
    private array $waiting = [];

    /** @var int Entries of the writer that are written. */
    private int $drained = 0;

    /** @var int Names of the writer that could not be read and are written. */
    private int $unread = 0;

    /** @var array|null The big file in progress: source, path, digests, piece (the next one), entries (before the file). */
    private ?array $big;

    /** @var string Why the work of the step stopped: more or window. */
    private string $stop = 'more';

    /**
     * Constructor.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner.
     * @param string $label dataroot or code.
     * @param array[] $sources The sources.
     */
    private function __construct(run_context $context, array $seams, string $label, array $sources) {
        $this->context = $context;
        $this->seams = $seams;
        $this->sources = $sources;
        $cursor = $context->cursor();
        $this->safe = ['source' => (int) ($cursor['source'] ?? 0), 'position' => $cursor['position'] ?? null];
        $this->big = $cursor['big'] ?? null;
        $this->writer = new series_writer(
            $context->dedup_key(),
            $label,
            $context->run_uuid(),
            $context->site_public_key(),
            $context->siteid,
            $context->folder,
            (int) ($cursor['next'] ?? 0)
        );
    }

    /**
     * Runs the stage "dataroot".
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: datarootroots.
     * @return string next, more or window.
     */
    public static function dataroot(run_context $context, array $seams): string {
        $sources = [];
        foreach (($seams['datarootroots'])() as $name => [$folder, $excluded]) {
            $sources[] = ['name' => $name, 'folder' => $folder, 'excluded' => $excluded, 'kind' => 'walk'];
        }
        return (new self($context, $seams, 'dataroot', $sources))->go('code');
    }

    /**
     * Runs the stage "code".
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: coderoots and corediff.
     * @return string next, more or window.
     */
    public static function code(run_context $context, array $seams): string {
        $sources = [];
        foreach (($seams['coderoots'])() as $component => $folder) {
            $sources[] = ['name' => $component, 'folder' => $folder, 'excluded' => [], 'kind' => 'walk'];
        }
        $sources[] = ['name' => 'core', 'folder' => null, 'excluded' => [], 'kind' => 'core'];
        return (new self($context, $seams, 'code', $sources))->go('manifest');
    }

    /**
     * Adds the files from the committed point on.
     *
     * @param string $nextstage The stage after this one.
     * @return string next, more or window.
     */
    private function go(string $nextstage): string {
        $outcome = 'next';
        $first = true;
        for ($index = $this->safe['source']; $index < count($this->sources) && $outcome === 'next'; $index++) {
            $position = $index === $this->safe['source'] ? $this->safe['position'] : null;
            foreach ($this->files($index, $position) as $file) {
                if (!$first && $this->context->out_of_time()) {
                    $outcome = 'more';
                    break;
                }
                if ($this->context->window_passed()) {
                    $outcome = 'window';
                    break;
                }
                $first = false;
                $this->withdraw_unless($index, $file['path']);
                if (!$this->add($index, $file)) {
                    $outcome = $this->stop;
                    break;
                }
            }
            if ($outcome === 'next') {
                // The source has ended: a file in progress that it did not give again is gone.
                $this->withdraw_unless($index, null);
            }
            if ($outcome === 'next' && $this->waiting === []) {
                $this->safe = ['source' => $index + 1, 'position' => null];
            }
        }
        if (
            $outcome !== 'window' && $this->waiting !== []
            && $this->context->make_room(run_context::chunk_bound(series_writer::PIECE))
        ) {
            $this->take($this->writer->flush());
            if ($outcome === 'next') {
                $this->safe = ['source' => count($this->sources), 'position' => null];
            }
        }
        if ($outcome === 'next' && $this->waiting === []) {
            $this->context->next_stage($nextstage);
            return 'next';
        }
        $this->commit();
        return $outcome === 'next' ? 'more' : $outcome;
    }

    /**
     * The files of a source after a position.
     *
     * @param int $index The source.
     * @param mixed $position The path (or the offset in the journal core_send) after which the files are given; null for all.
     * @return \Generator<array> ['root', 'base', 'path', 'position'] for each file.
     */
    private function files(int $index, mixed $position): \Generator {
        $source = $this->sources[$index];
        if ($source['kind'] === 'core') {
            $core = $this->core();
            $base = $core['diff']->root();
            if ($core['official']) {
                foreach ($this->context->journal('core_send')->read((int) $position) as $next => $path) {
                    $size = error_silencer::run(static fn() => filesize($base . '/' . $path), $warning);
                    yield ['root' => 'core', 'base' => $base, 'path' => $path, 'position' => $next, 'size' => (int) $size];
                }
                return;
            }
            yield from $this->walk($core['diff']->walker(), 'core', $base, $position);
            return;
        }
        $walker = new tree_walker($source['folder'], $source['excluded']);
        yield from $this->walk($walker, $source['name'], $source['folder'], $position);
    }

    /**
     * The files of a walk, page by page, with the links and the names that could not be read taken after every page.
     *
     * @param tree_walker $walker The walker.
     * @param string $root The name of the root.
     * @param string $base Its folder.
     * @param string|null $after The path after which the files are given.
     * @return \Generator<array>
     */
    private function walk(tree_walker $walker, string $root, string $base, ?string $after): \Generator {
        $skipped = 0;
        $unreadable = 0;
        while (true) {
            $page = $walker->next($after, self::PAGE);
            foreach (array_slice($walker->skipped(), $skipped) as $name) {
                $this->context->journal('skipped_links')->append($root . ':' . $name);
            }
            foreach (array_slice($walker->unreadable(), $unreadable) as $name) {
                $this->context->journal('unreadable')->append($root . ':' . $name);
            }
            $skipped = count($walker->skipped());
            $unreadable = count($walker->unreadable());
            if ($page === []) {
                return;
            }
            foreach ($page as $item) {
                $after = $item['path'];
                yield [
                    'root' => $root, 'base' => $base, 'path' => $item['path'], 'position' => $item['path'], 'size' => $item['size'],
                ];
            }
        }
    }

    /**
     * Adds one file.
     *
     * @param int $index The source.
     * @param array $file The file.
     * @return bool False when the step stops before the file is done (no room, no time, or the end of the window).
     */
    private function add(int $index, array $file): bool {
        $this->stop = 'more';
        if (
            $this->big !== null && $file['size'] < series_writer::FILE_LIMIT
            && $this->big['source'] === $index && $this->big['path'] === $file['path']
        ) {
            // The big file in progress is small now: what was written of it is cut back (its pieces stay in the index), and it
            // is taken whole as a small file, so it is in the entries once and not listed as unreadable.
            $this->context->entries->truncate_to($this->big['entries']);
            $this->big = null;
            $this->commit();
        }
        if ($file['size'] >= series_writer::FILE_LIMIT) {
            if ($this->waiting !== []) {
                // The open chunk goes first, so that nothing else waits while the big file is in progress.
                if (!$this->context->make_room(run_context::chunk_bound(series_writer::PIECE))) {
                    return false;
                }
                $this->take($this->writer->flush());
            }
            return $this->add_big($index, $file);
        }
        if (!$this->context->make_room(run_context::chunk_bound(series_writer::PIECE))) {
            return false;
        }
        $this->waiting[] = ['source' => $index, 'position' => $file['position'], 'root' => $file['root'],
            'path' => $file['path']];
        $this->take($this->writer->add($file['root'], $file['base'], $file['path'], true));
        return true;
    }

    /**
     * Adds a big file, or goes on with it, one piece at a time: each piece is committed with its entry before its chunk is
     * queued.
     *
     * @param int $index The source.
     * @param array $file The file.
     * @return bool False when the step stops in the middle of the file.
     */
    private function add_big(int $index, array $file): bool {
        if ($this->big === null) {
            $digests = $this->writer->big_digests($file['root'], $file['base'], $file['path']);
            if ($digests === null) {
                $this->take([]);
                $this->safe = ['source' => $index, 'position' => $file['position']];
                return true;
            }
            $this->big = ['source' => $index, 'path' => $file['path'], 'digests' => $digests, 'piece' => 0,
                'entries' => $this->context->entries->commit()];
        }
        $start = $this->big['piece'];
        $pieces = count($this->big['digests']['pieces']);
        for ($piece = $start; $piece < $pieces; $piece++) {
            if ($piece > $start && !$this->can_go_on()) {
                return false;
            }
            if (!$this->context->make_room(run_context::chunk_bound(series_writer::PIECE))) {
                return false;
            }
            $chunk = $this->writer->big_piece($file['root'], $file['base'], $file['path'], $this->big['digests'], $piece);
            if ($chunk === null) {
                // Committed at once, so that the entries are never fewer than the commit says.
                $this->withdraw($file['root'] . ':' . $file['path']);
                $this->safe = ['source' => $index, 'position' => $file['position']];
                $this->commit();
                return true;
            }
            $this->context->numbered($chunk);
            $this->drain_entries();
            $this->big['piece'] = $piece + 1;
            if ($piece + 1 === $pieces) {
                $this->big = null;
                $this->safe = ['source' => $index, 'position' => $file['position']];
            }
            $this->commit();
            $this->context->watch();
        }
        $this->safe = ['source' => $index, 'position' => $file['position']];
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
     * Withdraws the big file in progress unless it is the file that comes now: a file that a source does not give again
     * (removed since its first pieces) is listed as unreadable.
     *
     * @param int $index The source of the file that comes now.
     * @param string|null $path Its path; null when the source has ended.
     */
    private function withdraw_unless(int $index, ?string $path): void {
        if ($this->big === null || ($this->big['source'] === $index && $this->big['path'] === $path)) {
            return;
        }
        if ($this->big['source'] <= $index) {
            $this->withdraw($this->sources[$this->big['source']]['name'] . ':' . $this->big['path']);
            $this->commit();
        }
    }

    /**
     * Withdraws the big file in progress: its entries are cut back, it is listed as unreadable, and its pieces stay in the
     * index (they are, or will be, stored), referred to by no entry.
     *
     * @param string $name Its root, a colon and its path.
     */
    private function withdraw(string $name): void {
        $this->context->entries->truncate_to($this->big['entries']);
        $this->context->journal('unreadable')->append($name);
        $this->big = null;
    }

    /**
     * Writes the entries that the writer has made since the last time.
     */
    private function drain_entries(): void {
        $entries = $this->writer->entries();
        $this->context->add_entries(array_slice($entries, $this->drained));
        $this->drained = count($entries);
    }

    /**
     * Takes the chunks that the writer finished and the entries and names that came with them, and commits when a chunk was
     * finished.
     *
     * @param \tool_secbackup\local\crypto\chunk_info[] $chunks The chunks.
     */
    private function take(array $chunks): void {
        foreach ($chunks as $chunk) {
            $this->context->numbered($chunk);
        }
        $done = [];
        $entries = $this->writer->entries();
        $new = array_slice($entries, $this->drained);
        $this->drain_entries();
        foreach ($new as $entry) {
            $done[$entry['root'] . ':' . $entry['path']] = true;
        }
        $unreadable = $this->writer->unreadable();
        foreach (array_slice($unreadable, $this->unread) as $name) {
            $this->context->journal('unreadable')->append($name);
            $done[$name] = true;
        }
        $this->unread = count($unreadable);
        foreach ($this->waiting as $i => $file) {
            if (isset($done[$file['root'] . ':' . $file['path']])) {
                $this->waiting[$i]['done'] = true;
            }
        }
        while ($this->waiting !== [] && !empty($this->waiting[0]['done'])) {
            $file = array_shift($this->waiting);
            $this->safe = ['source' => $file['source'], 'position' => $file['position']];
        }
        if ($chunks !== []) {
            // Committed before the watch: a step stopped by the watch does not use the numbers again.
            $this->commit();
            $this->context->watch();
        }
    }

    /**
     * Commits the point to go on from, with the next number of the writer and the big file in progress.
     */
    private function commit(): void {
        $this->context->commit($this->safe + ['next' => $this->writer->next_number(), 'big' => $this->big]);
    }

    /**
     * The comparison of the Moodle program with the official list, made once in a run and kept in the journals.
     *
     * @return array ['diff' => core_diff, 'official' => bool]
     */
    private function core(): array {
        $diff = ($this->seams['corediff'])();
        $known = $this->context->get('core');
        if ($known !== null) {
            return ['diff' => $diff, 'official' => (bool) $known['official']];
        }
        $result = null;
        try {
            $official = $diff->fetch_official(
                $this->context->client,
                $this->context->endpoint,
                $this->context->pair(),
                $this->context->siteid,
                $this->context->objects,
                $this->context->folder
            );
            $result = $official === null ? null : $diff->compare($official, $diff->local_hashes());
        } catch (plugin_exception $e) {
            if (!in_array($e->errorcode, ['core_manifest_mismatch', 'core_manifest_invalid'], true)) {
                throw $e;
            }
        }
        if ($result !== null) {
            $this->record_diff($diff, $result);
        }
        $this->context->set('core', ['official' => $result !== null]);
        $this->commit();
        return ['diff' => $diff, 'official' => $result !== null];
    }

    /**
     * Writes the differences with the official list to the journals.
     *
     * @param core_diff $diff The comparison.
     * @param array $result What compare() found.
     */
    private function record_diff(core_diff $diff, array $result): void {
        $unlisted = $diff->unlisted();
        foreach ($unlisted as $path) {
            $this->context->journal('unreadable')->append('core:' . $path);
        }
        foreach (['modified', 'added'] as $kind) {
            foreach ($result[$kind] as $path) {
                $this->context->journal('core_' . $kind)->append($path);
            }
        }
        $send = array_merge($result['modified'], $result['added']);
        sort($send, SORT_STRING);
        foreach ($send as $path) {
            $this->context->journal('core_send')->append($path);
        }
        foreach ($result['deleted'] as $path) {
            if (!self::under_any($path, $unlisted)) {
                $this->context->journal('core_deleted')->append($path);
            }
        }
    }

    /**
     * Whether a path is one of some paths or under one of them.
     *
     * @param string $path The path.
     * @param string[] $paths Paths that could not be read; "." is the whole root.
     * @return bool
     */
    private static function under_any(string $path, array $paths): bool {
        foreach ($paths as $other) {
            if ($other === '.' || $path === $other || str_starts_with($path, $other . '/')) {
                return true;
            }
        }
        return false;
    }
}
