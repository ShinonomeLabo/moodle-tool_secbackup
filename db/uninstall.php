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
 * Uninstallation of tool_secbackup.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Removes the temporary files of the backups (the folders of the runs and the records of the present lists) from the
 * temporary folder of the plugin, which Moodle does not know of when it is set elsewhere. The settings and the table are
 * removed by Moodle.
 *
 * @return bool
 */
function xmldb_tool_secbackup_uninstall() {
    \tool_secbackup\local\backup\temp_area::create()->clear_all();
    return true;
}
