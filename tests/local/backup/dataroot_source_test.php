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
 * Tests for the roots of the data directory that are backed up.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\dataroot_source::class)]
/**
 * Tests for the roots of the data directory that are backed up.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\dataroot_source
 */
final class dataroot_source_test extends \advanced_testcase {
    /** @var string[] Folders that are never backed up with the rest of the data directory. */
    private const FIXED = ['cache', 'filedir', 'localcache', 'lock', 'sessions', 'temp', 'trashdir'];

    /**
     * A data directory of its own for the test, with the settings of Moodle that point into it.
     *
     * @return string The real path of the folder.
     */
    private function dataroot(): string {
        global $CFG;
        $this->resetAfterTest();
        $real = realpath(make_request_directory());
        $CFG->dataroot = $real;
        $CFG->tempdir = $real . '/temp';
        $CFG->cachedir = $real . '/cache';
        $CFG->localcachedir = $real . '/localcache';
        $CFG->backuptempdir = $real . '/temp/backup';
        $CFG->trashdir = $real . '/trashdir';
        unset($CFG->secretdataroot);
        unset_config('tempdir', 'tool_secbackup');
        return $real;
    }

    /**
     * The excluded paths of the root of the data directory, in order.
     *
     * @return string[]
     */
    private static function excluded(): array {
        $roots = dataroot_source::roots();
        $excluded = $roots['dataroot'][1];
        $sorted = $excluded;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $excluded);
        return $excluded;
    }

    public function test_the_root_is_the_data_directory_with_the_fixed_folders_left_out(): void {
        global $CFG;
        $real = $this->dataroot();
        $roots = dataroot_source::roots();
        $this->assertSame(['dataroot'], array_keys($roots));
        $this->assertSame($CFG->dataroot, $roots['dataroot'][0]);
        $this->assertSame(self::FIXED, $roots['dataroot'][1]);
        $this->assertDirectoryExists($real);
    }

    public function test_secret_is_never_left_out(): void {
        $real = $this->dataroot();
        mkdir($real . '/secret');
        $this->assertNotContains('secret', self::excluded());
    }

    public function test_folders_of_moodle_that_are_inside_the_data_directory_are_left_out(): void {
        global $CFG;
        $real = $this->dataroot();
        foreach (['mytemp', 'mycache', 'mylocal', 'mybackup', 'mytrash', 'plugintemp/run'] as $dir) {
            mkdir($real . '/' . $dir, 0777, true);
        }
        $CFG->tempdir = $real . '/mytemp';
        $CFG->cachedir = $real . '/mycache';
        $CFG->localcachedir = $real . '/mylocal';
        $CFG->backuptempdir = $real . '/mybackup';
        $CFG->trashdir = $real . '/mytrash';
        set_config('tempdir', $real . '/plugintemp/run', 'tool_secbackup');
        $expected = array_merge(
            self::FIXED,
            ['mybackup', 'mycache', 'mylocal', 'mytemp', 'mytrash', 'plugintemp/run']
        );
        sort($expected, SORT_STRING);
        $this->assertSame($expected, self::excluded());
    }

    public function test_a_folder_outside_or_missing_adds_nothing(): void {
        global $CFG;
        $real = $this->dataroot();
        $outside = realpath(make_request_directory());
        $CFG->tempdir = $outside;
        $CFG->cachedir = $real . '/missing-cache';
        $CFG->localcachedir = $real . '-sibling';
        $CFG->backuptempdir = $real;
        unset($CFG->trashdir);
        set_config('tempdir', $outside, 'tool_secbackup');
        $this->assertSame(self::FIXED, self::excluded());
    }

    public function test_a_data_directory_that_is_a_link_is_compared_by_its_real_path(): void {
        global $CFG;
        $real = $this->dataroot();
        mkdir($real . '/viatemp');
        $link = make_request_directory() . '/link';
        symlink($real, $link);
        $CFG->dataroot = $link;
        $CFG->tempdir = $link . '/viatemp';
        $CFG->cachedir = $real . '/viatemp';
        $roots = dataroot_source::roots();
        $this->assertSame($link, $roots['dataroot'][0]);
        $expected = array_merge(self::FIXED, ['viatemp']);
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $roots['dataroot'][1]);
    }

    public function test_a_secret_directory_outside_the_data_directory_is_a_root_of_its_own(): void {
        global $CFG;
        $this->dataroot();
        $secret = realpath(make_request_directory());
        $CFG->secretdataroot = $secret;
        $roots = dataroot_source::roots();
        $this->assertSame(['dataroot', 'secret'], array_keys($roots));
        $this->assertSame([$secret, []], $roots['secret']);
    }

    public function test_a_secret_directory_inside_the_data_directory_is_not_a_second_root(): void {
        global $CFG;
        $real = $this->dataroot();
        mkdir($real . '/hidden');
        $CFG->secretdataroot = $real . '/hidden';
        $this->assertSame(['dataroot'], array_keys(dataroot_source::roots()));
        $CFG->secretdataroot = $real;
        $this->assertSame(['dataroot'], array_keys(dataroot_source::roots()));
    }

    public function test_a_secret_directory_that_does_not_exist_is_not_a_root(): void {
        global $CFG;
        $this->dataroot();
        $CFG->secretdataroot = make_request_directory() . '/missing';
        $this->assertSame(['dataroot'], array_keys(dataroot_source::roots()));
    }

    public function test_the_secret_folder_is_named_as_the_lists_of_a_backup_name_it(): void {
        global $CFG;
        $real = $this->dataroot();
        // Not set: the folder secret of the data directory, whether it exists yet or not.
        $this->assertSame(['dataroot:secret'], dataroot_source::secret_names());
        mkdir($real . '/secret');
        $this->assertSame(['dataroot:secret'], dataroot_source::secret_names());
        // Set to a folder inside the data directory: its path there.
        mkdir($real . '/keys/site', 0777, true);
        $CFG->secretdataroot = $real . '/keys/site';
        $this->assertSame(['dataroot:keys/site'], dataroot_source::secret_names());
        // Set to a folder outside it: a root of its own.
        $CFG->secretdataroot = realpath(make_request_directory());
        $this->assertSame(['secret:'], dataroot_source::secret_names());
        // A data directory that does not exist.
        unset($CFG->secretdataroot);
        $CFG->dataroot = $real . '/missing';
        $this->assertSame(['dataroot:secret'], dataroot_source::secret_names());
    }

    public function test_a_name_is_in_the_secret_folder_when_it_is_the_folder_or_under_it(): void {
        $inside = ['dataroot:keys/site'];
        $this->assertTrue(dataroot_source::in_secret('dataroot:keys/site', $inside));
        $this->assertTrue(dataroot_source::in_secret('dataroot:keys/site/key/aes-256-gcm.key', $inside));
        $this->assertFalse(dataroot_source::in_secret('dataroot:keys/site-old/key', $inside));
        $this->assertFalse(dataroot_source::in_secret('dataroot:keys', $inside));
        $this->assertFalse(dataroot_source::in_secret('secret:key', $inside));
        $outside = ['secret:'];
        $this->assertTrue(dataroot_source::in_secret('secret:', $outside));
        $this->assertTrue(dataroot_source::in_secret('secret:key/aes-256-gcm.key', $outside));
        $this->assertFalse(dataroot_source::in_secret('dataroot:secret', $outside));
        $this->assertFalse(dataroot_source::in_secret('dataroot:secret', []));
    }

    public function test_the_place_of_a_folder_for_the_temporary_files(): void {
        global $CFG;
        $real = $this->dataroot();
        mkdir($real . '/coursework');
        mkdir($real . '/temp/tool_secbackup', 0777, true);
        mkdir($real . '/cache/x', 0777, true);
        $this->assertSame('dataroot', dataroot_source::place_of($real));
        $this->assertSame('dataroot', dataroot_source::place_of($real . '/'));
        $this->assertSame('backedup', dataroot_source::place_of($real . '/coursework'));
        // Folders that every backup leaves out, and folders that do not exist yet or lie elsewhere.
        $this->assertNull(dataroot_source::place_of($real . '/temp/tool_secbackup'));
        $this->assertNull(dataroot_source::place_of($real . '/cache'));
        $this->assertNull(dataroot_source::place_of($real . '/cache/x'));
        $this->assertNull(dataroot_source::place_of($real . '/new'));
        $this->assertNull(dataroot_source::place_of(realpath(make_request_directory())));
        $CFG->dataroot = $real . '/missing';
        $this->assertNull(dataroot_source::place_of($real . '/coursework'));
    }

    public function test_the_data_directory_that_does_not_exist_leaves_the_names_as_they_are(): void {
        global $CFG;
        $this->dataroot();
        $CFG->dataroot = make_request_directory() . '/missing';
        $roots = dataroot_source::roots();
        $this->assertSame(self::FIXED, $roots['dataroot'][1]);
    }
}
