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

use tool_secbackup\local\enrollment\deregistration;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Removal of the registration, confirmed by typing the site name.
 *
 * Custom data: sitename (site name on the platform), platform (platform name).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deregister_form extends \moodleform {
    /**
     * Defines the fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'stage', 'deregister');
        $mform->setType('stage', PARAM_ALPHA);
        $mform->addElement(
            'static',
            'deregisterexplain',
            '',
            get_string('deregister_explain', 'tool_secbackup', s($this->_customdata['platform']))
        );
        $mform->addElement(
            'static',
            'deregistertypename',
            '',
            get_string('deregister_typename', 'tool_secbackup', s($this->_customdata['sitename']))
        );
        $mform->addElement('text', 'confirmname', get_string('field_confirmname', 'tool_secbackup'), ['size' => 40]);
        $mform->setType('confirmname', PARAM_RAW_TRIMMED);
        $mform->addRule('confirmname', get_string('required'), 'required', null, 'server');
        $this->add_action_buttons(false, get_string('action_deregister', 'tool_secbackup'));
    }

    /**
     * Checks the typed site name.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!deregistration::confirmation_matches((string) ($data['confirmname'] ?? ''), $this->_customdata['sitename'])) {
            $errors['confirmname'] = get_string('field_confirmname_mismatch', 'tool_secbackup');
        }
        return $errors;
    }
}
