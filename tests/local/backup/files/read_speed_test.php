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

namespace tool_secbackup\local\backup\files;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\files\read_speed::class)]
/**
 * Tests for the speed of the reads of the file store.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\files\read_speed
 */
final class read_speed_test extends \basic_testcase {
    /** @var int One MiB. */
    private const MIB = 1048576;

    public function test_nothing_read_has_no_speed(): void {
        $this->assertNull((new read_speed())->mbps());
    }

    public function test_the_speed_is_the_bytes_over_the_seconds_of_the_reads(): void {
        $speed = new read_speed();
        $speed->add(4 * self::MIB, 2.0);
        $this->assertEqualsWithDelta(2.0, $speed->mbps(), 1e-9);
        $speed->add(8 * self::MIB, 1.0);
        $this->assertEqualsWithDelta(4.0, $speed->mbps(), 1e-9);
    }

    public function test_only_the_last_64_mib_count(): void {
        $speed = new read_speed();
        // A slow start is forgotten once 64 MiB have been read since.
        $speed->add(32 * self::MIB, 32.0);
        $speed->add(32 * self::MIB, 1.0);
        $this->assertEqualsWithDelta(64 / 33, $speed->mbps(), 1e-9);
        $speed->add(32 * self::MIB, 1.0);
        $this->assertEqualsWithDelta(32.0, $speed->mbps(), 1e-9);
        // The newest read alone counts when it holds 64 MiB or more.
        $speed->add(100 * self::MIB, 50.0);
        $this->assertEqualsWithDelta(2.0, $speed->mbps(), 1e-9);
    }

    public function test_a_read_that_took_no_time_does_not_divide_by_zero(): void {
        $speed = new read_speed();
        $speed->add(self::MIB, 0.0);
        $this->assertGreaterThan(1000.0, $speed->mbps());
    }

    public function test_the_clock_measures_the_reads(): void {
        $times = [10.0, 10.5];
        $speed = new read_speed(static function () use (&$times): float {
            return array_shift($times);
        });
        $start = $speed->clock();
        $speed->add(self::MIB, $speed->clock() - $start);
        $this->assertEqualsWithDelta(2.0, $speed->mbps(), 1e-9);
        $default = new read_speed();
        $this->assertGreaterThanOrEqual($default->clock(), $default->clock());
    }
}
