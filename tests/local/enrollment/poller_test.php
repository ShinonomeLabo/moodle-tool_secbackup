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

use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\poller::class)]
/**
 * Tests for the status request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\poller
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
        (new enroller(network_fakes::client($this->service->handler(), self::NOW)))->enroll(
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
        return new poller(network_fakes::client($this->service->handler(), $now), static fn(): int => $now);
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
        set_config('siteid', '', 'tool_secbackup');
        $this->assertTrue($GLOBALS['DB']->record_exists('config_plugins', ['plugin' => 'tool_secbackup', 'name' => 'siteid']));
        $this->assertSame('notregistered', $this->poller(self::NOW)->run());
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
        // A later success clears the error.
        $this->service->state['mode'] = '';
        $this->assertSame('ok', $this->poller(self::NOW)->run());
        $this->assertSame('', state::load()->last_poll_error());
        $this->assertSame('', state::load()->last_poll_error_a());
    }

    /**
     * Values of an error that are stored as text.
     *
     * @return array[]
     */
    public static function error_values(): array {
        return [
            'nothing' => [null, ''],
            'text' => ['10', '10'],
            'number' => [7, '7'],
            'object' => [new \stdClass(), ''],
            'array' => [['x'], ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('error_values')]
    /**
     * Only a scalar value of an error is stored; anything else would be an error or a warning when cast.
     *
     * @dataProvider error_values
     * @param mixed $a Value of the error.
     * @param string $expected What is stored.
     */
    public function test_error_value_is_stored_only_when_it_is_a_scalar(mixed $a, string $expected): void {
        $method = new \ReflectionMethod(poller::class, 'text');
        $this->assertSame($expected, $method->invoke(null, $a));
    }
}
