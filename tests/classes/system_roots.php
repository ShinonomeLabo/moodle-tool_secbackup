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

namespace tool_secbackup\tests;

/**
 * Folders that stand in for the root of /proc and /sys, for the tests of the diagnostics.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class system_roots {
    /**
     * A root with SELinux files and a mount table where the data directory is on NFS.
     *
     * @param string $enforce Content of /sys/fs/selinux/enforce.
     * @return string
     */
    public static function nfs(string $enforce): string {
        global $CFG;
        $root = make_request_directory();
        mkdir($root . '/sys/fs/selinux', 0777, true);
        mkdir($root . '/proc/self/attr', 0777, true);
        file_put_contents($root . '/sys/fs/selinux/enforce', $enforce . "\n");
        file_put_contents($root . '/proc/self/attr/current', "system_u:system_r:httpd_t:s0\0");
        $dataroot = realpath($CFG->dataroot);
        file_put_contents(
            $root . '/proc/self/mountinfo',
            "22 1 8:1 / / rw,relatime - ext4 /dev/sda1 rw\n" .
            "35 22 0:40 / " . dirname($dataroot) . " rw - nfs4 files.example.ac.jp:/export/moodle rw\n"
        );
        return $root;
    }

    /**
     * A root whose files hold text with markup, for the tests that print a text of the system.
     *
     * The data directory is moved into a folder whose name holds markup, so that the mount point holds it too: the
     * folder name is "<b>A&B</b>", the mount source "<u>C&D</u>" and the type of the process "<i>x</i>".
     *
     * @return string
     */
    public static function with_markup(): string {
        global $CFG;
        $root = make_request_directory();
        $data = $root . '/data/<b>A&B</b>';
        mkdir($data, 0777, true);
        $CFG->dataroot = $data;
        mkdir($root . '/sys/fs/selinux', 0777, true);
        mkdir($root . '/proc/self/attr', 0777, true);
        file_put_contents($root . '/sys/fs/selinux/enforce', "1\n");
        file_put_contents($root . '/proc/self/attr/current', "system_u:system_r:<i>x</i>:s0\0");
        file_put_contents(
            $root . '/proc/self/mountinfo',
            "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n" .
            "35 22 0:40 / " . realpath($data) . " rw - fuse.s3fs <u>C&D</u> rw\n"
        );
        return $root;
    }
}
