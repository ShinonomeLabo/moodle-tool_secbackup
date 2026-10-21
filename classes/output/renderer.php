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
use tool_secbackup\local\backup\abort_reason;
use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\schedule;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\backup\starter;
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
        $table->data = self::with_row_headers($table->data);
        return html_writer::table($table) . $this->clock_warning($state->clock_skew()) . $this->poll_error($state)
            . $this->backups($state) . $this->links();
    }

    /**
     * Rows of a table of labels and values, with the label as the header of its row, so that a screen reader announces the
     * value with its label.
     *
     * @param array[] $rows Each [label, value], both HTML.
     * @return \html_table_row[]
     */
    private static function with_row_headers(array $rows): array {
        return array_map(static function (array $row): \html_table_row {
            $label = new \html_table_cell($row[0]);
            $label->header = true;
            $label->scope = 'row';
            return new \html_table_row([$label, new \html_table_cell($row[1])]);
        }, $rows);
    }

    /**
     * Shows a warning in the dialog of Moodle, without JavaScript of this plugin.
     *
     * The dialog shows its body as HTML, so the plain text is escaped here, once.
     *
     * @param string $title The title.
     * @param string $message The message, as plain text.
     */
    public function warning_dialog(string $title, string $message): void {
        $this->page->requires->js_call_amd('core/notification', 'alert', [$title, s($message), get_string('ok')]);
    }

    /**
     * The backups: the last one that completed, the one in progress, what became of the latest one, and the button.
     *
     * @param state $state The registration.
     * @return string
     */
    private function backups(state $state): string {
        $active = run_record::active();
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable';
        $table->data = array_merge(
            $this->completed_rows(run_record::last_completed()),
            [$this->next_row($state)],
            $this->schedule_rows()
        );
        if ($active !== null) {
            $table->data[] = [get_string('status_running', 'tool_secbackup'), s($this->progress($active))];
        }
        $table->data = self::with_row_headers($table->data);
        return $this->output->heading(get_string('status_backups_heading', 'tool_secbackup'), 3) . html_writer::table($table)
            . $this->secret_warning(run_record::last_completed())
            . $this->latest_run_notice(run_record::recent(1)[0] ?? null, $state) . $this->backup_button($active !== null);
    }

    /**
     * A warning when the last backup that completed could not read files of the secret folder of the site.
     *
     * @param run_record|null $last The last run that completed.
     * @return string
     */
    private function secret_warning(?run_record $last): string {
        $secret = $last?->not_backed_up()['secret'] ?? 0;
        if ($secret === 0) {
            return '';
        }
        return $this->output->notification(
            s(get_string('status_secret_unreadable', 'tool_secbackup', $secret)),
            notification::NOTIFY_ERROR,
            false
        );
    }

    /**
     * The rows about the last backup that completed.
     *
     * @param run_record|null $last The run, or null when none completed.
     * @return array[]
     */
    private function completed_rows(?run_record $last): array {
        $label = get_string('status_last_completed', 'tool_secbackup');
        if ($last === null) {
            return [[$label, get_string('status_none_completed', 'tool_secbackup')]];
        }
        // A run that completed has an end.
        $finished = (int) $last->finished_at();
        $sent = get_string('status_size_value', 'tool_secbackup', (object) [
            'size' => display_size($last->bytes_uploaded()),
            'chunks' => $last->chunks_uploaded(),
        ]);
        $rows = [[$label, userdate($finished)]];
        // The size that the receipt named is the size of the backup; a run from before it was kept has only what it sent.
        if ($last->stored_bytes() !== null) {
            $rows[] = [get_string('status_last_stored', 'tool_secbackup'), display_size($last->stored_bytes())];
        }
        $rows[] = [get_string('status_last_size', 'tool_secbackup'), s($sent)];
        $rows[] = [get_string('status_last_duration', 'tool_secbackup'), format_time($finished - $last->started_at())];
        $missing = $last->not_backed_up();
        if ($missing !== null && $missing['missing'] + $missing['unreadable'] + $missing['skippedlinks'] > 0) {
            $rows[] = [get_string('status_notbackedup', 'tool_secbackup'), s(self::not_backed_up_text($missing))];
        }
        return $rows;
    }

    /**
     * The row about the next scheduled backup: when it starts, in which window; or that the settings have not arrived.
     *
     * @param state $state The registration, which has the site ID that the delay of the start comes from.
     * @return array
     */
    private function next_row(state $state): array {
        $label = get_string('status_next_backup', 'tool_secbackup');
        $settings = settings::current();
        if (!$settings->is_received()) {
            return [$label, get_string('status_next_backup_none', 'tool_secbackup')];
        }
        $next = schedule::next_slot($settings, $state->site_id(), time());
        return [$label, s(get_string('status_next_backup_value', 'tool_secbackup', (object) [
            'time' => userdate($next['start']),
            'window' => schedule::window_text($settings),
        ]))];
    }

    /**
     * How many files a backup does not hold, as plain text.
     *
     * @param int[] $counts What run_record::not_backed_up() gives.
     * @return string
     */
    public static function not_backed_up_text(array $counts): string {
        return get_string('status_notbackedup_value', 'tool_secbackup', (object) [
            'missing' => $counts['missing'],
            'unreadable' => $counts['unreadable'],
            'links' => $counts['skippedlinks'],
        ]);
    }

    /**
     * The row about the last scheduled start that was due: when, and what came of it (started, or why not).
     *
     * @return array[] One row, or none before a scheduled start was due.
     */
    private function schedule_rows(): array {
        $last = starter::last_scheduled();
        if ($last === null) {
            return [];
        }
        $value = get_string('status_last_schedule_value', 'tool_secbackup', (object) [
            'time' => userdate($last['at']),
            'text' => starter::answer_text($last['answer'], $last['cause']),
        ]);
        return [[get_string('status_last_schedule', 'tool_secbackup'), s($value)]];
    }

    /**
     * The stage and the progress of the backup in progress, as plain text.
     *
     * @param run_record $run The run.
     * @return string
     */
    private function progress(run_record $run): string {
        $a = (object) [
            'stage' => get_string('stage_' . $run->stage(), 'tool_secbackup'),
            'started' => userdate($run->started_at()),
            'size' => display_size($run->bytes_uploaded()),
            'chunks' => $run->chunks_uploaded(),
            'error' => $run->last_error(),
            'next' => $run->next_attempt() === null ? '' : userdate($run->next_attempt()),
        ];
        if ($run->status() !== 'paused') {
            return get_string('status_running_value', 'tool_secbackup', $a);
        }
        // A run that waits after a failed step says so; the error is its class and code, never its message.
        return get_string($a->error === null ? 'status_paused_value' : 'status_failed_value', 'tool_secbackup', $a);
    }

    /**
     * What became of the latest backup, when it was stopped or the service did not start it.
     *
     * Once a newer backup has begun or completed, the older stop is not the latest run any more and is not shown.
     *
     * @param run_record|null $latest The latest run.
     * @param state $state The registration, which has the name of the platform.
     * @return string
     */
    private function latest_run_notice(?run_record $latest, state $state): string {
        if ($latest === null || !in_array($latest->status(), ['aborted', 'refused'], true)) {
            return '';
        }
        $code = (string) $latest->abort_code();
        $when = userdate($latest->started_at());
        if ($latest->status() === 'aborted') {
            return $this->run_notice(
                get_string('status_aborted', 'tool_secbackup', $when),
                abort_reason::text($code, null, $latest->abort_note()),
                abort_reason::advice($code, null, $latest->abort_note()),
                notification::NOTIFY_ERROR
            );
        }
        // The service did not start the run: the site did nothing wrong, so this is a warning, not an error.
        return $this->run_notice(
            get_string('status_refused', 'tool_secbackup', $when),
            abort_reason::refusal_text($code, $latest->retry_at(), $state->platform_name()),
            abort_reason::refusal_advice($code, $state->platform_name()),
            notification::NOTIFY_WARNING
        );
    }

    /**
     * A notice about a backup that did not go through: what happened, the reason and what to do.
     *
     * @param string $heading What happened to the backup.
     * @param string $reason Why, as plain text.
     * @param string $advice What the administrator can do, as plain text.
     * @param string $type The type of the notification.
     * @return string
     */
    private function run_notice(string $heading, string $reason, string $advice, string $type): string {
        return $this->output->notification(
            html_writer::tag('p', html_writer::tag('strong', s($heading)))
            . html_writer::tag('p', s($reason))
            . html_writer::tag('p', s(get_string('status_advice', 'tool_secbackup', $advice)), ['class' => 'mb-0']),
            $type,
            false
        );
    }

    /**
     * The button that starts a backup without waiting for the schedule; off while a backup is in progress.
     *
     * @param bool $running Whether a backup is in progress.
     * @return string
     */
    private function backup_button(bool $running): string {
        $button = $this->output->single_button(
            new \moodle_url('/admin/tool/secbackup/index.php', ['action' => 'start']),
            get_string('status_backup_now', 'tool_secbackup'),
            'post',
            ['disabled' => $running]
        );
        if (!$running) {
            return $button;
        }
        return $button . html_writer::tag('p', get_string('status_backup_running', 'tool_secbackup'), ['class' => 'small']);
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
            html_writer::link(
                new \moodle_url('/admin/tool/secbackup/diagnostics.php'),
                get_string('page_diagnostics', 'tool_secbackup')
            ),
        ]);
    }

    /**
     * A hint, after a failure of the network, to open the connection diagnostics.
     *
     * @return string
     */
    public function diagnostics_hint(): string {
        $link = html_writer::link(
            new \moodle_url('/admin/tool/secbackup/diagnostics.php'),
            get_string('page_diagnostics', 'tool_secbackup')
        );
        return $this->output->notification(get_string('diag_hint', 'tool_secbackup', $link), notification::NOTIFY_INFO, false);
    }

    /**
     * The table of the diagnostics.
     *
     * The texts of the results are plain text and are escaped here, once.
     *
     * @param \tool_secbackup\local\diagnostics\check_result[] $results Results.
     * @return string
     */
    public function diagnostics(array $results): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable';
        $table->head = [
            get_string('diag_heading_check', 'tool_secbackup'),
            get_string('diag_heading_result', 'tool_secbackup'),
            get_string('diag_heading_detail', 'tool_secbackup'),
            get_string('diag_heading_advice', 'tool_secbackup'),
        ];
        foreach ($results as $result) {
            $table->data[] = [
                get_string('check_' . $result->check, 'tool_secbackup'),
                get_string('result_' . $result->result, 'tool_secbackup'),
                s($result->detail),
                s($result->advice),
            ];
        }
        return html_writer::table($table);
    }
}
