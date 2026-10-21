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

    public function test_the_folder_of_a_run_that_goes_on_is_kept_fresh_at_every_status_request(): void {
        global $CFG;
        $this->resetAfterTest();
        $run = \tool_secbackup\local\backup\run_record::create('schedule', time());
        $folder = $CFG->tempdir . '/tool_secbackup/run-' . $run->id();
        mkdir($folder . '/journal', 0777, true);
        file_put_contents($folder . '/journal/files.jsonl', "[\"a\",1]\n");
        foreach ([$folder . '/journal/files.jsonl', $folder . '/journal', $folder] as $path) {
            touch($path, time() - 2 * DAYSECS);
        }
        // The site is not registered, so the request is not made: the folder is kept fresh all the same.
        $this->expectOutputString(get_string('task_poll_notregistered', 'tool_secbackup') . "\n");
        (new poll_status())->execute();
        clearstatcache();
        foreach ([$folder, $folder . '/journal', $folder . '/journal/files.jsonl'] as $path) {
            $this->assertGreaterThan(time() - 60, filemtime($path), $path);
        }
    }

    public function test_the_task_prints_the_answer_of_the_schedule_when_it_was_asked(): void {
        $this->resetAfterTest();
        set_config('lastschedule', json_encode(['at' => 1, 'answer' => 'busy', 'cause' => 'disk_low']), 'tool_secbackup');
        $this->assertSame([
            get_string('task_poll_ok', 'tool_secbackup'),
            get_string('task_poll_start', 'tool_secbackup', get_string('start_cause_disk_low', 'tool_secbackup')),
        ], poll_status::lines('ok', 'busy'));
        $this->assertSame([
            get_string('task_poll_ok', 'tool_secbackup'),
            get_string('task_poll_start', 'tool_secbackup', get_string('start_reason_outsidewindow', 'tool_secbackup')),
        ], poll_status::lines('ok', 'outsidewindow'));
        // The schedule is asked only after a status request that succeeded.
        $this->assertSame([get_string('task_poll_failed', 'tool_secbackup')], poll_status::lines('failed', null));
    }

    public function test_the_task_prints_one_line(): void {
        $this->resetAfterTest();
        ob_start();
        (new poll_status())->execute();
        $output = ob_get_clean();
        $this->assertSame(1, substr_count($output, "\n"));
    }
}
