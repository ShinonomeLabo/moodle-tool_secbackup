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
     * Sends a message to every site administrator, in the language of each.
     *
     * A failure to tell one administrator is reported with debugging() and does not stop the others, and does not
     * leave this method: what the message tells has happened already.
     *
     * The platform and site names come from the service and are shown as text: the HTML of the message is made from
     * the escaped text, because Moodle would otherwise make the HTML part of an e-mail from the plain text without
     * escaping it.
     *
     * @param string $kind enrolled, reenrolled or deregistered.
     * @param string $platformname Platform name.
     * @param string $sitename Site name on the platform.
     * @param string $endpoint Connection URL.
     */
    public static function send(string $kind, string $platformname, string $sitename, string $endpoint): void {
        global $USER;
        $url = (new \moodle_url('/admin/tool/secbackup/index.php'))->out(false);
        $strings = get_string_manager();
        foreach (get_admins() as $admin) {
            try {
                $lang = $admin->lang;
                $a = (object) [
                    'platform' => $platformname,
                    'site' => $sitename,
                    'endpoint' => $endpoint,
                    'user' => empty($USER->id) ? $strings->get_string('notifier_system', 'tool_secbackup', null, $lang) :
                        fullname($USER),
                    'url' => $url,
                ];
                $message = new \core\message\message();
                $message->component = 'tool_secbackup';
                $message->name = 'connection';
                $message->courseid = SITEID;
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $admin;
                $message->subject = $strings->get_string('message_' . $kind . '_subject', 'tool_secbackup', $a, $lang);
                $message->fullmessage = $strings->get_string('message_' . $kind . '_body', 'tool_secbackup', $a, $lang);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = text_to_html(s($message->fullmessage));
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url;
                $message->contexturlname = $strings->get_string('pluginname', 'tool_secbackup', null, $lang);
                message_send($message);
            } catch (\Throwable $e) {
                debugging(
                    'The message about the connection could not be sent to the user ' . $admin->id . ': ' . $e::class
                );
            }
        }
    }
}
