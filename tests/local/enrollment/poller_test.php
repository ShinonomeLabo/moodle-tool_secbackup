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

use tool_secbackup\local\api\api_exception;
use tool_secbackup\local\api\client;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\net\aws_ranges;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\sec_trust;
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\instruction_factory;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\poller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\state::class)]
/**
 * Tests for the status request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\poller
 * @covers     \tool_secbackup\local\enrollment\state
 */
final class poller_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The service's clock. */
    private const NOW = 1760000000;

    /** @var fake_service The fake service. */
    private fake_service $service;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectMessages();
        network_fakes::no_security_list();
        $this->service = new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW]));
    }

    /**
     * Registers the site with the fake service.
     */
    private function register(): void {
        (new enroller(network_fakes::client($this->service->handler(), self::NOW), null, network_fakes::trust()))->enroll(
            endpoint::parse(self::API, developer_settings::from_value(null)),
            public_key_format::parse(fake_service::ENCRYPTION_KEY),
            fake_service::CODE
        );
    }

    /**
     * A poller whose clock is $now.
     *
     * @param int $now This server's clock.
     * @return poller
     */
    private function poller(int $now): poller {
        return new poller(network_fakes::client($this->service->handler(), $now), static fn(): int => $now, network_fakes::trust());
    }

    public function test_unregistered_site_asks_nothing(): void {
        $this->assertSame('notregistered', $this->poller(self::NOW)->run());
        $this->assertSame([], $this->service->state['requests']);
        $this->assertNull(state::load()->last_poll());
    }

    public function test_status_is_recorded(): void {
        $this->register();
        $this->assertSame('ok', $this->poller(self::NOW - 90)->run());
        $state = state::load();
        $this->assertSame(self::NOW - 90, $state->last_poll());
        $this->assertSame('active', $state->last_poll_status());
        $this->assertSame(90, $state->clock_skew());
        $this->assertSame('', $state->last_poll_error());
    }

    public function test_removed_site_is_recorded(): void {
        $this->register();
        $this->poller(self::NOW)->run();
        $this->service->state['mode'] = 'site_removed';
        $this->assertSame('failed', $this->poller(self::NOW + 300)->run());
        $state = state::load();
        $this->assertSame('api_site_not_enrolled', $state->last_poll_error());
        $this->assertSame('active', $state->last_poll_status());
        $this->assertSame(self::NOW + 300, $state->last_poll());
    }

    public function test_clock_difference_is_learnt_from_the_refusal(): void {
        $this->register();
        $this->assertSame('failed', $this->poller(self::NOW + 600)->run());
        $state = state::load();
        $this->assertSame('api_timestamp_out_of_range_minutes', $state->last_poll_error());
        $this->assertSame('10', $state->last_poll_error_a());
        $this->assertSame(-600, $state->clock_skew());
    }

    public function test_missing_signing_key(): void {
        $this->register();
        unset_config('signingsecret', 'tool_secbackup');
        $this->assertSame('failed', $this->poller(self::NOW)->run());
        $this->assertSame('signingkey_missing', state::load()->last_poll_error());
    }

    public function test_network_failure(): void {
        $this->register();
        $this->service->state['mode'] = 'unavailable';
        $this->assertSame('failed', $this->poller(self::NOW)->run());
        $this->assertSame('api_unavailable', state::load()->last_poll_error());
    }

    public function test_default_client(): void {
        $this->assertSame('notregistered', (new poller())->run());
    }

    public function test_registration_hidden_by_stale_cached_settings_is_found(): void {
        $this->register();
        // Another request cached the settings between the start and the commit of the registration.
        \cache::make('core', 'config')->set('tool_secbackup', ['pluginname' => 'x']);
        $this->assertFalse(state::load()->is_enrolled());
        $before = count($this->service->state['requests']);

        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame($before + 1, count($this->service->state['requests']));
        $state = state::load();
        $this->assertTrue($state->is_enrolled());
        $this->assertSame(self::NOW, $state->last_poll());
        $this->assertSame('active', $state->last_poll_status());
    }

    public function test_unregistered_site_stores_nothing(): void {
        $this->assertSame('notregistered', $this->poller(self::NOW)->run());
        foreach (['siteid', 'lastpoll', 'lastpollstatus', 'lastpollerror', 'lastpollerrora', 'clockskew'] as $name) {
            $this->assertFalse(get_config('tool_secbackup', $name), $name);
        }
        $this->assertSame([], $this->service->state['requests']);
    }

    public function test_empty_site_id_is_not_a_registration(): void {
        global $DB;
        set_config('siteid', '', 'tool_secbackup');
        set_config('sitename', 'Example site', 'tool_secbackup');
        $this->assertTrue($DB->record_exists('config_plugins', ['plugin' => 'tool_secbackup', 'name' => 'siteid']));
        // The cache holds what the table holds and one more value. Reading the table again would not bring it back.
        $cache = \cache::make('core', 'config');
        $cache->set('tool_secbackup', ['siteid' => '', 'sentinel' => 'cached']);
        $this->assertSame('notregistered', $this->poller(self::NOW)->run());
        $this->assertSame('cached', $cache->get('tool_secbackup')['sentinel']);
        $this->assertSame([], $this->service->state['requests']);
        $this->assertFalse(get_config('tool_secbackup', 'lastpoll'));
    }

    public function test_failure_keeps_the_earlier_status_of_the_service(): void {
        $this->register();
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->service->state['mode'] = 'unavailable';
        $this->assertSame('failed', $this->poller(self::NOW + 300)->run());
        $state = state::load();
        $this->assertSame('active', $state->last_poll_status());
        $this->assertSame('api_unavailable', $state->last_poll_error());
        $this->assertSame(self::NOW + 300, $state->last_poll());
        // The service gave no time with the refusal, so the clock difference stays what the success found.
        $this->assertSame(0, $state->clock_skew());
        // A later success clears the error.
        $this->service->state['mode'] = '';
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame('', state::load()->last_poll_error());
        $this->assertSame('', state::load()->last_poll_error_a());
    }

    /**
     * Errors whose value is not what the producers give today.
     *
     * @return array[]
     */
    public static function odd_errors(): array {
        return [
            'api error with an object' => [new api_exception('api_unexpected', '', 500, null, null, new \stdClass()), ''],
            'plugin error with an object' => [new plugin_exception('signingkey_corrupt', new \stdClass()), ''],
            'plugin error with an array' => [new plugin_exception('signingkey_corrupt', ['x']), ''],
            'plugin error with text' => [new plugin_exception('signingkey_corrupt', 'x'), 'x'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('odd_errors')]
    /**
     * Only a scalar value of an error is stored; anything else would be an error or a warning when cast.
     *
     * The exception is thrown by the name check of the transport, which runs outside the transport's own try.
     *
     * @dataProvider odd_errors
     * @param \Throwable $error What the request throws.
     * @param string $expected What is stored as the value of the error.
     */
    public function test_error_value_is_stored_only_when_it_is_a_scalar(\Throwable $error, string $expected): void {
        $this->register();
        $settings = developer_settings::from_value(null);
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['13.225.1.1'])],
            static function (string $host) use ($error): array {
                throw $error;
            }
        );
        $client = new client(
            new transport($settings, $guard, $this->service->handler()),
            new sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]),
            $settings,
            static fn(): int => self::NOW
        );
        $this->assertSame('failed', (new poller($client, static fn(): int => self::NOW))->run());
        $state = state::load();
        $this->assertSame($error->errorcode, $state->last_poll_error());
        $this->assertSame($expected, $state->last_poll_error_a());
    }

    public function test_unreadable_signing_key_is_recorded(): void {
        $this->register();
        set_config('signingsecret', 'not a stored key', 'tool_secbackup');
        $this->assertSame('failed', $this->poller(self::NOW)->run());
        $state = state::load();
        $this->assertSame('signingkey_corrupt', $state->last_poll_error());
        $this->assertSame('', $state->last_poll_error_a());
        $this->assertSame(self::NOW, $state->last_poll());
        $this->assertSame([], array_filter($this->service->state['requests'], static fn($r) => str_contains($r, 'status')));
    }

    public function test_unacceptable_stored_connection_url_is_recorded(): void {
        $this->register();
        set_config('endpoint', 'ftp://app.example.test/api', 'tool_secbackup');
        $this->assertSame('failed', $this->poller(self::NOW)->run());
        $this->assertSame('endpoint_scheme', state::load()->last_poll_error());
        $this->assertSame(self::NOW, state::load()->last_poll());
    }

    public function test_default_clock_is_the_time_of_this_server(): void {
        $this->register();
        // Without a signing key nothing is sent, so the default client is never asked.
        unset_config('signingsecret', 'tool_secbackup');
        $before = time();
        $this->assertSame('failed', (new poller())->run());
        $this->assertGreaterThanOrEqual($before, state::load()->last_poll());
        $this->assertLessThanOrEqual(time(), state::load()->last_poll());
    }

    /**
     * A list of AWS ranges that differs from the bundled one.
     *
     * @param string $token Sync token.
     * @return string
     */
    private static function ranges_content(string $token = '42'): string {
        $data = json_decode(file_get_contents(__DIR__ . '/../../../data/aws-ranges.json'), true);
        $data['syncToken'] = $token;
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Counts the requests that the fake service got for a path.
     *
     * @param string $name Last part of the path.
     * @return int
     */
    private function requests_for(string $name): int {
        return count(array_filter($this->service->state['requests'], static fn($r) => str_ends_with($r, '/' . $name)));
    }

    public function test_instructions_are_applied_once(): void {
        $this->register();
        $this->service->set_instruction('settings', 2, instruction_factory::settings_body([
            'window_start' => '03:00', 'bandwidth_mbps' => 20,
        ]));
        $this->service->publish_aws_ranges(5, self::ranges_content());
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame(3 * 60, settings::current()->window_start());
        $this->assertSame(20, settings::current()->bandwidth_mbps());
        $this->assertSame(2, settings::current()->seq());
        $this->assertSame('42', aws_ranges::current()->sync_token());
        $this->assertSame(5, aws_ranges::stored_seq());
        $this->assertSame('', state::load()->last_poll_error());
        $this->assertSame(1, $this->requests_for('aws-ranges'));

        // The same sequence numbers again change nothing and the list is not fetched again.
        $this->service->set_instruction('settings', 2, instruction_factory::settings_body(['window_start' => '04:00']));
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame(3 * 60, settings::current()->window_start());
        $this->assertSame(1, $this->requests_for('aws-ranges'));
    }

    public function test_a_broken_instruction_is_skipped_and_the_others_are_applied(): void {
        $this->register();
        $this->service->set_instruction('settings', 2, instruction_factory::settings_body(['runs_per_day' => 0]));
        $this->service->publish_aws_ranges(5, self::ranges_content());
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertFalse(settings::current()->is_received());
        $this->assertSame('42', aws_ranges::current()->sync_token());
        $state = state::load();
        $this->assertSame('instruction_invalid', $state->last_poll_error());
        $this->assertSame(self::NOW, $state->last_poll());
        $this->assertSame('active', $state->last_poll_status());
    }

    public function test_instructions_that_cannot_be_verified_are_skipped(): void {
        $this->register();
        $builder = new envelope_builder();
        $good = $builder->parts([
            'type' => 'instruction', 'kind' => 'settings', 'site_id' => fake_service::SITE_ID, 'seq' => 3,
            'issued_at' => '2026-10-18T09:00:00Z', 'body' => instruction_factory::settings_body(),
        ]);
        $other = $builder->parts([
            'type' => 'instruction', 'kind' => 'settings', 'site_id' => '99999999-2222-4333-8444-555555555555', 'seq' => 4,
            'issued_at' => '2026-10-18T09:00:00Z', 'body' => instruction_factory::settings_body(),
        ]);
        $forged = array_merge($good, ['sig' => envelope_builder::b64url(str_repeat("\0", 64))]);
        foreach (['another site' => $other, 'forged' => $forged, 'not an envelope' => 'junk'] as $name => $envelope) {
            $this->service->state['instructions'] = ['settings' => $envelope];
            $this->assertSame('ok', $this->poller(self::NOW)->run(), $name);
            $this->assertFalse(settings::current()->is_received(), $name);
            $this->assertSame('instruction_invalid', state::load()->last_poll_error(), $name);
        }
        // The next poll with nothing wrong clears the error.
        $this->service->state['instructions'] = ['settings' => $good];
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame('', state::load()->last_poll_error());
        $this->assertTrue(settings::current()->is_received());
    }

    public function test_a_list_that_changed_after_the_instruction_is_not_stored_and_is_fetched_again(): void {
        $this->register();
        $this->service->publish_aws_ranges(5, self::ranges_content('42'));
        $this->service->state['awsranges'] = self::ranges_content('43');
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame(0, aws_ranges::stored_seq());
        $this->assertSame(aws_ranges::bundled()->sync_token(), aws_ranges::current()->sync_token());
        $this->assertSame('', state::load()->last_poll_error());

        $this->service->publish_aws_ranges(6, self::ranges_content('43'));
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame(6, aws_ranges::stored_seq());
        $this->assertSame('43', aws_ranges::current()->sync_token());
        $this->assertSame(2, $this->requests_for('aws-ranges'));
    }

    public function test_a_list_that_cannot_be_fetched_is_recorded_and_tried_again(): void {
        $this->register();
        $this->service->publish_aws_ranges(5, self::ranges_content());
        $content = $this->service->state['awsranges'];
        $this->service->state['awsranges'] = null;
        $this->assertSame('failed', $this->poller(self::NOW)->run());
        $state = state::load();
        $this->assertSame('api_unexpected', $state->last_poll_error());
        $this->assertSame('active', $state->last_poll_status());
        $this->assertSame(0, aws_ranges::stored_seq());

        $this->service->state['awsranges'] = $content;
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame(5, aws_ranges::stored_seq());
        $this->assertSame('', state::load()->last_poll_error());
    }

    /**
     * A poller that notes when the starter is called.
     *
     * @param int $now This server's clock.
     * @param array[] $calls The time given and the time of the last poll in the state, for each call of the starter.
     * @return poller
     */
    private function poller_with_starter(int $now, array &$calls): poller {
        return new poller(
            network_fakes::client($this->service->handler(), $now),
            static fn(): int => $now,
            network_fakes::trust(),
            static function (int $at) use (&$calls): ?string {
                $calls[] = [$at, state::load()->last_poll()];
                return null;
            }
        );
    }

    public function test_the_starter_is_called_after_a_poll_that_succeeded(): void {
        $this->register();
        $calls = [];
        $this->assertSame('ok', $this->poller_with_starter(self::NOW, $calls)->run());
        // The answer was saved before the starter was called: it reads the time of the poll from the state.
        $this->assertSame([[self::NOW, self::NOW]], $calls);
    }

    public function test_the_starter_is_not_called_when_the_poll_failed(): void {
        $this->register();
        $calls = [];
        $this->service->state['mode'] = 'unavailable';
        $this->assertSame('failed', $this->poller_with_starter(self::NOW, $calls)->run());
        $this->assertSame([], $calls);
    }

    public function test_the_starter_is_not_called_for_a_site_that_is_not_registered(): void {
        $calls = [];
        $this->assertSame('notregistered', $this->poller_with_starter(self::NOW, $calls)->run());
        $this->assertSame([], $calls);
    }

    public function test_the_starter_is_not_called_when_an_instruction_could_not_be_applied(): void {
        $this->register();
        $calls = [];
        $this->service->publish_aws_ranges(5, self::ranges_content());
        $this->service->state['awsranges'] = null;
        $this->assertSame('failed', $this->poller_with_starter(self::NOW, $calls)->run());
        $this->assertSame('api_unexpected', state::load()->last_poll_error());
        $this->assertSame([], $calls);
    }

    public function test_an_error_of_the_starter_is_not_hidden(): void {
        $this->register();
        $poller = new poller(
            network_fakes::client($this->service->handler(), self::NOW),
            static fn(): int => self::NOW,
            network_fakes::trust(),
            static function (int $at): string {
                throw new \RuntimeException('starter');
            }
        );
        $this->expectException(\RuntimeException::class);
        $poller->run();
    }
}
