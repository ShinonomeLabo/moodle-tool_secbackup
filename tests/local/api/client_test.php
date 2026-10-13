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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
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
            'line separator in site name' => [['site_name' => "a\u{2028}b"], 'site_name'],
            'control character in platform name' => [['platform_name' => "a\x07b"], 'platform_name'],
            'long platform name' => [['platform_name' => str_repeat('a', 101)], 'platform_name'],
            'local time' => [['enrolled_at' => '2026-10-12 00:00:00'], 'enrolled_at'],
            'impossible date' => [['enrolled_at' => '2026-02-30T00:00:00Z'], 'enrolled_at'],
            'number instead of text' => [['enrolled_at' => 1760000000], 'enrolled_at'],
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

    public function test_site_name_is_trimmed(): void {
        $service = self::service(['enrollpayload' => ['site_name' => "  Example site \n"]]);
        $result = self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
        $this->assertSame('Example site', $result->sitename);
    }

    public function test_answer_signed_by_another_key_is_refused(): void {
        $service = self::service(['mode' => 'untrusted_key']);
        $this->expectException(envelope_exception::class);
        self::client($service->handler())->enroll(self::endpoint(), signing_keypair::generate(), self::request());
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

    public function test_busy_service_gives_the_retry_time(): void {
        $e = $this->expect_api_error('api_unavailable', fn() => self::client(self::service(['mode' => 'unavailable'])->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(120, $e->retryafter);
        $e = $this->expect_api_error('api_rate_limited', fn() => self::client(self::service(['mode' => 'rate_limited'])->handler())
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame(30, $e->retryafter);
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
        $mock = new MockHandler([new Response($status, [], json_encode(['error' => ['code' => $servercode]]))]);
        $e = $this->expect_api_error($expected, fn() => self::client($mock)
            ->enroll(self::endpoint(), signing_keypair::generate(), self::request()));
        $this->assertSame($servercode, $e->servercode);
        $this->assertSame($status, $e->httpstatus);
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

    public function test_create_reads_the_site_settings(): void {
        $this->assertInstanceOf(client::class, client::create());
    }
}
