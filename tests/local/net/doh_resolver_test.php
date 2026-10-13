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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\doh_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\transport_exception::class)]
/**
 * Tests for the DNS over HTTPS resolvers.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\doh_resolver
 * @covers     \tool_secbackup\local\net\transport_exception
 */
final class doh_resolver_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * A mocked answer.
     *
     * @param int $status DNS status.
     * @param array $answers Answer records.
     * @return MockHandler
     */
    private static function answer(int $status, array $answers): MockHandler {
        return new MockHandler([new Response(
            200,
            ['Content-Type' => 'application/dns-json'],
            json_encode(['Status' => $status, 'Answer' => $answers])
        )]);
    }

    /**
     * Expects lookup() to fail.
     *
     * @param MockHandler $mock Mocked transport.
     */
    private function expect_failure(MockHandler $mock): void {
        try {
            (new doh_resolver('cloudflare', $mock))->lookup('app.example.test');
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('dns_doh_failed', $e->errorcode);
            $this->assertSame('cloudflare', $e->debuginfo);
        }
    }

    /**
     * A mocked answer of an exact size: valid JSON, padded with spaces.
     *
     * @param int $bytes Size of the body.
     * @param array $headers Response headers.
     * @return MockHandler
     */
    private static function answer_of_size(int $bytes, array $headers = []): MockHandler {
        return new MockHandler([new Response(200, $headers, str_pad('{"Status":3}', $bytes))]);
    }

    /**
     * Expects a request to name the plugin and nothing of this site: Moodle's own user agent carries the site URL.
     *
     * @param RequestInterface $request The request that the resolver sent.
     */
    private function assert_request_has_no_site_data(RequestInterface $request): void {
        global $CFG;
        $this->assertNotSame('', $CFG->wwwroot);
        $this->assertSame('tool_secbackup', $request->getHeaderLine('User-Agent'));
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($CFG->wwwroot, $value, $name);
            }
        }
        $this->assertStringNotContainsString($CFG->wwwroot, (string) $request->getUri());
    }

    public function test_cloudflare_query_and_answer(): void {
        $mock = self::answer(0, [
            ['name' => 'app.example.test', 'type' => 5, 'TTL' => 60, 'data' => 'd111.cloudfront.net.'],
            ['name' => 'd111.cloudfront.net', 'type' => 1, 'TTL' => 60, 'data' => '13.225.1.1'],
            ['name' => 'd111.cloudfront.net', 'type' => 1, 'TTL' => 60, 'data' => '13.225.1.1'],
            ['name' => 'd111.cloudfront.net', 'type' => 1, 'TTL' => 60, 'data' => '13.225.1.2'],
            ['name' => 'd111.cloudfront.net', 'type' => 28, 'TTL' => 60, 'data' => '2600:9000::1'],
            ['name' => 'd111.cloudfront.net', 'type' => 1, 'TTL' => 60, 'data' => 'not an address'],
            ['name' => 'd111.cloudfront.net', 'type' => 1, 'TTL' => 60, 'data' => "13.225.1.7\n"],
            'not a record',
        ]);
        $resolver = new doh_resolver('cloudflare', $mock);
        $this->assertSame('cloudflare', $resolver->name());
        $this->assertSame(['13.225.1.1', '13.225.1.2'], $resolver->lookup('app.example.test'));

        $request = $mock->getLastRequest();
        $this->assertSame('https://1.1.1.1/dns-query?type=A&name=app.example.test', (string) $request->getUri());
        $this->assertSame('application/dns-json', $request->getHeaderLine('Accept'));
        $this->assert_request_has_no_site_data($request);
        $options = $mock->getLastOptions();
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['decode_content'], 'A compressed answer would be opened to any size.');
        $this->assertSame('', $options['proxy']);
    }

    public function test_google_query(): void {
        $mock = self::answer(0, [['name' => 'x', 'type' => 1, 'TTL' => 60, 'data' => '13.225.1.9']]);
        $this->assertSame(['13.225.1.9'], (new doh_resolver('google', $mock))->lookup('app.example.test'));
        $this->assertSame('https://8.8.8.8/resolve?type=A&name=app.example.test', (string) $mock->getLastRequest()->getUri());
        $this->assert_request_has_no_site_data($mock->getLastRequest());
    }

    public function test_name_is_encoded(): void {
        $mock = self::answer(3, []);
        (new doh_resolver('cloudflare', $mock))->lookup('a&b c');
        $this->assertSame('https://1.1.1.1/dns-query?type=A&name=a%26b%20c', (string) $mock->getLastRequest()->getUri());
    }

    public function test_unknown_name_gives_no_addresses(): void {
        $this->assertSame([], (new doh_resolver('cloudflare', self::answer(3, [])))->lookup('app.example.test'));
    }

    public function test_answer_without_records_gives_no_addresses(): void {
        $mock = new MockHandler([new Response(200, [], '{"Status":0}')]);
        $this->assertSame([], (new doh_resolver('cloudflare', $mock))->lookup('app.example.test'));
    }

    /**
     * Bodies whose "Answer" is not a list.
     *
     * @return array
     */
    public static function answer_not_a_list_provider(): array {
        return [
            'a string' => ['{"Status":0,"Answer":"x"}'],
            'a number' => ['{"Status":0,"Answer":5}'],
            'true' => ['{"Status":0,"Answer":true}'],
            'null' => ['{"Status":0,"Answer":null}'],
            'next to a name that does not exist' => ['{"Status":3,"Answer":"x"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answer_not_a_list_provider')]
    /**
     * An "Answer" that is not a list cannot be read: it is an error, and PHP must not warn about it.
     *
     * @dataProvider answer_not_a_list_provider
     * @param string $body The response body.
     */
    public function test_an_answer_that_is_not_a_list_is_an_error(string $body): void {
        $this->expect_failure(new MockHandler([new Response(200, [], $body)]));
    }

    public function test_an_answer_of_the_largest_size_is_read(): void {
        $this->assertSame([], (new doh_resolver('cloudflare', self::answer_of_size(65536)))->lookup('app.example.test'));
    }

    public function test_a_larger_answer_is_an_error(): void {
        $this->expect_failure(self::answer_of_size(65537));
    }

    public function test_a_declared_length_at_the_limit_is_read(): void {
        $mock = new MockHandler([new Response(200, ['Content-Length' => '65536'], '{"Status":3}')]);
        $this->assertSame([], (new doh_resolver('cloudflare', $mock))->lookup('app.example.test'));
    }

    public function test_a_declared_length_over_the_limit_is_an_error(): void {
        // The body is small: the length in the header alone stops the answer, before the body is read.
        $this->expect_failure(new MockHandler([new Response(200, ['Content-Length' => '65537'], '{"Status":3}')]));
    }

    public function test_the_progress_callback_cuts_a_transfer_over_the_limit(): void {
        $mock = self::answer(3, []);
        (new doh_resolver('cloudflare', $mock))->lookup('app.example.test');
        $curl = $mock->getLastOptions()['curl'];
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        // Returning 1 aborts the transfer at once; an exception from Guzzle's own progress option would come out late.
        $progress = $curl[CURLOPT_PROGRESSFUNCTION];
        $this->assertSame(0, $progress(null, 0, 0, 0, 0));
        $this->assertSame(0, $progress(null, 0, 65536, 0, 0));
        $this->assertSame(1, $progress(null, 0, 65537, 0, 0));
    }

    public function test_server_failure_is_an_error(): void {
        $this->expect_failure(self::answer(2, []));
    }

    public function test_http_error_is_an_error(): void {
        $this->expect_failure(new MockHandler([new Response(503, [], '')]));
    }

    public function test_not_json_is_an_error(): void {
        $this->expect_failure(new MockHandler([new Response(200, [], 'nope')]));
    }

    public function test_connection_error_is_an_error(): void {
        $this->expect_failure(new MockHandler([
            new ConnectException('cURL error 7', new Request('GET', 'https://1.1.1.1/'), null, ['errno' => 7]),
        ]));
    }

    public function test_moodle_security_list_applies(): void {
        set_config('curlsecurityblockedhosts', '1.1.1.1');
        $mock = self::answer(0, []);
        $this->expect_failure($mock);
        $this->assertDebuggingCalled();
        $this->assertCount(1, $mock, 'The request must be stopped before it is sent.');
    }

    public function test_proxy_is_used(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $mock = self::answer(3, []);
        (new doh_resolver('google', $mock))->lookup('app.example.test');
        $this->assertSame('http://proxy.example.ac.jp:3128', $mock->getLastOptions()['proxy']);
    }

    public function test_the_message_is_the_language_string_without_the_debug_information(): void {
        $e = new transport_exception('dns_not_found', 'cloudflare');
        $this->assertSame(get_string('dns_not_found', 'tool_secbackup'), $e->message());
        $this->assertSame('dns_not_found', $e->errorcode);
        $this->assertSame('cloudflare', $e->debuginfo);
    }

    public function test_all_resolvers(): void {
        $names = array_map(static fn(doh_resolver $resolver): string => $resolver->name(), doh_resolver::all());
        $this->assertSame(['cloudflare', 'google'], $names);
    }

    public function test_unknown_resolver_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        new doh_resolver('quad9');
    }
}
