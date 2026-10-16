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

use tool_secbackup\event\site_enrolled;
use tool_secbackup\local\api\api_exception;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\local\trust\sec_trust;
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\enroller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\notifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\state::class)]
/**
 * Tests for the registration.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\enroller
 * @covers     \tool_secbackup\local\enrollment\notifier
 * @covers     \tool_secbackup\local\enrollment\state
 */
final class enroller_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The clocks. */
    private const NOW = 1760000000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        network_fakes::no_security_list();
    }

    protected function tearDown(): void {
        // The observers a test replaced are not given back to the next test by every Moodle version.
        \core\event\manager::phpunit_reset();
        parent::tearDown();
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
     * An enroller that talks to the fake service.
     *
     * @param fake_service $service The fake service.
     * @param int $now This server's clock.
     * @return enroller
     */
    private static function enroller(fake_service $service, int $now = self::NOW): enroller {
        return new enroller(network_fakes::client($service->handler(), $now), static fn(): int => $now, network_fakes::trust());
    }

    /**
     * The connection URL.
     *
     * @param string $url URL.
     * @return endpoint
     */
    private static function endpoint(string $url = self::API): endpoint {
        return endpoint::parse($url, developer_settings::from_value(null));
    }

    /**
     * The fake site's encryption public key.
     *
     * @return string
     */
    private static function key(): string {
        return public_key_format::parse(fake_service::ENCRYPTION_KEY);
    }

    public function test_registration_saves_logs_and_tells_the_admins(): void {
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $service = self::service();
        $result = self::enroller($service, self::NOW - 200)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $this->assertSame(200, $result->clockskew);

        $state = state::load();
        $this->assertTrue($state->is_enrolled());
        $this->assertSame(fake_service::SITE_ID, $state->site_id());
        $this->assertSame(fake_service::PLATFORM_NAME, $state->platform_name());
        $this->assertSame(self::API, $state->endpoint_url());
        $this->assertSame(fake_service::ENCRYPTION_KEY, $state->encryption_key());
        $this->assertSame(self::NOW - 200, $state->enrolled_at());
        $this->assertSame(200, $state->clock_skew());
        $this->assertSame(base64_decode($service->state['signingkey']), signing_keys::load()->public_key());

        $logged = array_values(array_filter($events->get_events(), static fn($event): bool => $event instanceof site_enrolled));
        $this->assertCount(1, $logged);
        $this->assertSame(['endpoint' => self::API, 'siteid' => fake_service::SITE_ID, 'again' => false], $logged[0]->other);

        $sent = $messages->get_messages();
        $this->assertCount(count(get_admins()), $sent);
        $this->assertSame('tool_secbackup', $sent[0]->component);
        $this->assertSame('connection', $sent[0]->eventtype);
        $this->assertStringContainsString(fake_service::PLATFORM_NAME, $sent[0]->subject);
        $this->assertStringContainsString(self::API, $sent[0]->fullmessage);
    }

    public function test_without_a_trusted_key_the_code_is_not_sent(): void {
        global $CFG;
        // A version of the plugin without an embedded key, on a site without developer keys: the answer of the service could
        // not be checked, so sending the code would use it up for nothing. The developer keys of this site must not count.
        unset($CFG->tool_secbackup_developer);
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $service = self::service();
        $enroller = new enroller(network_fakes::client($service->handler(), self::NOW), static fn(): int => self::NOW);
        try {
            $enroller->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('enroll_no_trusted_key', $e->errorcode);
        }
        $this->assertSame([], $service->state['requests'], 'Nothing may be sent.');
        $this->assertFalse($service->state['codeused']);
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertNull(signing_keys::load());
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
    }

    public function test_the_keys_that_are_checked_are_given_or_those_of_the_site(): void {
        global $CFG;
        $service = self::service();
        $client = network_fakes::client($service->handler(), self::NOW);
        $clock = static fn(): int => self::NOW;
        try {
            (new enroller($client, $clock, new sec_trust([])))->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('enroll_no_trusted_key', $e->errorcode);
        }
        $this->assertSame([], $service->state['requests']);
        // The keys of the site are the embedded keys and the developer keys of config.php (here: of $CFG).
        $CFG->tool_secbackup_developer = ['trustedkeys' => [envelope_builder::PUBLIC_KEY]];
        (new enroller($client, $clock))->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $this->assertTrue(state::load()->is_enrolled());
    }

    public function test_failed_verification_saves_nothing(): void {
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        try {
            self::enroller(self::service(['mode' => 'bad_signature']))->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_signature', $e->errorcode);
        }
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertNull(signing_keys::load());
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
    }

    /**
     * Signed answers that do not belong to the request: one field differs from what was asked. Each row: the fields of the
     * answer, the field that the refusal names.
     *
     * @return array
     */
    public static function mismatched_answer_provider(): array {
        return [
            'the nonce of another request' => [['request_nonce' => 'b3RoZXItbm9uY2UtMDEyMzQ1'], 'request_nonce'],
            'another connection URL' => [['api_base' => 'https://other.example.test/api/lms-backup/v1'], 'api_base'],
            'another encryption key' => [['encryption_key_fingerprint' => str_repeat('AB', 32)], 'encryption_key_fingerprint'],
            'another signing key' => [['signing_key_fingerprint' => str_repeat('CD', 32)], 'signing_key_fingerprint'],
            'another type of answer' => [['type' => 'status'], 'type'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatched_answer_provider')]
    /**
     * A signed answer that does not belong to the request is refused, and nothing of the registration is kept.
     *
     * @dataProvider mismatched_answer_provider
     * @param array $payload Fields of the answer.
     * @param string $field The field that the refusal names.
     */
    public function test_a_signed_answer_that_does_not_belong_to_the_request_saves_nothing(array $payload, string $field): void {
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        try {
            self::enroller(self::service(['enrollpayload' => $payload]))->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (api_exception $e) {
            $this->assertSame('enroll_response_mismatch', $e->errorcode);
            $this->assertSame($field, $e->debuginfo);
        }
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertNull(signing_keys::load());
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
    }

    public function test_refused_code_saves_nothing(): void {
        $this->expectException(api_exception::class);
        try {
            self::enroller(self::service())->enroll(self::endpoint(), self::key(), 'SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ');
        } finally {
            $this->assertFalse(state::load()->is_enrolled());
            $this->assertNull(signing_keys::load());
        }
    }

    public function test_registering_again_replaces_the_key(): void {
        $service = self::service();
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $first = signing_keys::load()->public_key();

        // The platform issues a new registration code.
        $service->state['codeused'] = false;
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);

        $second = signing_keys::load()->public_key();
        $this->assertNotSame($first, $second);
        $this->assertSame(base64_decode($service->state['signingkey']), $second);
        $logged = array_values(array_filter($events->get_events(), static fn($event): bool => $event instanceof site_enrolled));
        $this->assertTrue($logged[0]->other['again']);
        $this->assertStringContainsString(fake_service::PLATFORM_NAME, $messages->get_messages()[0]->subject);
        $this->assertNotSame(get_string(
            'message_enrolled_subject',
            'tool_secbackup',
            (object) ['platform' => fake_service::PLATFORM_NAME]
        ), $messages->get_messages()[0]->subject);
    }

    public function test_registering_again_cannot_change_the_connection_url(): void {
        $service = self::service();
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $requests = count($service->state['requests']);
        try {
            self::enroller($service)->enroll(
                self::endpoint('https://other.example.test/api/lms-backup/v1'),
                self::key(),
                fake_service::CODE
            );
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertCount($requests, $service->state['requests'], 'Nothing may be sent.');
        }
    }

    public function test_names_from_the_service_are_kept_in_the_settings_and_the_stored_message(): void {
        $messages = $this->redirectMessages();
        $service = self::service(['enrollpayload' => ['site_name' => '<b>A&B</b>', 'platform_name' => '<i>P&Q</i>']]);
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);

        $this->assertSame('<b>A&B</b>', state::load()->site_name());
        $sent = $messages->get_messages();
        $this->assertNotEmpty($sent);
        foreach ($sent as $message) {
            $this->assertStringContainsString('<i>P&Q</i>', $message->subject);
            $this->assertStringContainsString('<b>A&B</b>', $message->fullmessage);
            $this->assertStringContainsString(s('<b>A&B</b>'), $message->fullmessagehtml);
            $this->assertStringNotContainsString('<b>', $message->fullmessagehtml);
            $this->assertEquals(FORMAT_PLAIN, $message->fullmessageformat);
        }
    }

    public function test_the_message_links_to_the_status_page_and_names_who_made_the_change(): void {
        $messages = $this->redirectMessages();
        self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $sent = $messages->get_messages();
        $url = (new \moodle_url('/admin/tool/secbackup/index.php'))->out(false);
        $this->assertSame($url, $sent[0]->contexturl);
        $this->assertStringContainsString($url, $sent[0]->fullmessage);
        $this->assertStringContainsString(fullname(get_admin()), $sent[0]->fullmessage);
    }

    public function test_a_change_made_without_a_user_is_attributed_to_the_system(): void {
        $this->setUser(0);
        $messages = $this->redirectMessages();
        self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $this->assertStringContainsString(
            get_string('notifier_system', 'tool_secbackup'),
            $messages->get_messages()[0]->fullmessage
        );
    }

    /**
     * Whether the secret, in any form it could take, is in the fields of the messages or in the data of the events.
     *
     * @param \stdClass[] $messages The messages (every field is searched).
     * @param array[] $eventdata The data of the events, with their descriptions and links.
     * @param string $secret The 64 bytes of the secret key.
     * @return bool
     */
    private static function secret_is_in(array $messages, array $eventdata, string $secret): bool {
        $seed = substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $forms = [$secret, base64_encode($secret), bin2hex($secret), rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
            $seed, bin2hex($seed), base64_encode($seed)];
        $haystack = serialize($messages) . serialize($eventdata);
        foreach ($forms as $form) {
            if (str_contains($haystack, $form)) {
                return true;
            }
        }
        return false;
    }

    public function test_event_and_messages_hold_no_secret(): void {
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $secret = signing_keys::load()->export_secret();
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($secret));

        $logged = array_values(array_filter($events->get_events(), static fn($event): bool => $event instanceof site_enrolled));
        $sent = $messages->get_messages();
        $this->assertNotEmpty($sent);
        $eventdata = [$logged[0]->get_data() + ['description' => $logged[0]->get_description(),
            'link' => $logged[0]->get_url()->out(false)]];

        $this->assertFalse(self::secret_is_in($sent, $eventdata, $secret));
        $texts = [serialize($sent), serialize($eventdata)];
        foreach ($texts as $text) {
            $this->assertStringNotContainsString(fake_service::CODE, $text);
            $this->assertStringNotContainsString(str_replace('-', '', fake_service::CODE), $text);
            $this->assertStringNotContainsString(fake_service::ENCRYPTION_KEY, $text);
        }

        // The control: the same search finds the secret planted in a message field, and in an event datum, in
        // more than one form.
        $seed = substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        foreach ([base64_encode($secret), bin2hex($secret), $secret, base64_encode($seed), bin2hex($seed), $seed] as $form) {
            $planted = $sent;
            $planted[0] = clone $planted[0];
            $planted[0]->fullmessage .= ' ' . $form;
            $this->assertTrue(self::secret_is_in($planted, $eventdata, $secret));

            $planted = $sent;
            $planted[0] = clone $planted[0];
            $planted[0]->subject = $form;
            $this->assertTrue(self::secret_is_in($planted, $eventdata, $secret));

            $plantedevents = $eventdata;
            $plantedevents[0]['other']['endpoint'] = $form;
            $this->assertTrue(self::secret_is_in($sent, $plantedevents, $secret));
        }
    }

    /**
     * Makes another user a site administrator.
     *
     * @return \stdClass The user.
     */
    private function add_admin(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        set_config('siteadmins', get_config('core', 'siteadmins') . ',' . $user->id);
        return $user;
    }

    public function test_every_administrator_is_told_with_the_text_of_the_kind(): void {
        $second = $this->add_admin();
        $service = self::service();
        $messages = $this->redirectMessages();
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $first = $messages->get_messages();
        $messages->clear();
        $service->state['codeused'] = false;
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $again = $messages->get_messages();

        $platform = (object) ['platform' => fake_service::PLATFORM_NAME];
        foreach (
            [[$first, 'message_enrolled_subject', 'registered this site with'],
                [$again, 'message_reenrolled_subject', 'registered this site again with']] as [$sent, $subject, $sentence]
        ) {
            $this->assertEqualsCanonicalizing(
                [(int) get_admin()->id, (int) $second->id],
                array_map(static fn($message): int => (int) $message->useridto, $sent)
            );
            foreach ($sent as $message) {
                $this->assertSame(get_string($subject, 'tool_secbackup', $platform), $message->subject);
                $this->assertStringContainsString($sentence, $message->fullmessage);
            }
        }
    }

    /**
     * Makes the event of the registration throw an Error when it is triggered (an Error is not caught by Moodle).
     */
    private function make_the_event_throw(): void {
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\tool_secbackup\event\site_enrolled',
            'callback' => static function (): void {
                throw new \Error('Provoked by the test');
            },
            'internal' => false,
            'priority' => 0,
            'includefile' => null,
        ]]);
    }

    public function test_a_failure_to_log_does_not_undo_the_registration(): void {
        // Moodle holds events back while a transaction is open, and PHPUnit wraps each test in one.
        $this->preventResetByRollback();
        $this->make_the_event_throw();
        $messages = $this->redirectMessages();

        $result = self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);

        $this->assertDebuggingCalled('The registration could not be logged: Error', DEBUG_NORMAL);
        $this->assertSame(fake_service::SITE_ID, $result->siteid);
        $this->assertTrue(state::load()->is_enrolled());
        $this->assertNotNull(signing_keys::load());
        $this->assertCount(count(get_admins()), $messages->get_messages());
    }

    public function test_a_failure_to_tell_the_administrators_does_not_undo_the_registration(): void {
        $this->add_admin();
        unset_config('popup_provider_tool_secbackup_connection_locked', 'message');

        $result = self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);

        $this->assertDebuggingCalledCount(
            2,
            array_map(
                static fn($id): string => 'The message about the connection could not be sent to the user ' . $id . ': '
                    . \core\exception\coding_exception::class,
                array_keys(get_admins())
            ),
            [DEBUG_NORMAL, DEBUG_NORMAL]
        );
        $this->assertSame(fake_service::SITE_ID, $result->siteid);
        $this->assertTrue(state::load()->is_enrolled());
        $this->assertNotNull(signing_keys::load());
    }

    /**
     * All stored settings of the plugin, read from the table so that no cache hides a difference.
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

    public function test_a_failed_save_leaves_a_first_registration_as_it_was(): void {
        $this->preventResetByRollback();
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $before = self::stored();
        $this->make_saving_the_key_fail();
        try {
            self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_savefailed', $e->errorcode);
        }
        $this->assertSame($before, self::stored());
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertSame('', state::load()->site_id());
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
    }

    public function test_a_failed_save_does_not_leave_the_rolled_back_values_in_the_cache(): void {
        global $CFG;
        $this->preventResetByRollback();
        $before = self::stored();
        // While the key is being encrypted, the settings are read, so that the cache holds the values saved so far.
        $CFG->secretdataroot = new class implements \Stringable {
            /**
             * Reads the settings of the plugin, then fails.
             *
             * @return string
             */
            public function __toString(): string {
                get_config('tool_secbackup');
                throw new \RuntimeException('Provoked by the test');
            }
        };
        try {
            self::enroller(self::service())->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_savefailed', $e->errorcode);
        }
        unset($CFG->secretdataroot);
        $this->assertSame($before, self::stored());
        $this->assertSame('', state::load()->site_id());
        $this->assertFalse(get_config('tool_secbackup', 'siteid'));
    }

    public function test_a_failed_save_leaves_a_registration_made_again_as_it_was(): void {
        global $CFG, $DB;
        $this->preventResetByRollback();
        $service = self::service();
        self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
        $before = self::stored();
        $signingkey = signing_keys::load()->public_key();
        $this->assertNotEmpty($before['signingsecret']);

        $service->state['codeused'] = false;
        $service->state['enrollpayload'] = ['site_name' => 'Renamed site'];
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $this->make_saving_the_key_fail();
        try {
            self::enroller($service)->enroll(self::endpoint(), self::key(), fake_service::CODE);
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_savefailed', $e->errorcode);
        }
        $this->assertSame($before, self::stored());
        $this->assertSame(fake_service::SITE_NAME, state::load()->site_name());
        $this->assertSame(
            $DB->get_field('config_plugins', 'value', ['plugin' => 'tool_secbackup', 'name' => 'sitename']),
            state::load()->site_name()
        );
        $this->assertSame([], $events->get_events());
        $this->assertSame([], $messages->get_messages());
        // The key that was saved before is still the one that is read, once Moodle finds its encryption key again.
        unset($CFG->secretdataroot, $CFG->nokeygeneration);
        $this->assertSame($signingkey, signing_keys::load()->public_key());
    }

    /**
     * Failures of a registration.
     *
     * @return array
     */
    public static function failing_registration_provider(): array {
        return [
            'the service refuses the code' => ['refused'],
            'the key cannot be saved' => ['save'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_registration_provider')]
    /**
     * Neither the registration code nor the key pair is left in the arguments of the trace of the exception.
     *
     * @dataProvider failing_registration_provider
     * @param string $failure What fails.
     */
    public function test_a_failed_registration_leaves_no_secret_in_the_trace(string $failure): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the secrets stay in the'
                . ' arguments of the trace there.');
        }
        $this->preventResetByRollback();
        $code = $failure === 'refused' ? 'SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ' : fake_service::CODE;
        if ($failure === 'save') {
            $this->make_saving_the_key_fail();
        }
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            // Control: a trace made like this one does show a text and a key pair that are passed along.
            $control = self::exception_created_with($code, signing_keypair::generate());
            $this->assertTrue(self::any_argument($control, static fn($argument): bool => $argument === $code));
            $this->assertTrue(self::any_argument($control, static fn($argument): bool => $argument instanceof signing_keypair));

            $thrown = null;
            try {
                self::enroller(self::service())->enroll(self::endpoint(), self::key(), $code);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertInstanceOf($failure === 'refused' ? api_exception::class : key_exception::class, $thrown);
        $needles = [$code, str_replace('-', '', $code), substr($code, 7)];
        $this->assertFalse(self::any_argument($thrown, static function ($argument) use ($needles): bool {
            foreach ($needles as $needle) {
                if (is_string($argument) && str_contains($argument, $needle)) {
                    return true;
                }
            }
            return false;
        }), 'The registration code is in the trace.');
        $this->assertFalse(
            self::any_argument($thrown, static fn($argument): bool => $argument instanceof signing_keypair),
            'The key pair is in the trace.'
        );
        $this->assertStringNotContainsString($code, $thrown->getMessage() . $thrown->getTraceAsString());
    }

    /**
     * An exception created in a call with the arguments, so that its trace holds them when PHP keeps arguments.
     *
     * @param mixed ...$arguments Anything.
     * @return \Exception
     */
    private static function exception_created_with(mixed ...$arguments): \Exception {
        return new \Exception('Control exception for ' . count($arguments) . ' arguments');
    }

    /**
     * Tells whether the test is true for an argument in the trace of an exception, or for an element of an array
     * argument (followed five levels deep).
     *
     * @param \Throwable $exception The exception.
     * @param callable $test Gets one value and returns a bool.
     * @return bool
     */
    private static function any_argument(\Throwable $exception, callable $test): bool {
        foreach ($exception->getTrace() as $frame) {
            if (self::any_value($frame['args'] ?? [], $test, 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tells whether the test is true for a value or for an element of it.
     *
     * @param mixed $value The value.
     * @param callable $test Gets one value and returns a bool.
     * @param int $depth How deep the value is.
     * @return bool
     */
    private static function any_value(mixed $value, callable $test, int $depth): bool {
        if ($test($value)) {
            return true;
        }
        if (is_array($value) && $depth < 5) {
            foreach ($value as $element) {
                if (self::any_value($element, $test, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function test_create_uses_the_site_settings(): void {
        $this->assertInstanceOf(enroller::class, enroller::create());
    }
}
