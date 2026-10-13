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

use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\api\status_result;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\state::class)]
/**
 * Tests for the registration state.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\state
 */
final class state_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Saves a registration.
     *
     * @return signing_keypair The saved key pair.
     */
    private static function save(): signing_keypair {
        $pair = signing_keypair::generate();
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            self::API,
            1760000000,
            -40
        );
        state::save_enrollment($result, 'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', $pair, 1760000100);
        return $pair;
    }

    public function test_nothing_saved_yet(): void {
        $state = state::load();
        $this->assertFalse($state->is_enrolled());
        $this->assertSame('', $state->site_id());
        $this->assertSame('', $state->encryption_fingerprint());
        $this->assertNull($state->enrolled_at());
        $this->assertNull($state->clock_skew());
        $this->assertNull($state->last_poll());
        $this->assertSame('', $state->last_poll_status());
    }

    public function test_saved_registration(): void {
        $pair = self::save();
        $state = state::load();
        $this->assertTrue($state->is_enrolled());
        $this->assertSame('11111111-2222-4333-8444-555555555555', $state->site_id());
        $this->assertSame('Example site', $state->site_name());
        $this->assertSame('Example Platform', $state->platform_name());
        $this->assertSame(self::API, $state->endpoint_url());
        $this->assertSame(self::API, $state->endpoint()->url());
        $this->assertSame('secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', $state->encryption_key());
        $this->assertSame('A260B4050FD28D2B313C9A94', $state->encryption_fingerprint());
        $this->assertSame(1760000100, $state->enrolled_at());
        $this->assertSame(-40, $state->clock_skew());
        $this->assertSame($pair->public_key(), signing_keys::load()->public_key());
    }

    public function test_saving_again_forgets_the_previous_poll(): void {
        self::save();
        state::record_poll(1760000200, null, 'api_site_not_enrolled');
        self::save();
        $this->assertSame('', state::load()->last_poll_error());
        $this->assertNull(state::load()->last_poll());
    }

    public function test_poll_results(): void {
        self::save();
        state::record_poll(1760000400, new status_result('active', 1760000405, 5, 0));
        $state = state::load();
        $this->assertSame(1760000400, $state->last_poll());
        $this->assertSame('active', $state->last_poll_status());
        $this->assertSame(5, $state->clock_skew());
        $this->assertSame('', $state->last_poll_error());

        state::record_poll(1760000700, null, 'api_timestamp_out_of_range_minutes', '9', 540);
        $state = state::load();
        $this->assertSame(1760000700, $state->last_poll());
        $this->assertSame('active', $state->last_poll_status(), 'The last known status stays.');
        $this->assertSame('api_timestamp_out_of_range_minutes', $state->last_poll_error());
        $this->assertSame('9', $state->last_poll_error_a());
        $this->assertSame(540, $state->clock_skew());
    }

    public function test_clear(): void {
        self::save();
        state::clear();
        $this->assertFalse(state::load()->is_enrolled());
        $this->assertSame('', state::load()->endpoint_url());
        $this->assertNull(signing_keys::load());
    }

    public function test_numbers_that_are_not_numbers_read_as_unknown(): void {
        set_config('clockskew', 'abc', 'tool_secbackup');
        $this->assertNull(state::load()->clock_skew());
    }

    public function test_a_number_with_a_trailing_line_feed_reads_as_unknown(): void {
        set_config('clockskew', "5\n", 'tool_secbackup');
        set_config('enrolledat', "1760000000\n", 'tool_secbackup');
        set_config('lastpoll', "1760000000\n", 'tool_secbackup');
        $state = state::load();
        $this->assertNull($state->clock_skew());
        $this->assertNull($state->enrolled_at());
        $this->assertNull($state->last_poll());
    }

    public function test_numbers_are_read_whole(): void {
        set_config('clockskew', '-12', 'tool_secbackup');
        $this->assertSame(-12, state::load()->clock_skew());
    }

    public function test_the_key_pair_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(state::class, 'save_enrollment'))->getParameters()[2];
        $this->assertSame('pair', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }
}
