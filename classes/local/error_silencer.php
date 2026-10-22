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

/**
 * Runs an operation without letting PHP report its warning, and keeps the text of the warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class error_silencer {
    /**
     * Runs an operation.
     *
     * @param \Closure $operation The operation.
     * @param string|null $warning Set to the text of the last warning, or to an empty string.
     * @return mixed What the operation returns.
     */
    public static function run(\Closure $operation, ?string &$warning): mixed {
        $warning = '';
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
