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
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\transport::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\response::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\guarded_options::class)]
/**
 * Tests for the transport to the service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\transport
 * @covers     \tool_secbackup\local\net\response
 * @covers     \tool_secbackup\local\net\guarded_options
 */
final class transport_test extends \advanced_testcase {
    /** @var string A signature as the service gets it: no exception of send() keeps it in its message or in its trace. */
    private const SIGNATURE = 'Zm9yIHRoZSB0cmFjZSB0ZXN0LCBub3QgYSByZWFsIHNpZ25hdHVyZQ';

    /** @var string The body of an enrolment request: it holds a registration code. */
    private const BODY = '{"enrollment_code":"SEC-BK-7QF2-MXA9-PDTW-4HJC-8NR5"}';

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
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function test_the_pinned_address_names_the_port_of_the_connection_url(): void {
        $settings = developer_settings::from_value(['allowprivatehosts' => true]);
        $endpoint = endpoint::parse('https://app.example.test:8443/api/lms-backup/v1', $settings);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        (new transport($settings, network_fakes::passing_guard(), $mock))->send('GET', $endpoint, 'status', [], '');
        $this->assertSame(['app.example.test:8443:13.225.9.9'], $mock->getLastOptions()['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('https://app.example.test:8443/api/lms-backup/v1/status', (string) $mock->getLastRequest()->getUri());
    }

    public function test_the_host_name_of_the_connection_url_is_the_one_that_is_checked(): void {
        $asked = [];
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['13.225.1.1'])],
            static function (string $host) use (&$asked): array {
                $asked[] = $host;
                return ['13.225.9.9'];
            }
        );
        self::send(new MockHandler([new Response(200, [], '{}')]), $guard);
        $this->assertSame([network_fakes::HOST], $asked);
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
        $headers = [
            'user-agent' => 'Browser/1.0 (+https://lms.example.ac.jp)',
            'User-Agent' => 'Other/2.0',
            'USER-AGENT' => 'Third/3.0',
            'Accept' => 'application/json',
        ];
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

    /**
     * Calls that send() refuses before it does anything.
     *
     * @return array
     */
    public static function refused_call_provider(): array {
        return [
            'a lower case method' => ['get', '', 'A method is'],
            'a method that is neither GET nor POST' => ['PUT', '{}', 'A method is'],
            'a method with a line feed and more' => ["GET\r\nX-Injected: 1", '', 'A method is'],
            'an empty method' => ['', '', 'A method is'],
            'a body with GET' => ['GET', '{}', 'A GET request has no body'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_call_provider')]
    /**
     * The method is a line of the text that the caller signed, so only GET and POST in upper case are sent: PSR-7 upper-cases
     * a method itself, but it lets a line break through, and curl sends that as it is. A GET request has no body: the caller
     * signed the hash of a body that would not be the one that is sent.
     *
     * @dataProvider refused_call_provider
     * @param string $method The method.
     * @param string $body The body.
     * @param string $message Start of the message that names the mistake.
     */
    public function test_a_method_or_a_body_that_is_not_allowed_is_a_coding_error(
        string $method,
        string $body,
        string $message
    ): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $transport = new transport(developer_settings::from_value(null), network_fakes::passing_guard(), $mock);
        try {
            $transport->send($method, self::endpoint(), 'status', ['Accept' => 'application/json'], $body);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount(1, $mock, 'Nothing was sent.');
    }

    public function test_a_post_without_a_body_is_sent(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $transport = new transport(developer_settings::from_value(null), network_fakes::passing_guard(), $mock);
        $transport->send('POST', self::endpoint(), 'status', ['Accept' => 'application/json'], '');
        $request = $mock->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('', (string) $request->getBody());
    }

    public function test_a_get_request_carries_no_body(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $transport = new transport(developer_settings::from_value(null), network_fakes::passing_guard(), $mock);
        $transport->send('GET', self::endpoint(), 'status', ['Accept' => 'application/json'], '');
        $request = $mock->getLastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(0, $request->getBody()->getSize());
        $this->assertFalse($request->hasHeader('Content-Length'));
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

    public function test_a_response_of_the_largest_size_is_read(): void {
        $response = self::send(new MockHandler([new Response(200, [], str_repeat('x', transport::MAX_RESPONSE))]));
        $this->assertSame(transport::MAX_RESPONSE, strlen($response->body));
    }

    public function test_a_declared_length_at_the_limit_is_read(): void {
        $headers = ['Content-Length' => (string) transport::MAX_RESPONSE];
        $this->assertSame('{}', self::send(new MockHandler([new Response(200, $headers, '{}')]))->body);
    }

    public function test_a_declared_length_that_is_not_a_number_does_not_stop_a_small_response(): void {
        // As a number the header is 0; the body is still read only up to the limit.
        $this->assertSame('{}', self::send(new MockHandler([new Response(200, ['Content-Length' => 'abc'], '{}')]))->body);
    }

    public function test_a_body_that_does_not_end_is_read_only_up_to_the_limit(): void {
        $chunks = 0;
        $limit = intdiv(transport::MAX_RESPONSE, 65536);
        $source = static function () use (&$chunks, $limit) {
            // Chunks of 64 KiB, four times the limit in all: a reader that does not stop at the limit takes all of them.
            return $chunks++ < 4 * $limit ? str_repeat('x', 65536) : false;
        };
        $this->expect_failure('transport_too_large', new MockHandler([new Response(200, [], new PumpStream($source))]));
        // The limit and one more byte are the chunks of the limit and one byte.
        $this->assertLessThanOrEqual($limit + 1, $chunks);
    }

    public function test_the_response_is_not_decompressed_so_that_the_limit_counts_the_bytes_of_the_wire(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::send($mock);
        $this->assertFalse($mock->getLastOptions()['decode_content']);
    }

    public function test_the_progress_callback_cuts_a_transfer_over_the_limit(): void {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        self::send($mock);
        $curl = $mock->getLastOptions()['curl'];
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        // Returning 1 aborts the transfer at once; an exception from Guzzle's own progress option would come out late.
        $progress = $curl[CURLOPT_PROGRESSFUNCTION];
        $this->assertSame(0, $progress(null, 0, 0, 0, 0));
        $this->assertSame(0, $progress(null, 0, transport::MAX_RESPONSE, 0, 0));
        $this->assertSame(1, $progress(null, 0, transport::MAX_RESPONSE + 1, 0, 0));
    }

    public function test_a_transfer_cut_by_the_progress_callback_is_a_response_that_is_too_large(): void {
        // What curl does when the callback returns 1: it stops with error 42, and Guzzle rejects the request with it.
        $returned = null;
        $cutting = function (RequestInterface $request, array $options) use (&$returned) {
            $progress = $options['curl'][CURLOPT_PROGRESSFUNCTION];
            $returned = $progress(null, 0, transport::MAX_RESPONSE + 1, 0, 0);
            return new RequestException(
                'cURL error 42: Operation was aborted by an application callback',
                $request,
                null,
                null,
                ['errno' => 42]
            );
        };
        $this->expect_failure('transport_too_large', new MockHandler([$cutting]));
        // Checked here and not in the handler: send() turns an exception of a failed assertion inside the client into a failure.
        $this->assertSame(1, $returned);
    }

    public function test_error_42_that_the_progress_callback_did_not_cause_is_another_failure(): void {
        $e = $this->expect_failure('transport_failed', new MockHandler([
            new RequestException('cURL error 42', new Request('POST', 'https://app.example.test/'), null, null, ['errno' => 42]),
        ]));
        $this->assertSame('cURL error 42', $e->debuginfo);
    }

    /**
     * Error numbers of curl, with the kind of failure each one makes.
     *
     * @return array
     */
    public static function curl_error_provider(): array {
        $rows = [];
        foreach ([35, 51, 58, 60, 77, 83, 90, 91] as $errno) {
            $rows["error $errno is a certificate or TLS failure"] = [$errno, 'transport_tls'];
        }
        // 97 is what libcurl 7.73.0 and later report when the handshake with a SOCKS proxy fails; older versions report 7.
        foreach ([5, 6, 7, 28, 52, 55, 56, 97] as $errno) {
            $rows["error $errno is a failed or timed out connection"] = [$errno, 'transport_connect'];
        }
        foreach ([1, 3, 18, 23, 47, 61] as $errno) {
            $rows["error $errno is another failure"] = [$errno, 'transport_failed'];
        }
        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('curl_error_provider')]
    /**
     * An error number of curl decides the kind of failure, and is all the debug information that is kept.
     *
     * @dataProvider curl_error_provider
     * @param int $errno The error number.
     * @param string $code The error code of the exception.
     */
    public function test_a_curl_error_gives_the_kind_of_failure(int $errno, string $code): void {
        $request = new Request('POST', 'https://app.example.test/');
        $e = $this->expect_failure($code, new MockHandler([
            new RequestException('cURL error ' . $errno . ': text (see https://app.example.test/?secret=1)', $request, null, null, [
                'errno' => $errno,
            ]),
        ]));
        $this->assertSame('cURL error ' . $errno, $e->debuginfo);
    }

    public function test_a_certificate_failure_that_guzzle_reports_as_a_connection_failure_is_still_a_certificate_failure(): void {
        // Guzzle makes a ConnectException of the error 35, which is a failed TLS handshake.
        $e = $this->expect_failure('transport_tls', new MockHandler([
            new ConnectException('cURL error 35', new Request('POST', 'https://app.example.test/'), null, ['errno' => 35]),
        ]));
        $this->assertSame('cURL error 35', $e->debuginfo);
    }

    public function test_an_error_number_given_as_text_is_read_as_a_number(): void {
        $e = $this->expect_failure('transport_tls', new MockHandler([
            new RequestException('cURL error 60', new Request('POST', 'https://app.example.test/'), null, null, ['errno' => '60']),
        ]));
        $this->assertSame('cURL error 60', $e->debuginfo);
    }

    public function test_a_connection_failure_without_an_error_number_names_the_class(): void {
        $e = $this->expect_failure('transport_connect', new MockHandler([
            new ConnectException('Could not connect', new Request('POST', 'https://app.example.test/')),
        ]));
        $this->assertSame(ConnectException::class, $e->debuginfo);
    }

    /**
     * Status codes around the redirects: the answer for the code, or null for a response that is returned.
     *
     * @return array
     */
    public static function redirect_status_provider(): array {
        return [
            '299 is returned' => [299, null],
            '300 is a redirect' => [300, 'HTTP 300'],
            '301 is a redirect' => [301, 'HTTP 301'],
            '303 is a redirect' => [303, 'HTTP 303'],
            '307 is a redirect' => [307, 'HTTP 307'],
            '308 is a redirect' => [308, 'HTTP 308'],
            '399 is a redirect' => [399, 'HTTP 399'],
            '400 is returned' => [400, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirect_status_provider')]
    /**
     * Every 3xx status is refused, and the debug information names the status. Other statuses are returned as they are.
     *
     * @dataProvider redirect_status_provider
     * @param int $status The HTTP status.
     * @param string|null $debuginfo The debug information of the refusal, or null for a response that is returned.
     */
    public function test_every_redirect_status_is_refused(int $status, ?string $debuginfo): void {
        $mock = new MockHandler([new Response($status, [], '{}')]);
        if ($debuginfo === null) {
            $this->assertSame($status, self::send($mock)->status);
            return;
        }
        $e = $this->expect_failure('transport_redirect', $mock);
        $this->assertSame($debuginfo, $e->debuginfo);
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
            'zero' => ['0', 0],
            'leading zeros' => ['000120', 120],
            'exactly a day' => ['86400', 86400],
            'capped at a day' => ['90000', 86400],
            'one second over a day' => ['86401', 86400],
            'six digits' => ['999999', 86400],
            'http date is ignored' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
            'too many digits' => ['1234567', null],
            'text before the digits' => ['in 120', null],
            'text after the digits' => ['120 seconds', null],
            'a sign' => ['-1', null],
            'a plus sign' => ['+30', null],
            'a fraction' => ['1.5', null],
            'nothing' => ['', null],
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

    /**
     * curl is told to use the folder of the bundled roots as its directory of certificates (CURLOPT_CAPATH), so that it does not
     * add the one of the system. OpenSSL reads a directory through files named after the hash of a subject, such as "4a6481c9.0"
     * for a certificate and "4a6481c9.r0" for a revocation list. The folder holds none, so it adds no trust; a file named like
     * that, added later, would. The pattern ignores case because a file system may.
     */
    public function test_the_folder_of_the_roots_holds_no_file_named_like_a_hash_of_openssl(): void {
        $hashname = '/^[0-9a-f]{8}\.r?\d+\z/i';
        foreach (['4a6481c9.0', '4a6481c9.12', '4a6481c9.r0', 'DEADBEEF.r3'] as $name) {
            $this->assertSame(1, preg_match($hashname, $name), $name . ' is read by OpenSSL.');
        }
        foreach (['amazon-roots.pem', 'aws-ranges.json', '4a6481c9', '4a6481c9.0.bak', '4a6481c.0', 'x4a6481c9.0'] as $name) {
            $this->assertSame(0, preg_match($hashname, $name), $name . ' is not read by OpenSSL.');
        }
        $names = scandir(dirname(transport::roots_file()));
        $this->assertContains(basename(transport::roots_file()), $names, 'The folder is the one that is scanned.');
        $found = array_values(array_filter($names, static fn(string $name): bool => preg_match($hashname, $name) === 1));
        $this->assertSame([], $found);
    }

    public function test_create_reads_the_site_settings(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['skipnetworkchecks' => true];
        $this->assertInstanceOf(transport::class, transport::create());
    }

    public function test_the_error_codes_are_language_strings(): void {
        $codes = ['transport_blocked', 'transport_connect', 'transport_failed', 'transport_redirect', 'transport_tls',
            'transport_too_large'];
        foreach ($codes as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }

    public function test_the_headers_the_body_and_the_failure_are_marked_sensitive(): void {
        $marked = [];
        foreach ([[transport::class, 'send'], [guarded_options::class, 'failure']] as [$class, $name]) {
            foreach ((new \ReflectionMethod($class, $name))->getParameters() as $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $marked[$name][] = $parameter->getName();
                }
            }
        }
        // The headers carry the signature, the body can hold a registration code, and the Guzzle failure holds the request.
        $this->assertSame(['send' => ['headers', 'body'], 'failure' => ['e']], $marked);
    }

    /**
     * Calls of send() that throw: from its own checks, from the name check, from the endpoint, from the mapping of a failure
     * of Guzzle and from the checks of the response. Each row: what the mocked network answers, whether the name check fails,
     * the name of the call, the method.
     *
     * @return array
     */
    public static function failing_send_provider(): array {
        $request = new Request('POST', 'https://app.example.test/');
        $toolarge = ['Content-Length' => (string) (transport::MAX_RESPONSE + 1)];
        return [
            'a body with GET' => [[], false, 'enroll', 'GET'],
            'a name check that fails' => [[], true, 'enroll', 'POST'],
            'a name of the call that the endpoint refuses' => [[], false, 'Not/Allowed', 'POST'],
            'a certificate that cannot be verified' => [
                [new RequestException('cURL error 60', $request, null, null, ['errno' => 60])], false, 'enroll', 'POST',
            ],
            'a connection that fails' => [
                [new ConnectException('cURL error 7', $request, null, ['errno' => 7])], false, 'enroll', 'POST',
            ],
            'a redirect' => [[new Response(302, ['Location' => 'https://elsewhere.example/'], '')], false, 'enroll', 'POST'],
            'a declared length over the limit' => [[new Response(200, $toolarge, 'x')], false, 'enroll', 'POST'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_send_provider')]
    /**
     * An exception that send() throws keeps the signature and the body out of its message, and has no previous exception,
     * which would keep the trace of the call that made it. That holds for the Guzzle failure that send() turns into one, too:
     * the failure holds the request.
     *
     * @dataProvider failing_send_provider
     * @param array $queue What the mocked network answers.
     * @param bool $namecheckfails Whether the name check fails.
     * @param string $relative Name of the API call.
     * @param string $method The method.
     */
    public function test_a_failure_does_not_leave_the_signature_or_the_body_in_the_message(
        array $queue,
        bool $namecheckfails,
        string $relative,
        string $method
    ): void {
        $e = $this->exception_from_failing_send($queue, $namecheckfails, $relative, $method);
        $this->assert_no_secret_in_the_message($e);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_send_provider')]
    /**
     * An exception that send() throws keeps the signature and the body out of the arguments of its trace.
     *
     * @dataProvider failing_send_provider
     * @param array $queue What the mocked network answers.
     * @param bool $namecheckfails Whether the name check fails.
     * @param string $relative Name of the API call.
     * @param string $method The method.
     */
    public function test_a_failure_does_not_leave_the_signature_or_the_body_in_the_trace(
        array $queue,
        bool $namecheckfails,
        string $relative,
        string $method
    ): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the headers and the body stay in the'
                . ' arguments of the trace there.');
        }
        $e = $this->exception_from_failing_send($queue, $namecheckfails, $relative, $method);
        $this->assertFalse(self::trace_leaks($e), 'The signature or the body is in the trace.');
    }

    /**
     * Calls send() as a row of failing_send_provider says, and returns what it throws.
     *
     * @param array $queue What the mocked network answers.
     * @param bool $namecheckfails Whether the name check fails.
     * @param string $relative Name of the API call.
     * @param string $method The method.
     * @return \Throwable What send() throws.
     */
    private function exception_from_failing_send(array $queue, bool $namecheckfails, string $relative, string $method): \Throwable {
        $guard = $namecheckfails
            ? network_fakes::guard(['13.225.1.1'], null, ['203.0.113.7'])
            : network_fakes::passing_guard();
        $headers = ['Accept' => 'application/json', 'X-SecBackup-Signature' => self::SIGNATURE];
        return $this->exception_from_send(new MockHandler($queue), $guard, $method, $relative, $headers);
    }

    /**
     * Failures inside the HTTP client that are not Guzzle exceptions. Each row: what the mocked network does, the headers,
     * the class that the exception names.
     *
     * @return array
     */
    public static function client_failure_provider(): array {
        $good = ['Accept' => 'application/json', 'X-SecBackup-Signature' => self::SIGNATURE];
        $refusing = static function (RequestInterface $request, array $options): void {
            throw new \InvalidArgumentException('SSL CA bundle not found: /nowhere/amazon-roots.pem');
        };
        $failing = static function (RequestInterface $request, array $options): void {
            throw new \TypeError('Something is wrong inside the handler');
        };
        return [
            // PSR-7 refuses a header value with a line break, and a header name with a blank, when it builds the request.
            'a header value with a line break' => [
                [],
                ['X-SecBackup-Signature' => self::SIGNATURE . "\r\nX-Injected: 1"] + $good,
                \InvalidArgumentException::class,
            ],
            'a header name that is not allowed' => [[], ['Bad Name' => 'x'] + $good, \InvalidArgumentException::class],
            'a handler that does not find the roots' => [[$refusing], $good, \InvalidArgumentException::class],
            'an error of PHP inside the handler' => [[$failing], $good, \TypeError::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('client_failure_provider')]
    /**
     * Whatever is thrown inside the HTTP client, send() does not let it out: a Guzzle exception becomes a kind of failure,
     * and anything else becomes "transport_failed" that names only the class. What Guzzle or PHP threw carries the arguments
     * of Guzzle's calls, the request among them, and PSR-7 puts a refused header value into its message.
     *
     * @dataProvider client_failure_provider
     * @param array $queue What the mocked network does.
     * @param array $headers The headers of the call.
     * @param string $class The class that the debug information names.
     */
    public function test_a_failure_inside_the_http_client_is_replaced_by_one_that_names_only_its_class(
        array $queue,
        array $headers,
        string $class
    ): void {
        $e = $this->exception_from_send(new MockHandler($queue), network_fakes::passing_guard(), 'POST', 'enroll', $headers);
        $this->assertInstanceOf(transport_exception::class, $e);
        $this->assertSame('transport_failed', $e->errorcode);
        $this->assertSame($class, $e->debuginfo);
        $this->assert_no_secret_in_the_message($e);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('client_failure_provider')]
    /**
     * What replaces a failure inside the HTTP client keeps the signature and the body out of the arguments of its trace.
     *
     * @dataProvider client_failure_provider
     * @param array $queue What the mocked network does.
     * @param array $headers The headers of the call.
     * @param string $class The class that the debug information names.
     */
    public function test_a_failure_inside_the_http_client_leaves_no_secret_in_the_trace(
        array $queue,
        array $headers,
        string $class
    ): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the headers and the body stay in the'
                . ' arguments of the trace there.');
        }
        $e = $this->exception_from_send(new MockHandler($queue), network_fakes::passing_guard(), 'POST', 'enroll', $headers);
        $this->assertSame($class, $e->debuginfo);
        $this->assertFalse(self::trace_leaks($e), 'The signature or the body is in the trace.');
    }

    /**
     * Calls send() with PHP keeping the arguments of calls in traces, and returns what it throws.
     *
     * A trace holds the arguments of the calls only while zend.exception_ignore_args is off, so it is off during the call:
     * with it on, the check would find nothing whatever the code does. A control exception shows that the check does see the
     * arguments when PHP keeps them.
     *
     * @param MockHandler $mock What the mocked network does.
     * @param dns_guard $guard Name check.
     * @param string $method The method.
     * @param string $relative Name of the API call.
     * @param string[] $headers The headers.
     * @return \Throwable What send() throws.
     */
    private function exception_from_send(
        MockHandler $mock,
        dns_guard $guard,
        string $method,
        string $relative,
        array $headers
    ): \Throwable {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            $control = self::exception_created_with($headers, self::BODY);
            $this->assertTrue(self::trace_leaks($control), 'A trace shows the arguments when PHP keeps them.');
            try {
                $transport = new transport(developer_settings::from_value(null), $guard, $mock);
                $transport->send($method, self::endpoint(), $relative, $headers, self::BODY);
            } catch (\Throwable $e) {
                return $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->fail('send() has to throw');
    }

    /**
     * Asserts that an exception of send() keeps the signature and the body out of its message, and has no previous exception.
     * The arguments of its trace have tests of their own, which are skipped before PHP 8.2.
     *
     * @param \Throwable $e The exception.
     */
    private function assert_no_secret_in_the_message(\Throwable $e): void {
        $this->assertNull($e->getPrevious(), 'A previous exception keeps the trace of the call that made it.');
        $this->assertStringNotContainsString(self::SIGNATURE, $e->getMessage());
        $this->assertStringNotContainsString(self::BODY, $e->getMessage());
    }

    /**
     * Returns an exception created in a call with the arguments, so that its trace holds them when PHP keeps arguments.
     *
     * @param string[] $headers Headers.
     * @param string $body A body.
     * @return \Exception
     */
    private static function exception_created_with(array $headers, string $body): \Exception {
        return new \Exception('Control exception for ' . count($headers) . ' headers and ' . strlen($body) . ' bytes');
    }

    /**
     * Tells whether the arguments in the trace of an exception, as far as the call of send(), hold the signature or the body,
     * also inside arrays and as part of longer text, or hold a Guzzle exception or message, which holds the request.
     *
     * @param \Throwable $exception The exception.
     * @return bool
     */
    private static function trace_leaks(\Throwable $exception): bool {
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (self::holds($argument)) {
                    return true;
                }
            }
            if (($frame['class'] ?? null) === transport::class && $frame['function'] === 'send') {
                // The frames above this one are those of the caller, here the test.
                break;
            }
        }
        return false;
    }

    /**
     * Tells whether an argument is, or holds, the signature or the body, or is an exception or a message.
     *
     * @param mixed $argument An argument of a call in a trace.
     * @return bool
     */
    private static function holds(mixed $argument): bool {
        if ($argument instanceof \Throwable || $argument instanceof MessageInterface) {
            return true;
        }
        if (is_string($argument)) {
            return str_contains($argument, self::SIGNATURE) || str_contains($argument, self::BODY);
        }
        if (is_array($argument)) {
            foreach ($argument as $item) {
                if (self::holds($item)) {
                    return true;
                }
            }
        }
        return false;
    }
}
