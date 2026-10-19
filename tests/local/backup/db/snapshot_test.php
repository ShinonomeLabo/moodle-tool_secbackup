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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\snapshot_mysql::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\snapshot_pgsql::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\connection_factory::class)]
/**
 * Tests for the connection of the export and the reading of the database at one point in time.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\db\snapshot_mysql
 * @covers     \tool_secbackup\local\backup\db\snapshot_pgsql
 * @covers     \tool_secbackup\local\backup\db\connection_factory
 */
final class snapshot_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // The export reads on its own connection, which sees only what the test has committed.
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    /**
     * A row for config_log.
     *
     * @param string $name Name of the setting.
     * @return \stdClass
     */
    private static function log_row(string $name): \stdClass {
        return (object)['userid' => 0, 'timemodified' => 1, 'plugin' => 'tool_secbackup', 'name' => $name, 'value' => 'v'];
    }

    public function test_rows_added_on_another_connection_are_not_seen(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('config_log', self::log_row('before'));
        $export = connection_factory::open();
        try {
            $this->assertNotSame($DB, $export);
            $snapshot = connection_factory::snapshot_for($export);
            $seen = $DB->count_records('config_log');
            $snapshot->begin($export);
            // The connection has read nothing yet: the point in time is that of begin(), not that of the first read.
            $DB->insert_record('config_log', self::log_row('during'));
            $this->assertSame($seen, $export->count_records('config_log'));
            $this->assertFalse($export->record_exists('config_log', ['name' => 'during']));
            $this->assertTrue($DB->record_exists('config_log', ['name' => 'during']));
            $snapshot->end($export);
            // Once the transaction has ended, the connection reads the present again.
            $this->assertTrue($export->record_exists('config_log', ['name' => 'during']));
        } finally {
            $export->dispose();
        }
    }

    public function test_the_snapshot_is_for_the_database_family(): void {
        global $DB;
        $export = connection_factory::open();
        try {
            $snapshot = connection_factory::snapshot_for($export);
            $this->assertInstanceOf(
                $DB->get_dbfamily() === 'postgres' ? snapshot_pgsql::class : snapshot_mysql::class,
                $snapshot
            );
        } finally {
            $export->dispose();
        }
    }

    public function test_the_statements_of_each_family(): void {
        $expected = [
            'mysql' => [
                snapshot_mysql::class,
                ['SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'START TRANSACTION WITH CONSISTENT SNAPSHOT'],
            ],
            'postgres' => [snapshot_pgsql::class, ['BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY', 'SELECT 1']],
        ];
        foreach ($expected as $family => [$class, $begin]) {
            $sent = [];
            $db = $this->createMock(\moodle_database::class);
            $db->method('get_dbfamily')->willReturn($family);
            $db->method('execute')->willReturnCallback(function (string $sql) use (&$sent): bool {
                $sent[] = $sql;
                return true;
            });
            $snapshot = connection_factory::snapshot_for($db);
            $this->assertInstanceOf($class, $snapshot);
            $snapshot->begin($db);
            $this->assertSame($begin, $sent, $family);
            $sent = [];
            $snapshot->end($db);
            $this->assertSame(['ROLLBACK'], $sent, $family);
        }
    }

    public function test_an_unknown_database_is_refused(): void {
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_dbfamily')->willReturn('mssql');
        try {
            connection_factory::snapshot_for($db);
            $this->fail('A database that is not supported was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('db_unsupported', $e->errorcode);
        }
    }

    public function test_options_leave_out_the_read_replica_and_the_persistent_link(): void {
        $options = connection_factory::options([
            'dbcollation' => 'utf8mb4_unicode_ci',
            'readonly' => ['instance' => [['dbhost' => 'replica']]],
            'dbpersist' => true,
            'fetchbuffersize' => 100,
        ]);
        $this->assertSame(['dbcollation' => 'utf8mb4_unicode_ci', 'dbpersist' => false, 'fetchbuffersize' => 100], $options);
        $this->assertSame(['dbpersist' => false], connection_factory::options([]));
    }

    public function test_the_connection_of_the_export_is_opened_with_those_options(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->dboptions = array_merge($CFG->dboptions ?? [], [
            'dbpersist' => true,
            'readonly' => ['instance' => [['dbhost' => 'unused.invalid']]],
        ]);
        $export = connection_factory::open();
        try {
            $this->assertTrue($export->record_exists('config', ['name' => 'version']));
            $options = (new \ReflectionProperty(\moodle_database::class, 'dboptions'))->getValue($export);
            $this->assertArrayNotHasKey('readonly', $options);
            $this->assertEmpty($options['dbpersist']);
        } finally {
            $export->dispose();
        }
    }

    public function test_an_unknown_driver_is_refused(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->dbtype = 'nosuchdb';
        try {
            connection_factory::open();
            $this->fail('A driver that does not exist was accepted');
        } catch (plugin_exception $e) {
            $this->assertSame('db_unsupported', $e->errorcode);
        }
    }

    public function test_a_failed_connection_does_not_show_the_password(): void {
        global $CFG;
        $this->resetAfterTest();
        $password = 'wrong-pass-' . bin2hex(random_bytes(6));
        $CFG->dbpass = $password;
        // The driver reports the failure as a PHP warning too.
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            connection_factory::open();
            $this->fail('The connection was opened with a wrong password');
        } catch (\dml_connection_exception $e) {
            $this->assertNotEmpty($e->getTrace());
            $strings = [$e->getMessage(), (string)$e->debuginfo, $e->getTraceAsString()];
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                array_walk_recursive($x->getTrace(), static function ($value) use (&$strings): void {
                    $value = $value instanceof \SensitiveParameterValue ? $value->getValue() : $value;
                    $strings[] = is_string($value) ? $value : '';
                });
            }
            foreach ($strings as $text) {
                $this->assertStringNotContainsString($password, $text);
            }
            $this->assertNull($e->getPrevious());
        } finally {
            restore_error_handler();
            // Some drivers report the failed connection with debugging() too.
            $this->resetDebugging();
        }
    }
}
