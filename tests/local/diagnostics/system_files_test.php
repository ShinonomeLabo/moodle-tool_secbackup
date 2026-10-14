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

namespace tool_secbackup\local\diagnostics;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\diagnostics\system_files::class)]
/**
 * Tests for reading /proc and /sys without warnings.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\diagnostics\system_files
 */
final class system_files_test extends \advanced_testcase {
    public function test_reads_a_file_under_the_root(): void {
        $root = make_request_directory();
        mkdir($root . '/sys/fs/selinux', 0777, true);
        file_put_contents($root . '/sys/fs/selinux/enforce', "1\n");
        $this->assertSame("1\n", (new system_files($root))->read('/sys/fs/selinux/enforce'));
    }

    public function test_an_empty_file_is_an_empty_string_and_not_unknown(): void {
        $root = make_request_directory();
        file_put_contents($root . '/empty', '');
        $this->assertSame('', (new system_files($root))->read('/empty'));
    }

    public function test_missing_file_is_unknown_without_warnings(): void {
        $this->assertNull((new system_files(make_request_directory()))->read('/proc/self/attr/current'));
    }

    public function test_directory_is_unknown_without_warnings(): void {
        $root = make_request_directory();
        mkdir($root . '/proc');
        $this->assertNull((new system_files($root))->read('/proc'));
    }

    public function test_a_file_that_cannot_be_read_is_unknown_without_warnings(): void {
        $root = make_request_directory();
        file_put_contents($root . '/secret', 'x');
        chmod($root . '/secret', 0000);
        if (is_readable($root . '/secret')) {
            chmod($root . '/secret', 0600);
            $this->markTestSkipped('This process can read a file without permissions (it runs as root).');
        }
        try {
            $this->assertNull((new system_files($root))->read('/secret'));
        } finally {
            chmod($root . '/secret', 0600);
        }
    }

    public function test_the_error_handler_of_the_caller_is_back_after_a_read(): void {
        $seen = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = $errstr;
            return true;
        });
        try {
            (new system_files(make_request_directory()))->read('/missing');
            (new system_files(make_request_directory()))->read('/missing');
            trigger_error('after the reads', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(['after the reads'], $seen);
    }

    public function test_real_root_reads_this_process(): void {
        $content = (new system_files())->read('/proc/self/mountinfo');
        $this->assertTrue($content === null || str_contains($content, ' - '));
    }
}
