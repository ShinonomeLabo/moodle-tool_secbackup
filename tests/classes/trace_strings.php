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

namespace tool_secbackup\tests;

/**
 * Reads the arguments that the traces of an exception keep, for the tests that a secret does not reach a trace.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_strings {
    /**
     * Every string among the arguments of the trace of an exception and of the exceptions it chains, also inside an exception
     * that is an argument.
     *
     * From PHP 8.2 on, an argument marked #[\SensitiveParameter] is a SensitiveParameterValue: its value is looked at too,
     * since a secret that only the marking hides is still kept in the trace.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     * @return string[]
     */
    public static function of(\Throwable $exception): array {
        $strings = [];
        self::collect($exception, $strings);
        return $strings;
    }

    /**
     * Whether an argument in the trace of an exception, or of one it chains, is an exception (or holds one in an array).
     *
     * An exception in an argument keeps its own trace, and that holds what the call of the first one held.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     * @return bool
     */
    public static function has_throwable_argument(\Throwable $exception): bool {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            foreach ($e->getTrace() as $frame) {
                if (self::holds_throwable($frame['args'] ?? [])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The classes of the objects that are arguments of calls of the plugin in the trace of an exception, or of one it chains.
     *
     * An object in the arguments of the plugin's own code can hold the data that the call worked on (a hash context holds
     * its last bytes; an exception holds its trace), and a trace is printed and serialised, so the code that makes an
     * exception for the outside must leave none. A SensitiveParameterValue counts too, unless it is empty.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     * @param string[] $allowed Classes of objects that hold no data of the call (such as the description of a table).
     * @return string[] Class names, one for each object found.
     */
    public static function objects_in_plugin_frames(\Throwable $exception, array $allowed = []): array {
        $found = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            foreach ($e->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'tool_secbackup\\')) {
                    self::collect_objects($frame['args'] ?? [], $found);
                }
            }
        }
        return array_values(array_diff($found, $allowed));
    }

    /**
     * Adds the classes of the objects in a value and in its arrays.
     *
     * @param mixed $value The value.
     * @param string[] $found The classes found so far.
     */
    private static function collect_objects(mixed $value, array &$found): void {
        if ($value instanceof \SensitiveParameterValue && $value->getValue() === null) {
            // The wrapper of a parameter that the function has unset holds nothing (PHP 8.2 and later wrap it still).
            return;
        }
        if (is_object($value)) {
            $found[] = $value::class;
        } else if (is_array($value)) {
            foreach ($value as $item) {
                self::collect_objects($item, $found);
            }
        }
    }

    /**
     * Whether a value is an exception or an array that holds one.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function holds_throwable(mixed $value): bool {
        if ($value instanceof \SensitiveParameterValue) {
            $value = $value->getValue();
        }
        if ($value instanceof \Throwable) {
            return true;
        }
        return is_array($value) && array_filter($value, [self::class, 'holds_throwable']) !== [];
    }

    /**
     * Adds the strings in a value, in its arrays and in the values of its SensitiveParameterValue objects.
     *
     * @param mixed $value The value.
     * @param string[] $strings The strings found so far.
     */
    private static function collect(mixed $value, array &$strings): void {
        if ($value instanceof \SensitiveParameterValue) {
            $value = $value->getValue();
        }
        if ($value instanceof \Throwable) {
            // The message, the arguments of every call in the trace, and the exception that it chains, which are as deep.
            for ($e = $value; $e !== null; $e = $e->getPrevious()) {
                $strings[] = $e->getMessage();
                foreach ($e->getTrace() as $frame) {
                    self::collect($frame['args'] ?? [], $strings);
                }
            }
        } else if (is_string($value)) {
            $strings[] = $value;
        } else if (is_array($value)) {
            foreach ($value as $item) {
                self::collect($item, $strings);
            }
        }
    }
}
