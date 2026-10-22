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

use tool_secbackup\local\plugin_exception;

/**
 * Opens the connection that the export reads from, and chooses its way of reading at one point in time.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_factory {
    /**
     * The connection options of the export.
     *
     * The snapshot statements and every read must go to one server, so a read replica is not used (readonly); a persistent
     * link could be the very connection that Moodle itself uses (dbpersist), and the transaction would then be shared.
     *
     * @param array $dboptions The database options of the site ($CFG->dboptions).
     * @return array
     */
    public static function options(array $dboptions): array {
        unset($dboptions['readonly']);
        $dboptions['dbpersist'] = false;
        return $dboptions;
    }

    /**
     * Opens a new connection to the database of the site, for the export only. The caller calls dispose() on it.
     *
     * Whatever goes wrong while the driver connects, including a failure after the link is up, is reported as a new
     * dml_connection_exception made here, with no previous exception: the trace of the driver's own exception carries the
     * arguments of connect(), and the password is one of them. Its debug information is that of the driver's exception when
     * that is an exception of Moodle, else the name of its class.
     *
     * @param \moodle_database|null $driver The driver to connect; by default a new one of the type of the site. It is a seam
     *     for tests: production passes nothing, and the connection of Moodle ($DB) must never be passed.
     * @return \moodle_database
     * @throws \dml_connection_exception When the database cannot be reached.
     * @throws plugin_exception With db_unsupported for a driver that cannot be loaded.
     */
    public static function open(?\moodle_database $driver = null): \moodle_database {
        global $CFG;
        $db = $driver ?? \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        if ($db === null) {
            throw new plugin_exception('db_unsupported');
        }
        try {
            $options = self::options($CFG->dboptions ?? []);
            $db->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $options);
        } catch (\Throwable $e) {
            $debuginfo = $e instanceof \moodle_exception ? (string)$e->debuginfo : get_class($e);
            try {
                $db->dispose();
            } catch (\Throwable) {
                // The link is given up anyway; the failure to report is the one of connect().
                unset($db);
            }
            throw new \dml_connection_exception($debuginfo);
        }
        return $db;
    }

    /**
     * The way to read a database at one point in time.
     *
     * @param \moodle_database $db The connection.
     * @return snapshot
     * @throws plugin_exception With db_unsupported for a database other than MySQL, MariaDB and PostgreSQL.
     */
    public static function snapshot_for(\moodle_database $db): snapshot {
        return match ($db->get_dbfamily()) {
            'mysql' => new snapshot_mysql(),
            'postgres' => new snapshot_pgsql(),
            default => throw new plugin_exception('db_unsupported'),
        };
    }
}
