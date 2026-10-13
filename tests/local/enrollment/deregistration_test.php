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

namespace tool_secbackup\local\enrollment;

use tool_secbackup\event\site_deregistered;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\deregistration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\notifier::class)]
/**
 * Tests for removing the registration.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\deregistration
 * @covers     \tool_secbackup\local\enrollment\notifier
 */
final class deregistration_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        // The observers a test replaced are not given back to the next test by every Moodle version.
        \core\event\manager::phpunit_reset();
        parent::tearDown();
    }

    public function test_run_forgets_logs_and_tells_the_admins(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
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
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();

        deregistration::run();

        $this->assertFalse(state::load()->is_enrolled());
        $this->assertNull(signing_keys::load());
        $logged = array_values(array_filter($events->get_events(), static fn($event): bool => $event instanceof site_deregistered));
        $this->assertSame(['endpoint' => 'https://app.example.test/api/lms-backup/v1',
            'siteid' => '11111111-2222-4333-8444-555555555555'], $logged[0]->other);
        $sent = $messages->get_messages();
        $this->assertCount(count(get_admins()), $sent);
        $this->assertStringContainsString('Example Platform', $sent[0]->subject);
    }

    /**
     * Saves a registration.
     */
    private function enrolled(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
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

    public function test_every_administrator_is_told_that_the_registration_was_removed(): void {
        $second = $this->getDataGenerator()->create_user();
        set_config('siteadmins', get_config('core', 'siteadmins') . ',' . $second->id);
        $this->enrolled();
        $messages = $this->redirectMessages();

        deregistration::run();

        $sent = $messages->get_messages();
        $this->assertEqualsCanonicalizing(
            [(int) get_admin()->id, (int) $second->id],
            array_map(static fn($message): int => (int) $message->useridto, $sent)
        );
        foreach ($sent as $message) {
            $this->assertSame('The registration with Example Platform was removed', $message->subject);
            $this->assertStringContainsString('removed the registration of this site with', $message->fullmessage);
        }
    }

    public function test_a_failure_to_log_does_not_leave_run(): void {
        // Moodle holds events back while a transaction is open, and PHPUnit wraps each test in one.
        $this->preventResetByRollback();
        $this->enrolled();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\tool_secbackup\event\site_deregistered',
            'callback' => static function (): void {
                throw new \Error('Provoked by the test');
            },
            'internal' => false,
            'priority' => 0,
            'includefile' => null,
        ]]);
        $messages = $this->redirectMessages();

        deregistration::run();

        $this->assertDebuggingCalled('The removal of the registration could not be logged: Error', DEBUG_DEVELOPER);
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertCount(count(get_admins()), $messages->get_messages());
    }

    public function test_a_failure_to_tell_the_administrators_does_not_leave_run(): void {
        $this->enrolled();
        unset_config('popup_provider_tool_secbackup_connection_locked', 'message');

        deregistration::run();

        $this->assertDebuggingCalledCount(1);
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertNull(signing_keys::load());
    }

    public function test_run_without_a_registration_does_nothing(): void {
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        deregistration::run();
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
    }

    /**
     * Typed confirmations.
     *
     * @return array
     */
    public static function confirmation_provider(): array {
        return [
            'same' => ['Example site', 'Example site', true],
            'surrounding spaces' => ["  Example site\n", 'Example site', true],
            'full width' => [mb_convert_kana('Example site', 'AS', 'UTF-8'), 'Example site', true],
            'other case' => ['example site', 'Example site', false],
            'other name' => ['Example', 'Example site', false],
            'empty' => ['', '', false],
            'invalid utf-8' => ["\xff", "\xff", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmation_provider')]
    /**
     * The typed confirmation is compared with the site name.
     *
     * @dataProvider confirmation_provider
     * @param string $typed Typed name.
     * @param string $sitename Site name.
     * @param bool $expected Whether it matches.
     */
    public function test_confirmation(string $typed, string $sitename, bool $expected): void {
        $this->assertSame($expected, deregistration::confirmation_matches($typed, $sitename));
    }
}
