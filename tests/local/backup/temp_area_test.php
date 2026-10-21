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

use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\temp_area::class)]
/**
 * Tests for the temporary area of a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\temp_area
 */
final class temp_area_test extends \advanced_testcase {
    /** @var int One MiB. */
    private const MIB = 1048576;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Limits whose temporary folder is the given one.
     *
     * @param string $tempdir Folder.
     * @param int $budgetmb Budget in MB.
     * @return limits
     */
    private static function limits(string $tempdir, int $budgetmb = 0): limits {
        return new limits(2.0, 256, 500, 5.0, $tempdir, $budgetmb, 0);
    }

    /**
     * Runs code with every PHP warning and notice turned into a failure.
     *
     * @param callable $code The code.
     * @return mixed What the code returns.
     */
    private function without_warnings(callable $code): mixed {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            return $code();
        } finally {
            restore_error_handler();
        }
    }

    public function test_create_uses_the_limits_that_are_set(): void {
        $base = make_request_directory();
        set_config('tempdir', $base . '/configured', 'tool_secbackup');
        $this->assertSame($base . '/configured/run-6', temp_area::create()->for_run(6));
    }

    /**
     * Makes a folder of a run inside Moodle's temporary folder, with files and folders that were last changed two days ago.
     *
     * @param temp_area $area The area.
     * @param int $runid The run.
     * @return string[] The files and folders, the folder of the run first.
     */
    private static function aged_run(temp_area $area, int $runid): array {
        $folder = $area->for_run($runid);
        mkdir($folder . '/journal');
        file_put_contents($folder . '/journal/files.jsonl', "[\"a\",1]\n");
        file_put_contents($folder . '/present.gz', 'list');
        file_put_contents($folder . '/chunk', 'data');
        $paths = [$folder, $folder . '/journal', $folder . '/journal/files.jsonl', $folder . '/present.gz', $folder . '/chunk'];
        foreach (array_reverse($paths) as $path) {
            touch($path, time() - 2 * DAYSECS);
        }
        clearstatcache();
        return $paths;
    }

    public function test_a_run_that_is_refreshed_is_kept_by_the_cleanup_of_the_temporary_folder_of_moodle(): void {
        global $CFG;
        $area = new temp_area(self::limits($CFG->tempdir . '/tool_secbackup'));
        $kept = self::aged_run($area, 41);
        $left = self::aged_run($area, 42);
        $outside = make_request_directory() . '/target';
        file_put_contents($outside, 'not of the run');
        touch($outside, time() - 2 * DAYSECS);
        symlink($outside, $kept[0] . '/link');
        $this->without_warnings(static fn() => $area->refresh(41));
        clearstatcache();
        foreach ($kept as $path) {
            $this->assertGreaterThan(time() - 60, filemtime($path), $path);
        }
        // A link is not followed: what it points to is not touched.
        $this->assertLessThan(time() - DAYSECS, filemtime($outside));
        // The cleanup of Moodle, at its shortest setting (one hour), removes what was not refreshed only.
        $CFG->tempdatafoldercleanup = 1;
        ob_start();
        (new \core\task\file_temp_cleanup_task())->execute();
        ob_end_clean();
        clearstatcache();
        foreach ($kept as $path) {
            $this->assertFileExists($path);
        }
        $this->assertFileDoesNotExist($left[2]);
        $this->assertFileDoesNotExist($left[4]);
    }

    public function test_refreshing_a_run_without_a_folder_makes_none(): void {
        $base = make_request_directory();
        $area = new temp_area(self::limits($base . '/tool'));
        $this->without_warnings(static fn() => $area->refresh(43));
        $this->assertDirectoryDoesNotExist($base . '/tool/run-43');
    }

    public function test_for_run_makes_the_folder_of_the_run_inside_the_temporary_folder(): void {
        $base = make_request_directory();
        $area = new temp_area(self::limits($base . '/nested/tool'));
        $path = $this->without_warnings(static fn(): string => $area->for_run(7));
        $this->assertSame($base . '/nested/tool/run-7', $path);
        $this->assertDirectoryExists($path);
        $this->assertTrue(is_writable($path));
        $this->assertSame(0, $area->used_bytes(7));
    }

    public function test_the_folder_gets_the_directory_permissions_of_moodle(): void {
        global $CFG;
        $CFG->directorypermissions = 0750;
        $base = make_request_directory();
        $path = (new temp_area(self::limits($base . '/t')))->for_run(3);
        $this->assertSame('0750', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function test_for_run_can_be_called_again_for_a_run_that_continues(): void {
        $base = make_request_directory();
        $area = new temp_area(self::limits($base));
        $path = $area->for_run(4);
        file_put_contents($path . '/kept', 'x');
        $this->assertSame($path, $area->for_run(4));
        $this->assertFileExists($path . '/kept');
    }

    public function test_for_run_fails_with_the_error_of_the_chunk_writer_when_the_folder_cannot_be_made(): void {
        $base = make_request_directory();
        file_put_contents($base . '/afile', 'x');
        $area = new temp_area(self::limits($base . '/afile/tool'));
        try {
            $this->without_warnings(static fn() => $area->for_run(1));
            $this->fail('An exception was expected.');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assertStringNotContainsString('secret', (string) $e->debuginfo);
        }
    }

    public function test_for_run_refuses_a_symbolic_link_as_the_folder_of_the_run(): void {
        $base = make_request_directory();
        $outside = make_request_directory();
        symlink($outside, $base . '/run-2');
        $area = new temp_area(self::limits($base));
        try {
            $this->without_warnings(static fn() => $area->for_run(2));
            $this->fail('An exception was expected.');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        $this->assertFileDoesNotExist($outside . '/anything');
    }

    public function test_for_run_fails_when_the_folder_cannot_be_written(): void {
        $base = make_request_directory();
        $area = new temp_area(self::limits($base));
        $path = $area->for_run(5);
        chmod($path, 0500);
        $writable = is_writable($path);
        chmod($path, 0700);
        if ($writable) {
            $this->markTestSkipped('This process can write to a folder without permission (it runs as root).');
        }
        chmod($path, 0500);
        try {
            $this->without_warnings(static fn() => $area->for_run(5));
            $this->fail('An exception was expected.');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        } finally {
            chmod($path, 0700);
        }
    }

    public function test_used_bytes_sums_the_regular_files_and_ignores_links(): void {
        $base = make_request_directory();
        $outside = make_request_directory();
        file_put_contents($outside . '/big', str_repeat('x', 5000));
        $area = new temp_area(self::limits($base));
        $path = $area->for_run(8);
        mkdir($path . '/sub/deeper', 0777, true);
        file_put_contents($path . '/a', str_repeat('a', 100));
        file_put_contents($path . '/sub/b', str_repeat('b', 23));
        file_put_contents($path . '/sub/deeper/c', '');
        symlink($outside . '/big', $path . '/link-to-file');
        symlink($outside, $path . '/link-to-folder');
        file_put_contents($base . '/run-9', str_repeat('z', 77));
        $this->assertSame(123, $this->without_warnings(static fn(): int => $area->used_bytes(8)));
    }

    public function test_used_bytes_of_a_run_without_a_folder_is_zero(): void {
        $area = new temp_area(self::limits(make_request_directory() . '/missing'));
        $this->assertSame(0, $this->without_warnings(static fn(): int => $area->used_bytes(1)));
    }

    public function test_clear_removes_the_folder_of_the_run_and_nothing_else(): void {
        $base = make_request_directory();
        $area = new temp_area(self::limits($base));
        $path = $area->for_run(10);
        $other = $area->for_run(11);
        mkdir($path . '/sub/deeper', 0777, true);
        file_put_contents($path . '/a', 'a');
        file_put_contents($path . '/sub/deeper/b', 'b');
        file_put_contents($other . '/keep', 'k');
        $this->assertTrue($this->without_warnings(static fn(): bool => $area->clear(10)));
        $this->assertDirectoryDoesNotExist($path);
        $this->assertFileExists($other . '/keep');
        $this->assertFileExists($base);
    }

    public function test_clear_does_nothing_when_the_folder_is_missing(): void {
        $area = new temp_area(self::limits(make_request_directory() . '/missing'));
        $this->assertTrue($this->without_warnings(static fn(): bool => $area->clear(99)));
        $this->assertSame(0, $area->used_bytes(99));
    }

    public function test_clear_never_follows_a_symbolic_link_out_of_the_temporary_folder(): void {
        $base = make_request_directory();
        $outside = make_request_directory();
        mkdir($outside . '/inner');
        file_put_contents($outside . '/inner/precious', 'p');
        file_put_contents($outside . '/file', 'f');
        $area = new temp_area(self::limits($base));
        $path = $area->for_run(12);
        symlink($outside, $path . '/link-to-folder');
        symlink($outside . '/file', $path . '/link-to-file');
        symlink($base . '/missing-target', $path . '/dangling');
        $this->assertTrue($this->without_warnings(static fn(): bool => $area->clear(12)));
        $this->assertDirectoryDoesNotExist($path);
        $this->assertFileExists($outside . '/inner/precious');
        $this->assertFileExists($outside . '/file');
    }

    public function test_clear_removes_a_link_in_place_of_the_folder_without_following_it(): void {
        $base = make_request_directory();
        $outside = make_request_directory();
        file_put_contents($outside . '/precious', 'p');
        symlink($outside, $base . '/run-13');
        $area = new temp_area(self::limits($base));
        $this->assertTrue($this->without_warnings(static fn(): bool => $area->clear(13)));
        $this->assertFalse(is_link($base . '/run-13'));
        $this->assertFileExists($outside . '/precious');
    }

    public function test_the_budget_is_the_setting_in_megabytes_when_it_is_above_zero(): void {
        $area = new temp_area(self::limits(make_request_directory(), 100), static fn(string $dir): array => [1, 2]);
        $this->assertSame(100 * self::MIB, $area->budget_bytes());
    }

    /**
     * Free space and the budget that follows when no budget is set.
     *
     * @return array[]
     */
    public static function default_budgets(): array {
        $gib = 1024 * self::MIB;
        return [
            'a tenth of the free space' => [10 * $gib, $gib],
            'the cap of 2,048 MB' => [100 * $gib, 2048 * self::MIB],
            'just at the cap' => [20480 * self::MIB, 2048 * self::MIB],
            'just below the cap' => [20479 * self::MIB, intdiv(20479 * self::MIB, 10)],
            'no free space' => [0, 0],
            'unknown free space' => [null, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('default_budgets')]
    /**
     * Without a setting the budget is the smaller of a tenth of the free space and 2048 mb.
     *
     * @dataProvider default_budgets
     * @param int|null $free Free space.
     * @param int $expected Budget.
     */
    public function test_without_a_setting_the_budget_is_the_smaller_of_a_tenth_of_the_free_space_and_2048_mb(
        ?int $free,
        int $expected
    ): void {
        $seen = [];
        $base = make_request_directory();
        $area = new temp_area(self::limits($base . '/t'), static function (string $dir) use ($free, &$seen): array {
            $seen[] = $dir;
            return [$free, $free === null ? null : $free * 2];
        });
        $this->assertSame($expected, $area->budget_bytes());
        $this->assertSame([$base . '/t'], $seen);
    }

    public function test_the_real_disk_figures_are_read_without_warnings(): void {
        $base = make_request_directory();
        // The folder does not exist yet: the nearest folder that does is measured.
        [$free, $total] = $this->without_warnings(static fn(): array => temp_area::disk_space($base . '/a/b/c'));
        $this->assertGreaterThan(0, $free);
        $this->assertGreaterThanOrEqual($free, $total);
        $this->assertIsInt($free);
        $this->assertIsInt($total);
        $budget = $this->without_warnings(static fn(): int => (new temp_area(self::limits($base)))->budget_bytes());
        $this->assertGreaterThanOrEqual(0, $budget);
        $this->assertLessThanOrEqual(2048 * self::MIB, $budget);
    }

    public function test_the_disk_figures_are_unknown_for_a_folder_that_cannot_be_measured(): void {
        // Only an absolute folder is measured: a relative name would be measured against whatever folder the process is in.
        $this->assertSame([null, null], $this->without_warnings(static fn(): array => temp_area::disk_space('no-such-folder')));
    }

    public function test_clear_says_false_when_the_run_folder_is_not_a_folder_and_stays(): void {
        $base = make_request_directory();
        file_put_contents($base . '/run-15', 'x');
        $area = new temp_area(self::limits($base));
        $this->assertFalse($this->without_warnings(static fn(): bool => $area->clear(15)));
        $this->assertFileExists($base . '/run-15');
    }

    public function test_clear_says_false_when_the_temporary_folder_does_not_resolve(): void {
        $area = new temp_area(self::limits(make_request_directory() . '/missing'));
        // Nothing exists: nothing is there to remove, and the folder is gone.
        $this->assertTrue($area->clear(1));
    }

    public function test_the_folder_of_a_run_in_a_temporary_folder_that_is_a_link_is_resolved_once(): void {
        $base = make_request_directory();
        $real = make_request_directory();
        symlink($real, $base . '/via');
        $area = new temp_area(self::limits($base . '/via'));
        $path = $area->for_run(16);
        file_put_contents($path . '/a', 'abc');
        $this->assertSame(3, $area->used_bytes(16));
        $this->assertTrue($area->clear(16));
        $this->assertDirectoryDoesNotExist($real . '/run-16');
    }

    public function test_an_unreadable_folder_gives_nothing_instead_of_an_exception(): void {
        $area = new temp_area(self::limits(make_request_directory()));
        $method = new \ReflectionMethod($area, 'files_below');
        $items = $method->invoke($area, make_request_directory() . '/missing', \RecursiveIteratorIterator::LEAVES_ONLY);
        $this->assertSame([], iterator_to_array($items));
    }

    public function test_a_path_outside_the_run_folder_is_not_touched(): void {
        $base = make_request_directory();
        $outside = make_request_directory();
        $area = new temp_area(self::limits($base));
        mkdir($base . '/run-1/x', 0777, true);
        mkdir($base . '/run-10');
        $method = new \ReflectionMethod($area, 'is_inside');
        $this->assertTrue($method->invoke($area, $base . '/run-1/f', $base . '/run-1'));
        $this->assertTrue($method->invoke($area, $base . '/run-1/x/f', $base . '/run-1'));
        $this->assertFalse($method->invoke($area, $outside . '/f', $base . '/run-1'));
        $this->assertFalse($method->invoke($area, $base . '/run-10/f', $base . '/run-1'));
        $this->assertFalse($method->invoke($area, $base . '/missing/f', $base . '/run-1'));
    }
}
