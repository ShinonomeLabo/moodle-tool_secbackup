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

namespace tool_secbackup;

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_tool_secbackup_upgrade')]
/**
 * Tests for the upgrade steps.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_tool_secbackup_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    public function test_upgrading_from_the_first_version_creates_the_runs_table(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_secbackup_runs');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table));
        set_config('version', 2026101202, 'tool_secbackup');

        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026101202));

        $this->assertTrue($dbman->table_exists($table));
        $this->assertEquals(2026102800, get_config('tool_secbackup', 'version'));
        $this->assertSame(0, $DB->count_records('tool_secbackup_runs'));
        $this->assertTrue($dbman->index_exists($table, new \xmldb_index('status', XMLDB_INDEX_NOTUNIQUE, ['status'])));
        $this->assertTrue($dbman->index_exists($table, new \xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated'])));
    }

    public function test_upgrading_a_site_that_has_the_table_changes_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->resetAfterTest();
        set_config('version', 2026101202, 'tool_secbackup');
        $id = $DB->insert_record('tool_secbackup_runs', ['triggeredby' => 'cli', 'stage' => 'prepare', 'status' => 'active']);

        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026101202));

        $this->assertTrue($DB->record_exists('tool_secbackup_runs', ['id' => $id]));
    }

    public function test_a_site_at_the_current_version_is_left_alone(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026102800));
    }

    public function test_upgrading_from_the_second_release_creates_the_restore_stops_table(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_secbackup_restore_stops');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table));
        set_config('version', 2026102000, 'tool_secbackup');

        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026102000));

        $this->assertTrue($dbman->table_exists($table));
        $this->assertEquals(2026102800, get_config('tool_secbackup', 'version'));
        $this->assertTrue($dbman->index_exists($table, new \xmldb_index('kind', XMLDB_INDEX_NOTUNIQUE, ['kind'])));
        $DB->insert_record('tool_secbackup_restore_stops', ['kind' => 'setting', 'name' => 'noemailever', 'previous' => null,
            'timecreated' => 1]);
        $this->assertSame(1, $DB->count_records('tool_secbackup_restore_stops'));

        // Running the step again on a site that has the table changes nothing.
        set_config('version', 2026102000, 'tool_secbackup');
        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026102000));
        $this->assertSame(1, $DB->count_records('tool_secbackup_restore_stops'));
    }

    /**
     * The tables that an upgrade step makes, with the version before the step.
     *
     * @return array
     */
    public static function table_provider(): array {
        return [
            'the runs, from the first version' => ['tool_secbackup_runs', 2026101202],
            'the things that a restore stopped, from the second release' => ['tool_secbackup_restore_stops', 2026102000],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('table_provider')]
    /**
     * A table that an upgrade step makes has the columns that install.xml gives it, in their order.
     *
     * The table of the test site cannot stand for install.xml: the other tests of this class make it again with the upgrade
     * step, and the database keeps what they made. So it is made from install.xml here first.
     *
     * @dataProvider table_provider
     * @param string $table The table.
     * @param int $from The version before the step.
     */
    public function test_the_upgrade_makes_the_columns_of_install_xml(string $table, int $from): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $dbman->drop_table(new \xmldb_table($table));
        $dbman->install_one_table_from_xmldb_file($CFG->dirroot . '/admin/tool/secbackup/db/install.xml', $table);
        $installed = self::columns($table);
        $dbman->drop_table(new \xmldb_table($table));
        set_config('version', $from, 'tool_secbackup');

        $this->assertTrue(\xmldb_tool_secbackup_upgrade($from));

        $this->assertSame($installed, self::columns($table));
    }

    public function test_the_value_before_a_stop_may_be_null_after_the_upgrade(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/upgrade.php');
        $this->resetAfterTest();
        $DB->get_manager()->drop_table(new \xmldb_table('tool_secbackup_restore_stops'));
        set_config('version', 2026102000, 'tool_secbackup');

        $this->assertTrue(\xmldb_tool_secbackup_upgrade(2026102000));

        // A setting that the restored site did not have is recorded with no value.
        $columns = self::columns('tool_secbackup_restore_stops');
        $this->assertFalse($columns['previous']['not_null']);
        $this->assertSame('X', $columns['previous']['meta_type']);
        foreach (['kind', 'name', 'timecreated'] as $name) {
            $this->assertTrue($columns[$name]['not_null'], $name);
        }
    }

    /**
     * What the database says of the columns of a table, read again from it.
     *
     * @param string $table The table.
     * @return array[] By name: the type, the length, whether it is not null, its default and whether it is a sequence.
     */
    private static function columns(string $table): array {
        global $DB;
        $columns = [];
        foreach ($DB->get_columns($table, false) as $name => $column) {
            $columns[$name] = [
                'meta_type' => $column->meta_type,
                'max_length' => $column->max_length,
                'not_null' => $column->not_null,
                'has_default' => $column->has_default,
                'default_value' => $column->default_value,
                'auto_increment' => $column->auto_increment,
            ];
        }
        return $columns;
    }
}
