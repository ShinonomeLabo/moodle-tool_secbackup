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
 * The size of the tables on disk, for the check that the temporary area can hold the export.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class size_estimate {
    /**
     * The size of some tables, as the database counts it.
     *
     * MySQL and MariaDB give the data_length of information_schema.tables, which leaves out the indexes and is an estimate
     * of the engine; PostgreSQL gives pg_total_relation_size(), which includes indexes and large objects of the table.
     *
     * @param \moodle_database $db The connection.
     * @param \xmldb_table[] $tables The tables.
     * @return int Bytes; 0 for no tables, for a database that cannot tell and when the database does not answer.
     */
    public static function bytes(\moodle_database $db, array $tables): int {
        if ($tables === []) {
            return 0;
        }
        $names = array_map(static fn(\xmldb_table $table): string => $db->get_prefix() . $table->getName(), $tables);
        [$in, $params] = $db->get_in_or_equal($names);
        $family = $db->get_dbfamily();
        if ($family === 'mysql') {
            $sql = "SELECT SUM(data_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name $in";
        } else if ($family === 'postgres') {
            $sql = "SELECT SUM(pg_total_relation_size(c.oid)) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname $in";
        } else {
            return 0;
        }
        try {
            $bytes = $db->get_field_sql($sql, $params);
        } catch (\dml_exception) {
            return 0;
        }
        return is_numeric($bytes) ? (int)$bytes : 0;
    }
}
