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

/**
 * The function that mtrace() of Moodle calls while the extraction runs, by its name.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The $CFG->mtrace_wrapper of the extraction on the command line: mtrace() of Moodle writes to the standard output past the
 * output buffer, so while the extraction runs it calls this function, which gives the text to the error stream.
 *
 * @param string $string The text.
 * @param string $eol The end of line that mtrace() was given.
 */
function tool_secbackup_restore_mtrace(string $string, string $eol): void {
    \tool_secbackup\local\cli\restore_command::stray($string . $eol);
}
