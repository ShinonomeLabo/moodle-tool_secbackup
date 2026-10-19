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
     * Every string among the arguments of the trace of an exception and of the exceptions it chains.
     *
     * From PHP 8.2 on, an argument marked #[\SensitiveParameter] is a SensitiveParameterValue: its value is looked at too,
     * since a secret that only the marking hides is still kept in the trace.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     * @return string[]
     */
    public static function of(\Throwable $exception): array {
        $strings = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            foreach ($e->getTrace() as $frame) {
                self::collect($frame['args'] ?? [], $strings);
            }
        }
        return $strings;
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
        if (is_string($value)) {
            $strings[] = $value;
        } else if (is_array($value)) {
            foreach ($value as $item) {
                self::collect($item, $strings);
            }
        }
    }
}
