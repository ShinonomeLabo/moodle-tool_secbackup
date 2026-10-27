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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\plugin_exception;

/**
 * The database refused a step of the import of the blocks (restore_import_failed).
 *
 * It keeps the exception of the database layer as its previous exception. Its own message and debug information name the
 * table, never a value of a row: the debug information of an error of the database layer has the values of the query.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class import_failed_exception extends plugin_exception {
    /**
     * Constructor.
     *
     * @param \Throwable $previous The exception of the database layer.
     * @param string $table The table that was being imported, without the prefix; empty before the first one.
     */
    public function __construct(\Throwable $previous, string $table) {
        parent::__construct('restore_import_failed', null, $table);
        // The constructor of moodle_exception gives \Exception no previous exception, so the one of \Exception runs again,
        // with the same message.
        \Exception::__construct($this->getMessage(), 0, $previous);
    }
}
