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
 * The lease of the command line on a run.
 *
 * cli/backup.php does not follow the windows of the schedule, and it releases the lock of the steps while it waits between
 * two of its steps. A step of the task that took the lock then would apply the window rules to the run and could stop it. So
 * while the command line drives a run, it holds a lease on it, and a step of the task leaves that run alone. The command line
 * renews the lease before and after each of its steps and at least every RENEW_EVERY seconds while it waits, and releases it
 * when it ends; when it is killed, the lease lapses within LENGTH seconds and the task goes on with the run, with its rules.
 *
 * The lease is a setting of the plugin, "clilease": the ID of the run and the time when the lease ends. It is read from the
 * table, since a cron process may hold a copy of the settings from before the last renewal.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cli_lease {
    /** @var int Seconds between two renewals while the command line waits, at most. */
    public const RENEW_EVERY = runner::RETRY_AFTER;

    /** @var int Seconds that a lease lasts after a renewal: three of the longest waits between two renewals. */
    public const LENGTH = 3 * self::RENEW_EVERY;

    /** @var string Name of the setting. */
    private const NAME = 'clilease';

    /**
     * Holds a run for the command line, or holds it longer.
     *
     * @param int $runid The run.
     * @param int $now The current time.
     */
    public static function renew(int $runid, int $now): void {
        set_config(self::NAME, $runid . ':' . ($now + self::LENGTH), 'tool_secbackup');
    }

    /**
     * Ends the lease: the command line no longer drives a run.
     */
    public static function release(): void {
        unset_config(self::NAME, 'tool_secbackup');
    }

    /**
     * Whether the command line holds a run.
     *
     * @param int $runid The run.
     * @param int $now The current time.
     * @return bool
     */
    public static function held(int $runid, int $now): bool {
        global $DB;
        $value = (string) $DB->get_field('config_plugins', 'value', ['plugin' => 'tool_secbackup', 'name' => self::NAME]);
        return preg_match('/^(\d+):(\d+)\z/', $value, $m) === 1 && (int) $m[1] === $runid && $now < (int) $m[2];
    }
}
