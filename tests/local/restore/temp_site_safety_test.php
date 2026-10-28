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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\temp_site_safety::class)]
/**
 * Tests for what a temporary site stops after the import: mail, the notifications but the ones on the page, the scheduled
 * tasks of the additional plugins and the core tasks that talk to other systems, the ad hoc tasks of the additional plugins
 * and the alternate login page, each recorded with the value that it had.
 *
 * The site of the tests plays the restored site. An additional plugin is a component of the header whose standard is false:
 * local_secbkextra, whose tasks the tests add to the tables.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\temp_site_safety
 */
final class temp_site_safety_test extends \advanced_testcase {
    /** @var string The additional plugin of the header. */
    private const EXTRA = 'local_secbkextra';

    /** @var string[] The core tasks that talk to other systems, as the table task_scheduled names them. */
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

    /** @var string A core task that is not stopped. */
    private const OTHER_CORE_TASK = '\core\task\session_cleanup_task';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The header of the generation, with its list of plugins.
     *
     * @param array|null $plugins The list, or null for the one of the tests: a standard plugin, the additional one and this
     *     plugin, which is additional too.
     * @return array
     */
    private static function header(?array $plugins = null): array {
        return [
            'v' => 1,
            'plugins' => $plugins ?? [
                ['component' => 'mod_forum', 'version' => '2024100700', 'standard' => true],
                ['component' => self::EXTRA, 'version' => '2025010100', 'standard' => false],
                ['component' => 'tool_secbackup', 'version' => '2026102800', 'standard' => false],
            ],
        ];
    }

    /**
     * Puts the site in the state of a site that sends mail and runs everything: the settings, the processors, the scheduled
     * tasks (each one there and enabled) and ad hoc tasks of the additional plugin and of core.
     *
     * @return array The ids of the ad hoc tasks: extra (two of one class, one of another) and core.
     */
    private function live_site(): array {
        global $DB;
        set_config('noemailever', 0);
        set_config('alternateloginurl', 'https://sso.example.com/login');
        foreach (['airnotifier', 'email', 'popup'] as $name) {
            if (!$DB->record_exists('message_processors', ['name' => $name])) {
                $DB->insert_record('message_processors', ['name' => $name, 'enabled' => 1]);
            }
        }
        $DB->set_field('message_processors', 'enabled', 1);
        foreach ([...self::CORE_TASKS, self::OTHER_CORE_TASK] as $classname) {
            $this->scheduled_task(explode('\\', $classname)[1], $classname);
        }
        $this->scheduled_task(self::EXTRA, '\local_secbkextra\task\sync');
        $this->scheduled_task(self::EXTRA, '\local_secbkextra\task\report');
        $this->scheduled_task('tool_secbackup', '\tool_secbackup\task\poll_status');
        $DB->set_field('task_scheduled', 'disabled', 0);
        $adhoc = fn(string $component, string $classname): int => $DB->insert_record('task_adhoc', [
            'component' => $component, 'classname' => $classname, 'nextruntime' => 0, 'customdata' => '{"to":"a@example.com"}',
        ]);
        return [
            'extra' => [
                $adhoc(self::EXTRA, '\local_secbkextra\task\push'),
                $adhoc(self::EXTRA, '\local_secbkextra\task\push'),
                $adhoc(self::EXTRA, '\local_secbkextra\task\pull'),
            ],
            'core' => $adhoc('core', '\core\task\send_new_user_passwords_task'),
        ];
    }

    /**
     * Makes sure that a scheduled task has its row.
     *
     * @param string $component The component of the task.
     * @param string $classname The class of the task, with its leading backslash.
     */
    private function scheduled_task(string $component, string $classname): void {
        global $DB;
        if (!$DB->record_exists('task_scheduled', ['classname' => $classname])) {
            $DB->insert_record('task_scheduled', ['component' => $component, 'classname' => $classname, 'minute' => '*',
                'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*', 'disabled' => 0]);
        }
    }

    /**
     * The records of what was stopped, in order.
     *
     * @return array[] kind, name and previous each.
     */
    private static function stops(): array {
        global $DB;
        return array_map(
            static fn(\stdClass $row): array => ['kind' => $row->kind, 'name' => $row->name, 'previous' => $row->previous],
            array_values($DB->get_records('tool_secbackup_restore_stops', null, 'id', 'id, kind, name, previous'))
        );
    }

    /**
     * Whether each of the scheduled tasks is disabled, by class.
     *
     * @return int[]
     */
    private static function disabled(): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu('task_scheduled', null, 'classname', 'classname, disabled'));
    }

    public function test_the_temporary_site_sends_no_mail_and_keeps_only_the_notifications_on_the_page(): void {
        global $DB;
        $this->live_site();
        temp_site_safety::apply(self::header());
        $this->assertSame('1', $DB->get_field('config', 'value', ['name' => 'noemailever']));
        $enabled = $DB->get_records_menu('message_processors', null, 'name', 'name, enabled');
        $this->assertEquals(0, $enabled['airnotifier']);
        $this->assertEquals(0, $enabled['email']);
        $this->assertEquals(1, $enabled['popup']);
        foreach ($enabled as $name => $value) {
            $this->assertEquals($name === 'popup' ? 1 : 0, $value, $name);
        }
    }

    public function test_the_tasks_of_the_additional_plugins_and_the_listed_core_tasks_are_disabled_and_no_other(): void {
        $this->live_site();
        $before = self::disabled();
        temp_site_safety::apply(self::header());
        $after = self::disabled();
        $stopped = [...self::CORE_TASKS, '\local_secbkextra\task\sync', '\local_secbkextra\task\report',
            '\tool_secbackup\task\poll_status'];
        foreach ($after as $classname => $disabled) {
            $this->assertSame(in_array($classname, $stopped, true) ? 1 : $before[$classname], $disabled, $classname);
        }
        $this->assertSame(0, $after[self::OTHER_CORE_TASK]);
        $this->assertSame(0, $after['\mod_forum\task\cron_task'] ?? 0);
    }

    public function test_the_other_columns_of_a_task_that_is_disabled_do_not_change(): void {
        global $DB;
        $this->live_site();
        $DB->set_field('task_scheduled', 'minute', '17', ['classname' => '\auth_db\task\sync_users']);
        $before = $DB->get_record('task_scheduled', ['classname' => '\auth_db\task\sync_users']);
        temp_site_safety::apply(self::header());
        $after = $DB->get_record('task_scheduled', ['classname' => '\auth_db\task\sync_users']);
        $before->disabled = '1';
        $this->assertEquals($before, $after);
    }

    public function test_the_ad_hoc_tasks_of_the_additional_plugins_are_deleted_and_the_others_are_kept(): void {
        global $DB;
        $ids = $this->live_site();
        temp_site_safety::apply(self::header());
        foreach ($ids['extra'] as $id) {
            $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $id]));
        }
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $ids['core']]));
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => self::EXTRA]));
    }

    public function test_the_alternate_login_page_is_emptied(): void {
        global $DB;
        $this->live_site();
        temp_site_safety::apply(self::header());
        $this->assertSame('', $DB->get_field('config', 'value', ['name' => 'alternateloginurl']));
    }

    public function test_what_was_stopped_is_recorded_once_with_the_value_that_it_had(): void {
        $this->live_site();
        $this->assertSame([], self::stops());
        $before = time();
        $returned = temp_site_safety::apply(self::header());
        $stops = self::stops();
        $tasks = [...self::CORE_TASKS, '\local_secbkextra\task\report', '\local_secbkextra\task\sync',
            '\tool_secbackup\task\poll_status'];
        sort($tasks, SORT_STRING);
        // Moodle 5.3 has the processor sms too.
        global $DB;
        $processors = $DB->get_fieldset_select('message_processors', 'name', 'name <> ?', ['popup']);
        sort($processors, SORT_STRING);
        $this->assertContains('airnotifier', $processors);
        $this->assertContains('email', $processors);
        $expected = [
            ['kind' => 'setting', 'name' => 'noemailever', 'previous' => '0'],
            ['kind' => 'setting', 'name' => 'alternateloginurl', 'previous' => 'https://sso.example.com/login'],
            ...array_map(
                static fn(string $name): array => ['kind' => 'processor', 'name' => $name, 'previous' => '1'],
                $processors
            ),
            ...array_map(static fn(string $task): array => ['kind' => 'task', 'name' => $task, 'previous' => '0'], $tasks),
            ['kind' => 'adhoc', 'name' => '\local_secbkextra\task\pull', 'previous' => '1'],
            ['kind' => 'adhoc', 'name' => '\local_secbkextra\task\push', 'previous' => '2'],
            ['kind' => 'registration', 'name' => 'tool_secbackup', 'previous' => null],
        ];
        $this->assertSame($expected, $stops);
        $this->assertSame(
            array_map(static fn(array $stop): array => ['kind' => $stop['kind'], 'name' => $stop['name']], $expected),
            $returned
        );
        foreach ($DB->get_fieldset_select('tool_secbackup_restore_stops', 'timecreated', '1 = 1') as $time) {
            $this->assertGreaterThanOrEqual($before, (int) $time);
            $this->assertLessThanOrEqual(time(), (int) $time);
        }
    }

    public function test_a_second_run_records_nothing_new_and_gives_the_same_list(): void {
        $this->live_site();
        $first = temp_site_safety::apply(self::header());
        $stops = self::stops();
        $second = temp_site_safety::apply(self::header());
        $this->assertSame($stops, self::stops());
        $this->assertSame($first, $second);
    }

    public function test_a_run_after_the_import_ran_again_stops_again_and_keeps_the_first_record(): void {
        global $DB;
        $this->live_site();
        temp_site_safety::apply(self::header());
        $stops = self::stops();
        // The import of the same staging folder brings the values of the backed-up site back.
        $ids = $this->live_site();
        set_config('alternateloginurl', 'https://other.example.com/login');
        temp_site_safety::apply(self::header());
        $this->assertSame($stops, self::stops());
        $this->assertSame('1', $DB->get_field('config', 'value', ['name' => 'noemailever']));
        $this->assertSame('', $DB->get_field('config', 'value', ['name' => 'alternateloginurl']));
        $this->assertEquals(0, $DB->get_field('message_processors', 'enabled', ['name' => 'email']));
        $this->assertSame(1, self::disabled()['\local_secbkextra\task\sync']);
        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $ids['extra'][0]]));
    }

    public function test_what_was_already_stopped_is_not_recorded(): void {
        global $DB;
        $this->live_site();
        set_config('noemailever', 1);
        set_config('alternateloginurl', '');
        $DB->set_field('message_processors', 'enabled', 0, ['name' => 'airnotifier']);
        $DB->set_field('task_scheduled', 'disabled', 1, ['classname' => '\auth_ldap\task\sync_task']);
        $DB->set_field('task_scheduled', 'disabled', 1, ['component' => self::EXTRA]);
        $DB->delete_records('task_adhoc', ['component' => self::EXTRA]);
        $returned = temp_site_safety::apply(self::header());
        $names = array_column($returned, 'name');
        $already = ['noemailever', 'alternateloginurl', 'airnotifier', '\auth_ldap\task\sync_task', '\local_secbkextra\task\sync',
            '\local_secbkextra\task\push'];
        foreach ($already as $name) {
            $this->assertNotContains($name, $names);
        }
        $this->assertContains('email', $names);
        $this->assertSame(['kind' => 'registration', 'name' => 'tool_secbackup'], end($returned));
    }

    public function test_a_record_of_another_kind_with_the_same_name_does_not_stand_for_a_thing_stopped(): void {
        global $DB;
        $this->live_site();
        $DB->insert_record('tool_secbackup_restore_stops', ['kind' => 'setting', 'name' => 'email', 'previous' => null,
            'timecreated' => 1]);
        temp_site_safety::apply(self::header());
        $this->assertTrue($DB->record_exists('tool_secbackup_restore_stops', ['kind' => 'processor', 'name' => 'email']));
    }

    public function test_settings_that_the_site_does_not_have_are_set_and_recorded_without_a_value(): void {
        global $DB;
        $this->live_site();
        unset_config('noemailever');
        unset_config('alternateloginurl');
        temp_site_safety::apply(self::header());
        $this->assertSame('1', $DB->get_field('config', 'value', ['name' => 'noemailever']));
        $this->assertSame(['kind' => 'setting', 'name' => 'noemailever', 'previous' => null], self::stops()[0]);
        $this->assertNotContains('alternateloginurl', array_column(self::stops(), 'name'));
    }

    public function test_the_values_of_the_database_count_and_not_the_ones_that_this_process_read_at_its_start(): void {
        global $CFG, $DB;
        $this->live_site();
        // The import replaced the rows of the table config under the settings that this process loaded.
        $DB->set_field('config', 'value', 'https://imported.example.com/', ['name' => 'alternateloginurl']);
        $DB->set_field('config', 'value', '1', ['name' => 'noemailever']);
        $CFG->noemailever = '0';
        temp_site_safety::apply(self::header());
        $this->assertSame('', $DB->get_field('config', 'value', ['name' => 'alternateloginurl']));
        $this->assertSame(
            [['kind' => 'setting', 'name' => 'alternateloginurl', 'previous' => 'https://imported.example.com/']],
            array_values(array_filter(self::stops(), static fn(array $stop): bool => $stop['kind'] === 'setting'))
        );
    }

    public function test_a_header_without_additional_plugins_stops_only_the_core_tasks(): void {
        $this->live_site();
        $returned = temp_site_safety::apply(self::header([['component' => 'mod_forum', 'version' => '1', 'standard' => true]]));
        $tasks = array_column(array_filter($returned, static fn(array $stop): bool => $stop['kind'] === 'task'), 'name');
        sort($tasks, SORT_STRING);
        $expected = self::CORE_TASKS;
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $tasks);
        $this->assertNotContains('adhoc', array_column($returned, 'kind'));
        $this->assertSame(0, self::disabled()['\local_secbkextra\task\sync']);
    }

    /**
     * Headers whose list of plugins is not in its form.
     *
     * @return array
     */
    public static function header_provider(): array {
        $plugin = ['component' => 'local_secbkextra', 'version' => '1', 'standard' => false];
        return [
            'no list' => [['v' => 1]],
            'a list that is text' => [['plugins' => 'local_secbkextra']],
            'a list that is an object' => [['plugins' => ['a' => $plugin]]],
            'a plugin that is text' => [['plugins' => ['local_secbkextra']]],
            'a plugin without a component' => [['plugins' => [['version' => '1', 'standard' => false]]]],
            'a plugin without standard' => [['plugins' => [['component' => 'local_secbkextra', 'version' => '1']]]],
            'a component that is a number' => [['plugins' => [['component' => 5, 'standard' => false] + $plugin]]],
            'standard that is text' => [['plugins' => [['standard' => 'false'] + $plugin]]],
            'standard that is a number' => [['plugins' => [['standard' => 0] + $plugin]]],
            'standard that is null' => [['plugins' => [['standard' => null] + $plugin]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('header_provider')]
    /**
     * A header whose list of plugins is not in its form is refused before anything is stopped.
     *
     * @dataProvider header_provider
     * @param array $header The header.
     */
    public function test_a_header_not_in_its_form_is_refused_before_anything_is_stopped(array $header): void {
        global $DB;
        $this->live_site();
        try {
            temp_site_safety::apply($header);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_input_invalid', $e->errorcode);
        }
        $this->assertSame([], self::stops());
        $this->assertSame('0', $DB->get_field('config', 'value', ['name' => 'noemailever']));
        $this->assertEquals(1, $DB->get_field('message_processors', 'enabled', ['name' => 'email']));
    }

    public function test_check_accepts_a_header_in_its_form_and_writes_nothing(): void {
        temp_site_safety::check(self::header());
        temp_site_safety::check(self::header([]));
        $this->assertSame([], self::stops());
        $this->expectException(plugin_exception::class);
        temp_site_safety::check(['plugins' => [['component' => 'x']]]);
    }
}
