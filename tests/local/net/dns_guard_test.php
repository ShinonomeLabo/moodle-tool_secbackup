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
     * @param bool $viaproxy Whether a proxy resolves the name.
     */
    private function expect_problem(
        string $code,
        dns_guard $guard,
        string $service = aws_ranges::SERVICE_CLOUDFRONT,
        bool $viaproxy = false
    ): void {
        try {
            $guard->check(network_fakes::HOST, $viaproxy, $service);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_answers_in_range_pin_the_first_os_address(): void {
        $this->assertSame('13.225.9.9', network_fakes::passing_guard()
            ->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_the_problem_of_a_report_is_read_without_an_exception(): void {
        // The diagnostics show a report and read its problem: problem() is public and does not throw.
        $passing = network_fakes::passing_guard();
        $this->assertNull($passing->problem($passing->inspect(network_fakes::HOST, false), aws_ranges::SERVICE_CLOUDFRONT));
        $outside = network_fakes::guard(['203.0.113.1'], null, ['13.225.9.9']);
        $report = $outside->inspect(network_fakes::HOST, false);
        $this->assertSame('dns_outside_aws', $outside->problem($report, aws_ranges::SERVICE_CLOUDFRONT));
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
                $this->fail('The operating system must not be asked when an HTTP proxy resolves the name.');
            }
        );
        $this->assertNull($guard->check(network_fakes::HOST, true, aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertNull($guard->inspect(network_fakes::HOST, true)->os);
    }

    public function test_with_a_proxy_the_resolver_answers_are_still_checked(): void {
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['203.0.113.1'])],
            function (string $host): array {
                $this->fail('The operating system must not be asked when an HTTP proxy resolves the name.');
            }
        );
        $this->expect_problem('dns_outside_aws', $guard, aws_ranges::SERVICE_CLOUDFRONT, true);
    }

    public function test_one_resolver_answering_is_enough(): void {
        $this->assertSame('13.225.9.9', network_fakes::guard(null, ['13.225.1.1'], ['13.225.9.9'])
            ->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_no_resolver_answering_stops(): void {
        $this->expect_problem('dns_doh_unavailable', network_fakes::guard(null, null, ['13.225.9.9']));
    }

    public function test_no_resolver_answering_because_no_connection_could_be_made_says_so(): void {
        // Both connections failed at once (cURL error 7): what a denial of the system (SELinux) looks like.
        $guard = network_fakes::guard(null, null, ['13.225.9.9']);
        $this->assertTrue($guard->inspect(network_fakes::HOST, false)->connectfailed);
        try {
            $guard->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('dns_doh_unavailable', $e->errorcode);
            $this->assertSame(transport_exception::CONNECT_FAILED, $e->debuginfo);
            $this->assertTrue(transport_exception::failed_at_connect($e));
        }
    }

    public function test_a_resolver_that_timed_out_or_answered_is_not_a_failed_connection(): void {
        $timeout = static fn(): doh_resolver => new doh_resolver('google', new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28',
                new \GuzzleHttp\Psr7\Request('GET', 'https://8.8.8.8/'),
                null,
                ['errno' => 28]
            ),
        ]));
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', null), $timeout()],
            static fn(string $host): array => ['13.225.9.9']
        );
        $this->assertFalse($guard->inspect(network_fakes::HOST, false)->connectfailed);
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', null), $timeout()],
            static fn(string $host): array => ['13.225.9.9']
        );
        try {
            $guard->check(network_fakes::HOST, false, aws_ranges::SERVICE_CLOUDFRONT);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('dns_doh_unavailable', $e->errorcode);
            $this->assertNull($e->debuginfo);
            $this->assertFalse(transport_exception::failed_at_connect($e));
        }
        $this->assertFalse(network_fakes::guard(null, ['13.225.1.1'], ['13.225.9.9'])
            ->inspect(network_fakes::HOST, false)->connectfailed);
        $this->assertFalse((new dns_report([], null))->connectfailed);
    }

    public function test_only_a_connection_or_a_lookup_that_failed_at_once_is_a_failed_connection(): void {
        $this->assertTrue(transport_exception::failed_at_connect(new transport_exception('transport_connect', 'cURL error 7')));
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('transport_connect', 'cURL error 28')));
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('transport_tls', 'cURL error 7')));
        $this->assertFalse(transport_exception::failed_at_connect(new \tool_secbackup\local\plugin_exception(
            'temp_write_failed',
            null,
            'cURL error 7'
        )));
        $this->assertTrue(transport_exception::failed_at_connect(new \tool_secbackup\local\plugin_exception(
            'transport_connect',
            null,
            'cURL error 7'
        )));
    }

    public function test_unknown_name_stops(): void {
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', [], 3)],
            static fn(string $host): array => []
        );
        $this->expect_problem('dns_not_found', $guard);
    }

    public function test_a_name_that_one_resolver_cannot_find_stops(): void {
        $this->expect_problem('dns_not_found', network_fakes::guard([], ['13.225.1.1'], ['13.225.9.9']));
    }

    public function test_resolver_answer_outside_aws_stops(): void {
        $this->expect_problem('dns_outside_aws', network_fakes::guard(['13.225.1.1'], ['203.0.113.1'], ['13.225.9.9']));
    }

    /**
     * Answers of one resolver with one address outside the AWS ranges, wherever it stands in the list.
     *
     * @return array
     */
    public static function one_address_outside_provider(): array {
        return [
            'last' => [['13.225.1.1', '203.0.113.1']],
            'first' => [['203.0.113.1', '13.225.1.1']],
            'middle' => [['13.225.1.1', '203.0.113.1', '13.225.1.2']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('one_address_outside_provider')]
    /**
     * Every address of a resolver has to be inside the ranges: one address outside them is enough to stop.
     *
     * @dataProvider one_address_outside_provider
     * @param string[] $addresses The answer of the first resolver.
     */
    public function test_one_address_outside_the_ranges_stops(array $addresses): void {
        $this->expect_problem('dns_outside_aws', network_fakes::guard($addresses, null, ['13.225.9.9']));
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

    public function test_create_checks_the_addresses_against_the_stored_list(): void {
        // 198.51.100.0/24 is a documentation range: the bundled list does not have it, the stored one does.
        $report = new dns_report(['cloudflare' => ['198.51.100.7'], 'google' => ['198.51.100.8']], null);
        $this->assertSame('dns_outside_aws', dns_guard::create()->problem($report, aws_ranges::SERVICE_CLOUDFRONT));
        set_config('endpoint', 'https://backup.example.com/api/backup/v1', 'tool_secbackup');
        set_config('awsranges', json_encode([
            'syncToken' => '2',
            'createDate' => '2026-10-20-00-00-00',
            'services' => [
                'CLOUDFRONT' => ['13.225.0.0/16', '192.0.2.0/24', '198.51.100.0/24', '198.18.0.0/15', '100.64.0.0/10'],
                'S3' => ['3.5.152.0/21', '192.0.0.0/24', '192.88.99.0/24', '192.175.48.0/24', '233.252.0.0/24'],
            ],
        ]), 'tool_secbackup');
        set_config('awsrangesseq', 2, 'tool_secbackup');
        set_config('awsrangesendpoint', 'https://backup.example.com/api/backup/v1', 'tool_secbackup');
        $this->assertNull(dns_guard::create()->problem($report, aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_the_error_codes_are_language_strings(): void {
        $codes = ['dns_doh_failed', 'dns_doh_unavailable', 'dns_not_found', 'dns_os_failed', 'dns_os_mismatch',
            'dns_outside_aws'];
        foreach ($codes as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }
}
