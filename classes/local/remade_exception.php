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

/**
 * Makes an exception again, so that the new one holds nothing of the first one's trace.
 *
 * The trace of an exception keeps the arguments of the calls that were open when it was made, and for an exception from the
 * writer of chunks these are pieces of the data that is being written. The new exception must not get the first as the
 * argument of a call either, since that keeps the whole first exception. So the work is in two steps, and the first
 * exception goes into the first only: ``remade_exception::build(remade_exception::parts($e))``. The call of build() has
 * the parts as its argument, which are strings and an array of values for the language string.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remade_exception {
    /** @var string[] Classes made again as they are: their constructor takes the error code, the value and the debug information. */
    private const KEPT = [plugin_exception::class, key_exception::class];

    /**
     * What is kept of an exception: for a plugin_exception or a key_exception its class, error code, value for the language
     * string and debug information; for any other class (also another exception of the plugin, whose constructor takes other
     * arguments) only its class.
     *
     * @param \Throwable $exception The exception.
     * @return array
     */
    public static function parts(\Throwable $exception): array {
        if (in_array($exception::class, self::KEPT, true)) {
            return [
                'class' => $exception::class,
                'errorcode' => $exception->errorcode,
                'a' => $exception->a,
                'debuginfo' => $exception->debuginfo,
            ];
        }
        return ['class' => $exception::class];
    }

    /**
     * The new exception.
     *
     * @param array $parts What parts() gave.
     * @param string $what What failed, for the message of a coding_exception.
     * @return \Throwable The same class, error code, value and debug information for a plugin_exception or a key_exception,
     *     with no exception chained; a coding_exception that names the class for any other.
     */
    public static function build(array $parts, string $what = 'The chunk could not be written'): \Throwable {
        if (!isset($parts['errorcode'])) {
            return new \coding_exception($what . ': ' . $parts['class']);
        }
        $class = $parts['class'];
        return new $class($parts['errorcode'], $parts['a'], $parts['debuginfo']);
    }
}
