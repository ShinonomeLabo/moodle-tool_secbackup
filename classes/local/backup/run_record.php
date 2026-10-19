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
            'hostname' => \core_text::substr((string) gethostname(), 0, 255),
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
     * @return self|null
     */
    public static function active(): ?self {
        return self::first('status IN (?, ?)', ['active', 'paused']);
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
     * The status: active, paused, completed or aborted.
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
     * Pauses the run until a time.
     *
     * @param int $now The current time.
     * @param int $nextattempt When the run is tried again.
     */
    public function pause(int $now, int $nextattempt): void {
        $this->save(['status' => 'paused', 'nextattempt' => $nextattempt, 'timemodified' => $now]);
    }

    /**
     * Marks the run as completed.
     *
     * @param int $now The current time.
     */
    public function finish(int $now): void {
        $this->save(['status' => 'completed', 'nextattempt' => null, 'timefinished' => $now, 'timemodified' => $now]);
    }

    /**
     * Marks the run as aborted.
     *
     * @param string $code Why the run was aborted.
     * @param int $now The current time.
     */
    public function abort(string $code, int $now): void {
        $this->save([
            'status' => 'aborted',
            'abortcode' => $code,
            'nextattempt' => null,
            'timefinished' => $now,
            'timemodified' => $now,
        ]);
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
