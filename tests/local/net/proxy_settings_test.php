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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\proxy_settings::class)]
/**
 * Tests for the proxy settings.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\proxy_settings
 */
final class proxy_settings_test extends \advanced_testcase {
    /** @var string An API URL. */
    private const URL = 'https://lms-backup.example.com/api/lms-backup/v1/status';

    public function test_no_proxy(): void {
        $this->assertSame('', proxy_settings::for_url(self::URL));
        $this->assertNull(proxy_settings::describe(self::URL));
    }

    public function test_http_proxy_with_port(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $this->assertSame('http://proxy.example.ac.jp:3128', proxy_settings::for_url(self::URL));
        $this->assertSame('HTTP proxy.example.ac.jp:3128', proxy_settings::describe(self::URL));
    }

    public function test_the_port_is_written_as_a_number(): void {
        global $CFG;
        $this->resetAfterTest();
        // The file config.php can give the port as any text, such as one with a blank that came with a copy and paste.
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128 ';
        $this->assertSame('http://proxy.example.ac.jp:3128', proxy_settings::for_url(self::URL));
        $this->assertSame('HTTP proxy.example.ac.jp:3128', proxy_settings::describe(self::URL));
    }

    public function test_credentials_are_separated_and_encoded(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '8080';
        $CFG->proxyuser = 'user@school';
        $CFG->proxypassword = 'p:ss/w@rd';
        $this->assertSame('http://user%40school:p%3Ass%2Fw%40rd@proxy.example.ac.jp:8080', proxy_settings::for_url(self::URL));
        $this->assertStringNotContainsString('rd', proxy_settings::describe(self::URL));
        $this->assertSame('HTTP proxy.example.ac.jp:8080', proxy_settings::describe(self::URL));
    }

    public function test_a_user_name_without_a_password_is_not_sent(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $CFG->proxyuser = 'user';
        $this->assertSame('http://proxy.example.ac.jp:3128', proxy_settings::for_url(self::URL));
    }

    public function test_socks5(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'socks.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
        $this->assertSame('socks5://socks.example.ac.jp:1080', proxy_settings::for_url(self::URL));
        $this->assertSame('SOCKS5 socks.example.ac.jp:1080', proxy_settings::describe(self::URL));
    }

    public function test_proxy_without_port(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $this->assertSame('http://proxy.example.ac.jp', proxy_settings::for_url(self::URL));
    }

    public function test_without_a_proxy_nobody_resolves_the_name_for_us(): void {
        $this->assertFalse(proxy_settings::resolves_names(self::URL));
    }

    public function test_an_http_proxy_resolves_the_name(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $this->assertTrue(proxy_settings::resolves_names(self::URL));
    }

    public function test_a_socks5_proxy_does_not_resolve_the_name(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'socks.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
        // The proxy is in use, but curl looks the name up on this server before it talks to the proxy.
        $this->assertNotSame('', proxy_settings::for_url(self::URL));
        $this->assertFalse(proxy_settings::resolves_names(self::URL));
    }

    public function test_a_host_on_the_bypass_list_has_no_proxy_to_resolve_the_name(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $CFG->proxybypass = '*.example.com';
        $this->assertFalse(proxy_settings::resolves_names(self::URL));
        $this->assertTrue(proxy_settings::resolves_names('https://8.8.8.8/resolve'));
    }

    public function test_bypass_list_means_direct(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        // Moodle matches host names exactly unless they start with "*.", and does not trim the entries.
        $CFG->proxybypass = 'localhost,*.example.com,1.1.1.1';
        $this->assertSame('', proxy_settings::for_url(self::URL));
        $this->assertSame('', proxy_settings::for_url('https://1.1.1.1/dns-query'));
        $this->assertSame('http://proxy.example.ac.jp:3128', proxy_settings::for_url('https://8.8.8.8/resolve'));
    }
}
