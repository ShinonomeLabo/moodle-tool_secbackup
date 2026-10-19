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
}
