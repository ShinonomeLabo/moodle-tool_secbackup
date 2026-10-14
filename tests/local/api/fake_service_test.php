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

use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the fake service itself: it has to refuse the headers that the real service refuses, and it has to stand on its
 * own, so that it checks the plugin independently and can be loaded outside Moodle.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class fake_service_test extends \basic_testcase {
    /** @var string Connection URL of the fake service. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int Clock of the fake service. */
    private const NOW = 1760000000;

    /**
     * A fake service with a fixed clock, and nobody enrolled.
     *
     * @return fake_service
     */
    private static function service(): fake_service {
        return new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW]));
    }

    /**
     * Headers that have the shape of the signature headers.
     *
     * @return string[]
     */
    private static function headers(): array {
        return [
            'x-secbackup-timestamp' => (string) self::NOW,
            'x-secbackup-nonce' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'x-secbackup-signature' => envelope_builder::b64url(str_repeat("\x01", 64)),
        ];
    }

    /**
     * Sends a status request with these headers.
     *
     * @param string[] $headers Headers.
     * @return array [HTTP status, error code]
     */
    private static function status_with(array $headers): array {
        $answer = self::service()->handle('GET', 'app.example.test', '/api/lms-backup/v1/status', $headers, '');
        return [$answer[0], json_decode($answer[2], true)['error']['code']];
    }

    public function test_headers_of_the_right_shape_go_on_to_the_next_check(): void {
        // The shape is taken, so the service goes on and finds that nobody is enrolled.
        $this->assertSame([401, 'site_not_enrolled'], self::status_with(self::headers()));
    }

    /**
     * Headers in a wrong shape: the name of the header and its value.
     *
     * @return array
     */
    public static function malformed_header_provider(): array {
        $signature = self::headers()['x-secbackup-signature'];
        return [
            'timestamp with a trailing line feed' => ['x-secbackup-timestamp', self::NOW . "\n"],
            'timestamp with a leading line feed' => ['x-secbackup-timestamp', "\n" . self::NOW],
            'timestamp of 13 digits' => ['x-secbackup-timestamp', '1000000000000'],
            'empty timestamp' => ['x-secbackup-timestamp', ''],
            'nonce with a trailing line feed' => ['x-secbackup-nonce', "AAAAAAAAAAAAAAAAAAAAAA\n"],
            'nonce with a leading line feed' => ['x-secbackup-nonce', "\nAAAAAAAAAAAAAAAAAAAAAA"],
            'nonce one character short' => ['x-secbackup-nonce', 'AAAAAAAAAAAAAAAAAAAAA'],
            'nonce one character long' => ['x-secbackup-nonce', 'AAAAAAAAAAAAAAAAAAAAAAA'],
            'signature with a trailing line feed' => ['x-secbackup-signature', $signature . "\n"],
            'signature with a leading line feed' => ['x-secbackup-signature', "\n" . $signature],
            'signature with a padding character' => ['x-secbackup-signature', $signature . '=='],
            'signature of 63 bytes' => ['x-secbackup-signature', envelope_builder::b64url(str_repeat("\x01", 63))],
            'empty signature' => ['x-secbackup-signature', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_header_provider')]
    /**
     * A header in a wrong shape gives signature_required, even when only a line feed is wrong.
     *
     * @dataProvider malformed_header_provider
     * @param string $name Header name.
     * @param string $value Header value.
     */
    public function test_malformed_headers_are_refused(string $name, string $value): void {
        $this->assertSame([401, 'signature_required'], self::status_with([$name => $value] + self::headers()));
    }

    /**
     * An enrolment body that the service takes.
     *
     * @return array
     */
    private static function body(): array {
        return [
            'enrollment_code' => fake_service::CODE,
            'signing_public_key' => base64_encode(str_repeat("\x02", 32)),
            'encryption_key_fingerprint' => 'A260 B405 0FD2 8D2B 313C 9A94',
            'site' => [
                'wwwroot' => 'https://lms.example.ac.jp',
                'moodle_release' => '5.3 (Build: 20261005)',
                'moodle_version' => '2026100500.00',
                'db_family' => 'postgres',
            ],
            'plugin_version' => '2026101200',
        ];
    }

    /**
     * Sends an enrolment body with signature headers of the right shape.
     *
     * @param array $body The body.
     * @return array [HTTP status, error code]
     */
    private static function enroll_with(array $body): array {
        $answer = self::service()->handle(
            'POST',
            'app.example.test',
            '/api/lms-backup/v1/enroll',
            self::headers(),
            json_encode($body)
        );
        return [$answer[0], json_decode($answer[2], true)['error']['code']];
    }

    public function test_a_body_in_the_service_format_goes_on_to_the_signature_check(): void {
        $this->assertSame([401, 'signature_invalid'], self::enroll_with(self::body()));
    }

    /**
     * Bodies that the service refuses, each as a change to the good body.
     *
     * @return array
     */
    public static function refused_body_provider(): array {
        $site = static fn(string $key, $value): \Closure => static function (array $body) use ($key, $value): array {
            $body['site'][$key] = $value;
            return $body;
        };
        $top = static fn(string $key, $value): \Closure => static function (array $body) use ($key, $value): array {
            $body[$key] = $value;
            return $body;
        };
        return [
            'a field the service does not know' => [$top('extra', 'x')],
            'a site field the service does not know' => [$site('extra', 'x')],
            'a public key of 43 characters' => [$top('signing_public_key', substr(base64_encode(str_repeat("\x02", 32)), 1))],
            'a connection url that is not http or https' => [$site('wwwroot', 'ftp://lms.example.ac.jp')],
            'a connection url that is not a url' => [$site('wwwroot', 'lms.example.ac.jp')],
            'a connection url of 256 characters' => [$site('wwwroot', 'https://lms.example.ac.jp/' . str_repeat('a', 230))],
            'an empty release' => [$site('moodle_release', '')],
            'a release of 101 characters' => [$site('moodle_release', str_repeat('a', 101))],
            'a release with a line feed' => [$site('moodle_release', "5.3\n")],
            'a release with a zero width space' => [$site('moodle_release', "5.3\u{200B}")],
            'a version without decimals in the wrong place' => [$site('moodle_version', '2026100500.0')],
            'a version of 9 digits' => [$site('moodle_version', '202610050.00')],
            'a version with a trailing line feed' => [$site('moodle_version', "2026100500.00\n")],
            'a database that is not known' => [$site('db_family', 'mssql')],
            'a plugin version of 9 digits' => [$top('plugin_version', '202610120')],
            'a plugin version with a trailing line feed' => [$top('plugin_version', "2026101200\n")],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_body_provider')]
    /**
     * A body that breaks one of the service's rules is refused before anything else is checked.
     *
     * @dataProvider refused_body_provider
     * @param \Closure $change Change to the good body.
     */
    public function test_bodies_that_the_service_refuses(\Closure $change): void {
        $this->assertSame([422, 'invalid_request'], self::enroll_with($change(self::body())));
    }

    public function test_a_fresh_service_gives_the_names_the_tests_expect(): void {
        // The answer to an enrolment carries these two names, so that a test can register a site with other ones.
        $state = fake_service::initial_state();
        $this->assertSame(fake_service::SITE_NAME, $state['sitename']);
        $this->assertSame(fake_service::PLATFORM_NAME, $state['platformname']);
    }
}
