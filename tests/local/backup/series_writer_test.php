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

/**
 * Tests for the packing of the files of the data directory and of the code into numbered chunks.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\failing_stream;
use tool_secbackup\tests\full_disk_stream;
use tool_secbackup\tests\trace_strings;

defined('MOODLE_INTERNAL') || die();

/**
 * Wipes a buffer as libsodium does, and keeps a copy of it for the tests of series_writer_test.
 *
 * PHP looks up a function that is called without a namespace in the namespace of the caller first, so while the tests run,
 * the classes of this namespace call this function instead of the one of libsodium. PHPUnit loads this file, which declares
 * it, before any test runs.
 *
 * @package    tool_secbackup
 * @param string $buffer The buffer, which is wiped.
 */
function sodium_memzero(string &$buffer): void {
    series_writer_test::$wiped[] = $buffer;
    \sodium_memzero($buffer);
}

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\series_writer::class)]
/**
 * Tests for the packing of the files of the data directory and of the code into numbered chunks.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\series_writer
 */
final class series_writer_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string UUID of the run of the tests. */
    private const RUN = '6f1d0c52-97d8-4f1e-8d3b-0a5e2c7b9e11';

    /** @var int One mebibyte. */
    private const MIB = 1048576;

    /** @var string[] Copies of the buffers that sodium_memzero() wiped, in order. */
    public static array $wiped = [];

    /** @var string Folder of the chunks of a test. */
    private string $dir;

    /** @var string Folder of the files that are packed. */
    private string $base;

    /** @var string|false The setting of zend.exception_ignore_args before the test. */
    private string|false $ignoreargs;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->dir = make_request_directory();
        $this->base = make_request_directory();
        failing_stream::register();
        // A trace holds the arguments of the calls only while this is off, so the tests of traces need it off.
        $this->ignoreargs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreargs);
        failing_stream::unregister();
        full_disk_stream::unregister();
        parent::tearDown();
    }

    /**
     * The key for names.
     *
     * @return string
     */
    private static function key(): string {
        return str_repeat("\x11", 32);
    }

    /**
     * The encryption key pair of the site of the tests.
     *
     * @return string
     */
    private static function pair(): string {
        return sodium_crypto_box_seed_keypair(str_repeat("\x07", 32));
    }

    /**
     * A writer.
     *
     * @param string $label "dataroot" or "code".
     * @param string|null $dir The folder of the chunks; the folder of the test by default.
     * @return series_writer
     */
    private function writer(string $label = 'dataroot', ?string $dir = null): series_writer {
        return new series_writer(
            self::key(),
            $label,
            self::RUN,
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $dir ?? $this->dir
        );
    }

    /**
     * Puts a file in the folder of the files.
     *
     * @param string $path Relative path.
     * @param string $content Content.
     */
    private function put(string $path, string $content): void {
        $dir = dirname($this->base . '/' . $path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->base . '/' . $path, $content);
    }

    /**
     * A file of many mebibytes in the folder of the files: a fresh random block for every mebibyte.
     *
     * @param string $path Relative path.
     * @param int $bytes Size.
     * @return string SHA-256 of the content in hexadecimal.
     */
    private function put_big(string $path, int $bytes): string {
        $handle = fopen($this->base . '/' . $path, 'wb');
        $hash = hash_init('sha256');
        for ($done = 0; $done < $bytes; $done += self::MIB) {
            $piece = random_bytes(min(self::MIB, $bytes - $done));
            hash_update($hash, $piece);
            fwrite($handle, $piece);
        }
        fclose($handle);
        return hash_final($hash);
    }

    /**
     * A reader of a chunk.
     *
     * @param chunk_info $info The chunk.
     * @return chunk_reader
     */
    private static function reader(chunk_info $info): chunk_reader {
        return new chunk_reader(
            $info->path,
            sodium_crypto_box_secretkey(self::pair()),
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $info->name
        );
    }

    /**
     * The names of the files in the folder of the chunks.
     *
     * @return string[]
     */
    private function files(): array {
        $files = array_values(array_diff(scandir($this->dir), ['.', '..']));
        sort($files);
        return $files;
    }

    /**
     * The content of the entries, read through their own offsets.
     *
     * @param array[] $entries The entries.
     * @param chunk_info[] $infos The chunks.
     * @return string[] The content of each entry.
     */
    private static function content_of(array $entries, array $infos): array {
        $byname = [];
        foreach ($infos as $info) {
            $byname[$info->name] = $info;
        }
        $content = [];
        foreach ($entries as $index => $entry) {
            $content[$index] = self::reader($byname[$entry['chunk']])->read_item($entry['offset']);
        }
        return $content;
    }

    /**
     * The name of the chunk with a number.
     *
     * @param int $n The number.
     * @param string $label The series.
     * @return string
     */
    private static function chunkname(int $n, string $label = 'dataroot'): string {
        return chunk_name::series(self::key(), $label, self::RUN, $n);
    }

    /**
     * Checks that no argument in the trace of an exception holds the secret content.
     *
     * @param \Throwable $exception The exception.
     */
    private function assert_trace_has_no_content(\Throwable $exception): void {
        $this->assertNotEmpty(
            array_filter($exception->getTrace(), static fn(array $frame): bool => !empty($frame['args'])),
            'The trace keeps the arguments, so the check means something.'
        );
        $texts = array_merge([$exception->getMessage(), $exception->getTraceAsString()], trace_strings::of($exception));
        foreach ($texts as $text) {
            $this->assertStringNotContainsString('SECRETMARKER', $text);
            $this->assertStringNotContainsString(self::key(), $text);
        }
        $this->assertNull($exception->getPrevious());
        $this->assertSame([], trace_strings::objects_in_plugin_frames($exception), 'No call of the plugin has an object.');
        $this->assertFalse(trace_strings::has_throwable_argument($exception), 'No call has an exception as an argument.');
    }

    public function test_small_files_are_items_of_one_chunk_in_the_order_they_were_added(): void {
        $contents = [];
        $writer = $this->writer();
        for ($i = 0; $i < 10; $i++) {
            $contents[$i] = str_repeat(chr(65 + $i), 1000 + $i);
            $this->put('d/f' . $i, $contents[$i]);
            $this->assertSame([], $writer->add('dataroot', $this->base, 'd/f' . $i));
        }
        $this->assertSame([], $writer->entries());
        $infos = $writer->flush();
        $this->assertCount(1, $infos);
        $info = $infos[0];
        $this->assertSame(self::chunkname(0), $info->name);
        $this->assertSame([$info->name], $this->files());
        $this->assertSame(chunk_writer::TYPE_FILES, self::reader($info)->type());
        $entries = $writer->entries();
        $this->assertCount(10, $entries);
        $read = self::content_of($entries, $infos);
        foreach ($contents as $i => $content) {
            $this->assertSame($content, $read[$i]);
            $this->assertSame([
                't' => 'dataroot', 'root' => 'dataroot', 'path' => 'd/f' . $i, 'size' => strlen($content),
                'sha256' => hash('sha256', $content), 'chunk' => $info->name,
                'offset' => $info->items[$i]['offset'], 'length' => $info->items[$i]['length'], 'piece' => 0, 'pieces' => 1,
            ], $entries[$i]);
        }
        $this->assertSame([], $writer->flush());
        $this->assertSame([], $writer->unreadable());
    }

    public function test_code_is_chunks_of_type_3_with_the_label_in_the_entries_and_the_name(): void {
        $this->put('plugin/version.php', '<?php // x');
        $writer = $this->writer('code');
        $writer->add('tool_x', $this->base, 'plugin/version.php');
        $infos = $writer->flush();
        $this->assertSame(self::chunkname(0, 'code'), $infos[0]->name);
        $this->assertSame(chunk_writer::TYPE_CODE, self::reader($infos[0])->type());
        $entry = $writer->entries()[0];
        $this->assertSame('code', $entry['t']);
        $this->assertSame('tool_x', $entry['root']);
        $this->assertSame(['<?php // x'], self::content_of([$entry], $infos));
    }

    public function test_an_empty_file_is_an_item_of_no_bytes(): void {
        $this->put('empty', '');
        $writer = $this->writer();
        $writer->add('dataroot', $this->base, 'empty');
        $infos = $writer->flush();
        $entry = $writer->entries()[0];
        $this->assertSame(0, $entry['size']);
        $this->assertSame(hash('sha256', ''), $entry['sha256']);
        $this->assertSame([''], self::content_of([$entry], $infos));
    }

    public function test_a_chunk_is_finished_when_it_holds_32_mib_of_content(): void {
        $content = str_repeat('x', 2 * self::MIB);
        $this->put('f', $content);
        $writer = $this->writer();
        $returned = [];
        for ($i = 0; $i < 17; $i++) {
            $returned[$i] = count($writer->add('dataroot', $this->base, 'f'));
        }
        // Sixteen files of 2 MiB are exactly 32 MiB.
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0], $returned);
        $infos = $writer->flush();
        $this->assertCount(1, $infos);
        $entries = $writer->entries();
        $this->assertCount(17, $entries);
        $this->assertSame(array_fill(0, 16, self::chunkname(0)), array_column(array_slice($entries, 0, 16), 'chunk'));
        $this->assertSame(self::chunkname(1), $entries[16]['chunk']);
    }

    public function test_a_file_that_does_not_fit_in_the_room_left_starts_a_new_chunk(): void {
        $size = 3 * self::MIB + self::MIB / 2;
        $this->put('f', str_repeat('y', $size));
        $writer = $this->writer();
        $returned = [];
        for ($i = 0; $i < 11; $i++) {
            $returned[$i] = count($writer->add('dataroot', $this->base, 'f'));
        }
        // Nine files are 31.5 MiB; the tenth does not fit in the 0.5 MiB that are left.
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0], $returned);
        $this->assertCount(1, $writer->flush());
        $this->assertCount(2, $this->files());
        $entries = $writer->entries();
        $this->assertSame(array_fill(0, 9, self::chunkname(0)), array_column(array_slice($entries, 0, 9), 'chunk'));
        $this->assertSame([self::chunkname(1), self::chunkname(1)], array_column(array_slice($entries, 9), 'chunk'));
    }

    public function test_the_limit_of_a_small_file_is_4_mib(): void {
        $this->put('small', str_repeat('s', 4 * self::MIB - 1));
        $this->put('big', str_repeat('b', 4 * self::MIB));
        $writer = $this->writer();
        $this->assertSame([], $writer->add('dataroot', $this->base, 'small'));
        // The file of 4 MiB is streamed, in a chunk of its own, and the chunk of the small one is finished before it.
        $infos = $writer->add('dataroot', $this->base, 'big');
        $this->assertCount(2, $infos);
        $names = array_map(static fn(chunk_info $info): string => $info->name, $infos);
        $this->assertSame([self::chunkname(0), self::chunkname(1)], $names);
        $entries = $writer->entries();
        $this->assertSame(['small', 'big'], array_column($entries, 'path'));
        $this->assertSame(4 * self::MIB, $entries[1]['size']);
        $this->assertSame(1, $entries[1]['pieces']);
    }

    public function test_a_big_file_is_a_chunk_of_its_own_between_small_files(): void {
        $this->put('a', 'first');
        $hash = $this->put_big('big', 5 * self::MIB);
        $this->put('c', 'last');
        $writer = $this->writer();
        $this->assertSame([], $writer->add('dataroot', $this->base, 'a'));
        $infos = $writer->add('dataroot', $this->base, 'big');
        $this->assertCount(2, $infos);
        $this->assertSame([], $writer->add('dataroot', $this->base, 'c'));
        $infos = array_merge($infos, $writer->flush());
        $this->assertCount(3, $infos);
        $entries = $writer->entries();
        $this->assertSame(['a', 'big', 'c'], array_column($entries, 'path'));
        $this->assertSame([self::chunkname(0), self::chunkname(1), self::chunkname(2)], array_column($entries, 'chunk'));
        $this->assertSame($hash, $entries[1]['sha256']);
        $read = self::content_of($entries, $infos);
        $this->assertSame(['first', file_get_contents($this->base . '/big'), 'last'], $read);
        foreach ($entries as $index => $entry) {
            $info = $infos[$index];
            $this->assertSame($info->items[0]['offset'], $entry['offset']);
            $this->assertSame($info->items[0]['length'], $entry['length']);
        }
    }

    public function test_a_file_of_70_mib_is_three_chunks_and_32_mib_is_one(): void {
        $hash = $this->put_big('big', 70 * self::MIB);
        $exact = $this->put_big('exact', 32 * self::MIB);
        $writer = $this->writer();
        $infos = $writer->add('dataroot', $this->base, 'big');
        $this->assertCount(3, $infos);
        $infos = array_merge($infos, $writer->add('dataroot', $this->base, 'exact'));
        $this->assertCount(4, $infos);
        $this->assertSame([], $writer->flush());
        $entries = $writer->entries();
        $this->assertCount(4, $entries);
        $whole = hash_init('sha256');
        $sizes = [32 * self::MIB, 32 * self::MIB, 6 * self::MIB];
        $digests = [];
        foreach (array_slice($entries, 0, 3) as $i => $entry) {
            $this->assertSame([
                't' => 'dataroot', 'root' => 'dataroot', 'path' => 'big', 'size' => 70 * self::MIB, 'sha256' => $hash,
                'chunk' => self::chunkname($i), 'offset' => $infos[$i]->items[0]['offset'],
                'length' => $infos[$i]->items[0]['length'], 'piece' => $i, 'pieces' => 3,
            ], $entry);
            $plain = self::reader($infos[$i])->read_item($entry['offset']);
            $this->assertSame($sizes[$i], strlen($plain));
            $digests[$i] = md5($plain);
            hash_update($whole, $plain);
        }
        $this->assertCount(3, array_unique($digests));
        $this->assertSame($hash, hash_final($whole));
        $this->assertSame(['exact', 32 * self::MIB, $exact, 1, 0], [
            $entries[3]['path'], $entries[3]['size'], $entries[3]['sha256'], $entries[3]['pieces'], $entries[3]['piece'],
        ]);
        $this->assertSame(self::chunkname(3), $entries[3]['chunk']);
        $this->assertCount(4, $this->files());
    }

    public function test_the_root_is_in_the_entry_and_in_the_unreadable_list(): void {
        $this->put('a', 'one');
        $writer = $this->writer();
        $writer->add('secret', $this->base, 'a');
        $writer->add('secret', $this->base, 'missing');
        $writer->add('dataroot', $this->base, 'also/missing');
        $writer->flush();
        $this->assertSame('secret', $writer->entries()[0]['root']);
        $this->assertSame(['secret:missing', 'dataroot:also/missing'], $writer->unreadable());
    }

    public function test_a_folder_in_place_of_a_file_is_unreadable(): void {
        mkdir($this->base . '/folder');
        $writer = $this->writer();
        $this->assertSame([], $writer->add('dataroot', $this->base, 'folder'));
        $this->assertSame(['dataroot:folder'], $writer->unreadable());
        $this->assertSame([], $writer->flush());
        $this->assertSame([], $this->files());
    }

    public function test_a_small_file_that_cannot_be_read_is_unreadable_and_the_others_stay(): void {
        $this->put('a', 'aaa');
        $this->put('b', 'bbb');
        $this->put('c', 'ccc');
        $writer = $this->writer();
        $writer->add('dataroot', $this->base, 'a');
        failing_stream::fail(1, 0);
        $this->assertSame([], $writer->add('dataroot', failing_stream::PROTOCOL . '://' . $this->base, 'b'));
        $writer->add('dataroot', $this->base, 'c');
        $infos = $writer->flush();
        $this->assertSame(['dataroot:b'], $writer->unreadable());
        $entries = $writer->entries();
        $this->assertSame(['a', 'c'], array_column($entries, 'path'));
        $this->assertSame(['aaa', 'ccc'], self::content_of($entries, $infos));
    }

    public function test_a_small_file_that_changes_its_length_is_taken_as_it_is_read(): void {
        $this->put('grows', str_repeat('g', 100));
        $this->put('shrinks', str_repeat('s', 100));
        $writer = $this->writer();
        $base = failing_stream::PROTOCOL . '://' . $this->base;
        failing_stream::fail(0);
        failing_stream::$onread = function (int $given): void {
            file_put_contents($this->base . '/grows', 'MORE', FILE_APPEND);
            failing_stream::$onread = null;
        };
        $writer->add('dataroot', $base, 'grows');
        failing_stream::$onread = function (int $given): void {
            file_put_contents($this->base . '/shrinks', str_repeat('s', 40));
            failing_stream::$onread = null;
        };
        $writer->add('dataroot', $base, 'shrinks');
        $infos = $writer->flush();
        $entries = $writer->entries();
        $this->assertSame([104, 40], array_column($entries, 'size'));
        $read = self::content_of($entries, $infos);
        $this->assertSame(str_repeat('g', 100) . 'MORE', $read[0]);
        $this->assertSame(hash('sha256', $read[1]), $entries[1]['sha256']);
        $this->assertSame([], $writer->unreadable());
    }

    public function test_a_small_file_that_grows_past_4_mib_is_unreadable(): void {
        $this->put('grows', str_repeat('g', 4 * self::MIB - 10));
        $writer = $this->writer();
        failing_stream::$onread = function (int $given): void {
            file_put_contents($this->base . '/grows', str_repeat('G', 100), FILE_APPEND);
            failing_stream::$onread = null;
        };
        $this->assertSame([], $writer->add('dataroot', failing_stream::PROTOCOL . '://' . $this->base, 'grows'));
        $this->assertSame([], $writer->flush());
        $this->assertSame(['dataroot:grows'], $writer->unreadable());
        $this->assertSame([], $writer->entries());
    }

    public function test_a_big_file_that_cannot_be_read_leaves_no_chunk_and_the_next_file_gets_a_new_number(): void {
        $this->put('a', 'first');
        $this->put_big('big', 5 * self::MIB);
        $this->put('c', 'last');
        $writer = $this->writer();
        $writer->add('dataroot', $this->base, 'a');
        failing_stream::fail(1, self::MIB);
        $infos = $writer->add('dataroot', failing_stream::PROTOCOL . '://' . $this->base, 'big');
        // The chunk with the small file was finished before the big one started, and it stays.
        $this->assertCount(1, $infos);
        $this->assertSame([self::chunkname(0)], $this->files());
        $this->assertSame(['dataroot:big'], $writer->unreadable());
        $writer->add('dataroot', $this->base, 'c');
        $infos = array_merge($infos, $writer->flush());
        $entries = $writer->entries();
        $this->assertSame(['a', 'c'], array_column($entries, 'path'));
        $this->assertSame([self::chunkname(0), self::chunkname(2)], array_column($entries, 'chunk'));
        $this->assertSame(['first', 'last'], self::content_of($entries, $infos));
        $names = [self::chunkname(0), self::chunkname(2)];
        sort($names);
        $this->assertSame($names, $this->files());
    }

    public function test_a_read_that_fails_after_the_last_byte_is_unreadable(): void {
        $this->put_big('big', 5 * self::MIB);
        $writer = $this->writer();
        failing_stream::fail(1, 5 * self::MIB);
        $this->assertSame([], $writer->add('dataroot', failing_stream::PROTOCOL . '://' . $this->base, 'big'));
        $this->assertSame(['dataroot:big'], $writer->unreadable());
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
    }

    public function test_a_big_file_that_grows_or_shrinks_while_it_is_read_is_unreadable(): void {
        $this->put_big('grows', 5 * self::MIB);
        $this->put_big('shrinks', 5 * self::MIB);
        $writer = $this->writer();
        $base = failing_stream::PROTOCOL . '://' . $this->base;
        failing_stream::$onread = function (int $given): void {
            file_put_contents($this->base . '/grows', 'MORE', FILE_APPEND);
            failing_stream::$onread = null;
        };
        $this->assertSame([], $writer->add('dataroot', $base, 'grows'));
        failing_stream::$onread = function (int $given): void {
            file_put_contents($this->base . '/shrinks', str_repeat('s', self::MIB));
            failing_stream::$onread = null;
        };
        $this->assertSame([], $writer->add('dataroot', $base, 'shrinks'));
        $this->assertSame(['dataroot:grows', 'dataroot:shrinks'], $writer->unreadable());
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
        $this->assertSame([], $writer->flush());
    }

    public function test_a_file_of_many_pieces_that_fails_in_a_later_piece_removes_the_pieces_that_were_finished(): void {
        $this->put_big('big', 70 * self::MIB);
        $writer = $this->writer();
        failing_stream::fail(1, 40 * self::MIB);
        $this->assertSame([], $writer->add('dataroot', failing_stream::PROTOCOL . '://' . $this->base, 'big'));
        $this->assertSame(['dataroot:big'], $writer->unreadable());
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
    }

    public function test_a_chunk_that_cannot_be_written_is_temp_write_failed_and_leaves_nothing(): void {
        full_disk_stream::register(300);
        $writer = $this->writer('dataroot', full_disk_stream::PROTOCOL . '://' . $this->dir);
        $this->put('f', str_repeat('SECRETMARKER', 100));
        $writer->add('dataroot', $this->base, 'f');
        try {
            $writer->flush();
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
            $this->assertNotNull($e->debuginfo);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
    }

    public function test_a_small_file_that_fills_the_chunk_and_fails_to_write_is_temp_write_failed(): void {
        full_disk_stream::register(2 * self::MIB);
        $writer = $this->writer('dataroot', full_disk_stream::PROTOCOL . '://' . $this->dir);
        $this->put('f', str_repeat('SECRETMARKER', intdiv(3 * self::MIB, 12)));
        try {
            $writer->add('dataroot', $this->base, 'f');
            $writer->add('dataroot', $this->base, 'f');
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
        }
        $this->assertSame([], $this->files());
    }

    public function test_a_piece_that_cannot_be_written_is_temp_write_failed_and_leaves_nothing(): void {
        full_disk_stream::register(3000);
        $writer = $this->writer('dataroot', full_disk_stream::PROTOCOL . '://' . $this->dir);
        $this->put('small', 'first');
        $this->put('big', str_repeat('SECRETMARKER', intdiv(5 * self::MIB, 12)));
        $writer->add('dataroot', $this->base, 'small');
        try {
            $writer->add('dataroot', $this->base, 'big');
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
        }
        // The chunk of the small file was finished for the big one and is removed too: the caller never got it.
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
        $this->assertSame([], $writer->flush());
    }

    public function test_a_later_piece_that_cannot_be_written_removes_the_pieces_that_were_finished(): void {
        full_disk_stream::register(33 * self::MIB);
        $writer = $this->writer('dataroot', full_disk_stream::PROTOCOL . '://' . $this->dir);
        $this->put('big', str_repeat('SECRETMARKER', intdiv(40 * self::MIB, 12)));
        try {
            $writer->add('dataroot', $this->base, 'big');
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $writer->entries());
    }

    public function test_a_folder_that_does_not_exist_is_temp_write_failed_at_the_first_chunk(): void {
        $writer = $this->writer('dataroot', $this->dir . '/no-such-folder');
        $this->put('f', 'x');
        try {
            $writer->add('dataroot', $this->base, 'f');
            $this->fail('A chunk was started in a folder that does not exist');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([], $writer->entries());
    }

    public function test_a_public_key_that_cannot_encrypt_is_publickey_invalid(): void {
        $writer = new series_writer(self::key(), 'dataroot', self::RUN, str_repeat("\0", 32), self::SITE, $this->dir);
        $this->put('f', 'x');
        try {
            $writer->add('dataroot', $this->base, 'f');
            $this->fail('A chunk was started with a key that cannot encrypt');
        } catch (key_exception $e) {
            $this->assertSame('publickey_invalid', $e->errorcode);
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([], $this->files());
    }

    public function test_the_writer_given_up_removes_the_chunk_that_is_open(): void {
        $this->put('f', 'x');
        $writer = $this->writer();
        $writer->add('dataroot', $this->base, 'f');
        $this->assertNotSame([], $this->files());
        unset($writer);
        $this->assertSame([], $this->files());
    }

    public function test_a_label_or_run_that_a_chunk_cannot_have_is_refused_and_the_trace_has_no_key(): void {
        foreach (['other', 'dataroot'] as $label) {
            self::$wiped = [];
            $run = $label === 'other' ? self::RUN : 'not a uuid';
            try {
                new series_writer(self::key(), $label, $run, sodium_crypto_box_publickey(self::pair()), self::SITE, $this->dir);
                $this->fail('A writer was made with a name that no chunk can have');
            } catch (\coding_exception $e) {
                $this->assertSame(1, substr_count($e->getMessage(), 'Coding error detected'));
                foreach (trace_strings::of($e) as $argument) {
                    $this->assertStringNotContainsString(self::key(), $argument);
                }
                // The constructor did not finish, so the destructor does not run: the constructor wipes the key.
                $this->assertContains(self::key(), self::$wiped);
            }
        }
    }

    public function test_a_key_of_the_wrong_size_is_refused_and_not_left_in_the_trace(): void {
        $key = str_repeat("\x2a", 31);
        self::$wiped = [];
        try {
            new series_writer($key, 'dataroot', self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE, $this->dir);
            $this->fail('A key of 31 bytes was accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('32 bytes', $e->getMessage());
            foreach (trace_strings::of($e) as $argument) {
                $this->assertStringNotContainsString($key, $argument);
            }
            $this->assertNotEmpty($e->getTrace());
        }
        $this->assertContains($key, self::$wiped);
    }

    public function test_the_key_is_wiped_when_the_writer_is_destroyed(): void {
        $writer = $this->writer();
        self::$wiped = [];
        unset($writer);
        $this->assertSame([self::key()], self::$wiped);
    }

    public function test_the_writer_does_not_show_the_key(): void {
        $writer = $this->writer();
        ob_start();
        var_dump($writer);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString(self::key(), $dump);
        $this->assertStringNotContainsString(bin2hex(self::key()), $dump);
        $this->assertStringContainsString(self::RUN, $dump);
    }

    public function test_the_writer_cannot_be_copied_or_serialised(): void {
        $writer = $this->writer();
        self::$wiped = [];
        try {
            $copy = clone $writer;
            $this->fail('A writer was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('cloned', $e->getMessage());
        }
        // The refused copy kept no key: had it kept one, destroying it would have wiped that key (a copy of the same bytes).
        $this->assertSame([], self::$wiped);
        try {
            serialize($writer);
            $this->fail('A writer was serialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('serialised', $e->getMessage());
        }
        try {
            $writer->__sleep();
            $this->fail('A writer was serialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('serialised', $e->getMessage());
        }
        $class = series_writer::class;
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}');
            $this->fail('A writer was built from a string');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('unserialised', $e->getMessage());
        }
        self::$wiped = [];
    }
}
