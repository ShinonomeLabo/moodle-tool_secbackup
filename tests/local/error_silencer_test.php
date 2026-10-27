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

namespace tool_secbackup\local;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\error_silencer::class)]
/**
 * Tests for the helper that runs an operation without letting PHP report its warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\error_silencer
 */
final class error_silencer_test extends \basic_testcase {
    public function test_it_gives_back_what_the_operation_gives_and_an_empty_warning(): void {
        $this->assertSame(42, error_silencer::run(static fn() => 42, $warning));
        $this->assertSame('', $warning);
    }

    public function test_it_keeps_the_text_of_the_last_warning_and_raises_nothing(): void {
        $result = error_silencer::run(static fn() => fopen('/no/such/folder/file', 'rb'), $warning);
        $this->assertFalse($result);
        $this->assertStringContainsString('No such file or directory', $warning);
        error_silencer::run(static function () {
            trigger_error('first', E_USER_NOTICE);
            trigger_error('second', E_USER_NOTICE);
        }, $warning);
        $this->assertSame('second', $warning);
    }

    public function test_the_handler_of_the_caller_comes_back_also_after_an_exception(): void {
        $seen = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = $errstr;
            return true;
        });
        try {
            error_silencer::run(static fn() => 1, $warning);
            try {
                error_silencer::run(static function (): void {
                    throw new \RuntimeException('boom');
                }, $warning);
                $this->fail('The exception was swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            trigger_error('after', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(['after'], $seen);
    }
}
