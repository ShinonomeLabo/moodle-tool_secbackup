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

namespace tool_secbackup\local\backup;

/**
 * Tells the site administrators that the backups of the site keep being stopped, so that a site without backups is noticed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notifier {
    /**
     * Sends a message to every site administrator, in the language of each.
     *
     * A failure to tell one administrator is reported with debugging() and does not stop the others, and does not leave this
     * method: the backup is recorded as stopped already.
     *
     * @param string $code The reason of the last stop, a word of lower-case letters and underscores.
     * @param int $count How many backups in a row were stopped.
     * @param array $note What the site kept about the last stop (abort::reason()).
     */
    public static function backup_failed(string $code, int $count, array $note = []): void {
        $url = (new \moodle_url('/admin/tool/secbackup/index.php'))->out(false);
        $strings = get_string_manager();
        foreach (get_admins() as $admin) {
            try {
                $lang = $admin->lang;
                $a = (object) [
                    'count' => $count,
                    'reason' => abort_reason::text($code, $lang, $note),
                    'advice' => abort_reason::advice($code, $lang, $note),
                    'url' => $url,
                ];
                $message = new \core\message\message();
                $message->component = 'tool_secbackup';
                $message->name = 'backupfailure';
                $message->courseid = SITEID;
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $admin;
                $message->subject = $strings->get_string('message_backupfailure_subject', 'tool_secbackup', $a, $lang);
                $message->fullmessage = $strings->get_string('message_backupfailure_body', 'tool_secbackup', $a, $lang);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = text_to_html(s($message->fullmessage));
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url;
                $message->contexturlname = $strings->get_string('pluginname', 'tool_secbackup', null, $lang);
                message_send($message);
            } catch (\Throwable $e) {
                debugging('The message about the stopped backups could not be sent to the user ' . $admin->id . ': ' . $e::class);
            }
        }
    }
}
