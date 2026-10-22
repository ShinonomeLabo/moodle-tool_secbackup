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

namespace tool_secbackup\local\diagnostics;

use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\system_roots;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\diagnostics\selinux::class)]
/**
 * Tests for telling whether SELinux denied something to this process.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\diagnostics\selinux
 */
final class selinux_test extends \advanced_testcase {
    /** @var string The context of a process of the web server. */
    private const WEB_SERVER = "system_u:system_r:httpd_t:s0\0";

    /**
     * A reader of /proc and /sys with these files.
     *
     * @param string|null $enforce Content of /sys/fs/selinux/enforce; null for none.
     * @param string|null $context Content of /proc/self/attr/current; null for none.
     * @return system_files
     */
    private static function files(?string $enforce, ?string $context): system_files {
        $contents = array_filter(
            ['/sys/fs/selinux/enforce' => $enforce, '/proc/self/attr/current' => $context],
            static fn(?string $content): bool => $content !== null
        );
        return new system_files(system_roots::with_files($contents));
    }

    public function test_selinux_is_enforcing_only_when_its_file_says_1(): void {
        $this->assertTrue(selinux::enforcing(self::files("1\n", null)));
        $this->assertFalse(selinux::enforcing(self::files("0\n", null)));
        $this->assertFalse(selinux::enforcing(self::files(null, null)));
    }

    /**
     * Contexts of the process and the type that they give.
     *
     * @return array[]
     */
    public static function contexts(): array {
        return [
            'the web server' => [self::WEB_SERVER, 'httpd_t'],
            'a line feed after it' => ["unconfined_u:unconfined_r:unconfined_t:s0-s0:c0.c1023\n", 'unconfined_t'],
            'no type' => ['system_u:system_r', null],
            'an empty type' => ['system_u:system_r::s0', null],
            'an empty file' => ['', null],
            'no file' => [null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contexts')]
    /**
     * The type is the third part of the context of the process.
     *
     * @dataProvider contexts
     * @param string|null $context Content of /proc/self/attr/current.
     * @param string|null $type The type.
     */
    public function test_the_type_of_the_process_is_read_from_its_context(?string $context, ?string $type): void {
        $this->assertSame($type, selinux::process_type(self::files(null, $context)));
    }

    public function test_only_the_confined_domains_of_the_web_server_are_confined(): void {
        $this->assertTrue(selinux::confined(self::files(null, self::WEB_SERVER)));
        $this->assertTrue(selinux::confined(self::files(null, "system_u:system_r:httpd_sys_script_t:s0\0")));
        foreach (
            ["system_u:system_r:httpd_unconfined_script_t:s0\0", "system_u:system_r:unconfined_t:s0\0",
                "system_u:system_r:system_cronjob_t:s0\0", null] as $context
        ) {
            $this->assertFalse(selinux::confined(self::files(null, $context)), (string) $context);
        }
    }

    public function test_a_connection_is_denied_when_the_loopback_address_answers_eacces(): void {
        $ports = [];
        $probe = static function (int $port) use (&$ports): int {
            $ports[] = $port;
            return 13;
        };
        $this->assertTrue(selinux::connection_denied($probe));
        // A refused connection and a connection that was made are not denials.
        $this->assertFalse(selinux::connection_denied(static fn(int $port): int => 111));
        $this->assertFalse(selinux::connection_denied(static fn(int $port): int => 0));
        $this->assertSame([443], $ports);
    }

    public function test_a_probe_that_cannot_run_proves_no_denial(): void {
        // With stream_socket_client() in disable_functions, PHP throws an Error when it is called.
        $disabled = static function (int $port): int {
            throw new \Error('Call to undefined function stream_socket_client()');
        };
        $this->assertFalse(selinux::connection_denied($disabled));
        $connect = new transport_exception('transport_connect', transport_exception::CONNECT_FAILED);
        $files = self::files("1\n", self::WEB_SERVER);
        $this->assertNull(selinux::denial($connect, $files, '/data/tool', $disabled));
    }

    public function test_a_connection_to_the_loopback_address_gives_the_error_number_of_the_system(): void {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $port = (int) substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1);
        try {
            $this->assertSame(0, selinux::connect_loopback($port));
        } finally {
            fclose($server);
        }
        // The port is closed now: the connection is refused, which is not a denial.
        $refused = selinux::connect_loopback($port);
        $this->assertNotSame(0, $refused);
        $this->assertNotSame(13, $refused);
    }

    public function test_the_nearest_folder_that_exists_is_the_one_that_must_be_writable(): void {
        $base = make_request_directory();
        $asked = [];
        $writable = static function (string $folder) use (&$asked): bool {
            $asked[] = $folder;
            return false;
        };
        $this->assertTrue(selinux::folder_denied($base . '/tool_secbackup/run-1', $writable));
        $this->assertSame([$base], $asked);
        // The check of the system by default: a folder of the tests can be written to.
        $this->assertFalse(selinux::folder_denied($base . '/tool_secbackup'));
        $this->assertFalse(selinux::folder_denied($base));
    }

    /**
     * Errors and what SELinux denied, on an enforcing system in the web server.
     *
     * @return array[]
     */
    public static function errors(): array {
        $connect = new transport_exception('transport_connect', transport_exception::CONNECT_FAILED);
        $lookups = new transport_exception('dns_doh_unavailable', transport_exception::CONNECT_FAILED);
        $temp = new plugin_exception('temp_write_failed', null, 'journal_write');
        $short = new plugin_exception('temp_write_failed', null, 'journal_short');
        $entries = new plugin_exception('temp_write_failed', null, 'entries_short');
        return [
            'a connection that is denied' => [$connect, 13, false, 'network'],
            'lookups that are denied' => [$lookups, 13, false, 'network'],
            'a connection that is refused' => [$connect, 111, false, null],
            'a timeout' => [new transport_exception('transport_connect', 'cURL error 28'), 13, false, null],
            'a temporary file in a folder that cannot be written' => [$temp, 0, false, 'tempfolder'],
            'a temporary file in a folder that can be written' => [$temp, 0, true, null],
            'a journal shorter than committed' => [$short, 0, false, null],
            'entries shorter than committed' => [$entries, 0, false, null],
            'another error' => [new plugin_exception('disk_low'), 13, false, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    /**
     * What SELinux denied, by the error and by what the system answers.
     *
     * @dataProvider errors
     * @param plugin_exception $e The error.
     * @param int $probe What the connection to the loopback address gives.
     * @param bool $writable Whether the temporary folder can be written to.
     * @param string|null $expected The denial.
     */
    public function test_the_denial_of_an_error(plugin_exception $e, int $probe, bool $writable, ?string $expected): void {
        $probefn = static fn(int $port): int => $probe;
        $writablefn = static fn(string $folder): bool => $writable;
        $files = self::files("1\n", self::WEB_SERVER);
        $this->assertSame($expected, selinux::denial($e, $files, '/data/tool', $probefn, $writablefn));
        // Never while SELinux is permissive, and never outside the web server.
        $this->assertNull(selinux::denial($e, self::files("0\n", self::WEB_SERVER), '/data/tool', $probefn, $writablefn));
        $cron = self::files("1\n", "system_u:system_r:system_cronjob_t:s0\0");
        $this->assertNull(selinux::denial($e, $cron, '/data/tool', $probefn, $writablefn));
    }
}
