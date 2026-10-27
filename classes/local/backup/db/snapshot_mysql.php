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
 * The snapshot of MySQL and MariaDB: a repeatable-read transaction with a consistent snapshot.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class snapshot_mysql implements snapshot {
    /**
     * Starts a repeatable-read transaction with a consistent snapshot.
     *
     * @param \moodle_database $db The connection of the export.
     */
    public function begin(\moodle_database $db): void {
        $db->execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        // Unlike PostgreSQL, the snapshot is taken here, not at the first read.
        $db->execute('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    }

    /**
     * Rolls the transaction back; it changed nothing.
     *
     * @param \moodle_database $db The connection of the export.
     */
    public function end(\moodle_database $db): void {
        $db->execute('ROLLBACK');
    }
}
