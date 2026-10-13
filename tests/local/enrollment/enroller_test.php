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
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\enroller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\notifier::class)]
/**
 * Tests for the registration.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\enroller
 * @covers     \tool_secbackup\local\enrollment\notifier
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
        return new enroller(network_fakes::client($service->handler(), $now), static fn(): int => $now);
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

    public function test_create_uses_the_site_settings(): void {
        $this->assertInstanceOf(enroller::class, enroller::create());
    }
}
