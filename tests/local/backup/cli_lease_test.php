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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\cli_lease::class)]
/**
 * Tests for the lease of the command line on a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\cli_lease
 */
final class cli_lease_test extends \advanced_testcase {
    public function test_a_lease_holds_its_run_until_it_lapses(): void {
        $this->resetAfterTest();
        $this->assertFalse(cli_lease::held(7, 1000));
        cli_lease::renew(7, 1000);
        $this->assertTrue(cli_lease::held(7, 1000));
        $this->assertTrue(cli_lease::held(7, 1000 + cli_lease::LENGTH - 1));
        $this->assertFalse(cli_lease::held(7, 1000 + cli_lease::LENGTH));
        // Another run is not held.
        $this->assertFalse(cli_lease::held(8, 1000));
        // A renewal moves the end.
        cli_lease::renew(7, 1500);
        $this->assertTrue(cli_lease::held(7, 1000 + cli_lease::LENGTH));
    }

    public function test_the_lease_lasts_several_waits_of_the_command_line(): void {
        $this->assertSame(runner::RETRY_AFTER, cli_lease::RENEW_EVERY);
        $this->assertGreaterThanOrEqual(3 * cli_lease::RENEW_EVERY, cli_lease::LENGTH);
    }

    public function test_a_lease_that_is_released_holds_nothing(): void {
        $this->resetAfterTest();
        cli_lease::renew(7, 1000);
        cli_lease::release();
        $this->assertFalse(cli_lease::held(7, 1000));
        $this->assertFalse(get_config('tool_secbackup', 'clilease'));
    }

    public function test_the_lease_is_read_from_the_table_and_not_from_an_older_copy_of_the_settings(): void {
        global $DB;
        $this->resetAfterTest();
        cli_lease::release();
        $this->assertFalse(get_config('tool_secbackup', 'clilease'));
        // Another process (the command line) renews it: the settings of this one may be cached from before.
        $DB->insert_record('config_plugins', (object) ['plugin' => 'tool_secbackup', 'name' => 'clilease', 'value' => '7:2000']);
        $this->assertTrue(cli_lease::held(7, 1000));
    }

    public function test_a_value_that_is_not_a_lease_holds_nothing(): void {
        $this->resetAfterTest();
        foreach (['', '7', '7:', ':2000', '7:2000:1', 'x:2000', "7:2000\n"] as $value) {
            set_config('clilease', $value, 'tool_secbackup');
            $this->assertFalse(cli_lease::held(7, 1000), $value);
        }
    }
}
