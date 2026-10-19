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
    /** @var int The clock of this server may differ from the service's by less than this many seconds. */
    private const MAX_SKEW = 300;

    /** @var int The least value of memory_limit that is accepted, in bytes (256 MB). */
    private const MIN_MEMORY = 268435456;

    /**
     * Starts the run of the schedule when one is due.
     *
     * @param int $now The current time.
     * @param callable|null $verdict Function () that returns why the server is too busy, or null; the watch of this server
     *     (monitor) when null.
     * @return string started, or the reason: notregistered, notreceived, outsidewindow, clock, memorylimit, running,
     *     autobackup or busy.
     */
    public static function maybe_start(int $now, ?callable $verdict = null): string {
        return self::decide('schedule', true, $now, $verdict);
    }

    /**
     * Starts a run at once, in or out of the window (the button of the status page).
     *
     * @param int $now The current time.
     * @param callable|null $verdict Function () that returns why the server is too busy, or null; the watch of this server
     *     (monitor) when null.
     * @return string started, or the reason; the same as for maybe_start() but never outsidewindow.
     */
    public static function start_now(int $now, ?callable $verdict = null): string {
        return self::decide('manual', false, $now, $verdict);
    }

    /**
     * Checks everything in order and creates the run.
     *
     * @param string $trigger What starts the run: schedule or manual.
     * @param bool $inwindow Whether the run has to be due in the schedule.
     * @param int $now The current time.
     * @param callable|null $verdict The watch, or null for the one of this server.
     * @return string started or the reason.
     */
    private static function decide(string $trigger, bool $inwindow, int $now, ?callable $verdict): string {
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
        $memory = ini_get('memory_limit');
        if ($memory !== '-1' && get_real_size($memory) < self::MIN_MEMORY) {
            return 'memorylimit';
        }
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('start', 0);
        if (!$lock) {
            return 'running';
        }
        try {
            return self::start_locked($trigger, $now, $verdict);
        } finally {
            $lock->release();
        }
    }

    /**
     * The checks and the new record that are made while holding the lock.
     *
     * @param string $trigger What starts the run.
     * @param int $now The current time.
     * @param callable|null $verdict The watch, or null for the one of this server.
     * @return string started or the reason.
     */
    private static function start_locked(string $trigger, int $now, ?callable $verdict): string {
        if (run_record::active() !== null) {
            return 'running';
        }
        if (autobackup_watch::busy()) {
            return 'autobackup';
        }
        $verdict ??= static fn(): ?string => monitor::verdict(monitor::create()->sample(), limits::current());
        if ($verdict() !== null) {
            return 'busy';
        }
        run_record::create($trigger, $now);
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
