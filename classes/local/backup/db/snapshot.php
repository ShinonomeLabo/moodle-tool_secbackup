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

namespace tool_secbackup\local\backup\db;

/**
 * Reads the database at one point in time on a connection that is used for nothing else.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface snapshot {
    /**
     * Starts a transaction whose reads all see the database as it is now.
     *
     * @param \moodle_database $db The connection of the export; no other code may use it.
     */
    public function begin(\moodle_database $db): void;

    /**
     * Ends the transaction. It changed nothing, so it is rolled back.
     *
     * @param \moodle_database $db The connection of the export.
     */
    public function end(\moodle_database $db): void;
}
