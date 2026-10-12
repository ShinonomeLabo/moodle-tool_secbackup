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

namespace tool_secbackup\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;

/**
 * Tests for the privacy provider.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    public function test_metadata_declares_the_backup_service(): void {
        $collection = provider::get_metadata(new collection('tool_secbackup'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertInstanceOf(external_location::class, $item);
        $this->assertSame('tool_secbackup_service', $item->get_name());
        $this->assertSame('privacy:metadata:service', $item->get_summary());
        $this->assertSame(['sitedata' => 'privacy:metadata:service:sitedata'], $item->get_privacy_fields());
    }

    public function test_metadata_strings_exist(): void {
        $this->assertNotEmpty(get_string('privacy:metadata:service', 'tool_secbackup'));
        $this->assertNotEmpty(get_string('privacy:metadata:service:sitedata', 'tool_secbackup'));
    }
}
