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

namespace tool_secbackup\local\endpoint;

use tool_secbackup\local\trust\developer_settings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\endpoint\endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\endpoint\endpoint_exception::class)]
/**
 * Tests for the connection URL.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\endpoint\endpoint
 * @covers     \tool_secbackup\local\endpoint\endpoint_exception
 */
final class endpoint_test extends \basic_testcase {
    /** @var string The connection URL in its normal form. */
    private const URL = 'https://lms-backup.example.com/api/lms-backup/v1';

    /**
     * Production settings.
     *
     * @return developer_settings
     */
    private static function production(): developer_settings {
        return developer_settings::from_value(null);
    }

    /**
     * Development settings for a local service.
     *
     * @return developer_settings
     */
    private static function development(): developer_settings {
        return developer_settings::from_value(['allowhttp' => true, 'allowprivatehosts' => true]);
    }

    /**
     * A connection URL of an exact length in bytes, in a shape that is accepted.
     *
     * @param int $length The length, at least 32.
     * @return string
     */
    private static function url_of_length(int $length): string {
        $start = 'https://lms-backup.example.com/';
        return $start . str_repeat('a', $length - strlen($start));
    }

    /**
     * A host name of an exact length in characters, made of labels of at most 63 characters.
     *
     * @param int $length The length, from 193 to 255.
     * @return string
     */
    private static function host_of_length(int $length): string {
        return implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', $length - 192)]);
    }

    /**
     * Ways of writing the same URL.
     *
     * @return array
     */
    public static function same_url_provider(): array {
        return [
            'normal' => [self::URL],
            'trailing slash' => [self::URL . '/'],
            'several trailing slashes' => [self::URL . '///'],
            'upper case host' => ['https://LMS-Backup.Example.COM/api/lms-backup/v1'],
            'upper case scheme' => ['HTTPS://lms-backup.example.com/api/lms-backup/v1'],
            'explicit port' => ['https://lms-backup.example.com:443/api/lms-backup/v1'],
            'surrounding spaces' => ["  " . self::URL . " \n"],
            'full-width spaces' => ["\u{3000}" . self::URL . "\u{3000}"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('same_url_provider')]
    /**
     * Every way of writing the connection URL gives the same normal form, and the URLs are equal.
     *
     * @dataProvider same_url_provider
     * @param string $input Typed URL.
     */
    public function test_variants_give_the_same_url(string $input): void {
        $endpoint = endpoint::parse($input, self::production());
        $this->assertSame(self::URL, $endpoint->url());
        $this->assertTrue($endpoint->equals(endpoint::parse(self::URL, self::production())));
    }

    public function test_parts(): void {
        $endpoint = endpoint::parse(self::URL, self::production());
        $this->assertSame('https', $endpoint->scheme());
        $this->assertSame('lms-backup.example.com', $endpoint->host());
        $this->assertSame(443, $endpoint->port());
        $this->assertSame('/api/lms-backup/v1', $endpoint->path());
        $this->assertSame(self::URL . '/enroll', $endpoint->join('enroll'));
        $this->assertSame('/api/lms-backup/v1/status', $endpoint->path_of('status'));
    }

    public function test_port_is_kept_in_the_url(): void {
        $settings = developer_settings::from_value(['allowhttp' => true, 'allowprivatehosts' => true]);
        $endpoint = endpoint::parse('http://LOCALHOST:8099/api/lms-backup/v1/', $settings);
        $this->assertSame('localhost', $endpoint->host());
        $this->assertSame(8099, $endpoint->port());
        $this->assertSame('http://localhost:8099/api/lms-backup/v1/status', $endpoint->join('status'));
        $this->assertSame('/api/lms-backup/v1/status', $endpoint->path_of('status'));
    }

    public function test_root_path(): void {
        $endpoint = endpoint::parse('https://backup.example.org/', self::production());
        $this->assertSame('https://backup.example.org', $endpoint->url());
        $this->assertSame('', $endpoint->path());
        $this->assertSame('/enroll', $endpoint->path_of('enroll'));
    }

    public function test_path_case_is_kept(): void {
        $endpoint = endpoint::parse('https://lms-backup.example.com/API/lms-backup/v1', self::production());
        $this->assertSame('https://lms-backup.example.com/API/lms-backup/v1', $endpoint->url());
        $this->assertFalse($endpoint->equals(endpoint::parse(self::URL, self::production())));
    }

    /**
     * Connection URLs that differ from http://localhost:8099/api in one part only.
     *
     * @return array
     */
    public static function other_url_provider(): array {
        return [
            'scheme' => ['https://localhost:8099/api'],
            'host' => ['http://fakesec:8099/api'],
            'port' => ['http://localhost:8098/api'],
            'path' => ['http://localhost:8099/other'],
            'root path' => ['http://localhost:8099'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('other_url_provider')]
    /**
     * Two connection URLs are the same only when the scheme, the host, the port and the path are all the same.
     *
     * @dataProvider other_url_provider
     * @param string $other The other URL.
     */
    public function test_urls_that_differ_in_one_part_are_not_equal(string $other): void {
        $settings = self::development();
        $endpoint = endpoint::parse('http://localhost:8099/api', $settings);
        $this->assertTrue($endpoint->equals(endpoint::parse('http://LOCALHOST:8099/api/', $settings)));
        $this->assertFalse($endpoint->equals(endpoint::parse($other, $settings)));
        $this->assertFalse(endpoint::parse($other, $settings)->equals($endpoint));
    }

    public function test_localhost_is_refused_only_as_the_last_label(): void {
        foreach (['https://localhost.example.com/api', 'https://a.notlocalhost/api'] as $url) {
            $this->assertSame($url, endpoint::parse($url, self::production())->url());
        }
    }

    public function test_join_refuses_unexpected_names(): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unexpected API path: ../admin');
        endpoint::parse(self::URL, self::production())->join('../admin');
    }

    public function test_join_refuses_a_name_that_ends_with_a_line_feed(): void {
        $this->expectException(\coding_exception::class);
        endpoint::parse(self::URL, self::production())->join("status\n");
    }

    public function test_path_of_refuses_a_name_that_ends_with_a_line_feed(): void {
        $this->expectException(\coding_exception::class);
        endpoint::parse(self::URL, self::production())->path_of("status\n");
    }

    /**
     * URLs refused in production, with the reason.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'empty' => ['', 'endpoint_empty'],
            'only spaces' => ["  \u{3000} ", 'endpoint_empty'],
            'no scheme' => ['lms-backup.example.com/api/lms-backup/v1', 'endpoint_invalid'],
            'too long' => ['https://lms-backup.example.com/' . str_repeat('a', 2100), 'endpoint_invalid'],
            'input of 2049 bytes' => [self::url_of_length(2049), 'endpoint_invalid'],
            'http' => ['http://lms-backup.example.com/api/lms-backup/v1', 'endpoint_scheme'],
            'ftp' => ['ftp://lms-backup.example.com/api', 'endpoint_scheme'],
            'user name' => ['https://user@lms-backup.example.com/api', 'endpoint_userinfo'],
            'user and password' => ['https://user:secret@lms-backup.example.com/api', 'endpoint_userinfo'],
            'other port' => ['https://lms-backup.example.com:8443/api', 'endpoint_port'],
            'empty port' => ['https://lms-backup.example.com:/api', 'endpoint_port'],
            'port with letters' => ['https://lms-backup.example.com:44a/api', 'endpoint_port'],
            'port with letters before the default port' => ['https://lms-backup.example.com:443x443/api', 'endpoint_port'],
            'query' => ['https://lms-backup.example.com/api?x=1', 'endpoint_query'],
            'empty query' => ['https://lms-backup.example.com/api?', 'endpoint_query'],
            'query without a path' => ['https://lms-backup.example.com?x=1', 'endpoint_query'],
            'fragment' => ['https://lms-backup.example.com/api#top', 'endpoint_query'],
            'fragment with a line feed' => ["https://lms-backup.example.com/api#top\nend", 'endpoint_query'],
            'ipv4 address' => ['https://203.0.113.5/api', 'endpoint_host'],
            'ipv6 address' => ['https://[2001:db8::1]/api', 'endpoint_host'],
            'localhost' => ['https://localhost/api', 'endpoint_host'],
            'name under localhost' => ['https://x.localhost/api', 'endpoint_host'],
            'deeper name under localhost' => ['https://a.b.localhost/api', 'endpoint_host'],
            'name under localhost in upper case' => ['https://X.LOCALHOST/api', 'endpoint_host'],
            'short numeric address' => ['https://127.1/api', 'endpoint_host'],
            'octal address' => ['https://0177.0.0.1/api', 'endpoint_host'],
            'decimal integer address' => ['https://2130706433/api', 'endpoint_host'],
            'hexadecimal integer address' => ['https://0x7f000001/api', 'endpoint_host'],
            'hexadecimal last label' => ['https://a.0x7f/api', 'endpoint_host'],
            'underscore' => ['https://app_yu.example.org/api', 'endpoint_host'],
            'leading hyphen' => ['https://-app.example.org/api', 'endpoint_host'],
            'space in host' => ['https://app yu.example.org/api', 'endpoint_host'],
            'non-ascii host' => ["https://\u{4F8B}.jp/api", 'endpoint_host'],
            'empty label' => ['https://lms-backup..example.com/api', 'endpoint_host'],
            'trailing dot' => ['https://lms-backup.example.com./api', 'endpoint_host'],
            'label of 64 characters' => ['https://' . str_repeat('a', 64) . '.example.com/api', 'endpoint_host'],
            'host of 254 characters' => ['https://' . self::host_of_length(254) . '/api', 'endpoint_host'],
            'dot segment' => ['https://lms-backup.example.com/api/../admin', 'endpoint_path'],
            'encoded dot segment' => ['https://lms-backup.example.com/api/%2e%2e/admin', 'endpoint_path'],
            'empty segment' => ['https://lms-backup.example.com//api', 'endpoint_path'],
            'space in path' => ['https://lms-backup.example.com/api v1', 'endpoint_path'],
            // The end of a pattern written with "$" also matches before a final line feed, so a line feed that ends the host,
            // the port or the path must not slip through. Each row gives the code of the part that carries the character.
            'line feed ending the path' => ["https://lms-backup.example.com/api\n/", 'endpoint_path'],
            'line feed after the host' => ["https://lms-backup.example.com\n/api/lms-backup/v1", 'endpoint_host'],
            'carriage return after the host' => ["https://lms-backup.example.com\r/api/lms-backup/v1", 'endpoint_host'],
            'tab after the host' => ["https://lms-backup.example.com\t/api/lms-backup/v1", 'endpoint_host'],
            'line feed before the port' => ["https://lms-backup.example.com\n:443/api/lms-backup/v1", 'endpoint_host'],
            'carriage return before the port' => ["https://lms-backup.example.com\r:443/api/lms-backup/v1", 'endpoint_host'],
            'tab before the port' => ["https://lms-backup.example.com\t:443/api/lms-backup/v1", 'endpoint_host'],
            'line feed after the port' => ["https://lms-backup.example.com:443\n/api/lms-backup/v1", 'endpoint_port'],
            'carriage return after the port' => ["https://lms-backup.example.com:443\r/api/lms-backup/v1", 'endpoint_port'],
            'tab after the port' => ["https://lms-backup.example.com:443\t/api/lms-backup/v1", 'endpoint_port'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    /**
     * A URL that does not have the one accepted shape is refused with the reason for it.
     *
     * @dataProvider refused_provider
     * @param string $input Typed URL.
     * @param string $code Expected error code.
     */
    public function test_refused_in_production(string $input, string $code): void {
        try {
            endpoint::parse($input, self::production());
            $this->fail('No exception');
        } catch (endpoint_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertTrue(get_string_manager()->string_exists($e->errorcode, 'tool_secbackup'), $e->errorcode);
        }
    }

    public function test_the_error_codes_are_language_strings(): void {
        $codes = ['endpoint_empty', 'endpoint_host', 'endpoint_invalid', 'endpoint_path', 'endpoint_port', 'endpoint_query',
            'endpoint_scheme', 'endpoint_userinfo'];
        foreach ($codes as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }

    public function test_the_longest_accepted_values(): void {
        $settings = self::production();
        $host = self::host_of_length(253);
        $this->assertSame(253, strlen($host));
        $this->assertSame('https://' . $host, endpoint::parse('https://' . $host, $settings)->url());
        $label = 'https://' . str_repeat('a', 63) . '.example.com/api';
        $this->assertSame($label, endpoint::parse($label, $settings)->url());
        $url = self::url_of_length(2048);
        $this->assertSame(2048, strlen($url));
        $this->assertSame($url, endpoint::parse($url, $settings)->url());
    }

    public function test_text_that_is_not_utf8_is_invalid_and_not_empty(): void {
        // The inputs are made here and shown in hexadecimal, so that a failure does not print bytes that are not text.
        $inputs = ["https://lms-backup.example.com/api\xff", "https://lms-backup.\xe3.example.com/api", "\xff"];
        foreach ($inputs as $input) {
            try {
                endpoint::parse($input, self::production());
                $this->fail('No exception for ' . bin2hex($input));
            } catch (endpoint_exception $e) {
                $this->assertSame('endpoint_invalid', $e->errorcode, bin2hex($input));
            }
        }
    }

    /**
     * URLs that only the developer settings accept.
     *
     * @return array
     */
    public static function development_provider(): array {
        return [
            'fake service container' => ['http://fakesec/api/lms-backup/v1', 'http://fakesec/api/lms-backup/v1', 80],
            'docker host' => ['http://host.docker.internal/api/lms-backup/v1/',
                'http://host.docker.internal/api/lms-backup/v1', 80],
            'loopback address' => ['http://127.0.0.1:80/api', 'http://127.0.0.1/api', 80],
            'https localhost' => ['https://localhost/api', 'https://localhost/api', 443],
            'fake service port' => ['http://localhost:8099/api/lms-backup/v1', 'http://localhost:8099/api/lms-backup/v1', 8099],
            'name under localhost' => ['http://fake.localhost:8099/api', 'http://fake.localhost:8099/api', 8099],
            'the lowest port' => ['http://localhost:1/api', 'http://localhost:1/api', 1],
            'the highest port' => ['http://localhost:65535/api', 'http://localhost:65535/api', 65535],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('development_provider')]
    /**
     * The developer settings accept a local service, and a port other than the default one stays in the URL.
     *
     * @dataProvider development_provider
     * @param string $input Typed URL.
     * @param string $url Expected normal form.
     * @param int $port Expected port.
     */
    public function test_development_settings_accept_local_services(string $input, string $url, int $port): void {
        $endpoint = endpoint::parse($input, self::development());
        $this->assertSame($url, $endpoint->url());
        $this->assertSame($port, $endpoint->port());
    }

    public function test_development_settings_keep_the_other_rules(): void {
        $refused = ['http://localhost:0/api' => 'endpoint_port', 'http://localhost:65536/api' => 'endpoint_port',
            'http://[::1]/api' => 'endpoint_host', 'http://127.1/api' => 'endpoint_host',
            'http://fakesec/api?x' => 'endpoint_query',
            'http://2130706433/api' => 'endpoint_host', 'http://0x7f000001/api' => 'endpoint_host',
            "http://localhost\n:8099/api" => 'endpoint_host', "http://localhost:8099\n/api" => 'endpoint_port'];
        foreach ($refused as $input => $code) {
            try {
                endpoint::parse($input, self::development());
                $this->fail('No exception for ' . $input);
            } catch (endpoint_exception $e) {
                $this->assertSame($code, $e->errorcode, $input);
            }
        }
    }

    public function test_allowhttp_alone_does_not_allow_private_hosts_or_ports(): void {
        $settings = developer_settings::from_value(['allowhttp' => true]);
        $accepted = endpoint::parse('http://lms-backup.example.com/api', $settings);
        $this->assertSame('http://lms-backup.example.com/api', $accepted->url());
        $refused = ['http://fakesec/api' => 'endpoint_host', 'http://x.localhost/api' => 'endpoint_host',
            'http://lms-backup.example.com:8080/api' => 'endpoint_port'];
        foreach ($refused as $input => $code) {
            try {
                endpoint::parse($input, $settings);
                $this->fail('No exception for ' . $input);
            } catch (endpoint_exception $e) {
                $this->assertSame($code, $e->errorcode, $input);
            }
        }
    }

    public function test_allowprivatehosts_alone_does_not_allow_http(): void {
        $settings = developer_settings::from_value(['allowprivatehosts' => true]);
        $this->assertSame('https://fakesec:8443/api', endpoint::parse('https://fakesec:8443/api', $settings)->url());
        try {
            endpoint::parse('http://fakesec/api', $settings);
            $this->fail('No exception');
        } catch (endpoint_exception $e) {
            $this->assertSame('endpoint_scheme', $e->errorcode);
        }
    }
}
