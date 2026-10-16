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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\fingerprint::class)]
/**
 * Tests for fingerprint (same algorithm as the service).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\fingerprint
 */
final class fingerprint_test extends \basic_testcase {
    public function test_matches_the_service_vector(): void {
        $this->assertSame('A260B4050FD28D2B313C9A94', fingerprint::of(str_repeat("\x01", 32)));
    }

    public function test_format_groups_by_four(): void {
        $this->assertSame('A260 B405 0FD2 8D2B 313C 9A94', fingerprint::format('A260B4050FD28D2B313C9A94'));
    }

    public function test_normalize_accepts_spaces_and_lower_case(): void {
        $this->assertSame('A260B4050FD28D2B313C9A94', fingerprint::normalize(" a260 b405\n0fd2 8d2b 313c 9a94 "));
    }

    public function test_normalize_rejects_other_shapes(): void {
        $this->assertNull(fingerprint::normalize('A260B4050FD28D2B313C9A9'));
        $this->assertNull(fingerprint::normalize('A260B4050FD28D2B313C9A9Z'));
        $this->assertNull(fingerprint::normalize(''));
        // The 24 characters must be the whole text, not its start or its end.
        $this->assertNull(fingerprint::normalize('XA260B4050FD28D2B313C9A94'));
        $this->assertNull(fingerprint::normalize('A260B4050FD28D2B313C9A940'));
    }
}
