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

    public function test_developer_banner(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['allowhttp' => true];
        $this->assertStringContainsString(get_string('developer_banner', 'tool_secbackup'), (new status_page())->render());
    }

    public function test_registered_site(): void {
        self::register();
        state::record_poll(1760000300, new status_result('active', 1760000305, 5, 0));
        $html = (new status_page())->render();
        $this->assertStringContainsString('Example Platform', $html);
        $this->assertStringContainsString('Example &lt;b&gt;site&lt;/b&gt;', $html);
        $this->assertStringContainsString('https://app.example.test/api/lms-backup/v1', $html);
        $this->assertStringContainsString('A260 B405 0FD2 8D2B 313C 9A94', $html);
        $this->assertStringContainsString(get_string('service_status_active', 'tool_secbackup'), $html);
        $this->assertStringContainsString(userdate(1760000300), $html);
        $this->assertStringNotContainsString(get_string('status_last_error', 'tool_secbackup'), $html);
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
        state::record_poll(1760000300, new status_result('active', 1760000360, 60, 0));
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
        state::record_poll(1760000300, new status_result('suspended', 1760000300, 0, 0));
        $this->assertStringContainsString(
            get_string('service_status_other', 'tool_secbackup', 'suspended'),
            (new status_page())->render()
        );
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
}
