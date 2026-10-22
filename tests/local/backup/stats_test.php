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

/**
 * Tests for the statistics that are sent to the backup service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\stats::class)]
/**
 * Tests for the statistics that are sent to the backup service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\stats
 */
final class stats_test extends \advanced_testcase {
    /** @var string[] A valid description of the Moodle program. */
    private const MOODLE = ['release' => '4.5.1 (Build: 20250113)', 'version' => '2024100701.10', 'build' => '20250113'];

    /** @var int[] Valid sizes. */
    private const BYTES = ['db' => 1, 'files' => 2, 'dataroot' => 3, 'code' => 4];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Collects with valid values, except for what a test gives.
     *
     * @param array $tables Rows of the tables.
     * @param array $bytes Sizes.
     * @param int $seconds Duration.
     * @param array $moodle The Moodle program.
     * @return array
     */
    private function collect(
        array $tables = [],
        array $bytes = self::BYTES,
        int $seconds = 5,
        array $moodle = self::MOODLE
    ): array {
        return stats::collect($tables, $bytes, $seconds, $moodle, false, false);
    }

    public function test_the_keys_and_the_plain_values(): void {
        $result = stats::collect(['user' => 3], self::BYTES, 12, self::MOODLE, true, true);
        $this->assertSame([
            'users_active_365d', 'courses', 'auth_methods', 'tables', 'bytes', 'duration_seconds', 'moodle', 'core_modified',
            'external_storage',
        ], array_keys($result));
        $this->assertSame(self::BYTES, $result['bytes']);
        $this->assertSame(12, $result['duration_seconds']);
        $this->assertTrue($result['core_modified']);
        $this->assertTrue($result['external_storage']);
        $this->assertSame(['user' => 3], (array) $result['tables']);
        $this->assertSame(['release', 'version', 'build', 'db_family'], array_keys($result['moodle']));
        $this->assertSame('4.5.1 (Build: 20250113)', $result['moodle']['release']);
        $this->assertSame('2024100701.10', $result['moodle']['version']);
        $this->assertSame('20250113', $result['moodle']['build']);
    }

    public function test_the_two_flags_are_taken_as_they_are(): void {
        $result = stats::collect([], self::BYTES, 1, self::MOODLE, false, false);
        $this->assertFalse($result['core_modified']);
        $this->assertFalse($result['external_storage']);
    }

    public function test_users_and_courses_are_counted(): void {
        global $DB;
        $before = stats::collect([], self::BYTES, 1, self::MOODLE, false, false);
        $generator = $this->getDataGenerator();
        $recent = $generator->create_user(['firstname' => 'Taro', 'lastname' => 'Yamada', 'email' => 'taro@example.test']);
        $old = $generator->create_user();
        $deleted = $generator->create_user();
        $never = $generator->create_user();
        $DB->set_field('user', 'lastaccess', time() - 10, ['id' => $recent->id]);
        $DB->set_field('user', 'lastaccess', time() - 366 * 86400, ['id' => $old->id]);
        $DB->set_field('user', 'lastaccess', time() - 10, ['id' => $deleted->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $DB->set_field('user', 'lastaccess', 0, ['id' => $never->id]);
        $generator->create_course();
        $generator->create_course();
        $after = stats::collect([], self::BYTES, 1, self::MOODLE, false, false);
        $this->assertSame($before['users_active_365d'] + 1, $after['users_active_365d']);
        $this->assertSame($before['courses'] + 2, $after['courses']);
        $this->assertSame(0, $before['courses']);
    }

    public function test_no_personal_data_is_in_the_result(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(
            ['firstname' => 'Hanako', 'lastname' => 'Suzuki', 'email' => 'hanako@example.test', 'username' => 'hsuzuki']
        );
        $DB->set_field('user', 'lastaccess', time(), ['id' => $user->id]);
        $this->getDataGenerator()->create_course(['fullname' => 'Secret Course Name', 'shortname' => 'SCN']);
        $json = json_encode($this->collect(['user' => 1]));
        foreach (['Hanako', 'Suzuki', 'example.test', 'hsuzuki', 'Secret Course', 'SCN'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_auth_names_are_valid_unique_sorted_and_at_most_thirty(): void {
        $names = [];
        for ($i = 0; $i < 35; $i++) {
            $names[] = sprintf('auth_%02d', $i);
        }
        $result = stats::auth_names(array_merge(['manual', 'manual', 'Bad-Name', str_repeat('a', 41)], array_reverse($names)));
        $this->assertSame(array_slice($names, 0, 30), $result);
        $this->assertSame(['manual'], stats::auth_names(['manual', 'Bad-Name', str_repeat('a', 41)]));
        $this->assertSame(['email', 'manual'], stats::auth_names(['manual', 'email', 'manual']));
        $this->assertSame([str_repeat('a', 40)], stats::auth_names([str_repeat('a', 40), str_repeat('a', 41), '']));
    }

    public function test_the_enabled_auth_methods_of_this_site_are_reported(): void {
        global $CFG;
        $CFG->auth = 'manual,email,manual';
        $this->assertSame(['email', 'manual', 'nologin'], $this->collect()['auth_methods']);
    }

    public function test_tables_are_checked_and_cut_to_two_thousand_by_rows(): void {
        $tables = ['Bad-Name' => 5, 'negative' => -1, 'text' => '3', 'float' => 1.5, str_repeat('a', 65) => 1, 'ok_zero' => 0];
        for ($i = 0; $i < 2000; $i++) {
            $tables[sprintf('t_%04d', $i)] = $i + 1;
        }
        $tables['tie_b'] = 1;
        $tables['tie_a'] = 1;
        $kept = (array) $this->collect($tables)['tables'];
        $this->assertCount(2000, $kept);
        $this->assertSame(2000, $kept['t_1999']);
        $this->assertArrayNotHasKey('Bad-Name', $kept);
        $this->assertArrayNotHasKey('negative', $kept);
        $this->assertArrayNotHasKey('text', $kept);
        $this->assertArrayNotHasKey('float', $kept);
        $this->assertArrayNotHasKey('ok_zero', $kept);
        // Three tables have 1 row (t_0000, tie_a, tie_b) and ok_zero has none: 2003 are valid, so ok_zero and the two
        // tables with 1 row whose names sort last are cut.
        $this->assertArrayHasKey('t_0000', $kept);
        $this->assertArrayNotHasKey('tie_a', $kept);
        $this->assertArrayNotHasKey('tie_b', $kept);
        $names = array_keys($kept);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function test_tables_are_a_json_object_even_when_empty(): void {
        $this->assertStringContainsString('"tables":{}', json_encode($this->collect([])));
        $this->assertStringContainsString('"tables":{"a":1}', json_encode($this->collect(['a' => 1])));
        $this->assertStringContainsString('"tables":{}', json_encode($this->collect(['Bad' => 1])));
    }

    public function test_bytes_must_have_exactly_the_four_keys_with_valid_integers(): void {
        $max = 9007199254740991;
        $this->assertSame(
            ['db' => 0, 'files' => $max, 'dataroot' => 0, 'code' => $max],
            $this->collect([], ['db' => 0, 'files' => $max, 'dataroot' => 0, 'code' => $max])['bytes']
        );
        $bad = [
            ['db' => 1, 'files' => 2, 'dataroot' => 3],
            self::BYTES + ['extra' => 1],
            ['db' => 1, 'files' => 2, 'dataroot' => 3, 'codes' => 4],
            ['db' => -1, 'files' => 2, 'dataroot' => 3, 'code' => 4],
            ['db' => $max + 1, 'files' => 2, 'dataroot' => 3, 'code' => 4],
            ['db' => '1', 'files' => 2, 'dataroot' => 3, 'code' => 4],
            ['db' => 1.0, 'files' => 2, 'dataroot' => 3, 'code' => 4],
        ];
        foreach ($bad as $bytes) {
            try {
                $this->collect([], $bytes);
                $this->fail('A coding_exception was expected for ' . json_encode($bytes));
            } catch (\coding_exception $e) {
                $this->assertInstanceOf(\coding_exception::class, $e);
            }
        }
    }

    public function test_the_duration_is_clamped(): void {
        $this->assertSame(0, $this->collect([], self::BYTES, -5)['duration_seconds']);
        $this->assertSame(2592000, $this->collect([], self::BYTES, 99999999)['duration_seconds']);
        $this->assertSame(2592000, $this->collect([], self::BYTES, 2592000)['duration_seconds']);
    }

    public function test_db_family_is_one_of_the_three_and_matches_this_database(): void {
        global $DB;
        $family = $this->collect()['moodle']['db_family'];
        if ($DB->get_dbfamily() === 'postgres') {
            $this->assertSame('postgres', $family);
        } else if ($DB->get_dbvendor() === 'mariadb') {
            $this->assertSame('mariadb', $family);
        } else {
            $this->assertSame('mysql', $family);
        }
    }

    public function test_the_release_is_cleaned_and_cut(): void {
        $moodle = ['release' => "4.5\u{0}.1\n (Build: 20250113)\u{200B}"] + self::MOODLE;
        $this->assertSame('4.5.1 (Build: 20250113)', $this->collect([], self::BYTES, 1, $moodle)['moodle']['release']);
        $long = ['release' => str_repeat("\u{3042}", 150)] + self::MOODLE;
        $this->assertSame(str_repeat("\u{3042}", 100), $this->collect([], self::BYTES, 1, $long)['moodle']['release']);
        $empty = ['release' => "\n\u{0}"] + self::MOODLE;
        $this->assertSame('2024100701.10', $this->collect([], self::BYTES, 1, $empty)['moodle']['release']);
        $invalid = ['release' => "\xff\xfe"] + self::MOODLE;
        $this->assertSame('2024100701.10', $this->collect([], self::BYTES, 1, $invalid)['moodle']['release']);
    }

    public function test_version_and_build_are_checked(): void {
        $short = $this->collect([], self::BYTES, 1, ['version' => '2024100701'] + self::MOODLE);
        $this->assertSame('2024100701', $short['moodle']['version']);
        $bad = [
            ['version' => '202410070'], ['version' => '2024100701.1'], ['version' => "2024100701.10\n"],
            ['build' => '2025011'], ['build' => "20250113\n"], ['build' => 'abcdefgh'],
        ];
        foreach ($bad as $change) {
            try {
                $this->collect([], self::BYTES, 1, $change + self::MOODLE);
                $this->fail('A coding_exception was expected for ' . json_encode($change));
            } catch (\coding_exception $e) {
                $this->assertInstanceOf(\coding_exception::class, $e);
            }
        }
    }

    public function test_fit_keeps_everything_that_fits(): void {
        $stats = $this->collect(['a' => 1, 'b' => 2]);
        $size = strlen(stats::encode($stats));
        $this->assertEquals($stats, stats::fit($stats, $size));
    }

    public function test_fit_removes_the_tables_with_the_fewest_rows_first_and_ties_by_name_descending(): void {
        $stats = $this->collect(['a' => 1, 'b' => 1, 'c' => 5, 'd' => 9]);
        $full = strlen(stats::encode($stats));
        $one = (array) stats::fit($stats, $full - 1)['tables'];
        $this->assertSame(['a' => 1, 'c' => 5, 'd' => 9], $one);
        $two = (array) stats::fit($stats, $full - 1 - strlen('"a":1,'))['tables'];
        $this->assertSame(['c' => 5, 'd' => 9], $two);
    }

    public function test_fit_never_removes_other_keys_and_empties_the_tables_if_it_must(): void {
        $stats = $this->collect(['a' => 1]);
        $fitted = stats::fit($stats, 1);
        $this->assertSame([], (array) $fitted['tables']);
        $this->assertStringContainsString('"tables":{}', stats::encode($fitted));
        $stats['tables'] = (object) [];
        $this->assertEquals($stats, stats::fit($stats, 1));
        unset($fitted['tables']);
        $expected = $this->collect([]);
        unset($expected['tables']);
        $this->assertSame(array_keys($expected), array_keys($fitted));
        $this->assertEquals($expected, $fitted);
    }

    public function test_fit_brings_two_thousand_long_table_names_under_the_limit(): void {
        $tables = [];
        for ($i = 0; $i < 2000; $i++) {
            $tables[str_pad(sprintf('t%04d_', $i), 64, 'x')] = $i;
        }
        $stats = $this->collect($tables);
        $this->assertCount(2000, (array) $stats['tables']);
        $this->assertGreaterThan(60000, strlen(stats::encode($stats)));
        $fitted = stats::fit($stats, 60000);
        $size = strlen(stats::encode($fitted));
        $this->assertLessThanOrEqual(60000, $size);
        $kept = (array) $fitted['tables'];
        $this->assertLessThan(2000, count($kept));
        $this->assertArrayHasKey(str_pad('t1999_', 64, 'x'), $kept);
        $this->assertArrayNotHasKey(str_pad('t0000_', 64, 'x'), $kept);
        // One table more would not have fitted: the cut is as small as it can be.
        $more = $kept;
        $next = str_pad(sprintf('t%04d_', 2000 - count($kept) - 1), 64, 'x');
        $more[$next] = 2000 - count($kept) - 1;
        $withmore = $fitted;
        $withmore['tables'] = (object) $more;
        $this->assertGreaterThan(60000, strlen(stats::encode($withmore)));
    }

    public function test_table_ties_are_ordered_by_byte_comparison_of_the_names(): void {
        $tables = ['9' => 1, '10' => 1];
        for ($i = 0; $i < 1999; $i++) {
            $tables[sprintf('f_%04d', $i)] = 5;
        }
        $kept = (array) $this->collect($tables)['tables'];
        $this->assertCount(2000, $kept);
        $this->assertArrayHasKey('10', $kept);
        $this->assertArrayNotHasKey('9', $kept);
    }

    public function test_encode_throws_when_the_statistics_cannot_be_encoded(): void {
        $stats = $this->collect();
        $stats['bad'] = NAN;
        $this->expectException(\coding_exception::class);
        stats::encode($stats);
    }
}
