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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\transport::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\response::class)]
/**
 * Tests for the transport to the service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\transport
 * @covers     \tool_secbackup\local\net\response
 */
final class transport_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * The test connection URL.
     *
     * @return endpoint
     */
    private static function endpoint(): endpoint {
        return endpoint::parse('https://' . network_fakes::HOST . '/api/lms-backup/v1', developer_settings::from_value(null));
    }

    /**
     * Sends one request through a mocked network with production settings.
     *
     * @param MockHandler $mock Mocked network.
     * @param dns_guard|null $guard Name check; null for one that passes.
     * @return \tool_secbackup\local\net\response The plugin's response. Its name is spelled out because the import of the
     *         Guzzle class Response above has the same name in lower case, and PHP class names are not case sensitive.
     */
    private static function send(MockHandler $mock, ?dns_guard $guard = null): \tool_secbackup\local\net\response {
        $transport = new transport(developer_settings::from_value(null), $guard ?? network_fakes::passing_guard(), $mock);
        return $transport->send('POST', self::endpoint(), 'enroll', ['Accept' => 'application/json'], '{"a":1}');
    }

    /**
     * Expects a send to fail with one error code.
     *
     * @param string $code Error code.
     * @param MockHandler $mock Mocked network.
     * @param dns_guard|null $guard Name check.
     * @return transport_exception
     */
    private function expect_failure(string $code, MockHandler $mock, ?dns_guard $guard = null): transport_exception {
        try {
            self::send($mock, $guard);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame($code, $e->errorcode);
            return $e;
        }
    }

    public function test_pins_the_checked_address_and_the_amazon_roots(): void {
        $mock = new MockHandler([new Response(201, [], '{"ok":true}')]);
        $response = self::send($mock);
        $this->assertSame(201, $response->status);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertNull($response->retryafter);

        $request = $mock->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://app.example.test/api/lms-backup/v1/enroll', (string) $request->getUri());
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $options = $mock->getLastOptions();
        $this->assertSame(transport::roots_file(), $options['verify']);
        $this->assertSame(dirname(transport::roots_file()), $options['curl'][CURLOPT_CAPATH]);
        $this->assertSame(['app.example.test:443:13.225.9.9'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(30, $options['timeout']);
    }

    public function test_the_request_names_the_plugin_and_nothing_of_the_site(): void {
        global $CFG;
        // The default user agent of Moodle's HTTP client is "MoodleBot/<version> (+<wwwroot>)": the service must not get it.
        $this->assertNotSame('', $CFG->wwwroot);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::send($mock);
        $request = $mock->getLastRequest();
        $this->assertSame(['tool_secbackup'], $request->getHeader('User-Agent'));
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($CFG->wwwroot, $value, $name);
            }
        }
        $this->assertStringNotContainsString($CFG->wwwroot, (string) $request->getUri());
    }

    public function test_a_user_agent_in_the_headers_is_replaced(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $headers = ['user-agent' => 'Browser/1.0 (+https://lms.example.ac.jp)', 'Accept' => 'application/json'];
        $transport = new transport(developer_settings::from_value(null), network_fakes::passing_guard(), $mock);
        $transport->send('GET', self::endpoint(), 'status', $headers, '');
        $request = $mock->getLastRequest();
        $this->assertSame(['tool_secbackup'], $request->getHeader('User-Agent'));
        $this->assertSame(['application/json'], $request->getHeader('Accept'));
    }

    public function test_with_a_proxy_nothing_is_pinned(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['13.225.1.1'])],
            function (string $host): array {
                $this->fail('A proxy resolves the name.');
            }
        );
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::send($mock, $guard);
        $options = $mock->getLastOptions();
        $this->assertSame('http://proxy.example.ac.jp:3128', $options['proxy']);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertSame(transport::roots_file(), $options['verify']);
    }

    /**
     * Sets a SOCKS5 proxy in the site configuration.
     */
    private static function use_socks5_proxy(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
    }

    public function test_with_a_socks5_proxy_the_checked_address_is_pinned(): void {
        // This server looks the name up before it talks to a SOCKS5 proxy, so the answer of this server is checked and pinned.
        self::use_socks5_proxy();
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::send($mock);
        $options = $mock->getLastOptions();
        $this->assertSame('socks5://proxy.example.ac.jp:1080', $options['proxy']);
        $this->assertSame(['app.example.test:443:13.225.9.9'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(transport::roots_file(), $options['verify']);
    }

    public function test_with_a_socks5_proxy_an_answer_of_this_server_outside_aws_sends_nothing(): void {
        self::use_socks5_proxy();
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $this->expect_failure('dns_os_mismatch', $mock, network_fakes::guard(['13.225.1.1'], null, ['203.0.113.7']));
        $this->assertCount(1, $mock);
    }

    public function test_name_check_failure_sends_nothing(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $this->expect_failure('dns_os_mismatch', $mock, network_fakes::guard(['13.225.1.1'], null, ['203.0.113.7']));
        $this->assertCount(1, $mock);
    }

    public function test_skip_network_checks_uses_neither_the_guard_nor_the_pinning(): void {
        $settings = developer_settings::from_value(['skipnetworkchecks' => true]);
        $failingguard = network_fakes::guard(null, null, []);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        (new transport($settings, $failingguard, $mock))->send('GET', self::endpoint(), 'status', [], '');
        $options = $mock->getLastOptions();
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertArrayNotHasKey(CURLOPT_CAPATH, $options['curl']);
        $this->assertNotSame(transport::roots_file(), $options['verify'] ?? true);
    }

    public function test_moodle_security_list_applies(): void {
        set_config('curlsecurityblockedhosts', network_fakes::HOST);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $this->expect_failure('transport_blocked', $mock);
        $this->assertDebuggingCalled();
        $this->assertCount(1, $mock);
    }

    public function test_private_hosts_setting_skips_the_security_list(): void {
        set_config('curlsecurityblockedhosts', 'fakesec');
        $settings = developer_settings::from_value(['allowhttp' => true, 'allowprivatehosts' => true, 'skipnetworkchecks' => true]);
        $endpoint = endpoint::parse('http://fakesec/api/lms-backup/v1', $settings);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $response = (new transport($settings, network_fakes::passing_guard(), $mock))->send('GET', $endpoint, 'status', [], '');
        $this->assertSame(200, $response->status);
        $this->assertSame('http://fakesec/api/lms-backup/v1/status', (string) $mock->getLastRequest()->getUri());
    }

    public function test_redirects_are_refused(): void {
        $this->expect_failure('transport_redirect', new MockHandler([
            new Response(302, ['Location' => 'https://elsewhere.example/'], ''),
        ]));
    }

    public function test_announced_large_response_is_refused(): void {
        $this->expect_failure('transport_too_large', new MockHandler([
            new Response(200, ['Content-Length' => (string) (transport::MAX_RESPONSE + 1)], 'x'),
        ]));
    }

    public function test_unannounced_large_response_is_refused(): void {
        $this->expect_failure('transport_too_large', new MockHandler([
            new Response(200, [], str_repeat('x', transport::MAX_RESPONSE + 1)),
        ]));
    }

    public function test_certificate_failure_is_reported_without_the_url(): void {
        $e = $this->expect_failure('transport_tls', new MockHandler([
            new RequestException(
                'cURL error 60: SSL certificate problem (see https://app.example.test/x?secret=1)',
                new Request('POST', 'https://app.example.test/'),
                null,
                null,
                ['errno' => 60]
            ),
        ]));
        $this->assertSame('cURL error 60', $e->debuginfo);
    }

    public function test_connection_failure(): void {
        $this->expect_failure('transport_connect', new MockHandler([
            new ConnectException('cURL error 7', new Request('POST', 'https://app.example.test/'), null, ['errno' => 7]),
        ]));
    }

    public function test_timeout_is_a_connection_failure(): void {
        $this->expect_failure('transport_connect', new MockHandler([
            new RequestException(
                'cURL error 28',
                new Request('POST', 'https://app.example.test/'),
                null,
                null,
                ['errno' => 28]
            ),
        ]));
    }

    public function test_other_failures(): void {
        $e = $this->expect_failure('transport_failed', new MockHandler([new TransferException('Something else')]));
        $this->assertSame(TransferException::class, $e->debuginfo);
    }

    /**
     * Retry-After values.
     *
     * @return array
     */
    public static function retry_after_provider(): array {
        return [
            'seconds' => ['120', 120],
            'capped at a day' => ['90000', 86400],
            'http date is ignored' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
            'too many digits' => ['1234567', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retry_after_provider')]
    /**
     * The Retry-After header is read as seconds, at most one day.
     *
     * @dataProvider retry_after_provider
     * @param string $value Header value.
     * @param int|null $expected Seconds.
     */
    public function test_retry_after(string $value, ?int $expected): void {
        $response = self::send(new MockHandler([new Response(503, ['Retry-After' => $value], '{}')]));
        $this->assertSame(503, $response->status);
        $this->assertSame($expected, $response->retryafter);
    }

    public function test_bundled_roots_are_the_five_amazon_roots(): void {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
            file_get_contents(transport::roots_file()),
            $matches
        );
        $fingerprints = array_map(
            static fn(string $body): string => strtoupper(hash('sha256', base64_decode(preg_replace('/\s+/', '', $body)))),
            $matches[1]
        );
        sort($fingerprints);
        $this->assertSame([
            '18CE6CFE7BF14E60B2E347B8DFE868CB31D02EBB3ADA271569F50343B46DB3A4',
            '1BA5B2AA8C65401A82960118F80BEC4F62304D83CEC4713A19C39C011EA46DB4',
            '568D6905A2C88708A4B3025190EDCFEDB1974A606A13C6E5290FCB2AE63EDAB5',
            '8ECDE6884F3D87B1125BA31AC3FCB13D7016DE7F57CC904FE1CB97C6AE98196E',
            'E35D28419ED02025CFA69038CD623962458DA5C695FBDEA3C22B0BFB25897092',
        ], $fingerprints);
    }

    public function test_create_reads_the_site_settings(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['skipnetworkchecks' => true];
        $this->assertInstanceOf(transport::class, transport::create());
    }
}
