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

namespace tool_secbackup\event;

/**
 * The site was registered with the backup service, or registered again.
 *
 * @property-read array $other {
 *      - string endpoint: connection URL.
 *      - string siteid: site ID at the service.
 *      - bool again: whether the site was registered before.
 * }
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_enrolled extends \core\event\base {
    /**
     * Sets the basic properties.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_enrolled', 'tool_secbackup');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $again = $this->other['again'] ? ' again' : '';
        return "The user with id '{$this->userid}' registered this site{$again} with the backup service at " .
            "'{$this->other['endpoint']}' (site ID '{$this->other['siteid']}').";
    }

    /**
     * Link to the status page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/secbackup/index.php');
    }

    /**
     * Checks the values of other.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['endpoint'], $this->other['siteid'], $this->other['again'])) {
            throw new \coding_exception('The values endpoint, siteid and again must be set in other.');
        }
    }

    /**
     * Nothing in other refers to course data.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
