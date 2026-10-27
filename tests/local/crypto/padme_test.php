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

namespace tool_secbackup\local\crypto;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\padme::class)]
/**
 * Tests for the Padmé padding of lengths.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\padme
 */
final class padme_test extends \basic_testcase {
    public function test_the_examples_of_the_paper(): void {
        $this->assertSame(1, padme::length(1));
        $this->assertSame(10, padme::length(9));
        $this->assertSame(1024, padme::length(1000));
        $this->assertSame(100352, padme::length(100000));
    }

    public function test_lengths_below_two_are_kept(): void {
        $this->assertSame(0, padme::length(0));
        $this->assertSame(1, padme::length(1));
        $this->assertSame(-5, padme::length(-5));
    }

    public function test_short_lengths_are_already_padded(): void {
        // Up to 8 the number of bits that are kept covers the whole length.
        foreach (range(2, 8) as $length) {
            $this->assertSame($length, padme::length($length));
        }
        $this->assertSame(1024, padme::length(1024));
    }

    public function test_the_lengths_that_the_chunk_tests_rely_on(): void {
        // 2 MiB + 9 bytes (a length prefix and 2 MiB + 1 bytes) rounds up to a multiple of 64 KiB.
        $this->assertSame(2162688, padme::length(2097161));
        // Just above 64 MiB the step is 2 MiB, so the padding is longer than one frame of 1 MiB.
        $this->assertSame(67108864 + 2097152, padme::length(67108864 + 9));
        $this->assertSame(67108864, padme::length(67108864));
    }

    public function test_large_lengths(): void {
        // 2^40 + 1: E = 40, S = 6, so the last 34 bits are cleared.
        $this->assertSame(1099511627776 + 17179869184, padme::length(1099511627776 + 1));
        $this->assertSame(PHP_INT_MAX & ~((1 << 57) - 1), padme::length(PHP_INT_MAX & ~((1 << 57) - 1)));
    }

    public function test_the_result_is_never_shorter_and_at_most_twelve_percent_longer(): void {
        $worst = 0.0;
        for ($length = 1; $length <= 65536; $length++) {
            $padded = padme::length($length);
            if ($padded < $length || ($padded - $length) > 0.12 * $length) {
                $this->fail("padme::length($length) = $padded");
            }
            $worst = max($worst, ($padded - $length) / $length);
        }
        // The largest overhead is that of 129 (padded to 144, 11.6%).
        $this->assertEqualsWithDelta(15 / 129, $worst, 1e-12);
        $this->assertSame(144, padme::length(129));
    }
}
