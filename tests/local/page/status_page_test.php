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

use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\api\status_result;
use tool_secbackup\local\backup\abort_reason;
use tool_secbackup\local\backup\env\external_storage;
use tool_secbackup\local\backup\limits;
use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\backup\starter;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\instruction_factory;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\status_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\output\renderer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\redirection::class)]
/**
 * Tests for the status page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\page\status_page
 * @covers     \tool_secbackup\local\page\access
 * @covers     \tool_secbackup\output\renderer
 */
final class status_page_test extends \advanced_testcase {
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/admin/tool/secbackup/index.php'));
        $PAGE->set_context(\context_system::instance());
    }

    /**
     * Saves a registration of "Example <b>site</b>" with "Example Platform".
     */
    private static function register(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example <b>site</b>',
            'Example Platform',
            'https://app.example.test/api/lms-backup/v1',
            1760000000,
            0
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
    }

    public function test_not_registered(): void {
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_not_registered', 'tool_secbackup'), $html);
        $this->assertStringContainsString('Moodle™', $html);
        $this->assertStringContainsString('Moodle Pty Ltd', $html);
        $this->assertStringContainsString('/admin/tool/secbackup/connect.php', $html);
        $this->assertStringNotContainsString(get_string('developer_banner', 'tool_secbackup'), $html);
    }

    public function test_the_top_of_the_page_of_a_registered_site(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('intro', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('trademark', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('developer_banner', 'tool_secbackup'), $html);
    }

    public function test_the_intro_and_the_notice_are_on_the_page_before_a_registration(): void {
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('intro', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('trademark', 'tool_secbackup'), $html);
    }

    public function test_developer_banner(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['allowhttp' => true];
        $this->assertStringContainsString(get_string('developer_banner', 'tool_secbackup'), (new status_page())->render());
    }

    public function test_registered_site(): void {
        self::register();
        state::record_poll(1760000300, new status_result('active', 1760000305, 5, []));
        $html = (new status_page())->render();
        $this->assertStringContainsString('Example Platform', $html);
        $this->assertStringContainsString('Example &lt;b&gt;site&lt;/b&gt;', $html);
        $this->assertStringContainsString('https://app.example.test/api/lms-backup/v1', $html);
        $this->assertStringContainsString('A260 B405 0FD2 8D2B 313C 9A94', $html);
        $this->assertStringContainsString(get_string('service_status_active', 'tool_secbackup'), $html);
        $this->assertStringContainsString(userdate(1760000300), $html);
        $this->assertStringNotContainsString(get_string('status_last_error', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('status_last_error_code', 'tool_secbackup', ''), $html);
    }

    public function test_the_page_of_a_registered_site_links_to_the_connection_settings(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringContainsString(
            'href="https://www.example.com/moodle/admin/tool/secbackup/connect.php"',
            $html
        );
    }

    public function test_registered_site_never_asked(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_never', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('status_unknown', 'tool_secbackup'), $html);
    }

    public function test_clock_error_is_explained(): void {
        self::register();
        state::record_poll(1760000300, null, 'api_timestamp_out_of_range_minutes', '9', 540);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('api_timestamp_out_of_range_minutes', 'tool_secbackup', '9'), $html);
        $this->assertStringContainsString(get_string('clock_skew_warning', 'tool_secbackup', 9), $html);
    }

    public function test_small_clock_difference_gives_no_warning(): void {
        self::register();
        state::record_poll(1760000300, new status_result('active', 1760000360, 60, []));
        $this->assertStringNotContainsString(
            get_string('clock_skew_warning', 'tool_secbackup', 1),
            (new status_page())->render()
        );
    }

    public function test_error_without_a_value(): void {
        self::register();
        state::record_poll(1760000300, null, 'api_site_not_enrolled');
        $this->assertStringContainsString(
            get_string('api_site_not_enrolled', 'tool_secbackup'),
            (new status_page())->render()
        );
    }

    public function test_unknown_error_code_is_shown_as_text(): void {
        self::register();
        state::record_poll(1760000300, null, 'no_such_string<x>');
        $html = (new status_page())->render();
        $this->assertStringContainsString(
            get_string('status_last_error_code', 'tool_secbackup', 'no_such_string&lt;x&gt;'),
            $html
        );
    }

    public function test_other_service_status(): void {
        self::register();
        state::record_poll(1760000300, new status_result('suspended', 1760000300, 0, []));
        $this->assertStringContainsString(
            get_string('service_status_other', 'tool_secbackup', 'suspended'),
            (new status_page())->render()
        );
    }

    /**
     * Checks that a text came out escaped once: the escaped text is there, the raw text and the twice escaped text are not.
     *
     * @param string $html The page.
     * @param string $raw The text as it was stored.
     * @param string $escaped The text escaped once.
     */
    private function assert_escaped_once(string $html, string $raw, string $escaped): void {
        $this->assertStringContainsString($escaped, $html);
        $this->assertStringNotContainsString($raw, $html);
        $this->assertStringNotContainsString(str_replace('&', '&amp;', $escaped), $html);
    }

    public function test_error_message_escapes_its_plain_text_once(): void {
        global $PAGE;
        $html = $PAGE->get_renderer('tool_secbackup')->error_message('<b>A&B</b>');
        $this->assert_escaped_once($html, '<b>A&B</b>', '&lt;b&gt;A&amp;B&lt;/b&gt;');
    }

    public function test_names_and_status_from_the_service_are_escaped_once(): void {
        self::register();
        set_config('sitename', '<b>A&B</b>', 'tool_secbackup');
        set_config('platformname', '<i>C&D</i>', 'tool_secbackup');
        state::record_poll(1760000300, new status_result('<u>E&F</u>', 1760000300, 0, []));
        $html = (new status_page())->render();
        $this->assert_escaped_once($html, '<b>A&B</b>', '&lt;b&gt;A&amp;B&lt;/b&gt;');
        $this->assert_escaped_once($html, '<i>C&D</i>', '&lt;i&gt;C&amp;D&lt;/i&gt;');
        $this->assert_escaped_once($html, '<u>E&F</u>', '&lt;u&gt;E&amp;F&lt;/u&gt;');
    }

    public function test_the_value_of_an_error_is_escaped_once(): void {
        self::register();
        state::record_poll(1760000300, null, 'api_ip_not_allowed_address', '<img src=x>');
        $html = (new status_page())->render();
        $this->assertStringContainsString('&lt;img src=x&gt;', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringNotContainsString('&amp;lt;img', $html);
    }

    public function test_an_unknown_error_code_is_escaped_once(): void {
        self::register();
        state::record_poll(1760000300, null, '<u>x</u>');
        $this->assert_escaped_once((new status_page())->render(), '<u>x</u>', '&lt;u&gt;x&lt;/u&gt;');
    }

    public function test_a_stored_time_that_is_not_a_number_is_not_shown_as_1970(): void {
        self::register();
        set_config('enrolledat', 'abc', 'tool_secbackup');
        $html = (new status_page())->render();
        $this->assertStringNotContainsString(userdate(0), $html);
        // The time will not become known, so it is not "not known yet"; that is for the status before the first request.
        $this->assertSame(1, substr_count($html, get_string('status_unreadable', 'tool_secbackup')));
        $this->assertSame(1, substr_count($html, get_string('status_unknown', 'tool_secbackup')));
    }

    public function test_a_stored_key_that_cannot_be_read_does_not_break_the_page(): void {
        self::register();
        set_config('encryptionkey', 'not a key', 'tool_secbackup');
        $html = (new status_page())->render();
        $this->assertSame(1, substr_count($html, get_string('status_unreadable', 'tool_secbackup')));
        $this->assertStringNotContainsString('secbk1:', $html);
        // Every other row is as before, and the page goes on to its end.
        $this->assertStringContainsString('Example Platform', $html);
        $this->assertStringContainsString('Example &lt;b&gt;site&lt;/b&gt;', $html);
        $this->assertStringContainsString('https://app.example.test/api/lms-backup/v1', $html);
        $this->assertStringContainsString(userdate(1760000000), $html);
        $this->assertStringContainsString('/admin/tool/secbackup/connect.php', $html);
    }

    public function test_a_registered_site_without_its_signing_key_is_still_shown(): void {
        // A removal that stopped half-way leaves a registered site without its key.
        self::register();
        signing_keys::forget();
        $this->assertStringContainsString('Example &lt;b&gt;site&lt;/b&gt;', (new status_page())->render());
    }

    /**
     * Differences between the clocks, and the warning that each one earns.
     *
     * @return array
     */
    public static function clock_difference_provider(): array {
        return [
            'just under two minutes' => [119, 0],
            'two minutes' => [120, 2],
            'just under two minutes, the service behind' => [-119, 0],
            'two minutes, the service behind' => [-120, 2],
            'two and a half minutes is rounded up' => [150, 3],
            'nine minutes, the service behind' => [-540, 9],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clock_difference_provider')]
    /**
     * The warning about the clocks starts at two minutes, in either direction.
     *
     * @dataProvider clock_difference_provider
     * @param int $skew The service's clock minus this server's clock, in seconds.
     * @param int $minutes The minutes the warning names, or 0 for no warning.
     */
    public function test_clock_warning_starts_at_two_minutes(int $skew, int $minutes): void {
        global $PAGE;
        $html = $PAGE->get_renderer('tool_secbackup')->clock_warning($skew);
        if ($minutes === 0) {
            $this->assertSame('', $html);
        } else {
            $this->assertStringContainsString(get_string('clock_skew_warning', 'tool_secbackup', $minutes), $html);
        }
    }

    public function test_a_status_the_service_has_a_label_for(): void {
        self::register();
        state::record_poll(1760000300, new status_result('awaiting_enrollment', 1760000300, 0, []));
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('service_status_awaiting_enrollment', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('service_status_other', 'tool_secbackup', ''), $html);
    }

    public function test_stored_values_that_hold_markup_are_escaped_once(): void {
        self::register();
        set_config('siteid', '<b>x&y</b>', 'tool_secbackup');
        set_config('endpoint', 'https://app.example.test/<i>&</i>', 'tool_secbackup');
        $html = (new status_page())->render();
        $this->assert_escaped_once($html, '<b>x&y</b>', '&lt;b&gt;x&amp;y&lt;/b&gt;');
        $this->assert_escaped_once($html, '<i>&</i>', '&lt;i&gt;&amp;&lt;/i&gt;');
    }

    public function test_needs_the_site_config_capability_too(): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Site configurer', 'siteconfigurer', '');
        assign_capability('moodle/site:config', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        new status_page();
    }

    public function test_needs_both_capabilities(): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Backup manager', 'backupmanager', '');
        assign_capability('tool/secbackup:manage', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        new status_page();
    }

    /** @var string The sentence of the design of the plugin (7.1) in English, with the name of the platform filled in. */
    private const EXTERNAL_EN = 'A plugin that keeps moodledata files in an external cloud service such as AWS S3 was detected. '
        . 'Running a backup may incur charges on that cloud service. '
        . '(We cannot provide support for charges incurred outside Example Platform.)';

    /** @var string The sentence of the design of the plugin (7.1) in Japanese, with the name of the platform filled in. */
    private const EXTERNAL_JA = 'AWS S3等外部クラウドサービスでmoodledata内のデータを管理しているプラグインを検知しました。'
        . 'バックアップ実行時、クラウドサービス側で課金が発生する可能性があります。'
        . '(Example Platform以外での課金発生については、弊社ではサポートしておりません)';

    /** @var string The sentence of the design of the plugin (8.2) in English. */
    private const CORE_EN = 'The core source code of the LMS being backed up (Moodle™) may have been modified. '
        . 'Backups can still be made, but restoring to a temporary site may fail. '
        . 'After each backup, always use the Preview feature to confirm that the backup can be restored.';

    /** @var string The sentence of the design of the plugin (8.2) in Japanese. */
    private const CORE_JA = 'バックアップ対象LMS (Moodle™) は、LMS本体のソースコードに変更が加えられている可能性があります。'
        . 'バックアップ自体は可能ですが、一時サイトへのリストアに失敗する可能性があります。'
        . 'バックアップ後に必ず「プレビュー」機能を利用し、リストア出来ることを確認して下さい。';

    /**
     * Makes a run that completed.
     *
     * @param int $start When it started.
     * @param int $end When it ended.
     * @param int $bytes The bytes that were sent.
     * @param int $chunks The chunks that were sent.
     * @param int|null $stored The size that the receipt named, or null for a run from before it was kept.
     * @return run_record
     */
    private static function completed(int $start, int $end, int $bytes, int $chunks, ?int $stored = null): run_record {
        $run = run_record::create('schedule', $start);
        $run->add_uploaded($bytes, $chunks);
        if ($stored !== null) {
            $run->keep(['stored_bytes' => $stored]);
        }
        $run->finish($end);
        return $run;
    }

    /**
     * The settings that the service sends, so that a backup can start.
     */
    private static function received(): void {
        set_config('siteid', fake_service::SITE_ID, 'tool_secbackup');
        settings::apply(instruction_factory::make('settings', 1, instruction_factory::settings_body()));
    }

    /**
     * Presses the button, as the page is asked for it.
     *
     * @param status_page $page The page.
     * @return redirection|null
     */
    private static function press(status_page $page): ?redirection {
        $_POST['action'] = 'start';
        $_POST['sesskey'] = sesskey();
        return $page->handle();
    }

    /**
     * A watch that finds nothing wrong.
     *
     * @return null
     */
    public static function calm() {
        return null;
    }

    /**
     * Whether the button of the page is switched off.
     *
     * @param string $html The page.
     * @return bool
     */
    private static function button_disabled(string $html): bool {
        return preg_match('~<button[^>]*\sdisabled[^>]*>\s*' . preg_quote(get_string('status_backup_now', 'tool_secbackup'), '~')
            . '~', $html) === 1;
    }

    public function test_without_a_completed_backup_the_page_says_so(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_none_completed', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('status_last_size', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('status_running', 'tool_secbackup'), $html);
    }

    public function test_the_last_completed_backup_shows_its_date_its_size_and_its_duration(): void {
        self::register();
        self::completed(1760000000, 1760000100, 1000, 1);
        self::completed(1760100000, 1760100000 + 3725, 5368709120, 40);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_last_completed', 'tool_secbackup'), $html);
        $this->assertStringContainsString(userdate(1760100000 + 3725), $html);
        $this->assertStringContainsString(
            s(get_string('status_size_value', 'tool_secbackup', (object) ['size' => display_size(5368709120), 'chunks' => 40])),
            $html
        );
        $this->assertStringContainsString(format_time(3725), $html);
        $this->assertStringNotContainsString(get_string('status_none_completed', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(display_size(1000), $html);
    }

    public function test_the_last_scheduled_start_is_shown_with_its_time_and_its_cause(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringNotContainsString(get_string('status_last_schedule', 'tool_secbackup'), $html);
        set_config('lastschedule', json_encode(['at' => 1760100000, 'answer' => 'busy', 'cause' => 'disk_low']), 'tool_secbackup');
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_last_schedule', 'tool_secbackup'), $html);
        $this->assertStringContainsString(s(get_string('status_last_schedule_value', 'tool_secbackup', (object) [
            'time' => userdate(1760100000),
            'text' => get_string('start_cause_disk_low', 'tool_secbackup'),
        ])), $html);
        $this->assertStringNotContainsString(get_string('start_reason_busy', 'tool_secbackup'), $html);
    }

    public function test_the_last_backup_says_how_many_files_it_does_not_hold(): void {
        self::register();
        $run = self::completed(1760100000, 1760100100, 2097152, 3);
        $html = (new status_page())->render();
        $this->assertStringNotContainsString(get_string('status_notbackedup', 'tool_secbackup'), $html);
        $run->keep(['notbackedup' => ['missing' => 0, 'unreadable' => 0, 'skippedlinks' => 0, 'secret' => 0]]);
        $this->assertStringNotContainsString(get_string('status_notbackedup', 'tool_secbackup'), (new status_page())->render());
        $run->keep(['notbackedup' => ['missing' => 2, 'unreadable' => 3, 'skippedlinks' => 4, 'secret' => 0]]);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_notbackedup', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string(
            'status_notbackedup_value',
            'tool_secbackup',
            (object) ['missing' => 2, 'unreadable' => 3, 'links' => 4]
        ), $html);
        $this->assertStringNotContainsString(s(get_string('status_secret_unreadable', 'tool_secbackup', 1)), $html);
        // The keys of the site could not be read: a backup that is not enough for a restore, said loudly.
        $run->keep(['notbackedup' => ['missing' => 0, 'unreadable' => 1, 'skippedlinks' => 0, 'secret' => 1]]);
        $html = (new status_page())->render();
        $this->assertStringContainsString(
            htmlspecialchars(get_string('status_secret_unreadable', 'tool_secbackup', 1), ENT_NOQUOTES),
            $html
        );
        // Values that are not counts read as none.
        $run->keep(['notbackedup' => ['missing' => 'x']]);
        $this->assertSame(['missing' => 0, 'unreadable' => 0, 'skippedlinks' => 0, 'secret' => 0], $run->not_backed_up());
    }

    public function test_the_size_of_the_backup_is_the_one_of_the_receipt_and_the_sent_bytes_come_second(): void {
        self::register();
        self::completed(1760100000, 1760100100, 2097152, 3, 7340032);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_last_stored', 'tool_secbackup'), $html);
        $this->assertStringContainsString(display_size(7340032), $html);
        $this->assertStringContainsString(get_string('status_last_size', 'tool_secbackup'), $html);
        $this->assertStringContainsString(display_size(2097152), $html);
    }

    public function test_a_run_from_before_the_size_was_kept_shows_only_the_sent_bytes(): void {
        self::register();
        self::completed(1760100000, 1760100100, 2097152, 3);
        $html = (new status_page())->render();
        $this->assertStringNotContainsString(get_string('status_last_stored', 'tool_secbackup'), $html);
        $this->assertStringContainsString(display_size(2097152), $html);
    }

    public function test_a_backup_in_progress_shows_its_stage_and_what_was_sent(): void {
        self::register();
        $run = run_record::create('manual', 1760000000);
        $run->set_stage('files', ['cursor' => 1], 1760000050);
        $run->add_uploaded(3145728, 7);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_running', 'tool_secbackup'), $html);
        $this->assertStringContainsString(s(get_string('status_running_value', 'tool_secbackup', (object) [
            'stage' => get_string('stage_files', 'tool_secbackup'),
            'started' => userdate(1760000000),
            'size' => display_size(3145728),
            'chunks' => 7,
        ])), $html);
        $this->assertTrue(self::button_disabled($html));
        $this->assertStringContainsString(get_string('status_backup_running', 'tool_secbackup'), $html);
    }

    public function test_a_waiting_backup_shows_when_it_is_tried_again(): void {
        self::register();
        $run = run_record::create('schedule', 1760000000);
        $run->set_stage('db', [], 1760000050);
        $run->pause(1760000060, 1760003600);
        $html = (new status_page())->render();
        $this->assertStringContainsString(s(get_string('status_paused_value', 'tool_secbackup', (object) [
            'stage' => get_string('stage_db', 'tool_secbackup'),
            'started' => userdate(1760000000),
            'size' => display_size(0),
            'chunks' => 0,
            'next' => userdate(1760003600),
        ])), $html);
        $this->assertTrue(self::button_disabled($html));
    }

    public function test_a_backup_that_waits_after_a_failed_step_says_so(): void {
        self::register();
        $run = run_record::create('schedule', 1760000000);
        $run->set_stage('files', [], 1760000050);
        $run->keep(['lasterror' => 'RuntimeException <b>']);
        $run->pause(1760000060, 1760000360);
        $html = (new status_page())->render();
        $this->assertStringContainsString(s(get_string('status_failed_value', 'tool_secbackup', (object) [
            'stage' => get_string('stage_files', 'tool_secbackup'),
            'started' => userdate(1760000000),
            'size' => display_size(0),
            'chunks' => 0,
            'error' => 'RuntimeException <b>',
            'next' => userdate(1760000360),
        ])), $html);
        $this->assertStringNotContainsString('RuntimeException <b>', $html);
    }

    public function test_every_stage_of_a_run_has_a_name(): void {
        foreach (run_record::STAGES as $stage) {
            $this->assertTrue(get_string_manager()->string_exists('stage_' . $stage, 'tool_secbackup'), $stage);
        }
    }

    public function test_the_button_is_on_when_no_backup_is_in_progress(): void {
        self::register();
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_backup_now', 'tool_secbackup'), $html);
        $this->assertFalse(self::button_disabled($html));
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('name="action" value="start"', $html);
        $this->assertStringNotContainsString(get_string('status_backup_running', 'tool_secbackup'), $html);
    }

    public function test_a_site_that_is_not_registered_has_no_button(): void {
        $this->assertStringNotContainsString(get_string('status_backup_now', 'tool_secbackup'), (new status_page())->render());
    }

    public function test_an_aborted_backup_gives_every_reason_and_what_to_do(): void {
        global $DB;
        self::register();
        $run = run_record::create('manual', 1760000000);
        $run->abort('disk_low', 1760000100);
        foreach (abort_reason::codes() as $code) {
            $DB->set_field('tool_secbackup_runs', 'abortcode', $code, ['id' => $run->id()]);
            $html = (new status_page())->render();
            // The notice goes through the cleaning of HTML, which leaves quotation marks as they are.
            $this->assertStringContainsString(
                htmlspecialchars(get_string('status_aborted', 'tool_secbackup', userdate(1760000000)), ENT_NOQUOTES),
                $html,
                $code
            );
            $this->assertStringContainsString(
                htmlspecialchars(get_string('abort_' . $code, 'tool_secbackup'), ENT_NOQUOTES),
                $html,
                $code
            );
            $this->assertStringContainsString(
                htmlspecialchars(
                    get_string('status_advice', 'tool_secbackup', get_string('abort_' . $code . '_advice', 'tool_secbackup')),
                    ENT_NOQUOTES
                ),
                $html,
                $code
            );
        }
    }

    public function test_a_denial_of_selinux_gives_the_advice_of_what_was_denied(): void {
        self::register();
        $run = run_record::create('manual', 1760000000);
        $run->abort('selinux_denied', 1760000100, ['denial' => 'network']);
        $html = (new status_page())->render();
        $advice = get_string('selinux_denial_network', 'tool_secbackup', limits::current()->tempdir);
        $this->assertStringContainsString(
            htmlspecialchars(get_string('status_advice', 'tool_secbackup', $advice), ENT_NOQUOTES),
            $html
        );
    }

    public function test_a_stop_for_a_content_names_the_file_escaped_once(): void {
        self::register();
        $hash = str_repeat('cd', 20);
        $run = run_record::create('manual', 1760000000);
        $run->abort('tamper_detected', 1760000100, ['file' => ['contenthash' => $hash, 'path' => 'filedir/cd/cd/' . $hash,
            'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 3, 'filename' => '<b>a&b.txt</b>']]);
        $html = (new status_page())->render();
        $this->assertStringContainsString('filedir/cd/cd/' . $hash, $html);
        $this->assertStringContainsString('&lt;b&gt;a&amp;b.txt&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>a&b.txt</b>', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    public function test_a_reason_without_a_text_of_its_own_is_named_and_escaped_once(): void {
        self::register();
        run_record::create('manual', 1760000000)->abort('<u>x</u>', 1760000100);
        $html = (new status_page())->render();
        $this->assert_escaped_once($html, '<u>x</u>', '&lt;u&gt;x&lt;/u&gt;');
        $this->assertStringContainsString(s(get_string('abort_unknown_advice', 'tool_secbackup')), $html);
    }

    public function test_an_aborted_backup_is_hidden_by_a_newer_one(): void {
        self::register();
        run_record::create('manual', 1760000000)->abort('disk_low', 1760000100);
        self::completed(1760000200, 1760000300, 10, 1);
        $this->assertStringNotContainsString(s(get_string('abort_disk_low', 'tool_secbackup')), (new status_page())->render());
        run_record::create('manual', 1760000400);
        $this->assertStringNotContainsString(s(get_string('abort_disk_low', 'tool_secbackup')), (new status_page())->render());
    }

    /**
     * The refusals of the service that stop a backup before it starts.
     *
     * @return array
     */
    public static function refusal_provider(): array {
        return [
            'the number of backups of the plan' => ['run_limit_reached'],
            'a contract that is not active' => ['contract_inactive'],
            'a refusal this plugin does not know' => ['something_new'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusal_provider')]
    /**
     * A backup that the service did not start is shown with its own text and advice, not as a stop.
     *
     * @dataProvider refusal_provider
     * @param string $code The error code of the service.
     */
    public function test_a_backup_that_the_service_did_not_start_is_shown(string $code): void {
        self::register();
        run_record::create('manual', 1760000000)->refuse($code, 1760000001);
        $html = (new status_page())->render();
        $known = $code !== 'something_new';
        $a = $known ? 'Example Platform' : $code;
        $id = $known ? 'refused_' . $code : 'refused_unknown';
        $this->assertStringContainsString(s(get_string('status_refused', 'tool_secbackup', userdate(1760000000))), $html);
        $this->assertStringContainsString(s(get_string($id, 'tool_secbackup', $a)), $html);
        $this->assertStringContainsString(
            s(get_string('status_advice', 'tool_secbackup', get_string($id . '_advice', 'tool_secbackup', $a))),
            $html
        );
        $this->assertStringNotContainsString(s(get_string('status_aborted', 'tool_secbackup', userdate(1760000000))), $html);
    }

    public function test_a_refusal_for_the_number_of_backups_says_when_one_can_start_again(): void {
        self::register();
        $refused = time() - 60;
        run_record::create('manual', $refused - 1)->refuse('run_limit_reached', $refused, 3600);
        $this->assertStringContainsString(
            s(get_string('refused_run_limit_reached_at', 'tool_secbackup', userdate($refused + 3600))),
            (new status_page())->render()
        );
    }

    public function test_a_refusal_for_the_number_of_backups_whose_time_has_come_says_no_time(): void {
        self::register();
        $refused = time() - 3600;
        run_record::create('manual', $refused - 1)->refuse('run_limit_reached', $refused, 1800);
        $html = (new status_page())->render();
        $this->assertStringContainsString(s(get_string('refused_run_limit_reached', 'tool_secbackup')), $html);
        $this->assertStringNotContainsString(s(userdate($refused + 1800)), $html);
    }

    public function test_a_backup_that_started_after_a_refusal_hides_it(): void {
        self::register();
        run_record::create('manual', 1760000000)->refuse('contract_inactive', 1760000001);
        run_record::create('manual', 1760000100);
        $this->assertStringNotContainsString(
            s(get_string('status_refused', 'tool_secbackup', userdate(1760000000))),
            (new status_page())->render()
        );
    }

    public function test_nothing_happens_without_the_button(): void {
        self::register();
        self::received();
        $this->assertNull((new status_page([self::class, 'calm']))->handle());
        $this->assertSame([], run_record::recent(1));
    }

    public function test_the_button_queues_a_backup_and_cannot_be_pressed_again(): void {
        self::register();
        self::received();
        $page = new status_page([self::class, 'calm']);
        $redirection = self::press($page);
        $this->assertSame('manual', run_record::active()->trigger());
        $this->assertSame(get_string('start_started', 'tool_secbackup'), $redirection->message);
        $this->assertSame(\core\output\notification::NOTIFY_SUCCESS, $redirection->type);
        $this->assertSame('/admin/tool/secbackup/index.php', $redirection->url->out_as_local_url(false));
        // The page now shows the button off, and a second press starts nothing.
        $this->assertTrue(self::button_disabled((new status_page())->render()));
        $again = self::press($page);
        $this->assertSame(get_string('start_running', 'tool_secbackup'), $again->message);
        $this->assertSame(\core\output\notification::NOTIFY_INFO, $again->type);
        $this->assertCount(1, run_record::recent(5));
    }

    public function test_the_reasons_for_not_starting_are_told(): void {
        self::register();
        $reasons = [];
        // The settings have not come from the service.
        $reasons['notreceived'] = self::press(new status_page([self::class, 'calm']))->message;
        self::received();
        // The server is too busy.
        $reasons['busy'] = self::press(new status_page(static fn(): string => 'server_busy'))->message;
        // The clocks differ.
        state::record_poll(1760000300, null, 'api_timestamp_out_of_range_minutes', '9', 540);
        $reasons['clock'] = self::press(new status_page([self::class, 'calm']))->message;
        foreach ($reasons as $reason => $message) {
            $this->assertSame(get_string('start_' . $reason, 'tool_secbackup'), $message, $reason);
        }
        $this->assertSame([], run_record::recent(1));
        $this->assertSame(\core\output\notification::NOTIFY_WARNING, self::press(new status_page([self::class, 'calm']))->type);
    }

    public function test_a_site_that_is_not_registered_is_told_so(): void {
        $this->assertSame(
            get_string('start_notregistered', 'tool_secbackup'),
            self::press(new status_page([self::class, 'calm']))->message
        );
    }

    public function test_every_answer_of_the_starter_has_a_message_in_both_languages(): void {
        foreach (['en', 'ja'] as $lang) {
            $string = [];
            include(\core_component::get_component_directory('tool_secbackup') . '/lang/' . $lang . '/tool_secbackup.php');
            foreach (starter::START_NOW_ANSWERS as $answer) {
                $this->assertNotEmpty($string['start_' . $answer] ?? '', $lang . ' ' . $answer);
            }
        }
    }

    public function test_the_button_needs_the_session_key(): void {
        self::register();
        self::received();
        $_POST['action'] = 'start';
        $this->expectException(\moodle_exception::class);
        try {
            (new status_page([self::class, 'calm']))->handle();
        } finally {
            $this->assertSame([], run_record::recent(1));
        }
    }

    /**
     * What the page asks the dialog of Moodle to show.
     *
     * @return string The code that the page adds at its end.
     */
    private static function dialogs(): string {
        global $PAGE;
        return $PAGE->requires->get_end_code();
    }

    /**
     * The dialogs of a page, as the texts that they show.
     *
     * @param string $code The code that the page adds at its end.
     * @return string[]
     */
    private static function dialog_texts(string $code): array {
        preg_match_all('~core/notification.{0,40}?alert\((.*?)\);~s', $code, $matches);
        $texts = [];
        foreach ($matches[1] as $arguments) {
            $texts[] = json_decode('[' . $arguments . ']', true)[1];
        }
        return $texts;
    }

    public function test_no_dialog_is_shown_for_a_site_that_needs_none(): void {
        self::register();
        (new status_page(null, new external_storage(static fn() => null)))->render();
        $this->assertSame([], self::dialog_texts(self::dialogs()));
    }

    public function test_external_storage_is_warned_about_with_the_sentence_of_the_design(): void {
        global $CFG;
        self::register();
        $CFG->alternative_file_system_class = '\file_system_filedir';
        (new status_page())->render();
        $this->assertSame([self::EXTERNAL_EN], self::dialog_texts(self::dialogs()));
    }

    public function test_a_modified_program_is_warned_about_with_the_sentence_of_the_design(): void {
        self::register();
        set_config('coremodified', 1, 'tool_secbackup');
        (new status_page(null, new external_storage(static fn() => null)))->render();
        $this->assertSame([self::CORE_EN], self::dialog_texts(self::dialogs()));
    }

    public function test_both_warnings_are_shown_when_both_apply(): void {
        global $CFG;
        self::register();
        $CFG->alternative_file_system_class = '\file_system_filedir';
        set_config('coremodified', 1, 'tool_secbackup');
        (new status_page())->render();
        $this->assertSame([self::EXTERNAL_EN, self::CORE_EN], self::dialog_texts(self::dialogs()));
    }

    public function test_a_program_that_is_not_modified_is_not_warned_about(): void {
        self::register();
        set_config('coremodified', 0, 'tool_secbackup');
        (new status_page(null, new external_storage(static fn() => null)))->render();
        $this->assertSame([], self::dialog_texts(self::dialogs()));
    }

    public function test_the_warnings_are_not_shown_before_the_registration(): void {
        global $CFG;
        $CFG->alternative_file_system_class = '\file_system_filedir';
        set_config('coremodified', 1, 'tool_secbackup');
        (new status_page())->render();
        $this->assertSame([], self::dialog_texts(self::dialogs()));
    }

    public function test_the_warnings_are_the_sentences_of_the_design_in_both_languages(): void {
        $string = [];
        include(\core_component::get_component_directory('tool_secbackup') . '/lang/ja/tool_secbackup.php');
        $this->assertSame(self::CORE_JA, $string['modal_core']);
        $this->assertSame(self::EXTERNAL_JA, str_replace('{$a}', 'Example Platform', $string['modal_external']));
        $string = [];
        include(\core_component::get_component_directory('tool_secbackup') . '/lang/en/tool_secbackup.php');
        $this->assertSame(self::CORE_EN, $string['modal_core']);
        $this->assertSame(self::EXTERNAL_EN, str_replace('{$a}', 'Example Platform', $string['modal_external']));
    }

    public function test_the_name_of_the_platform_in_the_warning_is_the_one_received_at_the_registration(): void {
        global $CFG;
        self::register();
        set_config('platformname', 'Other Platform', 'tool_secbackup');
        $CFG->alternative_file_system_class = '\file_system_filedir';
        (new status_page())->render();
        $this->assertSame(
            [str_replace('Example Platform', 'Other Platform', self::EXTERNAL_EN)],
            self::dialog_texts(self::dialogs())
        );
    }
}
