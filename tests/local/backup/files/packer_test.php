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
 * Tests for the packing of files into chunks, with a stand-in for sodium_memzero() in the namespace of the packer.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup\files;

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\failing_stream;
use tool_secbackup\tests\full_disk_stream;
use tool_secbackup\tests\trace_strings;

defined('MOODLE_INTERNAL') || die();

/**
 * Wipes a buffer as libsodium does, and keeps a copy of it for the tests of packer_test.
 *
 * PHP looks up a function that is called without a namespace in the namespace of the caller first, so while the tests run,
 * the classes of this namespace call this function instead of the one of libsodium. PHPUnit loads this file, which declares
 * it, before any test runs.
 *
 * @package    tool_secbackup
 * @param string $buffer The buffer, which is wiped.
 */
function sodium_memzero(string &$buffer): void {
    packer_test::$wiped[] = $buffer;
    \sodium_memzero($buffer);
}

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\files\packer::class)]
/**
 * Tests for the packing of files into chunks.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\files\packer
 */
final class packer_test extends \advanced_testcase {
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

    /** @var string|false The setting of zend.exception_ignore_args before the test. */
    private string|false $ignoreargs;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->dir = make_request_directory();
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
     * A packer.
     *
     * @param string|null $dir The folder of the chunks; the folder of the test by default.
     * @return packer
     */
    private function packer(?string $dir = null): packer {
        return new packer(self::key(), self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE, $dir ?? $this->dir);
    }

    /**
     * A stream with some text.
     *
     * @param string $content The text.
     * @return resource
     */
    private static function stream(string $content) {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, $content);
        rewind($handle);
        return $handle;
    }

    /**
     * A file of many mebibytes in the temporary folder: a fresh random block for every mebibyte.
     *
     * @param int $bytes Size of the file.
     * @return array [path, SHA-1 in hexadecimal]
     */
    private function big_file(int $bytes): array {
        $path = make_request_directory() . '/big.bin';
        $handle = fopen($path, 'wb');
        $hash = hash_init('sha1');
        for ($done = 0; $done < $bytes; $done += self::MIB) {
            $piece = random_bytes(min(self::MIB, $bytes - $done));
            hash_update($hash, $piece);
            fwrite($handle, $piece);
        }
        fclose($handle);
        return [$path, hash_final($hash)];
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

    public function test_ten_small_files_are_one_chunk(): void {
        $contents = [];
        $packer = $this->packer();
        for ($i = 0; $i < 10; $i++) {
            $contents[$i] = str_repeat(chr(65 + $i), 1024);
            $this->assertSame([], $packer->add(sha1($contents[$i]), self::stream($contents[$i]), 1024));
        }
        $infos = $packer->flush();
        $this->assertCount(1, $infos);
        $info = $infos[0];
        $hashes = array_map('sha1', $contents);
        $this->assertSame(chunk_name::pack(self::key(), $hashes), $info->name);
        $this->assertSame([$info->name], $this->files());
        $this->assertCount(10, $info->items);
        $reader = self::reader($info);
        $this->assertSame(chunk_writer::TYPE_FILES, $reader->type());
        $entries = $packer->entries();
        $this->assertCount(10, $entries);
        foreach ($contents as $i => $content) {
            $this->assertSame($content, $reader->read_item($info->items[$i]['offset']));
            $this->assertSame([
                't' => 'file', 'contenthash' => $hashes[$i], 'size' => 1024, 'chunk' => $info->name,
                'offset' => $info->items[$i]['offset'], 'length' => $info->items[$i]['length'], 'piece' => 0, 'pieces' => 1,
            ], $entries[$i]);
        }
        $this->assertSame([], $packer->flush());
    }

    public function test_nothing_pending_flushes_to_nothing(): void {
        $packer = $this->packer();
        $this->assertSame([], $packer->flush());
        $this->assertSame([], $packer->entries());
        $this->assertSame([], $this->files());
    }

    public function test_a_file_of_4_mib_or_more_is_not_packed_but_refused(): void {
        [$path, $hash] = $this->big_file(4 * self::MIB - 1);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $this->assertSame([], $packer->add($hash, $handle, 4 * self::MIB - 1));
        fclose($handle);
        // A big file goes through big_digests() and big_piece(): add() refuses it before it reads anything.
        [$path, $hash] = $this->big_file(4 * self::MIB);
        $handle = fopen($path, 'rb');
        try {
            $packer->add($hash, $handle, 4 * self::MIB);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('A file of FILE_LIMIT bytes or more is not packed.', $e->getMessage());
        }
        $this->assertSame(0, ftell($handle));
        fclose($handle);
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
        $this->assertCount(1, $packer->flush(), 'The small file still waits for its pack.');
    }

    public function test_the_small_files_are_packed_when_32_mib_are_pending(): void {
        $packer = $this->packer();
        $size = 3 * self::MIB + self::MIB / 2;
        $returned = [];
        for ($i = 0; $i < 11; $i++) {
            $content = str_repeat(chr(65 + $i), $size);
            $infos = $packer->add(sha1($content), self::stream($content), $size);
            $returned[$i] = count($infos);
        }
        // Nine files are 31.5 MiB; the tenth takes the pending bytes over 32 MiB, and the pack is written at once.
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0], $returned);
        $this->assertCount(1, $packer->flush());
        $this->assertCount(2, $this->files());
        $entries = $packer->entries();
        $this->assertCount(11, $entries);
        $this->assertSame(array_fill(0, 10, $entries[0]['chunk']), array_column(array_slice($entries, 0, 10), 'chunk'));
        $this->assertNotSame($entries[0]['chunk'], $entries[10]['chunk']);
    }

    public function test_entries_keep_the_order_the_files_were_added_in(): void {
        $packer = $this->packer();
        $small = 'a small file';
        $packer->add(sha1($small), self::stream($small), strlen($small));
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 5 * self::MIB);
        rewind($handle);
        $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
        fclose($handle);
        // The small file is not in a chunk yet, so it has no entry yet.
        $this->assertSame([$hash], array_column($packer->entries(), 'contenthash'));
        $packer->flush();
        $this->assertSame([sha1($small), $hash], array_column($packer->entries(), 'contenthash'));
    }

    public function test_the_entries_that_are_taken_are_forgotten(): void {
        $packer = $this->packer();
        $taken = [];
        for ($pack = 0; $pack < 3; $pack++) {
            foreach (['first', 'second'] as $name) {
                $content = $name . ' file of pack ' . $pack;
                $packer->add(sha1($content), self::stream($content), strlen($content));
            }
            // The files wait for their pack: nothing is taken.
            $this->assertSame([], $packer->take_entries());
            $packer->flush();
            $entries = $packer->take_entries();
            // Each time only the files of the newest pack, and nothing is kept after they are taken.
            $expected = [sha1('first file of pack ' . $pack), sha1('second file of pack ' . $pack)];
            $this->assertSame($expected, array_column($entries, 'contenthash'));
            $this->assertSame([], $packer->entries());
            $taken[] = $entries[0]['chunk'];
        }
        $this->assertCount(3, array_unique($taken));
    }

    public function test_the_entries_are_taken_up_to_the_first_file_that_waits(): void {
        $packer = $this->packer();
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 5 * self::MIB);
        $small = 'a small file';
        $packer->add(sha1($small), self::stream($small), strlen($small));
        rewind($handle);
        $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
        fclose($handle);
        // The small file waits for its pack and comes first: the piece after it is not taken before it.
        $this->assertSame([], $packer->take_entries());
        $packer->flush();
        $this->assertSame([sha1($small), $hash], array_column($packer->take_entries(), 'contenthash'));
        $this->assertSame([], $packer->take_entries());
    }

    public function test_the_reads_of_files_of_one_mib_or_more_are_measured(): void {
        $ticks = 0.0;
        $speed = new read_speed(static function () use (&$ticks): float {
            $ticks += 0.25;
            return $ticks;
        });
        $packer = new packer(self::key(), self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE, $this->dir, $speed);
        // A file below one MiB is mostly the time of opening it: not measured.
        $small = str_repeat('s', self::MIB - 1);
        $packer->add(sha1($small), self::stream($small), strlen($small));
        $this->assertNull($speed->mbps());
        $one = str_repeat('o', self::MIB);
        $packer->add(sha1($one), self::stream($one), self::MIB);
        // One MiB read between two looks at the clock, a quarter of a second apart.
        $this->assertEqualsWithDelta(4.0, $speed->mbps(), 1e-9);
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $handle = fopen($path, 'rb');
        $packer->big_digests($hash, $handle, 5 * self::MIB);
        fclose($handle);
        // The whole big file is one read: six MiB in all, in a quarter of a second each.
        $this->assertEqualsWithDelta(12.0, $speed->mbps(), 1e-9);
    }

    public function test_a_small_file_with_other_content_is_tamper_detected_and_not_packed(): void {
        $packer = $this->packer();
        $good = 'a good file';
        $packer->add(sha1($good), self::stream($good), strlen($good));
        foreach (
            [
                ['content' => 'the content', 'hash' => sha1('the name'), 'size' => 11],
                ['content' => 'too long!', 'hash' => sha1('too long!'), 'size' => 8],
                ['content' => 'short', 'hash' => sha1('short'), 'size' => 6],
            ] as $case
        ) {
            try {
                $packer->add($case['hash'], self::stream($case['content']), $case['size']);
                $this->fail('A file with other content was accepted');
            } catch (plugin_exception $e) {
                $this->assertSame('tamper_detected', $e->errorcode);
            }
        }
        $infos = $packer->flush();
        $this->assertCount(1, $infos);
        $this->assertSame([sha1($good)], array_column($packer->entries(), 'contenthash'));
        $this->assertSame($good, self::reader($infos[0])->read_item($infos[0]->items[0]['offset']));
        $this->assertCount(1, $infos[0]->items);
    }

    public function test_a_piece_that_ends_before_its_length_is_tamper_detected_and_leaves_nothing(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $content = file_get_contents($path);
        $packer = $this->packer();
        // The first read gave the digest of the whole piece; the stream of the second read ends one byte early.
        $digest = hash('sha256', $content);
        try {
            $packer->big_piece($hash, self::stream(substr($content, 0, -1)), 5 * self::MIB, 0, $digest);
            $this->fail('A piece that ends early was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
    }

    public function test_a_failed_read_of_a_small_file_is_nfs_slow_and_leaves_the_pack_alone(): void {
        $content = str_repeat('x', 5000);
        $path = make_request_directory() . '/f';
        file_put_contents($path, $content);
        $packer = $this->packer();
        $good = 'a good file';
        $packer->add(sha1($good), self::stream($good), strlen($good));
        failing_stream::fail(1, 1000);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $packer->add(sha1($content), $handle, 5000);
            $this->fail('A failed read was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        fclose($handle);
        $this->assertCount(1, $packer->flush());
        $this->assertSame([sha1($good)], array_column($packer->entries(), 'contenthash'));
    }

    public function test_a_failed_read_of_a_piece_is_nfs_slow_and_leaves_nothing(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 5 * self::MIB);
        fclose($handle);
        failing_stream::fail(1, 3 * self::MIB);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
            $this->fail('A failed read was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
        // The piece is written when it is read again.
        $handle = fopen($path, 'rb');
        $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
        fclose($handle);
        $this->assertCount(1, $packer->entries());
    }

    public function test_a_folder_that_cannot_be_written_is_temp_write_failed_for_a_piece_and_a_pack(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer($this->dir . '/no-such-folder');
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 5 * self::MIB);
        rewind($handle);
        try {
            $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
            $this->fail('A chunk was written to a folder that does not exist');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $packer->entries());
        // A small file waits in memory; the pack fails to be written, and the file that was pending is still pending.
        $first = 'first small file';
        $this->assertSame([], $packer->add(sha1($first), self::stream($first), strlen($first)));
        try {
            $packer->flush();
            $this->fail('A chunk was written to a folder that does not exist');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        $this->assertSame([], $packer->entries());
    }

    public function test_a_pack_that_cannot_be_renamed_leaves_nothing_and_stays_pending(): void {
        $packer = $this->packer();
        $content = 'a small file';
        $packer->add(sha1($content), self::stream($content), strlen($content));
        // A folder with the name of the chunk: the part file is written in full, and the rename at the end fails.
        $name = chunk_name::pack(self::key(), [sha1($content)]);
        mkdir($this->dir . '/' . $name);
        try {
            $packer->flush();
            $this->fail('A chunk was written over a folder');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        $this->assertSame([$name], $this->files());
        $this->assertSame([], $packer->entries());
        rmdir($this->dir . '/' . $name);
        $this->assertCount(1, $packer->flush());
        $this->assertCount(1, $packer->entries());
    }

    public function test_a_piece_that_cannot_be_renamed_is_temp_write_failed_and_leaves_nothing(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $name = chunk_name::file(self::key(), $hash, 0);
        mkdir($this->dir . '/' . $name);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 5 * self::MIB);
        rewind($handle);
        try {
            $packer->big_piece($hash, $handle, 5 * self::MIB, 0, $digests[0]);
            $this->fail('A chunk was written over a folder');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([$name], $this->files());
        $this->assertSame([], $packer->entries());
    }

    public function test_a_read_that_fails_after_the_last_byte_is_nfs_slow(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        // The first read of a big file reads one byte more to see the end, and that read fails.
        failing_stream::fail(1, 5 * self::MIB);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $this->packer()->big_digests($hash, $handle, 5 * self::MIB);
            $this->fail('A failed read was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $this->files());
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
        }
        $this->assertNull($exception->getPrevious());
        $this->assertSame([], trace_strings::objects_in_plugin_frames($exception), 'No call of the plugin has an object.');
        $this->assertFalse(
            trace_strings::has_throwable_argument($exception),
            'No call in the trace has the first exception as an argument: its trace holds the content.'
        );
    }

    public function test_a_pack_that_fails_part_way_leaves_no_content_in_the_trace(): void {
        full_disk_stream::register(300);
        $packer = $this->packer(full_disk_stream::PROTOCOL . '://' . $this->dir);
        $content = str_repeat('SECRETMARKER', 100);
        $packer->add(sha1($content), self::stream($content), strlen($content));
        try {
            $packer->flush();
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
            $this->assertNotNull($e->debuginfo);
        }
        $this->assertSame([], $this->files());
    }

    public function test_a_piece_that_fails_part_way_leaves_no_content_in_the_trace(): void {
        full_disk_stream::register(3000);
        $content = str_repeat('SECRETMARKER', intdiv(5 * self::MIB, 12));
        $size = strlen($content);
        $this->assertGreaterThanOrEqual(4 * self::MIB, $size);
        $packer = $this->packer(full_disk_stream::PROTOCOL . '://' . $this->dir);
        $digests = $packer->big_digests(sha1($content), self::stream($content), $size);
        try {
            $packer->big_piece(sha1($content), self::stream($content), $size, 0, $digests[0]);
            $this->fail('A chunk was written to a full disk');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assert_trace_has_no_content($e);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
    }

    public function test_the_pack_that_fails_when_it_fills_up_does_not_keep_the_file_that_filled_it(): void {
        $packer = $this->packer($this->dir . '/no-such-folder');
        $size = 3 * self::MIB + self::MIB / 2;
        for ($i = 0; $i < 9; $i++) {
            $content = str_repeat(chr(65 + $i), $size);
            $packer->add(sha1($content), self::stream($content), $size);
        }
        $content = str_repeat('Z', $size);
        try {
            $packer->add(sha1($content), self::stream($content), $size);
            $this->fail('A chunk was written to a folder that does not exist');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        // The file that failed is not pending: adding it again does not put it in the pack twice.
        mkdir($this->dir . '/no-such-folder');
        $infos = $packer->add(sha1($content), self::stream($content), $size);
        $this->assertCount(1, $infos);
        $this->assertCount(10, $infos[0]->items);
        $this->assertCount(10, $packer->entries());
    }

    public function test_a_content_hash_that_is_not_a_hash_is_refused(): void {
        $this->expectException(\coding_exception::class);
        $this->packer()->add('not a hash', self::stream('x'), 1);
    }

    /**
     * Checks that the trace of an exception holds no argument with the key.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     */
    private function assert_trace_hides_the_key(\Throwable $exception): void {
        $this->assertNotEmpty(
            array_filter($exception->getTrace(), static fn(array $frame): bool => !empty($frame['args'])),
            'The trace keeps the arguments, so the check means something.'
        );
        foreach (trace_strings::of($exception) as $argument) {
            $this->assertStringNotContainsString(self::key(), $argument);
        }
    }

    public function test_a_name_that_a_chunk_cannot_have_is_refused_and_the_trace_has_no_key(): void {
        try {
            $this->packer()->add('not a hash', self::stream('x'), 1);
            $this->fail('A content hash that is not a hash was accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('content hash', $e->getMessage());
            $this->assertSame(1, substr_count($e->getMessage(), 'Coding error detected'));
            $this->assert_trace_hides_the_key($e);
        }
        try {
            $this->packer()->add(strtoupper(sha1('x')), self::stream('x'), 1);
            $this->fail('A content hash in capitals was accepted');
        } catch (\coding_exception $e) {
            $this->assert_trace_hides_the_key($e);
        }
        $this->assertSame([], $this->files());
    }

    public function test_a_key_of_the_wrong_size_is_refused_and_not_left_in_the_trace(): void {
        $key = str_repeat("\x2a", 31);
        self::$wiped = [];
        try {
            new packer($key, self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE, $this->dir);
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

    public function test_the_key_is_wiped_when_the_packer_is_destroyed(): void {
        $packer = $this->packer();
        self::$wiped = [];
        unset($packer);
        $this->assertSame([self::key()], self::$wiped);
    }

    public function test_the_packer_does_not_show_the_key(): void {
        $packer = $this->packer();
        ob_start();
        var_dump($packer);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString(self::key(), $dump);
        $this->assertStringNotContainsString(bin2hex(self::key()), $dump);
        $this->assertStringContainsString(self::RUN, $dump);
    }

    public function test_the_packer_cannot_be_copied_or_serialised(): void {
        $packer = $this->packer();
        self::$wiped = [];
        try {
            $copy = clone $packer;
            $this->fail('A packer was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('cloned', $e->getMessage());
        }
        // The refused copy kept no key: had it kept one, destroying it would have wiped that key (a copy of the same bytes).
        $this->assertSame([], self::$wiped);
        try {
            serialize($packer);
            $this->fail('A packer was serialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('serialised', $e->getMessage());
        }
        try {
            $packer->__sleep();
            $this->fail('A packer was serialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('serialised', $e->getMessage());
        }
        $class = packer::class;
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}');
            $this->fail('A packer was built from a string');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('unserialised', $e->getMessage());
        }
        // The refused copy did not wipe the key of the original.
        self::$wiped = [];
        unset($packer);
        $this->assertSame([self::key()], self::$wiped);
    }

    public function test_a_big_file_is_read_once_for_the_digests_of_its_pieces_without_a_file(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $handle = fopen($path, 'rb');
        $digests = $this->packer()->big_digests($hash, $handle, 70 * self::MIB);
        fclose($handle);
        $content = file_get_contents($path);
        $this->assertSame([
            hash('sha256', substr($content, 0, 32 * self::MIB)),
            hash('sha256', substr($content, 32 * self::MIB, 32 * self::MIB)),
            hash('sha256', substr($content, 64 * self::MIB)),
        ], $digests);
        $this->assertSame([], $this->files());
    }

    public function test_the_first_read_of_a_big_file_checks_its_hash_and_its_length(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer();
        foreach ([[sha1('other'), 5 * self::MIB], [$hash, 5 * self::MIB + 1], [$hash, 5 * self::MIB - 1]] as [$claimed, $size]) {
            $handle = fopen($path, 'rb');
            try {
                $packer->big_digests($claimed, $handle, $size);
                $this->fail('No exception');
            } catch (plugin_exception $e) {
                $this->assertSame('tamper_detected', $e->errorcode);
            } finally {
                fclose($handle);
            }
        }
        failing_stream::fail(1, self::MIB);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $packer->big_digests($hash, $handle, 5 * self::MIB);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        } finally {
            fclose($handle);
        }
        $this->expectException(\coding_exception::class);
        $packer->big_digests($hash, self::stream('small'), 5);
    }

    public function test_the_pieces_of_a_big_file_are_written_one_at_a_time_and_checked_against_the_first_read(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 70 * self::MIB);
        fclose($handle);
        $content = file_get_contents($path);
        foreach ([0, 1, 2] as $piece) {
            $handle = fopen($path, 'rb');
            fseek($handle, $piece * packer::PIECE);
            $info = $packer->big_piece($hash, $handle, 70 * self::MIB, $piece, $digests[$piece]);
            fclose($handle);
            $this->assertSame(chunk_name::file(self::key(), $hash, $piece), $info->name);
            $this->assertSame([$info->name], $this->files(), 'One piece at a time is in the folder.');
            $entry = $packer->entries()[$piece];
            $this->assertSame([
                't' => 'file', 'contenthash' => $hash, 'size' => 70 * self::MIB, 'chunk' => $info->name,
                'offset' => $info->items[0]['offset'], 'length' => $info->items[0]['length'], 'piece' => $piece, 'pieces' => 3,
            ], $entry);
            $expected = substr($content, $piece * packer::PIECE, packer::PIECE);
            $this->assertSame($expected, self::reader($info)->read_item($entry['offset']));
            unlink($info->path);
        }
    }

    public function test_a_piece_that_is_not_the_one_of_the_first_read_is_not_handed_out(): void {
        [$path, $hash] = $this->big_file(40 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 40 * self::MIB);
        fclose($handle);
        // The file changes between the two reads.
        $handle = fopen($path, 'r+b');
        fseek($handle, packer::PIECE + 5);
        fwrite($handle, 'changed');
        fseek($handle, packer::PIECE);
        try {
            $packer->big_piece($hash, $handle, 40 * self::MIB, 1, $digests[1]);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
        } finally {
            fclose($handle);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
    }

    /**
     * The names in the folder of the chunks that look like the name of a chunk.
     *
     * @return string[]
     */
    private function chunk_named_files(): array {
        return array_values(array_filter(
            $this->files(),
            static fn(string $name): bool => preg_match('/^[0-9a-f]{64}\z/', $name) === 1
        ));
    }

    public function test_no_piece_has_the_name_of_its_chunk_before_it_is_checked(): void {
        [$path, $hash] = $this->big_file(40 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $digests = $packer->big_digests($hash, $handle, 40 * self::MIB);
        fclose($handle);
        $seen = null;
        $during = null;
        failing_stream::fail(0);
        failing_stream::$onread = function (int $given) use (&$seen, &$during): void {
            // The folder half way through the reading of the piece.
            if ($given > 16 * self::MIB && $seen === null) {
                $seen = $this->chunk_named_files();
                $during = $this->files();
            }
        };
        // The stand-in does not fail, but its hook is called; the piece is read with the hook in place.
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        $info = $packer->big_piece($hash, $handle, 40 * self::MIB, 0, $digests[0]);
        fclose($handle);
        $this->assertSame([], $seen, 'No file has the name of a chunk while the piece is read.');
        // The piece is written under its provisional name (and the writer's own name for a file that it is still writing).
        $this->assertCount(1, $during);
        $this->assertStringStartsWith($info->name . packer::UNVERIFIED, $during[0]);
        $this->assertSame([$info->name], $this->files(), 'Only the piece with the name of its chunk is left.');
    }

    public function test_a_piece_out_of_the_file_is_refused(): void {
        $this->expectException(\coding_exception::class);
        $this->packer()->big_piece(sha1('x'), self::stream('x'), 5 * self::MIB, 1, hash('sha256', 'x'));
    }
}
