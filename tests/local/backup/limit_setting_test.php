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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\limit_setting::class)]
/**
 * Tests for the settings of the limits, which accept only what the limits accept.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\limit_setting
 */
final class limit_setting_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * A setting that holds a number.
     *
     * @param string $name Name.
     * @param float $min Least value.
     * @param bool $integer Whether only whole numbers are allowed.
     * @return limit_setting
     */
    private static function number(string $name, float $min, bool $integer): limit_setting {
        return new limit_setting($name, '1', $min, $integer);
    }

    /**
     * A setting that holds a folder.
     *
     * @return limit_setting
     */
    private static function folder(): limit_setting {
        return new limit_setting('tempdir', '', null, false);
    }

    /**
     * Number settings with a value that must be refused.
     *
     * @return array[]
     */
    public static function refused_numbers(): array {
        return [
            'negative whole number' => ['maxdbms', 1.0, true, '-1'],
            'zero where it is not allowed' => ['maxdbms', 1.0, true, '0'],
            'a fraction as a whole number' => ['minmemorymb', 0.0, true, '2.5'],
            'a decimal as a whole number' => ['minmemorymb', 0.0, true, '2.0'],
            'text' => ['loadpercore', 0.1, false, 'abc'],
            'empty' => ['loadpercore', 0.1, false, ''],
            'below the least decimal' => ['loadpercore', 0.1, false, '0.05'],
            'negative decimal' => ['minnfsmbps', 0.0, false, '-0.5'],
            'too many decimals' => ['minnfsmbps', 0.0, false, '1.1234567'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_numbers')]
    /**
     * A refused number is not saved and the error is returned.
     *
     * @dataProvider refused_numbers
     * @param string $name Name.
     * @param float $min Least value.
     * @param bool $integer Whole numbers only.
     * @param string $value The value.
     */
    public function test_a_number_that_the_limits_would_not_use_is_refused(
        string $name,
        float $min,
        bool $integer,
        string $value
    ): void {
        $error = self::number($name, $min, $integer)->write_setting($value);
        $this->assertNotSame('', $error);
        $this->assertFalse(get_config('tool_secbackup', $name));
    }

    /**
     * Number settings with a value that must be saved.
     *
     * @return array[]
     */
    public static function accepted_numbers(): array {
        return [
            'a whole number' => ['maxdbms', 1.0, true, '500'],
            'zero where it is allowed' => ['minmemorymb', 0.0, true, '0'],
            'a decimal with a zero fraction' => ['loadpercore', 0.1, false, '2.0'],
            'a decimal' => ['minnfsmbps', 0.0, false, '2.25'],
            'the least decimal' => ['loadpercore', 0.1, false, '0.1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accepted_numbers')]
    /**
     * An accepted number is saved.
     *
     * @dataProvider accepted_numbers
     * @param string $name Name.
     * @param float $min Least value.
     * @param bool $integer Whole numbers only.
     * @param string $value The value.
     */
    public function test_a_number_that_the_limits_use_is_saved(string $name, float $min, bool $integer, string $value): void {
        $this->assertSame('', self::number($name, $min, $integer)->write_setting($value));
        $this->assertSame($value, get_config('tool_secbackup', $name));
    }

    public function test_the_name_and_description_are_the_strings_of_the_key(): void {
        $setting = self::number('maxdbms', 1.0, true);
        $this->assertSame('maxdbms', $setting->name);
        $this->assertSame('tool_secbackup', $setting->plugin);
        $this->assertSame(get_string('setting_maxdbms', 'tool_secbackup'), (string) $setting->visiblename);
        $this->assertSame(get_string('setting_maxdbms_desc', 'tool_secbackup'), (string) $setting->description);
        $this->assertSame('1', $setting->get_defaultsetting());
    }

    public function test_the_error_for_a_whole_number_and_for_a_decimal_name_the_least_value(): void {
        $whole = self::number('maxdbms', 1.0, true)->write_setting('0');
        $this->assertSame(get_string('setting_error_whole', 'tool_secbackup', '1'), $whole);
        $decimal = self::number('loadpercore', 0.1, false)->write_setting('0');
        $this->assertSame(get_string('setting_error_decimal', 'tool_secbackup', '0.1'), $decimal);
    }

    /**
     * Folder settings that must be refused.
     *
     * @return array[]
     */
    public static function refused_folders(): array {
        return [
            'relative' => ['tmp/x'],
            'parent segment' => ['/var/tmp/../x'],
            'the root' => ['/'],
            'control character' => ["/var/tmp/a\nb"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_folders')]
    /**
     * A folder that the limits would not use is not saved.
     *
     * @dataProvider refused_folders
     * @param string $value The folder.
     */
    public function test_a_folder_that_the_limits_would_not_use_is_refused(string $value): void {
        $error = self::folder()->write_setting($value);
        $this->assertSame(get_string('setting_error_directory', 'tool_secbackup'), $error);
        $this->assertFalse(get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_inside_the_code_of_moodle_is_refused(): void {
        global $CFG;
        foreach ([$CFG->dirroot, $CFG->dirroot . '/', $CFG->dirroot . '/public/tmp', $CFG->dirroot . '/a/b'] as $value) {
            $this->assertSame(get_string('setting_error_dirroot', 'tool_secbackup'), self::folder()->write_setting($value), $value);
        }
        $this->assertFalse(get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_beside_the_code_of_moodle_is_saved(): void {
        global $CFG;
        $this->assertSame('', self::folder()->write_setting($CFG->dirroot . '-tmp/backup'));
        $this->assertSame($CFG->dirroot . '-tmp/backup', get_config('tool_secbackup', 'tempdir'));
        $this->assertSame('', self::folder()->write_setting('/var/tmp/secbk'));
        $this->assertSame('/var/tmp/secbk', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_value_with_spaces_around_it_is_refused_by_the_general_check(): void {
        $this->assertSame(get_string('validateerror', 'admin'), self::number('maxdbms', 1.0, true)->write_setting(' 5 '));
        $this->assertFalse(get_config('tool_secbackup', 'maxdbms'));
    }

    public function test_an_empty_folder_is_saved_and_means_the_default(): void {
        set_config('tempdir', '/var/tmp/secbk', 'tool_secbackup');
        $this->assertSame('', self::folder()->write_setting(''));
        $this->assertSame('', get_config('tool_secbackup', 'tempdir'));
    }
}
