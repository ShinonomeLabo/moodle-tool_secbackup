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
use tool_secbackup\form\connection_form;
use tool_secbackup\form\deregister_form;
use tool_secbackup\form\register_form;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\enroller;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\connect_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\redirection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\form\connection_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\form\register_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\form\deregister_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\output\renderer::class)]
/**
 * Tests for the connection settings page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\page\connect_page
 * @covers     \tool_secbackup\local\page\redirection
 * @covers     \tool_secbackup\form\connection_form
 * @covers     \tool_secbackup\form\register_form
 * @covers     \tool_secbackup\form\deregister_form
 * @covers     \tool_secbackup\output\renderer
 */
final class connect_page_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The clocks. */
    private const NOW = 1760000000;

    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();
        network_fakes::no_security_list();
        $PAGE->set_url(new \moodle_url('/admin/tool/secbackup/connect.php'));
        $PAGE->set_context(\context_system::instance());
    }

    /**
     * A fake service with a fixed clock.
     *
     * @param array $state Changes to the initial state.
     * @return fake_service
     */
    private static function service(array $state = []): fake_service {
        return new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW], $state));
    }

    /**
     * The page, registering through the fake service.
     *
     * @param fake_service $service The fake service.
     * @param int $now This server's clock.
     * @return connect_page
     */
    private static function page(fake_service $service, int $now = self::NOW): connect_page {
        return new connect_page(new enroller(network_fakes::client($service->handler(), $now), static fn(): int => $now));
    }

    /**
     * Submits the second step.
     *
     * @param array $changes Changes to the submitted values.
     */
    private static function submit_registration(array $changes = []): void {
        register_form::mock_submit(array_merge([
            'stage' => 'register',
            'endpoint' => self::API,
            'publickey' => fake_service::ENCRYPTION_KEY,
            'confirmfingerprint' => 1,
            'code' => 'sec-bk-aaaa-bbbb-cccc-dddd-eeee',
        ], $changes));
    }

    /**
     * Registers the site through the fake service, without the page.
     *
     * @param fake_service $service The fake service.
     */
    private static function register(fake_service $service): void {
        (new enroller(network_fakes::client($service->handler(), self::NOW)))->enroll(
            endpoint::parse(self::API, developer_settings::from_value(null)),
            public_key_format::parse(fake_service::ENCRYPTION_KEY),
            fake_service::CODE
        );
    }

    public function test_first_step(): void {
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('name="endpoint"', $html);
        $this->assertStringContainsString('name="publickey"', $html);
        $this->assertStringNotContainsString('name="code"', $html);
        $this->assertStringNotContainsString('name="confirmname"', $html);
    }

    public function test_valid_first_step_shows_the_fingerprint(): void {
        connection_form::mock_submit([
            'stage' => 'connection',
            'endpoint' => self::API . '/',
            'publickey' => ' ' . fake_service::ENCRYPTION_KEY . "\n",
        ]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('A260 B405 0FD2 8D2B 313C 9A94', $html);
        $this->assertStringContainsString('name="code"', $html);
        $this->assertStringContainsString('value="' . self::API . '"', $html);
        $this->assertStringContainsString('value="' . fake_service::ENCRYPTION_KEY . '"', $html);
    }

    public function test_invalid_first_step_explains_the_problems(): void {
        connection_form::mock_submit([
            'stage' => 'connection',
            'endpoint' => 'app.example.test/api',
            'publickey' => 'secbk1:AQEB',
        ]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(get_string('endpoint_invalid', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('publickey_length', 'tool_secbackup'), $html);
    }

    public function test_registration(): void {
        self::submit_registration();
        $redirection = self::page(self::service())->handle();
        $this->assertSame('/admin/tool/secbackup/index.php', $redirection->url->out_as_local_url(false));
        $this->assertSame(get_string('enroll_done', 'tool_secbackup', fake_service::PLATFORM_NAME), $redirection->message);
        $this->assertSame(notification::NOTIFY_SUCCESS, $redirection->type);
        $this->assertTrue(state::load()->is_enrolled());
        $this->assertSame([], \core\notification::fetch());
    }

    public function test_registration_with_a_clock_difference_warns(): void {
        self::submit_registration();
        $this->assertNotNull(self::page(self::service(), self::NOW - 200)->handle());
        $warnings = array_map(static fn($n): string => $n->get_message(), \core\notification::fetch());
        $this->assertSame([get_string('clock_skew_warning', 'tool_secbackup', 3)], $warnings);
    }

    public function test_refused_registration_shows_the_reason(): void {
        self::submit_registration(['code' => 'SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assertStringContainsString(get_string('api_enrollment_code_invalid', 'tool_secbackup'), $page->render());
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_untrusted_answer_advises_a_new_code(): void {
        self::submit_registration();
        $page = self::page(self::service(['mode' => 'untrusted_key']));
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(get_string('envelope_untrusted', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('enroll_reissue_advice', 'tool_secbackup'), $html);
    }

    public function test_fingerprint_must_be_confirmed(): void {
        self::submit_registration(['confirmfingerprint' => 0]);
        $service = self::service();
        $page = self::page($service);
        $this->assertNull($page->handle());
        $this->assertStringContainsString(get_string('field_confirmfingerprint_required', 'tool_secbackup'), $page->render());
        $this->assertSame([], $service->state['requests'], 'Nothing may be sent.');
    }

    public function test_code_must_look_like_a_code(): void {
        self::submit_registration(['code' => 'nonsense']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assertStringContainsString(get_string('field_code_invalid', 'tool_secbackup'), $page->render());
    }

    public function test_changed_hidden_values_start_again(): void {
        self::submit_registration(['endpoint' => 'ftp://app.example.test/api']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(get_string('endpoint_scheme', 'tool_secbackup'), $html);
        $this->assertStringContainsString('name="publickey"', $html);
        $this->assertStringNotContainsString('name="code"', $html);
    }

    public function test_cancel_goes_back_to_the_first_step(): void {
        self::submit_registration(['cancel' => 'Cancel']);
        $redirection = self::page(self::service())->handle();
        $this->assertSame('/admin/tool/secbackup/connect.php', $redirection->url->out_as_local_url(false));
        $this->assertSame('', $redirection->message);
    }

    public function test_registered_site_keeps_its_url_and_can_be_removed(): void {
        self::register(self::service());
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(self::API, $html);
        $this->assertStringContainsString(get_string('field_endpoint_fixed', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString('name="endpoint"', $html);
        $this->assertStringContainsString(get_string('connect_again_heading', 'tool_secbackup'), $html);
        $this->assertStringContainsString('name="confirmname"', $html);
    }

    public function test_registered_site_first_step_keeps_the_saved_url(): void {
        $service = self::service();
        self::register($service);
        connection_form::mock_submit(['stage' => 'connection', 'publickey' => fake_service::ENCRYPTION_KEY]);
        $page = self::page($service);
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('name="code"', $html);
        $this->assertStringNotContainsString(
            'name="confirmname"',
            $html,
            'The removal form is not shown in the second step.'
        );
    }

    public function test_registering_again_ignores_a_changed_url(): void {
        $service = self::service();
        self::register($service);
        $service->state['codeused'] = false;
        self::submit_registration(['endpoint' => 'https://other.example.test/api/lms-backup/v1']);
        $this->assertNotNull(self::page($service)->handle());
        $this->assertSame(self::API, state::load()->endpoint_url());
    }

    public function test_removal_needs_the_site_name(): void {
        self::register(self::service());
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => 'Wrong site']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assertStringContainsString(get_string('field_confirmname_mismatch', 'tool_secbackup'), $page->render());
        $this->assertTrue(state::load()->is_enrolled());
    }

    public function test_removal(): void {
        self::register(self::service());
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => ' ' . fake_service::SITE_NAME . ' ']);
        $redirection = self::page(self::service())->handle();
        $this->assertSame('/admin/tool/secbackup/index.php', $redirection->url->out_as_local_url(false));
        $this->assertSame(get_string('deregister_done', 'tool_secbackup', fake_service::PLATFORM_NAME), $redirection->message);
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_create_without_an_enroller(): void {
        $page = new connect_page();
        $this->assertNull($page->handle());
    }
}
