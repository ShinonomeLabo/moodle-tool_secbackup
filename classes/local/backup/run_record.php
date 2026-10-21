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

/**
 * The persistent record of one backup run: its stage, progress and counters.
 *
 * A run takes many cron invocations. Every step that moves it forward is written to the table
 * tool_secbackup_runs first, so that the next invocation, possibly on another server, can continue.
 * The table must never hold a secret, so a progress that contains a URL is refused.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_record {
    /** @var string[] The stages of a run, in order. */
    public const STAGES = ['prepare', 'start', 'present', 'capacity', 'db', 'files', 'dataroot', 'code', 'manifest',
        'index', 'complete', 'receipt', 'done'];

    /** @var string[] What can start a run. */
    private const TRIGGERS = ['schedule', 'manual', 'cli'];

    /** @var string The table. */
    private const TABLE = 'tool_secbackup_runs';

    /** @var string[] The statuses of a run that goes on. */
    private const LIVE = ['active', 'paused'];

    /** @var \stdClass The row of the table. */
    private \stdClass $row;

    /**
     * Constructor.
     *
     * @param \stdClass $row A row of the table.
     */
    private function __construct(\stdClass $row) {
        $this->row = $row;
    }

    /**
     * Starts a new run.
     *
     * The column is called triggeredby because TRIGGER is a reserved word of MySQL and MariaDB.
     *
     * @param string $trigger What started the run: schedule, manual or cli.
     * @param int $now The current time.
     * @return self
     */
    public static function create(string $trigger, int $now): self {
        global $DB;
        if (!in_array($trigger, self::TRIGGERS, true)) {
            throw new \coding_exception('Unknown trigger of a backup run');
        }
        $row = (object) [
            'runuuid' => null,
            'kind' => 'full',
            'triggeredby' => $trigger,
            'stage' => self::STAGES[0],
            'status' => 'active',
            'abortcode' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'timefinished' => null,
            'nextattempt' => null,
            'windowmisses' => 0,
            'hostname' => null,
            'progress' => null,
            'entriescommitted' => 0,
            'bytesuploaded' => 0,
            'chunksuploaded' => 0,
            'estimatedbytes' => null,
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);
        return new self($row);
    }

    /**
     * The latest run that is active or paused.
     *
     * A run that the service refused to start (status refused) is neither: it did not start.
     *
     * @return self|null
     */
    public static function active(): ?self {
        return self::first('status IN (?, ?)', self::LIVE);
    }

    /**
     * The latest run that completed.
     *
     * @return self|null
     */
    public static function last_completed(): ?self {
        return self::first('status = ?', ['completed']);
    }

    /**
     * The run that has an ID at the backup service.
     *
     * @param string $uuid The UUID of the run at the service.
     * @return self|null
     */
    public static function find_by_uuid(string $uuid): ?self {
        return self::first('runuuid = ?', [$uuid]);
    }

    /**
     * The latest runs, newest first.
     *
     * @param int $limit How many to return at most.
     * @return self[]
     */
    public static function recent(int $limit): array {
        global $DB;
        if ($limit < 1) {
            return [];
        }
        $rows = $DB->get_records(self::TABLE, null, 'id DESC', '*', 0, $limit);
        return array_values(array_map(static fn($row) => new self($row), $rows));
    }

    /**
     * How many runs were aborted since the last run that completed.
     *
     * @return int
     */
    public static function consecutive_aborts(): int {
        global $DB;
        $last = self::last_completed();
        $lastid = $last === null ? 0 : $last->id();
        return $DB->count_records_select(self::TABLE, 'status = ? AND id > ?', ['aborted', $lastid]);
    }

    /**
     * Reads the newest run that matches a condition.
     *
     * @param string $select The condition, with placeholders.
     * @param array $params The values of the placeholders.
     * @return self|null
     */
    private static function first(string $select, array $params): ?self {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE, $select, $params, 'id DESC', '*', 0, 1);
        return $rows ? new self(reset($rows)) : null;
    }

    /**
     * The id of the row.
     *
     * @return int
     */
    public function id(): int {
        return (int) $this->row->id;
    }

    /**
     * The current stage.
     *
     * @return string
     */
    public function stage(): string {
        return $this->row->stage;
    }

    /**
     * The status: active, paused, completed, aborted or refused.
     *
     * @return string
     */
    public function status(): string {
        return $this->row->status;
    }

    /**
     * When the run was started.
     *
     * @return int UNIX time.
     */
    public function started_at(): int {
        return (int) $this->row->timecreated;
    }

    /**
     * When the run ended: completed, aborted or refused.
     *
     * @return int|null UNIX time, or null while the run goes on.
     */
    public function finished_at(): ?int {
        return $this->row->timefinished === null ? null : (int) $this->row->timefinished;
    }

    /**
     * What started the run.
     *
     * @return string
     */
    public function trigger(): string {
        return $this->row->triggeredby;
    }

    /**
     * Why the run was aborted.
     *
     * @return string|null
     */
    public function abort_code(): ?string {
        return $this->row->abortcode;
    }

    /**
     * The progress of the current stage.
     *
     * @return array
     */
    public function progress(): array {
        if ($this->row->progress === null || $this->row->progress === '') {
            return [];
        }
        return json_decode($this->row->progress, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The UUID of the run at the backup service.
     *
     * @return string|null
     */
    public function run_uuid(): ?string {
        return $this->row->runuuid;
    }

    /**
     * The number of index entries written so far.
     *
     * @return int
     */
    public function entries_committed(): int {
        return (int) $this->row->entriescommitted;
    }

    /**
     * When a paused run is tried again.
     *
     * @return int|null UNIX time, or null when the run is not waiting.
     */
    public function next_attempt(): ?int {
        return $this->row->nextattempt === null ? null : (int) $this->row->nextattempt;
    }

    /**
     * How many backup windows ended while the run was not done.
     *
     * @return int
     */
    public function window_misses(): int {
        return (int) $this->row->windowmisses;
    }

    /**
     * The server that started the run, when the temporary files of the run are on a disk of that server only.
     *
     * @return string|null Null when every server can go on with the run.
     */
    public function hostname(): ?string {
        return $this->row->hostname === null || $this->row->hostname === '' ? null : $this->row->hostname;
    }

    /**
     * The bytes of the chunks that the storage has taken.
     *
     * @return int
     */
    public function bytes_uploaded(): int {
        return (int) $this->row->bytesuploaded;
    }

    /**
     * The number of chunks that the storage has taken.
     *
     * @return int
     */
    public function chunks_uploaded(): int {
        return (int) $this->row->chunksuploaded;
    }

    /**
     * The estimated size of the backup.
     *
     * @return int|null Bytes, or null before the size was decided.
     */
    public function estimated_bytes(): ?int {
        return $this->row->estimatedbytes === null ? null : (int) $this->row->estimatedbytes;
    }

    /**
     * Reads the run again from the table, for a step that begins: another process (the task, the command line, another
     * server) may have moved it on since this copy was read.
     *
     * @throws \dml_missing_record_exception When the run is not in the table any more.
     */
    public function reload(): void {
        global $DB;
        $this->row = $DB->get_record(self::TABLE, ['id' => $this->row->id], '*', MUST_EXIST);
    }

    /**
     * Whether the run goes on (active or paused), as the table says now.
     *
     * @return bool
     */
    public function is_live(): bool {
        return in_array($this->stored_status(), self::LIVE, true);
    }

    /**
     * The status in the table, which this copy learns.
     *
     * @return string
     */
    private function stored_status(): string {
        global $DB;
        $this->row->status = (string) $DB->get_field(self::TABLE, 'status', ['id' => $this->row->id]);
        return $this->row->status;
    }

    /**
     * Writes fields that change the status, only when the status in the table is one of some.
     *
     * A copy of the run that is stale (another process ended the run) must never change it again.
     *
     * @param array $fields Field names and their new values.
     * @param string[] $from The statuses from which the change is made.
     * @return bool Whether it was made.
     */
    private function change(array $fields, array $from): bool {
        if (!in_array($this->stored_status(), $from, true)) {
            return false;
        }
        $this->save($fields);
        return true;
    }

    /**
     * Moves the run to a stage and stores its progress.
     *
     * @param string $stage One of STAGES.
     * @param array $progress The progress; it must not hold a URL.
     * @param int $now The current time.
     */
    public function set_stage(string $stage, array $progress, int $now): void {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \coding_exception('Unknown stage of a backup run');
        }
        self::refuse_urls($progress);
        $this->save(['stage' => $stage, 'progress' => json_encode($progress, JSON_THROW_ON_ERROR), 'timemodified' => $now]);
    }

    /**
     * Stores the UUID of the run at the backup service.
     *
     * @param string $uuid The UUID.
     */
    public function set_run_uuid(string $uuid): void {
        $this->save(['runuuid' => $uuid]);
    }

    /**
     * Adds to the amount uploaded.
     *
     * @param int $bytes The bytes of the chunk that was uploaded.
     * @param int $chunks The number of chunks that were uploaded.
     */
    public function add_uploaded(int $bytes, int $chunks): void {
        $this->save([
            'bytesuploaded' => (int) $this->row->bytesuploaded + $bytes,
            'chunksuploaded' => (int) $this->row->chunksuploaded + $chunks,
        ]);
    }

    /**
     * Stores the number of index entries that were written.
     *
     * @param int $n The number of entries.
     */
    public function set_entries_committed(int $n): void {
        $this->save(['entriescommitted' => $n]);
    }

    /**
     * Stores the estimated size of the backup.
     *
     * @param int $bytes The estimate in bytes.
     */
    public function set_estimate(int $bytes): void {
        $this->save(['estimatedbytes' => $bytes]);
    }

    /**
     * Writes the stage, its progress and the number of entries that were written, in one update: the point that a later step
     * of the run goes on from.
     *
     * @param string $stage One of STAGES.
     * @param array $progress The progress; it must not hold a URL.
     * @param int $entries The number of entries of the list of the generation that are written.
     * @param int $now The current time.
     */
    public function commit(string $stage, array $progress, int $entries, int $now): void {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \coding_exception('Unknown stage of a backup run');
        }
        self::refuse_urls($progress);
        $this->save([
            'stage' => $stage,
            'progress' => json_encode($progress, JSON_THROW_ON_ERROR),
            'entriescommitted' => $entries,
            'timemodified' => $now,
        ]);
    }

    /**
     * Records the server that started the run, or that any server may go on with it.
     *
     * @param string|null $hostname The name of the server, or null.
     */
    public function set_hostname(?string $hostname): void {
        $this->save(['hostname' => $hostname === null ? null : \core_text::substr($hostname, 0, 255)]);
    }

    /**
     * Stores values of the run (under "run" in its progress) at once, without moving the point that the last commit holds:
     * the journals, the cursor and the chunks to send stay as they were committed.
     *
     * @param array $values Names and values, without a URL.
     */
    public function keep(array $values): void {
        self::refuse_urls($values);
        $progress = $this->progress();
        $progress['run'] = $values + (is_array($progress['run'] ?? null) ? $progress['run'] : []);
        $this->save(['progress' => json_encode($progress, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Stores how many backup windows ended while the run was not done.
     *
     * @param int $misses The number.
     */
    public function set_window_misses(int $misses): void {
        $this->save(['windowmisses' => $misses]);
    }

    /**
     * Makes a paused run active again.
     *
     * @param int $now The current time.
     * @return bool Whether the run was paused, and so became active.
     */
    public function activate(int $now): bool {
        return $this->change(['status' => 'active', 'nextattempt' => null, 'timemodified' => $now], ['paused']);
    }

    /**
     * Records that the service refused to start the run (the plan's number of runs, or a contract that is not active).
     *
     * The run never started, so it is not an aborted run: it does not count as a stop in a row. It is the latest run of the
     * slot, so the schedule does not start another one in the same slot.
     *
     * The time when the service lets another run start (the wait it gave) is kept in the progress under "refusal", not in
     * the column of the next attempt, which belongs to a run that waits.
     *
     * @param string $code The reason: the error code of the service.
     * @param int $now The current time.
     * @param int|null $retryafter The seconds after which the service lets a run start again, if it said so.
     * @return bool Whether the run was live, and so was refused.
     */
    public function refuse(string $code, int $now, ?int $retryafter = null): bool {
        $fields = [
            'status' => 'refused',
            'abortcode' => $code,
            'nextattempt' => null,
            'timefinished' => $now,
            'timemodified' => $now,
        ];
        if ($retryafter !== null) {
            $progress = ['refusal' => ['retry_at' => $now + $retryafter]] + $this->progress();
            $fields['progress'] = json_encode($progress, JSON_THROW_ON_ERROR);
        }
        return $this->change($fields, self::LIVE);
    }

    /**
     * When the service lets another run start, after it refused this one for the number of runs of the plan.
     *
     * @return int|null UNIX time, or null when the service gave none.
     */
    public function retry_at(): ?int {
        $at = $this->progress()['refusal']['retry_at'] ?? null;
        return is_int($at) ? $at : null;
    }

    /**
     * What this site kept about the stop of the run besides its reason (abort::reason()), such as the denial of SELinux.
     *
     * @return array Empty for a run that was not stopped, or stopped without details.
     */
    public function abort_note(): array {
        $note = $this->progress()['abort'] ?? null;
        return is_array($note) ? $note : [];
    }

    /**
     * The class and code of the unexpected error that ended the last step, while the run waits to try again.
     *
     * @return string|null Null when the last step did not end with such an error, or a step has committed since.
     */
    public function last_error(): ?string {
        $error = $this->progress()['run']['lasterror'] ?? null;
        return is_string($error) ? $error : null;
    }

    /**
     * The size of the stored backup that the receipt of the service named.
     *
     * @return int|null Bytes, or null for a run that has no receipt (or from before it was kept).
     */
    public function stored_bytes(): ?int {
        $bytes = $this->progress()['run']['stored_bytes'] ?? null;
        return is_int($bytes) ? $bytes : null;
    }

    /**
     * Pauses the run until a time.
     *
     * @param int $now The current time.
     * @param int $nextattempt When the run is tried again.
     * @return bool Whether the run was live, and so was paused.
     */
    public function pause(int $now, int $nextattempt): bool {
        return $this->change(['status' => 'paused', 'nextattempt' => $nextattempt, 'timemodified' => $now], self::LIVE);
    }

    /**
     * Marks the run as completed.
     *
     * @param int $now The current time.
     * @return bool Whether the run was live, and so was completed.
     */
    public function finish(int $now): bool {
        return $this->change(
            ['status' => 'completed', 'nextattempt' => null, 'timefinished' => $now, 'timemodified' => $now],
            self::LIVE
        );
    }

    /**
     * Marks the run as aborted.
     *
     * @param string $code Why the run was aborted.
     * @param int $now The current time.
     * @param array|null $note What the stop left, stored under "abort" in the progress (which keeps the rest); null for
     *     nothing.
     * @return bool Whether the run was live, and so was aborted.
     */
    public function abort(string $code, int $now, ?array $note = null): bool {
        $fields = [
            'status' => 'aborted',
            'abortcode' => $code,
            'nextattempt' => null,
            'timefinished' => $now,
            'timemodified' => $now,
        ];
        if ($note !== null) {
            $progress = ['abort' => $note] + $this->progress();
            self::refuse_urls($progress);
            $fields['progress'] = json_encode($progress, JSON_THROW_ON_ERROR);
        }
        return $this->change($fields, self::LIVE);
    }

    /**
     * Writes fields to the table, and to the copy in memory when that succeeded.
     *
     * @param array $fields Field names and their new values.
     */
    private function save(array $fields): void {
        global $DB;
        $update = (object) ($fields + ['id' => $this->row->id]);
        $DB->update_record(self::TABLE, $update);
        foreach ($fields as $name => $value) {
            $this->row->$name = $value;
        }
    }

    /**
     * Refuses a progress that holds a URL anywhere.
     *
     * The backup service gives URLs to upload to and to fetch from, which are secrets. A value with
     * "://" is refused, which is stricter than "https://" so that no other scheme gets through either.
     *
     * @param array $progress The progress.
     */
    private static function refuse_urls(array $progress): void {
        foreach ($progress as $value) {
            if (is_array($value)) {
                self::refuse_urls($value);
            } else if (is_string($value) && strpos($value, '://') !== false) {
                throw new \coding_exception('A backup run must not store a URL in its progress');
            }
        }
    }
}
