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

use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\faulty_stream;
use tool_secbackup\tests\trace_strings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\manifest_builder::class)]
/**
 * Tests for the list of generations that a run writes entry by entry.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\manifest_builder
 */
final class manifest_builder_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Run ID of the tests. */
    private const RUN = '22222222-2222-4222-8222-222222222222';

    /** @var string|false The value of zend.exception_ignore_args before the test. */
    private $ignoreargs;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ignoreargs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreargs);
        faulty_stream::unregister();
        parent::tearDown();
    }

    /**
     * A builder whose folder is reached through the wrapper that makes faults, with lines in it.
     *
     * @param int $lines Lines appended and committed.
     * @return array [builder, the real folder]
     */
    private function faulty_builder(int $lines): array {
        faulty_stream::register();
        $folder = make_request_directory();
        $builder = new manifest_builder(faulty_stream::url($folder));
        for ($n = 1; $n <= $lines; $n++) {
            $builder->append(['n' => $n]);
        }
        $builder->commit();
        return [$builder, $folder];
    }

    /**
     * Runs a call that must fail with temp_write_failed.
     *
     * @param callable $call The call.
     * @param string $info The debug information that the failure has.
     */
    private function assert_write_failure(callable $call, string $info): void {
        try {
            $call();
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assertSame($info, $e->debuginfo);
        }
        $this->assertTrue(faulty_stream::used(), 'The fault was met.');
    }

    /**
     * Builds the chunk of a builder.
     *
     * @param manifest_builder $builder The builder.
     * @return \tool_secbackup\local\crypto\chunk_info
     */
    private static function build(manifest_builder $builder): \tool_secbackup\local\crypto\chunk_info {
        return $builder->build(['v' => 1], self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
    }

    /**
     * The key pair that encrypts the chunks of the tests.
     *
     * @return string
     */
    private static function pair(): string {
        return sodium_crypto_box_seed_keypair(str_repeat("\x09", 32));
    }

    /**
     * Opens the chunk that build() wrote and gives the lines of the list.
     *
     * @param \tool_secbackup\local\crypto\chunk_info $chunk The chunk.
     * @return string[] The lines, without their line feeds.
     */
    private static function lines(\tool_secbackup\local\crypto\chunk_info $chunk): array {
        $reader = new chunk_reader(
            $chunk->path,
            sodium_crypto_box_secretkey(self::pair()),
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $chunk->name
        );
        self::assertSame(chunk_writer::TYPE_MANIFEST, $reader->type());
        // The name is made without the key for names: a restore opens the manifest with the site's secret key alone.
        self::assertSame(chunk_name::manifest(self::RUN), $chunk->name);
        $text = gzdecode($reader->read_item($chunk->items[0]['offset']));
        self::assertSame("\n", substr($text, -1));
        return explode("\n", substr($text, 0, -1));
    }

    /**
     * A builder in a new folder.
     *
     * @return array [builder, folder]
     */
    private function builder(): array {
        $folder = make_request_directory();
        return [new manifest_builder($folder), $folder];
    }

    public function test_the_list_is_a_header_the_entries_and_an_end_line(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['t' => 'db', 'chunk' => 'a']);
        $builder->append(['t' => 'file', 'path' => 'a/b.txt', 'note' => "line\nfeed \u{3042}"]);
        $this->assertSame(2, $builder->commit());
        $header = ['v' => 1, 'site_id' => self::SITE, 'run_id' => self::RUN, 'kind' => 'full'];
        $chunk = $builder->build($header, self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        $lines = self::lines($chunk);
        $this->assertCount(4, $lines);
        $this->assertSame($header, json_decode($lines[0], true));
        $this->assertSame(['t' => 'db', 'chunk' => 'a'], json_decode($lines[1], true));
        $this->assertSame("line\nfeed \u{3042}", json_decode($lines[2], true)['note']);
        $this->assertSame('{"end":true,"entries":2}', $lines[3]);
        // The chunk is kept in the folder of the run, and the material of the list stays until the run ends.
        $this->assertSame($folder, dirname($chunk->path));
        $this->assertFileExists($folder . '/entries.jsonl');
        $this->assertSame([], glob($folder . '/*.gz'), 'The compressed copy is removed.');
    }

    public function test_a_list_without_entries_has_a_header_and_an_end_line(): void {
        [$builder] = $this->builder();
        $chunk = $builder->build(['v' => 1], self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        $this->assertSame(['{"v":1}', '{"end":true,"entries":0}'], self::lines($chunk));
    }

    public function test_commit_counts_the_complete_lines_and_truncate_drops_the_rest(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->append(['n' => 2]);
        $this->assertSame(2, $builder->commit());
        $builder->append(['n' => 3]);
        // A run that stopped here: the third line is on the disk but was never committed, and a half line follows it.
        file_put_contents($folder . '/entries.jsonl', '{"n":4,"half', FILE_APPEND);
        $resumed = new manifest_builder($folder);
        $resumed->truncate_to(2);
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n", file_get_contents($folder . '/entries.jsonl'));
        $resumed->append(['n' => 5]);
        $this->assertSame(3, $resumed->commit());
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n{\"n\":5}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_builder_restored_from_the_committed_figures_cuts_without_reading(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->append(['n' => 2]);
        $this->assertSame(2, $builder->commit());
        $this->assertSame(16, $builder->bytes());
        $builder->append(['n' => 3]);
        $builder->commit();
        file_put_contents($folder . '/entries.jsonl', '{"n":4,"half', FILE_APPEND);
        $restored = new manifest_builder($folder, 2, 16);
        $this->assertSame(2, $restored->commit());
        $this->assertSame(16, $restored->bytes());
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n", file_get_contents($folder . '/entries.jsonl'));
        $restored->append(['n' => 5]);
        $this->assertSame(3, $restored->commit());
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n{\"n\":5}\n", file_get_contents($folder . '/entries.jsonl'));
        // The figures are trusted as the journals' are: the lines before them are not read, not even counted.
        file_put_contents($folder . '/entries.jsonl', 'not lines at all');
        $this->assertSame(1, (new manifest_builder($folder, 1, 3))->commit());
        $this->assertSame('not', file_get_contents($folder . '/entries.jsonl'));
        // Nothing committed: whatever a stopped step left is dropped, and a missing file is an empty list.
        $this->assertSame(0, (new manifest_builder($folder, 0, 0))->commit());
        $this->assertSame('', file_get_contents($folder . '/entries.jsonl'));
        $this->assertSame(0, (new manifest_builder(make_request_directory(), 0, 0))->commit());
    }

    public function test_a_builder_shorter_than_its_committed_figures_is_refused(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->commit();
        foreach ([[$folder, 2, 50], [make_request_directory(), 1, 8]] as [$dir, $lines, $bytes]) {
            try {
                new manifest_builder($dir, $lines, $bytes);
                $this->fail('No exception');
            } catch (plugin_exception $e) {
                $this->assertSame('temp_write_failed', $e->errorcode);
                $this->assertSame('entries_short', $e->debuginfo);
            }
        }
        $this->assertSame("{\"n\":1}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_file_that_holds_fewer_lines_than_were_committed_is_a_failure(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->commit();
        try {
            (new manifest_builder($folder))->truncate_to(2);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
        }
        $this->assertSame("{\"n\":1}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_truncating_a_folder_without_the_file_to_zero_is_fine_and_to_more_is_a_failure(): void {
        [$builder] = $this->builder();
        $builder->truncate_to(0);
        $this->assertSame(0, $builder->commit());
        $this->expectException(plugin_exception::class);
        $builder->truncate_to(1);
    }

    public function test_a_builder_that_is_opened_again_counts_what_the_file_holds(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->append(['n' => 2]);
        $builder->commit();
        $again = new manifest_builder($folder);
        $this->assertSame(2, $again->commit());
    }

    /**
     * Entries that cannot be one line of the list.
     *
     * @return array
     */
    public static function bad_entry_provider(): array {
        return [
            'a list' => [[1, 2]],
            'text that is not UTF-8' => [['path' => "\xff\xfe"]],
            'a number that has no JSON form' => [['n' => INF]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_entry_provider')]
    /**
     * An entry that cannot be written as one line is a coding error, and nothing is written.
     *
     * @dataProvider bad_entry_provider
     * @param array $entry The entry.
     */
    public function test_an_entry_that_is_not_a_json_object_is_a_coding_error(array $entry): void {
        [$builder, $folder] = $this->builder();
        try {
            $builder->append($entry);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertSame(0, $builder->commit());
        }
        $this->assertSame('', (string) @file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_header_that_cannot_be_written_is_a_coding_error(): void {
        [$builder] = $this->builder();
        $this->expectException(\coding_exception::class);
        $builder->build(['x' => INF], self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
    }

    public function test_a_large_list_is_written_without_holding_it_in_memory(): void {
        [$builder] = $this->builder();
        $line = ['t' => 'file', 'path' => str_repeat('p', 900)];
        for ($i = 0; $i < 3000; $i++) {
            $builder->append($line + ['n' => $i]);
        }
        $this->assertSame(3000, $builder->commit());
        // The peak can be reset from PHP 8.2 on; before it the peak holds what earlier tests used, and the bound is left out.
        $resettable = function_exists('memory_reset_peak_usage');
        if ($resettable) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage();
        $chunk = $builder->build(['v' => 1], self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        // The list is 2.8 MB of text: it is read and compressed in pieces.
        if ($resettable) {
            $this->assertLessThan(2000000, memory_get_peak_usage() - $before);
        }
        $this->assertGreaterThan(0, $chunk->size);
        $reader = new chunk_reader(
            $chunk->path,
            sodium_crypto_box_secretkey(self::pair()),
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $chunk->name
        );
        $text = gzdecode($reader->read_item($chunk->items[0]['offset']));
        $this->assertSame(3002, substr_count($text, "\n"));
    }

    public function test_a_folder_that_cannot_be_written_is_a_failure_without_a_path_in_the_message(): void {
        $builder = new manifest_builder(make_request_directory() . '/missing');
        try {
            $builder->append(['n' => 1]);
            $builder->commit();
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('temp_write_failed', $e->errorcode);
            $this->assertStringNotContainsString('missing', (string) $e->debuginfo);
        }
    }

    public function test_a_builder_over_a_file_with_a_cut_line_drops_the_cut_line_itself(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->commit();
        file_put_contents($folder . '/entries.jsonl', '{"n":2,"ha', FILE_APPEND);
        $again = new manifest_builder($folder);
        $this->assertSame(1, $again->commit());
        $this->assertSame("{\"n\":1}\n", file_get_contents($folder . '/entries.jsonl'));
        $again->append(['n' => 3]);
        $this->assertSame(2, $again->commit());
        $this->assertSame("{\"n\":1}\n{\"n\":3}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_run_that_is_not_a_uuid_is_refused_before_anything_is_written(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        try {
            $builder->build(['v' => 1], 'not-a-uuid', sodium_crypto_box_publickey(self::pair()), self::SITE);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('run UUID', $e->getMessage());
        }
        $this->assertSame([], glob($folder . '/manifest*'));
    }

    /**
     * Site public keys that the writer of the chunk refuses, and what build() throws for each.
     *
     * @return array
     */
    public static function refused_public_key_provider(): array {
        return [
            'a key of the wrong length' => ['short', \coding_exception::class, null],
            'a key that cannot encrypt' => [str_repeat("\0", 32), key_exception::class, 'publickey_invalid'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_public_key_provider')]
    /**
     * A chunk that cannot be written is a failure made again without the trace of the writer, and leaves no compressed copy.
     *
     * @dataProvider refused_public_key_provider
     * @param string $publickey The site public key.
     * @param string $class The class of the exception.
     * @param string|null $errorcode Its error code, for an exception of the plugin.
     */
    public function test_a_chunk_that_cannot_be_written_is_remade_and_leaves_no_copy(
        string $publickey,
        string $class,
        ?string $errorcode
    ): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['t' => 'file', 'path' => 'marker-of-the-entries']);
        try {
            $builder->build(['v' => 1], self::RUN, $publickey, self::SITE);
            $this->fail('No exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            if ($errorcode !== null) {
                $this->assertSame($errorcode, $e->errorcode);
            } else {
                // Made again: it names the class of the writer's exception, not its message.
                $this->assertStringContainsString('The chunk could not be written: ', $e->getMessage());
                $this->assertStringNotContainsString('32 bytes', $e->getMessage());
            }
            $this->assertNull($e->getPrevious());
            // Nothing of what the writer read is in the trace of the new exception.
            foreach (trace_strings::of($e) as $string) {
                $this->assertStringNotContainsString('marker-of-the-entries', $string);
            }
            $this->assertSame([], trace_strings::objects_in_plugin_frames($e));
        }
        $this->assertSame([], glob($folder . '/manifest*'), 'Neither the compressed copy nor a part of the chunk is left.');
        $this->assertFileExists($folder . '/entries.jsonl');
    }

    public function test_a_line_that_cannot_be_written_is_a_failure_and_is_not_counted(): void {
        [$builder, $folder] = $this->faulty_builder(1);
        faulty_stream::fail('write', '/entries.jsonl');
        $this->assert_write_failure(fn() => $builder->append(['n' => 2]), 'entries_write');
        $this->assertSame(1, $builder->commit());
        $this->assertSame("{\"n\":1}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_lines_that_cannot_be_made_safe_on_the_disk_are_a_failure(): void {
        [$builder] = $this->faulty_builder(1);
        $builder->append(['n' => 2]);
        faulty_stream::fail('flush', '/entries.jsonl');
        $this->assert_write_failure(fn() => $builder->commit(), 'entries_flush');
    }

    /**
     * The faults of a file that cannot be cut.
     *
     * @return array
     */
    public static function cut_fault_provider(): array {
        return ['the file does not open' => ['open:r+b'], 'the file does not get shorter' => ['truncate']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cut_fault_provider')]
    /**
     * A file that cannot be cut back keeps every line, and the failure says so.
     *
     * @dataProvider cut_fault_provider
     * @param string $fault The fault.
     */
    public function test_lines_that_cannot_be_dropped_are_a_failure_and_stay(string $fault): void {
        [$builder, $folder] = $this->faulty_builder(2);
        $builder->append(['n' => 3]);
        faulty_stream::fail($fault, '/entries.jsonl');
        $this->assert_write_failure(fn() => $builder->truncate_to(1), 'entries_truncate');
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n{\"n\":3}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_file_that_cannot_be_read_again_for_its_lines_is_a_failure(): void {
        [$builder, $folder] = $this->faulty_builder(2);
        faulty_stream::fail('open:rb', '/entries.jsonl');
        $this->assert_write_failure(fn() => $builder->truncate_to(1), 'entries_short');
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_a_cut_line_that_cannot_be_dropped_when_a_builder_opens_is_a_failure(): void {
        faulty_stream::register();
        $folder = make_request_directory();
        file_put_contents($folder . '/entries.jsonl', "{\"n\":1}\n{\"n\":");
        faulty_stream::fail('truncate', '/entries.jsonl');
        $this->assert_write_failure(fn() => new manifest_builder(faulty_stream::url($folder)), 'entries_truncate');
    }

    public function test_lines_after_the_committed_figures_that_cannot_be_dropped_are_a_failure(): void {
        faulty_stream::register();
        $folder = make_request_directory();
        file_put_contents($folder . '/entries.jsonl', "{\"n\":1}\n{\"n\":2}\n");
        faulty_stream::fail('truncate', '/entries.jsonl');
        $this->assert_write_failure(fn() => new manifest_builder(faulty_stream::url($folder), 1, 8), 'entries_truncate');
        $this->assertSame("{\"n\":1}\n{\"n\":2}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    public function test_every_line_can_be_dropped_while_the_file_is_open(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->append(['n' => 2]);
        $builder->commit();
        $builder->truncate_to(0);
        $this->assertSame('', file_get_contents($folder . '/entries.jsonl'));
        $this->assertSame(0, $builder->commit());
        $builder->append(['n' => 3]);
        $this->assertSame(1, $builder->commit());
        $this->assertSame("{\"n\":3}\n", file_get_contents($folder . '/entries.jsonl'));
    }

    /**
     * The faults of the files that build() writes and reads.
     *
     * @return array
     */
    public static function build_fault_provider(): array {
        return [
            'the compressed file does not open' => ['open:wb', '/manifest.jsonl.gz', 'manifest_open'],
            'the lines do not open' => ['open:rb', '/entries.jsonl', 'manifest_open'],
            'the compressed file cannot be written' => ['write', '/manifest.jsonl.gz', 'manifest_write'],
            'the compressed file does not open again' => ['open:rb', '/manifest.jsonl.gz', 'manifest_read'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('build_fault_provider')]
    /**
     * A list that cannot be compressed or read back is a failure, and leaves no compressed file and no chunk.
     *
     * @dataProvider build_fault_provider
     * @param string $fault The fault.
     * @param string $suffix The end of the path that it applies to.
     * @param string $info The debug information of the failure.
     */
    public function test_a_list_whose_files_fail_is_not_built(string $fault, string $suffix, string $info): void {
        [$builder, $folder] = $this->faulty_builder(2);
        faulty_stream::fail($fault, $suffix);
        $this->assert_write_failure(fn() => self::build($builder), $info);
        $this->assertFileDoesNotExist($folder . '/manifest.jsonl.gz');
        $this->assertFileDoesNotExist($folder . '/manifest.chunk');
    }

    public function test_lines_that_are_gone_from_the_file_when_the_list_is_built_are_a_failure(): void {
        [$builder, $folder] = $this->builder();
        $builder->append(['n' => 1]);
        $builder->append(['n' => 2]);
        $builder->commit();
        // Something else cut the file after the commit.
        $handle = fopen($folder . '/entries.jsonl', 'r+b');
        ftruncate($handle, 3);
        fclose($handle);
        $this->assert_write_failure(fn() => self::build($builder), 'manifest_short');
        $this->assertFileDoesNotExist($folder . '/manifest.jsonl.gz');
    }
}
