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

namespace tool_secbackup\local\cli;

use tool_secbackup\local\backup\abort_reason;
use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\runner;
use tool_secbackup\local\backup\starter;

/**
 * The backup on the command line: it goes on with the running backup, or starts one with --now, until the backup ends.
 *
 * Large sites run it from the cron of the system, so that a backup is not cut into steps of ten minutes. It does not stop at
 * the end of the backup window. What it prints names stages and reasons, never an address or a key.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_command {
    /** @var int Seconds of one step. */
    public const BUDGET = 3600;

    /** @var int The backup is complete. */
    public const EXIT_DONE = 0;

    /** @var int The backup was stopped, or the service did not start it. */
    public const EXIT_STOPPED = 1;

    /** @var int No backup was run. */
    public const EXIT_NOT_RUN = 2;

    /** @var int Seconds to wait for the lock of the steps, which the task holds for up to ten minutes. */
    public const LOCK_WAIT = 60;

    /** @var int Seconds to wait before the lock is asked for again. */
    public const LOCK_RETRY = 30;

    /** @var int Seconds that one ask for the lock waits. */
    private int $lockwait;

    /** @var runner|null Runner; null for the one of this site, made when it is needed. */
    private ?runner $runner;

    /** @var callable Gives the current UNIX time. */
    private $clock;

    /** @var callable Waits some seconds. */
    private $sleep;

    /** @var callable|null The watch of the starter; null for the one of this server. */
    private $verdict;

    /**
     * Constructor.
     *
     * @param runner|null $runner Runner; null for the one of this site, which does not stop at the end of a window.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param callable|null $sleep Waits some seconds; null for sleep().
     * @param callable|null $verdict The watch of the starter, as for starter::start_cli(); null for the one of this server.
     * @param int $lockwait Seconds that one ask for the lock of the steps waits.
     */
    public function __construct(
        ?runner $runner = null,
        ?callable $clock = null,
        ?callable $sleep = null,
        ?callable $verdict = null,
        int $lockwait = self::LOCK_WAIT
    ) {
        $this->runner = $runner;
        $this->lockwait = $lockwait;
        $this->clock = $clock ?? static fn(): int => time();
        $this->sleep = $sleep ?? static fn(int $seconds) => sleep($seconds);
        $this->verdict = $verdict;
    }

    /**
     * Writes the help of the command, under the heading and the trademark notice.
     *
     * @param callable $write Writes one line.
     */
    public function help(callable $write): void {
        self::write_heading($write);
        $write(get_string('cli_backup_help', 'tool_secbackup'));
    }

    /**
     * Runs the backup until it ends.
     *
     * @param bool $now Whether to start a backup when none is running.
     * @param bool $quiet Whether to write the outcome only.
     * @param callable $write Writes one line.
     * @return int EXIT_DONE, EXIT_STOPPED or EXIT_NOT_RUN.
     */
    public function run(bool $now, bool $quiet, callable $write): int {
        $say = $quiet ? static fn(string $line) => null : $write;
        self::write_heading($say);
        $run = run_record::active();
        if ($run === null) {
            if (!$now) {
                $write(get_string('cli_backup_none', 'tool_secbackup'));
                return self::EXIT_NOT_RUN;
            }
            $reason = starter::start_cli(($this->clock)(), $this->verdict);
            if ($reason !== 'started') {
                $text = get_string('start_reason_' . $reason, 'tool_secbackup');
                $write(get_string('cli_backup_notstarted', 'tool_secbackup', $text));
                return self::EXIT_NOT_RUN;
            }
            $run = run_record::active();
            $say(get_string('cli_backup_started', 'tool_secbackup'));
        } else {
            $say(get_string('cli_backup_continued', 'tool_secbackup'));
        }
        $runner = $this->runner ?? runner::create(false);
        while (true) {
            $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('step', $this->lockwait);
            if (!$lock) {
                // The task (or another server) runs a step: the command goes on after it, from what it committed.
                $say(get_string('cli_backup_lockwait', 'tool_secbackup'));
                ($this->sleep)(self::LOCK_RETRY);
                continue;
            }
            try {
                $result = $runner->step($run, self::BUDGET);
            } finally {
                $lock->release();
            }
            $end = $this->outcome($run, $result, $write);
            if ($end !== null) {
                return $end;
            }
            if ($runner->last_error() !== null) {
                // The run waits five minutes and is tried again; a run without a receipt after 24 hours is stopped.
                $say(get_string('cli_backup_failed', 'tool_secbackup', $runner->last_error()));
            }
            $say(get_string('cli_backup_stage', 'tool_secbackup', $run->stage()));
            $next = $run->next_attempt();
            $wait = $next === null ? 0 : $next - ($this->clock)();
            if ($wait > 0) {
                $say(get_string('cli_backup_waiting', 'tool_secbackup', userdate($next)));
                ($this->sleep)($wait);
            }
        }
    }

    /**
     * Writes the outcome of a step that ended the command.
     *
     * @param run_record $run The run.
     * @param string $result What the step answered.
     * @param callable $write Writes one line.
     * @return int|null The exit code, or null when the backup goes on.
     */
    private function outcome(run_record $run, string $result, callable $write): ?int {
        if ($result === 'done') {
            $write(get_string('cli_backup_done', 'tool_secbackup'));
            return self::EXIT_DONE;
        }
        if ($result === 'aborted') {
            $write($run->status() === 'refused'
                ? get_string('cli_backup_refused', 'tool_secbackup', get_string('api_' . $run->abort_code(), 'tool_secbackup'))
                : get_string('cli_backup_aborted', 'tool_secbackup', abort_reason::text((string) $run->abort_code())));
            return self::EXIT_STOPPED;
        }
        if ($result === 'paused') {
            // The command does not stop at the end of a window: a run that pauses has its files on another server.
            $write(get_string('cli_backup_otherserver', 'tool_secbackup', (string) $run->hostname()));
            return self::EXIT_NOT_RUN;
        }
        return null;
    }

    /**
     * Writes the heading and the trademark notice. The notice comes before any other line, so the first mention of Moodle in
     * the output is the notice itself.
     *
     * @param callable $write Writes one line.
     */
    private static function write_heading(callable $write): void {
        $write(get_string('cli_backup_heading', 'tool_secbackup'));
        $write(get_string('trademark', 'tool_secbackup'));
    }
}
