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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\table_list::class)]
/**
 * Tests for the list of the tables to export.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\db\table_list
 */
final class table_list_test extends \advanced_testcase {
    /**
     * The names of some tables.
     *
     * @param \xmldb_table[] $tables The tables.
     * @return string[]
     */
    private static function names(array $tables): array {
        return array_map(static fn(\xmldb_table $table): string => $table->getName(), $tables);
    }

    public function test_sessions_and_the_cache_flags_are_never_exported(): void {
        global $DB;
        $existing = $DB->get_tables(false);
        $this->assertContains('sessions', $existing);
        $this->assertContains('cache_flags', $existing);
        foreach ([false, true] as $includelogs) {
            $list = table_list::build($includelogs);
            $this->assertNotContains('sessions', self::names($list['export']));
            $this->assertNotContains('cache_flags', self::names($list['export']));
            $this->assertContains('sessions', $list['excluded']);
            $this->assertContains('cache_flags', $list['excluded']);
        }
    }

    public function test_the_tables_of_logs_are_exported_only_when_the_logs_are_included(): void {
        global $DB;
        $existing = $DB->get_tables(false);
        $logs = array_values(array_intersect(['log', 'logstore_standard_log'], $existing));
        $this->assertContains('logstore_standard_log', $logs);
        $without = table_list::build(false);
        $with = table_list::build(true);
        foreach ($logs as $name) {
            $this->assertNotContains($name, self::names($without['export']));
            $this->assertContains($name, $without['excluded']);
            $this->assertContains($name, self::names($with['export']));
            $this->assertNotContains($name, $with['excluded']);
        }
        $this->assertSame(['cache_flags', 'sessions'], $with['excluded']);
        $this->assertSame(array_merge(['cache_flags'], $logs, ['sessions']), $without['excluded']);
    }

    public function test_the_tables_to_export_are_objects_sorted_by_name(): void {
        global $DB;
        $list = table_list::build(true);
        $names = self::names($list['export']);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertContainsOnlyInstancesOf(\xmldb_table::class, $list['export']);
        // The core tables are there, and every table of the site that an install.xml file describes.
        $this->assertContains('config', $names);
        $this->assertContains('user', $names);
        $this->assertContains('tool_secbackup_runs', $names);
        $expected = array_diff($DB->get_tables(false), ['sessions', 'cache_flags'], $list['unknown']);
        sort($expected, SORT_STRING);
        $this->assertSame(array_values($expected), $names);
    }

    public function test_a_table_with_the_prefix_that_no_install_file_describes_is_unknown(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $manager = $DB->get_manager();
        $table = new \xmldb_table('secbk_handmade');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $manager->create_table($table);
        try {
            $list = table_list::build(false);
            $this->assertSame(['secbk_handmade'], $list['unknown']);
            $this->assertNotContains('secbk_handmade', self::names($list['export']));
            $this->assertNotContains('secbk_handmade', $list['excluded']);
        } finally {
            $manager->drop_table($table);
        }
        $this->assertSame([], table_list::build(false)['unknown']);
    }

    public function test_a_table_without_the_prefix_is_not_counted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $DB->execute('CREATE TABLE secbk_unprefixed (id INT)');
        try {
            $list = table_list::build(true);
            $this->assertNotContains('secbk_unprefixed', $list['unknown']);
            $this->assertNotContains('secbk_unprefixed', self::names($list['export']));
        } finally {
            $DB->execute('DROP TABLE secbk_unprefixed');
        }
    }
}
