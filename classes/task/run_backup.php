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

namespace tool_secbackup\task;

use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\runner;

/**
 * Moves the backup run forward for ten minutes, and queues itself again while the run has more to do.
 *
 * The starter queues it when it starts a run, and again at a status request when a run has none. Only one step runs at a
 * time: a step of the command line or of another server holds the same lock, and then this task does nothing (that step
 * queues the next one, or the next status request does).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_backup extends \core\task\adhoc_task {
    /** @var int Seconds of one step. */
    public const BUDGET = 600;

    /** @var int Seconds after which a paused run that does not say when is tried again. */
    public const PAUSED_RETRY = 300;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_run_backup', 'tool_secbackup');
    }

    /**
     * Steps the run with the runner of this site.
     */
    public function execute() {
        $this->step_with(static fn(run_record $run, int $budget): string => runner::create(true)->step($run, $budget));
    }

    /**
     * Steps the active run once, while holding the lock of the steps, and queues the task again when the run goes on.
     *
     * @param callable $step Function (run_record $run, int $budget): string that steps the run.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param callable|null $write Writes a line of the log of the task; null for mtrace().
     * @return string|null What the step answered, or null when there was no run or another process held the lock.
     */
    public function step_with(callable $step, ?callable $clock = null, ?callable $write = null): ?string {
        $write ??= static fn(string $line) => mtrace($line);
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('step', 0);
        if (!$lock) {
            $write(get_string('task_run_backup_locked', 'tool_secbackup'));
            return null;
        }
        try {
            $run = run_record::active();
            if ($run === null) {
                $write(get_string('task_run_backup_none', 'tool_secbackup'));
                return null;
            }
            $result = $step($run, self::BUDGET);
            if ($result === 'continue' || $result === 'paused') {
                self::queue_next($run, $result, $clock === null ? time() : (int) $clock());
            }
            $write(get_string('task_run_backup_' . $result, 'tool_secbackup'));
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Queues the task again: when the run waits, for the time it waits for; a paused run that does not say when, in five
     * minutes; otherwise at once.
     *
     * @param run_record $run The run.
     * @param string $result continue or paused.
     * @param int $now The current time.
     */
    private static function queue_next(run_record $run, string $result, int $now): void {
        $task = new self();
        $next = $run->next_attempt();
        if ($next !== null && $next > $now) {
            $task->set_next_run_time($next);
        } else if ($result === 'paused') {
            $task->set_next_run_time($now + self::PAUSED_RETRY);
        }
        // The task that runs now is still in the queue, so the check for an existing one would find it.
        \core\task\manager::queue_adhoc_task($task);
    }
}
