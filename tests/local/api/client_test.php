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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\api_exception::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\enroll_result::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\status_result::class)]
/**
 * Tests for the API client, against the fake service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\client
 * @covers     \tool_secbackup\local\api\api_exception
 * @covers     \tool_secbackup\local\api\enroll_result
 * @covers     \tool_secbackup\local\api\status_result
 */
final class client_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The service's clock. */
    private const NOW = 1760000000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * A fake service with a fixed clock.
     *
     * @param array $state Changes to the initial state.
     * @param envelope_builder|null $signer Response signer.
     * @return fake_service
     */
    private static function service(array $state = [], ?envelope_builder $signer = null): fake_service {
        return new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW], $state), $signer);
    }

    /**
     * A client that talks to a Guzzle handler, with this server's clock at $now.
     *
     * @param callable $handler Guzzle handler.
     * @param int $now Local clock.
     * @return client
     */
    private static function client(callable $handler, int $now = self::NOW): client {
        return network_fakes::client($handler, $now);
    }

    /**
     * The connection URL.
     *
     * @return endpoint
     */
    private static function endpoint(): endpoint {
        return endpoint::parse(self::API, developer_settings::from_value(null));
    }

    /**
     * An enrolment request with the fake site's public key.
     *
     * @param string $code Registration code.
     * @param string $publickey Public key as pasted.
     * @return enrollment_request
     */
    private static function request(
        string $code = fake_service::CODE,
        string $publickey = fake_service::ENCRYPTION_KEY
    ): enrollment_request {
        return new enrollment_request(
            $code,
            public_key_format::parse($publickey),
            [
                'wwwroot' => 'https://lms.example.ac.jp',
                'moodle_release' => '5.3 (Build: 20261005)',
                'moodle_version' => '2026100500.00',
                'db_family' => 'postgres',
            ],
            '2026101200'
        );
    }

    /**
     * Expects an API failure.
     *
     * @param string $code Expected error code.
     * @param callable $call The call.
     * @return api_exception
     */
    private function expect_api_error(string $code, callable $call): api_exception {
        try {
            $call();
            $this->fail('No exception');
        } catch (api_exception $e) {
            $this->assertSame($code, $e->errorcode);
            return $e;
        }
    }

    public function test_enroll_returns_the_verified_answer(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        $result = self::client($service->handler(), self::NOW - 30)->enroll(self::endpoint(), $pair, self::request());
        $this->assertSame(fake_service::SITE_ID, $result->siteid);
        $this->assertSame(fake_service::SITE_NAME, $result->sitename);
        $this->assertSame(fake_service::PLATFORM_NAME, $result->platformname);
        $this->assertSame(self::API, $result->apibase);
        $this->assertSame(self::NOW, $result->enrolledat);
        $this->assertSame(30, $result->clockskew);
        $this->assertSame(base64_encode($pair->public_key()), $service->state['signingkey']);
        $this->assertArrayNotHasKey('x-secbackup-site', $service->state['lastheaders']);
    }

    /**
     * The service's fingerprints are read the way a pasted one is: case and spaces do not matter.
     */
    public function test_fingerprints_in_the_answer_are_compared_after_normalising(): void {
        $pair = signing_keypair::generate();
        $service = self::service(['enrollpayload' => [
            'signing_key_fingerprint' => strtolower(fingerprint::format(fingerprint::of($pair->public_key()))),
            'encryption_key_fingerprint' => strtolower(fingerprint::format(self::request()->encryption_fingerprint())),
        ]]);
        $result = self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $this->assertSame(fake_service::SITE_ID, $result->siteid);
    }

    /**
     * Enrolment answers that are signed correctly but belong to another request.
     *
     * @return array
     */
    public static function mismatch_provider(): array {
        return [
            'another nonce' => [['request_nonce' => 'AAAAAAAAAAAAAAAAAAAAAA'], 'request_nonce'],
            'another connection url' => [['api_base' => 'https://other.example.test/api/lms-backup/v1'], 'api_base'],
            'another path' => [['api_base' => 'https://app.example.test/api/lms-backup/v2'], 'api_base'],
            'unreadable connection url' => [['api_base' => 'http://localhost/api'], 'api_base'],
            'another signing key' => [['signing_key_fingerprint' => '0000 0000 0000 0000 0000 0000'], 'signing_key_fingerprint'],
            'another encryption key' => [
                ['encryption_key_fingerprint' => '0000 0000 0000 0000 0000 0000'],
                'encryption_key_fingerprint',
            ],
            'signing key fingerprint that is not a fingerprint' => [
                ['signing_key_fingerprint' => 'not a fingerprint'],
                'signing_key_fingerprint',
            ],
            'encryption key fingerprint that is not a fingerprint' => [
                ['encryption_key_fingerprint' => 'A260B4050FD28D2B313C9A9'],
                'encryption_key_fingerprint',
            ],
            'another type' => [['type' => 'status'], 'type'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatch_provider')]
    /**
     * A signed answer that belongs to another request is refused, naming the field that differs.
     *
     * @dataProvider mismatch_provider
     * @param array $override Fields of the answer.
     * @param string $field Field named in the debug information.
     */
    public function test_answers_for_another_request_are_refused(array $override, string $field): void {
        $service = self::service(['enrollpayload' => $override]);
        $e = $this->expect_api_error('enroll_response_mismatch', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame($field, $e->debuginfo);
    }

    /**
     * Enrolment answers with missing or malformed fields.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'site id is not a uuid' => [['site_id' => 'site-1'], 'site_id'],
            'site id with a trailing line feed' => [['site_id' => fake_service::SITE_ID . "\n"], 'site_id'],
            'site id with a leading line feed' => [['site_id' => "\n" . fake_service::SITE_ID], 'site_id'],
            'site id in upper case' => [['site_id' => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE'], 'site_id'],
            'site id one character too long' => [['site_id' => fake_service::SITE_ID . '0'], 'site_id'],
            'empty site name' => [['site_name' => '  '], 'site_name'],
            'long site name' => [['site_name' => str_repeat('a', 256)], 'site_name'],
            'site name of 256 characters with accents' => [['site_name' => str_repeat("\u{E9}", 256)], 'site_name'],
            'empty platform name' => [['platform_name' => "\t "], 'platform_name'],
            'long platform name in japanese' => [['platform_name' => str_repeat("\u{3042}", 101)], 'platform_name'],
            'line separator in site name' => [['site_name' => "a\u{2028}b"], 'site_name'],
            'paragraph separator in site name' => [['site_name' => "a\u{2029}b"], 'site_name'],
            'format character in site name' => [['site_name' => "a\u{200B}b"], 'site_name'],
            'format character in platform name' => [['platform_name' => "a\u{200B}b"], 'platform_name'],
            'line feed in site name' => [['site_name' => "a\nb"], 'site_name'],
            'control character in platform name' => [['platform_name' => "a\x07b"], 'platform_name'],
            'long platform name' => [['platform_name' => str_repeat('a', 101)], 'platform_name'],
            'local time' => [['enrolled_at' => '2026-10-12 00:00:00'], 'enrolled_at'],
            'impossible date' => [['enrolled_at' => '2026-02-30T00:00:00Z'], 'enrolled_at'],
            'number instead of text' => [['enrolled_at' => 1760000000], 'enrolled_at'],
            'enrolled at with a trailing line feed' => [['enrolled_at' => "2026-10-12T03:04:05Z\n"], 'enrolled_at'],
            'no type' => [['type' => null], 'type'],
            'no site id' => [['site_id' => null], 'site_id'],
            'no site name' => [['site_name' => null], 'site_name'],
            'no platform name' => [['platform_name' => null], 'platform_name'],
            'no encryption key fingerprint' => [['encryption_key_fingerprint' => null], 'encryption_key_fingerprint'],
            'no signing key fingerprint' => [['signing_key_fingerprint' => null], 'signing_key_fingerprint'],
            'no request nonce' => [['request_nonce' => null], 'request_nonce'],
            'no enrolled at' => [['enrolled_at' => null], 'enrolled_at'],
            'no api base' => [['api_base' => null], 'api_base'],
            'number as type' => [['type' => 5], 'type'],
            'number as site id' => [['site_id' => 5], 'site_id'],
            'number as site name' => [['site_name' => 5], 'site_name'],
            'number as platform name' => [['platform_name' => 5], 'platform_name'],
            'number as request nonce' => [['request_nonce' => 5], 'request_nonce'],
            'number as api base' => [['api_base' => 5], 'api_base'],
            'site id with an upper case first group' => [['site_id' => 'AAAAAAAA-bbbb-4ccc-8ddd-eeeeeeeeeeee'], 'site_id'],
            'site id with an upper case middle group' => [['site_id' => 'aaaaaaaa-bbbb-4ccc-8DDD-eeeeeeeeeeee'], 'site_id'],
            'site id with an upper case last group' => [['site_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeeE'], 'site_id'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * A signed answer with a missing or malformed field is refused, naming the field.
     *
     * @dataProvider invalid_provider
     * @param array $override Fields of the answer.
     * @param string $field Field named in the debug information.
     */
    public function test_malformed_answers_are_refused(array $override, string $field): void {
        $service = self::service(['enrollpayload' => $override]);
        $e = $this->expect_api_error('enroll_response_invalid', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame($field, $e->debuginfo);
    }

    public function test_a_site_id_with_lower_case_letters_is_taken(): void {
        $service = self::service(['enrollpayload' => ['site_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]);
        $result = self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $result->siteid);
    }

    public function test_names_of_the_longest_length_are_taken_by_characters_and_not_by_bytes(): void {
        // The service cuts a platform name to 100 characters, and 100 Japanese characters are 300 bytes.
        $names = ['site_name' => str_repeat("\u{3042}", 255), 'platform_name' => str_repeat("\u{3042}", 100)];
        $service = self::service(['enrollpayload' => $names]);
        $result = self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame($names['site_name'], $result->sitename);
        $this->assertSame($names['platform_name'], $result->platformname);
    }

    /**
     * Ways to write the connection URL of the answer that mean the connection URL of the request.
     *
     * @return array
     */
    public static function same_url_provider(): array {
        return [
            'upper case host' => ['https://APP.EXAMPLE.TEST/api/lms-backup/v1'],
            'a slash at the end' => ['https://app.example.test/api/lms-backup/v1/'],
            'the standard port' => ['https://app.example.test:443/api/lms-backup/v1'],
            'white space around' => ["  https://app.example.test/api/lms-backup/v1 \n"],
            'upper case scheme' => ['HTTPS://app.example.test/api/lms-backup/v1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('same_url_provider')]
    /**
     * The connection URL of the answer is compared in its normal form, and the result holds the normal form.
     *
     * @dataProvider same_url_provider
     * @param string $apibase The connection URL as the answer writes it.
     */
    public function test_the_connection_url_of_the_answer_may_be_written_in_another_way(string $apibase): void {
        $service = self::service(['enrollpayload' => ['api_base' => $apibase]]);
        $result = self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame(self::API, $result->apibase);
    }

    public function test_the_connection_url_of_the_request_may_be_written_in_another_way(): void {
        $service = self::service();
        $endpoint = endpoint::parse(" HTTPS://App.Example.TEST:443/api/lms-backup/v1/ ", developer_settings::from_value(null));
        $result = self::client($service->handler())->enroll($endpoint, signing_keypair::generate(), self::request());
        $this->assertSame(self::API, $result->apibase);
        $this->assertSame(['POST /api/lms-backup/v1/enroll'], $service->state['requests']);
    }

    /**
     * A handler that answers as the fake service does, with another HTTP status.
     *
     * @param fake_service $service The fake service.
     * @param int $status The HTTP status.
     * @return callable
     */
    private static function with_status(fake_service $service, int $status): callable {
        $inner = $service->handler();
        return fn($request, array $options) => $inner($request, $options)
            ->then(fn(Response $response) => $response->withStatus($status));
    }

    public function test_only_the_status_201_is_an_enrolment(): void {
        foreach ([200, 202, 204, 299] as $status) {
            $service = self::service();
            $e = $this->expect_api_error('api_unexpected', fn() => self::client(self::with_status($service, $status))
                ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
            $this->assertSame($status, $e->httpstatus);
        }
    }

    public function test_only_the_status_200_is_a_status_answer(): void {
        foreach ([201, 202, 204, 299] as $status) {
            $mock = new MockHandler([new Response($status, [], self::status_body([]))]);
            $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
                ->status(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
            $this->assertSame($status, $e->httpstatus);
        }
    }

    public function test_the_nonce_of_the_answer_is_compared_whole(): void {
        $service = self::service();
        $inner = $service->handler();
        $handler = function ($request, array $options) use ($inner) {
            $nonce = $request->getHeaderLine('X-SecBackup-Nonce');
            return $inner($request, $options)->then(function (Response $response) use ($nonce) {
                $envelope = json_decode((string) $response->getBody(), true);
                $payload = json_decode(base64_decode(strtr($envelope['payload'], '-_', '+/')), true);
                $payload['request_nonce'] = substr($nonce, 0, 21) . (substr($nonce, -1) === 'A' ? 'B' : 'A');
                return new Response(201, [], (new envelope_builder())->body($payload));
            });
        };
        $e = $this->expect_api_error('enroll_response_mismatch', fn() => self::client($handler)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame('request_nonce', $e->debuginfo);
    }

    public function test_the_connection_url_of_the_answer_is_read_with_the_site_settings(): void {
        $settings = developer_settings::from_value(['allowhttp' => true]);
        $api = 'http://app.example.test/api/lms-backup/v1';
        $service = new fake_service($api, array_merge(fake_service::initial_state(), ['now' => self::NOW]));
        $client = new client(
            new \tool_secbackup\local\net\transport($settings, network_fakes::passing_guard(), $service->handler()),
            new \tool_secbackup\local\trust\sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]),
            $settings,
            static fn(): int => self::NOW
        );
        $result = $client->enroll(endpoint::parse($api, $settings), signing_keypair::generate(), self::request());
        $this->assertSame($api, $result->apibase);
    }

    public function test_a_code_that_the_client_does_not_know_is_not_kept(): void {
        $mock = new MockHandler([new Response(400, [], '{"error":{"code":"brand_new_error"}}')]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame('', $e->servercode);
        $this->assertSame(400, $e->a);
    }

    public function test_the_service_time_of_a_known_error_is_kept(): void {
        $mock = new MockHandler([new Response(401, [], '{"error":{"code":"signature_invalid","server_time":1760000123}}')]);
        $e = $this->expect_api_error('api_signature_invalid', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(1760000123, $e->servertime);
    }

    public function test_the_clock_difference_that_the_pages_warn_about(): void {
        $this->assertSame(120, client::CLOCK_SKEW_WARNING);
    }

    public function test_site_name_is_trimmed(): void {
        $service = self::service(['enrollpayload' => ['site_name' => "  Example site \n"]]);
        $result = self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame('Example site', $result->sitename);
    }

    public function test_answer_signed_by_another_key_is_refused(): void {
        $service = self::service(['mode' => 'untrusted_key']);
        try {
            self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_untrusted', $e->errorcode);
        }
    }

    public function test_answer_with_a_broken_signature_is_refused(): void {
        $service = self::service(['mode' => 'bad_signature']);
        try {
            self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_signature', $e->errorcode);
        }
    }

    public function test_code_works_only_once(): void {
        $service = self::service();
        self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->expect_api_error('api_enrollment_code_invalid', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
    }

    public function test_wrong_code(): void {
        $service = self::service();
        $e = $this->expect_api_error('api_enrollment_code_invalid', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request('SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ')));
        $this->assertSame('enrollment_code_invalid', $e->servercode);
        $this->assertSame(401, $e->httpstatus);
    }

    public function test_another_public_key(): void {
        $service = self::service();
        $this->expect_api_error('api_encryption_key_mismatch', fn() => self::client($service->handler())
            ->enroll(
                self::endpoint(),
                signing_keypair::generate(),
                self::request(fake_service::CODE, 'secbk1:AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=')
            ));
    }

    public function test_ip_not_allowed_names_the_address(): void {
        $service = self::service(['mode' => 'ip_not_allowed']);
        $e = $this->expect_api_error('api_ip_not_allowed_address', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame('198.51.100.7', $e->a);
    }

    public function test_ip_not_allowed_without_a_valid_address(): void {
        $mock = new MockHandler([new Response(403, [], '{"error":{"code":"ip_not_allowed","ip":"<b>x</b>"}}')]);
        $this->expect_api_error('api_ip_not_allowed', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
    }

    public function test_an_address_with_a_trailing_line_feed_is_not_an_address(): void {
        $body = json_encode(['error' => ['code' => 'ip_not_allowed', 'ip' => "198.51.100.7\n"]]);
        $mock = new MockHandler([new Response(403, [], $body)]);
        $e = $this->expect_api_error('api_ip_not_allowed', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertNull($e->a);
        $this->assertStringNotContainsString("\n", (string) $e->debuginfo);
    }

    public function test_clock_difference_is_reported_in_minutes(): void {
        $service = self::service();
        $client = self::client($service->handler(), self::NOW + 600);
        $e = $this->expect_api_error(
            'api_timestamp_out_of_range_minutes',
            fn() => $client->enroll(self::endpoint(), signing_keypair::generate(), self::request())
        );
        $this->assertSame(10, $e->a);
        $this->assertSame(self::NOW, $e->servertime);
    }

    public function test_clock_error_without_the_service_time(): void {
        $mock = new MockHandler([new Response(401, [], '{"error":{"code":"timestamp_out_of_range"}}')]);
        $this->expect_api_error('api_timestamp_out_of_range', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
    }

    /**
     * The time that the service gives in a clock error, as it is written in the answer, with the time the client takes from
     * it and the minutes the message shows (both null when the time is not taken).
     *
     * The answer is not signed, so a time is taken only when it is a whole number of 0 to 999999999999, the times that a
     * request can carry. The client reads its own clock at NOW.
     *
     * @return array
     */
    public static function service_time_provider(): array {
        $now = self::NOW;
        return [
            'twenty seconds ahead is no explanation' => [(string) ($now + 20), $now + 20, null],
            'eighty-nine seconds ahead is no explanation' => [(string) ($now + 89), $now + 89, null],
            'ninety seconds ahead is no explanation' => [(string) ($now + 90), $now + 90, null],
            'the same time is no explanation' => [(string) $now, $now, null],
            'two hundred and ninety-nine seconds ahead is no explanation' => [(string) ($now + 299), $now + 299, null],
            'two hundred and ninety-nine seconds behind is no explanation' => [(string) ($now - 299), $now - 299, null],
            'three hundred seconds ahead is five minutes' => [(string) ($now + 300), $now + 300, 5],
            'three hundred seconds behind is five minutes' => [(string) ($now - 300), $now - 300, 5],
            'three hundred and twenty-nine seconds ahead is five minutes' => [(string) ($now + 329), $now + 329, 5],
            'three hundred and thirty seconds ahead is six minutes' => [(string) ($now + 330), $now + 330, 6],
            'ten minutes behind' => [(string) ($now - 600), $now - 600, 10],
            'the smallest time' => ['0', 0, 29333333],
            'the largest time' => ['999999999999', 999999999999, 16637333333],
            'a negative time' => ['-1', null, null],
            'the smallest integer' => ['-9223372036854775808', null, null],
            'a time of 13 digits' => ['1000000000000', null, null],
            'the largest integer' => ['9223372036854775807', null, null],
            'a number beyond the integers' => ['9223372036854775808', null, null],
            'a time with a fraction' => ['1760000000.5', null, null],
            'a time as text' => ['"1760000000"', null, null],
            'a time that is true' => ['true', null, null],
            'a time that is null' => ['null', null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('service_time_provider')]
    /**
     * The message names the difference of the clocks in minutes only when the service gave a time that can be one.
     *
     * @dataProvider service_time_provider
     * @param string $literal The time as written in the answer.
     * @param int|null $servertime The time that the client takes, or null.
     * @param int|null $minutes The minutes in the message, or null for the message without minutes.
     */
    public function test_the_service_time_is_used_only_when_it_can_be_a_time(
        string $literal,
        ?int $servertime,
        ?int $minutes
    ): void {
        $body = '{"error":{"code":"timestamp_out_of_range","server_time":' . $literal . '}}';
        $mock = new MockHandler([new Response(401, [], $body)]);
        $errorcode = $minutes === null ? 'api_timestamp_out_of_range' : 'api_timestamp_out_of_range_minutes';
        $e = $this->expect_api_error($errorcode, fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame($minutes, $e->a);
        $this->assertSame($servertime, $e->servertime);
    }

    public function test_busy_service_gives_the_retry_time(): void {
        $e = $this->expect_api_error('api_unavailable', fn() => self::client(self::service(['mode' => 'unavailable'])->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(120, $e->retryafter);
        $e = $this->expect_api_error('api_rate_limited', fn() => self::client(self::service(['mode' => 'rate_limited'])->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(30, $e->retryafter);
    }

    /**
     * Answers of a busy service in the shapes the web server and the framework give them.
     *
     * @return array
     */
    public static function busy_provider(): array {
        $text = 'Service words that must not show 7f3a';
        return [
            'rate limit as a page' => [429, '<html><body>' . $text . '</body></html>', 'api_rate_limited', $text, ''],
            'rate limit as json without an error' => [
                429,
                json_encode(['message' => $text, 'status' => 429]),
                'api_rate_limited',
                $text,
                '',
            ],
            'rate limit in the service format' => [
                429,
                json_encode(['error' => ['code' => 'too_many_requests', 'message' => $text]]),
                'api_rate_limited',
                $text,
                'too_many_requests',
            ],
            'maintenance as a page' => [503, '<html><body>' . $text . '</body></html>', 'api_unavailable', $text, ''],
            'maintenance as json without an error' => [
                503,
                json_encode(['message' => $text, 'status' => 503]),
                'api_unavailable',
                $text,
                '',
            ],
            'maintenance in the service format' => [
                503,
                json_encode(['error' => ['code' => 'service_unavailable', 'message' => $text]]),
                'api_unavailable',
                $text,
                'service_unavailable',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('busy_provider')]
    /**
     * A busy service gives its own message and the time to wait, and nothing it wrote reaches the exception.
     *
     * @dataProvider busy_provider
     * @param int $status HTTP status.
     * @param string $body The answer.
     * @param string $expected Plugin error code.
     * @param string $text Words of the service that must stay out.
     * @param string $servercode The code the exception keeps.
     */
    public function test_busy_answers_in_any_shape(
        int $status,
        string $body,
        string $expected,
        string $text,
        string $servercode
    ): void {
        $mock = new MockHandler([new Response($status, ['Retry-After' => '30'], $body)]);
        $e = $this->expect_api_error($expected, fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(30, $e->retryafter);
        $this->assertSame($status, $e->httpstatus);
        $this->assertSame($servercode, $e->servercode);
        $this->assert_words_stay_out($e, $text);
    }

    /**
     * Asserts that the words of the service are neither in the debug information nor in any message.
     *
     * @param api_exception $e The exception.
     * @param string $text The words.
     */
    private function assert_words_stay_out(api_exception $e, string $text): void {
        $this->assertNull($e->debuginfo);
        $this->assertStringNotContainsString($text, $e->getMessage());
        $this->assertStringNotContainsString($text, $e->message());
    }

    public function test_the_retry_time_is_kept_for_the_other_answers_too(): void {
        $mock = new MockHandler([new Response(500, ['Retry-After' => '45'], '<html>error</html>')]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(45, $e->retryafter);
        $mock = new MockHandler([new Response(409, ['Retry-After' => '45'], '{"error":{"code":"wwwroot_mismatch"}}')]);
        $e = $this->expect_api_error('api_wwwroot_mismatch', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(45, $e->retryafter);
        $mock = new MockHandler([new Response(409, [], '{"error":{"code":"wwwroot_mismatch"}}')]);
        $e = $this->expect_api_error('api_wwwroot_mismatch', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertNull($e->retryafter);
    }

    public function test_unexpected_answers_show_only_the_http_status(): void {
        $e = $this->expect_api_error('api_unexpected', fn() => self::client(self::service(['mode' => 'unexpected'])->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(500, $e->a);
        $this->assertSame('', $e->servercode);
    }

    public function test_odd_error_codes_are_not_taken(): void {
        $mock = new MockHandler([new Response(400, [], '{"error":{"code":"<script>","message":"Call 000-0000"}}')]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame('', $e->servercode);
        $this->assertStringNotContainsString('000-0000', $e->getMessage());
    }

    /**
     * Error codes as the service may send them, with the code that the exception keeps.
     *
     * The code is kept only when the whole text is 1 to 64 lower-case letters or underscores.
     *
     * @return array
     */
    public static function server_code_provider(): array {
        return [
            'a code' => ['too_many_requests', 'too_many_requests'],
            'the longest code' => [str_repeat('a', 64), str_repeat('a', 64)],
            'one character too long' => [str_repeat('a', 65), ''],
            'a trailing line feed' => ["too_many_requests\n", ''],
            'a leading line feed' => ["\ntoo_many_requests", ''],
            'upper case' => ['Too_Many_Requests', ''],
            'a digit' => ['error1', ''],
            'a space' => ['too many', ''],
            'empty' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('server_code_provider')]
    /**
     * When the HTTP status alone decides the message (429 and 503), the exception keeps the error code of the service only
     * in its plain form.
     *
     * @dataProvider server_code_provider
     * @param string $code Code from the service.
     * @param string $expected Code kept in the exception.
     */
    public function test_the_error_code_is_kept_only_in_its_plain_form(string $code, string $expected): void {
        $body = json_encode(['error' => ['code' => $code]]);
        foreach ([429 => 'api_rate_limited', 503 => 'api_unavailable'] as $status => $errorcode) {
            $mock = new MockHandler([new Response($status, [], $body)]);
            $e = $this->expect_api_error($errorcode, fn() => self::client($mock)
                ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
            $this->assertSame($expected, $e->servercode, $status . ' ' . $code);
        }
    }

    public function test_a_known_error_code_with_a_line_feed_is_not_known(): void {
        $mock = new MockHandler([new Response(422, [], json_encode(['error' => ['code' => "invalid_request\n"]]))]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame('', $e->servercode);
        $this->assertSame(422, $e->a);
    }

    /**
     * Bodies of an error answer that tell no error code.
     *
     * @return array
     */
    public static function unreadable_error_provider(): array {
        return [
            'nothing' => [''],
            'a page' => ['<html>error</html>'],
            'null' => ['null'],
            'a list' => ['[]'],
            'a number' => ['422'],
            'an error that is text' => ['{"error":"Something is wrong"}'],
            'an error that is a list' => ['{"error":[]}'],
            'an error without a code' => ['{"error":{"message":"No code"}}'],
            'a code that is a number' => ['{"error":{"code":401}}'],
            'a code that is a list' => ['{"error":{"code":["invalid_request"]}}'],
            'a code that is null' => ['{"error":{"code":null}}'],
            'more levels than the client reads' => [
                '{"error":{"code":"invalid_request","more":' . str_repeat('[', 9) . str_repeat(']', 9) . '}}',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable_error_provider')]
    /**
     * An error answer without a readable error code gives the message about an unexpected answer, with the HTTP status.
     *
     * @dataProvider unreadable_error_provider
     * @param string $body The body of the answer.
     */
    public function test_an_error_answer_without_a_code_is_unexpected(string $body): void {
        $mock = new MockHandler([new Response(422, [], $body)]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(422, $e->a);
        $this->assertSame(422, $e->httpstatus);
        $this->assertSame('', $e->servercode);
    }

    /**
     * Error codes of the service that have their own message.
     *
     * @return array
     */
    public static function known_error_provider(): array {
        return [
            ['invalid_request', 422, 'api_invalid_request'],
            ['signature_required', 401, 'api_signature_required'],
            ['signature_invalid', 401, 'api_signature_invalid'],
            ['nonce_reused', 401, 'api_nonce_reused'],
            ['site_not_enrolled', 401, 'api_site_not_enrolled'],
            ['wwwroot_mismatch', 409, 'api_wwwroot_mismatch'],
            ['body_too_large', 413, 'api_body_too_large'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('known_error_provider')]
    /**
     * Each error code of the service that has its own message gives that message.
     *
     * @dataProvider known_error_provider
     * @param string $servercode Code from the service.
     * @param int $status HTTP status.
     * @param string $expected Plugin error code.
     */
    public function test_known_error_codes(string $servercode, int $status, string $expected): void {
        $text = 'Service words that must not show 7f3a';
        $body = json_encode(['error' => ['code' => $servercode, 'message' => $text]]);
        $mock = new MockHandler([new Response($status, [], $body)]);
        $e = $this->expect_api_error($expected, fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame($servercode, $e->servercode);
        $this->assertSame($status, $e->httpstatus);
        $this->assert_words_stay_out($e, $text);
    }

    public function test_status_after_enrolment(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $result = self::client($service->handler(), self::NOW - 90)->status(self::endpoint(), $pair, fake_service::SITE_ID);
        $this->assertSame('active', $result->status);
        $this->assertSame(self::NOW, $result->servertime);
        $this->assertSame(90, $result->clockskew);
        $this->assertSame(0, $result->instructions);
        $this->assertSame(fake_service::SITE_ID, $service->state['lastheaders']['x-secbackup-site']);
    }

    public function test_status_of_a_removed_site(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $service->state['mode'] = 'site_removed';
        $this->expect_api_error('api_site_not_enrolled', fn() => self::client($service->handler())
            ->status(self::endpoint(), $pair, fake_service::SITE_ID));
    }

    public function test_status_signed_with_another_key(): void {
        $service = self::service();
        self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->expect_api_error('api_signature_invalid', fn() => self::client($service->handler())
            ->status(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
    }

    public function test_status_answer_for_another_site_is_refused(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $service->state['mode'] = 'bad_status';
        $this->expect_api_error('status_response_invalid', fn() => self::client($service->handler())
            ->status(self::endpoint(), $pair, fake_service::SITE_ID));
    }

    /**
     * A status answer as the service gives it, with some fields replaced or left out.
     *
     * @param array $changes Fields to replace; a field set to null is left out.
     * @return string The body.
     */
    private static function status_body(array $changes): string {
        $fields = array_merge([
            'site_id' => fake_service::SITE_ID,
            'status' => 'active',
            'server_time' => self::NOW,
            'instructions' => [],
        ], $changes);
        return json_encode(array_filter($fields, static fn($value): bool => $value !== null));
    }

    /**
     * Bodies of a status answer that cannot be used.
     *
     * @return array
     */
    public static function unreadable_status_provider(): array {
        return [
            'nothing' => [''],
            'a page' => ['<html>ok</html>'],
            'null' => ['null'],
            'a list' => ['[]'],
            'a number' => ['200'],
            'no site id' => [self::status_body(['site_id' => null])],
            'a site id that is not the site' => [self::status_body(['site_id' => '00000000-0000-4000-8000-000000000000'])],
            'a site id that is a number' => [self::status_body(['site_id' => 1])],
            'no status' => [self::status_body(['status' => null])],
            'a status that is a number' => [self::status_body(['status' => 1])],
            'no server time' => [self::status_body(['server_time' => null])],
            'a server time as text' => [self::status_body(['server_time' => '1760000000'])],
            'a server time with a fraction' => [self::status_body(['server_time' => 1760000000.5])],
            'a negative server time' => [self::status_body(['server_time' => -1])],
            'the smallest integer as the server time' => [self::status_body(['server_time' => PHP_INT_MIN])],
            'a server time of 13 digits' => [self::status_body(['server_time' => 1000000000000])],
            'the largest integer as the server time' => [self::status_body(['server_time' => PHP_INT_MAX])],
            'no instructions' => [self::status_body(['instructions' => null])],
            'instructions as text' => [self::status_body(['instructions' => 'none'])],
            'more levels than the client reads' => [self::status_body(['instructions' => [[[[[[[[[]]]]]]]]]])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable_status_provider')]
    /**
     * A status answer that is not in the form of the service is refused as a whole.
     *
     * @dataProvider unreadable_status_provider
     * @param string $body The body of the answer.
     */
    public function test_a_status_answer_that_cannot_be_used_is_refused(string $body): void {
        $mock = new MockHandler([new Response(200, [], $body)]);
        $e = $this->expect_api_error('status_response_invalid', fn() => self::client($mock)
            ->status(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertSame(200, $e->httpstatus);
        $this->assertSame('', $e->servercode);
    }

    public function test_the_edges_of_the_server_time_and_the_instructions_are_counted(): void {
        $pair = signing_keypair::generate();
        $first = new MockHandler([new Response(200, [], self::status_body(['server_time' => 0]))]);
        $result = self::client($first)->status(self::endpoint(), $pair, fake_service::SITE_ID);
        $this->assertSame(0, $result->servertime);
        $this->assertSame(-self::NOW, $result->clockskew);
        $this->assertSame(0, $result->instructions);

        $instructions = [['kind' => 'a'], ['kind' => 'b']];
        $body = self::status_body(['server_time' => 999999999999, 'instructions' => $instructions]);
        $last = new MockHandler([new Response(200, [], $body)]);
        $result = self::client($last)->status(self::endpoint(), $pair, fake_service::SITE_ID);
        $this->assertSame(999999999999, $result->servertime);
        $this->assertSame(999999999999 - self::NOW, $result->clockskew);
        $this->assertSame(2, $result->instructions);
    }

    /**
     * Status values: the value, and whether the client takes it.
     *
     * A status is 1 to 40 lower-case letters or underscores.
     *
     * @return array
     */
    public static function status_value_provider(): array {
        return [
            'a word' => ['active', true],
            'words joined by an underscore' => ['awaiting_enrollment', true],
            'the longest status' => [str_repeat('a', 40), true],
            'one character too long' => [str_repeat('a', 41), false],
            'empty' => ['', false],
            'upper case' => ['Active', false],
            'a digit' => ['active1', false],
            'a space' => ['not active', false],
            'a trailing line feed' => ["active\n", false],
            'a leading line feed' => ["\nactive", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('status_value_provider')]
    /**
     * The status text is shown on the pages, so only a plain word is taken.
     *
     * @dataProvider status_value_provider
     * @param string $status The status the service gives.
     * @param bool $accepted Whether the client takes it.
     */
    public function test_the_status_value_is_checked(string $status, bool $accepted): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $service->state['status'] = $status;
        $client = self::client($service->handler());
        if ($accepted) {
            $this->assertSame($status, $client->status(self::endpoint(), $pair, fake_service::SITE_ID)->status);
            return;
        }
        $this->expect_api_error('status_response_invalid', fn() => $client->status(self::endpoint(), $pair, fake_service::SITE_ID));
    }

    public function test_the_message_with_the_address_shows_it(): void {
        $service = self::service(['mode' => 'ip_not_allowed']);
        $e = $this->expect_api_error('api_ip_not_allowed_address', fn() => self::client($service->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(get_string('api_ip_not_allowed_address', 'tool_secbackup', '198.51.100.7'), $e->message());
        $this->assertStringContainsString('198.51.100.7', $e->message());
    }

    public function test_the_message_with_the_address_asks_to_check_it_in_both_languages(): void {
        // The address comes from an answer that is not signed, so the message names it twice: as seen, and to be checked.
        foreach (['en', 'ja'] as $lang) {
            $strings = get_string_manager()->load_component_strings('tool_secbackup', $lang, true);
            $this->assertSame(2, substr_count($strings['api_ip_not_allowed_address'], '{$a}'), $lang);
        }
    }

    public function test_the_message_with_the_minutes_shows_them(): void {
        $client = self::client(self::service()->handler(), self::NOW + 600);
        $e = $this->expect_api_error('api_timestamp_out_of_range_minutes', fn() => $client
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(get_string('api_timestamp_out_of_range_minutes', 'tool_secbackup', 10), $e->message());
        $this->assertStringContainsString('10', $e->message());
    }

    public function test_the_message_for_an_unexpected_answer_shows_the_http_status_and_nothing_of_the_answer(): void {
        $mock = new MockHandler([new Response(502, [], '{"error":{"code":"x","message":"Call 000-0000 now"}}')]);
        $e = $this->expect_api_error('api_unexpected', fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(get_string('api_unexpected', 'tool_secbackup', 502), $e->message());
        $this->assertStringContainsString('502', $e->message());
        $this->assertStringNotContainsString('000-0000', $e->message());
        $this->assertStringNotContainsString('000-0000', $e->getMessage());
    }

    public function test_the_error_codes_are_language_strings(): void {
        $codes = ['api_invalid_request', 'api_signature_required', 'api_signature_invalid', 'api_timestamp_out_of_range',
            'api_nonce_reused', 'api_enrollment_code_invalid', 'api_site_not_enrolled', 'api_ip_not_allowed',
            'api_encryption_key_mismatch', 'api_wwwroot_mismatch', 'api_body_too_large', 'api_ip_not_allowed_address',
            'api_timestamp_out_of_range_minutes', 'api_rate_limited', 'api_unavailable', 'api_unexpected',
            'enroll_response_invalid', 'enroll_response_mismatch', 'status_response_invalid'];
        foreach ($codes as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }

    public function test_enroll_sends_one_signed_post_with_the_documented_headers_and_fields(): void {
        $service = self::service();
        self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame(['POST /api/lms-backup/v1/enroll'], $service->state['requests']);
        $headers = $service->state['lastheaders'];
        $this->assertSame((string) self::NOW, $headers['x-secbackup-timestamp']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}\z/', $headers['x-secbackup-nonce']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{86}\z/', $headers['x-secbackup-signature']);
        $this->assertSame('application/json', $headers['accept']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame('tool_secbackup', $headers['user-agent']);
        $this->assertArrayNotHasKey('x-secbackup-site', $headers);
        // The service refuses a request with a field it does not know.
        $fields = array_keys(json_decode($service->state['lastbody'], true));
        sort($fields);
        $expected = ['encryption_key_fingerprint', 'enrollment_code', 'plugin_version', 'signing_public_key', 'site'];
        $this->assertSame($expected, $fields);
    }

    public function test_status_sends_one_signed_get_with_the_site_and_no_body(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        self::client($service->handler())->status(self::endpoint(), $pair, fake_service::SITE_ID);
        $this->assertSame(['POST /api/lms-backup/v1/enroll', 'GET /api/lms-backup/v1/status'], $service->state['requests']);
        $headers = $service->state['lastheaders'];
        $this->assertSame(fake_service::SITE_ID, $headers['x-secbackup-site']);
        $this->assertSame((string) self::NOW, $headers['x-secbackup-timestamp']);
        $this->assertSame('application/json', $headers['accept']);
        $this->assertArrayNotHasKey('content-type', $headers);
        $this->assertSame('', $service->state['lastbody']);
    }

    public function test_every_call_uses_a_new_nonce(): void {
        $service = self::service();
        $pair = signing_keypair::generate();
        self::client($service->handler())->enroll(self::endpoint(), $pair, self::request());
        $client = self::client($service->handler());
        $client->status(self::endpoint(), $pair, fake_service::SITE_ID);
        $client->status(self::endpoint(), $pair, fake_service::SITE_ID);
        // The fake service refuses a nonce that it has seen, so three calls make three different ones.
        $this->assertCount(3, array_unique($service->state['nonces']));
    }

    public function test_create_reads_the_site_settings(): void {
        $this->assertInstanceOf(client::class, client::create());
    }

    public function test_the_key_pair_and_the_request_are_marked_sensitive(): void {
        $marked = [];
        foreach (['enroll', 'status', 'check_binding'] as $name) {
            foreach ((new \ReflectionMethod(client::class, $name))->getParameters() as $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $marked[$name][] = $parameter->getName();
                }
            }
        }
        // The key pair holds the secret key, and the request holds a registration code that is still valid.
        $this->assertSame(['enroll' => ['pair', 'request'], 'status' => ['pair'], 'check_binding' => ['pair', 'request']], $marked);
    }

    /**
     * Calls of enroll() and status() that fail in every way the client reports. Each row is a function that makes the call
     * with a key pair and a request.
     *
     * @return array
     */
    public static function failing_call_provider(): array {
        return [
            'an error answer' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::service(['mode' => 'ip_not_allowed']);
                self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
            }],
            'a code that was used before' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::service(['codeused' => true]);
                self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
            }],
            'an answer for another request' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::service(['enrollpayload' => ['request_nonce' => 'AAAAAAAAAAAAAAAAAAAAAA']]);
                self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
            }],
            'a malformed answer' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::service(['enrollpayload' => ['site_id' => 'site-1']]);
                self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
            }],
            'an answer signed by another key' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::service(['mode' => 'untrusted_key']);
                self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
            }],
            'an enrolment connection that fails' => [static function (signing_keypair $pair, enrollment_request $request): void {
                self::client(self::disconnected('POST'))->enroll(self::endpoint(), $pair, $request);
            }],
            'a status call that is refused' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::enrolled_service($pair, $request);
                $service->state['mode'] = 'site_removed';
                self::client($service->handler())->status(self::endpoint(), $pair, fake_service::SITE_ID);
            }],
            'a status answer that is not readable' => [static function (signing_keypair $pair, enrollment_request $request): void {
                $service = self::enrolled_service($pair, $request);
                $service->state['mode'] = 'bad_status';
                self::client($service->handler())->status(self::endpoint(), $pair, fake_service::SITE_ID);
            }],
            'a status connection that fails' => [static function (signing_keypair $pair, enrollment_request $request): void {
                self::client(self::disconnected('GET'))->status(self::endpoint(), $pair, fake_service::SITE_ID);
            }],
        ];
    }

    /**
     * A fake service where the site has been enrolled.
     *
     * @param signing_keypair $pair The key pair of the site.
     * @param enrollment_request $request The enrolment request.
     * @return fake_service
     */
    private static function enrolled_service(signing_keypair $pair, enrollment_request $request): fake_service {
        $service = self::service();
        self::client($service->handler())->enroll(self::endpoint(), $pair, $request);
        return $service;
    }

    /**
     * A network where the connection fails.
     *
     * @param string $method Method of the request that fails.
     * @return MockHandler
     */
    private static function disconnected(string $method): MockHandler {
        $request = new Request($method, 'https://app.example.test/');
        return new MockHandler([new ConnectException('cURL error 7', $request, null, ['errno' => 7])]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_call_provider')]
    /**
     * An exception from enroll() or status() has no previous exception, which would keep the trace of the call that made
     * it, and its message holds neither the registration code nor the secret key.
     *
     * @dataProvider failing_call_provider
     * @param callable $call Makes a call that fails, with a key pair and a request.
     */
    public function test_a_failure_has_no_previous_exception_and_no_secret_in_its_message(callable $call): void {
        $pair = signing_keypair::generate();
        $e = $this->exception_from($call, $pair, self::request());
        $this->assertNull($e->getPrevious());
        $this->assertStringNotContainsString(fake_service::CODE, $e->getMessage());
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $e->getMessage());
        $this->assertStringNotContainsString(base64_encode($pair->public_key()), $e->getMessage());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_call_provider')]
    /**
     * An exception from enroll() or status() keeps neither the key pair nor the request in the arguments of its trace.
     *
     * @dataProvider failing_call_provider
     * @param callable $call Makes a call that fails, with a key pair and a request.
     */
    public function test_a_failure_does_not_leave_the_key_pair_or_the_request_in_the_trace(callable $call): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the key pair and the request stay'
                . ' in the arguments of the trace there.');
        }
        $pair = signing_keypair::generate();
        $request = self::request();

        // A trace holds the arguments of the calls only while zend.exception_ignore_args is off, so it is off during the
        // calls: with it on, the check would find nothing whatever the code does.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            $control = self::exception_created_with($pair, $request);
            $this->assertTrue(self::trace_holds($control, $pair, $request), 'A trace shows the arguments when PHP keeps them.');
            $e = $this->exception_from($call, $pair, $request);
            $this->assertFalse(self::trace_holds($e, $pair, $request), 'The key pair or the request is in the trace.');
            $frame = self::frame_of_the_call($e);
            $this->assertNotNull($frame, 'The trace has the frame of enroll() or status().');
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /**
     * Makes a call that is expected to fail, and returns what it throws.
     *
     * @param callable $call Makes the call with a key pair and a request.
     * @param signing_keypair $pair The key pair.
     * @param enrollment_request $request The request.
     * @return \Throwable
     */
    private function exception_from(callable $call, signing_keypair $pair, enrollment_request $request): \Throwable {
        try {
            $call($pair, $request);
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('The call has to fail');
    }

    /**
     * Returns an exception created in a call with the arguments, so that its trace holds them when PHP keeps arguments.
     *
     * @param signing_keypair $pair A key pair.
     * @param enrollment_request $request A request.
     * @return \Exception
     */
    private static function exception_created_with(signing_keypair $pair, enrollment_request $request): \Exception {
        return new \Exception('Control exception for ' . $pair::class . ' and ' . $request::class);
    }

    /**
     * The frame of the call of enroll() or status() in the trace of an exception.
     *
     * @param \Throwable $exception The exception.
     * @return array|null The frame, or null when the trace has none.
     */
    private static function frame_of_the_call(\Throwable $exception): ?array {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === client::class && in_array($frame['function'], ['enroll', 'status'], true)) {
                return $frame;
            }
        }
        return null;
    }

    /**
     * Tells whether the arguments in the trace of an exception, as far as the call of enroll() or status(), hold the key
     * pair or the request, also inside arrays and as part of longer text.
     *
     * @param \Throwable $exception The exception.
     * @param signing_keypair $pair The key pair.
     * @param enrollment_request $request The request.
     * @return bool
     */
    private static function trace_holds(\Throwable $exception, signing_keypair $pair, enrollment_request $request): bool {
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (self::holds($argument, $pair, $request)) {
                    return true;
                }
            }
            if (($frame['class'] ?? null) === client::class && in_array($frame['function'], ['enroll', 'status'], true)) {
                // The frames above this one are those of the caller, here the test, which has both objects.
                break;
            }
        }
        return false;
    }

    /**
     * Tells whether an argument is, or holds, the key pair or the request or the registration code.
     *
     * @param mixed $argument An argument of a call in a trace.
     * @param signing_keypair $pair The key pair.
     * @param enrollment_request $request The request.
     * @return bool
     */
    private static function holds(mixed $argument, signing_keypair $pair, enrollment_request $request): bool {
        if ($argument === $pair || $argument === $request) {
            return true;
        }
        if (is_string($argument)) {
            return str_contains($argument, fake_service::CODE);
        }
        if (is_array($argument)) {
            foreach ($argument as $item) {
                if (self::holds($item, $pair, $request)) {
                    return true;
                }
            }
        }
        return false;
    }
}
