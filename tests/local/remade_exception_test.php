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

use tool_secbackup\local\api\api_exception;
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
     * Throws an exception from a call that has a secret as an argument, so that its trace holds the secret.
     *
     * @param string $class The class of the exception.
     * @param string $errorcode The error code.
     * @param string $secret The secret.
     * @param mixed $a Value for the language string.
     * @param string|null $debuginfo Debug information.
     * @throws \Throwable Always.
     */
    private static function throw_with(
        string $class,
        string $errorcode,
        string $secret,
        mixed $a = null,
        ?string $debuginfo = null
    ): never {
        throw new $class($errorcode, $a, $debuginfo);
    }

    /**
     * Throws an exception that does not take an error code, from a call that has a secret as an argument.
     *
     * @param string $secret The secret.
     * @throws \RuntimeException Always.
     */
    private static function throw_other(string $secret): never {
        throw new \RuntimeException('the secret data ' . $secret);
    }

    /**
     * Whether the secret is in the strings of the trace of an exception.
     *
     * @param \Throwable $exception The exception.
     * @return bool
     */
    private static function holds_secret(\Throwable $exception): bool {
        foreach (trace_strings::of($exception) as $text) {
            if (str_contains($text, 'SECRETMARKER')) {
                return true;
            }
        }
        return false;
    }

    public function test_a_plugin_exception_is_the_same_class_with_the_same_code_text_and_debug_information(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::throw_with(plugin_exception::class, 'temp_write_failed', 'SECRETMARKER', 'text', 'write: no room');
            } catch (\Throwable $e) {
                $this->assertTrue(self::holds_secret($e), 'The first exception holds the secret, so the check means something.');
                $again = remade_exception::build(remade_exception::parts($e));
            }
            $this->assertSame(plugin_exception::class, $again::class);
            $this->assertSame('temp_write_failed', $again->errorcode);
            $this->assertSame('text', $again->a);
            $this->assertSame('write: no room', $again->debuginfo);
            $this->assertNull($again->getPrevious());
            $this->assertFalse(trace_strings::has_throwable_argument($again));
            $this->assertFalse(self::holds_secret($again));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_a_key_exception_stays_a_key_exception(): void {
        try {
            self::throw_with(key_exception::class, 'publickey_invalid', 'SECRETMARKER');
        } catch (\Throwable $e) {
            $again = remade_exception::build(remade_exception::parts($e));
        }
        $this->assertSame(key_exception::class, $again::class);
        $this->assertSame('publickey_invalid', $again->errorcode);
    }

    public function test_an_exception_with_another_constructor_becomes_a_coding_exception(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $first = new api_exception('api_failed', 'some_server_code', 500);
            $again = remade_exception::build(remade_exception::parts($first));
            $this->assertInstanceOf(\coding_exception::class, $again);
            $this->assertStringContainsString(api_exception::class, $again->getMessage());
            $this->assertNull($again->getPrevious());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_another_exception_becomes_a_coding_exception_that_names_only_its_class(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                self::throw_other('SECRETMARKER');
            } catch (\Throwable $e) {
                $again = remade_exception::build(remade_exception::parts($e));
            }
            $this->assertInstanceOf(\coding_exception::class, $again);
            $this->assertStringContainsString(\RuntimeException::class, $again->getMessage());
            $this->assertStringNotContainsString('SECRETMARKER', $again->getMessage());
            $this->assertNull($again->getPrevious());
            $this->assertFalse(self::holds_secret($again));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
