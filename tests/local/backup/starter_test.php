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

use core\task\course_backup_task;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\instruction_factory;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\starter::class)]
/**
 * Tests for the decision to start a backup run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\starter
 */
final class starter_test extends \advanced_testcase {
    /** @var string memory_limit before the test. */
    private string $memory;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->memory = (string) ini_get('memory_limit');
        ini_set('memory_limit', '512M');
    }

    protected function tearDown(): void {
        ini_set('memory_limit', $this->memory);
        parent::tearDown();
    }

    /**
     * Registers the site and stores settings: 02:00-05:00 in Tokyo, once a day.
     */
    private function ready(): void {
        set_config('siteid', fake_service::SITE_ID, 'tool_secbackup');
        settings::apply(instruction_factory::make('settings', 1, instruction_factory::settings_body()));
    }

    /**
     * 03:00 in Tokyo on a day, which is inside the window whatever the delay of the site.
     *
     * @param string $date The date.
     * @return int
     */
    private static function inside(string $date = '2026-10-19'): int {
        return (new \DateTimeImmutable($date . ' 03:00', new \DateTimeZone('Asia/Tokyo')))->getTimestamp();
    }

    /**
     * A watch that finds nothing wrong.
     *
     * @return null
     */
    public static function calm() {
        return null;
    }

    public function test_an_unregistered_site_starts_nothing(): void {
        $this->assertSame('notregistered', starter::maybe_start(self::inside(), [self::class, 'calm']));
        $this->assertSame('notregistered', starter::start_now(self::inside(), [self::class, 'calm']));
    }

    public function test_a_site_without_settings_starts_nothing(): void {
        set_config('siteid', fake_service::SITE_ID, 'tool_secbackup');
        $this->assertSame('notreceived', starter::maybe_start(self::inside(), [self::class, 'calm']));
        $this->assertSame('notreceived', starter::start_now(self::inside(), [self::class, 'calm']));
        $this->assertSame([], run_record::recent(1));
    }

    public function test_outside_the_window_the_schedule_does_not_start(): void {
        $this->ready();
        $noon = (new \DateTimeImmutable('2026-10-19 12:00', new \DateTimeZone('Asia/Tokyo')))->getTimestamp();
        $this->assertSame('outsidewindow', starter::maybe_start($noon, [self::class, 'calm']));
        $this->assertSame([], run_record::recent(1));
    }

    public function test_the_button_starts_outside_the_window(): void {
        $this->ready();
        $noon = (new \DateTimeImmutable('2026-10-19 12:00', new \DateTimeZone('Asia/Tokyo')))->getTimestamp();
        $this->assertSame('started', starter::start_now($noon, [self::class, 'calm']));
        $run = run_record::active();
        $this->assertSame('manual', $run->trigger());
        $this->assertSame($noon, $run->started_at());
    }

    public function test_the_schedule_starts_one_run_in_the_window(): void {
        $this->ready();
        $now = self::inside();
        $this->assertSame('started', starter::maybe_start($now, [self::class, 'calm']));
        $run = run_record::active();
        $this->assertSame('schedule', $run->trigger());
        $this->assertSame($now, $run->started_at());
        $this->assertCount(1, run_record::recent(5));
    }

    public function test_a_second_run_is_not_started_while_one_is_active(): void {
        $this->ready();
        $this->assertSame('started', starter::maybe_start(self::inside(), [self::class, 'calm']));
        // The run that started in this slot is the reason for the schedule: nothing more is due.
        $this->assertSame('outsidewindow', starter::maybe_start(self::inside() + 60, [self::class, 'calm']));
        $this->assertSame('running', starter::start_now(self::inside() + 60, [self::class, 'calm']));
        $this->assertCount(1, run_record::recent(5));
    }

    public function test_a_run_of_an_earlier_day_that_is_still_active_blocks_the_schedule(): void {
        $this->ready();
        run_record::create('cli', self::inside('2026-10-17'))->pause(self::inside('2026-10-17'), self::inside('2026-10-18'));
        $this->assertSame('running', starter::maybe_start(self::inside(), [self::class, 'calm']));
        $this->assertCount(1, run_record::recent(5));
    }

    public function test_a_run_that_ended_in_the_slot_is_not_started_again(): void {
        $this->ready();
        $this->assertSame('started', starter::maybe_start(self::inside(), [self::class, 'calm']));
        run_record::active()->abort('connection', self::inside() + 60);
        $this->assertSame('outsidewindow', starter::maybe_start(self::inside() + 120, [self::class, 'calm']));
        // The button does not look at the slot.
        $this->assertSame('started', starter::start_now(self::inside() + 180, [self::class, 'calm']));
        // The next day the schedule starts again, once the manual run has ended.
        run_record::active()->finish(self::inside() + 240);
        $this->assertSame('started', starter::maybe_start(self::inside('2026-10-20'), [self::class, 'calm']));
    }

    public function test_a_lock_that_cannot_be_taken_means_a_run_is_starting(): void {
        global $CFG;
        $this->ready();
        // The lock of the database is held again by the same connection; a file lock is not.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $factory = \core\lock\lock_config::get_lock_factory('tool_secbackup_run');
        $lock = $factory->get_lock('start', 0);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame('running', starter::maybe_start(self::inside(), [self::class, 'calm']));
        } finally {
            $lock->release();
        }
        $this->assertSame([], run_record::recent(1));
        $this->assertSame('started', starter::maybe_start(self::inside(), [self::class, 'calm']));
    }

    public function test_the_lock_is_released_after_a_start(): void {
        $this->ready();
        starter::maybe_start(self::inside(), [self::class, 'calm']);
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('start', 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    public function test_the_lock_is_released_when_the_check_fails(): void {
        $this->ready();
        $this->expectException(\RuntimeException::class);
        try {
            starter::maybe_start(self::inside(), static function (): ?string {
                throw new \RuntimeException('probe');
            });
        } finally {
            $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('start', 0);
            $this->assertNotFalse($lock);
            $lock->release();
        }
    }

    public function test_a_queued_course_backup_stops_the_start(): void {
        $this->ready();
        $task = new course_backup_task();
        $task->set_custom_data(['courseid' => 2]);
        \core\task\manager::queue_adhoc_task($task);
        $this->assertSame('autobackup', starter::maybe_start(self::inside(), [self::class, 'calm']));
        $this->assertSame('autobackup', starter::start_now(self::inside(), [self::class, 'calm']));
        $this->assertSame([], run_record::recent(1));
    }

    /**
     * Every verdict of the watch.
     *
     * @return string[][]
     */
    public static function verdicts(): array {
        return ['server_busy' => ['server_busy'], 'disk_low' => ['disk_low'], 'db_slow' => ['db_slow'], 'nfs_slow' => ['nfs_slow']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verdicts')]
    /**
     * Any verdict of the watch holds the start back.
     *
     * @dataProvider verdicts
     * @param string $verdict What the watch says.
     */
    public function test_a_busy_server_stops_the_start(string $verdict): void {
        $this->ready();
        $this->assertSame('busy', starter::maybe_start(self::inside(), static fn(): string => $verdict));
        $this->assertSame('busy', starter::start_now(self::inside(), static fn(): string => $verdict));
        $this->assertSame([], run_record::recent(1));
    }

    public function test_the_watch_of_this_server_is_used_by_default(): void {
        $this->ready();
        // What the server really says is not known here; the run starts or the server is busy, and nothing else.
        $this->assertContains(starter::maybe_start(self::inside()), ['started', 'busy']);
    }

    /**
     * Skews of the clock in seconds and what they give.
     *
     * @return array[]
     */
    public static function skews(): array {
        return [
            'the same clock' => [0, 'started'],
            '299 seconds fast' => [299, 'started'],
            '299 seconds slow' => [-299, 'started'],
            '300 seconds fast' => [300, 'clock'],
            '300 seconds slow' => [-300, 'clock'],
            'ten minutes' => [600, 'clock'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skews')]
    /**
     * A clock that is 300 seconds or more off holds the start back.
     *
     * @dataProvider skews
     * @param int $skew The skew.
     * @param string $expected The answer.
     */
    public function test_the_clock_must_be_within_300_seconds(int $skew, string $expected): void {
        $this->ready();
        set_config('clockskew', $skew, 'tool_secbackup');
        $this->assertSame($expected, starter::maybe_start(self::inside(), [self::class, 'calm']));
    }

    public function test_an_unknown_clock_skew_does_not_stop_the_start(): void {
        $this->ready();
        $this->assertNull(\tool_secbackup\local\enrollment\state::load()->clock_skew());
        $this->assertSame('started', starter::maybe_start(self::inside(), [self::class, 'calm']));
    }

    /**
     * Values of memory_limit and what they give.
     *
     * @return array[]
     */
    public static function memory_limits(): array {
        return [
            '128M' => ['128M', 'memorylimit'],
            '255M' => ['255M', 'memorylimit'],
            '1G' => ['1G', 'started'],
            '256M' => ['256M', 'started'],
            'no limit' => ['-1', 'started'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memory_limits')]
    /**
     * A memory limit under 256 MB holds the start back; no limit does not.
     *
     * @dataProvider memory_limits
     * @param string $limit The ini value.
     * @param string $expected The answer.
     */
    public function test_the_memory_limit_must_be_256_mb_or_more(string $limit, string $expected): void {
        $this->ready();
        ini_set('memory_limit', $limit);
        $this->assertSame($expected, starter::maybe_start(self::inside(), [self::class, 'calm']));
    }

    public function test_the_reasons_come_in_order(): void {
        $this->ready();
        $noon = (new \DateTimeImmutable('2026-10-19 12:00', new \DateTimeZone('Asia/Tokyo')))->getTimestamp();
        set_config('clockskew', 900, 'tool_secbackup');
        ini_set('memory_limit', '128M');
        $this->assertSame('outsidewindow', starter::maybe_start($noon, [self::class, 'calm']));
        $this->assertSame('clock', starter::start_now($noon, [self::class, 'calm']));
        set_config('clockskew', 0, 'tool_secbackup');
        $this->assertSame('memorylimit', starter::start_now($noon, [self::class, 'calm']));
    }
}
