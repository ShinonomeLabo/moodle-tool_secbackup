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

namespace tool_secbackup\local\backup;

use tool_secbackup\local\api\client;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\dedup_key;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\plugin_exception;

/**
 * What one step of a run works with, and the way it goes on from the point that the step before committed.
 *
 * The state that survives between the steps is the record of the run (stage, progress, entries committed, counters) and the
 * folder of the run: the queue of the uploader, the entries of the list of the generation (entries.jsonl), the present list as
 * it was fetched (present.gz) and the journals (journal/*.jsonl). commit() is the one point that a later step goes on from: it
 * makes the files safe on the disk and then writes the stage, the progress and the number of entries in one update.
 *
 * When a step begins, everything that was written after the last commit is dropped: the entries and every journal are cut to
 * their committed figures, the chunks that were queued after it are taken out of the queue, and every file of the folder that
 * is not kept (the queue, the entries, the present list) and not waiting to be sent is removed. Chunks whose names are made
 * from their content (blocks of tables, pieces of files, packs) are queued as they are made, and may be sent before their
 * entries are committed. When their step stops before its commit, the step that goes on makes them again: the same name for
 * the same content, and another name for content that changed meanwhile (the rows of a table). What is guaranteed is that a
 * chunk that waited unsent in the queue of the stopped step is dropped, not sent. What is not: a chunk that the stopped step
 * sent already (a stage that cannot hold its chunks sends some before its commit, see make_room()) stays at the storage, and
 * when the step that goes on makes its content under another name, no list of a generation holds it until the retention ends.
 * Such chunks are bounded by what that step sent, and are not in the index of the run, so not in its stored bytes. A numbered
 * chunk of the rest of the data root or of code waits only after the commit that holds its entries (tosend), so that its
 * number is never used again for other content.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_context {
    /** @var string[] The journals of a run. */
    public const JOURNALS = ['files', 'chunks', 'missing_files', 'skipped_links', 'unreadable', 'core_modified', 'core_added',
        'core_deleted', 'core_send'];

    /** @var string[] The files of the folder of a run that are kept between the steps. */
    private const KEPT = ['uploads.json', 'entries.jsonl', 'present.gz'];

    /** @var string[] The stages in which a run makes chunks, and so stops at the end of a window. */
    private const WINDOW_STAGES = ['prepare', 'start', 'present', 'capacity', 'db', 'files', 'dataroot', 'code', 'manifest',
        'index'];

    /** @var int Seconds between two pauses of the watch, at least. */
    private const PAUSE_EVERY = 300;

    /** @var int Bytes that are allowed for the overhead of a chunk: its header, the frames and the padding. */
    private const CHUNK_SLACK = 1048576;

    /** @var run_record The run. */
    public readonly run_record $run;

    /** @var client The client of the service. */
    public readonly client $client;

    /** @var object_transport The transport of the storage. */
    public readonly object_transport $objects;

    /** @var uploader The uploader of the run. */
    public readonly uploader $uploader;

    /** @var temp_area The temporary area. */
    public readonly temp_area $temp;

    /** @var files\read_speed The speed of the reads of the file store in this step. */
    public readonly files\read_speed $readspeed;

    /** @var limits The limits. */
    public readonly limits $limits;

    /** @var settings The settings of the service. */
    public readonly settings $settings;

    /** @var string The folder of the run. */
    public readonly string $folder;

    /** @var manifest_builder The entries of the list of the generation. */
    public readonly manifest_builder $entries;

    /** @var endpoint The connection URL. */
    public readonly endpoint $endpoint;

    /** @var string The site's ID at the service. */
    public readonly string $siteid;

    /** @var signing_keypair The signing key pair of the site. */
    private signing_keypair $pair;

    /** @var string The X25519 public key of the site, which the chunks are encrypted for. */
    private string $sitepublickey;

    /** @var string|null The key for the names of the chunks, read when it is needed. */
    private ?string $dedupkey = null;

    /**
     * @var array The progress of the run: run, journals, entries (the committed lines and bytes of the entries, or null),
     *     cursor, tosend and commits (the number of commits so far).
     */
    private array $progress;

    /** @var journal[] The journals by name. */
    private array $journals = [];

    /** @var array The seams: clock, timer, sample, sleep, diskspace, windows. */
    private array $env;

    /** @var float When the budget of the step ends, by the timer. */
    private float $deadline;

    /** @var present_list|null The present list, read when it is needed. */
    private ?present_list $present = null;

    /** @var int[] The size of every chunk that this step made or found in the present list, by name. */
    private array $made = [];

    /** @var bool[] The chunks whose line is in the journal of chunks, from this step. */
    private array $lined = [];

    /** @var chunk_info[] Numbered chunks that wait for the next commit before they are queued. */
    private array $tosend = [];

    /** @var \moodle_database|null The export connection, while it is open. */
    private ?\moodle_database $exportdb = null;

    /** @var db\snapshot|null The snapshot of the export connection, while it is open. */
    private ?db\snapshot $snapshot = null;

    /** @var string|null A reason that the service gave for failing the run. */
    private ?string $failure = null;

    /**
     * Opens the folder of a run and goes on from the point that its last step committed.
     *
     * @param run_record $run The run.
     * @param client $client The client of the service.
     * @param object_transport $objects The transport of the storage.
     * @param temp_area $temp The temporary area.
     * @param limits $limits The limits.
     * @param callable $link Gives [endpoint, signing_keypair, site ID].
     * @param array $env clock, timer, sample, sleep, diskspace (or null), windows.
     * @param float $deadline When the budget of the step ends, by the timer.
     * @throws plugin_exception With temp_write_failed when the folder or its files cannot be used.
     */
    public function __construct(
        run_record $run,
        client $client,
        object_transport $objects,
        temp_area $temp,
        limits $limits,
        callable $link,
        array $env,
        float $deadline
    ) {
        $this->run = $run;
        $this->client = $client;
        $this->objects = $objects;
        $this->temp = $temp;
        $this->limits = $limits;
        $this->env = $env;
        $this->deadline = $deadline;
        $this->readspeed = new files\read_speed();
        $this->settings = settings::current();
        [$this->endpoint, $this->pair, $this->siteid] = $link();
        $this->sitepublickey = public_key_format::parse(state::load()->encryption_key());
        $this->folder = $temp->for_run($run->id());
        $progress = $run->progress();
        $this->progress = [
            'run' => array_diff_key(is_array($progress['run'] ?? null) ? $progress['run'] : [], ['lasterror' => true]),
            'journals' => is_array($progress['journals'] ?? null) ? $progress['journals'] : [],
            'entries' => is_array($progress['entries'] ?? null) ? $progress['entries'] : null,
            'cursor' => is_array($progress['cursor'] ?? null) ? $progress['cursor'] : [],
            'tosend' => is_array($progress['tosend'] ?? null) ? $progress['tosend'] : [],
            'commits' => is_int($progress['commits'] ?? null) ? $progress['commits'] : 0,
        ];
        $this->uploader = new uploader(
            $client,
            $objects,
            $temp,
            $limits,
            $link,
            $env['clock'],
            $this->settings->bandwidth_mbps(),
            fn(): int => max(0, (int) $this->get('estimate', 0) - $this->run->bytes_uploaded() - $this->uploader->pending_bytes())
        );
        $this->uploader->resume($run);
        $this->uploader->go_on_from($this->progress['commits']);
        $this->open_entries();
        $this->open_journals();
        $this->queue_waiting();
        $this->remove_leftovers();
    }

    /**
     * Opens the entries of the list of the generation and cuts them to the committed figures: by the bytes, without reading
     * the file, when the last commit holds them; else by counting the lines (a run that has not committed any).
     */
    private function open_entries(): void {
        $figures = $this->progress['entries'];
        if (
            $figures !== null && ($figures[0] ?? null) === $this->run->entries_committed() && is_int($figures[1] ?? null)
        ) {
            $this->entries = new manifest_builder($this->folder, $figures[0], $figures[1]);
            return;
        }
        $this->entries = new manifest_builder($this->folder);
        $this->entries->truncate_to($this->run->entries_committed());
    }

    /**
     * Opens the journals and cuts them to their committed figures.
     */
    private function open_journals(): void {
        global $CFG;
        $dir = $this->folder . '/journal';
        $mode = (int) $CFG->directorypermissions;
        if (!is_dir($dir) && !error_silencer::run(static fn() => mkdir($dir, $mode), $warning) && !is_dir($dir)) {
            throw new plugin_exception('temp_write_failed', null, 'journal_folder');
        }
        foreach (self::JOURNALS as $name) {
            $figures = $this->progress['journals'][$name] ?? [0, 0];
            $this->journals[$name] = new journal($dir . '/' . $name . '.jsonl', (int) $figures[0], (int) $figures[1]);
        }
    }

    /**
     * Queues the numbered chunks of the last commit that may not have been queued.
     */
    private function queue_waiting(): void {
        foreach ($this->progress['tosend'] as $item) {
            if (is_file($item['path']) && dirname($item['path']) === $this->folder) {
                $chunk = new chunk_info($item['path'], $item['name'], $item['size'], $item['sha256'], []);
                $this->uploader->queue($chunk, 'object', true);
            }
        }
    }

    /**
     * Removes the files that a stopped step left: in the folder of the run, every file that is neither kept nor waiting, and
     * in the temporary folder, the records of a present list.
     */
    private function remove_leftovers(): void {
        $keep = array_flip($this->uploader->queued_paths());
        foreach (error_silencer::run(fn() => scandir($this->folder), $warning) ?: [] as $name) {
            $path = $this->folder . '/' . $name;
            if (!in_array($name, self::KEPT, true) && !isset($keep[$path]) && is_file($path) && !is_link($path)) {
                error_silencer::run(static fn() => unlink($path), $warning);
            }
        }
        foreach (glob($this->limits->tempdir . '/present-*.records') ?: [] as $path) {
            error_silencer::run(static fn() => unlink($path), $warning);
        }
    }

    /**
     * The current UNIX time.
     *
     * @return int
     */
    public function now(): int {
        return (int) ($this->env['clock'])();
    }

    /**
     * Whether the budget of the step is used.
     *
     * @return bool
     */
    public function out_of_time(): bool {
        return ($this->env['timer'])() >= $this->deadline;
    }

    /**
     * A value of the run that the stages keep between the steps.
     *
     * @param string $key The name.
     * @param mixed $default The value when there is none.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed {
        return array_key_exists($key, $this->progress['run']) ? $this->progress['run'][$key] : $default;
    }

    /**
     * Sets a value of the run; it is stored at the next commit.
     *
     * @param string $key The name.
     * @param mixed $value A value without a URL.
     */
    public function set(string $key, mixed $value): void {
        $this->progress['run'][$key] = $value;
    }

    /**
     * Where the stage goes on from.
     *
     * @return array
     */
    public function cursor(): array {
        return $this->progress['cursor'];
    }

    /**
     * A journal.
     *
     * @param string $name One of JOURNALS.
     * @return journal
     */
    public function journal(string $name): journal {
        return $this->journals[$name];
    }

    /**
     * Commits what the step did, and queues the numbered chunks of this commit.
     *
     * @param array $cursor Where the stage goes on from.
     * @param string|null $stage The stage to move to, or null to stay.
     * @throws plugin_exception With temp_write_failed when a file cannot be made safe, and with capacity_refused when the run
     *     has more chunks than an index can hold.
     */
    public function commit(array $cursor, ?string $stage = null): void {
        $entries = $this->entries->commit();
        $this->progress['entries'] = [$entries, $this->entries->bytes()];
        foreach ($this->journals as $name => $journal) {
            $this->progress['journals'][$name] = [$journal->commit(), $journal->bytes()];
        }
        if ($this->journals['chunks']->lines() > index_builder::MAX_COUNT) {
            throw new plugin_exception('capacity_refused', null, 'index_count');
        }
        $this->progress['cursor'] = $cursor;
        $this->progress['tosend'] = array_map(static fn(chunk_info $c): array => [
            'name' => $c->name, 'path' => $c->path, 'size' => $c->size, 'sha256' => $c->sha256base64,
        ], $this->tosend);
        $this->progress['commits']++;
        $this->run->commit($stage ?? $this->run->stage(), $this->progress, $entries, $this->now());
        $this->uploader->committed($this->progress['commits']);
        foreach ($this->tosend as $chunk) {
            $this->uploader->queue($chunk, 'object', true);
        }
        $this->tosend = [];
    }

    /**
     * Commits and moves to the next stage, which starts from its beginning.
     *
     * @param string $stage The stage.
     */
    public function next_stage(string $stage): void {
        $this->commit([], $stage);
    }

    /**
     * Makes room for a chunk: sends what waits when the chunk would not fit beside it in the budget.
     *
     * @param int $bytes The most bytes of the chunk that is about to be made.
     * @return bool False when what waits could not be sent (the uploader waits, and has paused the run): no chunk is made.
     */
    public function make_room(int $bytes): bool {
        if (!$this->uploader->needs_drain($bytes)) {
            return true;
        }
        $this->uploader->drain($this->run, true);
        return !$this->uploader->needs_drain($bytes);
    }

    /**
     * The most bytes of a chunk that holds a payload of a size.
     *
     * @param int $payload Bytes of the payload.
     * @return int
     */
    public static function chunk_bound(int $payload): int {
        return $payload + intdiv($payload, 8) + self::CHUNK_SLACK;
    }

    /**
     * Takes a chunk whose name is made from its content: it is sent, or removed when the present list has it already.
     *
     * @param chunk_info $chunk The chunk.
     * @param bool $lookup Whether the present list can have it (chunks whose names hold the run never are).
     */
    public function object(chunk_info $chunk, bool $lookup = true): void {
        $this->made[$chunk->name] = $chunk->size;
        if ($lookup && $this->present()->size_of($chunk->name) === $chunk->size) {
            $path = $chunk->path;
            error_silencer::run(static fn() => unlink($path), $warning);
            return;
        }
        $this->uploader->queue($chunk, 'object');
    }

    /**
     * Takes a numbered chunk: it is queued after the next commit.
     *
     * @param chunk_info $chunk The chunk.
     */
    public function numbered(chunk_info $chunk): void {
        $this->made[$chunk->name] = $chunk->size;
        $this->tosend[] = $chunk;
    }

    /**
     * Notes the size of a chunk that the present list holds and that is not made again.
     *
     * @param string $name The name of the chunk.
     * @param int $size Its size.
     */
    public function known(string $name, int $size): void {
        $this->made[$name] = $size;
    }

    /**
     * Writes entries of the list of the generation, and the line of every chunk that they refer to the first time.
     *
     * @param array[] $entries The entries; each refers to a chunk that this step made or noted.
     */
    public function add_entries(array $entries): void {
        foreach ($entries as $entry) {
            $this->entries->append($entry);
            $chunk = $entry['chunk'];
            if (!isset($this->lined[$chunk])) {
                $this->journals['chunks']->append([$chunk, $this->made[$chunk]]);
                $this->lined[$chunk] = true;
            }
        }
    }

    /**
     * Looks at the server after a chunk was made: stops the run when a limit is passed, and waits when a value cannot be
     * read (at most once in five minutes of the run: the time of the last wait is kept with the run at once, so that the next
     * step, which may be another process, knows it).
     *
     * Once the step has read files of the file store of one MiB or more, their speed is the speed of the file storage, and the
     * monitor does not read a file of its own for it (the page cache of the server answers that one).
     *
     * @throws plugin_exception With the reason (server_busy, disk_low, db_slow or nfs_slow) when a limit is passed.
     */
    public function watch(): void {
        $measured = $this->readspeed->mbps();
        $sample = ($this->env['sample'])($measured === null);
        if ($measured !== null) {
            $sample['nfs_mbps'] = $measured;
            $sample['not_applicable'] = array_values(array_diff($sample['not_applicable'] ?? [], ['nfs_mbps']));
        }
        $reason = monitor::verdict($sample, $this->limits);
        if ($reason !== null) {
            throw new plugin_exception($reason);
        }
        $seconds = monitor::pause_seconds($sample);
        $last = $this->get('pauseat');
        if ($seconds > 0 && ($last === null || $this->now() - (int) $last >= self::PAUSE_EVERY)) {
            $this->set('pauseat', $this->now());
            $this->run->keep(['pauseat' => $this->now()]);
            ($this->env['sleep'])($seconds);
        }
    }

    /**
     * Whether the window of the run has ended while the run still makes chunks.
     *
     * @return bool
     */
    public function window_passed(): bool {
        $end = $this->get('window_end');
        return $this->env['windows'] && $end !== null && in_array($this->run->stage(), self::WINDOW_STAGES, true)
            && $this->now() >= (int) $end;
    }

    /**
     * Pauses the run until a time.
     *
     * @param int $until UNIX time.
     */
    public function wait(int $until): void {
        $this->run->pause($this->now(), $until);
    }

    /**
     * The present list of the run; empty when the storage had none or its address was lost.
     *
     * @return present_list
     * @throws plugin_exception With signature_invalid when the list is not this site's or not in its form, and with disk_low or
     *     temp_write_failed when its records cannot be written.
     */
    public function present(): present_list {
        $this->present ??= present_list::load(
            $this->folder . '/present.gz',
            $this->siteid,
            $this->limits->tempdir,
            $this->env['diskspace']
        );
        return $this->present;
    }

    /**
     * The key for the names of the chunks.
     *
     * @return string
     */
    public function dedup_key(): string {
        $this->dedupkey ??= dedup_key::load_or_create(dedup_key::owner($this->siteid, $this->sitepublickey));
        return $this->dedupkey;
    }

    /**
     * The signing key pair of the site.
     *
     * @return signing_keypair
     */
    public function pair(): signing_keypair {
        return $this->pair;
    }

    /**
     * The X25519 public key of the site.
     *
     * @return string
     */
    public function site_public_key(): string {
        return $this->sitepublickey;
    }

    /**
     * The UUID of the run at the service.
     *
     * @return string
     */
    public function run_uuid(): string {
        return (string) $this->run->run_uuid();
    }

    /**
     * Keeps the export connection, so that a stop closes it.
     *
     * @param \moodle_database $db The connection.
     * @param db\snapshot $snapshot Its snapshot.
     */
    public function hold_export(\moodle_database $db, db\snapshot $snapshot): void {
        $this->exportdb = $db;
        $this->snapshot = $snapshot;
    }

    /**
     * Ends the transaction of the export and closes its connection, if they are open. It never throws: the export only
     * reads, so a transaction that cannot be ended loses nothing, and its connection ends with the process anyway. An error
     * here would carry this context (and the keys it holds) in the arguments of its trace, so it is not kept.
     */
    public function end_export(): void {
        if ($this->exportdb === null) {
            return;
        }
        $db = $this->exportdb;
        $snapshot = $this->snapshot;
        $this->exportdb = null;
        $this->snapshot = null;
        try {
            $snapshot->end($db);
        } catch (\Throwable $e) {
            unset($e);
        }
        try {
            $db->dispose();
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Records the reason with which the service failed the run.
     *
     * @param string $code The reason.
     */
    public function set_failure(string $code): void {
        $this->failure = $code;
    }

    /**
     * The reason with which the service failed the run.
     *
     * @return string|null
     */
    public function failure(): ?string {
        return $this->failure;
    }

    /**
     * Closes the files of the step. The export connection is closed too.
     *
     * It never throws, for the reason that end_export() gives: what the step committed is in the table and in the files
     * already, and a file that cannot be closed is closed with the process.
     */
    public function close(): void {
        $this->end_export();
        $closers = [fn() => $this->present?->close()];
        foreach ($this->journals as $journal) {
            $closers[] = static fn() => $journal->close();
        }
        foreach ($closers as $close) {
            try {
                $close();
            } catch (\Throwable $e) {
                unset($e);
            }
        }
    }

    /**
     * Keeps the keys out of print_r(), var_dump() and debugging output.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['run' => $this->run->id(), 'stage' => $this->run->stage()];
    }
}
