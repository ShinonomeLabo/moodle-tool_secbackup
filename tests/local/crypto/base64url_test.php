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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\base64url::class)]
/**
 * Tests for base64url.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\base64url
 */
final class base64url_test extends \basic_testcase {
    public function test_round_trip_without_padding(): void {
        $data = "\xfb\xff\x00binary";
        $encoded = base64url::encode($data);
        $this->assertSame('-_8AYmluYXJ5', $encoded);
        $this->assertSame($data, base64url::decode($encoded));
    }

    /**
     * Inputs that are not URL-safe base64 without padding.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'empty' => [''],
            'padding' => ['YQ=='],
            'standard alphabet' => ['+/8A'],
            'space' => ['YW Jj'],
            'impossible length' => ['A'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * Text that is not URL-safe base64 without padding is not decoded.
     *
     * @dataProvider invalid_provider
     * @param string $text The input.
     */
    public function test_invalid_input_returns_null(string $text): void {
        $this->assertNull(base64url::decode($text));
    }
}
