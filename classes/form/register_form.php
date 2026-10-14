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

use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\enrollment\enrollment_code;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Second step of the registration: the fingerprint check and the registration code.
 *
 * Custom data: endpoint (normal form), publickey (as the platform shows it), fingerprint.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_form extends \moodleform {
    /**
     * Defines the fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'stage', 'register');
        $mform->setType('stage', PARAM_ALPHA);
        $mform->addElement('hidden', 'endpoint', $this->_customdata['endpoint']);
        $mform->setType('endpoint', PARAM_RAW);
        $mform->addElement('hidden', 'publickey', $this->_customdata['publickey']);
        $mform->setType('publickey', PARAM_RAW);
        $mform->addElement(
            'static',
            'endpointshown',
            get_string('field_endpoint', 'tool_secbackup'),
            s($this->_customdata['endpoint'])
        );
        $mform->addElement(
            'static',
            'fingerprint',
            get_string('field_fingerprint', 'tool_secbackup'),
            \html_writer::tag('code', s(fingerprint::format($this->_customdata['fingerprint'])))
        );
        $mform->addElement(
            'checkbox',
            'confirmfingerprint',
            get_string('field_confirmfingerprint_label', 'tool_secbackup'),
            get_string('field_confirmfingerprint', 'tool_secbackup')
        );
        $mform->addElement('text', 'code', get_string('field_code', 'tool_secbackup'), ['size' => 40, 'autocomplete' => 'off']);
        $mform->setType('code', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('code', 'field_code', 'tool_secbackup');
        $this->add_action_buttons(true, get_string('action_register', 'tool_secbackup'));
    }

    /**
     * Checks the confirmation and the shape of the registration code.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['confirmfingerprint'])) {
            $errors['confirmfingerprint'] = get_string('field_confirmfingerprint_required', 'tool_secbackup');
        }
        if (enrollment_code::normalize((string) ($data['code'] ?? '')) === null) {
            $errors['code'] = get_string('field_code_invalid', 'tool_secbackup');
        }
        return $errors;
    }
}
