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
 * Each command holds a lease of its own, named by a random token: a second command on the same run (a second cron entry, or
 * a server that does not hold the files of the run) neither takes the lease while the first holds it nor removes it when it
 * ends. The value is read and then written, which two commands can interleave: the loser of such a race leaves a lease that
 * lapses within LENGTH seconds.
 *
 * The lease is a setting of the plugin, "clilease": the ID of the run, the time when the lease ends and the token. It is read
 * from the table, since a cron process may hold a copy of the settings from before the last renewal.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cli_lease {
    /** @var int Seconds between two renewals while the command line waits, at most. */
    public const RENEW_EVERY = runner::RETRY_AFTER;

    /** @var int Seconds that a lease lasts after a renewal: three of the longest waits between two renewals. */
    public const LENGTH = 3 * self::RENEW_EVERY;

    /** @var string Name of the setting. */
    private const NAME = 'clilease';

    /** @var string The token of the lease of this command. */
    private string $token;

    /**
     * Constructor: a lease of its own for a command.
     */
    public function __construct() {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * Holds a run for this command, or holds it longer. Nothing happens while another command holds the same run.
     *
     * @param int $runid The run.
     * @param int $now The current time.
     */
    public function renew(int $runid, int $now): void {
        $stored = self::stored();
        if ($stored !== null && $stored['token'] !== $this->token && $stored['run'] === $runid && $now < $stored['end']) {
            return;
        }
        set_config(self::NAME, $runid . ':' . ($now + self::LENGTH) . ':' . $this->token, 'tool_secbackup');
    }

    /**
     * Ends the lease of this command, which no longer drives a run. The lease of another command is left as it is.
     */
    public function release(): void {
        if ((self::stored()['token'] ?? null) === $this->token) {
            unset_config(self::NAME, 'tool_secbackup');
        }
    }

    /**
     * Whether a command holds a run.
     *
     * @param int $runid The run.
     * @param int $now The current time.
     * @return bool
     */
    public static function held(int $runid, int $now): bool {
        $stored = self::stored();
        return $stored !== null && $stored['run'] === $runid && $now < $stored['end'];
    }

    /**
     * The lease that is stored.
     *
     * @return array|null ['run' => ID, 'end' => time, 'token' => token], or null when there is none.
     */
    private static function stored(): ?array {
        global $DB;
        $value = (string) $DB->get_field('config_plugins', 'value', ['plugin' => 'tool_secbackup', 'name' => self::NAME]);
        if (preg_match('/^(\d+):(\d+):([0-9a-f]{32})\z/', $value, $m) !== 1) {
            return null;
        }
        return ['run' => (int) $m[1], 'end' => (int) $m[2], 'token' => $m[3]];
    }
}
