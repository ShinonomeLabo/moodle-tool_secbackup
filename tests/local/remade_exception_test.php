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

use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\tests\trace_strings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\remade_exception::class)]
/**
 * Tests for the exceptions that are made again without the trace of the first.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\remade_exception
 */
final class remade_exception_test extends \basic_testcase {
    /**
     * Throws an exception from a call that has a secret as an argument.
     *
     * @param \Throwable $exception What to throw.
     * @param string $secret The secret.
     * @throws \Throwable Always.
     */
    private static function throw_with(\Throwable $exception, string $secret): never {
        throw $exception;
    }

    public function test_a_plugin_exception_is_the_same_class_with_the_same_code_text_and_debug_information(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $first = null;
            try {
                self::throw_with(new plugin_exception('temp_write_failed', 'text', 'write: no room'), 'SECRETMARKER');
            } catch (plugin_exception $e) {
                $first = $e;
            }
            try {
                throw $first;
            } catch (\Throwable $e) {
                $again = remade_exception::build(remade_exception::parts($e));
            }
            $this->assertSame(plugin_exception::class, $again::class);
            $this->assertSame('temp_write_failed', $again->errorcode);
            $this->assertSame('text', $again->a);
            $this->assertSame('write: no room', $again->debuginfo);
            $this->assertNull($again->getPrevious());
            $this->assertFalse(trace_strings::has_throwable_argument($again));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_a_subclass_stays_a_subclass(): void {
        try {
            throw new key_exception('publickey_invalid');
        } catch (\Throwable $e) {
            $again = remade_exception::build(remade_exception::parts($e));
        }
        $this->assertSame(key_exception::class, $again::class);
        $this->assertSame('publickey_invalid', $again->errorcode);
    }

    public function test_another_exception_becomes_a_coding_exception_that_names_only_its_class(): void {
        try {
            throw new \RuntimeException('the secret data SECRETMARKER');
        } catch (\Throwable $e) {
            $again = remade_exception::build(remade_exception::parts($e));
        }
        $this->assertInstanceOf(\coding_exception::class, $again);
        $this->assertStringContainsString(\RuntimeException::class, $again->getMessage());
        $this->assertStringNotContainsString('SECRETMARKER', $again->getMessage());
        $this->assertNull($again->getPrevious());
    }

    public function test_nothing_of_the_first_exception_is_in_the_trace(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::throw_with(new plugin_exception('temp_write_failed'), 'SECRETMARKER');
            } catch (plugin_exception $e) {
                $again = remade_exception::build(remade_exception::parts($e));
            }
            $this->assertFalse(trace_strings::has_throwable_argument($again));
            foreach (trace_strings::of($again) as $text) {
                $this->assertStringNotContainsString('SECRETMARKER', $text);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
