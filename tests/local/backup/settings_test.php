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

use tool_secbackup\local\api\instruction;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\instruction_factory;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\settings::class)]
/**
 * Tests for the backup settings the service sends.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\settings
 */
final class settings_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A valid body with some fields replaced.
     *
     * @param array $changes Fields to replace.
     * @return array
     */
    public static function body(array $changes = []): array {
        return instruction_factory::settings_body($changes);
    }

    /**
     * A settings instruction signed by the test key.
     *
     * @param int $seq Sequence number.
     * @param array $body Body.
     * @return instruction
     */
    public static function instruction(int $seq, array $body): instruction {
        return instruction_factory::make('settings', $seq, $body);
    }

    public function test_nothing_is_received_at_first(): void {
        $settings = settings::current();
        $this->assertFalse($settings->is_received());
        $this->assertSame(0, $settings->seq());
    }

    public function test_a_valid_instruction_is_stored_and_read(): void {
        $this->assertTrue(settings::apply(self::instruction(5, self::body([
            'timezone' => 'America/New_York',
            'window_start' => '22:30',
            'window_end' => '01:00',
            'runs_per_day' => 2,
            'full_interval' => 'monthly',
            'bandwidth_mbps' => 250,
            'include_logs' => true,
        ]))));
        $settings = settings::current();
        $this->assertTrue($settings->is_received());
        $this->assertSame(5, $settings->seq());
        $this->assertSame('America/New_York', $settings->timezone()->getName());
        $this->assertSame(22 * 60 + 30, $settings->window_start());
        $this->assertSame(60, $settings->window_end());
        $this->assertSame(2, $settings->runs_per_day());
        $this->assertSame('monthly', $settings->full_interval());
        $this->assertSame(250, $settings->bandwidth_mbps());
        $this->assertTrue($settings->include_logs());
    }

    public function test_default_values_before_anything_is_received(): void {
        $settings = settings::current();
        $this->assertSame('UTC', $settings->timezone()->getName());
        $this->assertSame(0, $settings->window_start());
        $this->assertSame(0, $settings->window_end());
        $this->assertSame(1, $settings->runs_per_day());
        $this->assertSame('weekly', $settings->full_interval());
        $this->assertSame(0, $settings->bandwidth_mbps());
        $this->assertFalse($settings->include_logs());
    }

    public function test_an_older_or_equal_seq_changes_nothing(): void {
        $this->assertTrue(settings::apply(self::instruction(5, self::body())));
        $this->assertFalse(settings::apply(self::instruction(5, self::body(['window_start' => '03:00']))));
        $this->assertFalse(settings::apply(self::instruction(4, self::body(['window_start' => '03:00']))));
        $this->assertSame(2 * 60, settings::current()->window_start());
        $this->assertSame(5, settings::current()->seq());
        $this->assertTrue(settings::apply(self::instruction(6, self::body(['window_start' => '03:00']))));
        $this->assertSame(3 * 60, settings::current()->window_start());
    }

    public function test_a_window_over_midnight_is_accepted(): void {
        $this->assertTrue(settings::apply(self::instruction(1, self::body(['window_start' => '22:00', 'window_end' => '02:00']))));
        $this->assertSame(22 * 60, settings::current()->window_start());
        $this->assertSame(2 * 60, settings::current()->window_end());
    }

    /**
     * Bodies that break a rule.
     *
     * @return array[]
     */
    public static function invalid_bodies(): array {
        return [
            'four runs in a window of seven hours' => [self::body(['runs_per_day' => 4, 'window_end' => '09:00'])],
            'a window of 24 hours with two runs' => [self::body(['runs_per_day' => 2, 'window_end' => '14:30'])],
            'a window of no length' => [self::body(['window_end' => '02:00'])],
            'a time not on the half hour' => [self::body(['window_start' => '02:15'])],
            'a time of 24:00' => [self::body(['window_end' => '24:00'])],
            'a time with a final line feed' => [self::body(['window_end' => "05:00\n"])],
            'a time that is a number' => [self::body(['window_end' => 500])],
            'no runs' => [self::body(['runs_per_day' => 0])],
            'too many runs' => [self::body(['runs_per_day' => 25])],
            'runs as text' => [self::body(['runs_per_day' => '1'])],
            'an unknown interval' => [self::body(['full_interval' => 'daily'])],
            'an interval that is not text' => [self::body(['full_interval' => 1])],
            'a negative bandwidth' => [self::body(['bandwidth_mbps' => -1])],
            'a bandwidth over 1000' => [self::body(['bandwidth_mbps' => 1001])],
            'a bandwidth as text' => [self::body(['bandwidth_mbps' => '10'])],
            'logs as a number' => [self::body(['include_logs' => 1])],
            'a time zone that does not exist' => [self::body(['timezone' => 'Mars/Base'])],
            'a time zone that is not text' => [self::body(['timezone' => 9])],
            'an empty time zone' => [self::body(['timezone' => ''])],
            'a time zone with a null byte' => [self::body(['timezone' => "UTC\0"])],
            'a time zone that is far too long' => [self::body(['timezone' => str_repeat('A', 100)])],
            'an extra field' => [self::body(['extra' => 1])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_bodies')]
    /**
     * A body that breaks a rule is refused and nothing is stored.
     *
     * @dataProvider invalid_bodies
     * @param array $body Body.
     */
    public function test_invalid_bodies_are_refused(array $body): void {
        try {
            settings::apply(self::instruction(3, $body));
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('instruction_invalid', $e->errorcode);
        }
        $this->assertFalse(settings::current()->is_received());
    }

    public function test_a_body_without_a_field_is_refused(): void {
        foreach (array_keys(self::body()) as $field) {
            $body = self::body();
            unset($body[$field]);
            try {
                settings::apply(self::instruction(3, $body));
                $this->fail('No exception without ' . $field);
            } catch (envelope_exception $e) {
                $this->assertSame('instruction_invalid', $e->errorcode, $field);
            }
        }
    }

    public function test_the_longest_window_for_the_runs_is_accepted(): void {
        $this->assertTrue(settings::apply(self::instruction(1, self::body(['runs_per_day' => 4, 'window_end' => '08:00']))));
        $this->assertTrue(settings::apply(self::instruction(2, self::body(['runs_per_day' => 1, 'window_end' => '01:30']))));
        $this->assertSame(1 * 60 + 30, settings::current()->window_end());
    }

    public function test_an_instruction_of_another_kind_is_a_coding_error(): void {
        $other = instruction_factory::make('aws_ranges', 1, self::body());
        $this->expectException(\coding_exception::class);
        settings::apply($other);
    }

    public function test_a_stored_value_that_cannot_be_read_is_treated_as_not_received(): void {
        set_config('backupsettings', 'garbage', 'tool_secbackup');
        set_config('backupsettingsseq', '3', 'tool_secbackup');
        $this->assertFalse(settings::current()->is_received());
        // A new instruction is accepted over the damaged one, whatever the stored seq says.
        $this->assertTrue(settings::apply(self::instruction(1, self::body())));
        $this->assertTrue(settings::current()->is_received());
    }
}
