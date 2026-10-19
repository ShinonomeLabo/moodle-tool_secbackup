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

namespace tool_secbackup\local\backup\files;

use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\failing_stream;
use tool_secbackup\tests\flaky_file_source;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\files\file_source::class)]
/**
 * Tests for the list of the files of the file store and the opening of them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\files\file_source
 */
final class file_source_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string UUID of the run of the tests. */
    private const RUN = '6f1d0c52-97d8-4f1e-8d3b-0a5e2c7b9e11';

    /** @var int[] Seconds that the stand-in for sleep() was given. */
    private array $slept = [];

    /** @var int Number for the item id of the next file. */
    private int $next = 1;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        failing_stream::register();
    }

    protected function tearDown(): void {
        failing_stream::unregister();
        parent::tearDown();
    }

    /**
     * A source whose sleep() only counts.
     *
     * @return file_source
     */
    private function source(): file_source {
        global $DB;
        return new file_source($DB, function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    /**
     * Makes a stored file.
     *
     * @param string $content The content.
     * @param string $name The name.
     * @return \stored_file
     */
    private function make(string $content, string $name = 'f.bin'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'tool_secbackup', 'filearea' => 'test',
            'itemid' => $this->next++, 'filepath' => '/', 'filename' => $name,
        ], $content);
    }

    /**
     * Path of the content of a file in the file directory.
     *
     * @param string $hash The content hash.
     * @return string
     */
    private static function filedir_path(string $hash): string {
        global $CFG;
        return $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
    }

    /**
     * A packer for the tests.
     *
     * @param string $dir The folder of the chunks.
     * @return packer
     */
    private function packer(string $dir): packer {
        $public = sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x07", 32)));
        return new packer(str_repeat("\x11", 32), self::RUN, $public, self::SITE, $dir);
    }

    public function test_equal_content_is_listed_once_and_empty_files_not_at_all(): void {
        $one = $this->make('same content', 'a.txt');
        $two = $this->make('same content', 'b.txt');
        $three = $this->make('other content here', 'c.txt');
        $this->make('', 'empty.txt');
        get_file_storage()->create_directory(\context_system::instance()->id, 'tool_secbackup', 'test', 99, '/dir/');
        $this->assertSame($one->get_contenthash(), $two->get_contenthash());
        $list = $this->source()->next(null, 1000);
        $hashes = array_column($list, 'contenthash');
        $this->assertSame(array_unique($hashes), $hashes);
        $sorted = $hashes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $hashes);
        $this->assertNotContains(sha1(''), $hashes);
        $mine = array_column($list, 'size', 'contenthash');
        $this->assertSame(12, $mine[$one->get_contenthash()]);
        $this->assertSame(18, $mine[$three->get_contenthash()]);
        foreach ($list as $entry) {
            $this->assertIsInt($entry['size']);
            $this->assertGreaterThan(0, $entry['size']);
        }
    }

    public function test_the_list_goes_on_after_a_hash(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->make('content number ' . $i);
        }
        $all = $this->source()->next(null, 1000);
        $this->assertGreaterThanOrEqual(5, count($all));
        $paged = [];
        $after = null;
        while (($page = $this->source()->next($after, 2)) !== []) {
            $this->assertLessThanOrEqual(2, count($page));
            $paged = array_merge($paged, $page);
            $after = end($page)['contenthash'];
        }
        $this->assertSame($all, $paged);
        $this->assertSame(array_slice($all, 1), $this->source()->next($all[0]['contenthash'], 1000));
        $this->assertSame([], $this->source()->next(end($all)['contenthash'], 10));
    }

    public function test_a_file_in_the_file_directory_is_opened_from_there(): void {
        $file = $this->make('hello file store');
        $opened = $this->source()->open($file->get_contenthash());
        $this->assertSame('filedir', $opened['where']);
        $this->assertSame('hello file store', stream_get_contents($opened['handle']));
        fclose($opened['handle']);
    }

    public function test_a_file_in_the_trash_is_opened_from_the_trash_and_left_there(): void {
        global $CFG;
        $file = $this->make('content in the trash');
        $hash = $file->get_contenthash();
        $trash = $CFG->dataroot . '/trashdir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        mkdir($trash, 0777, true);
        rename(self::filedir_path($hash), $trash . '/' . $hash);
        $opened = $this->source()->open($hash);
        $this->assertSame('trashdir', $opened['where']);
        $this->assertSame('content in the trash', stream_get_contents($opened['handle']));
        fclose($opened['handle']);
        // Opening does not recover the file into the file directory: the site's file store is not written.
        $this->assertFileDoesNotExist(self::filedir_path($hash));
        $this->assertFileExists($trash . '/' . $hash);
    }

    public function test_the_trash_may_hold_the_file_in_its_root_or_be_set_elsewhere(): void {
        global $CFG;
        $file = $this->make('content in the root of the trash');
        $hash = $file->get_contenthash();
        $CFG->trashdir = make_request_directory();
        rename(self::filedir_path($hash), $CFG->trashdir . '/' . $hash);
        $opened = $this->source()->open($hash);
        $this->assertSame('trashdir', $opened['where']);
        $this->assertSame('content in the root of the trash', stream_get_contents($opened['handle']));
        fclose($opened['handle']);
    }

    public function test_a_file_that_is_nowhere_is_null(): void {
        $file = $this->make('content that goes away');
        unlink(self::filedir_path($file->get_contenthash()));
        $this->assertNull($this->source()->open($file->get_contenthash()));
        $this->assertNull($this->source()->open(sha1('no such file')));
        $this->assertNull($this->source()->open(sha1('')));
    }

    public function test_pack_gives_the_chunks_and_closes_the_file(): void {
        global $DB;
        $dir = make_request_directory();
        $content = str_repeat('0123456789', 100);
        $file = $this->make($content);
        $source = new flaky_file_source($DB, fn(int $seconds) => $this->slept[] = $seconds);
        $packer = $this->packer($dir);
        $this->assertSame([], $source->pack($file->get_contenthash(), 1000, $packer));
        $infos = $packer->flush();
        $this->assertCount(1, $infos);
        $reader = new chunk_reader(
            $infos[0]->path,
            sodium_crypto_box_secretkey(sodium_crypto_box_seed_keypair(str_repeat("\x07", 32))),
            sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x07", 32))),
            self::SITE,
            $infos[0]->name
        );
        $this->assertSame(chunk_writer::TYPE_FILES, $reader->type());
        $this->assertSame($content, $reader->read_item($infos[0]->items[0]['offset']));
        $this->assertSame([], $this->slept);
        $this->assertCount(1, $source->handles);
        $this->assertFalse(is_resource($source->handles[0]), 'The handle is closed');
    }

    public function test_pack_of_a_file_that_is_nowhere_is_null(): void {
        $dir = make_request_directory();
        $file = $this->make('content that is gone');
        unlink(self::filedir_path($file->get_contenthash()));
        $packer = $this->packer($dir);
        $this->assertNull($this->source()->pack($file->get_contenthash(), 20, $packer));
        $this->assertSame([], $packer->entries());
    }

    public function test_a_read_that_fails_twice_is_read_again_after_one_and_five_seconds(): void {
        global $DB;
        $dir = make_request_directory();
        $file = $this->make(str_repeat('abcdefghij', 100));
        $source = new flaky_file_source($DB, fn(int $seconds) => $this->slept[] = $seconds);
        failing_stream::fail(2, 300);
        $packer = $this->packer($dir);
        $this->assertSame([], $source->pack($file->get_contenthash(), 1000, $packer));
        $this->assertSame([1, 5], $this->slept);
        $this->assertCount(3, $source->handles);
        foreach ($source->handles as $handle) {
            $this->assertFalse(is_resource($handle));
        }
        $this->assertCount(1, $packer->flush());
        $this->assertCount(1, $packer->entries());
    }

    public function test_four_failures_in_a_row_are_nfs_slow_and_leave_nothing(): void {
        global $DB;
        $dir = make_request_directory();
        $file = $this->make(str_repeat('abcdefghij', 100));
        $source = new flaky_file_source($DB, fn(int $seconds) => $this->slept[] = $seconds);
        failing_stream::fail(4, 0);
        $packer = $this->packer($dir);
        try {
            $source->pack($file->get_contenthash(), 1000, $packer);
            $this->fail('A file that cannot be read was packed');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        $this->assertSame([1, 5, 15], $this->slept);
        $this->assertSame([], $packer->entries());
        $this->assertSame([], $packer->flush());
        $this->assertSame([], array_values(array_diff(scandir($dir), ['.', '..'])));
        foreach ($source->handles as $handle) {
            $this->assertFalse(is_resource($handle));
        }
    }

    public function test_a_file_that_opens_only_at_the_third_try_is_read(): void {
        global $DB;
        $dir = make_request_directory();
        $file = $this->make('content of a file that opens late');
        $source = new flaky_file_source($DB, fn(int $seconds) => $this->slept[] = $seconds);
        $source->openfailures = 2;
        $packer = $this->packer($dir);
        $source->pack($file->get_contenthash(), 33, $packer);
        $this->assertSame([1, 5], $this->slept);
        $packer->flush();
        $this->assertCount(1, $packer->entries());
        $source->openfailures = 4;
        $this->slept = [];
        try {
            $source->pack($file->get_contenthash(), 33, $this->packer($dir));
            $this->fail('A file that does not open was packed');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        $this->assertSame([1, 5, 15], $this->slept);
    }

    public function test_a_changed_file_is_not_read_again(): void {
        $dir = make_request_directory();
        $file = $this->make(str_repeat('abcdefghij', 100));
        file_put_contents(self::filedir_path($file->get_contenthash()), str_repeat('ABCDEFGHIJ', 100));
        $packer = $this->packer($dir);
        try {
            $this->source()->pack($file->get_contenthash(), 1000, $packer);
            $this->fail('A changed file was packed');
        } catch (plugin_exception $e) {
            $this->assertSame('tamper_detected', $e->errorcode);
        }
        $this->assertSame([], $this->slept);
        $this->assertSame([], $packer->entries());
        $this->assertSame([], array_values(array_diff(scandir($dir), ['.', '..'])));
    }

    public function test_a_file_in_the_trash_that_does_not_open_is_nfs_slow_and_read_again(): void {
        global $DB;
        global $CFG;
        $dir = make_request_directory();
        $file = $this->make('content in the trash that opens late');
        $hash = $file->get_contenthash();
        $CFG->trashdir = make_request_directory();
        rename(self::filedir_path($hash), $CFG->trashdir . '/' . $hash);
        $source = new flaky_file_source($DB, fn(int $seconds) => $this->slept[] = $seconds);
        $source->openfailures = 1;
        try {
            $source->open($hash);
            $this->fail('A file that does not open was opened');
        } catch (plugin_exception $e) {
            $this->assertSame('nfs_slow', $e->errorcode);
        }
        $source->openfailures = 1;
        $packer = $this->packer($dir);
        $source->pack($hash, 36, $packer);
        $this->assertSame([1], $this->slept);
        $this->assertCount(1, $packer->flush());
    }

    public function test_a_file_whose_row_is_gone_since_the_snapshot_is_read_from_the_trash(): void {
        global $CFG, $DB;
        $file = $this->make('content deleted after the snapshot');
        $hash = $file->get_contenthash();
        $trash = $CFG->dataroot . '/trashdir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        mkdir($trash, 0777, true);
        rename(self::filedir_path($hash), $trash . '/' . $hash);
        $DB->delete_records('files', ['contenthash' => $hash]);
        $opened = $this->source()->open($hash);
        $this->assertSame('trashdir', $opened['where']);
        $this->assertSame('content deleted after the snapshot', stream_get_contents($opened['handle']));
        fclose($opened['handle']);
    }
}
