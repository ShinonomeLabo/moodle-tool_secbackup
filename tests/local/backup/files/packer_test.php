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

    public function test_a_file_of_5_mib_is_one_piece_at_once(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $infos = $packer->add($hash, $handle, 5 * self::MIB);
        fclose($handle);
        $this->assertCount(1, $infos);
        $this->assertSame(chunk_name::file(self::key(), $hash, 0), $infos[0]->name);
        $this->assertSame([$infos[0]->name], $this->files());
        $this->assertSame([
            ['t' => 'file', 'contenthash' => $hash, 'size' => 5 * self::MIB, 'chunk' => $infos[0]->name,
                'offset' => $infos[0]->items[0]['offset'], 'length' => $infos[0]->items[0]['length'], 'piece' => 0,
                'pieces' => 1],
        ], $packer->entries());
        // The entry is enough to read the piece back.
        $entry = $packer->entries()[0];
        $this->assertSame(file_get_contents($path), self::reader($infos[0])->read_item($entry['offset']));
        $this->assertSame([], $packer->flush());
    }

    public function test_the_limit_of_a_big_file_is_4_mib_of_the_size_in_the_table(): void {
        [$path, $hash] = $this->big_file(4 * self::MIB - 1);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $this->assertSame([], $packer->add($hash, $handle, 4 * self::MIB - 1));
        fclose($handle);
        [$path, $hash] = $this->big_file(4 * self::MIB);
        $handle = fopen($path, 'rb');
        $this->assertCount(1, $packer->add($hash, $handle, 4 * self::MIB));
        fclose($handle);
    }

    public function test_a_file_of_70_mib_is_three_pieces(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $packer = $this->packer();
        $handle = fopen($path, 'rb');
        $infos = $packer->add($hash, $handle, 70 * self::MIB);
        fclose($handle);
        $this->assertCount(3, $infos);
        $sizes = [32 * self::MIB, 32 * self::MIB, 6 * self::MIB];
        $whole = hash_init('sha1');
        $digests = [];
        $entries = $packer->entries();
        $this->assertCount(3, $entries);
        foreach ($infos as $i => $info) {
            $this->assertSame(chunk_name::file(self::key(), $hash, $i), $info->name);
            $this->assertSame([
                't' => 'file', 'contenthash' => $hash, 'size' => 70 * self::MIB, 'chunk' => $info->name,
                'offset' => $info->items[0]['offset'], 'length' => $info->items[0]['length'], 'piece' => $i, 'pieces' => 3,
            ], $entries[$i]);
            // Every piece is read back through its entry, and the pieces are not alike.
            $plain = self::reader($info)->read_item($entries[$i]['offset']);
            $this->assertSame($sizes[$i], strlen($plain));
            $digests[$i] = md5($plain);
            hash_update($whole, $plain);
        }
        $this->assertCount(3, array_unique($digests));
        $this->assertSame($hash, hash_final($whole));
        $this->assertCount(3, $this->files());
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
        $packer->add($hash, $handle, 5 * self::MIB);
        fclose($handle);
        // The small file is not in a chunk yet, so it has no entry yet.
        $this->assertSame([$hash], array_column($packer->entries(), 'contenthash'));
        $packer->flush();
        $this->assertSame([sha1($small), $hash], array_column($packer->entries(), 'contenthash'));
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

    public function test_a_big_file_with_other_content_is_tamper_detected_and_nothing_is_left(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer();
        // The same length, another content: the name is the hash of the original.
        $other = self::stream(random_bytes(5 * self::MIB));
        try {
            $packer->add($hash, $other, 5 * self::MIB);
            $this->fail('A big file with other content was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
        $this->assertSame([], $packer->flush());
    }

    public function test_a_change_in_the_last_piece_removes_the_pieces_that_were_finished(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $handle = fopen($path, 'r+b');
        fseek($handle, -1, SEEK_END);
        $last = ord(fread($handle, 1));
        fseek($handle, -1, SEEK_END);
        fwrite($handle, chr(($last + 1) % 256));
        rewind($handle);
        $packer = $this->packer();
        try {
            $packer->add($hash, $handle, 70 * self::MIB);
            $this->fail('A changed file was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
    }

    public function test_a_big_file_with_more_or_less_than_its_size_is_tamper_detected(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $content = file_get_contents($path);
        foreach ([$content . 'x', substr($content, 0, -1)] as $other) {
            try {
                $this->packer()->add($hash, self::stream($other), 5 * self::MIB);
                $this->fail('A big file of another length was accepted');
            } catch (plugin_exception $e) {
                $this->assertSame('tamper_detected', $e->errorcode);
            }
            $this->assertSame([], $this->files());
        }
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

    public function test_a_failed_read_of_a_big_file_is_nfs_slow_and_leaves_nothing(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $packer = $this->packer();
        // The first piece is written, the second fails.
        failing_stream::fail(1, 40 * self::MIB);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $packer->add($hash, $handle, 70 * self::MIB);
            $this->fail('A failed read was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $this->files());
        $this->assertSame([], $packer->entries());
        // The same file is packed from its start when it is read again.
        $handle = fopen($path, 'rb');
        $this->assertCount(3, $packer->add($hash, $handle, 70 * self::MIB));
        fclose($handle);
        $this->assertCount(3, $packer->entries());
    }

    public function test_a_folder_that_cannot_be_written_is_temp_write_failed_for_a_big_file_and_a_pack(): void {
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $packer = $this->packer($this->dir . '/no-such-folder');
        $handle = fopen($path, 'rb');
        try {
            $packer->add($hash, $handle, 5 * self::MIB);
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
        $handle = fopen($path, 'rb');
        $packer = $this->packer();
        try {
            $packer->add($hash, $handle, 5 * self::MIB);
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
        failing_stream::fail(1, 5 * self::MIB);
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        try {
            $this->packer()->add($hash, $handle, 5 * self::MIB);
            $this->fail('A failed read was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        fclose($handle);
        $this->assertSame([], $this->files());
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

    public function test_no_piece_has_its_final_name_before_the_whole_file_is_checked(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $seen = [];
        failing_stream::fail(0);
        failing_stream::$onread = function (int $given) use (&$seen): void {
            // Snapshot of the folder when the reading of the last piece is under way.
            if ($given > 40 * self::MIB && $seen === []) {
                $seen = $this->chunk_named_files();
            }
        };
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        // The stand-in does not fail, but its hook is called; the file is read with the hook in place.
        $infos = $this->packer()->add($hash, $handle, 70 * self::MIB);
        fclose($handle);
        $this->assertSame([], $seen, 'No file with the name of a chunk exists while the file is still being read.');
        $names = array_map(static fn(chunk_info $info): string => $info->name, $infos);
        sort($names);
        $this->assertSame($names, $this->chunk_named_files());
        foreach ($infos as $info) {
            $this->assertSame($this->dir . '/' . $info->name, $info->path);
            $this->assertFileExists($info->path);
        }
        $this->assertSame(3, count($this->files()), 'Nothing but the three chunks is left.');
    }

    public function test_a_failed_check_leaves_no_file_with_the_name_of_a_chunk(): void {
        [$path, $hash] = $this->big_file(70 * self::MIB);
        $handle = fopen($path, 'r+b');
        fseek($handle, 5);
        fwrite($handle, 'X');
        rewind($handle);
        try {
            $this->packer()->add($hash, $handle, 70 * self::MIB);
            $this->fail('A changed file was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
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
        try {
            $packer->add(sha1($content), self::stream($content), $size);
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
        [$path, $hash] = $this->big_file(5 * self::MIB);
        $handle = fopen($path, 'rb');
        try {
            $this->packer()->add(strtoupper($hash), $handle, 5 * self::MIB);
            $this->fail('A content hash in capitals was accepted');
        } catch (\coding_exception $e) {
            $this->assert_trace_hides_the_key($e);
        }
        fclose($handle);
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
}
