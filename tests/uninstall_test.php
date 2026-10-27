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

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_tool_secbackup_uninstall')]
/**
 * Tests for the uninstallation of the plugin.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_tool_secbackup_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    public function test_the_temporary_files_of_the_runs_are_removed(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/admin/tool/secbackup/db/uninstall.php');
        $base = make_request_directory();
        set_config('tempdir', $base, 'tool_secbackup');
        $area = \tool_secbackup\local\backup\temp_area::create();
        file_put_contents($area->for_run(5) . '/chunk', 'data');
        $this->assertTrue(xmldb_tool_secbackup_uninstall());
        $this->assertDirectoryDoesNotExist($base . '/run-5');
        $this->assertDirectoryExists($base);
    }
}
