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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\index_builder::class)]
/**
 * Tests for the index of the chunks of a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\index_builder
 */
final class index_builder_test extends \basic_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '11111111-1111-4111-8111-111111111111';

    /** @var string Run ID of the tests. */
    private const RUN = '22222222-2222-4222-8222-222222222222';

    /**
     * A chunk name.
     *
     * @param string $digit One hexadecimal digit.
     * @return string
     */
    private static function chunkname(string $digit): string {
        return str_repeat($digit, 64);
    }

    public function test_the_index_is_the_header_and_a_line_for_each_chunk_in_the_order_of_the_names(): void {
        $built = index_builder::build(
            [self::chunkname('b') => 2048, self::chunkname('a') => 17, self::chunkname('c') => 9007199254740991],
            self::SITE,
            self::RUN
        );
        $expected = '{"v":1,"site_id":"' . self::SITE . '","run_id":"' . self::RUN . '","count":3}' . "\n"
            . self::chunkname('a') . " 17\n"
            . self::chunkname('b') . " 2048\n"
            . self::chunkname('c') . " 9007199254740991\n";
        // The content, as the contract fixes it, in hexadecimal for the first bytes and as text for the rest.
        $this->assertSame($expected, gzdecode($built['gzip']));
        $this->assertSame(bin2hex('{"v":1,"site_id":"11111111-1111-4111'), bin2hex(substr(gzdecode($built['gzip']), 0, 36)));
        $this->assertSame(3, $built['count']);
        $this->assertSame(base64_encode(hash('sha256', $built['gzip'], true)), $built['sha256base64']);
        $this->assertSame("\x1f\x8b", substr($built['gzip'], 0, 2));
    }

    public function test_the_same_sizes_give_the_same_bytes(): void {
        $sizes = [self::chunkname('b') => 2, self::chunkname('a') => 1];
        $this->assertSame(
            index_builder::build($sizes, self::SITE, self::RUN),
            index_builder::build(array_reverse($sizes, true), self::SITE, self::RUN)
        );
    }

    public function test_an_index_without_chunks_is_the_header_alone(): void {
        $built = index_builder::build([], self::SITE, self::RUN);
        $header = '{"v":1,"site_id":"' . self::SITE . '","run_id":"' . self::RUN . '","count":0}' . "\n";
        $this->assertSame($header, gzdecode($built['gzip']));
        $this->assertSame(0, $built['count']);
    }

    /**
     * Sizes that are not an index.
     *
     * @return array
     */
    public static function bad_sizes_provider(): array {
        $name = str_repeat('a', 64);
        return [
            'a short name' => [['abc' => 1]],
            'a name in capitals' => [[str_repeat('A', 64) => 1]],
            'a name with a line feed' => [[$name . "\n" => 1]],
            'a size of zero' => [[$name => 0]],
            'a negative size' => [[$name => -1]],
            'a size as text' => [[$name => '5']],
            'a size above what a number holds exactly' => [[$name => 9007199254740992]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_sizes_provider')]
    /**
     * Sizes that are not in their form are a coding error.
     *
     * @dataProvider bad_sizes_provider
     * @param array $sizes The sizes.
     */
    public function test_a_name_or_a_size_that_is_not_in_its_form_is_a_coding_error(array $sizes): void {
        $this->expectException(\coding_exception::class);
        index_builder::build($sizes, self::SITE, self::RUN);
    }

    public function test_the_site_and_the_run_are_uuids(): void {
        $this->expectException(\coding_exception::class);
        index_builder::build([], self::SITE, 'x');
    }

    public function test_more_than_the_most_chunks_is_a_refusal_before_anything_is_built(): void {
        $this->assertSame(818399, index_builder::MAX_COUNT);
        $this->assertSame(67108864, index_builder::MAX_BYTES);
        $sizes = [];
        for ($i = 0; $i <= index_builder::MAX_COUNT; $i++) {
            $sizes[sprintf('%064x', $i)] = 1;
        }
        $this->assertCount(818400, $sizes);
        try {
            index_builder::build($sizes, self::SITE, self::RUN);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('capacity_refused', $e->errorcode);
        }
    }

    public function test_the_most_chunks_are_built_and_fit_in_the_most_bytes(): void {
        $sizes = [];
        for ($i = 0; $i < index_builder::MAX_COUNT; $i++) {
            $sizes[sprintf('%064x', $i)] = 9007199254740991;
        }
        $built = index_builder::build($sizes, self::SITE, self::RUN);
        $this->assertSame(818399, $built['count']);
        $text = gzdecode($built['gzip']);
        $this->assertLessThanOrEqual(index_builder::MAX_BYTES, strlen($text));
        $this->assertSame(818400, substr_count($text, "\n"));
    }

    public function test_an_index_whose_text_is_over_the_most_bytes_is_a_refusal(): void {
        // Few names cannot reach the limit of the bytes; a lower limit is set for the check.
        $this->expectException(plugin_exception::class);
        index_builder::build([self::chunkname('a') => 1], self::SITE, self::RUN, 100);
    }
}
