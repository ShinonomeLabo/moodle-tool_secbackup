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

use tool_secbackup\local\api\client;

/**
 * The texts of the reasons why a run is stopped: what happened, and what the administrator can do about it.
 *
 * The status page, the message to the administrators and the command line all take the text from here, so that one reason
 * reads the same everywhere. The reasons are the ones that this plugin gives itself (client::FAIL_CODES) and the ones with
 * which the backup service fails a run (abort::SERVICE_CODES); any other code is named as it is.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class abort_reason {
    /**
     * Every reason that a run can record.
     *
     * @return string[]
     */
    public static function codes(): array {
        return array_merge(client::FAIL_CODES, abort::SERVICE_CODES);
    }

    /**
     * What happened.
     *
     * @param string $code The reason.
     * @param string|null $lang The language; null for the one of the user.
     * @return string Plain text; a code that has no text of its own is named in it.
     */
    public static function text(string $code, ?string $lang = null): string {
        if (in_array($code, self::codes(), true)) {
            return get_string_manager()->get_string('abort_' . $code, 'tool_secbackup', null, $lang);
        }
        return get_string_manager()->get_string('abort_unknown', 'tool_secbackup', $code, $lang);
    }

    /**
     * What the administrator can do.
     *
     * @param string $code The reason.
     * @param string|null $lang The language; null for the one of the user.
     * @return string Plain text.
     */
    public static function advice(string $code, ?string $lang = null): string {
        $id = in_array($code, self::codes(), true) ? 'abort_' . $code . '_advice' : 'abort_unknown_advice';
        return get_string_manager()->get_string($id, 'tool_secbackup', null, $lang);
    }
}
