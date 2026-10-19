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

use tool_secbackup\local\backup\run_record;
use tool_secbackup\tests\run_fixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\task\run_backup::class)]
/**
 * Tests for the task that moves a backup run forward and queues itself again.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\task\run_backup
 */
final class run_backup_test extends \advanced_testcase {
    /** @var run_fixture The site and the service. */
    private run_fixture $fixture;

    /** @var string[] The lines that the task wrote. */
    private array $lines = [];

    /** @var int[] The budgets that the steps were given. */
    private array $budgets = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->fixture = new run_fixture(run_fixture::tokyo('2026-10-19 03:00'));
    }

    /**
     * The tasks that step a run, as queued.
     *
     * @return \stdClass[]
     */
    private static function queued(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc', ['classname' => '\\' . run_backup::class], 'id'));
    }

    /**
     * Marks the queued tasks as started, as cron does with the task that it runs.
     */
    private static function start_queued(): void {
        global $DB;
        $DB->set_field('task_adhoc', 'timestarted', time(), ['classname' => '\\' . run_backup::class]);
    }

    public function test_name(): void {
        $this->assertSame(get_string('task_run_backup', 'tool_secbackup'), (new run_backup())->get_name());
    }

    public function test_without_a_run_the_task_does_nothing_and_is_not_queued_again(): void {
        $this->expectOutputString(get_string('task_run_backup_none', 'tool_secbackup') . "\n");
        (new run_backup())->execute();
        $this->assertSame([], self::queued());
    }

    public function test_a_run_with_more_to_do_is_stepped_for_600_seconds_and_queued_again_at_once(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $result = (new run_backup())->step_with(
            fn(run_record $r, int $budget): string => $this->answer($budget, 'continue'),
            $this->fixture->clock(),
            $this->writer()
        );
        $this->assertSame('continue', $result);
        $this->assertSame([600], $this->budgets);
        $this->assertSame([get_string('task_run_backup_continue', 'tool_secbackup')], $this->lines);
        $queued = self::queued();
        $this->assertCount(1, $queued);
        $this->assertLessThanOrEqual(time(), (int) $queued[0]->nextruntime);
        $this->assertSame($run->id(), run_record::active()->id());
    }

    public function test_a_run_that_waits_is_queued_for_the_time_it_waits_for(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $run->pause($this->fixture->time, $this->fixture->time + 900);
        $this->assertSame('continue', (new run_backup())->step_with(
            fn(run_record $r, int $budget): string => $this->answer($budget, 'continue'),
            $this->fixture->clock(),
            $this->writer()
        ));
        $this->assertSame($this->fixture->time + 900, (int) self::queued()[0]->nextruntime);
    }

    public function test_a_paused_run_is_queued_for_its_next_attempt_or_in_five_minutes(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $step = fn(run_record $r, int $budget): string => $this->answer($budget, 'paused');
        $this->assertSame('paused', (new run_backup())->step_with($step, $this->fixture->clock(), $this->writer()));
        $this->assertSame($this->fixture->time + 300, (int) self::queued()[0]->nextruntime);
        $run->pause($this->fixture->time, $this->fixture->time + 7200);
        // The task that was queued runs now.
        self::start_queued();
        (new run_backup())->step_with($step, $this->fixture->clock(), $this->writer());
        $this->assertSame($this->fixture->time + 7200, (int) self::queued()[1]->nextruntime);
    }

    public function test_a_run_that_ended_is_not_queued_again(): void {
        foreach (['done', 'aborted'] as $outcome) {
            run_record::create('manual', $this->fixture->time);
            $this->assertSame($outcome, (new run_backup())->step_with(
                fn(run_record $r, int $budget): string => $this->answer($budget, $outcome),
                $this->fixture->clock(),
                $this->writer()
            ));
            run_record::active()?->abort('server_busy', $this->fixture->time);
        }
        $this->assertSame([], self::queued());
    }

    public function test_while_another_process_steps_the_run_the_task_does_nothing(): void {
        global $CFG;
        run_record::create('manual', $this->fixture->time);
        // The lock of the database is held again by the same connection; a file lock is not.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('step', 0);
        $this->assertNotFalse($lock);
        try {
            $this->assertNull((new run_backup())->step_with(
                fn(run_record $r, int $budget): string => $this->answer($budget, 'continue'),
                $this->fixture->clock(),
                $this->writer()
            ));
            $this->assertSame([], $this->budgets);
            $this->assertSame([], self::queued());
        } finally {
            $lock->release();
        }
        $this->assertSame([get_string('task_run_backup_locked', 'tool_secbackup')], $this->lines);
    }

    public function test_the_lock_is_released_after_a_step_that_fails(): void {
        global $CFG;
        run_record::create('manual', $this->fixture->time);
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        try {
            (new run_backup())->step_with(static function (): string {
                throw new \coding_exception('broken');
            }, $this->fixture->clock(), $this->writer());
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('step', 0);
            $this->assertNotFalse($lock);
            $lock->release();
        }
    }

    public function test_the_task_steps_the_run_with_the_runner_of_the_site(): void {
        $run = run_record::create('manual', time());
        // Far ahead of the real clock, which the runner of the site reads.
        $run->pause(time(), time() + 31536000);
        ob_start();
        (new run_backup())->execute();
        $output = ob_get_clean();
        // A run that waits is not touched: nothing is asked of the network.
        $this->assertSame(get_string('task_run_backup_continue', 'tool_secbackup') . "\n", $output);
        $this->assertSame([], $this->fixture->requests);
        $this->assertCount(1, self::queued());
    }

    /**
     * A writer of the lines of the task that keeps them.
     *
     * @return callable
     */
    private function writer(): callable {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    /**
     * Records a budget and answers.
     *
     * @param int $budget The budget.
     * @param string $result The answer.
     * @return string
     */
    private function answer(int $budget, string $result): string {
        $this->budgets[] = $budget;
        return $result;
    }

    public function test_a_task_that_waits_already_is_the_only_one(): void {
        run_record::create('manual', $this->fixture->time);
        run_backup::queue();
        $this->assertCount(1, self::queued());
        run_backup::queue($this->fixture->time + 900);
        $this->assertCount(1, self::queued());
        $this->assertSame('continue', (new run_backup())->step_with(
            fn(run_record $r, int $budget): string => $this->answer($budget, 'continue'),
            $this->fixture->clock(),
            $this->writer()
        ));
        $this->assertCount(1, self::queued(), 'A step of the command line does not queue a second chain.');
        self::start_queued();
        (new run_backup())->step_with(
            fn(run_record $r, int $budget): string => $this->answer($budget, 'continue'),
            $this->fixture->clock(),
            $this->writer()
        );
        $queued = self::queued();
        $this->assertCount(2, $queued, 'The task that runs queues the next one.');
        $this->assertNull($queued[1]->timestarted);
    }

    public function test_a_task_that_has_no_attempts_left_does_not_keep_the_run_from_its_next(): void {
        global $DB;
        run_record::create('manual', $this->fixture->time);
        run_backup::queue();
        $DB->set_field('task_adhoc', 'attemptsavailable', 0, ['classname' => '\\' . run_backup::class]);
        run_backup::queue();
        $this->assertCount(2, self::queued());
    }
}
