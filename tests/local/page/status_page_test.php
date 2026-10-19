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
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\enrollment\state;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\status_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\output\renderer::class)]
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

    /**
     * The refusals of the service that stop a backup before it starts.
     *
     * @return array
     */
    public static function refusal_provider(): array {
        return [
            'the number of backups of the plan' => ['run_limit_reached'],
            'a contract that is not active' => ['contract_inactive'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusal_provider')]
    /**
     * A backup that the service did not start is shown with the reason.
     *
     * @dataProvider refusal_provider
     * @param string $code The error code of the service.
     */
    public function test_a_backup_that_the_service_did_not_start_is_shown(string $code): void {
        $this->resetAfterTest();
        self::register();
        \tool_secbackup\local\backup\run_record::create('manual', 1760000000)->refuse($code, 1760000001);
        $html = (new status_page())->render();
        $this->assertStringContainsString(get_string('status_start_refused', 'tool_secbackup'), $html);
        $this->assertStringContainsString(s(get_string('api_' . $code, 'tool_secbackup')), $html);
    }

    public function test_a_backup_that_started_after_a_refusal_hides_it(): void {
        $this->resetAfterTest();
        self::register();
        \tool_secbackup\local\backup\run_record::create('manual', 1760000000)->refuse('contract_inactive', 1760000001);
        \tool_secbackup\local\backup\run_record::create('manual', 1760000100);
        $html = (new status_page())->render();
        $this->assertStringNotContainsString(get_string('status_start_refused', 'tool_secbackup'), $html);
    }
}
