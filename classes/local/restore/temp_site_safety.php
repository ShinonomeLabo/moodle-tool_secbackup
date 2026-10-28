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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\plugin_exception;

/**
 * Stops what a temporary site must not do with the data of the backed-up site: send mail and notifications, run the tasks of
 * the additional plugins and the core tasks that talk to other systems, and send its users to another login page.
 *
 * It sets noemailever to 1, disables every message processor but popup (the notifications on the page), disables the
 * scheduled tasks of the additional plugins (the plugins of the header whose standard is false) and of CORE_TASKS, deletes the
 * ad hoc tasks of the additional plugins, and empties alternateloginurl, so that secbackup_admin can log in on the login page
 * of the site. The registration of this plugin is not restored (the import leaves its tables and settings out), and is
 * recorded only.
 *
 * Each thing that it changes is recorded in tool_secbackup_restore_stops with the value that it had, for a later resume of the
 * site: a setting with its value (none when the site did not have it), a processor and a task with their enabled and disabled
 * flags, and the ad hoc tasks of a class with how many were deleted. What was already stopped is not recorded. A thing that
 * has its record already is stopped again and not recorded again: the import can run again on the same staging folder and
 * bring the values of the backed-up site back, and the first record keeps them.
 *
 * The values are read from the database, not from $CFG: the import replaced the settings under the ones that this process
 * loaded. The tables are written directly, not through the task manager, whose caches the restore host purges after the
 * import.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class temp_site_safety {
    /** @var string The table of the records. */
    private const TABLE = 'tool_secbackup_restore_stops';

    /** @var string The message processor that stays enabled. */
    private const KEPT_PROCESSOR = 'popup';

    /** @var string[] The core tasks that talk to other systems, as task_scheduled names them. */
    private const CORE_TASKS = [
        '\auth_ldap\task\sync_task',
        '\auth_db\task\sync_users',
        '\auth_cas\task\sync_task',
        '\enrol_ldap\task\sync_enrolments',
        '\enrol_database\task\sync_enrolments',
        '\enrol_imsenterprise\task\cron_task',
        '\enrol_flatfile\task\flatfile_sync_task',
        '\core\task\registration_cron_task',
        '\core\task\h5p_get_content_types_task',
    ];

    /** @var string The plugin whose registration is not restored. */
    private const COMPONENT = 'tool_secbackup';

    /**
     * Checks that the list of plugins of the header is in its form, and writes nothing.
     *
     * @param array $header The header of the generation.
     * @return string[] The additional plugins: the components whose standard is false.
     * @throws plugin_exception With restore_input_invalid when plugins is not a list of objects with a component that is text
     *     and a standard that is true or false.
     */
    public static function check(array $header): array {
        $plugins = $header['plugins'] ?? null;
        if (!is_array($plugins) || !array_is_list($plugins)) {
            throw new plugin_exception('restore_input_invalid', null, 'plugins');
        }
        $additional = [];
        foreach ($plugins as $plugin) {
            if (!is_string($plugin['component'] ?? null) || !is_bool($plugin['standard'] ?? null)) {
                throw new plugin_exception('restore_input_invalid', null, 'plugins');
            }
            if (!$plugin['standard']) {
                $additional[] = $plugin['component'];
            }
        }
        return $additional;
    }

    /**
     * Stops what the temporary site must not do, and records it.
     *
     * @param array $header The header of the generation, for its list of plugins.
     * @return array[] What is stopped, kind and name each, in the order of the records: all the records, also the ones of an
     *     earlier run.
     * @throws plugin_exception With restore_input_invalid when the list of plugins is not in its form, before anything is
     *     stopped.
     */
    public static function apply(array $header): array {
        global $DB;
        $additional = self::check($header);
        self::setting('noemailever', static fn(?string $value): bool => $value !== '1', '1');
        self::setting('alternateloginurl', static fn(?string $value): bool => (string) $value !== '', '');
        $processors = $DB->get_records_select(
            'message_processors',
            'name <> ? AND enabled <> 0',
            [self::KEPT_PROCESSOR],
            'name',
            'id, name, enabled'
        );
        foreach ($processors as $processor) {
            $DB->set_field('message_processors', 'enabled', 0, ['id' => $processor->id]);
            self::record('processor', $processor->name, $processor->enabled);
        }
        foreach ($DB->get_records('task_scheduled', ['disabled' => 0], 'classname', 'id, component, classname') as $task) {
            if (in_array($task->component, $additional, true) || in_array($task->classname, self::CORE_TASKS, true)) {
                $DB->set_field('task_scheduled', 'disabled', 1, ['id' => $task->id]);
                self::record('task', $task->classname, '0');
            }
        }
        foreach ($additional as $component) {
            $counts = $DB->get_records_sql_menu(
                'SELECT classname, COUNT(1) FROM {task_adhoc} WHERE component = ? GROUP BY classname ORDER BY classname',
                [$component]
            );
            $DB->delete_records('task_adhoc', ['component' => $component]);
            foreach ($counts as $classname => $count) {
                self::record('adhoc', $classname, (string) $count);
            }
        }
        self::record('registration', self::COMPONENT, null);
        return array_map(
            static fn(\stdClass $stop): array => ['kind' => $stop->kind, 'name' => $stop->name],
            array_values($DB->get_records(self::TABLE, null, 'id', 'id, kind, name'))
        );
    }

    /**
     * Gives a setting of core a value when its value in the database is one to stop, and records the value that it had.
     *
     * @param string $name The setting.
     * @param \Closure $stops Takes the value in the database (null for none) and tells whether to stop it.
     * @param string $value The value that stops it.
     */
    private static function setting(string $name, \Closure $stops, string $value): void {
        global $DB;
        $previous = $DB->get_field('config', 'value', ['name' => $name]);
        $previous = $previous === false ? null : $previous;
        if ($stops($previous)) {
            set_config($name, $value);
            self::record('setting', $name, $previous);
        }
    }

    /**
     * Records a thing that was stopped, unless it has its record already.
     *
     * @param string $kind The kind: setting, processor, task, adhoc or registration.
     * @param string $name The name of the thing.
     * @param string|null $previous The value that it had, or null for none.
     */
    private static function record(string $kind, string $name, ?string $previous): void {
        global $DB;
        if (!$DB->record_exists(self::TABLE, ['kind' => $kind, 'name' => $name])) {
            $DB->insert_record(self::TABLE, ['kind' => $kind, 'name' => $name, 'previous' => $previous,
                'timecreated' => time()]);
        }
    }
}
