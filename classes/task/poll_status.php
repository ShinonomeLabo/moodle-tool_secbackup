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

namespace tool_secbackup\task;

use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\temp_area;
use tool_secbackup\local\enrollment\poller;

/**
 * Asks the backup service for the status of this site every five minutes.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_status extends \core\task\scheduled_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_poll_status', 'tool_secbackup');
    }

    /**
     * Keeps the folder of a run that goes on fresh, asks for the status and prints the outcome.
     *
     * A run that waits for its next window is not stepped for most of a day; this task runs every five minutes, so the files
     * of the run never look old to Moodle's cleanup of its temporary folder (temp_area::refresh()).
     */
    public function execute() {
        $live = run_record::active();
        if ($live !== null) {
            temp_area::create()->refresh($live->id());
        }
        mtrace(get_string('task_poll_' . (new poller())->run(), 'tool_secbackup'));
    }
}
