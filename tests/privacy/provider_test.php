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
use core_privacy\local\metadata\types\subsystem_link;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\privacy\provider::class)]
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

        $this->assertCount(2, $items);
        $item = reset($items);
        $this->assertInstanceOf(external_location::class, $item);
        $this->assertSame('tool_secbackup_service', $item->get_name());
        $this->assertSame('privacy:metadata:service', $item->get_summary());
        $this->assertSame(['sitedata' => 'privacy:metadata:service:sitedata'], $item->get_privacy_fields());
    }

    public function test_metadata_declares_the_messages_to_the_administrators(): void {
        $items = provider::get_metadata(new collection('tool_secbackup'))->get_collection();

        $link = end($items);
        $this->assertInstanceOf(subsystem_link::class, $link);
        $this->assertSame('core_message', $link->get_name());
        $this->assertSame('privacy:metadata:core_message', $link->get_summary());
    }

    public function test_metadata_strings_exist(): void {
        $this->assertTrue(get_string_manager()->string_exists('privacy:metadata:service', 'tool_secbackup'));
        $this->assertTrue(get_string_manager()->string_exists('privacy:metadata:core_message', 'tool_secbackup'));
        $this->assertTrue(get_string_manager()->string_exists('privacy:metadata:service:sitedata', 'tool_secbackup'));
    }

    public function test_the_component_complies_with_the_privacy_api(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('tool_secbackup'));
    }

    public function test_the_provider_supports_user_lists(): void {
        // The privacy registry flags a provider of user data that is not a core_userlist_provider.
        $this->assertInstanceOf(\core_privacy\local\request\core_userlist_provider::class, new provider());
    }

    public function test_no_context_holds_data_about_a_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $contextlist = $this->get_contexts_for_userid($user->id, 'tool_secbackup');

        $this->assertInstanceOf(contextlist::class, $contextlist);
        $this->assertSame([], $contextlist->get_contextids());
    }

    public function test_no_user_has_data_in_a_context(): void {
        $userlist = new userlist(\context_system::instance(), 'tool_secbackup');

        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }

    public function test_exporting_writes_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->export_context_data_for_user($user->id, $context, 'tool_secbackup');

        $this->assertFalse(writer::with_context($context)->has_any_data_in_any_context());
    }

    public function test_deleting_leaves_users_and_settings_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        set_config('probe', 'kept', 'tool_secbackup');

        provider::delete_data_for_all_users_in_context($context);
        provider::delete_data_for_user(new approved_contextlist($user, 'tool_secbackup', [$context->id]));
        provider::delete_data_for_users(new approved_userlist($context, 'tool_secbackup', [$user->id]));

        $this->assertTrue($DB->record_exists('user', ['id' => $user->id, 'deleted' => 0]));
        $this->assertSame('kept', get_config('tool_secbackup', 'probe'));
    }
}
