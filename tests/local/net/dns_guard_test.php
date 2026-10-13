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

namespace tool_secbackup\local\net;

use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\dns_guard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\dns_report::class)]
/**
 * Tests for the name resolution check.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\dns_guard
 * @covers     \tool_secbackup\local\net\dns_report
 */
final class dns_guard_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * Expects check() to fail with one error code.
     *
     * @param string $code Error code.
     * @param dns_guard $guard The guard.
     * @param string $service AWS service.
     */
    private function expect_problem(string $code, dns_guard $guard, string $service = aws_ranges::SERVICE_CLOUDFRONT): void {
        try {
            $guard->check(network_fakes::HOST, false, $service);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_answers_in_range_pin_the_first_os_address(): void {
        $this->assertSame('13.225.9.9', network_fakes::passing_guard()
            ->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_inspect_reports_each_source(): void {
        $report = network_fakes::guard(['13.225.1.1'], null, ['13.225.9.9'])->inspect(network_fakes::HOST, false);
        $this->assertSame(['cloudflare' => ['13.225.1.1'], 'google' => null], $report->doh);
        $this->assertSame(['13.225.9.9'], $report->os);
        $this->assertSame('13.225.9.9', $report->pinned());
    }

    public function test_with_a_proxy_the_operating_system_is_not_asked(): void {
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['13.225.1.1'])],
            function (string $host): array {
                $this->fail('The operating system must not be asked when a proxy resolves the name.');
            }
        );
        $this->assertNull($guard->check(network_fakes::HOST, true, aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertNull($guard->inspect(network_fakes::HOST, true)->os);
    }

    public function test_one_resolver_answering_is_enough(): void {
        $this->assertSame('13.225.9.9', network_fakes::guard(null, ['13.225.1.1'], ['13.225.9.9'])
            ->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_no_resolver_answering_stops(): void {
        $this->expect_problem('dns_doh_unavailable', network_fakes::guard(null, null, ['13.225.9.9']));
    }

    public function test_unknown_name_stops(): void {
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', [], 3)],
            static fn(string $host): array => []
        );
        $this->expect_problem('dns_not_found', $guard);
    }

    public function test_resolver_answer_outside_aws_stops(): void {
        $this->expect_problem('dns_outside_aws', network_fakes::guard(['13.225.1.1'], ['203.0.113.1'], ['13.225.9.9']));
    }

    public function test_s3_addresses_are_not_cloudfront(): void {
        $guard = network_fakes::guard(['3.5.152.1'], null, ['3.5.152.2']);
        $this->expect_problem('dns_outside_aws', $guard);
        $this->assertSame('3.5.152.2', $guard->check(network_fakes::HOST, false, aws_ranges::SERVICE_S3));
    }

    public function test_operating_system_without_answer_stops(): void {
        $this->expect_problem('dns_os_failed', network_fakes::guard(['13.225.1.1'], null, []));
    }

    public function test_operating_system_answer_outside_aws_stops(): void {
        $this->expect_problem('dns_os_mismatch', network_fakes::guard(['13.225.1.1'], null, ['13.225.9.9', '203.0.113.7']));
    }

    public function test_default_lookup_asks_the_operating_system(): void {
        $guard = new dns_guard(network_fakes::ranges(), [network_fakes::doh('cloudflare', ['13.225.1.1'])]);
        try {
            $guard->check('localhost', false, aws_ranges::SERVICE_CLOUDFRONT);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            // The operating system answers 127.0.0.1 for localhost, which is not an AWS address.
            $this->assertSame('dns_os_mismatch', $e->errorcode);
        }
    }

    public function test_create_uses_both_resolvers(): void {
        $this->assertSame(['cloudflare', 'google'], dns_guard::create()->resolver_names());
    }
}
