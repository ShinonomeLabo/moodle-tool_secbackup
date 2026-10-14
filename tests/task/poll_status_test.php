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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\task\poll_status::class)]
/**
 * Tests for the scheduled status request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\task\poll_status
 */
final class poll_status_test extends \advanced_testcase {
    public function test_name(): void {
        $this->assertSame(get_string('task_poll_status', 'tool_secbackup'), (new poll_status())->get_name());
    }

    public function test_unregistered_site(): void {
        $this->resetAfterTest();
        $this->expectOutputString(get_string('task_poll_notregistered', 'tool_secbackup') . "\n");
        (new poll_status())->execute();
    }

    public function test_task_is_scheduled_every_five_minutes(): void {
        $task = \core\task\manager::get_scheduled_task(poll_status::class);
        $this->assertSame('*/5', $task->get_minute());
        $this->assertSame('*', $task->get_hour());
    }

    public function test_every_outcome_and_the_missing_key_have_a_message(): void {
        // The task prints the string of the outcome; the poller records the string of the missing key.
        foreach (['task_poll_ok', 'task_poll_failed', 'task_poll_notregistered', 'task_poll_status', 'signingkey_missing'] as $id) {
            $this->assertTrue(get_string_manager()->string_exists($id, 'tool_secbackup'), $id);
        }
    }

    public function test_the_task_prints_one_line(): void {
        $this->resetAfterTest();
        ob_start();
        (new poll_status())->execute();
        $output = ob_get_clean();
        $this->assertSame(1, substr_count($output, "\n"));
    }
}
