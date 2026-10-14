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

namespace tool_secbackup\form;

use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A connection URL to check before the site is registered.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostics_form extends \moodleform {
    /**
     * Defines the field.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('text', 'endpoint', get_string('field_endpoint', 'tool_secbackup'), ['size' => 60]);
        $mform->setType('endpoint', PARAM_RAW_TRIMMED);
        $mform->addRule('endpoint', get_string('required'), 'required', null, 'server');
        $this->add_action_buttons(false, get_string('diag_run', 'tool_secbackup'));
    }

    /**
     * Checks the connection URL.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        try {
            endpoint::parse((string) ($data['endpoint'] ?? ''), developer_settings::current());
        } catch (plugin_exception $e) {
            // A validation error is printed as HTML, and the message is plain text.
            $errors['endpoint'] = s($e->message());
        }
        return $errors;
    }
}
