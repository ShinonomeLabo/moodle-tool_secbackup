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

use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\trust\developer_settings;

/**
 * The status page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_page {
    /**
     * Constructor: checks access.
     */
    public function __construct() {
        access::check();
    }

    /**
     * The body of the page.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE;
        $renderer = $PAGE->get_renderer('tool_secbackup');
        return $renderer->page_top(developer_settings::current()) . $renderer->status(state::load());
    }
}
