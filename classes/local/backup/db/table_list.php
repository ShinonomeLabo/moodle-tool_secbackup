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
 * The tables to export: those that the install.xml files of the core and of the installed plugins describe.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class table_list {
    /** @var string[] Tables that are never exported: the sessions of the site and the flags of the caches. */
    private const ALWAYS_EXCLUDED = ['cache_flags', 'sessions'];

    /** @var string[] Tables of logs, exported only when the settings ask for the logs. */
    private const LOG_TABLES = ['log', 'logstore_standard_log'];

    /**
     * The tables of the site, sorted into those to export, those that are not known and those that are left out.
     *
     * @param bool $includelogs Whether the tables of logs are exported (the include_logs setting).
     * @return array{export: \xmldb_table[], unknown: string[], excluded: string[]} The tables to export (objects of the
     *     install.xml files, sorted by name), the names of the tables of the site that no install.xml file describes, and the
     *     names of the tables that exist but are left out. Names have no prefix. A table that an install.xml file describes
     *     but the database does not have is in none of them.
     */
    public static function build(bool $includelogs): array {
        global $DB;
        $existing = array_flip($DB->get_tables(false));
        $leftout = $includelogs ? self::ALWAYS_EXCLUDED : array_merge(self::ALWAYS_EXCLUDED, self::LOG_TABLES);
        $described = [];
        $export = [];
        foreach ($DB->get_manager()->get_install_xml_schema()->getTables() as $table) {
            $name = $table->getName();
            $described[$name] = true;
            if (isset($existing[$name]) && !in_array($name, $leftout, true)) {
                $export[$name] = $table;
            }
        }
        ksort($export, SORT_STRING);
        $unknown = array_diff(array_keys($existing), array_keys($described));
        sort($unknown, SORT_STRING);
        $excluded = array_values(array_intersect($leftout, array_keys($existing)));
        sort($excluded, SORT_STRING);
        return ['export' => array_values($export), 'unknown' => $unknown, 'excluded' => $excluded];
    }
}
