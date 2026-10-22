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

use tool_secbackup\local\enrollment\state;

/**
 * Decides whether a backup run may start now, and starts it.
 *
 * The answer is the word 'started' or the reason why not. Whatever is checked, no more than one run is active at a time:
 * the check and the new record are made while holding a lock.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class starter {
    /** @var string[] Every answer of start_now(): started, or the reason why not. */
    public const START_NOW_ANSWERS = ['started', 'notregistered', 'notreceived', 'clock', 'memorylimit', 'running', 'autobackup',
        'busy'];

    /** @var int The clock of this server may differ from the service's by less than this many seconds. */
    private const MAX_SKEW = 300;

    /** @var int The least value of memory_limit that is accepted, in bytes (256 MB). */
    private const MIN_MEMORY = 268435456;

    /** @var string The setting that keeps what came of the last scheduled start that was due. */
    private const LAST_SCHEDULE = 'lastschedule';

    /**
     * Starts the run of the schedule when one is due.
     *
     * Every answer but outsidewindow is kept with its time (and, for busy, the limit that was passed), so that the status page
     * and the task can tell why the schedule did not start a backup: see last_scheduled().
     *
     * @param int $now The current time.
     * @param callable|null $verdict A seam for tests: function () that returns why the server is too busy, or null. The
     *     watch of this server (monitor::cause()) with the stored limits when null.
     * @return string started, or the reason: notregistered, notreceived, outsidewindow, clock, memorylimit, running,
     *     autobackup or busy.
     */
    public static function maybe_start(int $now, ?callable $verdict = null): string {
        $cause = null;
        $answer = self::decide('schedule', true, $now, $verdict, $cause);
        if ($answer !== 'outsidewindow') {
            $last = json_encode(['at' => $now, 'answer' => $answer, 'cause' => $cause]);
            set_config(self::LAST_SCHEDULE, $last, 'tool_secbackup');
        }
        return $answer;
    }

    /**
     * What came of the last scheduled start that was due.
     *
     * @return array|null ['at' => UNIX time, 'answer' => what maybe_start() answered, 'cause' => the limit that the server
     *     passed (monitor::cause()) for busy, else null]; null when none was due yet.
     */
    public static function last_scheduled(): ?array {
        $value = json_decode((string) get_config('tool_secbackup', self::LAST_SCHEDULE), true);
        $valid = is_array($value) && array_keys($value) === ['at', 'answer', 'cause'] && is_int($value['at'])
            && is_string($value['answer']) && ($value['cause'] === null || is_string($value['cause']));
        return $valid ? $value : null;
    }

    /**
     * The text of an answer of the starter: the limit that was passed for busy, else the reason.
     *
     * @param string $answer What the starter answered.
     * @param string|null $cause The limit that the server passed, for busy.
     * @return string Plain text.
     */
    public static function answer_text(string $answer, ?string $cause): string {
        $id = 'start_cause_' . $cause;
        if ($answer === 'busy' && $cause !== null && get_string_manager()->string_exists($id, 'tool_secbackup')) {
            return get_string($id, 'tool_secbackup');
        }
        return get_string('start_reason_' . $answer, 'tool_secbackup');
    }

    /**
     * Starts a run at once, in or out of the window (the button of the status page).
     *
     * @param int $now The current time.
     * @param callable|null $verdict A seam for tests: function () that returns why the server is too busy, or null. The
     *     watch of this server (monitor) with the stored limits when null.
     * @return string started, or the reason; the same as for maybe_start() but never outsidewindow.
     */
    public static function start_now(int $now, ?callable $verdict = null): string {
        return self::decide('manual', false, $now, $verdict);
    }

    /**
     * Starts a run at once for the command line, in or out of the window.
     *
     * @param int $now The current time.
     * @param callable|null $verdict A seam for tests, as for maybe_start().
     * @return string started, or the reason; the same as for start_now().
     */
    public static function start_cli(int $now, ?callable $verdict = null): string {
        return self::decide('cli', false, $now, $verdict);
    }

    /**
     * Checks everything in order and creates the run.
     *
     * @param string $trigger What starts the run: schedule, manual or cli.
     * @param bool $inwindow Whether the run has to be due in the schedule.
     * @param int $now The current time.
     * @param callable|null $verdict The watch, or null for the one of this server.
     * @param string|null $cause Set to what the watch said, when the answer is busy.
     * @return string started or the reason.
     */
    private static function decide(string $trigger, bool $inwindow, int $now, ?callable $verdict, ?string &$cause = null): string {
        $state = state::load();
        if (!$state->is_enrolled()) {
            return 'notregistered';
        }
        $settings = settings::current();
        if (!$settings->is_received()) {
            return 'notreceived';
        }
        if ($inwindow && !self::is_due($settings, $state->site_id(), $now)) {
            return 'outsidewindow';
        }
        $skew = $state->clock_skew();
        if ($skew !== null && abs($skew) >= self::MAX_SKEW) {
            return 'clock';
        }
        global $CFG;
        if (self::memory_limit_too_low((string) ini_get('memory_limit'), (string) ($CFG->extramemorylimit ?? ''))) {
            return 'memorylimit';
        }
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('start', 0);
        if (!$lock) {
            return 'running';
        }
        try {
            return self::start_locked($trigger, $now, $verdict, $cause);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the memory the run will have is less than 256 MB.
     *
     * The run goes on in cron, which raises the limit to the extra limit of Moodle when that is larger than the ini value,
     * and the button of the status page runs in a web request, whose ini value is the lower one. So the run is judged by
     * the larger of the two, not by the limit of the caller.
     *
     * @param string $ini The memory_limit of PHP; -1 is no limit.
     * @param string $extra The extra memory limit of Moodle ($CFG->extramemorylimit); empty for the default of 512M.
     * @return bool
     */
    public static function memory_limit_too_low(string $ini, string $extra): bool {
        $extra = $extra === '' ? '512M' : $extra;
        if ($ini === '-1' || $extra === '-1') {
            return false;
        }
        return max(get_real_size($ini), get_real_size($extra)) < self::MIN_MEMORY;
    }

    /**
     * The checks and the new record that are made while holding the lock.
     *
     * @param string $trigger What starts the run.
     * @param int $now The current time.
     * @param callable|null $verdict The watch, or null for the one of this server.
     * @param string|null $cause Set to what the watch said, when the answer is busy.
     * @return string started or the reason.
     */
    private static function start_locked(string $trigger, int $now, ?callable $verdict, ?string &$cause): string {
        if (run_record::active() !== null) {
            // A run that lost its task (the queue was cleared, or the task failed for good) gets one again.
            \tool_secbackup\task\run_backup::queue();
            return 'running';
        }
        if (autobackup_watch::busy()) {
            return 'autobackup';
        }
        $verdict ??= static fn(): ?string => monitor::cause(monitor::create()->sample(), limits::current());
        $cause = $verdict();
        if ($cause !== null) {
            return 'busy';
        }
        run_record::create($trigger, $now);
        \tool_secbackup\task\run_backup::queue();
        return 'started';
    }

    /**
     * Whether a run of the schedule is due.
     *
     * @param settings $settings The settings.
     * @param string $siteid The ID of this site.
     * @param int $now The current time.
     * @return bool
     */
    private static function is_due(settings $settings, string $siteid, int $now): bool {
        $latest = run_record::recent(1);
        return schedule::due($settings, $siteid, $now, $latest === [] ? null : $latest[0]->started_at());
    }
}
