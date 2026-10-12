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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\public_key_format::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\key_exception::class)]
/**
 * Tests for the pasted public key format.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\public_key_format
 * @covers     \tool_secbackup\local\crypto\key_exception
 */
final class public_key_format_test extends \basic_testcase {
    /** @var string The service's encoding of 32 bytes of 0x01. */
    private const VALID = 'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';

    /**
     * Pasted variants that must all be read as the same key.
     *
     * @return array
     */
    public static function accepted_provider(): array {
        return [
            'as shown' => [self::VALID],
            'surrounding spaces' => ['  ' . self::VALID . "\n"],
            'line break inside' => ["secbk1:AQEBAQEBAQEBAQEBAQEBAQEB\nAQEBAQEBAQEBAQEBAQE="],
            'upper case prefix' => ['SECBK1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='],
            'full-width space' => ["\u{3000}" . self::VALID],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accepted_provider')]
    /**
     * Every pasted variant is read as the same key.
     *
     * @dataProvider accepted_provider
     * @param string $input Pasted text.
     */
    public function test_parse_accepts_pasted_variants(string $input): void {
        $this->assertSame(str_repeat("\x01", 32), public_key_format::parse($input));
    }

    /**
     * Inputs that must be refused, with the reason.
     *
     * @return array
     */
    public static function rejected_provider(): array {
        return [
            'no prefix' => ['AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', 'publickey_prefix'],
            'other prefix' => ['secbk2:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', 'publickey_prefix'],
            'not base64' => ['secbk1:AQEB*QEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', 'publickey_base64'],
            'too short' => ['secbk1:AQEBAQ==', 'publickey_length'],
            'too long' => ['secbk1:' . base64_encode(str_repeat("\x01", 33)), 'publickey_length'],
            'empty' => ['', 'publickey_prefix'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected_provider')]
    /**
     * Text that is not a key is refused with the matching error code.
     *
     * @dataProvider rejected_provider
     * @param string $input Pasted text.
     * @param string $reason Expected error code.
     */
    public function test_parse_rejects_with_reason(string $input, string $reason): void {
        try {
            public_key_format::parse($input);
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame($reason, $e->errorcode);
        }
    }

    public function test_encode_matches_the_service(): void {
        $this->assertSame(self::VALID, public_key_format::encode(str_repeat("\x01", 32)));
    }

    public function test_encode_rejects_wrong_length(): void {
        $this->expectException(\coding_exception::class);
        public_key_format::encode('short');
    }

    public function test_encode_rejects_a_key_that_is_too_long(): void {
        $this->expectException(\coding_exception::class);
        public_key_format::encode(str_repeat("\x01", 33));
    }
}
