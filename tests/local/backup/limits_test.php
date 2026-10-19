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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\limits::class)]
/**
 * Tests for the limits of the run engine.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\limits
 */
final class limits_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_defaults(): void {
        global $CFG;
        $limits = limits::current();
        $this->assertSame(2.0, $limits->loadpercore);
        $this->assertSame(256, $limits->minmemorymb);
        $this->assertSame(500, $limits->maxdbms);
        $this->assertSame(5.0, $limits->minnfsmbps);
        $this->assertSame($CFG->tempdir . '/tool_secbackup', $limits->tempdir);
        $this->assertSame(0, $limits->tempbudgetmb);
        $this->assertSame(0, $limits->bandwidthmbps);
    }

    public function test_stored_values_are_used(): void {
        foreach (
            ['loadpercore' => '1.5', 'minmemorymb' => '512', 'maxdbms' => '250', 'minnfsmbps' => '2.25',
                'tempdir' => '/var/tmp/secbk/', 'tempbudgetmb' => '1024', 'bandwidthmbps' => '40'] as $name => $value
        ) {
            set_config($name, $value, 'tool_secbackup');
        }
        $limits = limits::current();
        $this->assertSame(1.5, $limits->loadpercore);
        $this->assertSame(512, $limits->minmemorymb);
        $this->assertSame(250, $limits->maxdbms);
        $this->assertSame(2.25, $limits->minnfsmbps);
        $this->assertSame('/var/tmp/secbk', $limits->tempdir);
        $this->assertSame(1024, $limits->tempbudgetmb);
        $this->assertSame(40, $limits->bandwidthmbps);
    }

    public function test_zero_means_unlimited_budget_and_bandwidth_and_no_floors(): void {
        set_config('tempbudgetmb', '0', 'tool_secbackup');
        set_config('bandwidthmbps', '0', 'tool_secbackup');
        set_config('minmemorymb', '0', 'tool_secbackup');
        set_config('minnfsmbps', '0', 'tool_secbackup');
        $limits = limits::current();
        $this->assertSame(0, $limits->tempbudgetmb);
        $this->assertSame(0, $limits->bandwidthmbps);
        $this->assertSame(0, $limits->minmemorymb);
        $this->assertSame(0.0, $limits->minnfsmbps);
    }

    /**
     * Values that are not numbers in their range.
     *
     * @return array[]
     */
    public static function invalid_numbers(): array {
        return [
            'text' => ['abc'],
            'negative' => ['-1'],
            'empty' => [''],
            'exponent' => ['1e3'],
            'trailing newline' => ["3\n"],
            'too long' => ['1234567890123'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_numbers')]
    /**
     * Invalid load and database values give the defaults.
     *
     * @dataProvider invalid_numbers
     * @param string $value The stored value.
     */
    public function test_invalid_load_and_database_values_give_the_defaults(string $value): void {
        set_config('loadpercore', $value, 'tool_secbackup');
        set_config('maxdbms', $value, 'tool_secbackup');
        $limits = limits::current();
        $this->assertSame(2.0, $limits->loadpercore);
        $this->assertSame(500, $limits->maxdbms);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_numbers')]
    /**
     * Invalid whole numbers give the defaults.
     *
     * @dataProvider invalid_numbers
     * @param string $value The stored value.
     */
    public function test_invalid_whole_numbers_give_the_defaults(string $value): void {
        set_config('minmemorymb', $value, 'tool_secbackup');
        set_config('tempbudgetmb', $value, 'tool_secbackup');
        set_config('bandwidthmbps', $value, 'tool_secbackup');
        set_config('minnfsmbps', $value, 'tool_secbackup');
        $limits = limits::current();
        $this->assertSame(256, $limits->minmemorymb);
        $this->assertSame(0, $limits->tempbudgetmb);
        $this->assertSame(0, $limits->bandwidthmbps);
        $this->assertSame(5.0, $limits->minnfsmbps);
    }

    public function test_zero_is_not_allowed_for_load_and_database_time(): void {
        set_config('loadpercore', '0', 'tool_secbackup');
        set_config('maxdbms', '0', 'tool_secbackup');
        $limits = limits::current();
        $this->assertSame(2.0, $limits->loadpercore);
        $this->assertSame(500, $limits->maxdbms);
    }

    public function test_a_fraction_is_not_a_whole_number(): void {
        set_config('minmemorymb', '12.5', 'tool_secbackup');
        set_config('loadpercore', '0.05', 'tool_secbackup');
        $limits = limits::current();
        $this->assertSame(256, $limits->minmemorymb);
        $this->assertSame(2.0, $limits->loadpercore);
    }

    /**
     * Folder settings that cannot be used.
     *
     * @return array[]
     */
    public static function unusable_directories(): array {
        return [
            'relative' => ['tmp/secbk'],
            'parent segment' => ['/var/tmp/../etc'],
            'parent at the end' => ['/var/tmp/..'],
            'parent windows' => ['C:\\data\\..\\x'],
            'control character' => ["/var/tmp/a\nb"],
            'only slashes' => ['///'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusable_directories')]
    /**
     * An unusable folder gives the default.
     *
     * @dataProvider unusable_directories
     * @param string $value The stored folder.
     */
    public function test_an_unusable_folder_gives_the_default(string $value): void {
        global $CFG;
        set_config('tempdir', $value, 'tool_secbackup');
        $this->assertSame($CFG->tempdir . '/tool_secbackup', limits::current()->tempdir);
    }

    public function test_a_windows_style_folder_is_accepted_and_a_name_with_dots_too(): void {
        set_config('tempdir', 'D:\\secbk\\tmp', 'tool_secbackup');
        $this->assertSame('D:\\secbk\\tmp', limits::current()->tempdir);
        set_config('tempdir', '/var/tmp/a..b/c', 'tool_secbackup');
        $this->assertSame('/var/tmp/a..b/c', limits::current()->tempdir);
    }

    public function test_the_defaults_are_the_constants_of_the_class(): void {
        $this->assertSame(limits::DEFAULT_LOADPERCORE, limits::current()->loadpercore);
        $this->assertSame(limits::DEFAULT_MINMEMORYMB, limits::current()->minmemorymb);
        $this->assertSame(limits::DEFAULT_MAXDBMS, limits::current()->maxdbms);
        $this->assertSame(limits::DEFAULT_MINNFSMBPS, limits::current()->minnfsmbps);
        $this->assertSame(limits::DEFAULT_TEMPBUDGETMB, limits::current()->tempbudgetmb);
        $this->assertSame(limits::DEFAULT_BANDWIDTHMBPS, limits::current()->bandwidthmbps);
    }

    public function test_the_checks_of_the_settings_page_are_public(): void {
        $this->assertTrue(limits::valid_number('12', 1.0, true));
        $this->assertFalse(limits::valid_number('0', 1.0, true));
        $this->assertTrue(limits::valid_number('0.5', 0.1, false));
        $this->assertFalse(limits::valid_number('1.0', 0.0, true));
        $this->assertTrue(limits::usable_directory('/var/tmp/x'));
        $this->assertFalse(limits::usable_directory('x'));
    }

    public function test_inside_dirroot(): void {
        global $CFG;
        $this->assertTrue(limits::inside_dirroot($CFG->dirroot));
        $this->assertTrue(limits::inside_dirroot($CFG->dirroot . '/'));
        $this->assertTrue(limits::inside_dirroot($CFG->dirroot . '/public/x'));
        $this->assertFalse(limits::inside_dirroot($CFG->dirroot . '-x'));
        $this->assertFalse(limits::inside_dirroot('/var/tmp'));
    }
}
