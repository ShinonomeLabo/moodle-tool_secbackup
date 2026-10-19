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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\journal::class)]
/**
 * Tests for the files of lines that a run keeps between its steps.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\journal
 */
final class journal_test extends \advanced_testcase {
    /**
     * A journal in a new folder.
     *
     * @return array [journal, path]
     */
    private static function journal(): array {
        $path = make_request_directory() . '/list.jsonl';
        return [new journal($path), $path];
    }

    public function test_values_are_written_one_on_a_line_and_read_back_in_order(): void {
        [$journal, $path] = self::journal();
        $journal->append('a/b');
        $journal->append(['x', 12]);
        $journal->append(['k' => 'v']);
        $this->assertSame(3, $journal->commit());
        $this->assertSame("\"a/b\"\n[\"x\",12]\n{\"k\":\"v\"}\n", file_get_contents($path));
        $this->assertSame(['a/b', ['x', 12], ['k' => 'v']], iterator_to_array($journal->values(), false));
        $this->assertSame(3, $journal->lines());
    }

    public function test_reading_from_an_offset_gives_the_offset_after_each_value(): void {
        [$journal] = self::journal();
        $journal->append('one');
        $journal->append('two');
        $journal->append('three');
        $journal->commit();
        $read = [];
        foreach ($journal->read(0) as $next => $value) {
            $read[] = [$next, $value];
        }
        $this->assertSame([[6, 'one'], [12, 'two'], [20, 'three']], $read);
        $this->assertSame(['two', 'three'], iterator_to_array($journal->read(6), false));
        $this->assertSame([], iterator_to_array($journal->read(20), false));
    }

    public function test_a_new_journal_counts_the_lines_and_drops_a_line_that_was_cut(): void {
        [, $path] = self::journal();
        file_put_contents($path, "\"a\"\n\"b\"\n\"cu");
        $journal = new journal($path);
        $this->assertSame(2, $journal->lines());
        $this->assertSame("\"a\"\n\"b\"\n", file_get_contents($path));
        $journal->append('c');
        $journal->commit();
        $this->assertSame(['a', 'b', 'c'], iterator_to_array($journal->values(), false));
    }

    public function test_truncate_keeps_the_committed_lines_only(): void {
        [$journal, $path] = self::journal();
        foreach (['a', 'b', 'c', 'd'] as $value) {
            $journal->append($value);
        }
        $journal->commit();
        $journal->truncate_to(2);
        $this->assertSame(2, $journal->lines());
        $this->assertSame("\"a\"\n\"b\"\n", file_get_contents($path));
        $journal->append('e');
        $this->assertSame(['a', 'b', 'e'], iterator_to_array($journal->values(), false));
        $journal->truncate_to(0);
        $this->assertSame('', file_get_contents($path));
    }

    public function test_a_journal_restored_from_the_committed_figures_cuts_without_reading(): void {
        [$journal, $path] = self::journal();
        foreach (['a', 'b', 'c'] as $value) {
            $journal->append($value);
        }
        $journal->commit();
        $this->assertSame(12, $journal->bytes());
        $journal->close();
        $restored = new journal($path, 2, 8);
        $this->assertSame(2, $restored->lines());
        $this->assertSame(8, $restored->bytes());
        $this->assertSame("\"a\"\n\"b\"\n", file_get_contents($path));
        $restored->append('d');
        $this->assertSame(['a', 'b', 'd'], iterator_to_array($restored->values(), false));
        // Nothing committed: whatever a stopped step left is dropped, and a missing file is an empty journal.
        $this->assertSame(0, (new journal($path, 0, 0))->lines());
        $this->assertSame('', file_get_contents($path));
        $this->assertSame(0, (new journal(dirname($path) . '/none.jsonl', 0, 0))->lines());
    }

    public function test_a_journal_shorter_than_its_committed_figures_is_refused(): void {
        [$journal, $path] = self::journal();
        $journal->append('a');
        $journal->commit();
        $e = $this->catch_plugin_exception(static fn() => new journal($path, 2, 50));
        $this->assertSame('temp_write_failed', $e->errorcode);
        $e = $this->catch_plugin_exception(static fn() => new journal(dirname($path) . '/none.jsonl', 1, 4));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    public function test_truncate_beyond_the_lines_is_refused(): void {
        [$journal] = self::journal();
        $journal->append('a');
        $journal->commit();
        $e = $this->catch_plugin_exception(static fn() => $journal->truncate_to(2));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    public function test_a_missing_file_is_an_empty_journal_and_more_lines_are_refused(): void {
        $path = make_request_directory() . '/none.jsonl';
        $journal = new journal($path);
        $this->assertSame(0, $journal->lines());
        $this->assertSame([], iterator_to_array($journal->values(), false));
        $journal->truncate_to(0);
        $this->assertFileDoesNotExist($path);
        $e = $this->catch_plugin_exception(static fn() => (new journal($path))->truncate_to(1));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    public function test_a_value_without_a_json_form_is_refused(): void {
        [$journal] = self::journal();
        $this->expectException(\coding_exception::class);
        $journal->append("\xff\xfe");
    }

    public function test_a_file_that_cannot_be_written_is_temp_write_failed(): void {
        $folder = make_request_directory();
        mkdir($folder . '/list.jsonl');
        $journal = new journal($folder . '/list.jsonl');
        $e = $this->catch_plugin_exception(static fn() => $journal->append('a'));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    public function test_a_line_that_is_not_json_is_refused_when_read(): void {
        [, $path] = self::journal();
        file_put_contents($path, "\"a\"\n{oops\n");
        $journal = new journal($path);
        $e = $this->catch_plugin_exception(static fn() => iterator_to_array($journal->values(), false));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    /**
     * The exception of the plugin that a call throws.
     *
     * @param callable $call The call.
     * @return plugin_exception
     */
    private function catch_plugin_exception(callable $call): plugin_exception {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }
}
