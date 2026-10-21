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

use tool_secbackup\local\diagnostics\system_files;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\monitor::class)]
/**
 * Tests for the watch on the load, memory, database, free space and file storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\monitor
 */
final class monitor_test extends \advanced_testcase {
    /** @var int One MiB. */
    private const MIB = 1048576;

    /** @var int One GiB. */
    private const GIB = 1073741824;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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

    /**
     * A root that holds the files of /proc and /sys that the watch reads.
     *
     * @param string|null $loadavg Content of /proc/loadavg, or null for no file.
     * @param string|null $meminfo Content of /proc/meminfo, or null for no file.
     * @param string|null $online Content of /sys/devices/system/cpu/online, or null for no file.
     * @return system_files
     */
    private static function files(?string $loadavg, ?string $meminfo, ?string $online): system_files {
        $root = make_request_directory();
        mkdir($root . '/proc');
        mkdir($root . '/sys/devices/system/cpu', 0777, true);
        $contents = ['/proc/loadavg' => $loadavg, '/proc/meminfo' => $meminfo, '/sys/devices/system/cpu/online' => $online];
        foreach ($contents as $path => $text) {
            if ($text !== null) {
                file_put_contents($root . $path, $text);
            }
        }
        return new system_files($root);
    }

    /**
     * Files of a healthy four-core server.
     *
     * @return system_files
     */
    private static function healthy_files(): system_files {
        return self::files(
            "0.52 0.40 0.30 1/200 1234\n",
            "MemTotal:       8000000 kB\nMemFree:  100 kB\nMemAvailable:    2048000 kB\nBuffers: 1 kB\n",
            "0-3\n"
        );
    }

    /**
     * The limits of the defaults.
     *
     * @return limits
     */
    private static function limits(): limits {
        return new limits(2.0, 256, 500, 5.0, make_request_directory() . '/t', 0, 0);
    }

    /**
     * A clock that moves a fixed step at each call.
     *
     * @param float $step Seconds.
     * @return callable
     */
    private static function clock(float $step): callable {
        $now = 1000.0;
        return static function () use (&$now, $step): float {
            $now += $step;
            return $now;
        };
    }

    /**
     * A function for the free space that is the same for every folder.
     *
     * @param int|null $free Free bytes.
     * @param int|null $total Total bytes.
     * @return callable
     */
    private static function disk(?int $free, ?int $total): callable {
        return static fn(string $dir): array => [$free, $total];
    }

    /**
     * A watch with no stored file of one MiB or more.
     *
     * @param system_files $files Files of /proc and /sys.
     * @param callable|null $clock Clock.
     * @param callable|null $disk Free space function.
     * @param \file_system|null $filesystem File system.
     * @param bool $deletebig Whether the stored files of 1 MiB or more are deleted first.
     * @param callable|null $random Gives the MiB at which the read starts, from 0 to its argument.
     * @return monitor
     */
    private function monitor(
        system_files $files,
        ?callable $clock = null,
        ?callable $disk = null,
        ?\file_system $filesystem = null,
        bool $deletebig = true,
        ?callable $random = null
    ): monitor {
        global $DB;
        if ($deletebig) {
            $DB->execute('DELETE FROM {files} WHERE filesize >= ?', [self::MIB]);
        }
        $disk ??= self::disk(50 * self::GIB, 100 * self::GIB);
        return new monitor($files, self::limits(), $clock, $disk, $filesystem, $random);
    }

    /**
     * Stores a file of the given size in the file storage.
     *
     * @param int $bytes Size.
     * @return \stored_file
     */
    private function store_file(int $bytes): \stored_file {
        static $n = 0;
        $n++;
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_secbackup',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'big' . $n . '.bin',
        ], str_repeat(chr(65 + $n % 20), $bytes));
    }

    public function test_sample_reads_the_load_memory_database_and_free_space(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.0125));
        $sample = $this->without_warnings(static fn(): array => $monitor->sample());
        $this->assertEqualsWithDelta(0.13, $sample['load_per_core'], 1e-9);
        $this->assertSame(2048000 * 1024, $sample['memory_available_bytes']);
        $this->assertEqualsWithDelta(12.5, $sample['db_ms'], 1e-6);
        $this->assertSame(50 * self::GIB, $sample['disk_free_bytes']);
        $this->assertSame(100 * self::GIB, $sample['disk_total_bytes']);
        $this->assertNull($sample['nfs_mbps']);
        $keys = array_keys($sample);
        sort($keys);
        $this->assertSame(
            ['db_ms', 'disk_free_bytes', 'disk_total_bytes', 'load_per_core', 'memory_available_bytes', 'nfs_mbps',
                'not_applicable'],
            $keys
        );
    }

    public function test_the_monitor_measures_the_real_server_without_warnings(): void {
        global $DB;
        $DB->execute('DELETE FROM {files} WHERE filesize >= ?', [self::MIB]);
        $monitor = $this->without_warnings(static fn(): monitor => monitor::create());
        $sample = $this->without_warnings(static fn(): array => $monitor->sample());
        $this->assertGreaterThanOrEqual(0.0, $sample['db_ms']);
        $this->assertGreaterThan(0, $sample['disk_free_bytes']);
        foreach (['load_per_core', 'memory_available_bytes'] as $key) {
            $this->assertTrue($sample[$key] === null || $sample[$key] >= 0, $key);
        }
    }

    /**
     * Content of /sys/devices/system/cpu/online and the number of cores it means (null when it cannot be read).
     *
     * @return array[]
     */
    public static function core_lists(): array {
        return [
            'one core' => ["0\n", 1],
            'a range' => ["0-3\n", 4],
            'ranges and singles' => ["0,2-3,8\n", 4],
            'no newline' => ['0-7', 8],
            'a reversed range' => ["3-1\n", null],
            'text' => ["abc\n", null],
            'empty' => ['', null],
            'a trailing comma' => ["0-3,\n", null],
            'a huge range' => ["0-99999999\n", null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('core_lists')]
    /**
     * The load is divided by the online cores.
     *
     * @dataProvider core_lists
     * @param string $online The file.
     * @param int|null $cores The number of cores.
     */
    public function test_the_load_is_divided_by_the_online_cores(string $online, ?int $cores): void {
        $monitor = $this->monitor(self::files("6.0 0 0 1/1 1\n", null, $online));
        $load = $this->without_warnings(static fn(): ?float => $monitor->sample()['load_per_core']);
        if ($cores === null) {
            $this->assertNull($load);
        } else {
            $this->assertEqualsWithDelta(6.0 / $cores, $load, 1e-9);
        }
    }

    /**
     * Content of /proc/loadavg that cannot be read as a load.
     *
     * @return array[]
     */
    public static function bad_loadavgs(): array {
        return [
            'empty' => [''],
            'text' => ["busy 0 0\n"],
            'negative' => ["-1.0 0 0\n"],
            'exponent' => ["1e2 0 0\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_loadavgs')]
    /**
     * A load that cannot be read is unknown.
     *
     * @dataProvider bad_loadavgs
     * @param string $loadavg The file.
     */
    public function test_a_load_that_cannot_be_read_is_unknown(string $loadavg): void {
        $monitor = $this->monitor(self::files($loadavg, null, "0-3\n"));
        $this->assertNull($this->without_warnings(static fn(): ?float => $monitor->sample()['load_per_core']));
    }

    /**
     * Content of /proc/meminfo and the available memory it means.
     *
     * @return array[]
     */
    public static function meminfos(): array {
        return [
            'found' => ["MemTotal: 5 kB\nMemAvailable:   1000 kB\n", 1000 * 1024],
            'no available line' => ["MemTotal: 5 kB\nMemFree: 1 kB\n", null],
            'not in kB' => ["MemAvailable: 1000 MB\n", null],
            'a line that only ends in it' => ["XMemAvailable: 1000 kB\n", null],
            'empty' => ['', null],
            'zero' => ["MemAvailable: 0 kB\n", 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('meminfos')]
    /**
     * The available memory is read from meminfo.
     *
     * @dataProvider meminfos
     * @param string $meminfo The file.
     * @param int|null $expected The memory.
     */
    public function test_the_available_memory_is_read_from_meminfo(string $meminfo, ?int $expected): void {
        $monitor = $this->monitor(self::files("1 0 0 1/1 1\n", $meminfo, "0\n"));
        $this->assertSame($expected, $this->without_warnings(static fn(): ?int => $monitor->sample()['memory_available_bytes']));
    }

    public function test_files_that_cannot_be_read_give_unknown_values_without_warnings(): void {
        $monitor = $this->monitor(self::files(null, null, null), null, self::disk(null, null));
        $sample = $this->without_warnings(static fn(): array => $monitor->sample());
        $this->assertNull($sample['load_per_core']);
        $this->assertNull($sample['memory_available_bytes']);
        $this->assertNull($sample['disk_free_bytes']);
        $this->assertNull($sample['disk_total_bytes']);
        $this->assertNull($sample['nfs_mbps']);
        $this->assertIsFloat($sample['db_ms']);
        $this->assertSame(30, monitor::pause_seconds($sample));
    }

    public function test_the_disk_function_gets_the_temporary_folder(): void {
        $seen = [];
        $limits = self::limits();
        $monitor = new monitor(self::healthy_files(), $limits, null, static function (string $dir) use (&$seen): array {
            $seen[] = $dir;
            return [1, 2];
        });
        $monitor->sample();
        $this->assertSame([$limits->tempdir], $seen);
    }

    public function test_the_database_response_is_the_time_of_one_read_in_milliseconds(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.25));
        $this->assertEqualsWithDelta(250.0, $monitor->measure_db(), 1e-6);
    }

    public function test_the_file_storage_speed_is_the_speed_of_reading_up_to_4_mib_of_one_big_file(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(2 * self::MIB);
        // Two MiB in half a second.
        $this->assertEqualsWithDelta(4.0, $this->without_warnings(static fn(): ?float => $monitor->measure_nfs()), 1e-9);
    }

    public function test_only_4_mib_of_a_bigger_file_are_read(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(6 * self::MIB);
        $this->assertEqualsWithDelta(8.0, $this->without_warnings(static fn(): ?float => $monitor->measure_nfs()), 1e-9);
    }

    public function test_the_largest_file_is_read(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(2 * self::MIB);
        $this->store_file(3 * self::MIB);
        $this->store_file(self::MIB);
        $this->assertEqualsWithDelta(6.0, $monitor->measure_nfs(), 1e-9);
    }

    public function test_each_read_starts_at_a_random_mib_of_the_file_so_that_it_is_seldom_in_the_cache(): void {
        $asked = [];
        $starts = [2, 0];
        $random = static function (int $max) use (&$asked, &$starts): int {
            $asked[] = $max;
            return array_shift($starts);
        };
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5), null, null, true, $random);
        $file = $this->store_file(6 * self::MIB);
        // The content is a MiB shorter than the table says: a read from the third MiB gets three MiB, one from the first four.
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file);
        $handle = fopen($path, 'r+b');
        ftruncate($handle, 5 * self::MIB);
        fclose($handle);
        $this->assertEqualsWithDelta(6.0, $monitor->measure_nfs(), 1e-9);
        $this->assertEqualsWithDelta(8.0, $monitor->measure_nfs(), 1e-9);
        $this->assertSame([2, 2], $asked);
        // A file of four MiB or less is read from its start.
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5), null, null, true, $random);
        $this->store_file(4 * self::MIB);
        $this->assertEqualsWithDelta(8.0, $monitor->measure_nfs(), 1e-9);
        $this->assertSame([2, 2], $asked);
        // By default the start is random.
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(7 * self::MIB);
        $this->assertEqualsWithDelta(8.0, $monitor->measure_nfs(), 1e-9);
    }

    public function test_a_sample_without_the_probe_does_not_read_the_file_storage(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(2 * self::MIB);
        $sample = $monitor->sample(false);
        $this->assertNull($sample['nfs_mbps']);
        $this->assertSame(['nfs_mbps'], $sample['not_applicable']);
        $this->assertEqualsWithDelta(4.0, $monitor->sample()['nfs_mbps'], 1e-9);
    }

    public function test_a_file_of_exactly_one_mib_is_big_enough_and_a_smaller_one_is_not(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0));
        $this->store_file(self::MIB - 1);
        $this->assertNull($monitor->measure_nfs());
        $this->store_file(self::MIB);
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0), null, null, false);
        $this->assertEqualsWithDelta(1.0, $monitor->measure_nfs(), 1e-9);
    }

    public function test_the_speed_is_unknown_without_a_big_file(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0));
        $this->assertNull($monitor->measure_nfs());
    }

    public function test_a_clock_that_does_not_move_does_not_divide_by_zero(): void {
        $monitor = $this->monitor(self::healthy_files(), static fn(): float => 5.0);
        $this->store_file(self::MIB);
        $this->assertGreaterThan(1000.0, $this->without_warnings(static fn(): ?float => $monitor->measure_nfs()));
        $this->assertSame(0.0, $monitor->measure_db());
    }

    public function test_the_speed_is_unknown_when_the_content_of_the_file_is_missing_without_warnings(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0));
        $file = $this->store_file(2 * self::MIB);
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file);
        $this->assertTrue(unlink($path));
        $this->assertNull($this->without_warnings(static fn(): ?float => $monitor->measure_nfs()));
    }

    public function test_the_speed_is_unknown_when_the_content_of_the_file_is_empty(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0));
        $file = $this->store_file(2 * self::MIB);
        file_put_contents(get_file_storage()->get_file_system()->get_local_path_from_storedfile($file), '');
        $this->assertNull($this->without_warnings(static fn(): ?float => $monitor->measure_nfs()));
    }

    public function test_the_speed_is_not_measured_when_the_file_system_is_not_the_local_one(): void {
        $external = new class extends \file_system_filedir {
        };
        $monitor = $this->monitor(self::healthy_files(), self::clock(1.0), null, $external);
        $this->store_file(2 * self::MIB);
        $this->assertNull($monitor->measure_nfs());
    }

    /**
     * A sample in which nothing is wrong.
     *
     * @param array $changes Values to replace.
     * @return array
     */
    private static function sample(array $changes = []): array {
        return $changes + [
            'load_per_core' => 0.5,
            'memory_available_bytes' => self::GIB,
            'db_ms' => 10.0,
            'disk_free_bytes' => 100 * self::GIB,
            'disk_total_bytes' => 200 * self::GIB,
            'nfs_mbps' => 50.0,
        ];
    }

    /**
     * Samples and the verdict for them, with the default limits.
     *
     * @return array[]
     */
    public static function verdicts(): array {
        $gib = self::GIB;
        return [
            'nothing is wrong' => [[], null],
            'load at the limit' => [['load_per_core' => 2.0], null],
            'load above the limit' => [['load_per_core' => 2.01], 'server_busy'],
            'memory at the limit' => [['memory_available_bytes' => 256 * self::MIB], null],
            'memory below the limit' => [['memory_available_bytes' => 256 * self::MIB - 1], 'server_busy'],
            'free space at 10% of a big disk' => [['disk_free_bytes' => 20 * $gib], null],
            'free space below 10% of a big disk' => [['disk_free_bytes' => 20 * $gib - 1], 'disk_low'],
            'free space at 5 GB of a small disk' => [['disk_free_bytes' => 5 * $gib, 'disk_total_bytes' => 10 * $gib], null],
            'free space below 5 GB of a small disk' => [
                ['disk_free_bytes' => 5 * $gib - 1, 'disk_total_bytes' => 10 * $gib],
                'disk_low',
            ],
            'free space where 10% and 5 GB are equal' => [['disk_free_bytes' => 5 * $gib, 'disk_total_bytes' => 50 * $gib], null],
            'free space below that' => [['disk_free_bytes' => 5 * $gib - 1, 'disk_total_bytes' => 50 * $gib], 'disk_low'],
            'total unknown, enough free' => [['disk_total_bytes' => null, 'disk_free_bytes' => 5 * $gib], null],
            'total unknown, below 5 GB' => [['disk_total_bytes' => null, 'disk_free_bytes' => 5 * $gib - 1], 'disk_low'],
            'free unknown' => [['disk_free_bytes' => null], null],
            'database at the limit' => [['db_ms' => 500.0], null],
            'database slower than the limit' => [['db_ms' => 500.1], 'db_slow'],
            'storage at the limit' => [['nfs_mbps' => 5.0], null],
            'storage slower than the limit' => [['nfs_mbps' => 4.99], 'nfs_slow'],
            'nothing known' => [
                ['load_per_core' => null, 'memory_available_bytes' => null, 'db_ms' => null, 'disk_free_bytes' => null,
                    'disk_total_bytes' => null, 'nfs_mbps' => null],
                null,
            ],
            'busy before low disk' => [
                ['load_per_core' => 3.0, 'disk_free_bytes' => 1, 'db_ms' => 900.0, 'nfs_mbps' => 1.0],
                'server_busy',
            ],
            'low disk before slow database' => [['disk_free_bytes' => 1, 'db_ms' => 900.0, 'nfs_mbps' => 1.0], 'disk_low'],
            'slow database before slow storage' => [['db_ms' => 900.0, 'nfs_mbps' => 1.0], 'db_slow'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verdicts')]
    /**
     * Verdict.
     *
     * @dataProvider verdicts
     * @param array $changes Values of the sample that differ from a healthy one.
     * @param string|null $expected The verdict.
     */
    public function test_verdict(array $changes, ?string $expected): void {
        $this->assertSame($expected, monitor::verdict(self::sample($changes), self::limits()));
    }

    public function test_the_cause_names_the_load_and_the_memory_apart(): void {
        $this->assertSame('load', monitor::cause(self::sample(['load_per_core' => 2.01]), self::limits()));
        $this->assertSame('memory', monitor::cause(self::sample(['memory_available_bytes' => 1]), self::limits()));
        $this->assertSame('load', monitor::cause(self::sample(['load_per_core' => 3.0, 'memory_available_bytes' => 1]),
            self::limits()));
        $this->assertSame('disk_low', monitor::cause(self::sample(['disk_free_bytes' => 1]), self::limits()));
        $this->assertSame('db_slow', monitor::cause(self::sample(['db_ms' => 900.0]), self::limits()));
        $this->assertSame('nfs_slow', monitor::cause(self::sample(['nfs_mbps' => 1.0]), self::limits()));
        $this->assertNull(monitor::cause(self::sample([]), self::limits()));
    }

    public function test_verdict_follows_the_limits(): void {
        $limits = new limits(0.5, 1024, 5, 100.0, '/t', 0, 0);
        $this->assertSame('server_busy', monitor::verdict(self::sample(['load_per_core' => 0.6]), $limits));
        $this->assertSame('server_busy', monitor::verdict(self::sample(['memory_available_bytes' => 1023 * self::MIB]), $limits));
        $this->assertSame('db_slow', monitor::verdict(self::sample(['db_ms' => 6.0]), $limits));
        $this->assertSame('nfs_slow', monitor::verdict(self::sample(['db_ms' => 1.0, 'nfs_mbps' => 99.0]), $limits));
        $this->assertNull(monitor::verdict(self::sample(['db_ms' => 1.0, 'nfs_mbps' => 500.0]), $limits));
    }

    /**
     * The five values that are read.
     *
     * @return array[]
     */
    public static function sample_keys(): array {
        return [
            ['load_per_core'],
            ['memory_available_bytes'],
            ['db_ms'],
            ['disk_free_bytes'],
            ['nfs_mbps'],
        ];
    }

    public function test_the_pause_is_zero_when_every_value_is_known(): void {
        $this->assertSame(0, monitor::pause_seconds(self::sample()));
        // The total size of the disk is only the base of a limit: not knowing it does not widen the pause.
        $this->assertSame(0, monitor::pause_seconds(self::sample(['disk_total_bytes' => null])));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sample_keys')]
    /**
     * The pause is 30 seconds when a value is unknown.
     *
     * @dataProvider sample_keys
     * @param string $key A value of the sample.
     */
    public function test_the_pause_is_30_seconds_when_a_value_is_unknown(string $key): void {
        $this->assertSame(30, monitor::pause_seconds(self::sample([$key => null])));
        $sample = self::sample();
        unset($sample[$key]);
        $this->assertSame(30, monitor::pause_seconds($sample));
    }

    public function test_a_site_without_a_big_file_does_not_wait_for_a_speed_that_does_not_exist(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.01));
        $sample = $monitor->sample();
        $this->assertNull($sample['nfs_mbps']);
        $this->assertSame(['nfs_mbps'], $sample['not_applicable']);
        $this->assertSame(0, monitor::pause_seconds($sample));
    }

    public function test_an_external_store_does_not_make_the_run_wait(): void {
        $external = new class extends \file_system_filedir {
        };
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.01), null, $external);
        $this->store_file(2 * self::MIB);
        $sample = $monitor->sample();
        $this->assertNull($sample['nfs_mbps']);
        $this->assertSame(['nfs_mbps'], $sample['not_applicable']);
        $this->assertSame(0, monitor::pause_seconds($sample));
    }

    public function test_a_local_store_with_a_big_file_has_nothing_that_does_not_apply(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->store_file(2 * self::MIB);
        $sample = $this->without_warnings(static fn(): array => $monitor->sample());
        $this->assertSame([], $sample['not_applicable']);
        $this->assertEqualsWithDelta(4.0, $sample['nfs_mbps'], 1e-9);
        $this->assertSame(0, monitor::pause_seconds($sample));
    }

    public function test_an_unreadable_big_file_still_makes_the_run_wait(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $file = $this->store_file(2 * self::MIB);
        unlink(get_file_storage()->get_file_system()->get_local_path_from_storedfile($file));
        $sample = $this->without_warnings(static fn(): array => $monitor->sample());
        $this->assertNull($sample['nfs_mbps']);
        $this->assertSame([], $sample['not_applicable']);
        $this->assertSame(30, monitor::pause_seconds($sample));
    }

    public function test_an_unreadable_load_still_gives_the_pause_when_the_speed_does_not_apply(): void {
        $monitor = $this->monitor(self::files(null, "MemAvailable: 1000000 kB\n", "0-3\n"), self::clock(0.01));
        $sample = $monitor->sample();
        $this->assertSame(['nfs_mbps'], $sample['not_applicable']);
        $this->assertSame(30, monitor::pause_seconds($sample));
    }

    public function test_pause_seconds_ignores_only_the_keys_that_do_not_apply(): void {
        $this->assertSame(0, monitor::pause_seconds(self::sample(['nfs_mbps' => null, 'not_applicable' => ['nfs_mbps']])));
        $this->assertSame(30, monitor::pause_seconds(self::sample(['db_ms' => null, 'not_applicable' => ['nfs_mbps']])));
        $this->assertSame(30, monitor::pause_seconds(self::sample(['nfs_mbps' => null, 'not_applicable' => []])));
        $this->assertSame(30, monitor::pause_seconds(self::sample(['nfs_mbps' => null, 'not_applicable' => ['db_ms']])));
    }

    public function test_the_file_to_read_is_chosen_once_per_monitor(): void {
        global $DB;
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $file = $this->store_file(2 * self::MIB);
        $this->assertEqualsWithDelta(4.0, $monitor->measure_nfs(), 1e-9);
        // The query for a big file does not run again: the file that was chosen is read although it no longer matches.
        $DB->set_field('files', 'filesize', 0, ['id' => $file->get_id()]);
        $this->assertEqualsWithDelta(4.0, $monitor->measure_nfs(), 1e-9);
    }

    public function test_a_site_without_a_big_file_asks_the_database_once(): void {
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $this->assertNull($monitor->measure_nfs());
        $this->store_file(2 * self::MIB);
        // Not looked for again: the answer of the first query stays for the life of the monitor.
        $this->assertNull($monitor->measure_nfs());
        $this->assertSame(['nfs_mbps'], $monitor->sample()['not_applicable']);
    }

    public function test_the_file_that_was_chosen_and_then_removed_is_looked_for_again(): void {
        global $DB;
        $monitor = $this->monitor(self::healthy_files(), self::clock(0.5));
        $first = $this->store_file(2 * self::MIB);
        $this->assertEqualsWithDelta(4.0, $monitor->measure_nfs(), 1e-9);
        $DB->delete_records('files', ['id' => $first->get_id()]);
        // The chosen file is gone: one unknown value, then the choice is made again.
        $second = $this->store_file(3 * self::MIB);
        $this->assertNull($monitor->measure_nfs());
        $this->assertEqualsWithDelta(6.0, $monitor->measure_nfs(), 1e-9);
        $this->assertNotSame($first->get_id(), $second->get_id());
    }

    public function test_the_time_to_open_the_file_counts(): void {
        $monitor = null;
        $file = null;
        $calls = 0;
        // The first reading of the clock removes the content: a clock that starts before the file is opened loses it.
        $clock = function () use (&$file, &$calls): float {
            $calls++;
            if ($calls === 1) {
                unlink(get_file_storage()->get_file_system()->get_local_path_from_storedfile($file));
            }
            return (float) $calls;
        };
        $monitor = $this->monitor(self::healthy_files(), $clock);
        $file = $this->store_file(2 * self::MIB);
        $this->assertNull($this->without_warnings(static fn(): ?float => $monitor->measure_nfs()));
        $this->assertGreaterThanOrEqual(1, $calls);
    }
}
