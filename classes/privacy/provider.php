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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for tool_secbackup.
 *
 * The plugin sends encrypted backups of the whole site, which include the personal data of all
 * users, to the backup service. It keeps no personal data in its own tables, and the backup is the
 * encrypted site as a whole, which cannot be searched, exported or changed for one user. So the
 * request methods below have nothing to do; they exist so that Moodle treats the plugin as
 * compliant and lists the metadata of get_metadata() in its privacy registry.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // The plugin does not store personal data itself: it only sends the site to the backup service.
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the data sent to the backup service.
     *
     * @param collection $collection The collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'tool_secbackup_service',
            ['sitedata' => 'privacy:metadata:service:sitedata'],
            'privacy:metadata:service'
        );
        return $collection;
    }

    /**
     * Gets the contexts that hold data about a user.
     *
     * The plugin keeps no data about users in Moodle, so no context is returned.
     *
     * @param int $userid The user to search for.
     * @return contextlist An empty list.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Gets the users who have data in a context.
     *
     * The plugin keeps no data about users in Moodle, so nobody is added.
     *
     * @param userlist $userlist The list to add the users to.
     */
    public static function get_users_in_context(userlist $userlist): void {
    }

    /**
     * Exports the data about a user in the given contexts. There is none.
     *
     * @param approved_contextlist $contextlist The approved contexts to export the data of.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
    }

    /**
     * Deletes the data about all users in a context. There is none.
     *
     * @param \context $context The context to delete the data in.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
    }

    /**
     * Deletes the data about one user in the given contexts. There is none.
     *
     * @param approved_contextlist $contextlist The approved contexts, and the user, to delete the data of.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
    }

    /**
     * Deletes the data about several users in one context. There is none.
     *
     * @param approved_userlist $userlist The approved context, and the users, to delete the data of.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
    }
}
