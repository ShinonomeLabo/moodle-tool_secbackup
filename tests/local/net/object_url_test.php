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

use tool_secbackup\local\trust\developer_settings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\object_url::class)]
/**
 * Tests for the pre-signed URLs of the storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\object_url
 */
final class object_url_test extends \advanced_testcase {
    /** @var string Query of a pre-signed URL. */
    private const QUERY = '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
        . '&X-Amz-Credential=AKIDEXAMPLE%2F20261018%2Fap-northeast-1%2Fs3%2Faws4_request&X-Amz-Signature=0123456789abcdef';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * URLs of the storage region in the two forms.
     *
     * @return array
     */
    public static function accepted_provider(): array {
        return [
            'path style' => [
                'https://s3.ap-northeast-1.amazonaws.com/bucket/sites/x/objects/y' . self::QUERY,
                's3.ap-northeast-1.amazonaws.com',
            ],
            'virtual host style' => [
                'https://bucket-1.s3.ap-northeast-1.amazonaws.com/sites/x/objects/y' . self::QUERY,
                'bucket-1.s3.ap-northeast-1.amazonaws.com',
            ],
            'a bucket with dots' => [
                'https://my.bucket.name.s3.ap-northeast-1.amazonaws.com/k?a=b',
                'my.bucket.name.s3.ap-northeast-1.amazonaws.com',
            ],
            'a bucket of three characters' => [
                'https://a-b.s3.ap-northeast-1.amazonaws.com/k?a=b',
                'a-b.s3.ap-northeast-1.amazonaws.com',
            ],
            'a query with a question mark' => [
                'https://s3.ap-northeast-1.amazonaws.com/b/k?a=b?c',
                's3.ap-northeast-1.amazonaws.com',
            ],
            'a key that starts with dots' => [
                'https://s3.ap-northeast-1.amazonaws.com/b/...x?a=b',
                's3.ap-northeast-1.amazonaws.com',
            ],
        ];
    }

    /**
     * The two forms of the storage region are accepted.
     *
     * @dataProvider accepted_provider
     * @param string $url The URL.
     * @param string $host Its host.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('accepted_provider')]
    public function test_the_two_forms_of_the_storage_region_are_accepted(string $url, string $host): void {
        $parsed = object_url::parse($url, developer_settings::from_value(null));
        $this->assertSame($url, $parsed->url());
        $this->assertSame($host, $parsed->host());
        $this->assertSame(443, $parsed->port());
        $this->assertSame('https', $parsed->scheme());
    }

    public function test_the_region_is_the_one_of_the_current_list_of_ranges(): void {
        $this->assertSame('ap-northeast-1', aws_ranges::current()->s3_region());
        $this->expectException(transport_exception::class);
        object_url::parse('https://s3.us-east-1.amazonaws.com/b/k?a=b', developer_settings::from_value(null));
    }

    public function test_the_region_of_a_stored_list_decides(): void {
        $list = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/data/aws-ranges.json'), true);
        $list['s3_region'] = 'us-east-1';
        set_config('awsranges', json_encode($list, JSON_UNESCAPED_SLASHES), 'tool_secbackup');
        set_config('awsrangesseq', '1', 'tool_secbackup');
        set_config('awsrangesendpoint', (string) get_config('tool_secbackup', 'endpoint'), 'tool_secbackup');
        $this->assertSame('us-east-1', aws_ranges::current()->s3_region());
        $url = 'https://s3.us-east-1.amazonaws.com/b/k?a=b';
        $this->assertSame($url, object_url::parse($url, developer_settings::from_value(null))->url());
    }

    /**
     * URLs that are refused with production settings.
     *
     * @return array
     */
    public static function refused_provider(): array {
        $good = 'https://s3.ap-northeast-1.amazonaws.com/b/k?a=b';
        return [
            'http' => ['http://s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'a look-alike host' => ['https://s3.ap-northeast-1.amazonaws.com.example.test/b/k?a=b'],
            'a look-alike with the name first' => ['https://amazonaws.com.example.test/b/k?a=b'],
            'a look-alike with a hyphen' => ['https://xs3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'an explicit port 443' => ['https://s3.ap-northeast-1.amazonaws.com:443/b/k?a=b'],
            'another port' => ['https://s3.ap-northeast-1.amazonaws.com:8443/b/k?a=b'],
            'a user name' => ['https://user@s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'a user name that names a host' => ['https://s3.ap-northeast-1.amazonaws.com@evil.example.test/b/k?a=b'],
            'a user name with a password' => ['https://user:pass@s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'another region' => ['https://s3.us-east-1.amazonaws.com/b/k?a=b'],
            'another region in the bucket form' => ['https://b-1.s3.eu-west-1.amazonaws.com/k?a=b'],
            'the region without the s3 label' => ['https://ap-northeast-1.amazonaws.com/b/k?a=b'],
            'the global host' => ['https://s3.amazonaws.com/b/k?a=b'],
            'a dual stack host' => ['https://s3.dualstack.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'an IPv4 address' => ['https://52.95.156.1/b/k?a=b'],
            'an IPv4 address with the region name' => ['https://3.5.152.1.s3.ap-northeast-1.amazonaws.com.1/b/k?a=b'],
            'an IPv6 address' => ['https://[2001:db8::1]/b/k?a=b'],
            'upper case in the host' => ['https://S3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'upper case in the scheme' => ['HTTPS://s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'a bucket of two characters' => ['https://ab.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket of 64 characters' => ['https://' . str_repeat('a', 64) . '.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket that starts with a hyphen' => ['https://-ab.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket that ends with a hyphen' => ['https://ab-.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket with two dots' => ['https://a..b.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket without the dot before the region host' => ['https://abcds3.ap-northeast-1.amazonaws.com/k?a=b'],
            'a bucket that starts with a dot' => ['https://.abc.s3.ap-northeast-1.amazonaws.com/k?a=b'],
            'no query' => ['https://s3.ap-northeast-1.amazonaws.com/b/k'],
            'an empty query' => ['https://s3.ap-northeast-1.amazonaws.com/b/k?'],
            'a fragment' => ['https://s3.ap-northeast-1.amazonaws.com/b/k?a=b#x'],
            'a fragment before the query' => ['https://s3.ap-northeast-1.amazonaws.com/b/k#x?a=b'],
            'no path' => ['https://s3.ap-northeast-1.amazonaws.com?a=b'],
            'only a slash' => ['https://s3.ap-northeast-1.amazonaws.com/?a=b'],
            'a backslash in the path' => ['https://s3.ap-northeast-1.amazonaws.com/b\\k?a=b'],
            'a backslash in the query' => ['https://s3.ap-northeast-1.amazonaws.com/b/k?a=\\b'],
            'a space' => ['https://s3.ap-northeast-1.amazonaws.com/b/k ?a=b'],
            'a space before the URL' => [' ' . $good],
            'a line feed at the end' => [$good . "\n"],
            'a line feed in the query' => ["https://s3.ap-northeast-1.amazonaws.com/b/k?a=b\nHost: x"],
            'a carriage return' => ["https://s3.ap-northeast-1.amazonaws.com/b/k\r?a=b"],
            'a tab' => ["https://s3.ap-northeast-1.amazonaws.com/b/k?a=\tb"],
            'a character that is not ASCII' => ["https://s3.ap-northeast-1.amazonaws.com/b/\u{3042}?a=b"],
            'a dot segment' => ['https://s3.ap-northeast-1.amazonaws.com/b/./k?a=b'],
            'a dot dot segment' => ['https://s3.ap-northeast-1.amazonaws.com/b/../k?a=b'],
            'a dot dot segment at the end' => ['https://s3.ap-northeast-1.amazonaws.com/b/..?a=b'],
            'an encoded dot dot segment' => ['https://s3.ap-northeast-1.amazonaws.com/b/%2E%2e/k?a=b'],
            'an encoded dot segment' => ['https://s3.ap-northeast-1.amazonaws.com/b/%2e/k?a=b'],
            'a mixed dot dot segment' => ['https://s3.ap-northeast-1.amazonaws.com/b/.%2e/k?a=b'],
            'another scheme' => ['ftp://s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'no scheme' => ['s3.ap-northeast-1.amazonaws.com/b/k?a=b'],
            'an empty string' => [''],
            'a URL that is too long' => [
                $good . str_repeat('x', 8192 - strlen($good) + 1),
            ],
        ];
    }

    /**
     * Other urls are refused without the url in the exception.
     *
     * @dataProvider refused_provider
     * @param string $url The URL.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    public function test_other_urls_are_refused_without_the_url_in_the_exception(string $url): void {
        try {
            object_url::parse($url, developer_settings::from_value(null));
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('object_url_invalid', $e->errorcode);
            $this->assertNull($e->debuginfo);
            $this->assertStringNotContainsString('amazonaws', $e->getMessage());
            $this->assertStringNotContainsString('amazonaws', (string) $e->getTraceAsString());
        }
    }

    public function test_a_url_of_the_longest_size_is_accepted(): void {
        $prefix = 'https://s3.ap-northeast-1.amazonaws.com/b/k?a=';
        $url = $prefix . str_repeat('x', 8192 - strlen($prefix));
        $this->assertSame(8192, strlen($url));
        $this->assertSame($url, object_url::parse($url, developer_settings::from_value(null))->url());
    }

    public function test_the_developer_setting_allowhttp_admits_http_but_only_for_the_storage_host(): void {
        $settings = developer_settings::from_value(['allowhttp' => true]);
        $parsed = object_url::parse('http://s3.ap-northeast-1.amazonaws.com/b/k?a=b', $settings);
        $this->assertSame('http', $parsed->scheme());
        $this->assertSame(80, $parsed->port());
        $this->expectException(transport_exception::class);
        object_url::parse('http://host.docker.internal:9000/b/k?a=b', $settings);
    }

    /**
     * URLs that the developer settings admit.
     *
     * @return array
     */
    public static function developer_provider(): array {
        return [
            'a host with a port' => ['http://host.docker.internal:9000/bucket/k?a=b', 'http', 'host.docker.internal', 9000],
            'localhost' => ['http://localhost:8099/_s3/sites/x/objects/y?a=b', 'http', 'localhost', 8099],
            'a name without a dot' => ['http://fakesec/_s3/k?a=b', 'http', 'fakesec', 80],
            'an IPv4 address' => ['http://127.0.0.1:9000/b/k?a=b', 'http', '127.0.0.1', 9000],
            'https on another port' => ['https://storage.example.test:8443/b/k?a=b', 'https', 'storage.example.test', 8443],
            'the highest port' => ['http://localhost:65535/b/k?a=b', 'http', 'localhost', 65535],
            'the lowest port' => ['http://localhost:1/b/k?a=b', 'http', 'localhost', 1],
            'a host of 253 characters' => [
                'http://' . implode('.', array_fill(0, 3, str_repeat('a', 63))) . '.' . str_repeat('a', 61) . ':9000/b/k?a=b',
                'http',
                implode('.', array_fill(0, 3, str_repeat('a', 63))) . '.' . str_repeat('a', 61),
                9000,
            ],
            'a storage host with a port' => [
                'https://s3.ap-northeast-1.amazonaws.com:9000/b/k?a=b',
                'https',
                's3.ap-northeast-1.amazonaws.com',
                9000,
            ],
        ];
    }

    /**
     * The developer settings admit a private host with a port.
     *
     * @dataProvider developer_provider
     * @param string $url The URL.
     * @param string $scheme Scheme.
     * @param string $host Host.
     * @param int $port Port.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('developer_provider')]
    public function test_the_developer_settings_admit_a_private_host_with_a_port(
        string $url,
        string $scheme,
        string $host,
        int $port
    ): void {
        $settings = developer_settings::from_value(['allowhttp' => true, 'allowprivatehosts' => true]);
        $parsed = object_url::parse($url, $settings);
        $this->assertSame($url, $parsed->url());
        $this->assertSame([$scheme, $host, $port], [$parsed->scheme(), $parsed->host(), $parsed->port()]);
    }

    /**
     * URLs that the developer settings do not admit.
     *
     * @return array
     */
    public static function developer_refused_provider(): array {
        $both = ['allowhttp' => true, 'allowprivatehosts' => true];
        return [
            'http without allowhttp' => ['http://localhost:8099/b/k?a=b', ['allowprivatehosts' => true]],
            'a port of zero' => ['http://localhost:0/b/k?a=b', $both],
            'a port above 65535' => ['http://localhost:65536/b/k?a=b', $both],
            'a port that is not a number' => ['http://localhost:abc/b/k?a=b', $both],
            'a port of six digits' => ['http://localhost:123456/b/k?a=b', $both],
            'a host that curl reads as an address' => ['http://127.1:9000/b/k?a=b', $both],
            'a last label that starts with a digit' => ['http://example.1a:9000/b/k?a=b', $both],
            'two colons' => ['http://a:b:9000/b/k?a=b', $both],
            'a user name' => ['http://u@localhost:9000/b/k?a=b', $both],
            'a host with an underscore' => ['http://a_b:9000/b/k?a=b', $both],
            'a host with a hyphen at the start' => ['http://-a.test:9000/b/k?a=b', $both],
            'a host that is too long' => [
                'http://' . implode('.', array_fill(0, 5, str_repeat('a', 60))) . ':9000/b/k?a=b',
                $both,
            ],
            'a label that is too long' => [
                'http://' . str_repeat('a', 64) . ':9000/b/k?a=b',
                $both,
            ],
            'a port without private hosts' => ['http://localhost:8099/b/k?a=b', ['allowhttp' => true]],
            'skipnetworkchecks alone' => ['http://localhost:8099/b/k?a=b', ['allowhttp' => true, 'skipnetworkchecks' => true]],
        ];
    }

    /**
     * The developer settings refuse what is not a host either.
     *
     * @dataProvider developer_refused_provider
     * @param string $url The URL.
     * @param array $value The developer settings.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('developer_refused_provider')]
    public function test_the_developer_settings_refuse_what_is_not_a_host_either(string $url, array $value): void {
        $this->expectException(transport_exception::class);
        $this->expectExceptionMessage(get_string('object_url_invalid', 'tool_secbackup'));
        object_url::parse($url, developer_settings::from_value($value));
    }
}
