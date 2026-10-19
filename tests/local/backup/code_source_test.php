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
 * Tests for the roots of the added plugins.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\code_source::class)]
/**
 * Tests for the roots of the added plugins.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\code_source
 */
final class code_source_test extends \advanced_testcase {
    public function test_the_added_plugins_are_roots_and_the_standard_ones_are_not(): void {
        $roots = code_source::roots();
        $this->assertArrayHasKey('tool_secbackup', $roots);
        $this->assertSame(
            \core_plugin_manager::instance()->get_plugin_info('tool_secbackup')->rootdir,
            $roots['tool_secbackup']
        );
        $this->assertDirectoryExists($roots['tool_secbackup']);
        $this->assertArrayNotHasKey('mod_forum', $roots);
        $this->assertArrayNotHasKey('tool_task', $roots);
        foreach (array_keys($roots) as $component) {
            $this->assertFalse(\core_plugin_manager::instance()->get_plugin_info($component)->is_standard(), $component);
        }
        $keys = array_keys($roots);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_the_versions_list_every_plugin_with_the_version_of_the_database(): void {
        $versions = code_source::versions();
        $byname = array_column($versions, null, 'component');
        $this->assertCount(count($versions), $byname);
        $this->assertSame(
            ['component' => 'tool_secbackup', 'version' => (string) get_config('tool_secbackup', 'version'), 'standard' => false],
            $byname['tool_secbackup']
        );
        $this->assertTrue($byname['mod_forum']['standard']);
        $this->assertSame((string) get_config('mod_forum', 'version'), $byname['mod_forum']['version']);
        foreach ($versions as $entry) {
            $this->assertSame(['component', 'version', 'standard'], array_keys($entry));
            $this->assertIsBool($entry['standard']);
        }
        $components = array_column($versions, 'component');
        $sorted = $components;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $components);
    }

    public function test_a_plugin_that_is_missing_from_the_disk_has_no_root_but_has_a_version(): void {
        $this->resetAfterTest();
        set_config('version', 2026010100, 'local_ghost');
        \core_plugin_manager::reset_caches();
        $this->assertArrayNotHasKey('local_ghost', code_source::roots());
        $byname = array_column(code_source::versions(), null, 'component');
        $this->assertSame(
            ['component' => 'local_ghost', 'version' => '2026010100', 'standard' => false],
            $byname['local_ghost']
        );
        foreach (code_source::roots() as $dir) {
            $this->assertIsString($dir);
            $this->assertDirectoryExists($dir);
        }
    }

    /**
     * A stand-in for a plugin.
     *
     * @param string|null $rootdir Its folder.
     * @return object
     */
    private static function plugin(?string $rootdir): object {
        return new class ($rootdir) {
            /** @var string|null Folder. */
            public ?string $rootdir;

            /**
             * Constructor.
             *
             * @param string|null $rootdir Folder.
             */
            public function __construct(?string $rootdir) {
                $this->rootdir = $rootdir;
            }

            /**
             * Added plugins only are given.
             *
             * @return bool
             */
            public function is_standard(): bool {
                return false;
            }
        };
    }

    public function test_a_plugin_inside_the_folder_of_another_is_not_a_root_of_its_own(): void {
        $base = realpath(make_request_directory());
        foreach (['parent/sub/child', 'parentx', 'other'] as $dir) {
            mkdir($base . '/' . $dir, 0777, true);
        }
        symlink($base . '/parent', $base . '/viaLink');
        $plugins = [
            'local_child' => self::plugin($base . '/parent/sub/child'),
            'local_linked' => self::plugin($base . '/viaLink/sub'),
            'local_other' => self::plugin($base . '/other'),
            'local_parent' => self::plugin($base . '/parent'),
            'local_parentx' => self::plugin($base . '/parentx'),
            'local_same' => self::plugin($base . '/other'),
            'local_gone' => self::plugin($base . '/gone'),
            'local_null' => self::plugin(null),
        ];
        $roots = code_source::roots($plugins);
        $this->assertSame(['local_other', 'local_parent', 'local_parentx'], array_keys($roots));
        $this->assertSame($base . '/parent', $roots['local_parent']);
    }
}
