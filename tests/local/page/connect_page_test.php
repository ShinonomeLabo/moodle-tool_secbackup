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
use tool_secbackup\local\crypto\signing_keys;
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

    /** @var string A name that holds markup. */
    private const ODD = '<b>A&B</b>';

    /** @var string That name, escaped once. */
    private const ODD_ESCAPED = '&lt;b&gt;A&amp;B&lt;/b&gt;';

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

    public function test_a_site_that_is_not_registered_is_not_offered_to_register_again_or_to_remove(): void {
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringNotContainsString(get_string('connect_again_heading', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('connect_again_explain', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('deregister_heading', 'tool_secbackup'), $html);
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

    public function test_problems_are_shown_as_error_notifications(): void {
        global $PAGE;
        self::submit_registration(['code' => 'SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $expected = $PAGE->get_renderer('tool_secbackup')
            ->error_message(get_string('api_enrollment_code_invalid', 'tool_secbackup'));
        $this->assertStringContainsString($expected, $page->render());
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

    /**
     * Checks that a text came out escaped once: the escaped text is there, the raw text and the twice escaped text are not.
     *
     * @param string $text The page or the message.
     */
    private function assert_odd_name_escaped_once(string $text): void {
        $this->assertStringContainsString(self::ODD_ESCAPED, $text);
        $this->assertStringNotContainsString(self::ODD, $text);
        $this->assertStringNotContainsString('&amp;lt;', $text);
        $this->assertStringNotContainsString('&amp;amp;', $text);
    }

    public function test_the_message_after_a_registration_escapes_the_platform_name_once(): void {
        self::submit_registration();
        $redirection = self::page(self::service(['platformname' => self::ODD]))->handle();
        $this->assertSame(get_string('enroll_done', 'tool_secbackup', self::ODD_ESCAPED), $redirection->message);
        $this->assert_odd_name_escaped_once($redirection->message);
    }

    public function test_the_removal_form_escapes_the_names_once(): void {
        self::register(self::service(['sitename' => self::ODD, 'platformname' => self::ODD]));
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assert_odd_name_escaped_once($html);
        $this->assertStringContainsString(get_string('deregister_explain', 'tool_secbackup', self::ODD_ESCAPED), $html);
        $this->assertStringContainsString(get_string('deregister_typename', 'tool_secbackup', self::ODD_ESCAPED), $html);
    }

    public function test_the_message_after_a_removal_escapes_the_platform_name_once(): void {
        self::register(self::service(['sitename' => self::ODD, 'platformname' => self::ODD]));
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => self::ODD]);
        $redirection = self::page(self::service())->handle();
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertSame(get_string('deregister_done', 'tool_secbackup', self::ODD_ESCAPED), $redirection->message);
        $this->assert_odd_name_escaped_once($redirection->message);
    }

    public function test_problems_found_by_the_first_step_are_escaped_once(): void {
        // The texts of these two problems hold quotation marks, which an escaped text shows as entities.
        connection_form::mock_submit([
            'stage' => 'connection',
            'endpoint' => 'https://app.example.test/api?x=1',
            'publickey' => 'abc',
        ]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('&quot;?&quot; or &quot;#&quot;', $html);
        $this->assertStringContainsString('&quot;secbk1:&quot;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
    }

    /**
     * Checks that the page refuses the request it is about to handle, and that nothing happened.
     *
     * @param fake_service $service The fake service the page would have used.
     * @param bool $missing Whether the request has no session key at all. Moodle then reports the missing parameter,
     *     and not an invalid key.
     */
    private function assert_refused_for_the_session_key(fake_service $service, bool $missing = false): void {
        $page = self::page($service);
        try {
            $page->handle();
            $this->fail('The request must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame($missing ? 'missingparam' : 'invalidsesskey', $e->errorcode);
            $this->assertSame($missing ? 'sesskey' : null, $e->a);
        }
        // Counted outside the fake service's handler: nothing was sent to it.
        $this->assertSame([], $service->state['requests']);
    }

    public function test_the_second_step_without_a_session_key_is_refused_before_anything_is_read(): void {
        $_POST = ['stage' => 'register', 'endpoint' => self::API, 'publickey' => fake_service::ENCRYPTION_KEY];
        $this->assert_refused_for_the_session_key(self::service(), true);
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_the_second_step_with_a_wrong_session_key_is_refused(): void {
        $_POST = [
            'stage' => 'register',
            'endpoint' => self::API,
            'publickey' => fake_service::ENCRYPTION_KEY,
            'sesskey' => 'wrong',
        ];
        $this->assert_refused_for_the_session_key(self::service());
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_the_second_step_from_a_link_is_refused(): void {
        $_GET = ['stage' => 'register', 'endpoint' => self::API, 'publickey' => fake_service::ENCRYPTION_KEY];
        $this->assert_refused_for_the_session_key(self::service(), true);
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_the_second_step_of_a_registered_site_without_a_session_key_is_refused(): void {
        self::register(self::service());
        $_POST = ['stage' => 'register', 'publickey' => fake_service::ENCRYPTION_KEY];
        $this->assert_refused_for_the_session_key(self::service(), true);
        $this->assertTrue(state::load()->is_enrolled());
    }

    public function test_the_removal_with_a_wrong_session_key_is_refused(): void {
        self::register(self::service());
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => fake_service::SITE_NAME]);
        $_POST['sesskey'] = 'wrong';
        $this->assert_refused_for_the_session_key(self::service());
        $this->assertTrue(state::load()->is_enrolled());
    }

    /**
     * The stored settings of the plugin.
     *
     * @return array
     */
    private static function stored(): array {
        global $DB;
        return $DB->get_records_menu('config_plugins', ['plugin' => 'tool_secbackup'], 'name', 'name, value');
    }

    /**
     * Makes saving a signing key fail: Moodle has no key for its encryption and may not create one.
     */
    private function make_saving_the_key_fail(): void {
        global $CFG;
        $CFG->secretdataroot = make_request_directory();
        $CFG->nokeygeneration = true;
    }

    public function test_a_failed_save_after_the_service_accepted_says_the_code_is_used_up(): void {
        // Moodle holds events back while a transaction is open, and PHPUnit wraps each test in one.
        $this->preventResetByRollback();
        self::submit_registration();
        $before = self::stored();
        $this->make_saving_the_key_fail();
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(s(get_string('signingkey_savefailed', 'tool_secbackup')), $html);
        $this->assertStringContainsString(s(get_string('enroll_code_used_advice', 'tool_secbackup')), $html);
        $this->assertSame($before, self::stored());
        $this->assertFalse(state::load()->is_enrolled());
    }

    public function test_a_failed_save_of_a_registration_made_again_says_the_code_is_used_up(): void {
        $this->preventResetByRollback();
        $service = self::service();
        self::register($service);
        $service->state['codeused'] = false;
        $before = self::stored();
        self::submit_registration();
        $this->make_saving_the_key_fail();
        $page = self::page($service);
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString(s(get_string('signingkey_savefailed', 'tool_secbackup')), $html);
        $this->assertStringContainsString(s(get_string('enroll_code_used_advice', 'tool_secbackup')), $html);
        $this->assertSame($before, self::stored());
        $this->assertTrue(state::load()->is_enrolled());
    }

    public function test_other_failures_do_not_say_the_code_is_used_up(): void {
        self::submit_registration(['code' => 'SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assertStringNotContainsString(
            s(get_string('enroll_code_used_advice', 'tool_secbackup')),
            $page->render()
        );
    }

    public function test_a_registered_site_without_its_signing_key_can_still_be_removed(): void {
        // A removal that stopped half-way leaves a registered site without its key.
        self::register(self::service());
        signing_keys::forget();
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assertStringContainsString('name="confirmname"', $page->render());
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => fake_service::SITE_NAME]);
        $this->assertNotNull(self::page(self::service())->handle());
        $this->assertFalse(state::load()->is_enrolled());
    }

    /**
     * Differences between the clocks, and the warning that each one earns after a registration.
     *
     * @return array
     */
    public static function clock_difference_provider(): array {
        return [
            'just under two minutes' => [119, 0],
            'two minutes' => [120, 2],
            'two minutes, this server ahead' => [-120, 2],
            'two and a half minutes is rounded up' => [150, 3],
            'just under two minutes, this server ahead' => [-119, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clock_difference_provider')]
    /**
     * A registration warns about the clocks from two minutes, in either direction.
     *
     * @dataProvider clock_difference_provider
     * @param int $difference How far this server's clock is behind the service's, in seconds.
     * @param int $minutes The minutes the warning names, or 0 for no warning.
     */
    public function test_a_registration_warns_about_the_clocks_from_two_minutes(int $difference, int $minutes): void {
        self::submit_registration();
        $this->assertNotNull(self::page(self::service(), self::NOW - $difference)->handle());
        $warnings = array_map(static fn($n): string => $n->get_message(), \core\notification::fetch());
        $expected = $minutes === 0 ? [] : [get_string('clock_skew_warning', 'tool_secbackup', $minutes)];
        $this->assertSame($expected, $warnings);
    }

    /**
     * The users who have only one of the two capabilities.
     *
     * @return array
     */
    public static function one_capability_provider(): array {
        return [
            'only the right to manage the connection' => ['tool/secbackup:manage'],
            'only the right to configure the site' => ['moodle/site:config'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('one_capability_provider')]
    /**
     * The page needs both capabilities.
     *
     * @dataProvider one_capability_provider
     * @param string $capability The only capability the user has.
     */
    public function test_needs_both_capabilities(string $capability): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Partial manager', 'partialmanager', '');
        assign_capability($capability, CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        new connect_page();
    }

    public function test_the_saved_url_of_a_registered_site_is_escaped_once(): void {
        self::register(self::service());
        set_config('endpoint', 'https://app.example.test/<i>&</i>', 'tool_secbackup');
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('https://app.example.test/&lt;i&gt;&amp;&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('<i>&</i>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * The text of the connection URL that the second step shows to the administrator.
     *
     * @param string $html The page.
     * @return string The HTML of the element, without surrounding white space.
     */
    private function shown_endpoint(string $html): string {
        $this->assertSame(1, preg_match('~data-name="endpointshown">\s*(.*?)\s*</div>~s', $html, $matches));
        return $matches[1];
    }

    public function test_the_second_step_shows_the_typed_url_in_its_normal_form(): void {
        connection_form::mock_submit([
            'stage' => 'connection',
            'endpoint' => '  HTTPS://App.Example.TEST:443/api/lms-backup/v1/  ',
            'publickey' => fake_service::ENCRYPTION_KEY,
        ]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertSame(self::API, $this->shown_endpoint($html));
        $this->assertStringContainsString('name="code"', $html);
    }

    public function test_the_second_step_of_a_registered_site_shows_the_saved_url(): void {
        self::register(self::service());
        // The request names another URL and is turned back by the form, so that the step is shown again.
        self::submit_registration(['endpoint' => 'https://other.example.test/api/lms-backup/v1', 'confirmfingerprint' => 0]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        // The hidden field may echo the submitted value, and the registration ignores it; the administrator reads this.
        $this->assertSame(self::API, $this->shown_endpoint($html));
    }

    public function test_the_second_step_shows_a_saved_url_that_holds_markup_escaped_once(): void {
        self::register(self::service());
        set_config('endpoint', 'https://app.example.test/<i>&</i>', 'tool_secbackup');
        connection_form::mock_submit(['stage' => 'connection', 'publickey' => fake_service::ENCRYPTION_KEY]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertSame('https://app.example.test/&lt;i&gt;&amp;&lt;/i&gt;', $this->shown_endpoint($html));
        $this->assertStringNotContainsString('<i>&</i>', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * Checks the top of a page: the introduction with the trademark mark, the trademark notice, and the developer warning.
     *
     * @param string $html The page.
     * @param bool $developer Whether the developer settings are active.
     */
    private function assert_page_top(string $html, bool $developer): void {
        $this->assertStringContainsString(get_string('intro', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('trademark', 'tool_secbackup'), $html);
        $banner = get_string('developer_banner', 'tool_secbackup');
        if ($developer) {
            $this->assertStringContainsString($banner, $html);
        } else {
            $this->assertStringNotContainsString($banner, $html);
        }
    }

    public function test_the_top_of_the_page_before_a_registration(): void {
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assert_page_top($page->render(), false);
    }

    public function test_the_top_of_the_page_of_a_registered_site(): void {
        self::register(self::service());
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assert_page_top($page->render(), false);
    }

    public function test_the_top_of_the_second_step(): void {
        connection_form::mock_submit(['stage' => 'connection', 'endpoint' => self::API, 'publickey' => fake_service::ENCRYPTION_KEY]);
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $html = $page->render();
        $this->assertStringContainsString('name="code"', $html);
        $this->assert_page_top($html, false);
    }

    public function test_the_page_warns_while_developer_settings_are_active(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['allowhttp' => true];
        $page = self::page(self::service());
        $this->assertNull($page->handle());
        $this->assert_page_top($page->render(), true);
    }

    public function test_the_page_sends_the_registration_code_in_its_normal_form(): void {
        self::submit_registration(['code' => '  sec bk aaaa bbbb-cccc dddd eeee ']);
        $service = self::service();
        $this->assertNotNull(self::page($service)->handle());
        // What the service received, and not what it made of it.
        $this->assertSame(fake_service::CODE, json_decode($service->state['lastbody'], true)['enrollment_code']);
    }

    public function test_the_message_after_a_removal_is_a_success(): void {
        self::register(self::service());
        deregister_form::mock_submit(['stage' => 'deregister', 'confirmname' => fake_service::SITE_NAME]);
        $redirection = self::page(self::service())->handle();
        $this->assertSame(notification::NOTIFY_SUCCESS, $redirection->type);
    }

    public function test_create_without_an_enroller(): void {
        $page = new connect_page();
        $this->assertNull($page->handle());
    }
}
