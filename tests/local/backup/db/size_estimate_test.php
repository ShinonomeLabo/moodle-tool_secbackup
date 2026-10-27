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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\db\size_estimate::class)]
/**
 * Tests for the estimate of the size of the tables.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\db\size_estimate
 */
final class size_estimate_test extends \advanced_testcase {
    /**
     * Tables, by name.
     *
     * @param string ...$names Names.
     * @return \xmldb_table[]
     */
    private static function tables(string ...$names): array {
        return array_map(static fn(string $name): \xmldb_table => new \xmldb_table($name), $names);
    }

    public function test_the_size_of_tables_that_have_rows_is_more_than_nothing(): void {
        global $DB;
        $one = size_estimate::bytes($DB, self::tables('config'));
        $this->assertGreaterThan(0, $one);
        $two = size_estimate::bytes($DB, self::tables('config', 'user'));
        $this->assertGreaterThanOrEqual($one, $two);
        $this->assertGreaterThan($one, size_estimate::bytes($DB, self::tables('config', 'files', 'course', 'user')));
    }

    public function test_the_size_is_the_same_on_the_connection_of_the_export(): void {
        global $DB;
        $export = connection_factory::open();
        try {
            $this->assertSame(
                size_estimate::bytes($DB, self::tables('config', 'user')),
                size_estimate::bytes($export, self::tables('config', 'user'))
            );
        } finally {
            $export->dispose();
        }
    }

    public function test_no_tables_and_unknown_tables_are_nothing(): void {
        global $DB;
        $this->assertSame(0, size_estimate::bytes($DB, []));
        $this->assertSame(0, size_estimate::bytes($DB, self::tables('no_such_table')));
    }

    /**
     * A connection that is a stand-in.
     *
     * @param string $family Family of the database.
     * @param \Closure|null $answer What get_field_sql() does.
     * @return \moodle_database
     */
    private function stand_in(string $family, ?\Closure $answer = null): \moodle_database {
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_prefix')->willReturn('m_');
        $db->method('get_dbfamily')->willReturn($family);
        $db->method('get_in_or_equal')->willReturn(['IN (?)', ['m_config']]);
        if ($answer !== null) {
            $db->method('get_field_sql')->willReturnCallback($answer);
        }
        return $db;
    }

    public function test_a_database_that_does_not_answer_is_nothing(): void {
        foreach (['mysql', 'postgres'] as $family) {
            $db = $this->stand_in($family, static function (): void {
                throw new \dml_read_exception('timeout');
            });
            $this->assertSame(0, size_estimate::bytes($db, self::tables('config')), $family);
        }
    }

    public function test_an_answer_that_is_not_a_number_is_nothing(): void {
        foreach ([null, false, 'n/a'] as $answer) {
            $db = $this->stand_in('postgres', static fn() => $answer);
            $this->assertSame(0, size_estimate::bytes($db, self::tables('config')));
        }
        $this->assertSame(123456, size_estimate::bytes($this->stand_in('mysql', static fn() => '123456'), self::tables('config')));
    }

    public function test_a_database_of_another_family_is_nothing(): void {
        $db = $this->stand_in('mssql', static fn() => '99');
        $this->assertSame(0, size_estimate::bytes($db, self::tables('config')));
    }
}
