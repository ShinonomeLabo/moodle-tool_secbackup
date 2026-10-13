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

namespace tool_secbackup\local\enrollment;

/**
 * Tells the site administrators about changes to the connection, so that a change nobody expected is noticed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notifier {
    /**
     * Sends a message to every site administrator.
     *
     * @param string $kind enrolled, reenrolled or deregistered.
     * @param string $platformname Platform name.
     * @param string $sitename Site name on the platform.
     * @param string $endpoint Connection URL.
     */
    public static function send(string $kind, string $platformname, string $sitename, string $endpoint): void {
        global $USER;
        $url = new \moodle_url('/admin/tool/secbackup/index.php');
        $a = (object) [
            'platform' => $platformname,
            'site' => $sitename,
            'endpoint' => $endpoint,
            'user' => empty($USER->id) ? get_string('notifier_system', 'tool_secbackup') : fullname($USER),
            'url' => $url->out(false),
        ];
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'tool_secbackup';
            $message->name = 'connection';
            $message->courseid = SITEID;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('message_' . $kind . '_subject', 'tool_secbackup', $a);
            $message->fullmessage = get_string('message_' . $kind . '_body', 'tool_secbackup', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $a->url;
            $message->contexturlname = get_string('pluginname', 'tool_secbackup');
            message_send($message);
        }
    }
}
