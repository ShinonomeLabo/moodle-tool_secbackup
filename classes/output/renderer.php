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

namespace tool_secbackup\output;

use core\output\notification;
use html_writer;
use tool_secbackup\local\api\client;
use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

/**
 * Renders the plugin's pages with Moodle's standard parts only.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /** @var string[] Statuses of the service that have their own label. */
    private const STATUSES = ['active', 'awaiting_enrollment'];

    /**
     * The top of every page: the warning about developer settings, the introduction and the trademark notice.
     *
     * @param developer_settings $settings Developer settings.
     * @return string
     */
    public function page_top(developer_settings $settings): string {
        $out = '';
        if ($settings->is_active()) {
            $out .= $this->output->notification(
                get_string('developer_banner', 'tool_secbackup'),
                notification::NOTIFY_WARNING,
                false
            );
        }
        $out .= html_writer::tag('p', get_string('intro', 'tool_secbackup'));
        $out .= html_writer::tag('p', get_string('trademark', 'tool_secbackup'), ['class' => 'small']);
        return $out;
    }

    /**
     * An error message.
     *
     * The notification prints its message as HTML, so the plain text is escaped here, once.
     *
     * @param string $message The message, as plain text.
     * @return string
     */
    public function error_message(string $message): string {
        return $this->output->notification(s($message), notification::NOTIFY_ERROR, false);
    }

    /**
     * The registration and the outcome of the last status request.
     *
     * @param state $state The registration.
     * @return string
     */
    public function status(state $state): string {
        if (!$state->is_enrolled()) {
            return $this->output->notification(
                get_string('status_not_registered', 'tool_secbackup'),
                notification::NOTIFY_INFO,
                false
            ) . $this->links();
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable';
        $lastpoll = $state->last_poll();
        $enrolledat = $state->enrolled_at();
        $table->data = [
            [get_string('status_platform', 'tool_secbackup'), s($state->platform_name())],
            [get_string('status_site', 'tool_secbackup'), s($state->site_name())],
            [get_string('field_endpoint', 'tool_secbackup'), s($state->endpoint_url())],
            [get_string('status_siteid', 'tool_secbackup'), s($state->site_id())],
            [get_string('field_fingerprint', 'tool_secbackup'), $this->fingerprint($state)],
            [
                get_string('status_enrolled_at', 'tool_secbackup'),
                $enrolledat === null ? get_string('status_unreadable', 'tool_secbackup') : userdate($enrolledat),
            ],
            [
                get_string('status_last_poll', 'tool_secbackup'),
                $lastpoll === null ? get_string('status_never', 'tool_secbackup') : userdate($lastpoll),
            ],
            [get_string('status_service_status', 'tool_secbackup'), $this->service_status($state->last_poll_status())],
        ];
        return html_writer::table($table) . $this->clock_warning($state->clock_skew()) . $this->poll_error($state)
            . $this->links();
    }

    /**
     * The fingerprint of the stored public key, or a note that the stored key cannot be read.
     *
     * @param state $state The registration.
     * @return string
     */
    private function fingerprint(state $state): string {
        try {
            return html_writer::tag('code', s(fingerprint::format($state->encryption_fingerprint())));
        } catch (plugin_exception $e) {
            return get_string('status_unreadable', 'tool_secbackup');
        }
    }

    /**
     * Label of the status given by the service.
     *
     * @param string $status Status.
     * @return string
     */
    private function service_status(string $status): string {
        if ($status === '') {
            return get_string('status_unknown', 'tool_secbackup');
        }
        if (in_array($status, self::STATUSES, true)) {
            return get_string('service_status_' . $status, 'tool_secbackup');
        }
        return get_string('service_status_other', 'tool_secbackup', s($status));
    }

    /**
     * A warning when the clocks differ by two minutes or more.
     *
     * @param int|null $skew The service's clock minus this server's clock, in seconds.
     * @return string
     */
    public function clock_warning(?int $skew): string {
        if ($skew === null || abs($skew) < client::CLOCK_SKEW_WARNING) {
            return '';
        }
        return $this->output->notification(
            get_string('clock_skew_warning', 'tool_secbackup', (int) round(abs($skew) / 60)),
            notification::NOTIFY_WARNING,
            false
        );
    }

    /**
     * The failure of the last status request, if any.
     *
     * @param state $state The registration.
     * @return string
     */
    private function poll_error(state $state): string {
        $error = $state->last_poll_error();
        if ($error === '') {
            return '';
        }
        if (!get_string_manager()->string_exists($error, 'tool_secbackup')) {
            return $this->error_message(get_string('status_last_error_code', 'tool_secbackup', $error));
        }
        $a = $state->last_poll_error_a() === '' ? null : $state->last_poll_error_a();
        return $this->error_message(
            get_string('status_last_error', 'tool_secbackup') . ' ' . get_string($error, 'tool_secbackup', $a)
        );
    }

    /**
     * Links to the other pages.
     *
     * @return string
     */
    public function links(): string {
        return html_writer::alist([
            html_writer::link(new \moodle_url('/admin/tool/secbackup/connect.php'), get_string('page_connect', 'tool_secbackup')),
        ]);
    }
}
