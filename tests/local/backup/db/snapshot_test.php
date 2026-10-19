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
use tool_secbackup\tests\trace_strings;

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
    /** @var string|false The setting of zend.exception_ignore_args before the test. */
    private string|false $ignoreargs;

    protected function setUp(): void {
        parent::setUp();
        // The export reads on its own connection, which sees only what the test has committed.
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // A trace holds the arguments of the calls only while this is off, so the tests of traces need it off.
        $this->ignoreargs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreargs);
        parent::tearDown();
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

    /**
     * Checks that a password is in no text of an exception.
     *
     * @param \Throwable $e The exception.
     * @param string $password The password.
     */
    private function assert_without_password(\Throwable $e, string $password): void {
        $texts = array_merge([$e->getMessage(), $e->getTraceAsString(), (string)($e->debuginfo ?? '')], trace_strings::of($e));
        foreach ($texts as $text) {
            $this->assertStringNotContainsString($password, $text);
        }
        $this->assertNull($e->getPrevious());
    }

    public function test_a_failed_connection_does_not_show_the_password(): void {
        global $CFG;
        $this->resetAfterTest();
        $password = 'wrong-pass-' . bin2hex(random_bytes(6));
        $CFG->dbpass = $password;
        // The database does not exist either: a server with trust authentication accepts any password, and the connection
        // must fail whatever the authentication is, so that the exception it makes is the one that is checked.
        $CFG->dbname = 'secbk_missing_' . bin2hex(random_bytes(4));
        // The driver reports the failure as a PHP warning too.
        $quiet = static fn(int $errno, string $errstr): bool => true;
        set_error_handler($quiet);
        try {
            connection_factory::open();
            $failure = null;
        } catch (\dml_connection_exception $e) {
            $failure = $e;
        } finally {
            restore_error_handler();
        }
        // The assertions run with the handler of PHPUnit, not with the one that only hides the warning.
        $handler = set_error_handler(static fn(int $errno, string $errstr): bool => false);
        restore_error_handler();
        $this->assertNotSame($quiet, $handler);
        // Some drivers report the failed connection with debugging() too.
        $this->resetDebugging();
        $this->assertNotNull($failure, 'The connection was opened with a wrong password');
        $this->assertNotEmpty($failure->getTrace());
        $this->assert_without_password($failure, $password);
    }

    public function test_a_failure_after_the_link_is_up_does_not_show_the_password(): void {
        global $CFG;
        $this->resetAfterTest();
        $password = 'wrong-pass-' . bin2hex(random_bytes(6));
        $CFG->dbpass = $password;
        $failures = [
            'a dml exception' => static fn(): \Throwable => new \dml_read_exception('server version'),
            'a plain exception' => static fn(): \Throwable => new \RuntimeException('server version'),
        ];
        foreach ($failures as $label => $make) {
            $driver = $this->createMock(\moodle_database::class);
            $driver->method('connect')->willReturnCallback(
                // Like a driver, the stand-in has the password as an argument of the call that fails.
                static function (string $host, string $user, string $pass) use ($make): void {
                    throw $make();
                }
            );
            $driver->expects($this->once())->method('dispose');
            try {
                connection_factory::open($driver);
                $this->fail($label . ' did not stop the connection');
            } catch (\dml_connection_exception $e) {
                $this->assertContains($password, trace_strings::of($this->original($driver, $make)), $label);
                $this->assert_without_password($e, $password);
            }
        }
    }

    /**
     * The exception that the stand-in driver throws, made as the driver makes it, to show that its trace holds the password.
     *
     * @param \moodle_database $driver The stand-in.
     * @param \Closure $make Makes the exception.
     * @return \Throwable
     */
    private function original(\moodle_database $driver, \Closure $make): \Throwable {
        global $CFG;
        try {
            $driver->connect('h', 'u', $CFG->dbpass, 'n', 'p');
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('The stand-in driver did not throw');
    }

    public function test_a_driver_that_cannot_be_disposed_still_gives_the_connection_exception(): void {
        $driver = $this->createMock(\moodle_database::class);
        $driver->method('connect')->willThrowException(new \RuntimeException('server version'));
        $driver->method('dispose')->willThrowException(new \RuntimeException('already closed'));
        try {
            connection_factory::open($driver);
            $this->fail('The connection was opened');
        } catch (\dml_connection_exception $e) {
            $this->assertStringContainsString('RuntimeException', (string)$e->debuginfo);
            $this->assertNull($e->getPrevious());
        }
    }
}
