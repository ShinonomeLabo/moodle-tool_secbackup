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

use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * First step of the registration: the connection URL and the site's public key.
 *
 * Custom data: endpoint, the registered connection URL (empty when the site is not registered).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_form extends \moodleform {
    /**
     * Defines the fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'stage', 'connection');
        $mform->setType('stage', PARAM_ALPHA);
        $fixed = (string) ($this->_customdata['endpoint'] ?? '');
        if ($fixed === '') {
            $mform->addElement('text', 'endpoint', get_string('field_endpoint', 'tool_secbackup'), ['size' => 60]);
            $mform->setType('endpoint', PARAM_RAW_TRIMMED);
            $mform->addRule('endpoint', get_string('required'), 'required', null, 'server');
            $mform->addHelpButton('endpoint', 'field_endpoint', 'tool_secbackup');
        } else {
            $mform->addElement(
                'static',
                'endpointshown',
                get_string('field_endpoint', 'tool_secbackup'),
                s($fixed) . \html_writer::tag('div', get_string('field_endpoint_fixed', 'tool_secbackup'))
            );
        }
        $mform->addElement(
            'textarea',
            'publickey',
            get_string('field_publickey', 'tool_secbackup'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('publickey', PARAM_RAW);
        $mform->addRule('publickey', get_string('required'), 'required', null, 'server');
        $mform->addHelpButton('publickey', 'field_publickey', 'tool_secbackup');
        $this->add_action_buttons(false, get_string('action_next', 'tool_secbackup'));
    }

    /**
     * Checks the connection URL and the public key.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((string) ($this->_customdata['endpoint'] ?? '') === '') {
            try {
                endpoint::parse((string) ($data['endpoint'] ?? ''), developer_settings::current());
            } catch (plugin_exception $e) {
                $errors['endpoint'] = $e->message();
            }
        }
        try {
            public_key_format::parse((string) ($data['publickey'] ?? ''));
        } catch (plugin_exception $e) {
            $errors['publickey'] = $e->message();
        }
        return $errors;
    }
}
