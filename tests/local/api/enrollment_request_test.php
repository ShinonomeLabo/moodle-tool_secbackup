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

namespace tool_secbackup\local\api;

use tool_secbackup\local\crypto\signing_keypair;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\enrollment_request::class)]
/**
 * Tests for the enrolment request body.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\enrollment_request
 */
final class enrollment_request_test extends \advanced_testcase {
    /** @var string A registration code. */
    private const CODE = 'SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE';

    public function test_body_has_the_service_format(): void {
        $pair = signing_keypair::generate();
        $site = ['wwwroot' => 'https://lms.example.ac.jp', 'moodle_release' => '5.3 (Build: 20261005)',
            'moodle_version' => '2026100500.00', 'db_family' => 'postgres'];
        $request = new enrollment_request(self::CODE, str_repeat("\x01", 32), $site, '2026101200');
        $body = json_decode($request->body($pair), true);
        $this->assertSame([
            'enrollment_code' => self::CODE,
            'signing_public_key' => base64_encode($pair->public_key()),
            'encryption_key_fingerprint' => 'A260 B405 0FD2 8D2B 313C 9A94',
            'site' => $site,
            'plugin_version' => '2026101200',
        ], $body);
        $this->assertSame(44, strlen($body['signing_public_key']));
        $this->assertSame('A260B4050FD28D2B313C9A94', $request->encryption_fingerprint());
    }

    public function test_the_body_keeps_slashes_and_characters_as_they_are(): void {
        $site = ['wwwroot' => 'https://lms.example.ac.jp/moodle', 'moodle_release' => "5.3 \u{65E5}\u{672C}\u{8A9E}"];
        $request = new enrollment_request(self::CODE, str_repeat("\x01", 32), $site, '2026101200');
        $body = $request->body(signing_keypair::generate());
        $this->assertStringContainsString('"wwwroot":"https://lms.example.ac.jp/moodle"', $body);
        $this->assertStringContainsString("\"moodle_release\":\"5.3 \u{65E5}\u{672C}\u{8A9E}\"", $body);
    }

    public function test_invalid_utf8_in_the_release_is_replaced(): void {
        $request = new enrollment_request(self::CODE, str_repeat("\x01", 32), ['moodle_release' => "5.3\xff"], '2026101200');
        $body = json_decode($request->body(signing_keypair::generate()), true);
        $this->assertSame("5.3\u{FFFD}", $body['site']['moodle_release']);
    }

    public function test_for_this_site(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->version = '2026100500.1';
        $request = enrollment_request::for_this_site(self::CODE, str_repeat("\x01", 32));
        $body = json_decode($request->body(signing_keypair::generate()), true);
        $this->assertSame($CFG->wwwroot, $body['site']['wwwroot']);
        $this->assertSame($CFG->release, $body['site']['moodle_release']);
        $this->assertSame('2026100500.10', $body['site']['moodle_version']);
        $this->assertSame(enrollment_request::db_family($DB), $body['site']['db_family']);
        $this->assertSame(get_config('tool_secbackup', 'version'), $body['plugin_version']);
        // The service refuses a request with a field it does not know.
        $this->assertSame(['wwwroot', 'moodle_release', 'moodle_version', 'db_family'], array_keys($body['site']));
    }

    /**
     * Versions of Moodle as the configuration table keeps them (text), and as the service wants them.
     *
     * @return array
     */
    public static function version_provider(): array {
        return [
            'no decimals' => ['2026100500', '2026100500.00'],
            'one decimal that is a zero in the second place' => ['2026100500.1', '2026100500.10'],
            'two decimals' => ['2026100500.01', '2026100500.01'],
            'two decimals of a release' => ['2024100703.10', '2024100703.10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('version_provider')]
    /**
     * The version of Moodle always has two decimals: the service accepts 10 digits and two decimals.
     *
     * @dataProvider version_provider
     * @param string $configured The version in the configuration.
     * @param string $sent The version in the request.
     */
    public function test_the_moodle_version_has_two_decimals(string $configured, string $sent): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->version = $configured;
        $request = enrollment_request::for_this_site(self::CODE, str_repeat("\x01", 32));
        $body = json_decode($request->body(signing_keypair::generate()), true);
        $this->assertSame($sent, $body['site']['moodle_version']);
        $this->assertMatchesRegularExpression('/^\d{10}(\.\d{2})?\z/', $body['site']['moodle_version']);
    }

    public function test_the_error_for_an_unsupported_database_is_a_language_string(): void {
        $this->assertTrue(get_string_manager()->string_exists('db_unsupported', 'tool_secbackup'));
    }

    /**
     * Database drivers.
     *
     * @return array
     */
    public static function database_provider(): array {
        return [
            'mariadb' => ['mariadb', 'mysql', 'mariadb'],
            'mysql' => ['mysql', 'mysql', 'mysql'],
            'aurora mysql' => ['aurora', 'mysql', 'mysql'],
            'postgres' => ['postgres', 'postgres', 'postgres'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('database_provider')]
    /**
     * The database family is named as the service names it.
     *
     * @dataProvider database_provider
     * @param string $vendor Database vendor.
     * @param string $family Database family.
     * @param string $expected Value sent to the service.
     */
    public function test_db_family(string $vendor, string $family, string $expected): void {
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_dbvendor')->willReturn($vendor);
        $db->method('get_dbfamily')->willReturn($family);
        $this->assertSame($expected, enrollment_request::db_family($db));
    }

    public function test_other_databases_are_not_supported(): void {
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_dbvendor')->willReturn('mssql');
        $db->method('get_dbfamily')->willReturn('mssql');
        try {
            enrollment_request::db_family($db);
            $this->fail('No exception');
        } catch (\tool_secbackup\local\plugin_exception $e) {
            $this->assertSame('db_unsupported', $e->errorcode);
        }
    }

    public function test_debug_output_hides_the_code(): void {
        $request = new enrollment_request(
            self::CODE,
            str_repeat("\x01", 32),
            ['wwwroot' => 'https://lms.example.ac.jp'],
            '2026101200'
        );
        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString('AAAA', $dump);
        $this->assertStringContainsString('lms.example.ac.jp', $dump);
        $this->assertStringContainsString('2026101200', $dump);
    }

    public function test_the_code_and_the_key_pair_parameters_are_marked_sensitive(): void {
        $marked = [];
        foreach (['__construct', 'for_this_site', 'body'] as $name) {
            foreach ((new \ReflectionMethod(enrollment_request::class, $name))->getParameters() as $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $marked[$name][] = $parameter->getName();
                }
            }
        }
        // The registration code is still valid when a request is built, and the key pair holds the secret key.
        $this->assertSame(['__construct' => ['code'], 'for_this_site' => ['code'], 'body' => ['pair']], $marked);
    }
}
