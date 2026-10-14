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

namespace tool_secbackup\local\page;

/**
 * Where a page sends the browser after handling a form, and the message to show there.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class redirection {
    /** @var \moodle_url Destination. */
    public readonly \moodle_url $url;

    /** @var string Message, already escaped. */
    public readonly string $message;

    /** @var string Notification type. */
    public readonly string $type;

    /**
     * Constructor.
     *
     * @param \moodle_url $url Destination.
     * @param string $message Message, already escaped.
     * @param string $type One of the \core\output\notification::NOTIFY_* types.
     */
    public function __construct(
        \moodle_url $url,
        string $message = '',
        string $type = \core\output\notification::NOTIFY_INFO
    ) {
        $this->url = $url;
        $this->message = $message;
        $this->type = $type;
    }
}
