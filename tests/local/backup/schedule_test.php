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

use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\instruction_factory;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\schedule::class)]
/**
 * Tests for the time slots of the backup runs.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\schedule
 */
final class schedule_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('siteid', fake_service::SITE_ID, 'tool_secbackup');
    }

    /**
     * Stores settings.
     *
     * @param array $changes Fields of the body to replace.
     * @return settings
     */
    private function settings(array $changes = []): settings {
        settings::apply(instruction_factory::make('settings', 1, instruction_factory::settings_body($changes)));
        return settings::current();
    }

    /**
     * A time.
     *
     * @param string $local Date and time as written in the zone.
     * @param string $zone The time zone.
     * @return int UNIX time.
     */
    private static function ts(string $local, string $zone): int {
        return (new \DateTimeImmutable($local, new \DateTimeZone($zone)))->getTimestamp();
    }

    public function test_the_window_is_told_with_its_zone_and_its_number_of_runs(): void {
        $this->assertSame(get_string('schedule_window_none', 'tool_secbackup'), schedule::window_text(settings::current()));
        $window = (object) ['from' => '02:00', 'to' => '05:00', 'timezone' => 'Asia/Tokyo'];
        $this->assertSame(get_string('schedule_window', 'tool_secbackup', $window), schedule::window_text($this->settings()));
        settings::apply(instruction_factory::make('settings', 2, instruction_factory::settings_body(['window_start' => '23:30',
            'window_end' => '01:00', 'runs_per_day' => 3, 'timezone' => 'Europe/Berlin'])));
        $settings = settings::current();
        $this->assertSame(get_string('schedule_window_runs', 'tool_secbackup', (object) ['from' => '23:30', 'to' => '01:00',
            'timezone' => 'Europe/Berlin', 'runs' => 3]), schedule::window_text($settings));
    }

    public function test_the_jitter_comes_from_the_site_id(): void {
        // Window of 180 minutes: the divisor is 30. The numbers are the first 4 bytes of the SHA-256 of the ID.
        $this->assertSame(4110802125 % 30, schedule::jitter('site-1', 180));
        $this->assertSame(15, schedule::jitter('site-1', 180));
        $this->assertSame(0, schedule::jitter('site-2', 180));
        $this->assertSame(3, schedule::jitter('site-3', 180));
        // A window of 90 minutes: a quarter is 22.
        $this->assertSame(4110802125 % 22, schedule::jitter('site-1', 90));
    }

    public function test_a_window_too_short_for_a_delay_gives_none(): void {
        $this->assertSame(0, schedule::jitter('site-1', 3));
        $this->assertSame(0, schedule::jitter('site-1', 0));
    }

    public function test_the_delay_is_at_most_a_quarter_of_the_window_and_30_minutes(): void {
        foreach (['site-1', 'site-4', 'site-5', 'a', 'b', 'c'] as $id) {
            $this->assertLessThan(30, schedule::jitter($id, 1440));
            $this->assertLessThan(7, schedule::jitter($id, 30));
        }
    }

    public function test_a_time_in_the_window_of_another_zone_is_inside(): void {
        $this->setTimezone('UTC');
        $s = $this->settings();
        $delay = schedule::jitter('site-1', 180);
        // The server runs in UTC: 17:30 on the 18th is 02:30 on the 19th in Tokyo.
        $now = self::ts('2026-10-18 17:30', 'UTC');
        $slot = schedule::slot_at($s, 'site-1', $now);
        $this->assertSame(self::ts('2026-10-19 02:00', 'Asia/Tokyo') + $delay * 60, $slot['start']);
        $this->assertSame(self::ts('2026-10-19 05:00', 'Asia/Tokyo'), $slot['end']);
        // 02:30 in UTC is 11:30 in Tokyo: outside.
        $this->assertNull(schedule::slot_at($s, 'site-1', self::ts('2026-10-19 02:30', 'UTC')));
    }

    public function test_the_slot_starts_after_the_delay_and_ends_at_the_end_of_the_window(): void {
        $s = $this->settings();
        $start = self::ts('2026-10-19 02:00', 'Asia/Tokyo') + 15 * 60;
        $end = self::ts('2026-10-19 05:00', 'Asia/Tokyo');
        $this->assertNull(schedule::slot_at($s, 'site-1', $start - 1));
        $this->assertSame(['start' => $start, 'end' => $end], schedule::slot_at($s, 'site-1', $start));
        $this->assertSame(['start' => $start, 'end' => $end], schedule::slot_at($s, 'site-1', $end - 1));
        $this->assertNull(schedule::slot_at($s, 'site-1', $end));
    }

    public function test_the_delay_is_the_same_every_day(): void {
        $s = $this->settings();
        $first = schedule::slot_at($s, 'site-1', self::ts('2026-10-19 03:00', 'Asia/Tokyo'));
        $second = schedule::slot_at($s, 'site-1', self::ts('2026-10-20 03:00', 'Asia/Tokyo'));
        $this->assertSame($first['start'] + 86400, $second['start']);
    }

    public function test_a_window_over_midnight(): void {
        $s = $this->settings(['timezone' => 'UTC', 'window_start' => '22:00', 'window_end' => '02:00']);
        // The window is 240 minutes; the delay of site-2 is 0.
        foreach (['2026-10-19 23:00', '2026-10-20 01:00', '2026-10-20 00:00', '2026-10-19 22:00'] as $time) {
            $slot = schedule::slot_at($s, 'site-2', self::ts($time, 'UTC'));
            $this->assertNotNull($slot, $time);
            $this->assertSame(self::ts('2026-10-19 22:00', 'UTC'), $slot['start'], $time);
            $this->assertSame(self::ts('2026-10-20 02:00', 'UTC'), $slot['end'], $time);
        }
        foreach (['2026-10-20 03:00', '2026-10-20 02:00', '2026-10-19 21:59', '2026-10-19 12:00'] as $time) {
            $this->assertNull(schedule::slot_at($s, 'site-2', self::ts($time, 'UTC')), $time);
        }
    }

    public function test_a_window_over_midnight_in_a_zone_other_than_the_server_s(): void {
        $s = $this->settings(['timezone' => 'Asia/Tokyo', 'window_start' => '22:00', 'window_end' => '02:00']);
        // 22:30 JST on the 19th is 13:30 UTC; 01:00 JST on the 20th is 16:00 UTC on the 19th; 03:00 JST is 18:00 UTC.
        $this->assertNotNull(schedule::slot_at($s, 'site-2', self::ts('2026-10-19 13:30', 'UTC')));
        $this->assertNotNull(schedule::slot_at($s, 'site-2', self::ts('2026-10-19 16:00', 'UTC')));
        $this->assertNull(schedule::slot_at($s, 'site-2', self::ts('2026-10-19 18:00', 'UTC')));
        $this->assertNull(schedule::slot_at($s, 'site-2', self::ts('2026-10-19 12:00', 'UTC')));
    }

    public function test_several_runs_a_day_have_their_own_slots(): void {
        // Four runs from the window 02:00-05:00: a slot every 6 hours, each of 3 hours (02:00, 08:00, 14:00, 20:00).
        $s = $this->settings(['runs_per_day' => 4]);
        $delay = schedule::jitter('site-1', 180) * 60;
        foreach (['02:00', '08:00', '14:00', '20:00'] as $from) {
            $begin = self::ts("2026-10-19 $from", 'Asia/Tokyo');
            $expected = ['start' => $begin + $delay, 'end' => $begin + 3 * 3600];
            $this->assertNull(schedule::slot_at($s, 'site-1', $begin + $delay - 1), $from);
            $this->assertSame($expected, schedule::slot_at($s, 'site-1', $begin + $delay), $from);
            $this->assertSame($expected, schedule::slot_at($s, 'site-1', $begin + 3 * 3600 - 1), $from);
            $this->assertNull(schedule::slot_at($s, 'site-1', $begin + 3 * 3600), $from);
        }
        $this->assertNull(schedule::slot_at($s, 'site-1', self::ts('2026-10-19 12:00', 'Asia/Tokyo')));
        $this->assertNull(schedule::slot_at($s, 'site-1', self::ts('2026-10-19 07:59', 'Asia/Tokyo')));
    }

    public function test_slots_of_a_window_over_midnight_with_several_runs(): void {
        // 23:00-01:00 twice a day: slots at 23:00 and 11:00, each of 2 hours.
        $s = $this->settings(['timezone' => 'UTC', 'runs_per_day' => 2, 'window_start' => '23:00', 'window_end' => '01:00']);
        $noon = schedule::slot_at($s, 'site-2', self::ts('2026-10-19 12:00', 'UTC'));
        $this->assertSame(
            ['start' => self::ts('2026-10-19 11:00', 'UTC'), 'end' => self::ts('2026-10-19 13:00', 'UTC')],
            $noon
        );
        $night = schedule::slot_at($s, 'site-2', self::ts('2026-10-19 00:30', 'UTC'));
        $this->assertSame(
            ['start' => self::ts('2026-10-18 23:00', 'UTC'), 'end' => self::ts('2026-10-19 01:00', 'UTC')],
            $night
        );
        $this->assertNull(schedule::slot_at($s, 'site-2', self::ts('2026-10-19 02:00', 'UTC')));
    }

    public function test_the_tightest_accepted_setting_gives_slots_that_meet(): void {
        // The settings accept no window longer than a slot: 6 hours, 4 runs, so each slot ends where the next begins.
        $s = $this->settings(['timezone' => 'UTC', 'runs_per_day' => 4, 'window_start' => '00:00', 'window_end' => '06:00']);
        $slot = schedule::slot_at($s, 'site-2', self::ts('2026-10-19 05:59', 'UTC'));
        $this->assertSame(self::ts('2026-10-19 06:00', 'UTC'), $slot['end']);
        $next = schedule::slot_at($s, 'site-2', self::ts('2026-10-19 06:00', 'UTC'));
        $this->assertSame(self::ts('2026-10-19 06:00', 'UTC'), $next['start']);
    }

    public function test_the_wall_clock_is_kept_over_a_change_to_summer_time(): void {
        // In New York the clocks went forward on 2026-03-08, so 02:00-05:00 that night is two hours long.
        $s = $this->settings(['timezone' => 'America/New_York']);
        $slot = schedule::slot_at($s, 'site-2', self::ts('2026-03-08 04:00', 'America/New_York'));
        $this->assertSame(self::ts('2026-03-08 05:00', 'America/New_York'), $slot['end']);
        $this->assertNull(schedule::slot_at($s, 'site-2', self::ts('2026-03-08 05:00', 'America/New_York')));
        $slot = schedule::slot_at($s, 'site-2', self::ts('2026-03-09 02:30', 'America/New_York'));
        $this->assertSame(self::ts('2026-03-09 02:00', 'America/New_York'), $slot['start']);
    }

    public function test_nothing_is_due_before_settings_are_received(): void {
        $this->assertNull(schedule::slot_at(settings::current(), 'site-1', time()));
        $this->assertFalse(schedule::due(settings::current(), 'site-1', time(), null));
    }

    public function test_a_run_is_due_in_a_slot_that_has_no_run_yet(): void {
        $s = $this->settings();
        $now = self::ts('2026-10-19 03:00', 'Asia/Tokyo');
        $this->assertTrue(schedule::due($s, 'site-1', $now, null));
        // The last run was yesterday.
        $this->assertTrue(schedule::due($s, 'site-1', $now, $now - 86400));
    }

    public function test_a_run_that_started_in_the_slot_blocks_a_second_one(): void {
        $s = $this->settings();
        $slot = schedule::slot_at($s, 'site-1', self::ts('2026-10-19 03:00', 'Asia/Tokyo'));
        $now = self::ts('2026-10-19 04:30', 'Asia/Tokyo');
        $this->assertFalse(schedule::due($s, 'site-1', $now, $slot['start']));
        $this->assertFalse(schedule::due($s, 'site-1', $now, $slot['start'] + 600));
        $this->assertTrue(schedule::due($s, 'site-1', $now, $slot['start'] - 1));
    }

    public function test_nothing_is_due_outside_a_slot(): void {
        $s = $this->settings();
        $this->assertFalse(schedule::due($s, 'site-1', self::ts('2026-10-19 12:00', 'Asia/Tokyo'), null));
    }

    public function test_the_next_slot_is_the_first_that_starts_at_or_after_a_time(): void {
        $s = $this->settings();
        $jitter = schedule::jitter(fake_service::SITE_ID, 180) * 60;
        $start = self::ts('2026-10-20 02:00', 'Asia/Tokyo') + $jitter;
        $end = self::ts('2026-10-20 05:00', 'Asia/Tokyo');
        $five = self::ts('2026-10-19 05:00', 'Asia/Tokyo');
        $noon = self::ts('2026-10-19 12:00', 'Asia/Tokyo');
        $this->assertSame(['start' => $start, 'end' => $end], schedule::next_slot($s, fake_service::SITE_ID, $five));
        $this->assertSame(['start' => $start, 'end' => $end], schedule::next_slot($s, fake_service::SITE_ID, $noon));
        // A slot that begins at that very time is the next one.
        $this->assertSame(['start' => $start, 'end' => $end], schedule::next_slot($s, fake_service::SITE_ID, $start));
        // Inside a slot, the next one is the one of the next day.
        $this->assertSame(
            ['start' => $start + 86400, 'end' => $end + 86400],
            schedule::next_slot($s, fake_service::SITE_ID, $start + 60)
        );
    }

    public function test_the_next_slot_of_several_runs_a_day_and_across_midnight(): void {
        $s = $this->settings(['runs_per_day' => 2]);
        $jitter = schedule::jitter(fake_service::SITE_ID, 180) * 60;
        $this->assertSame(
            ['start' => self::ts('2026-10-19 14:00', 'Asia/Tokyo') + $jitter, 'end' => self::ts('2026-10-19 17:00', 'Asia/Tokyo')],
            schedule::next_slot($s, fake_service::SITE_ID, self::ts('2026-10-19 05:00', 'Asia/Tokyo'))
        );
        settings::apply(instruction_factory::make('settings', 2, instruction_factory::settings_body(
            ['window_start' => '23:00', 'window_end' => '01:00']
        )));
        $s = settings::current();
        $jitter = schedule::jitter(fake_service::SITE_ID, 120) * 60;
        $this->assertSame(
            ['start' => self::ts('2026-10-19 23:00', 'Asia/Tokyo') + $jitter, 'end' => self::ts('2026-10-20 01:00', 'Asia/Tokyo')],
            schedule::next_slot($s, fake_service::SITE_ID, self::ts('2026-10-19 01:00', 'Asia/Tokyo'))
        );
    }
}
