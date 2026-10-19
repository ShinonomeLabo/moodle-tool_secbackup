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

use core\task\automated_backup_task;
use core\task\course_backup_task;
use core\task\manager;

/**
 * Watches the automated course backup of Moodle, so that this plugin does not run at the same time.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class autobackup_watch {
    /**
     * Whether the automated backup is working or about to work.
     *
     * @return bool True when a course backup task is queued or running, or the automated backup task is running.
     */
    public static function busy(): bool {
        if (manager::get_adhoc_tasks(course_backup_task::class) !== []) {
            return true;
        }
        foreach (manager::get_running_tasks() as $running) {
            if ($running->type === 'scheduled' && ltrim($running->classname, '\\') === automated_backup_task::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * What is known of the automated backup of this site.
     *
     * @return array{backup_auto_active: int, weekdays: string, hour: int, minute: int, lastduration: int|null} The setting of
     *     Moodle, and how long the last round took in seconds (null when no course has been backed up).
     */
    public static function describe(): array {
        global $DB;
        $last = (int) $DB->get_field_select('backup_courses', 'MAX(laststarttime)', 'laststarttime > 0');
        $duration = null;
        if ($last > 0) {
            $round = $DB->get_record_sql(
                'SELECT MIN(laststarttime) AS firststart, MAX(lastendtime) AS lastend
                   FROM {backup_courses}
                  WHERE laststarttime >= ? AND lastendtime >= laststarttime',
                [$last - DAYSECS]
            );
            if ($round->firststart !== null) {
                $duration = (int) $round->lastend - (int) $round->firststart;
            }
        }
        return [
            'backup_auto_active' => (int) get_config('backup', 'backup_auto_active'),
            'weekdays' => (string) get_config('backup', 'backup_auto_weekdays'),
            'hour' => (int) get_config('backup', 'backup_auto_hour'),
            'minute' => (int) get_config('backup', 'backup_auto_minute'),
            'lastduration' => $duration,
        ];
    }
}
