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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\object_transport::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\guarded_options::class)]
/**
 * Tests for the transport to the storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\object_transport
 * @covers     \tool_secbackup\local\net\guarded_options
 */
final class object_transport_test extends \advanced_testcase {
    /** @var string The signature inside a pre-signed URL: no exception keeps it in its message or in its trace. */
    private const SECRET = 'X-Amz-Signature=5ecretSignatureOfTheTestUrl';

    /** @var string The password of a proxy: it must not stay in any trace either. */
    private const PROXYPASSWORD = 'pr0xyPassw0rdOfTheTest';

    /** @var string Address of the test object. */
    private const URL = 'https://bucket-1.s3.ap-northeast-1.amazonaws.com/sites/x/objects/y?X-Amz-Expires=900&' . self::SECRET;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * The URL of the test object.
     *
     * @return object_url
     */
    private static function url(): object_url {
        return object_url::parse(self::URL, developer_settings::from_value(null));
    }

    /**
     * A file in a directory that the test removes.
     *
     * @param string|null $content Content; null for a path where no file is.
     * @return string Path.
     */
    private function file(?string $content): string {
        $path = make_request_directory() . '/part';
        if ($content !== null) {
            file_put_contents($path, $content);
        }
        return $path;
    }

    /**
     * The headers that the backup service returns for a body.
     *
     * @param string $content The body.
     * @return string[]
     */
    private static function headers(string $content): array {
        return [
            'Content-Length' => (string) strlen($content),
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', $content, true)),
            'x-amz-storage-class' => 'STANDARD',
            'x-amz-object-lock-mode' => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => '2026-12-01T00:00:00Z',
        ];
    }

    /**
     * An answer that reads the whole body first, as a network does.
     *
     * @param int $status The status.
     * @param string[] $headers Headers of the answer.
     * @param string $body Body of the answer.
     * @return callable
     */
    private static function reading(int $status = 200, array $headers = [], string $body = ''): callable {
        return static function (RequestInterface $request) use ($status, $headers, $body): Response {
            $request->getBody()->getContents();
            return new Response($status, $headers, $body);
        };
    }

    /**
     * The transport, with production settings and a name check that passes for the storage.
     *
     * @param callable $handler Mocked network.
     * @param dns_guard|null $guard Name check.
     * @param developer_settings|null $settings Developer settings.
     * @return object_transport
     */
    private static function transport(
        callable $handler,
        ?dns_guard $guard = null,
        ?developer_settings $settings = null
    ): object_transport {
        return new object_transport(
            $settings ?? developer_settings::from_value(null),
            $guard ?? network_fakes::passing_s3_guard(),
            $handler
        );
    }

    /**
     * Sends a file and returns the exception, which has to be thrown.
     *
     * @param MockHandler $mock Mocked network.
     * @param string $content Content of the file.
     * @param dns_guard|null $guard Name check.
     * @return transport_exception
     */
    private function put_failure(MockHandler $mock, string $content = 'data', ?dns_guard $guard = null): transport_exception {
        try {
            self::transport($mock, $guard)->put_file(self::url(), self::headers($content), $this->file($content), 0);
        } catch (transport_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }

    /**
     * Reads the object into a file and returns the exception, which has to be thrown.
     *
     * @param MockHandler $mock Mocked network.
     * @param string $path File.
     * @param int $maxbytes Limit.
     * @return transport_exception
     */
    private function get_failure(MockHandler $mock, string $path, int $maxbytes = 1000): transport_exception {
        try {
            self::transport($mock)->get_to_file(self::url(), $path, $maxbytes);
        } catch (transport_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }

    public function test_the_file_is_sent_with_put_and_the_headers_as_they_were_given(): void {
        $content = "some bytes\n\0of a part";
        $sent = null;
        $mock = new MockHandler([static function (RequestInterface $request) use (&$sent): Response {
            $sent = (string) $request->getBody();
            return new Response(200);
        }]);
        $headers = self::headers($content);
        self::transport($mock)->put_file(self::url(), $headers, $this->file($content), 0);

        $request = $mock->getLastRequest();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame($content, $sent);
        foreach ($headers as $name => $value) {
            $this->assertSame([$value], $request->getHeader($name), $name);
        }
        $this->assertSame((string) strlen($content), $request->getHeaderLine('Content-Length'));
        $this->assertFalse($request->hasHeader('Transfer-Encoding'));
        $this->assertSame('tool_secbackup', $request->getHeaderLine('User-Agent'));
    }

    public function test_header_names_are_kept_in_the_case_that_was_given(): void {
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(
            self::url(),
            ['content-length' => '4', 'X-AMZ-Checksum-SHA256' => base64_encode(hash('sha256', 'data', true))],
            $this->file('data'),
            0
        );
        $names = array_keys($mock->getLastRequest()->getHeaders());
        $this->assertContains('content-length', $names);
        $this->assertContains('X-AMZ-Checksum-SHA256', $names);
    }

    public function test_the_connection_is_pinned_to_the_checked_address_with_the_amazon_roots(): void {
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $options = $mock->getLastOptions();
        $this->assertSame(transport::roots_file(), $options['verify']);
        $this->assertSame(dirname(transport::roots_file()), $options['curl'][CURLOPT_CAPATH]);
        $this->assertSame(['bucket-1.s3.ap-northeast-1.amazonaws.com:443:3.5.152.9'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);
        $this->assertFalse($options['decode_content']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function test_the_name_check_is_the_one_of_the_storage(): void {
        $asked = [];
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['3.5.152.1'])],
            static function (string $host) use (&$asked): array {
                $asked[] = $host;
                return ['3.5.152.9'];
            }
        );
        $mock = new MockHandler([self::reading()]);
        self::transport($mock, $guard)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $this->assertSame(['bucket-1.s3.ap-northeast-1.amazonaws.com'], $asked);
    }

    public function test_an_address_of_the_service_is_not_an_address_of_the_storage(): void {
        // 13.225.0.0/16 is a CloudFront range in the test list; the storage has to answer from its own ranges.
        $mock = new MockHandler([self::reading()]);
        $e = $this->put_failure($mock, 'data', network_fakes::passing_guard());
        $this->assertSame('dns_outside_aws', $e->errorcode);
        $this->assertCount(1, $mock, 'Nothing was sent.');
    }

    public function test_a_name_check_that_fails_sends_nothing(): void {
        $mock = new MockHandler([self::reading()]);
        $guard = network_fakes::guard(['3.5.152.1'], null, ['203.0.113.7']);
        $this->assertSame('dns_os_mismatch', $this->put_failure($mock, 'data', $guard)->errorcode);
        $this->assertCount(1, $mock);

        $mock = new MockHandler([new Response(200, [], 'x')]);
        try {
            self::transport($mock, $guard)->get_to_file(self::url(), $this->file(null), 10);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('dns_os_mismatch', $e->errorcode);
        }
        $this->assertCount(1, $mock);
    }

    public function test_with_a_socks5_proxy_the_checked_address_is_pinned_and_with_an_http_proxy_it_is_not(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $options = $mock->getLastOptions();
        $this->assertSame('socks5://proxy.example.ac.jp:1080', $options['proxy']);
        $this->assertSame(['bucket-1.s3.ap-northeast-1.amazonaws.com:443:3.5.152.9'], $options['curl'][CURLOPT_RESOLVE]);

        $CFG->proxytype = 'HTTP';
        $CFG->proxyport = '3128';
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $options = $mock->getLastOptions();
        $this->assertSame('http://proxy.example.ac.jp:3128', $options['proxy']);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertSame(transport::roots_file(), $options['verify']);
    }

    public function test_skipnetworkchecks_uses_neither_the_guard_nor_the_pinning(): void {
        $settings = developer_settings::from_value(['skipnetworkchecks' => true]);
        $mock = new MockHandler([self::reading()]);
        self::transport($mock, network_fakes::guard(null, null, []), $settings)
            ->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $options = $mock->getLastOptions();
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertArrayNotHasKey(CURLOPT_CAPATH, $options['curl']);
        $this->assertNotSame(transport::roots_file(), $options['verify'] ?? true);
    }

    public function test_private_hosts_skip_the_security_list_and_a_port_is_pinned(): void {
        set_config('curlsecurityblockedhosts', 'localhost');
        $settings = developer_settings::from_value(['allowhttp' => true, 'allowprivatehosts' => true]);
        $url = object_url::parse('http://localhost:8099/_s3/k?a=b', $settings);
        $guard = network_fakes::guard(['3.5.152.1'], ['3.5.152.2'], ['3.5.152.9']);
        $mock = new MockHandler([self::reading()]);
        self::transport($mock, $guard, $settings)->put_file($url, self::headers('data'), $this->file('data'), 0);
        $this->assertSame('http://localhost:8099/_s3/k?a=b', (string) $mock->getLastRequest()->getUri());
        $this->assertSame(['localhost:8099:3.5.152.9'], $mock->getLastOptions()['curl'][CURLOPT_RESOLVE]);
    }

    public function test_moodles_security_list_applies_without_private_hosts(): void {
        set_config('curlsecurityblockedhosts', 'bucket-1.s3.ap-northeast-1.amazonaws.com');
        $mock = new MockHandler([self::reading()]);
        $this->assertSame('transport_blocked', $this->put_failure($mock)->errorcode);
        $this->assertDebuggingCalled();
        $this->assertCount(1, $mock);
    }

    public function test_the_limit_of_the_speed_is_given_to_curl_only_when_there_is_one(): void {
        $mock = new MockHandler([self::reading(), self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 131072);
        $this->assertSame(131072, $mock->getLastOptions()['curl'][CURLOPT_MAX_SEND_SPEED_LARGE]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $this->assertArrayNotHasKey(CURLOPT_MAX_SEND_SPEED_LARGE, $mock->getLastOptions()['curl']);
    }

    public function test_a_negative_limit_of_the_speed_is_a_coding_error(): void {
        $mock = new MockHandler([self::reading()]);
        try {
            self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), -1);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('not negative', $e->getMessage());
        }
        $this->assertCount(1, $mock);
    }

    /**
     * Sizes and the time limit of a transfer: the size over 128 KiB a second and 60 seconds, at most 3600.
     *
     * @return array
     */
    public static function timeout_provider(): array {
        return [
            'nothing' => [0, 60],
            'one byte' => [1, 61],
            'a second of transfer' => [131072, 61],
            'a byte over a second' => [131073, 62],
            'a megabyte' => [1048576, 68],
            'a part of 32 MiB' => [33554432, 316],
            'the last size under the cap' => [(3600 - 60) * 131072, 3600],
            'the first size over the cap' => [(3600 - 60) * 131072 + 1, 3600],
            'a very large size' => [PHP_INT_MAX, 3600],
        ];
    }

    /**
     * Sizes that a download can have.
     *
     * @return array
     */
    public static function download_timeout_provider(): array {
        return array_filter(self::timeout_provider(), static fn(array $row): bool => $row[0] > 0);
    }

    /**
     * Sizes that a test can make a file of.
     *
     * @return array
     */
    public static function upload_timeout_provider(): array {
        return array_filter(self::timeout_provider(), static fn(array $row): bool => $row[0] <= 33554432);
    }

    /**
     * The time limit of a download follows the largest size.
     *
     * @dataProvider download_timeout_provider
     * @param int $size Size.
     * @param int $expected Seconds.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('download_timeout_provider')]
    public function test_the_time_limit_of_a_download_follows_the_largest_size(int $size, int $expected): void {
        $mock = new MockHandler([new Response(200, [], '')]);
        self::transport($mock)->get_to_file(self::url(), $this->file(null), $size);
        $this->assertSame($expected, $mock->getLastOptions()['timeout']);
    }

    /**
     * The time limit of an upload follows the declared length.
     *
     * @dataProvider upload_timeout_provider
     * @param int $size Size.
     * @param int $expected Seconds.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('upload_timeout_provider')]
    public function test_the_time_limit_of_an_upload_follows_the_declared_length(int $size, int $expected): void {
        $content = str_repeat('x', $size);
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers($content), $this->file($content), 0);
        $this->assertSame($expected, $mock->getLastOptions()['timeout']);
    }

    public function test_a_limit_of_the_speed_lengthens_the_time_limit(): void {
        // 200 MB at 125,000 bytes a second (1 Mbps) take 1600 seconds; the limit of 128 KiB/s would give 1589 and cut it off.
        $path = $this->file(null);
        $handle = fopen($path, 'wb');
        ftruncate($handle, 200000000);
        fclose($handle);
        $skipping = static function (RequestInterface $request): Response {
            $request->getBody()->seek(200000000);
            return new Response(200);
        };
        $headers = ['Content-Length' => '200000000'];
        $mock = new MockHandler([$skipping]);
        self::transport($mock)->put_file(self::url(), $headers, $path, 125000);
        $this->assertSame(1660, $mock->getLastOptions()['timeout']);
    }

    public function test_the_time_limit_is_the_size_over_the_slower_speed_and_at_most_3600(): void {
        $cases = [[1000, 0, 61], [1000, 1048576, 61], [1000, 131072, 61], [1000, 100, 70], [1000, 1, 1060], [5000, 1, 3600]];
        foreach ($cases as [$size, $limit, $expected]) {
            $content = str_repeat('x', $size);
            $mock = new MockHandler([self::reading()]);
            self::transport($mock)->put_file(self::url(), self::headers($content), $this->file($content), $limit);
            $this->assertSame($expected, $mock->getLastOptions()['timeout'], "$size bytes at $limit");
        }
    }

    /**
     * Header sets that are mistakes of the caller.
     *
     * @return array
     */
    public static function bad_headers_provider(): array {
        $good = [
            'Content-Length' => '4',
            'x-amz-checksum-sha256' => 'sum',
            'x-amz-storage-class' => 'STANDARD',
        ];
        return [
            'a header that is not bound' => [$good + ['Host' => 'example.test'], 'one of the five'],
            'an authorization header' => [$good + ['Authorization' => 'x'], 'one of the five'],
            'the user agent' => [$good + ['User-Agent' => 'x'], 'one of the five'],
            'a bound header twice in two cases' => [$good + ['content-length' => '4'], 'once'],
            'if-none-match that is not a star' => [$good + ['If-None-Match' => '"abc"'], 'If-None-Match'],
            'if-none-match twice' => [$good + ['If-None-Match' => '*', 'if-none-match' => '*'], 'once'],
            'a header with a line feed' => [['Content-Length' => '4', 'x-amz-storage-class' => "A\nHost: x"], 'plain text'],
            'a header with a carriage return' => [['Content-Length' => '4', 'x-amz-storage-class' => "A\rB"], 'plain text'],
            'a header with a character that is not ASCII' => [
                ['Content-Length' => '4', 'x-amz-storage-class' => "\u{3042}"],
                'plain',
            ],
            'a header with a control character' => [['Content-Length' => '4', 'x-amz-storage-class' => "A\x01"], 'plain text'],
            'a value that is not text' => [['Content-Length' => 4], 'plain text'],
            'an array as a value' => [['Content-Length' => '4', 'x-amz-storage-class' => ['A']], 'plain text'],
            'no content length' => [['x-amz-storage-class' => 'STANDARD'], 'Content-Length is a number'],
            'no headers' => [[], 'Content-Length is a number'],
            'a content length with a sign' => [['Content-Length' => '+4'], 'Content-Length is a number'],
            'a negative content length' => [['Content-Length' => '-4'], 'Content-Length is a number'],
            'a content length with a leading zero' => [['Content-Length' => '04'], 'Content-Length is a number'],
            'a content length with a space' => [['Content-Length' => '4 '], 'Content-Length is a number'],
            'a content length with a line feed at the end' => [['Content-Length' => "4\n"], 'plain text'],
            'an empty content length' => [['Content-Length' => ''], 'Content-Length is a number'],
            'a content length that is text' => [['Content-Length' => 'abc'], 'Content-Length is a number'],
            'a content length of 17 digits' => [['Content-Length' => '10000000000000000'], 'Content-Length is a number'],
        ];
    }

    /**
     * Headers that the url is not bound to are a coding error.
     *
     * @dataProvider bad_headers_provider
     * @param array $headers The headers.
     * @param string $message Start of the message.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_headers_provider')]
    public function test_headers_that_the_url_is_not_bound_to_are_a_coding_error(array $headers, string $message): void {
        $mock = new MockHandler([self::reading()]);
        try {
            self::transport($mock)->put_file(self::url(), $headers, $this->file('data'), 0);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount(1, $mock, 'Nothing was sent.');
    }

    public function test_a_content_length_of_sixteen_digits_is_a_number(): void {
        // The length is taken from the header and compared with the file: it is a number, but the file is not that long.
        $mock = new MockHandler([self::reading()]);
        $headers = ['Content-Length' => '1000000000000000'];
        try {
            self::transport($mock)->put_file(self::url(), $headers, $this->file('data'), 0);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('object_incomplete', $e->errorcode);
        }
        $this->assertCount(1, $mock);
    }

    public function test_a_file_that_is_not_as_long_as_the_content_length_is_not_sent(): void {
        foreach (['dat', 'datax'] as $content) {
            $mock = new MockHandler([self::reading()]);
            try {
                self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file($content), 0);
                $this->fail('No exception');
            } catch (transport_exception $e) {
                $this->assertSame('object_incomplete', $e->errorcode);
                $this->assertSame('file_size', $e->debuginfo);
            }
            $this->assertCount(1, $mock, 'Nothing was sent.');
        }
    }

    public function test_a_file_that_is_not_there_is_not_sent(): void {
        $mock = new MockHandler([self::reading()]);
        try {
            self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file(null), 0);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('transport_failed', $e->errorcode);
            $this->assertSame('file_unreadable', $e->debuginfo);
        }
        $this->assertCount(1, $mock);
    }

    public function test_an_empty_file_is_sent(): void {
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers(''), $this->file(''), 0);
        $this->assertSame('0', $mock->getLastRequest()->getHeaderLine('Content-Length'));
    }

    public function test_a_body_that_the_client_did_not_take_completely_is_an_incomplete_transfer(): void {
        $content = 'abcdefghij';
        $partial = static function (RequestInterface $request): Response {
            $request->getBody()->read(4);
            return new Response(200);
        };
        $e = $this->put_failure(new MockHandler([$partial]), $content);
        $this->assertSame('object_incomplete', $e->errorcode);
        $this->assertSame('sent', $e->debuginfo);
    }

    /**
     * Answers of the storage to a PUT, with the error code each one makes.
     *
     * @return array
     */
    public static function put_status_provider(): array {
        return [
            '100 is not an answer' => [100, 'object_rejected', 'http_100'],
            '199' => [199, 'object_rejected', 'http_199'],
            '200' => [200, null, null],
            '204' => [204, null, null],
            '299' => [299, null, null],
            '300' => [300, 'transport_redirect', 'HTTP 300'],
            '301' => [301, 'transport_redirect', 'HTTP 301'],
            '307' => [307, 'transport_redirect', 'HTTP 307'],
            '399' => [399, 'transport_redirect', 'HTTP 399'],
            '400' => [400, 'object_rejected', 'http_400'],
            '403' => [403, 'object_rejected', 'http_403'],
            '404' => [404, 'object_rejected', 'http_404'],
            '500' => [500, 'object_rejected', 'http_500'],
            '503' => [503, 'object_rejected', 'http_503'],
        ];
    }

    /**
     * The status of the answer decides.
     *
     * @dataProvider put_status_provider
     * @param int $status The status.
     * @param string|null $code The error code, or null for an upload that succeeds.
     * @param string|null $debuginfo Its debug information.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('put_status_provider')]
    public function test_the_status_of_the_answer_decides(int $status, ?string $code, ?string $debuginfo): void {
        $mock = new MockHandler([self::reading($status, ['Location' => 'https://elsewhere.example.test/x'], '<Error/>')]);
        if ($code === null) {
            self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
            $this->assertCount(0, $mock);
            return;
        }
        $e = $this->put_failure($mock);
        $this->assertSame($code, $e->errorcode);
        $this->assertSame($debuginfo, $e->debuginfo);
        $this->assertStringNotContainsString('amazonaws', (string) $e->debuginfo);
    }

    public function test_an_answer_that_is_announced_as_too_large_is_refused(): void {
        // An empty file, so that an answer that is not read through a callback is a complete transfer.
        $e = $this->put_failure(new MockHandler([
            new Response(200, ['Content-Length' => (string) (transport::MAX_RESPONSE + 1)], 'x'),
        ]), '');
        $this->assertSame('transport_too_large', $e->errorcode);
    }

    public function test_an_error_answer_that_is_announced_as_large_is_still_a_rejection(): void {
        $e = $this->put_failure(new MockHandler([
            new Response(403, ['Content-Length' => (string) (transport::MAX_RESPONSE + 1)], 'x'),
        ]), '');
        $this->assertSame('object_rejected', $e->errorcode);
    }

    public function test_an_answer_of_the_largest_size_is_accepted(): void {
        $mock = new MockHandler([new Response(200, ['Content-Length' => (string) transport::MAX_RESPONSE], 'x')]);
        self::transport($mock)->put_file(self::url(), self::headers(''), $this->file(''), 0);
        $this->assertCount(0, $mock);
    }

    public function test_the_progress_callback_cuts_a_transfer_over_the_limit(): void {
        $mock = new MockHandler([self::reading()]);
        self::transport($mock)->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
        $curl = $mock->getLastOptions()['curl'];
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        $progress = $curl[CURLOPT_PROGRESSFUNCTION];
        $this->assertSame(0, $progress(null, 0, 0, 0, 0));
        $this->assertSame(0, $progress(null, 0, transport::MAX_RESPONSE, 0, 0));
        $this->assertSame(1, $progress(null, 0, transport::MAX_RESPONSE + 1, 0, 0));
    }

    public function test_a_transfer_cut_by_the_progress_callback_is_a_response_that_is_too_large(): void {
        $returned = null;
        $cutting = function (RequestInterface $request, array $options) use (&$returned) {
            $returned = $options['curl'][CURLOPT_PROGRESSFUNCTION](null, 0, transport::MAX_RESPONSE + 1, 0, 0);
            return new RequestException('cURL error 42', $request, null, null, ['errno' => 42]);
        };
        $this->assertSame('transport_too_large', $this->put_failure(new MockHandler([$cutting]))->errorcode);
        $this->assertSame(1, $returned);
    }

    public function test_the_progress_callback_of_a_download_uses_the_limit_of_the_caller(): void {
        $mock = new MockHandler([new Response(200, [], 'abc')]);
        self::transport($mock)->get_to_file(self::url(), $this->file(null), 3);
        $progress = $mock->getLastOptions()['curl'][CURLOPT_PROGRESSFUNCTION];
        $this->assertSame(0, $progress(null, 0, 3, 0, 0));
        $this->assertSame(1, $progress(null, 0, 4, 0, 0));
    }

    /**
     * Failures of the connection, with the error code each one makes.
     *
     * @return array
     */
    public static function failure_provider(): array {
        $request = new Request('PUT', 'https://bucket-1.s3.ap-northeast-1.amazonaws.com/k?' . self::SECRET);
        return [
            'a certificate that cannot be verified' => [
                new RequestException('cURL error 60', $request, null, null, ['errno' => 60]),
                'transport_tls',
                'cURL error 60',
            ],
            'a connection that fails' => [new ConnectException('cURL error 7', $request, null, ['errno' => 7]),
                'transport_connect', 'cURL error 7'],
            'a time out' => [new RequestException('cURL error 28', $request, null, null, ['errno' => 28]),
                'transport_connect', 'cURL error 28'],
            'another failure' => [new RequestException('cURL error 55', $request, null, null, ['errno' => 18]),
                'transport_failed', 'cURL error 18'],
            'a failure of PHP' => [new \RuntimeException('Broken ' . self::URL), 'transport_failed', \RuntimeException::class],
        ];
    }

    /**
     * A failure of the connection is reported without the url.
     *
     * @dataProvider failure_provider
     * @param \Throwable $failure What the network does.
     * @param string $code The error code.
     * @param string $debuginfo Its debug information.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failure_provider')]
    public function test_a_failure_of_the_connection_is_reported_without_the_url(
        \Throwable $failure,
        string $code,
        string $debuginfo
    ): void {
        $put = $this->put_failure(new MockHandler([$failure]));
        $get = $this->get_failure(new MockHandler([$failure]), $this->file(null));
        foreach ([$put, $get] as $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame($debuginfo, $e->debuginfo);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('Signature', $e->getMessage() . $e->debuginfo);
        }
    }

    /**
     * Calls that throw, to see that no URL stays in the trace.
     *
     * @return array
     */
    public static function leaking_provider(): array {
        $request = new Request('PUT', self::URL);
        return [
            'a connection that fails' => [new ConnectException('cURL error 7', $request, null, ['errno' => 7]), false],
            'a failure of PHP' => [new \RuntimeException(self::URL), false],
            'an answer that is refused' => [new Response(403, [], 'denied'), false],
            'a name check that fails' => [new Response(200), true],
            'an answer that is too large' => [
                new Response(200, ['Content-Length' => (string) (transport::MAX_RESPONSE + 1)], 'x'),
                false,
            ],
            'an answer that is a redirect' => [new Response(302, ['Location' => 'https://elsewhere.example.test/'], ''), false],
        ];
    }

    /**
     * The url does not stay in the trace of a failure.
     *
     * @dataProvider leaking_provider
     * @param mixed $answer What the network does.
     * @param bool $namecheckfails Whether the name check fails.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('leaking_provider')]
    public function test_the_url_does_not_stay_in_the_trace_of_a_failure(mixed $answer, bool $namecheckfails): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing.');
        }
        $guard = $namecheckfails ? network_fakes::guard(['3.5.152.1'], null, ['203.0.113.7']) : null;
        // A SOCKS5 proxy looks nothing up, so the name check of this server still runs. The password is part of the proxy option.
        $this->use_proxy_with_credentials();
        $this->assertStringContainsString(self::PROXYPASSWORD, proxy_settings::for_url(self::URL));
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            foreach (['put', 'get'] as $way) {
                try {
                    $transport = self::transport(new MockHandler([$answer]), $guard);
                    if ($way === 'put') {
                        $transport->put_file(self::url(), self::headers('data'), $this->file('data'), 0);
                    } else {
                        $transport->get_to_file(self::url(), $this->file(null), 10);
                    }
                    $this->fail('No exception');
                } catch (transport_exception $e) {
                    $this->assertNull($e->getPrevious());
                    $this->assertStringNotContainsString('Signature', $e->getMessage());
                    $this->assertFalse(self::holds_secret($e->getTrace()), 'The URL is in the arguments of the trace.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /**
     * Sets a SOCKS5 proxy with a user name and a password.
     */
    private function use_proxy_with_credentials(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
        $CFG->proxyuser = 'proxyuser';
        $CFG->proxypassword = self::PROXYPASSWORD;
    }

    /**
     * Calls whose arguments hold a secret in different ways.
     *
     * @return array
     */
    public static function control_provider(): array {
        $url = object_url::parse(self::URL, developer_settings::from_value(null));
        return [
            'the URL as text' => [self::URL],
            'the URL in an object' => [$url],
            'the URL in an array' => [['a' => ['b' => self::URL]]],
            'the proxy password in an array' => [['proxy' => 'socks5://u:' . self::PROXYPASSWORD . '@proxy.example.ac.jp:1080']],
        ];
    }

    /**
     * The trace check sees a secret that is given to a call.
     *
     * @dataProvider control_provider
     * @param mixed $argument The argument.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('control_provider')]
    public function test_the_trace_check_sees_a_secret_that_is_given_to_a_call(mixed $argument): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $thrown = (static function (mixed $argument): \Exception {
                return new \Exception('x');
            })($argument);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertTrue(self::holds_secret($thrown->getTrace()));
    }

    /**
     * Tells whether the arguments of a trace hold the signature of the URL, or a Guzzle exception or message (which holds the
     * request).
     *
     * @param array $trace The trace.
     * @return bool
     */
    private static function holds_secret(array $trace): bool {
        foreach ($trace as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (self::argument_holds_secret($argument)) {
                    return true;
                }
            }
            if (in_array($frame['function'], ['put_file', 'get_to_file'], true)) {
                // The frames above this one are those of the caller, here the test.
                break;
            }
        }
        return false;
    }

    /**
     * Tells whether an argument holds the signature, the query or the proxy password, also inside arrays and longer text.
     *
     * @param mixed $argument An argument of a call in a trace.
     * @return bool
     */
    private static function argument_holds_secret(mixed $argument): bool {
        if ($argument instanceof \Throwable || $argument instanceof MessageInterface) {
            return true;
        }
        // The object of a pre-signed URL holds it. Other objects (a stream, a closure with the test case) are not read.
        if ($argument instanceof object_url) {
            return true;
        }
        if (is_array($argument)) {
            foreach ($argument as $item) {
                if (self::argument_holds_secret($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($argument)) {
            foreach ([self::SECRET, 'X-Amz-Expires', self::PROXYPASSWORD] as $needle) {
                if (str_contains($argument, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function test_the_url_and_the_options_are_marked_sensitive_where_they_are_passed_on(): void {
        $marked = [];
        $methods = [
            [object_url::class, 'parse'],
            [guarded_options::class, 'apply'],
            [object_transport::class, 'put_file'],
            [object_transport::class, 'get_to_file'],
            [object_transport::class, 'request'],
        ];
        foreach ($methods as [$class, $name]) {
            foreach ((new \ReflectionMethod($class, $name))->getParameters() as $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $marked[$name][] = $parameter->getName();
                }
            }
        }
        $this->assertSame([
            'parse' => ['url'],
            'apply' => ['url', 'options'],
            'put_file' => ['url'],
            'get_to_file' => ['url'],
            'request' => ['url', 'given'],
        ], $marked);
    }

    public function test_the_file_is_read_into_place_and_true_is_returned(): void {
        $path = $this->file(null);
        $mock = new MockHandler([new Response(200, ['Content-Length' => '11'], "some\nbytes\0")]);
        $this->assertTrue(self::transport($mock)->get_to_file(self::url(), $path, 11));
        $this->assertSame("some\nbytes\0", file_get_contents($path));
        $request = $mock->getLastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame($path, $mock->getLastOptions()['sink']);
        $this->assertSame('tool_secbackup', $request->getHeaderLine('User-Agent'));
    }

    public function test_a_missing_object_gives_false_and_leaves_no_file(): void {
        $path = $this->file('old');
        $mock = new MockHandler([new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>')]);
        $this->assertFalse(self::transport($mock)->get_to_file(self::url(), $path, 1000));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_missing_object_is_not_a_too_large_answer_whatever_it_announces(): void {
        $mock = new MockHandler([new Response(404, ['Content-Length' => '5000'], 'x')]);
        $this->assertFalse(self::transport($mock)->get_to_file(self::url(), $this->file(null), 1000));
    }

    public function test_other_answers_are_rejections_and_leave_no_file(): void {
        $rows = [
            [403, 'object_rejected', 'http_403'],
            [500, 'object_rejected', 'http_500'],
            [302, 'transport_redirect', 'HTTP 302'],
        ];
        foreach ($rows as $row) {
            $path = $this->file(null);
            $e = $this->get_failure(new MockHandler([new Response($row[0], [], 'denied')]), $path);
            $this->assertSame([$row[1], $row[2]], [$e->errorcode, $e->debuginfo]);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_an_object_larger_than_the_limit_is_refused_and_leaves_no_file(): void {
        // Announced: refused when the headers arrive. Not announced: refused when the file is longer than the limit.
        foreach ([['Content-Length' => '11'], []] as $headers) {
            $path = $this->file(null);
            $e = $this->get_failure(new MockHandler([new Response(200, $headers, str_repeat('x', 11))]), $path, 10);
            $this->assertSame('transport_too_large', $e->errorcode);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_an_object_of_the_size_of_the_limit_is_read(): void {
        $path = $this->file(null);
        $mock = new MockHandler([new Response(200, ['Content-Length' => '10'], str_repeat('x', 10))]);
        $this->assertTrue(self::transport($mock)->get_to_file(self::url(), $path, 10));
        $this->assertSame(10, filesize($path));
    }

    public function test_a_transfer_cut_by_the_progress_callback_of_a_download_is_too_large(): void {
        $path = $this->file(null);
        $cutting = function (RequestInterface $request, array $options) {
            $options['curl'][CURLOPT_PROGRESSFUNCTION](null, 0, 11, 0, 0);
            file_put_contents($options['sink'], 'partial');
            return new RequestException('cURL error 42', $request, null, null, ['errno' => 42]);
        };
        $e = $this->get_failure(new MockHandler([$cutting]), $path, 10);
        $this->assertSame('transport_too_large', $e->errorcode);
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_file_that_is_not_as_long_as_the_content_length_is_incomplete(): void {
        foreach ([['Content-Length' => '10'], ['Content-Length' => '3']] as $headers) {
            $path = $this->file(null);
            $e = $this->get_failure(new MockHandler([new Response(200, $headers, 'abcde')]), $path);
            $this->assertSame('object_incomplete', $e->errorcode);
            $this->assertSame('received', $e->debuginfo);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_an_object_without_a_declared_length_is_read(): void {
        $path = $this->file(null);
        $this->assertTrue(self::transport(new MockHandler([new Response(200, [], 'abcde')]))->get_to_file(self::url(), $path, 10));
        $this->assertSame('abcde', file_get_contents($path));
    }

    public function test_an_empty_object_is_read(): void {
        $path = $this->file(null);
        $mock = new MockHandler([new Response(200, ['Content-Length' => '0'], '')]);
        $this->assertTrue(self::transport($mock)->get_to_file(self::url(), $path, 10));
        $this->assertSame(0, filesize($path));
    }

    public function test_a_limit_that_is_not_positive_is_a_coding_error(): void {
        foreach ([0, -1] as $maxbytes) {
            $mock = new MockHandler([new Response(200)]);
            try {
                self::transport($mock)->get_to_file(self::url(), $this->file(null), $maxbytes);
                $this->fail('No exception');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('positive', $e->getMessage());
            }
            $this->assertCount(1, $mock);
        }
    }

    public function test_a_file_is_sent_to_the_fake_storage_and_read_back(): void {
        $service = new fake_service('https://app.example.test/api/lms-backup/v1');
        $url = object_url::parse(
            'https://bucket-1.s3.ap-northeast-1.amazonaws.com/_s3/sites/x/objects/y?' . self::SECRET,
            developer_settings::from_value(null)
        );
        $transport = self::transport($service->handler());
        $content = random_bytes(3000);
        $transport->put_file($url, self::headers($content), $this->file($content), 0);
        $this->assertSame(['sites/x/objects/y'], array_keys($service->state['objects']));
        $path = $this->file(null);
        $this->assertTrue($transport->get_to_file($url, $path, 4000));
        $this->assertSame($content, file_get_contents($path));
        $missing = object_url::parse(
            'https://bucket-1.s3.ap-northeast-1.amazonaws.com/_s3/sites/x/objects/z?a=b',
            developer_settings::from_value(null)
        );
        $this->assertFalse($transport->get_to_file($missing, $this->file(null), 4000));
    }

    public function test_the_fake_storage_refuses_what_a_real_one_refuses(): void {
        $service = new fake_service('https://app.example.test/api/lms-backup/v1');
        $url = object_url::parse(
            'https://bucket-1.s3.ap-northeast-1.amazonaws.com/_s3/k?a=b',
            developer_settings::from_value(null)
        );
        $transport = self::transport($service->handler());
        $wrongsum = ['x-amz-checksum-sha256' => base64_encode(hash('sha256', 'other', true))] + self::headers('data');
        $nolock = self::headers('data');
        unset($nolock['x-amz-object-lock-mode']);
        $nodate = self::headers('data');
        unset($nodate['x-amz-object-lock-retain-until-date']);
        $nochecksum = self::headers('data');
        unset($nochecksum['x-amz-checksum-sha256']);
        $refused = [
            'a wrong checksum' => $wrongsum,
            'no lock mode' => $nolock,
            'no lock date' => $nodate,
            'no checksum' => $nochecksum,
        ];
        foreach ($refused as $why => $headers) {
            try {
                $transport->put_file($url, $headers, $this->file('data'), 0);
                $this->fail($why . ' was accepted');
            } catch (transport_exception $e) {
                $this->assertSame(['object_rejected', 'http_400'], [$e->errorcode, $e->debuginfo], $why);
            }
        }
        $this->assertSame([], $service->state['objects']);
        $service->state['mode'] = 'unexpected';
        $this->assertSame(
            'http_500',
            $this->catch_code(fn() => $transport->put_file($url, self::headers('data'), $this->file('data'), 0))
        );
    }

    public function test_a_header_that_makes_the_put_conditional_is_sent_as_it_was_given(): void {
        $mock = new MockHandler([self::reading()]);
        $headers = self::headers('data') + ['If-None-Match' => '*'];
        $this->assertTrue(self::transport($mock)->put_file(self::url(), $headers, $this->file('data'), 0));
        $this->assertSame(['*'], $mock->getLastRequest()->getHeader('If-None-Match'));
        $this->assertSame(['STANDARD'], $mock->getLastRequest()->getHeader('x-amz-storage-class'));
    }

    public function test_a_412_to_a_conditional_put_means_that_the_object_is_stored_already(): void {
        $mock = new MockHandler([self::reading(412, [], '<Error><Code>PreconditionFailed</Code></Error>')]);
        $headers = self::headers('data') + ['If-None-Match' => '*'];
        $stored = self::transport($mock)->put_file(self::url(), $headers, $this->file('data'), 0);
        $this->assertFalse($stored, 'Not stored by this PUT.');
        $this->assertCount(0, $mock);
    }

    public function test_a_412_that_does_not_read_the_body_is_stored_too(): void {
        $skipping = static fn(RequestInterface $request): Response => new Response(412);
        $headers = self::headers('data') + ['if-none-match' => '*'];
        $transport = self::transport(new MockHandler([$skipping]));
        $this->assertFalse($transport->put_file(self::url(), $headers, $this->file('data'), 0));
    }

    public function test_a_412_to_a_put_that_was_not_conditional_is_a_rejection(): void {
        $mock = new MockHandler([self::reading(412)]);
        $e = $this->put_failure($mock);
        $this->assertSame(['object_rejected', 'http_412'], [$e->errorcode, $e->debuginfo]);
    }

    public function test_a_conflict_of_a_conditional_put_is_a_rejection_that_is_tried_again(): void {
        $mock = new MockHandler([self::reading(409, [], '<Error><Code>ConditionalRequestConflict</Code></Error>')]);
        $headers = self::headers('data') + ['If-None-Match' => '*'];
        try {
            self::transport($mock)->put_file(self::url(), $headers, $this->file('data'), 0);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame(['object_rejected', 'http_409'], [$e->errorcode, $e->debuginfo]);
        }
    }

    public function test_the_fake_storage_keeps_the_first_object_of_a_conditional_put(): void {
        $service = new fake_service('https://app.example.test/api/lms-backup/v1');
        $url = object_url::parse(
            'https://bucket-1.s3.ap-northeast-1.amazonaws.com/_s3/sites/x/objects/y?a=b',
            developer_settings::from_value(null)
        );
        $transport = self::transport($service->handler());
        $conditional = ['If-None-Match' => '*'];
        $transport->put_file($url, self::headers('first') + $conditional, $this->file('first'), 0);
        // The second is "stored already": the call succeeds and the first content stays.
        $transport->put_file($url, self::headers('other') + $conditional, $this->file('other'), 0);
        $this->assertSame('first', base64_decode($service->state['objects']['sites/x/objects/y']));
        // Without the header the storage replaces the object, as it does for the manifest and the index.
        $transport->put_file($url, self::headers('third'), $this->file('third'), 0);
        $this->assertSame('third', base64_decode($service->state['objects']['sites/x/objects/y']));
    }

    /**
     * The debug information of the exception that a call throws.
     *
     * @param callable $call The call.
     * @return string|null
     */
    private function catch_code(callable $call): ?string {
        try {
            $call();
        } catch (transport_exception $e) {
            return $e->debuginfo;
        }
        $this->fail('No exception');
    }

    public function test_create_reads_the_site_settings(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['skipnetworkchecks' => true];
        $this->assertInstanceOf(object_transport::class, object_transport::create());
    }

    public function test_the_error_codes_are_language_strings_in_both_languages(): void {
        foreach (['en', 'ja'] as $lang) {
            $string = [];
            include(dirname(__DIR__, 3) . '/lang/' . $lang . '/tool_secbackup.php');
            foreach (['object_incomplete', 'object_rejected', 'object_url_invalid'] as $code) {
                $this->assertArrayHasKey($code, $string, $lang . ' ' . $code);
                $this->assertNotSame('', $string[$code]);
            }
        }
        $this->assertTrue(get_string_manager()->string_exists('object_url_invalid', 'tool_secbackup'));
    }
}
