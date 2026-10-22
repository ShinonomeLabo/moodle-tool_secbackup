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

namespace tool_secbackup\local\backup\env;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\env\external_storage::class)]
/**
 * Tests for the detection of an external file store.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\env\external_storage
 */
final class external_storage_test extends \advanced_testcase {
    /**
     * The text of a mountinfo with the data directory on a file system of a type.
     *
     * @param string $type The type.
     * @return string
     */
    private static function mountinfo(string $type): string {
        global $CFG;
        $dataroot = realpath($CFG->dataroot) ?: $CFG->dataroot;
        return "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n"
            . "40 22 0:35 / " . $dataroot . " rw,nosuid - " . $type . " bucket rw,user_id=0\n";
    }

    /**
     * Types of file system and whether they are an external store.
     *
     * @return array
     */
    public static function types(): array {
        return [
            's3fs' => ['fuse.s3fs', true],
            'mountpoint-s3' => ['fuse.mountpoint-s3', true],
            'ext4' => ['ext4', false],
            'nfs' => ['nfs4', false],
            'other fuse' => ['fuse.sshfs', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('types')]
    /**
     * The file system of the data directory decides, when the site has no alternative file system class.
     *
     * @dataProvider types
     * @param string $type The type of the file system of the data directory.
     * @param bool $expected Whether it is detected.
     */
    public function test_the_file_system_of_the_data_directory_decides(string $type, bool $expected): void {
        $this->resetAfterTest();
        global $CFG;
        $CFG->alternative_file_system_class = '';
        $storage = new external_storage(fn(): ?string => self::mountinfo($type));
        $this->assertSame($expected, $storage->detected());
    }

    public function test_a_file_system_class_in_the_configuration_is_enough(): void {
        $this->resetAfterTest();
        global $CFG;
        $CFG->alternative_file_system_class = '\\tool_example\\file_system';
        $storage = new external_storage(fn(): ?string => self::mountinfo('ext4'));
        $this->assertTrue($storage->detected());
    }

    public function test_an_unset_class_and_an_unreadable_mountinfo_are_not_detected(): void {
        $this->resetAfterTest();
        global $CFG;
        unset($CFG->alternative_file_system_class);
        $this->assertFalse((new external_storage(fn(): ?string => null))->detected());
        $CFG->alternative_file_system_class = null;
        $this->assertFalse((new external_storage(fn(): ?string => null))->detected());
        // A data directory that no line of the mountinfo holds.
        $this->assertFalse((new external_storage(fn(): ?string => ''))->detected());
    }

    public function test_the_real_mountinfo_is_read_without_a_warning(): void {
        $this->resetAfterTest();
        global $CFG;
        $CFG->alternative_file_system_class = '';
        // The test machine does not keep its test data on an external store, and the read raises no warning.
        $this->assertFalse((new external_storage())->detected());
    }
}
