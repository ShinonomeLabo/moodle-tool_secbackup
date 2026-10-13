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

namespace tool_secbackup\local\enrollment;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\enrollment_code::class)]
/**
 * Tests for the registration code (same rules as the service).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\enrollment_code
 */
final class enrollment_code_test extends \basic_testcase {
    /** @var string A code in its normal form. */
    private const CODE = 'SEC-BK-7QF2-MXA9-PDTW-4HJC-8NR5';

    /**
     * Ways of typing the same code.
     *
     * @return array
     */
    public static function same_code_provider(): array {
        return [
            'as shown' => [self::CODE],
            'without the prefix' => ['7QF2-MXA9-PDTW-4HJC-8NR5'],
            'without hyphens' => ['7QF2MXA9PDTW4HJC8NR5'],
            'lower case' => ['sec-bk-7qf2-mxa9-pdtw-4hjc-8nr5'],
            'spaces' => [' 7QF2 MXA9 PDTW 4HJC 8NR5 '],
            'full width' => [mb_convert_kana(self::CODE, 'AS', 'UTF-8')],
            'trailing line feed' => [self::CODE . "\n"],
            'tabs and line breaks inside' => ["7QF2\tMXA9\r\nPDTW 4HJC\n8NR5"],
            'ideographic space' => ["7QF2\u{3000}MXA9PDTW4HJC8NR5"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('same_code_provider')]
    /**
     * Every way of typing a code gives the code in its normal form.
     *
     * @dataProvider same_code_provider
     * @param string $input Typed code.
     */
    public function test_variants_give_the_same_code(string $input): void {
        $this->assertSame(self::CODE, enrollment_code::normalize($input));
    }

    public function test_the_secret_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(enrollment_code::class, 'normalize'))->getParameters()[0];
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_letters_that_look_like_digits(): void {
        // O reads as 0, and I and L read as 1.
        $this->assertSame('SEC-BK-0111-AAAA-BBBB-CCCC-DDDD', enrollment_code::normalize('OIL1-AAAA-BBBB-CCCC-DDDD'));
    }

    public function test_code_that_starts_with_secbk_keeps_it(): void {
        $this->assertSame('SEC-BK-SECB-KAAA-BBBB-CCCC-DDDD', enrollment_code::normalize('SECBKAAABBBBCCCCDDDD'));
    }

    /**
     * Inputs that cannot be a code.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'empty' => [''],
            'letter u' => ['UUUU-AAAA-BBBB-CCCC-DDDD'],
            'too short' => ['SEC-BK-7QF2-MXA9'],
            'too long' => [self::CODE . '-AAAA'],
            'symbols' => ['7QF2!MXA9-PDTW-4HJC-8NR5'],
            'nul byte' => ["7QF2MXA9PDTW4HJC8NR5\0"],
            'control character' => ["7QF2MXA9PDTW\x074HJC8NR5"],
            'invalid utf-8' => ["7QF2MXA9PDTW4HJC8NR5\xff"],
            'only separators' => [" - \n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * Text that cannot be a code gives null.
     *
     * @dataProvider invalid_provider
     * @param string $input Typed text.
     */
    public function test_invalid_inputs(string $input): void {
        $this->assertNull(enrollment_code::normalize($input));
    }
}
