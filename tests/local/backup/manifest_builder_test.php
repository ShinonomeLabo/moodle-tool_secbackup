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
use tool_secbackup\local\plugin_exception;

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

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * @param string $dedupkey Key for names.
     * @return string[] The lines, without their line feeds.
     */
    private static function lines(\tool_secbackup\local\crypto\chunk_info $chunk, string $dedupkey): array {
        $reader = new chunk_reader(
            $chunk->path,
            sodium_crypto_box_secretkey(self::pair()),
            sodium_crypto_box_publickey(self::pair()),
            self::SITE,
            $chunk->name
        );
        self::assertSame(chunk_writer::TYPE_MANIFEST, $reader->type());
        self::assertSame(chunk_name::manifest($dedupkey, self::RUN), $chunk->name);
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
        $key = str_repeat('k', 32);
        $header = ['v' => 1, 'site_id' => self::SITE, 'run_id' => self::RUN, 'kind' => 'full'];
        $chunk = $builder->build($header, $key, self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        $lines = self::lines($chunk, $key);
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
        $key = str_repeat('k', 32);
        $chunk = $builder->build(['v' => 1], $key, self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        $this->assertSame(['{"v":1}', '{"end":true,"entries":0}'], self::lines($chunk, $key));
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
        $builder->build(['x' => INF], str_repeat('k', 32), self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
    }

    public function test_a_large_list_is_written_without_holding_it_in_memory(): void {
        [$builder] = $this->builder();
        $line = ['t' => 'file', 'path' => str_repeat('p', 900)];
        for ($i = 0; $i < 3000; $i++) {
            $builder->append($line + ['n' => $i]);
        }
        $this->assertSame(3000, $builder->commit());
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage();
        $key = str_repeat('k', 32);
        $chunk = $builder->build(['v' => 1], $key, self::RUN, sodium_crypto_box_publickey(self::pair()), self::SITE);
        // The list is 2.8 MB of text: it is read and compressed in pieces.
        $this->assertLessThan(2000000, memory_get_peak_usage() - $before);
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
}
