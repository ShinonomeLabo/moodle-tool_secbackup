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
use tool_secbackup\task\poll_status;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\autobackup_watch::class)]
/**
 * Tests for the watch on the automated backup of Moodle.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\autobackup_watch
 */
final class autobackup_watch_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Adds a row to the table of the course backups.
     *
     * @param int $start When the backup started.
     * @param int $end When it ended.
     */
    private function backup_row(int $start, int $end): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('backup_courses', ['courseid' => $course->id, 'laststarttime' => $start, 'lastendtime' => $end,
            'laststatus' => '1', 'nextstarttime' => 0]);
    }

    public function test_it_is_quiet_when_nothing_is_queued(): void {
        $this->assertFalse(autobackup_watch::busy());
    }

    public function test_a_queued_course_backup_makes_it_busy(): void {
        $task = new course_backup_task();
        $task->set_custom_data(['courseid' => 2]);
        \core\task\manager::queue_adhoc_task($task);
        $this->assertTrue(autobackup_watch::busy());
    }

    public function test_the_running_automated_backup_makes_it_busy(): void {
        global $DB;
        $name = '\\' . automated_backup_task::class;
        $DB->set_field('task_scheduled', 'timestarted', time(), ['classname' => $name]);
        $this->assertTrue(autobackup_watch::busy());
    }

    public function test_another_running_task_does_not(): void {
        global $DB;
        $DB->set_field('task_scheduled', 'timestarted', time(), ['classname' => '\\' . poll_status::class]);
        $this->assertFalse(autobackup_watch::busy());
    }

    public function test_describe_reads_the_setting_of_moodle(): void {
        set_config('backup_auto_active', 1, 'backup');
        set_config('backup_auto_weekdays', '0111110', 'backup');
        set_config('backup_auto_hour', 3, 'backup');
        set_config('backup_auto_minute', 30, 'backup');
        $this->assertSame([
            'backup_auto_active' => 1,
            'weekdays' => '0111110',
            'hour' => 3,
            'minute' => 30,
            'lastduration' => null,
        ], autobackup_watch::describe());
    }

    public function test_the_duration_is_from_the_first_start_to_the_last_end_of_the_last_round(): void {
        $this->backup_row(1000000, 1000100);
        // The round of the last day.
        $this->backup_row(2000000, 2000500);
        $this->backup_row(2000300, 2000900);
        $this->backup_row(2000700, 2000800);
        $this->assertSame(900, autobackup_watch::describe()['lastduration']);
    }

    public function test_a_row_that_ended_before_it_started_is_not_counted(): void {
        $this->backup_row(2000000, 2000500);
        $this->backup_row(2000600, 2000100);
        $this->assertSame(500, autobackup_watch::describe()['lastduration']);
    }

    public function test_a_round_with_no_end_has_no_duration(): void {
        $this->backup_row(2000000, 0);
        $this->assertNull(autobackup_watch::describe()['lastduration']);
    }

    public function test_courses_never_backed_up_give_no_duration(): void {
        $this->backup_row(0, 0);
        $this->assertNull(autobackup_watch::describe()['lastduration']);
    }
}
