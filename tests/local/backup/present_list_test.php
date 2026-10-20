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
use tool_secbackup\tests\faulty_stream;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\present_list::class)]
/**
 * Tests for the list of the chunks that the storage holds.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\present_list
 */
final class present_list_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        faulty_stream::unregister();
        parent::tearDown();
    }

    /**
     * Loads a list of two chunks through the wrapper that makes faults, with a fault.
     *
     * @param string $fault The operation that fails.
     * @param string $suffix The end of the path that it applies to.
     * @param string $folder The real folder of the records.
     * @return present_list
     */
    private function load_with_fault(string $fault, string $suffix, string $folder): present_list {
        faulty_stream::register();
        $gz = $this->gz(self::header(2) . str_repeat('a', 64) . " 10\n" . str_repeat('b', 64) . " 20\n");
        faulty_stream::fail($fault, $suffix);
        return present_list::load(
            faulty_stream::url($gz),
            self::SITE,
            faulty_stream::url($folder),
            static fn(string $path): array => [1073741824, 2147483648]
        );
    }

    /**
     * Writes a gzip file.
     *
     * @param string $text The text.
     * @return string Path.
     */
    private function gz(string $text): string {
        $path = make_request_directory() . '/present';
        file_put_contents($path, gzencode($text));
        return $path;
    }

    /**
     * A good header.
     *
     * @param int $count Number of lines after it.
     * @param array $override Fields that replace those of the header.
     * @return string The line, with a line feed.
     */
    private static function header(int $count, array $override = []): string {
        return json_encode($override + ['v' => 1, 'site_id' => self::SITE, 'generated_at' => '2026-10-18T09:00:00Z',
            'count' => $count]) . "\n";
    }

    /**
     * Loads a list into a new folder.
     *
     * @param string $path The file.
     * @param callable|null $diskspace Measure of the disk.
     * @return present_list
     */
    private function load(string $path, ?callable $diskspace = null): present_list {
        return present_list::load($path, self::SITE, make_request_directory(), $diskspace);
    }

    public function test_the_sizes_are_found_by_name(): void {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $c = str_repeat('0', 63) . '1';
        $list = $this->load($this->gz(self::header(3) . $c . " 7\n" . $a . " 10\n" . $b . " 9007199254740991\n"));
        $this->assertSame(3, $list->count());
        $this->assertSame(10, $list->size_of($a));
        $this->assertSame(9007199254740991, $list->size_of($b));
        $this->assertSame(7, $list->size_of($c));
        $this->assertNull($list->size_of(str_repeat('c', 64)));
        $this->assertNull($list->size_of(str_repeat('0', 64)));
        $this->assertNull($list->size_of('not a name'));
        $list->close();
    }

    public function test_every_name_of_a_longer_list_is_found_and_the_ones_between_are_not(): void {
        $text = '';
        $names = [];
        for ($i = 1; $i <= 101; $i++) {
            $name = hash('sha256', 'n' . $i);
            $names[$name] = $i * 3;
        }
        ksort($names, SORT_STRING);
        foreach ($names as $name => $size) {
            $text .= $name . ' ' . $size . "\n";
        }
        $list = $this->load($this->gz(self::header(101) . $text));
        foreach ($names as $name => $size) {
            $this->assertSame($size, $list->size_of($name));
        }
        $this->assertNull($list->size_of(hash('sha256', 'absent')));
    }

    public function test_the_temporary_file_is_removed_on_close_and_when_the_list_goes(): void {
        $folder = make_request_directory();
        $list = present_list::load($this->gz(self::header(1) . str_repeat('a', 64) . " 1\n"), self::SITE, $folder);
        $this->assertCount(1, glob($folder . '/*'));
        $list->close();
        $this->assertSame([], glob($folder . '/*'));
        $this->assertNull($list->size_of(str_repeat('a', 64)));
        $list->close();
        $other = present_list::load($this->gz(self::header(1) . str_repeat('a', 64) . " 1\n"), self::SITE, $folder);
        $this->assertCount(1, glob($folder . '/*'));
        unset($other);
        $this->assertSame([], glob($folder . '/*'));
    }

    public function test_a_list_without_chunks_is_empty(): void {
        $list = $this->load($this->gz(self::header(0)));
        $this->assertSame(0, $list->count());
        $this->assertNull($list->size_of(str_repeat('a', 64)));
    }

    public function test_a_list_that_was_not_fetched_is_empty(): void {
        // A first run: the storage has no list yet (404), and the file is not there.
        $folder = make_request_directory();
        $list = present_list::load($folder . '/none', self::SITE, $folder);
        $this->assertSame(0, $list->count());
        $this->assertNull($list->size_of(str_repeat('a', 64)));
        $this->assertSame([], glob($folder . '/*'));
    }

    /**
     * Lists that are not the list of this site.
     *
     * @return array
     */
    public static function bad_list_provider(): array {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $header = static fn(int $count, array $override = []): string => self::header($count, $override);
        return [
            'another site' => [$header(1, ['site_id' => '33333333-3333-4333-8333-333333333333']) . $a . " 1\n"],
            'another version' => [$header(1, ['v' => 2]) . $a . " 1\n"],
            'a version as text' => [$header(1, ['v' => '1']) . $a . " 1\n"],
            'no site' => [json_encode(['v' => 1, 'count' => 0]) . "\n"],
            'a key more in the header' => [$header(1, ['x' => 1]) . $a . " 1\n"],
            'no time in the header' => [json_encode(['v' => 1, 'site_id' => self::SITE, 'count' => 0]) . "\n"],
            'a time that is not one' => [$header(1, ['generated_at' => 'yesterday']) . $a . " 1\n"],
            'a header that is not JSON' => ["not json\n"],
            'a header that is a list' => ["[1]\n"],
            'an empty text' => [''],
            'a count that is text' => [$header(1, ['count' => '1']) . $a . " 1\n"],
            'a negative count' => [$header(-1)],
            'a count beyond what is read' => [$header(10000001)],
            'fewer lines than counted' => [$header(2) . $a . " 1\n"],
            'more lines than counted' => [$header(1) . $a . " 1\n" . $b . " 1\n"],
            'a line without a size' => [$header(1) . $a . "\n"],
            'a line with a short name' => [$header(1) . "abc 1\n"],
            'a line with a name in capitals' => [$header(1) . str_repeat('A', 64) . " 1\n"],
            'a size of zero' => [$header(1) . $a . " 0\n"],
            'a size with a sign' => [$header(1) . $a . " +1\n"],
            'a size with a leading zero' => [$header(1) . $a . " 01\n"],
            'a size beyond what a number holds' => [$header(1) . $a . " 9007199254740992\n"],
            'a size with a space after it' => [$header(1) . $a . " 1 \n"],
            'names out of order' => [$header(2) . $b . " 1\n" . $a . " 1\n"],
            'a name twice' => [$header(2) . $a . " 1\n" . $a . " 2\n"],
            'a last line without its line feed' => [$header(1) . $a . ' 1'],
            'a line that never ends' => [$header(1) . str_repeat('a', 500)],
            'a large count and nothing after it' => [$header(9000000)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_list_provider')]
    /**
     * A list that is not the list of this site, or not in its form, stops the run with signature_invalid.
     *
     * @dataProvider bad_list_provider
     * @param string $text The text of the list.
     */
    public function test_a_list_that_is_not_the_list_of_this_site_is_a_signature_failure(string $text): void {
        $folder = make_request_directory();
        try {
            present_list::load($this->gz($text), self::SITE, $folder);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('signature_invalid', $e->errorcode);
        }
        $this->assertSame([], glob($folder . '/*'), 'The temporary file is removed.');
    }

    public function test_a_file_that_is_not_gzip_is_a_signature_failure(): void {
        $path = make_request_directory() . '/present';
        file_put_contents($path, "plain text\n");
        $this->expectException(plugin_exception::class);
        $this->load($path);
    }

    public function test_a_truncated_gzip_is_a_signature_failure(): void {
        $path = make_request_directory() . '/present';
        $gz = gzencode(self::header(1) . str_repeat('a', 64) . " 1\n");
        file_put_contents($path, substr($gz, 0, -6));
        try {
            $this->load($path);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('signature_invalid', $e->errorcode);
        }
    }

    public function test_data_after_the_end_of_the_compressed_stream_is_a_signature_failure(): void {
        $path = make_request_directory() . '/present';
        file_put_contents($path, gzencode(self::header(0)) . 'x');
        $this->expectException(plugin_exception::class);
        $this->load($path);
    }

    public function test_the_site_of_the_argument_is_a_uuid(): void {
        $this->expectException(\coding_exception::class);
        present_list::load($this->gz(self::header(0)), 'x', make_request_directory());
    }

    public function test_without_room_for_the_records_the_run_stops_with_disk_low(): void {
        $folder = make_request_directory();
        $text = self::header(100);
        for ($i = 0; $i < 100; $i++) {
            $text .= sprintf('%064x', $i) . " 1\n";
        }
        $seen = null;
        $diskspace = static function (string $dir) use (&$seen): array {
            $seen = $dir;
            return [3999, 100000];
        };
        try {
            present_list::load($this->gz($text), self::SITE, $folder, $diskspace);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('disk_low', $e->errorcode);
        }
        $this->assertSame($folder, $seen);
        $this->assertSame([], glob($folder . '/*'));
        // 40 bytes a record: 4,000 bytes are enough. An unknown space is not a reason to stop.
        $this->assertSame(100, present_list::load($this->gz($text), self::SITE, $folder, fn(): array => [4000, 1])->count());
        $this->assertSame(100, present_list::load($this->gz($text), self::SITE, $folder, fn(): array => [null, null])->count());
    }

    public function test_a_large_list_is_read_without_holding_it_in_memory(): void {
        $path = make_request_directory() . '/present';
        $out = fopen($path, 'wb');
        $context = deflate_init(ZLIB_ENCODING_GZIP);
        $count = 200000;
        fwrite($out, deflate_add($context, self::header($count), ZLIB_NO_FLUSH));
        $piece = '';
        for ($i = 0; $i < $count; $i++) {
            $piece .= sprintf('%064x', $i) . ' ' . ($i + 1) . "\n";
            if ($i % 5000 === 4999) {
                fwrite($out, deflate_add($context, $piece, ZLIB_NO_FLUSH));
                $piece = '';
            }
        }
        fwrite($out, deflate_add($context, $piece, ZLIB_FINISH));
        fclose($out);
        // The peak can be reset from PHP 8.2 on. Before it, the peak holds what earlier tests used, so the bound is left out
        // there and the assertions below (the sizes are found in the file) are what the test says.
        $resettable = function_exists('memory_reset_peak_usage');
        if ($resettable) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage();
        $list = $this->load($path);
        if ($resettable) {
            $this->assertLessThan(4 * 1048576, memory_get_peak_usage() - $before);
        }
        $this->assertSame($count, $list->count());
        $this->assertSame(1, $list->size_of(sprintf('%064x', 0)));
        $this->assertSame(123457, $list->size_of(sprintf('%064x', 123456)));
        $this->assertSame($count, $list->size_of(sprintf('%064x', $count - 1)));
        $this->assertNull($list->size_of(sprintf('%064x', $count)));
        $list->close();
    }

    public function test_a_folder_that_cannot_be_written_is_a_failure(): void {
        $gz = $this->gz(self::header(0));
        try {
            present_list::load($gz, self::SITE, make_request_directory() . '/missing');
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
    }

    /**
     * The faults of the files of a present list, and what each gives.
     *
     * @return array
     */
    public static function fault_provider(): array {
        return [
            'the fetched file does not open' => ['open:rb', '/present', 'signature_invalid', 'open'],
            'the records cannot be written' => ['write', '.records', 'temp_write_failed', 'present_write'],
            'the records do not open again' => ['open:rb', '.records', 'temp_write_failed', 'present_reopen'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fault_provider')]
    /**
     * A file of the list that fails is a failure that leaves no records in the folder.
     *
     * @dataProvider fault_provider
     * @param string $fault The operation that fails.
     * @param string $suffix The end of the path that it applies to.
     * @param string $code The error code.
     * @param string $info The debug information.
     */
    public function test_a_file_of_the_list_that_fails_leaves_no_records(
        string $fault,
        string $suffix,
        string $code,
        string $info
    ): void {
        $folder = make_request_directory();
        try {
            $this->load_with_fault($fault, $suffix, $folder);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertStringContainsString($info, (string) $e->debuginfo);
        }
        $this->assertTrue(faulty_stream::used(), 'The fault was met.');
        $this->assertSame([], glob($folder . '/*.records'));
    }

    public function test_a_list_read_through_the_wrapper_without_a_fault_is_found(): void {
        $folder = make_request_directory();
        $list = $this->load_with_fault('open:rb', '/not-a-file-of-the-list', $folder);
        $this->assertSame(2, $list->count());
        $this->assertSame(20, $list->size_of(str_repeat('b', 64)));
        $list->close();
        $this->assertSame([], glob($folder . '/*.records'));
    }
}
