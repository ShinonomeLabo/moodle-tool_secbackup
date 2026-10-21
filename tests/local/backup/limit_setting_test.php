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
        set_config($name, '123', 'tool_secbackup');
        $error = self::number($name, $min, $integer)->write_setting($value);
        $this->assertNotSame('', $error);
        $this->assertSame('123', get_config('tool_secbackup', $name));
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
        set_config($name, '123', 'tool_secbackup');
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
        set_config('tempdir', '/var/tmp/known', 'tool_secbackup');
        $error = self::folder()->write_setting($value);
        $this->assertSame(get_string('setting_error_directory', 'tool_secbackup'), $error);
        $this->assertSame('/var/tmp/known', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_inside_the_code_of_moodle_is_refused(): void {
        global $CFG;
        set_config('tempdir', '/var/tmp/known', 'tool_secbackup');
        foreach ([$CFG->dirroot, $CFG->dirroot . '/', $CFG->dirroot . '/public/tmp', $CFG->dirroot . '/a/b'] as $value) {
            $this->assertSame(get_string('setting_error_dirroot', 'tool_secbackup'), self::folder()->write_setting($value), $value);
        }
        $this->assertSame('/var/tmp/known', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_beside_the_code_of_moodle_is_saved(): void {
        global $CFG;
        $this->assertSame('', self::folder()->write_setting($CFG->dirroot . '-tmp/backup'));
        $this->assertSame($CFG->dirroot . '-tmp/backup', get_config('tool_secbackup', 'tempdir'));
        $this->assertSame('', self::folder()->write_setting('/var/tmp/secbk'));
        $this->assertSame('/var/tmp/secbk', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_value_with_spaces_around_it_is_refused_by_the_general_check(): void {
        set_config('maxdbms', '123', 'tool_secbackup');
        $this->assertSame(get_string('validateerror', 'admin'), self::number('maxdbms', 1.0, true)->write_setting(' 5 '));
        $this->assertSame('123', get_config('tool_secbackup', 'maxdbms'));
    }

    public function test_the_data_directory_itself_is_refused(): void {
        global $CFG;
        set_config('tempdir', '/var/tmp/known', 'tool_secbackup');
        $error = get_string('setting_error_dataroot', 'tool_secbackup');
        foreach ([$CFG->dataroot, $CFG->dataroot . '/', realpath($CFG->dataroot)] as $value) {
            // The backup of the data directory would hold the temporary files of the run itself.
            $this->assertSame($error, self::folder()->write_setting($value), $value);
        }
        $this->assertSame('/var/tmp/known', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_of_the_data_directory_that_holds_files_is_refused(): void {
        global $CFG;
        set_config('tempdir', '/var/tmp/known', 'tool_secbackup');
        check_dir_exists($CFG->dataroot . '/coursework/2026');
        file_put_contents($CFG->dataroot . '/coursework/2026/essay.txt', 'x');
        $error = get_string('setting_error_datafolder', 'tool_secbackup');
        foreach ([$CFG->dataroot . '/coursework', $CFG->dataroot . '/coursework/2026/'] as $value) {
            // The folder is left out of the backup, and its files with it.
            $this->assertSame($error, self::folder()->write_setting($value), $value);
        }
        $this->assertSame('/var/tmp/known', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_new_or_empty_folder_of_the_data_directory_is_saved(): void {
        global $CFG;
        $this->assertSame('', self::folder()->write_setting($CFG->dataroot . '/secbk-new'));
        $this->assertSame($CFG->dataroot . '/secbk-new', get_config('tool_secbackup', 'tempdir'));
        check_dir_exists($CFG->dataroot . '/secbk-empty');
        $this->assertSame('', self::folder()->write_setting($CFG->dataroot . '/secbk-empty'));
        $this->assertSame($CFG->dataroot . '/secbk-empty', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_a_folder_with_files_in_a_folder_that_the_backup_leaves_out_is_saved(): void {
        global $CFG;
        check_dir_exists($CFG->tempdir . '/tool_secbackup');
        file_put_contents($CFG->tempdir . '/tool_secbackup/left', 'x');
        $this->assertSame('', self::folder()->write_setting($CFG->tempdir . '/tool_secbackup'));
        $this->assertSame($CFG->tempdir . '/tool_secbackup', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_the_saved_folder_is_saved_again_with_the_files_of_the_plugin_in_it(): void {
        global $CFG;
        check_dir_exists($CFG->dataroot . '/secbk/run-1');
        set_config('tempdir', $CFG->dataroot . '/secbk', 'tool_secbackup');
        // The page writes every setting when it is saved, also the ones that did not change.
        $this->assertSame('', self::folder()->write_setting($CFG->dataroot . '/secbk'));
        $this->assertSame('', self::folder()->write_setting($CFG->dataroot . '/secbk/'));
    }

    public function test_the_folder_is_not_changed_while_a_backup_runs(): void {
        set_config('tempdir', '/var/tmp/known', 'tool_secbackup');
        $run = run_record::create('manual', time());
        $running = get_string('setting_error_running', 'tool_secbackup');
        $this->assertSame($running, self::folder()->write_setting('/var/tmp/other'));
        $this->assertSame($running, self::folder()->write_setting(''));
        $this->assertSame('/var/tmp/known', get_config('tool_secbackup', 'tempdir'));
        // The page saves the folder again unchanged, and the numbers can be changed.
        $this->assertSame('', self::folder()->write_setting('/var/tmp/known'));
        $this->assertSame('', self::number('maxdbms', 1.0, true)->write_setting('600'));
        $run->pause(time(), time() + 60);
        $this->assertSame($running, self::folder()->write_setting('/var/tmp/other'));
        $run->finish(time());
        $this->assertSame('', self::folder()->write_setting('/var/tmp/other'));
        $this->assertSame('/var/tmp/other', get_config('tool_secbackup', 'tempdir'));
    }

    public function test_the_default_folder_is_not_changed_while_a_backup_runs(): void {
        run_record::create('schedule', time());
        $this->assertSame(get_string('setting_error_running', 'tool_secbackup'), self::folder()->write_setting('/var/tmp/other'));
        $this->assertSame('', self::folder()->write_setting(''));
    }

    public function test_the_description_says_that_another_folder_keeps_a_backup_on_its_server(): void {
        $en = get_string_manager()->get_string('setting_tempdir_desc', 'tool_secbackup', null, 'en');
        $this->assertStringContainsString('only that server goes on with', $en);
        $this->assertStringContainsString('new or empty', $en);
    }

    public function test_an_empty_folder_is_saved_and_means_the_default(): void {
        set_config('tempdir', '/var/tmp/secbk', 'tool_secbackup');
        $this->assertSame('', self::folder()->write_setting(''));
        $this->assertSame('', get_config('tool_secbackup', 'tempdir'));
    }
}
