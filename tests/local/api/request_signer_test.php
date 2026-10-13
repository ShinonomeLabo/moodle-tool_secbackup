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
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\request_signer::class)]
/**
 * Tests for the request signatures (same text as the service's verifier).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\request_signer
 */
final class request_signer_test extends \basic_testcase {
    /** @var string Secret key of the seed 0x07 x 32, standard base64. */
    private const SECRET = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwfqSmxj4pxSCr71UHsTLsX5lUd2rr6+e5JCHuppFEbSLA==';

    /** @var string A site ID. */
    private const SITE = '11111111-2222-4333-8444-555555555555';

    /** @var string The body of an enrolment request: it holds a registration code. */
    private const BODY = '{"enrollment_code":"SEC-BK-7QF2-MXA9-PDTW-4HJC-8NR5"}';

    /**
     * The test key pair.
     *
     * @return signing_keypair
     */
    private static function pair(): signing_keypair {
        return new signing_keypair(base64_decode(self::SECRET));
    }

    /**
     * The test connection URL.
     *
     * @return endpoint
     */
    private static function endpoint(): endpoint {
        return endpoint::parse('https://app.example.test/api/lms-backup/v1', developer_settings::from_value(null));
    }

    public function test_canonical_text_matches_the_service(): void {
        $this->assertSame(
            "SECBACKUP-V1\nGET\napp.example.test\n/api/lms-backup/v1/status\n\n1760000000\nAAAAAAAAAAAAAAAAAAAAAA\n"
                . 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            request_signer::canonical(
                'get',
                'app.example.test',
                '/api/lms-backup/v1/status',
                '',
                '1760000000',
                'AAAAAAAAAAAAAAAAAAAAAA',
                ''
            )
        );
        $this->assertSame(
            "SECBACKUP-V1\nPOST\napp.example.test\n/api/lms-backup/v1/enroll\n\n1760000123\nMDEyMzQ1Njc4OWFiY2RlZg\n"
                . 'f61e16f4a84a66309e62682db55ea3430b1923de2f1352e53984555cd7166d62',
            request_signer::canonical(
                'POST',
                'App.Example.TEST',
                '/api/lms-backup/v1/enroll',
                '',
                '1760000123',
                'MDEyMzQ1Njc4OWFiY2RlZg',
                '{"enrollment_code":"SEC-BK-7QF2-MXA9-PDTW-4HJC-8NR5"}'
            )
        );
    }

    public function test_empty_path_is_signed_as_slash(): void {
        $text = request_signer::canonical('GET', 'host.example', '', '', '1', 'n', '');
        $this->assertStringContainsString("\nhost.example\n/\n\n", $text);
    }

    public function test_status_headers(): void {
        $headers = request_signer::headers(
            self::pair(),
            'GET',
            self::endpoint(),
            'status',
            '',
            self::SITE,
            1760000000,
            'AAAAAAAAAAAAAAAAAAAAAA'
        );
        $this->assertSame([
            'X-SecBackup-Timestamp' => '1760000000',
            'X-SecBackup-Nonce' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'X-SecBackup-Signature' => '_r5b9Q-9sFDVj04Ts_nzU6ExpRcmmXOWOe13jKXNaXbuuELHDttWEpE8J8qzhgMuHuPSTz1Z6Y6McFnE5sUYBg',
            'Accept' => 'application/json',
            'X-SecBackup-Site' => self::SITE,
        ], $headers);
    }

    public function test_enroll_headers_have_no_site_and_a_content_type(): void {
        $body = '{"enrollment_code":"SEC-BK-7QF2-MXA9-PDTW-4HJC-8NR5"}';
        $headers = request_signer::headers(
            self::pair(),
            'POST',
            self::endpoint(),
            'enroll',
            $body,
            null,
            1760000123,
            'MDEyMzQ1Njc4OWFiY2RlZg'
        );
        $this->assertSame(
            'ohVQ-6Q8vjSP97A5PiqIZOGJ1XgY4DT5Ttt-v2uYIewsonJ6Yx1GKkQ1yG9lTCBjqaLkgYoRirGpIFQRDcOaDw',
            $headers['X-SecBackup-Signature']
        );
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertArrayNotHasKey('X-SecBackup-Site', $headers);
    }

    public function test_new_nonces_are_22_url_safe_characters_and_differ(): void {
        $first = request_signer::new_nonce();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}\z/', $first);
        $this->assertNotSame($first, request_signer::new_nonce());
    }

    public function test_wrong_nonce_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        request_signer::headers(self::pair(), 'GET', self::endpoint(), 'status', '', self::SITE, 1, 'short');
    }

    public function test_wrong_site_id_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        request_signer::headers(self::pair(), 'GET', self::endpoint(), 'status', '', 'not-a-uuid', 1, 'AAAAAAAAAAAAAAAAAAAAAA');
    }

    /**
     * Nonces and site IDs in a wrong shape: one value is wrong in each row, the other is good.
     *
     * Both values become HTTP header values, so a line feed before or after a good value must not get through.
     *
     * @return array
     */
    public static function malformed_values_provider(): array {
        $nonce = 'AAAAAAAAAAAAAAAAAAAAAA';
        $nonceerror = 'A nonce has';
        $siteerror = 'A site ID is';
        return [
            'nonce with a trailing line feed' => [$nonce . "\n", self::SITE, $nonceerror],
            'nonce with a leading line feed' => ["\n" . $nonce, self::SITE, $nonceerror],
            'nonce one character long' => [$nonce . 'A', self::SITE, $nonceerror],
            'nonce one character short' => [substr($nonce, 1), self::SITE, $nonceerror],
            'site ID with a trailing line feed' => [$nonce, self::SITE . "\n", $siteerror],
            'site ID with a leading line feed' => [$nonce, "\n" . self::SITE, $siteerror],
            'upper-case site ID' => [$nonce, '1111111A-2222-4333-8444-55555555555A', $siteerror],
            'empty site ID' => [$nonce, '', $siteerror],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_values_provider')]
    /**
     * A nonce or a site ID in a wrong shape is a coding error, even when only a line feed is wrong.
     *
     * @dataProvider malformed_values_provider
     * @param string $nonce The nonce.
     * @param string $siteid The site ID.
     * @param string $message Start of the message that names the wrong value.
     */
    public function test_malformed_values_are_coding_errors(string $nonce, string $siteid, string $message): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage($message);
        request_signer::headers(self::pair(), 'GET', self::endpoint(), 'status', '', $siteid, 1, $nonce);
    }

    public function test_every_url_safe_character_and_hex_letter_is_accepted(): void {
        $headers = request_signer::headers(
            self::pair(),
            'GET',
            self::endpoint(),
            'status',
            '',
            '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
            1,
            'Az09_-Az09_-Az09_-Az09'
        );
        $this->assertSame('Az09_-Az09_-Az09_-Az09', $headers['X-SecBackup-Nonce']);
        $this->assertSame('0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', $headers['X-SecBackup-Site']);
    }

    /**
     * Methods and times in a wrong shape: one value is wrong in each row, the other is good.
     *
     * The method is a line of the signed text, so a line feed in it would let two different requests read the same.
     *
     * @return array
     */
    public static function malformed_method_or_time_provider(): array {
        return [
            'a method with a line feed and more' => ["GET\nX", 1760000000, 'A method is'],
            'a method with a trailing line feed' => ["POST\n", 1760000000, 'A method is'],
            'a lower-case method' => ['get', 1760000000, 'A method is'],
            'a method that is neither GET nor POST' => ['PUT', 1760000000, 'A method is'],
            'an empty method' => ['', 1760000000, 'A method is'],
            'a negative time' => ['GET', -1, 'A timestamp is'],
            'a time of 13 digits' => ['GET', 1000000000000, 'A timestamp is'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_method_or_time_provider')]
    /**
     * A method or a time in a wrong shape is a coding error, even when only a line feed is wrong.
     *
     * @dataProvider malformed_method_or_time_provider
     * @param string $method The method.
     * @param int $timestamp The time.
     * @param string $message Start of the message that names the wrong value.
     */
    public function test_a_wrong_method_or_time_is_a_coding_error(string $method, int $timestamp, string $message): void {
        $nonce = 'AAAAAAAAAAAAAAAAAAAAAA';
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage($message);
        request_signer::headers(self::pair(), $method, self::endpoint(), 'status', '', self::SITE, $timestamp, $nonce);
    }

    public function test_both_methods_and_the_bounds_of_the_time_are_accepted(): void {
        $nonce = 'AAAAAAAAAAAAAAAAAAAAAA';
        $first = request_signer::headers(self::pair(), 'POST', self::endpoint(), 'status', '', null, 0, $nonce);
        $last = request_signer::headers(self::pair(), 'GET', self::endpoint(), 'status', '', null, 999999999999, $nonce);
        $this->assertSame('0', $first['X-SecBackup-Timestamp']);
        $this->assertSame('999999999999', $last['X-SecBackup-Timestamp']);
    }

    public function test_the_key_pair_and_the_body_parameters_are_marked_sensitive(): void {
        $parameters = (new \ReflectionMethod(request_signer::class, 'headers'))->getParameters();
        $marked = [];
        foreach ($parameters as $parameter) {
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                $marked[] = $parameter->getName();
            }
        }
        $this->assertSame(['pair', 'body'], $marked);
    }

    /**
     * Calls that make headers() throw: from its own checks, and from the endpoint it asks for the path.
     *
     * @return array
     */
    public static function failing_call_provider(): array {
        return [
            'a nonce in a wrong shape' => [['nonce' => 'short']],
            'a site ID in a wrong shape' => [['siteid' => 'not-a-uuid']],
            'a name of the call that the endpoint refuses' => [['relative' => 'Not/Allowed']],
            'a method that is not allowed' => [['method' => 'PUT']],
            'a time out of range' => [['timestamp' => -1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_call_provider')]
    /**
     * An exception thrown in headers() keeps neither the key pair nor the body in the arguments of its trace.
     *
     * @dataProvider failing_call_provider
     * @param array $override Arguments of the call that replace the good ones.
     */
    public function test_a_failure_does_not_leave_the_key_pair_or_the_body_in_the_trace(array $override): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the key pair and the body stay'
                . ' in the arguments of the trace there.');
        }
        $call = $override + [
            'method' => 'POST',
            'relative' => 'enroll',
            'siteid' => null,
            'timestamp' => 1760000123,
            'nonce' => 'MDEyMzQ1Njc4OWFiY2RlZg',
        ];
        $pair = self::pair();

        // A trace holds the arguments of the calls only while zend.exception_ignore_args is off, so it is off during the
        // calls: with it on, the check would find nothing whatever the code does.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            $control = self::exception_created_with($pair, self::BODY);
            $this->assertTrue(self::trace_has_argument($control, $pair), 'A trace shows the key pair when PHP keeps arguments.');
            $this->assertTrue(self::trace_has_argument($control, self::BODY), 'A trace shows the body when PHP keeps arguments.');
            try {
                request_signer::headers(
                    $pair,
                    $call['method'],
                    self::endpoint(),
                    $call['relative'],
                    self::BODY,
                    $call['siteid'],
                    $call['timestamp'],
                    $call['nonce']
                );
            } catch (\coding_exception $e) {
                $this->assertFalse(self::trace_has_argument($e, $pair), 'The key pair is in the arguments of the trace.');
                $this->assertFalse(self::trace_has_argument($e, self::BODY), 'The body is in the arguments of the trace.');
                return;
            }
            $this->fail('headers() has to throw a coding_exception');
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /**
     * Returns an exception created in a call with the arguments, so that its trace holds them when PHP keeps arguments.
     *
     * @param signing_keypair $pair A key pair.
     * @param string $body A body.
     * @return \Exception
     */
    private static function exception_created_with(signing_keypair $pair, string $body): \Exception {
        return new \Exception('Control exception for ' . $pair::class . ' and ' . strlen($body) . ' bytes');
    }

    /**
     * Tells whether an argument in the trace of an exception is the value (the same object, or an equal string).
     *
     * @param \Throwable $exception The exception.
     * @param mixed $value The key pair or the text.
     * @return bool
     */
    private static function trace_has_argument(\Throwable $exception, mixed $value): bool {
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if ($argument === $value) {
                    return true;
                }
            }
        }
        return false;
    }
}
