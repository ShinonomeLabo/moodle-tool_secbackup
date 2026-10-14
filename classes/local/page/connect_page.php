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

use core\output\notification;
use html_writer;
use tool_secbackup\form\connection_form;
use tool_secbackup\form\deregister_form;
use tool_secbackup\form\register_form;
use tool_secbackup\local\api\client;
use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\deregistration;
use tool_secbackup\local\enrollment\enroller;
use tool_secbackup\local\enrollment\enrollment_code;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\envelope_exception;

/**
 * The connection settings page: registration in two steps, registration again, removal of the registration.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connect_page {
    /** @var enroller|null Enroller; null for the one of this site, made when needed. */
    private ?enroller $enroller;

    /** @var \moodle_url URL of this page. */
    private \moodle_url $url;

    /** @var \moodleform|null The form of the current step. */
    private ?\moodleform $form = null;

    /** @var deregister_form|null The removal form, for a registered site. */
    private ?deregister_form $deregisterform = null;

    /** @var string[] Error messages for the top of the page, as plain text. */
    private array $errors = [];

    /**
     * Constructor: checks access.
     *
     * @param enroller|null $enroller Enroller; null for the one of this site.
     */
    public function __construct(?enroller $enroller = null) {
        access::check();
        $this->enroller = $enroller;
        $this->url = new \moodle_url('/admin/tool/secbackup/connect.php');
    }

    /**
     * Handles a submitted form.
     *
     * @return redirection|null Where to go next, or null to show the page.
     */
    public function handle(): ?redirection {
        $state = state::load();
        $stage = optional_param('stage', '', PARAM_ALPHA);
        if ($state->is_enrolled()) {
            $this->deregisterform = new deregister_form(
                $this->url,
                ['sitename' => $state->site_name(), 'platform' => $state->platform_name()]
            );
            if ($stage === 'deregister' && $this->deregisterform->get_data() !== null) {
                deregistration::run();
                return new redirection(
                    new \moodle_url('/admin/tool/secbackup/index.php'),
                    get_string('deregister_done', 'tool_secbackup', s($state->platform_name())),
                    notification::NOTIFY_SUCCESS
                );
            }
        }
        if ($stage === 'register') {
            return $this->handle_registration($state);
        }
        $connection = new connection_form($this->url, ['endpoint' => $state->endpoint_url()]);
        $data = $connection->get_data();
        if ($data === null) {
            $this->form = $connection;
            return null;
        }
        $endpoint = $state->is_enrolled() ? $state->endpoint_url()
            : endpoint::parse($data->endpoint, developer_settings::current())->url();
        $this->form = $this->register_form($endpoint, public_key_format::parse($data->publickey));
        return null;
    }

    /**
     * Handles the second step.
     *
     * @param state $state The registration.
     * @return redirection|null
     */
    private function handle_registration(state $state): ?redirection {
        try {
            $endpointtext = $state->is_enrolled() ? $state->endpoint_url() : optional_param('endpoint', '', PARAM_RAW);
            $endpoint = endpoint::parse($endpointtext, developer_settings::current());
            $key = public_key_format::parse(optional_param('publickey', '', PARAM_RAW));
        } catch (plugin_exception $e) {
            $this->errors[] = $e->message();
            $this->form = new connection_form($this->url, ['endpoint' => $state->endpoint_url()]);
            return null;
        }
        $this->form = $this->register_form($endpoint->url(), $key);
        if ($this->form->is_cancelled()) {
            return new redirection($this->url);
        }
        $data = $this->form->get_data();
        if ($data === null) {
            return null;
        }
        try {
            $result = $this->enroller()->enroll($endpoint, $key, (string) enrollment_code::normalize($data->code));
        } catch (envelope_exception $e) {
            $this->errors = [$e->message(), get_string('enroll_reissue_advice', 'tool_secbackup')];
            return null;
        } catch (plugin_exception $e) {
            $this->errors = [$e->message()];
            return null;
        }
        if (abs($result->clockskew) >= client::CLOCK_SKEW_WARNING) {
            $minutes = (int) round(abs($result->clockskew) / 60);
            \core\notification::warning(get_string('clock_skew_warning', 'tool_secbackup', $minutes));
        }
        return new redirection(
            new \moodle_url('/admin/tool/secbackup/index.php'),
            get_string('enroll_done', 'tool_secbackup', s($result->platformname)),
            notification::NOTIFY_SUCCESS
        );
    }

    /**
     * The form of the second step.
     *
     * @param string $endpoint Connection URL in its normal form.
     * @param string $key The site's encryption public key (32 bytes).
     * @return register_form
     */
    private function register_form(string $endpoint, string $key): register_form {
        return new register_form($this->url, [
            'endpoint' => $endpoint,
            'publickey' => public_key_format::encode($key),
            'fingerprint' => fingerprint::of($key),
        ]);
    }

    /**
     * The enroller.
     *
     * @return enroller
     */
    private function enroller(): enroller {
        return $this->enroller ?? enroller::create();
    }

    /**
     * The body of the page.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE, $OUTPUT;
        $renderer = $PAGE->get_renderer('tool_secbackup');
        $out = $renderer->page_top(developer_settings::current());
        foreach ($this->errors as $message) {
            $out .= $renderer->error_message($message);
        }
        if ($this->deregisterform !== null) {
            $out .= $OUTPUT->heading(get_string('connect_again_heading', 'tool_secbackup'), 3);
            $out .= html_writer::tag('p', get_string('connect_again_explain', 'tool_secbackup'));
        }
        $out .= $this->form->render();
        if ($this->deregisterform !== null && !($this->form instanceof register_form)) {
            $out .= $OUTPUT->heading(get_string('deregister_heading', 'tool_secbackup'), 3);
            $out .= $this->deregisterform->render();
        }
        return $out;
    }
}
