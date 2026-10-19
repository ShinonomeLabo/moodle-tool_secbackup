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
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\net\object_url;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\api_exception::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\run_start::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\capacity_result::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\upload_target::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\run_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\core_manifest_ref::class)]
/**
 * Tests for the run calls of the API client, against the fake service and against hand-made answers.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\client
 * @covers     \tool_secbackup\local\api\api_exception
 * @covers     \tool_secbackup\local\api\run_start
 * @covers     \tool_secbackup\local\api\capacity_result
 * @covers     \tool_secbackup\local\api\upload_target
 * @covers     \tool_secbackup\local\api\run_state
 * @covers     \tool_secbackup\local\api\core_manifest_ref
 */
final class client_runs_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The service's clock, and this server's. */
    private const NOW = 1760000000;

    /** @var string ID of the first run that the fake service makes. */
    private const RUN = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';

    /** @var string SHA-256 of "a", standard base64. */
    private const SHA_A = 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs=';

    /** @var array A part of a report. */
    private const PART = ['sha256' => self::SHA_A];

    /** @var string A chunk name. */
    private const NAME_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var string Another chunk name. */
    private const NAME_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * Developer settings that let the fake storage's URLs (a private host name) through.
     *
     * @return developer_settings
     */
    private static function settings(): developer_settings {
        return developer_settings::from_value(['allowprivatehosts' => true]);
    }

    /**
     * A client that talks to a Guzzle handler.
     *
     * @param callable $handler Guzzle handler.
     * @return client
     */
    private static function client(callable $handler): client {
        return network_fakes::client($handler, self::NOW, self::settings());
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
     * A fake service where this key pair is enrolled.
     *
     * @param signing_keypair $pair The site's key pair.
     * @param array $state Changes to the initial state.
     * @return fake_service
     */
    private static function service(signing_keypair $pair, array $state = []): fake_service {
        return new fake_service(self::API, array_merge(
            fake_service::initial_state(),
            ['now' => self::NOW, 'signingkey' => base64_encode($pair->public_key())],
            $state
        ));
    }

    /**
     * A client that answers with these bodies, one after another.
     *
     * @param Response[] $responses The answers.
     * @return client
     */
    private static function answering(array $responses): client {
        return self::client(new MockHandler($responses));
    }

    /**
     * An answer with a JSON body.
     *
     * @param int $status HTTP status.
     * @param mixed $data The body.
     * @param string[] $headers Headers.
     * @return Response
     */
    private static function json(int $status, mixed $data, array $headers = []): Response {
        return new Response($status, $headers, json_encode($data));
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

    /**
     * Starts a run and records its size, on the fake service.
     *
     * @param client $client The client.
     * @param signing_keypair $pair The key pair.
     * @param int $estimate Expected size.
     * @return string The run ID.
     */
    private static function begin(client $client, signing_keypair $pair, int $estimate = 1000): string {
        $run = $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID);
        $client->capacity(self::endpoint(), $pair, fake_service::SITE_ID, $run->run_id(), $estimate);
        return $run->run_id();
    }

    /**
     * The payload of a start answer.
     *
     * @param array $override Fields that replace those of a good payload.
     * @return array
     */
    private static function start_payload(array $override = []): array {
        return array_merge([
            'type' => 'run',
            'site_id' => fake_service::SITE_ID,
            'run_id' => self::RUN,
            'kind' => 'full',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'present_url' => 'https://app.example.test/_s3/sites/x/indexes/present?X-Amz-Signature=ab',
            'present_url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW + 900),
        ], $override);
    }

    /**
     * A signed start answer.
     *
     * @param array $override Fields that replace those of a good payload.
     * @return Response
     */
    private static function start_answer(array $override = []): Response {
        return new Response(201, [], (new envelope_builder())->body(self::start_payload($override)));
    }

    public function test_start_run_returns_the_verified_answer_and_sends_one_signed_post(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $run = self::client($service->handler())->start_run(self::endpoint(), $pair, fake_service::SITE_ID);
        $this->assertSame(self::RUN, $run->run_id());
        $this->assertSame(self::NOW, $run->started_at());
        $this->assertSame(self::NOW + 900, $run->present_url_expires_at());
        $this->assertInstanceOf(object_url::class, $run->present_url());
        $this->assertStringContainsString('/_s3/sites/' . fake_service::SITE_ID . '/indexes/present?', $run->present_url()->url());
        $this->assertSame(['POST /api/lms-backup/v1/runs'], $service->state['requests']);
        $this->assertSame('{"kind":"full"}', $service->state['lastbody']);
        $this->assertSame(fake_service::SITE_ID, $service->state['lastheaders']['x-secbackup-site']);
        $this->assertSame('application/json', $service->state['lastheaders']['content-type']);
    }

    public function test_the_signed_text_of_a_run_call_holds_its_path(): void {
        // The fake service verifies the signature over the path it received, independently of the plugin: a call whose
        // path was signed differently would be refused with signature_invalid.
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        $client->upload_urls(self::endpoint(), $pair, fake_service::SITE_ID, $runid, [
            ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
        ]);
        $this->assertContains('POST /api/lms-backup/v1/runs/' . $runid . '/upload-urls', $service->state['requests']);
    }

    public function test_start_run_refuses_a_kind_that_is_not_a_word(): void {
        $this->expectException(\coding_exception::class);
        self::answering([])->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, 'Full');
    }

    public function test_a_site_id_that_is_not_a_uuid_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        self::answering([])->start_run(self::endpoint(), signing_keypair::generate(), 'x');
    }

    public function test_start_run_accepts_an_answer_with_more_fields_than_it_needs(): void {
        $run = self::answering([self::start_answer(['more' => 1])])
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID);
        $this->assertSame(self::RUN, $run->run_id());
    }

    /**
     * Payloads of a start answer that cannot be used: the changes and the field they break.
     *
     * @return array
     */
    public static function bad_start_provider(): array {
        return [
            'type' => [['type' => 'enrollment'], 'type'],
            'other site' => [['site_id' => '99999999-2222-4333-8444-555555555555'], 'site_id'],
            'site not a string' => [['site_id' => 5], 'site_id'],
            'other kind' => [['kind' => 'incremental'], 'kind'],
            'upper case run' => [['run_id' => strtoupper(self::RUN)], 'run_id'],
            'run with a line feed' => [['run_id' => self::RUN . "\n"], 'run_id'],
            'run missing' => [['run_id' => null], 'run_id'],
            'started with a space' => [['started_at' => '2025-10-09 08:53:20'], 'started_at'],
            'started on a day that is not' => [['started_at' => '2025-02-31T08:53:20Z'], 'started_at'],
            'expires now' => [['present_url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW)], 'present_url_expires_at'],
            'expired' => [['present_url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 1)], 'present_url_expires_at'],
            'expiry as a number' => [['present_url_expires_at' => self::NOW + 900], 'present_url_expires_at'],
            'url as a number' => [['present_url' => 5], 'present_url'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_start_provider')]
    /**
     * A start answer that is not for this site and this request, or malformed, is refused.
     *
     * @dataProvider bad_start_provider
     * @param array $override Changes to a good payload.
     * @param string $field The field that is wrong.
     */
    public function test_a_start_answer_that_cannot_be_used_is_refused(array $override, string $field): void {
        $client = self::answering([self::start_answer($override)]);
        $e = $this->expect_api_error('api_response_invalid', fn() => $client
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertSame($field, $e->debuginfo);
        $this->assertSame(201, $e->httpstatus);
    }

    public function test_a_start_answer_whose_url_is_not_the_storage_is_refused_without_the_url(): void {
        $url = 'https://evil.example.org/x?X-Amz-Signature=secret1234';
        $client = self::answering([self::start_answer(['present_url' => $url])]);
        $client = network_fakes::client(new MockHandler([self::start_answer(['present_url' => $url])]), self::NOW);
        try {
            $client->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('object_url_invalid', $e->errorcode);
            $this->assertStringNotContainsString('secret1234', $e->getMessage());
        }
    }

    public function test_a_start_answer_signed_by_another_key_or_not_signed_is_refused(): void {
        $other = (new envelope_builder(str_repeat('07', 32)))->body(self::start_payload());
        $client = self::answering([new Response(201, [], $other), new Response(201, [], '{"run_id":"x"}')]);
        $pair = signing_keypair::generate();
        try {
            $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_untrusted', $e->errorcode);
        }
        try {
            $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_malformed', $e->errorcode);
        }
    }

    public function test_only_the_status_201_is_a_start_answer(): void {
        $client = self::answering([new Response(200, [], (new envelope_builder())->body(self::start_payload()))]);
        $this->expect_api_error('api_unexpected', fn() => $client
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
    }

    /**
     * The error modes of the fake service, and the exception each gives.
     *
     * @return array
     */
    public static function run_error_provider(): array {
        return [
            ['run_in_progress', 409, 'api_run_in_progress'],
            ['run_limit_reached', 429, 'api_run_limit_reached'],
            ['run_not_found', 404, 'api_run_not_found'],
            ['run_state_invalid', 409, 'api_run_state_invalid'],
            ['capacity_recheck_required', 409, 'api_capacity_recheck_required'],
            ['artifact_signature_invalid', 422, 'api_artifact_signature_invalid'],
            ['core_manifest_not_found', 404, 'api_core_manifest_not_found'],
            ['contract_inactive', 403, 'api_contract_inactive'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('run_error_provider')]
    /**
     * Each new error of the service gives its own message, and the codes are language strings in both languages.
     *
     * @dataProvider run_error_provider
     * @param string $mode Mode of the fake service, which is the error code.
     * @param int $status HTTP status.
     * @param string $expected Plugin error code.
     */
    public function test_the_new_errors_of_the_service(string $mode, int $status, string $expected): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair, ['mode' => $mode]);
        $e = $this->expect_api_error($expected, fn() => self::client($service->handler())
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame($mode, $e->servercode);
        $this->assertSame($status, $e->httpstatus);
        $this->assertNotSame('', $e->message());
        $this->assertStringNotContainsString('Fake service message', $e->getMessage());
        foreach (['en', 'ja'] as $lang) {
            $this->assertTrue(get_string_manager()->string_exists($expected, 'tool_secbackup'), $lang . ' ' . $expected);
        }
    }

    public function test_the_details_of_the_errors_are_kept(): void {
        $pair = signing_keypair::generate();
        $client = fn(string $mode) => self::client(self::service($pair, ['mode' => $mode])->handler());
        $e = $this->expect_api_error('api_run_in_progress', fn() => $client('run_in_progress')
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame('99999999-0000-4000-8000-000000000001', $e->runid);
        $e = $this->expect_api_error('api_run_state_invalid', fn() => $client('run_state_invalid')
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame('verifying', $e->runstatus);
        $e = $this->expect_api_error('api_capacity_recheck_required', fn() => $client('capacity_recheck_required')
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame(100, $e->requestedbytes);
        $this->assertSame(50, $e->estimatedbytes);
        $e = $this->expect_api_error('api_run_limit_reached', fn() => $client('run_limit_reached')
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame(3600, $e->retryafter);
        $this->assertNull($e->runid);
        $this->assertNull($e->runstatus);
        $this->assertNull($e->requestedbytes);
    }

    /**
     * Error answers whose details cannot be used: the code, the error object and the detail that has to be null.
     *
     * @return array
     */
    public static function bad_detail_provider(): array {
        $uuid = 'aaaaaaaa-0000-4000-8000-00000000000a';
        return [
            'run id in capitals' => [409, 'run_in_progress', ['run_id' => strtoupper($uuid)], 'runid', 'api_run_in_progress'],
            'run id with a line feed' => [409, 'run_in_progress', ['run_id' => $uuid . "\n"], 'runid', 'api_run_in_progress'],
            'run id as a number' => [409, 'run_in_progress', ['run_id' => 5], 'runid', 'api_run_in_progress'],
            'status in capitals' => [409, 'run_state_invalid', ['status' => 'Verifying'], 'runstatus', 'api_run_state_invalid'],
            'status with a line feed' => [409, 'run_state_invalid', ['status' => "verifying\n"], 'runstatus',
                'api_run_state_invalid'],
            'status too long' => [409, 'run_state_invalid', ['status' => str_repeat('a', 41)], 'runstatus',
                'api_run_state_invalid'],
            'status as a number' => [409, 'run_state_invalid', ['status' => 5], 'runstatus', 'api_run_state_invalid'],
            'requested below zero' => [409, 'capacity_recheck_required', ['requested_bytes' => -1], 'requestedbytes',
                'api_capacity_recheck_required'],
            'requested as text' => [409, 'capacity_recheck_required', ['requested_bytes' => '5'], 'requestedbytes',
                'api_capacity_recheck_required'],
            'estimated below zero' => [409, 'capacity_recheck_required', ['estimated_bytes' => -1], 'estimatedbytes',
                'api_capacity_recheck_required'],
            'estimated as a float' => [409, 'capacity_recheck_required', ['estimated_bytes' => 1.5], 'estimatedbytes',
                'api_capacity_recheck_required'],
            'retry as text' => [429, 'run_limit_reached', ['retry_after' => 'soon'], 'retryafter', 'api_run_limit_reached'],
            'retry below zero' => [429, 'run_limit_reached', ['retry_after' => -5], 'retryafter', 'api_run_limit_reached'],
            'retry beyond a day' => [429, 'run_limit_reached', ['retry_after' => 86401], 'retryafter', 'api_run_limit_reached'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_detail_provider')]
    /**
     * A detail of an error that cannot be used is left out, and the code is kept.
     *
     * @dataProvider bad_detail_provider
     * @param int $status HTTP status.
     * @param string $code Error code.
     * @param array $extra Fields of the error object.
     * @param string $property Property of the exception that has to be null.
     * @param string $expected Plugin error code.
     */
    public function test_a_detail_that_cannot_be_used_is_left_out(
        int $status,
        string $code,
        array $extra,
        string $property,
        string $expected
    ): void {
        $body = json_encode(['error' => ['code' => $code, 'message' => 'm'] + $extra]);
        $client = self::answering([new Response($status, [], $body)]);
        $e = $this->expect_api_error($expected, fn() => $client
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertNull($e->$property);
        $this->assertSame($code, $e->servercode);
    }

    public function test_the_retry_time_of_the_run_limit_comes_from_the_header_and_then_from_the_body(): void {
        $body = json_encode(['error' => ['code' => 'run_limit_reached', 'message' => 'm', 'retry_after' => 7200]]);
        $e = $this->expect_api_error('api_run_limit_reached', fn() => self::answering(
            [new Response(429, ['Retry-After' => '90'], $body)]
        )
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertSame(90, $e->retryafter);
        $e = $this->expect_api_error('api_run_limit_reached', fn() => self::answering([new Response(429, [], $body)])
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertSame(7200, $e->retryafter);
        // Zero and one day are the edges that a clock can give.
        foreach ([0, 86400] as $seconds) {
            $text = json_encode(['error' => ['code' => 'run_limit_reached', 'message' => 'm', 'retry_after' => $seconds]]);
            $e = $this->expect_api_error('api_run_limit_reached', fn() => self::answering([new Response(429, [], $text)])
                ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
            $this->assertSame($seconds, $e->retryafter);
        }
    }

    public function test_a_run_limit_answer_that_is_not_a_429_is_an_ordinary_known_error(): void {
        $body = json_encode(['error' => ['code' => 'run_limit_reached', 'message' => 'm', 'retry_after' => 60]]);
        $e = $this->expect_api_error('api_run_limit_reached', fn() => self::answering([new Response(409, [], $body)])
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
        $this->assertSame(409, $e->httpstatus);
    }

    public function test_a_429_without_the_run_limit_code_stays_the_rate_limit(): void {
        $body = json_encode(['error' => ['code' => 'too_many_requests', 'message' => 'm']]);
        $this->expect_api_error('api_rate_limited', fn() => self::answering([new Response(429, [], $body)])
            ->start_run(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID));
    }

    public function test_capacity_continues_or_refuses(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID)->run_id();
        $answer = $client->capacity(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 5000);
        $this->assertTrue($answer->continue());
        $this->assertSame(1000000000000, $answer->limit_bytes());
        $this->assertSame(100, $answer->used_bytes());
        $this->assertSame('{"estimated_bytes":5000}', $service->state['lastbody']);
        $this->assertSame('POST /api/lms-backup/v1/runs/' . $runid . '/capacity', end($service->state['requests']));
        $refused = $client->capacity(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 2000000000000);
        $this->assertFalse($refused->continue());
    }

    /**
     * Arguments that are a mistake of the caller.
     *
     * @return array
     */
    public static function bad_argument_provider(): array {
        return [
            'run in capitals' => [static fn(client $c, $p) => $c->capacity(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                strtoupper(self::RUN),
                5
            )],
            'run with a path' => [static fn(client $c, $p) => $c->run_state(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                '../' . self::RUN
            )],
            'run with a line feed' => [static fn(client $c, $p) => $c->fail(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN . "\n",
                'restarted'
            )],
            'estimate above what a number holds exactly' => [static fn(client $c, $p) => $c->capacity(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                9007199254740992
            )],
            'size of zero' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 0, 'sha256' => self::SHA_A]]
            )],
            'size above what a number holds exactly' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 9007199254740992, 'sha256' => self::SHA_A]]
            )],
            'the same object twice' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [
                    ['kind' => 'object', 'name' => self::NAME_A, 'size' => 1, 'sha256' => self::SHA_A],
                    ['kind' => 'object', 'name' => self::NAME_A, 'size' => 2, 'sha256' => self::SHA_A],
                ]
            )],
            'the manifest twice' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [
                    ['kind' => 'manifest', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A],
                    ['kind' => 'manifest', 'size' => 1, 'sha256' => self::SHA_A],
                ]
            )],
            'negative estimate' => [static fn(client $c, $p) => $c->capacity(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                -1
            )],
            'unknown failure code' => [static fn(client $c, $p) => $c->fail(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                'expired'
            )],
            'no items' => [static fn(client $c, $p) => $c->upload_urls(self::endpoint(), $p, fake_service::SITE_ID, self::RUN, [])],
            'too many items' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                array_fill(0, 257, ['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A])
            )],
            'items with keys' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                ['a' => ['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A]]
            )],
            'item that is not an array' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                ['x']
            )],
            'unknown kind' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'present', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A]]
            )],
            'object without a name' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'object', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A]]
            )],
            'object with a short name' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'object', 'name' => 'abc', 'size' => 1, 'sha256' => self::SHA_A]]
            )],
            'object with a line feed in the name' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'object', 'name' => self::NAME_A . "\n", 'size' => 1,
                'sha256' => self::SHA_A]]
            )],
            'index with a name' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => self::NAME_A, 'size' => 1, 'sha256' => self::SHA_A]]
            )],
            'negative size' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => -1, 'sha256' => self::SHA_A]]
            )],
            'size as text' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => '1', 'sha256' => self::SHA_A]]
            )],
            'digest of the wrong length' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => 'AAAA']]
            )],
            'digest in hexadecimal' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => str_repeat('ab', 32)]]
            )],
            'digest that is not canonical' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1,
                'sha256' => 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLt=']]
            )],
            'digest with a line feed' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A . "\n"]]
            )],
            'item with a key more' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1, 'sha256' => self::SHA_A, 'x' => 1]]
            )],
            'item without a key' => [static fn(client $c, $p) => $c->upload_urls(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                [['kind' => 'index', 'name' => null, 'size' => 1]]
            )],
            'version with a letter' => [static fn(client $c, $p) => $c->core_manifest(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                '2024100703.1a',
                '20250113'
            )],
            'build with nine digits' => [static fn(client $c, $p) => $c->core_manifest(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                '2024100703.10',
                '202501130'
            )],
            'version with a line feed' => [static fn(client $c, $p) => $c->core_manifest(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                "2024100703.10\n",
                '20250113'
            )],
            'request larger than the service takes' => [static fn(client $c, $p) => $c->complete(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                ['sha256' => self::SHA_A],
                ['sha256' => self::SHA_A],
                ['tables' => str_repeat('a', 70000)]
            )],
            'request that cannot be encoded' => [static fn(client $c, $p) => $c->complete(
                self::endpoint(),
                $p,
                fake_service::SITE_ID,
                self::RUN,
                ['sha256' => self::SHA_A],
                ['sha256' => self::SHA_A],
                ['x' => INF]
            )],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_argument_provider')]
    /**
     * A call with an argument that is not in its shape is a coding error, and nothing is sent.
     *
     * @dataProvider bad_argument_provider
     * @param callable $call Makes the call with a client and a key pair.
     */
    public function test_a_wrong_argument_is_a_coding_error_and_sends_nothing(callable $call): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $this->expectException(\coding_exception::class);
        try {
            $call(self::client($service->handler()), $pair);
        } finally {
            $this->assertSame([], $service->state['requests']);
        }
    }

    /**
     * Answers of the capacity call that cannot be used.
     *
     * @return array
     */
    public static function bad_capacity_provider(): array {
        $ok = ['decision' => 'continue', 'limit_bytes' => 100, 'used_bytes' => 5];
        return [
            'not json' => ['nope'],
            'a list' => ['[1,2,3]'],
            'empty' => ['{}'],
            'a field more' => [json_encode($ok + ['x' => 1])],
            'a field less' => [json_encode(['decision' => 'continue', 'limit_bytes' => 100])],
            'another decision' => [json_encode(['decision' => 'maybe'] + $ok)],
            'decision as a boolean' => [json_encode(['decision' => true] + $ok)],
            'limit as text' => [json_encode(['limit_bytes' => '100'] + $ok)],
            'limit below zero' => [json_encode(['limit_bytes' => -1] + $ok)],
            'used as null' => [json_encode(['used_bytes' => null] + $ok)],
            'used below zero' => [json_encode(['used_bytes' => -1] + $ok)],
            'used as a float' => [json_encode(['used_bytes' => 1.5] + $ok)],
            'used beyond the integers' => ['{"decision":"continue","limit_bytes":100,"used_bytes":99999999999999999999}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_capacity_provider')]
    /**
     * A capacity answer that cannot be used is refused.
     *
     * @dataProvider bad_capacity_provider
     * @param string $body The body of the answer.
     */
    public function test_a_capacity_answer_that_cannot_be_used_is_refused(string $body): void {
        $client = self::answering([new Response(200, [], $body)]);
        $e = $this->expect_api_error('api_response_invalid', fn() => $client
            ->capacity(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN, 5));
        $this->assertSame(200, $e->httpstatus);
    }

    public function test_a_capacity_answer_without_a_limit_or_for_zero_bytes_is_taken(): void {
        $body = '{"decision":"refuse","limit_bytes":null,"used_bytes":0}';
        $answer = self::answering([new Response(200, [], $body)])
            ->capacity(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN, 0);
        $this->assertFalse($answer->continue());
        $this->assertNull($answer->limit_bytes());
        $this->assertSame(0, $answer->used_bytes());
    }

    public function test_upload_urls_give_a_target_for_each_item_in_order(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        $items = [
            ['kind' => 'object', 'name' => self::NAME_A, 'size' => 10, 'sha256' => self::SHA_A],
            ['kind' => 'object', 'name' => self::NAME_B, 'size' => 20, 'sha256' => self::SHA_A],
            ['kind' => 'manifest', 'name' => null, 'size' => 30, 'sha256' => self::SHA_A],
            ['kind' => 'index', 'name' => null, 'size' => 40, 'sha256' => self::SHA_A],
        ];
        $targets = $client->upload_urls(self::endpoint(), $pair, fake_service::SITE_ID, $runid, $items);
        $this->assertCount(4, $targets);
        $this->assertSame(['object', 'object', 'manifest', 'index'], array_map(fn($t) => $t->kind(), $targets));
        $this->assertSame([self::NAME_A, self::NAME_B, null, null], array_map(fn($t) => $t->name(), $targets));
        $this->assertSame('10', $targets[0]->headers()['Content-Length']);
        $this->assertSame('40', $targets[3]->headers()['Content-Length']);
        $this->assertSame(self::SHA_A, $targets[0]->headers()['x-amz-checksum-sha256']);
        // An object is bound to a sixth header, which makes the PUT refuse an object that is stored already.
        $this->assertCount(6, $targets[0]->headers());
        $this->assertSame('*', $targets[0]->headers()['If-None-Match']);
        $this->assertCount(6, $targets[1]->headers());
        $this->assertCount(5, $targets[2]->headers());
        $this->assertCount(5, $targets[3]->headers());
        $this->assertArrayNotHasKey('If-None-Match', $targets[3]->headers());
        $this->assertSame(self::NOW + 900, $targets[0]->expires_at());
        $this->assertStringContainsString('/objects/' . self::NAME_A . '?', $targets[0]->url()->url());
        $this->assertStringContainsString('/manifests/' . $runid . '?', $targets[2]->url()->url());
        $this->assertStringContainsString('/indexes/' . $runid . '?', $targets[3]->url()->url());
        // The request has the fields of the service and nothing else, in the form it reads.
        $sent = json_decode($service->state['lastbody'], true);
        $this->assertSame(['items'], array_keys($sent));
        $this->assertSame(['kind', 'name', 'size', 'sha256'], array_keys($sent['items'][0]));
        $this->assertSame(['kind', 'size', 'sha256'], array_keys($sent['items'][2]));
    }

    public function test_the_name_of_a_manifest_or_an_index_may_be_left_out(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        $targets = $client->upload_urls(self::endpoint(), $pair, fake_service::SITE_ID, $runid, [
            ['kind' => 'manifest', 'size' => 3, 'sha256' => self::SHA_A],
        ]);
        $this->assertSame('manifest', $targets[0]->kind());
        $this->assertNull($targets[0]->name());
    }

    public function test_upload_urls_without_a_decision_ask_for_the_estimate_again(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID)->run_id();
        $e = $this->expect_api_error('api_capacity_recheck_required', fn() => $client->upload_urls(
            self::endpoint(),
            $pair,
            fake_service::SITE_ID,
            $runid,
            [['kind' => 'index', 'name' => null, 'size' => 30, 'sha256' => self::SHA_A]]
        ));
        // The bytes that were granted so far: the refused batch is not in them.
        $this->assertSame(0, $e->requestedbytes);
        $this->assertNull($e->estimatedbytes);
        $client->capacity(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 100);
        $e = $this->expect_api_error('api_capacity_recheck_required', fn() => $client->upload_urls(
            self::endpoint(),
            $pair,
            fake_service::SITE_ID,
            $runid,
            [['kind' => 'index', 'name' => null, 'size' => 111, 'sha256' => self::SHA_A]]
        ));
        $this->assertSame(0, $e->requestedbytes);
        $this->assertSame(100, $e->estimatedbytes);
        // After a batch was granted the figure is its size, whatever is refused next.
        $client->upload_urls(self::endpoint(), $pair, fake_service::SITE_ID, $runid, [
            ['kind' => 'index', 'name' => null, 'size' => 60, 'sha256' => self::SHA_A],
        ]);
        $e = $this->expect_api_error('api_capacity_recheck_required', fn() => $client->upload_urls(
            self::endpoint(),
            $pair,
            fake_service::SITE_ID,
            $runid,
            [['kind' => 'index', 'name' => null, 'size' => 51, 'sha256' => self::SHA_A]]
        ));
        $this->assertSame(60, $e->requestedbytes);
    }

    public function test_a_contract_that_ends_in_the_middle_of_a_run_is_refused_when_the_urls_are_asked_for(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        $service->state['contractinactive'] = true;
        $e = $this->expect_api_error('api_contract_inactive', fn() => $client->upload_urls(
            self::endpoint(),
            $pair,
            fake_service::SITE_ID,
            $runid,
            [['kind' => 'index', 'name' => null, 'size' => 30, 'sha256' => self::SHA_A]]
        ));
        $this->assertSame([403, 'contract_inactive'], [$e->httpstatus, $e->servercode]);
        // A run does not start either; the run that is open is not touched.
        $this->expect_api_error(
            'api_contract_inactive',
            fn() => $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID)
        );
        $client->fail(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 'contract_inactive');
        $this->assertSame('contract_inactive', $service->state['runs'][$runid]['failure_code']);
    }

    public function test_the_sixth_header_of_an_object_is_taken_as_a_star_and_an_index_has_five(): void {
        $targets = self::targets_from(self::good_targets());
        $this->assertSame('*', $targets[0]->headers()['If-None-Match']);
        $this->assertCount(6, $targets[0]->headers());
        $this->assertCount(5, $targets[1]->headers());
    }

    /**
     * A good answer to a request for the targets of an object and an index.
     *
     * @return array
     */
    private static function good_targets(): array {
        $headers = [
            'Content-Length' => '10',
            'x-amz-checksum-sha256' => self::SHA_A,
            'x-amz-storage-class' => 'STANDARD',
            'x-amz-object-lock-mode' => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => '2025-11-22T00:00:00Z',
        ];
        $expires = gmdate('Y-m-d\TH:i:s\Z', self::NOW + 900);
        return ['items' => [
            ['kind' => 'object', 'name' => self::NAME_A, 'url' => 'https://app.example.test/_s3/o?s=1',
                'headers' => $headers + ['If-None-Match' => '*'], 'expires_at' => $expires],
            ['kind' => 'index', 'url' => 'https://app.example.test/_s3/i?s=1', 'headers' => ['Content-Length' => '5'] + $headers,
                'expires_at' => $expires],
        ]];
    }

    /**
     * Asks for the targets of an object and an index, and gets this answer.
     *
     * @param mixed $answer The decoded answer, or a string for a body that is sent as it is.
     * @return upload_target[]
     */
    private static function targets_from(mixed $answer): array {
        $body = is_string($answer) ? $answer : json_encode($answer);
        return self::answering([new Response(200, [], $body)])->upload_urls(
            self::endpoint(),
            signing_keypair::generate(),
            fake_service::SITE_ID,
            self::RUN,
            [
                ['kind' => 'object', 'name' => self::NAME_A, 'size' => 10, 'sha256' => self::SHA_A],
                ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
            ]
        );
    }

    public function test_a_good_hand_made_answer_for_the_targets_is_taken(): void {
        $targets = self::targets_from(self::good_targets());
        $this->assertCount(2, $targets);
        $this->assertSame('5', $targets[1]->headers()['Content-Length']);
    }

    public function test_the_header_names_may_be_written_in_any_case(): void {
        $answer = self::good_targets();
        $answer['items'][0]['headers'] = [
            'content-length' => '10',
            'X-AMZ-CHECKSUM-SHA256' => self::SHA_A,
            'X-Amz-Storage-Class' => 'STANDARD',
            'x-amz-object-lock-mode' => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => '2025-11-22T00:00:00Z',
            'if-none-match' => '*',
        ];
        $targets = self::targets_from($answer);
        // They are kept as the service wrote them: they are sent as they are.
        $this->assertSame(array_keys($answer['items'][0]['headers']), array_keys($targets[0]->headers()));
    }

    /**
     * Answers for the targets that cannot be used.
     *
     * @return array
     */
    public static function bad_targets_provider(): array {
        $change = static function (callable $f): array {
            $answer = self::good_targets();
            $f($answer);
            return [$answer];
        };
        return [
            'not json' => ['nope'],
            'a list' => ['[]'],
            'a field more at the top' => $change(static function (array &$a): void {
                $a['more'] = 1;
            }),
            'items as an object' => [['items' => ['a' => 1, 'b' => 2]]],
            'items as a string' => [['items' => 'x']],
            'one item less' => $change(static function (array &$a): void {
                array_pop($a['items']);
            }),
            'one item more' => $change(static function (array &$a): void {
                $a['items'][] = $a['items'][0];
            }),
            'items in another order' => $change(static function (array &$a): void {
                $a['items'] = array_reverse($a['items']);
            }),
            'another kind' => $change(static function (array &$a): void {
                $a['items'][1]['kind'] = 'manifest';
            }),
            'another name' => $change(static function (array &$a): void {
                $a['items'][0]['name'] = self::NAME_B;
            }),
            'no name for an object' => $change(static function (array &$a): void {
                unset($a['items'][0]['name']);
            }),
            'a name for an index' => $change(static function (array &$a): void {
                $a['items'][1]['name'] = self::NAME_A;
            }),
            'a field more in an item' => $change(static function (array &$a): void {
                $a['items'][0]['more'] = 1;
            }),
            'no url' => $change(static function (array &$a): void {
                unset($a['items'][0]['url']);
            }),
            'url as a number' => $change(static function (array &$a): void {
                $a['items'][0]['url'] = 5;
            }),
            'no expiry' => $change(static function (array &$a): void {
                unset($a['items'][0]['expires_at']);
            }),
            'expiry as a number' => $change(static function (array &$a): void {
                $a['items'][0]['expires_at'] = self::NOW + 900;
            }),
            'expiry in the past' => $change(static function (array &$a): void {
                $a['items'][0]['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', self::NOW - 1);
            }),
            'expiry now' => $change(static function (array &$a): void {
                $a['items'][0]['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', self::NOW);
            }),
            'headers as a list' => $change(static function (array &$a): void {
                $a['items'][0]['headers'] = ['10', self::SHA_A, 'STANDARD', 'COMPLIANCE', 'x'];
            }),
            'a header more' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['x-amz-acl'] = 'public-read';
            }),
            'an object with five headers' => $change(static function (array &$a): void {
                unset($a['items'][0]['headers']['If-None-Match']);
            }),
            'if-none-match that is not a star' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['If-None-Match'] = '"abc"';
            }),
            'if-none-match as a list' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['If-None-Match'] = ['*'];
            }),
            'if-none-match for an index' => $change(static function (array &$a): void {
                $a['items'][1]['headers']['If-None-Match'] = '*';
            }),
            'if-none-match twice in another case' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['If-None-Match'] = '*';
                $a['items'][0]['headers']['if-none-match'] = '*';
            }),
            'a sixth header that is not if-none-match' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['x-amz-acl'] = 'public-read';
            }),
            'a header less' => $change(static function (array &$a): void {
                unset($a['items'][0]['headers']['x-amz-storage-class']);
            }),
            'a header twice in another case' => $change(static function (array &$a): void {
                unset($a['items'][0]['headers']['x-amz-storage-class']);
                $a['items'][0]['headers']['Content-length'] = '10';
            }),
            'a header with another name' => $change(static function (array &$a): void {
                unset($a['items'][0]['headers']['x-amz-storage-class']);
                $a['items'][0]['headers']['x-amz-storage'] = 'STANDARD';
            }),
            'length as a number' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['Content-Length'] = 10;
            }),
            'another length' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['Content-Length'] = '11';
            }),
            'length with a plus' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['Content-Length'] = '+10';
            }),
            'another digest' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['x-amz-checksum-sha256'] = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
            }),
            'a header that is not text' => $change(static function (array &$a): void {
                $a['items'][0]['headers']['x-amz-storage-class'] = ['STANDARD'];
            }),
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_targets_provider')]
    /**
     * An answer for the targets that does not match the request, or is malformed, is refused.
     *
     * @dataProvider bad_targets_provider
     * @param mixed $answer The answer.
     */
    public function test_an_answer_for_the_targets_that_cannot_be_used_is_refused(mixed $answer): void {
        $this->expect_api_error('api_response_invalid', fn() => self::targets_from($answer));
    }

    public function test_a_target_url_that_is_not_the_storage_is_refused_without_the_url(): void {
        $answer = self::good_targets();
        $answer['items'][0]['url'] = 'https://evil.example.org/o?X-Amz-Signature=secret5678';
        $client = network_fakes::client(new MockHandler([self::json(200, $answer)]), self::NOW);
        try {
            $client->upload_urls(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN, [
                ['kind' => 'object', 'name' => self::NAME_A, 'size' => 10, 'sha256' => self::SHA_A],
                ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
            ]);
            $this->fail('No exception');
        } catch (transport_exception $e) {
            $this->assertSame('object_url_invalid', $e->errorcode);
            $this->assertStringNotContainsString('secret5678', $e->getMessage());
        }
    }

    /**
     * Stores an object in the fake storage, as if it were sent.
     *
     * @param fake_service $service The service.
     * @param string $key Key of the object.
     * @param string $body Body.
     */
    private static function store(fake_service $service, string $key, string $body): void {
        $service->state['objects'][$key] = base64_encode($body);
    }

    /**
     * The manifest and the index of a run with these chunks, stored in the fake storage, and the body of the report.
     *
     * @param fake_service $service The service.
     * @param signing_keypair $pair The key pair.
     * @param string $runid The run.
     * @param string[] $chunks Bodies by name.
     * @return array [manifest, index, stats] of the report, and the chunk count
     */
    private static function report(fake_service $service, signing_keypair $pair, string $runid, array $chunks): array {
        ksort($chunks);
        $site = fake_service::SITE_ID;
        $lines = [json_encode(['v' => 1, 'site_id' => $site, 'run_id' => $runid, 'count' => count($chunks)])];
        foreach ($chunks as $name => $body) {
            self::store($service, 'sites/' . $site . '/objects/' . $name, $body);
            $lines[] = $name . ' ' . strlen($body);
        }
        $index = gzencode(implode("\n", $lines) . "\n");
        $manifest = 'encrypted manifest bytes';
        self::store($service, 'sites/' . $site . '/manifests/' . $runid, $manifest);
        self::store($service, 'sites/' . $site . '/indexes/' . $runid, $index);
        $msha = base64_encode(hash('sha256', $manifest, true));
        $isha = base64_encode(hash('sha256', $index, true));
        $count = count($chunks);
        return [
            ['sha256' => $msha, 'size' => strlen($manifest),
                'signature' => envelope_builder::b64url($pair->sign(
                    "SECBACKUP-MANIFEST-V1\n" . $site . "\n" . $runid . "\n" . $msha
                ))],
            ['sha256' => $isha, 'size' => strlen($index), 'count' => $count,
                'signature' => envelope_builder::b64url($pair->sign("SECBACKUP-INDEX-V1\n" . $site . "\n" . $runid . "\n" . $isha
                    . "\n" . $count))],
            ['users_active_365d' => 3, 'courses' => 2, 'auth_methods' => ['manual'], 'tables' => ['user' => 5]],
            $count,
        ];
    }

    /**
     * Makes a run on the fake service that has got its upload URLs.
     *
     * @param client $client The client.
     * @param signing_keypair $pair The key pair.
     * @return string The run ID.
     */
    private static function uploading(client $client, signing_keypair $pair): string {
        $runid = self::begin($client, $pair);
        $client->upload_urls(self::endpoint(), $pair, fake_service::SITE_ID, $runid, [
            ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
        ]);
        return $runid;
    }

    public function test_a_run_goes_from_the_start_to_a_receipt(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair, ['holdverify' => true]);
        $client = self::client($service->handler());
        $runid = self::uploading($client, $pair);
        $this->assertSame('uploading', $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid)->status());
        [$manifest, $index, $stats, $count] = self::report($service, $pair, $runid, ['c' . str_repeat('0', 63) => 'chunk one']);
        $client->complete(self::endpoint(), $pair, fake_service::SITE_ID, $runid, $manifest, $index, $stats);
        $sent = json_decode($service->state['lastbody'], true);
        $this->assertSame(['manifest', 'index', 'stats'], array_keys($sent));
        $state = $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid);
        $this->assertSame('verifying', $state->status());
        $this->assertNull($state->failure_code());
        $this->assertNull($state->receipt());
        $service->verify_run($runid);
        $state = $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid);
        $this->assertSame('completed', $state->status());
        $payload = receipt::verify(
            $state->receipt(),
            network_fakes::trust(),
            fake_service::SITE_ID,
            $runid,
            $manifest['sha256'],
            $index['sha256'],
            $count
        );
        $this->assertSame('receipt', $payload['type']);
        $this->assertSame(strlen('chunk one') + $manifest['size'] + $index['size'], $payload['stored_bytes']);
    }

    public function test_a_run_whose_chunk_is_missing_fails_the_verification_and_has_no_receipt(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::uploading($client, $pair);
        [$manifest, $index, $stats] = self::report($service, $pair, $runid, [str_repeat('1', 64) => 'chunk']);
        unset($service->state['objects']['sites/' . fake_service::SITE_ID . '/objects/' . str_repeat('1', 64)]);
        $client->complete(self::endpoint(), $pair, fake_service::SITE_ID, $runid, $manifest, $index, $stats);
        $state = $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid);
        $this->assertSame('failed', $state->status());
        $this->assertSame('verify_missing_chunks', $state->failure_code());
        $this->assertNull($state->receipt());
    }

    public function test_a_report_signed_by_another_key_is_refused_by_the_service(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::uploading($client, $pair);
        [$manifest, $index, $stats] = self::report($service, signing_keypair::generate(), $runid, []);
        $e = $this->expect_api_error('api_artifact_signature_invalid', fn() => $client
            ->complete(self::endpoint(), $pair, fake_service::SITE_ID, $runid, $manifest, $index, $stats));
        $this->assertSame(422, $e->httpstatus);
    }

    public function test_complete_before_the_upload_urls_is_a_state_error_with_the_status(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        [$manifest, $index, $stats] = self::report($service, $pair, $runid, []);
        $e = $this->expect_api_error('api_run_state_invalid', fn() => $client
            ->complete(self::endpoint(), $pair, fake_service::SITE_ID, $runid, $manifest, $index, $stats));
        $this->assertSame('started', $e->runstatus);
        $this->assertSame(409, $e->httpstatus);
    }

    /**
     * Answers of the calls that give a status only.
     *
     * @return array
     */
    public static function bad_status_only_provider(): array {
        return [
            'not json' => ['nope'],
            'another status' => ['{"status":"completed"}'],
            'status as a boolean' => ['{"status":true}'],
            'a field more' => ['{"status":"X","more":1}'],
            'empty' => ['{}'],
            'a list' => ['["X"]'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_status_only_provider')]
    /**
     * A complete or a fail answer that is not the one the service gives is refused.
     *
     * @dataProvider bad_status_only_provider
     * @param string $body The body, where X stands for the status that is expected.
     */
    public function test_a_report_or_failure_answer_that_cannot_be_used_is_refused(string $body): void {
        $pair = signing_keypair::generate();
        $verifying = str_replace('X', 'verifying', $body);
        $failed = str_replace('X', 'failed', $body);
        $this->expect_api_error('api_response_invalid', fn() => self::answering([new Response(202, [], $verifying)])
            ->complete(self::endpoint(), $pair, fake_service::SITE_ID, self::RUN, self::PART, self::PART, []));
        $this->expect_api_error('api_response_invalid', fn() => self::answering([new Response(200, [], $failed)])
            ->fail(self::endpoint(), $pair, fake_service::SITE_ID, self::RUN, 'restarted'));
    }

    public function test_complete_and_fail_take_only_their_own_success_status(): void {
        $pair = signing_keypair::generate();
        $this->expect_api_error('api_unexpected', fn() => self::answering([new Response(200, [], '{"status":"verifying"}')])
            ->complete(self::endpoint(), $pair, fake_service::SITE_ID, self::RUN, self::PART, self::PART, []));
        $this->expect_api_error('api_unexpected', fn() => self::answering([new Response(202, [], '{"status":"failed"}')])
            ->fail(self::endpoint(), $pair, fake_service::SITE_ID, self::RUN, 'restarted'));
    }

    public function test_fail_stops_the_run_and_a_second_fail_is_a_state_error(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = self::begin($client, $pair);
        $client->fail(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 'disk_low');
        $this->assertSame('{"code":"disk_low"}', $service->state['lastbody']);
        $state = $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid);
        $this->assertSame('failed', $state->status());
        $this->assertSame('disk_low', $state->failure_code());
        $e = $this->expect_api_error('api_run_state_invalid', fn() => $client
            ->fail(self::endpoint(), $pair, fake_service::SITE_ID, $runid, 'disk_low'));
        $this->assertSame('failed', $e->runstatus);
        // Every reason of the contract is accepted by the service.
        foreach (client::FAIL_CODES as $code) {
            // A client for each reason: the fake name sources answer a number of times only.
            $each = self::client($service->handler());
            $next = $each->start_run(self::endpoint(), $pair, fake_service::SITE_ID)->run_id();
            $each->fail(self::endpoint(), $pair, fake_service::SITE_ID, $next, $code);
        }
        $this->assertCount(14, client::FAIL_CODES);
        $this->assertContains('contract_inactive', client::FAIL_CODES);
    }

    public function test_a_run_that_the_service_does_not_know_is_not_found(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $e = $this->expect_api_error('api_run_not_found', fn() => self::client($service->handler())
            ->run_state(self::endpoint(), $pair, fake_service::SITE_ID, 'aaaaaaaa-bbbb-4ccc-8ddd-000000000099'));
        $this->assertSame('run_not_found', $e->servercode);
    }

    public function test_a_second_start_while_a_run_is_open_names_the_run(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID)->run_id();
        $e = $this->expect_api_error('api_run_in_progress', fn() => $client
            ->start_run(self::endpoint(), $pair, fake_service::SITE_ID));
        $this->assertSame($runid, $e->runid);
    }

    /**
     * Answers of the run state call that cannot be used.
     *
     * @return array
     */
    public static function bad_state_provider(): array {
        $ok = ['run_id' => self::RUN, 'status' => 'uploading', 'failure_code' => null, 'receipt' => null];
        return [
            'not json' => ['nope'],
            'a list' => ['[]'],
            'a field more' => [json_encode($ok + ['x' => 1])],
            'a field less' => [json_encode(array_diff_key($ok, ['receipt' => 1]))],
            'another run' => [json_encode(['run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002'] + $ok)],
            'run in capitals' => [json_encode(['run_id' => strtoupper(self::RUN)] + $ok)],
            'status in capitals' => [json_encode(['status' => 'Uploading'] + $ok)],
            'status with a line feed' => [json_encode(['status' => "uploading\n"] + $ok)],
            'status too long' => [json_encode(['status' => str_repeat('a', 41)] + $ok)],
            'status as a number' => [json_encode(['status' => 5] + $ok)],
            'failure code with digits' => [json_encode(['failure_code' => 'verify1'] + $ok)],
            'failure code with a line feed' => [json_encode(['failure_code' => "expired\n"] + $ok)],
            'failure code too long' => [json_encode(['failure_code' => str_repeat('a', 65)] + $ok)],
            'failure code as a number' => [json_encode(['failure_code' => 5] + $ok)],
            'receipt as a string' => [json_encode(['receipt' => 'x'] + $ok)],
            'receipt as a list' => [json_encode(['receipt' => [1, 2]] + $ok)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_state_provider')]
    /**
     * A run state that cannot be used is refused.
     *
     * @dataProvider bad_state_provider
     * @param string $body The body of the answer.
     */
    public function test_a_run_state_that_cannot_be_used_is_refused(string $body): void {
        $this->expect_api_error('api_response_invalid', fn() => self::answering([new Response(200, [], $body)])
            ->run_state(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN));
    }

    public function test_a_run_state_with_a_failure_code_and_a_receipt_is_kept_as_it_came(): void {
        $envelope = ['v' => 1, 'kid' => 'x', 'payload' => 'y', 'sig' => 'z'];
        $body = json_encode(['run_id' => self::RUN, 'status' => 'failed', 'failure_code' => str_repeat('a', 64),
            'receipt' => $envelope]);
        $state = self::answering([new Response(200, [], $body)])
            ->run_state(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN);
        $this->assertSame(str_repeat('a', 64), $state->failure_code());
        $this->assertSame($envelope, $state->receipt());
    }

    public function test_a_run_state_is_a_get_without_a_body(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $client = self::client($service->handler());
        $runid = $client->start_run(self::endpoint(), $pair, fake_service::SITE_ID)->run_id();
        $client->run_state(self::endpoint(), $pair, fake_service::SITE_ID, $runid);
        $this->assertSame('GET /api/lms-backup/v1/runs/' . $runid, end($service->state['requests']));
        $this->assertSame('', $service->state['lastbody']);
        $this->assertArrayNotHasKey('content-type', $service->state['lastheaders']);
    }

    /**
     * Publishes a list of official files on the fake service and returns the content that was published.
     *
     * @param fake_service $service The service.
     * @return string
     */
    private static function publish_core(fake_service $service): string {
        $gz = gzencode("{\"v\":1}\nindex.php\tabc\n");
        $service->publish_core_manifest('2024100703.10', '20250113', $gz);
        return $gz;
    }

    public function test_the_list_of_official_files_is_found_and_verified(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $gz = self::publish_core($service);
        $ref = self::client($service->handler())
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113');
        $this->assertSame(base64_encode(hash('sha256', $gz, true)), $ref->sha256());
        $this->assertSame(strlen($gz), $ref->size());
        $this->assertSame(3, $ref->count());
        $this->assertStringContainsString('/_s3/core-manifests/2024100703.10/20250113.gz?', $ref->url()->url());
        $this->assertSame('{"version":"2024100703.10","build":"20250113"}', $service->state['lastbody']);
        $this->assertSame('POST /api/lms-backup/v1/core-manifests', end($service->state['requests']));
    }

    public function test_a_list_of_official_files_that_does_not_exist_is_null(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $ref = self::client($service->handler())
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113');
        $this->assertNull($ref);
    }

    public function test_another_not_found_is_not_a_missing_list(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair, ['mode' => 'run_not_found']);
        $this->expect_api_error('api_run_not_found', fn() => self::client($service->handler())
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113'));
        // And the missing list with another status is an error too.
        $body = json_encode(['error' => ['code' => 'core_manifest_not_found', 'message' => 'm']]);
        $this->expect_api_error('api_core_manifest_not_found', fn() => self::answering([new Response(409, [], $body)])
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113'));
    }

    /**
     * Payloads of the envelope of a list of official files that cannot be used.
     *
     * @return array
     */
    public static function bad_core_payload_provider(): array {
        return [
            'type' => [['type' => 'run']],
            'other version' => [['version' => '2024100703.11']],
            'other build' => [['build' => '20250114']],
            'digest of the wrong length' => [['sha256' => 'AAAA']],
            'digest in hexadecimal' => [['sha256' => str_repeat('ab', 32)]],
            'digest that is not canonical' => [['sha256' => 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLt=']],
            'digest as a number' => [['sha256' => 5]],
            'size below zero' => [['size' => -1]],
            'size as text' => [['size' => '5']],
            'count below zero' => [['count' => -1]],
            'count as text' => [['count' => '5']],
            'published with a space' => [['published_at' => '2025-10-09 08:53:20']],
            'published as a number' => [['published_at' => 5]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_core_payload_provider')]
    /**
     * An envelope of a list of official files that is not for this request, or malformed, is refused.
     *
     * @dataProvider bad_core_payload_provider
     * @param array $override Changes to a good payload.
     */
    public function test_a_list_of_official_files_that_cannot_be_used_is_refused(array $override): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        self::publish_core($service);
        $service->publish_core_manifest('2024100703.10', '20250113', gzencode('x'), $override);
        $this->expect_api_error('api_response_invalid', fn() => self::client($service->handler())
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113'));
    }

    /**
     * Answers of the list call, built from the good answer of the fake service.
     *
     * @return array
     */
    public static function bad_core_answer_provider(): array {
        return [
            'not json' => [static fn(array $a): string => 'nope'],
            'a list' => [static fn(array $a): string => '[]'],
            'a field more' => [static fn(array $a): string => json_encode($a + ['more' => 1])],
            'no url' => [static function (array $a): string {
                unset($a['url']);
                return json_encode($a);
            }],
            'url as a number' => [static fn(array $a): string => json_encode(['url' => 5] + $a)],
            'expiry as a number' => [static fn(array $a): string => json_encode(['url_expires_at' => self::NOW + 900] + $a)],
            'expiry in the past' => [static fn(array $a): string => json_encode(
                ['url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW)] + $a
            )],
            'envelope as text' => [static fn(array $a): string => json_encode(['manifest' => 'x'] + $a)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_core_answer_provider')]
    /**
     * An answer for the list of official files that cannot be used is refused.
     *
     * @dataProvider bad_core_answer_provider
     * @param callable $make Makes the body from the decoded good answer.
     */
    public function test_an_answer_for_the_list_of_official_files_that_cannot_be_used_is_refused(callable $make): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        self::publish_core($service);
        $answer = [
            'manifest' => $service->state['coremanifests']['2024100703.10/20250113'],
            'url' => 'https://app.example.test/_s3/core-manifests/2024100703.10/20250113.gz?s=1',
            'url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW + 900),
        ];
        $this->expect_api_error('api_response_invalid', fn() => self::answering([new Response(200, [], $make($answer))])
            ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113'));
    }

    public function test_a_list_of_official_files_signed_by_another_key_is_refused(): void {
        $pair = signing_keypair::generate();
        $service = new fake_service(self::API, array_merge(
            fake_service::initial_state(),
            ['now' => self::NOW, 'signingkey' => base64_encode($pair->public_key())]
        ), new envelope_builder(str_repeat('07', 32)));
        self::publish_core($service);
        try {
            self::client($service->handler())
                ->core_manifest(self::endpoint(), $pair, fake_service::SITE_ID, '2024100703.10', '20250113');
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_untrusted', $e->errorcode);
        }
    }

    public function test_the_key_pair_is_marked_sensitive_in_every_run_call(): void {
        $calls = ['start_run', 'capacity', 'upload_urls', 'complete', 'fail', 'run_state', 'core_manifest'];
        foreach ($calls as $name) {
            $parameters = (new \ReflectionMethod(client::class, $name))->getParameters();
            $this->assertSame('pair', $parameters[1]->getName(), $name);
            $this->assertNotSame([], $parameters[1]->getAttributes(\SensitiveParameter::class), $name);
        }
        $private = (new \ReflectionMethod(client::class, 'call'))->getParameters();
        $this->assertNotSame([], $private[1]->getAttributes(\SensitiveParameter::class));
    }

    public function test_a_failure_of_a_run_call_has_no_previous_exception_and_no_key_in_its_message(): void {
        $pair = signing_keypair::generate();
        $e = $this->expect_api_error('api_unexpected', fn() => self::answering([new Response(500, [], 'x')])
            ->capacity(self::endpoint(), $pair, fake_service::SITE_ID, self::RUN, 5));
        $this->assertNull($e->getPrevious());
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $e->getMessage());
    }

    /**
     * Answers for the upload URLs that are refused, by what is wrong with them.
     *
     * @return array
     */
    public static function refused_answer_provider(): array {
        return [
            'an object with five headers' => ['five'],
            'an url that has expired' => ['expired'],
            'an url that is not the storage' => ['foreign'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_answer_provider')]
    /**
     * When an answer for the upload URLs is refused, the signed URL is in no part of the exception, nor of its trace.
     *
     * The case is a name, so that no frame of the test holds the answer.
     *
     * @dataProvider refused_answer_provider
     * @param string $case What is wrong with the answer.
     */
    public function test_the_signed_url_is_not_in_the_trace_of_a_refused_answer(string $case): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $answer = self::good_targets();
            $signed = 'X-Amz-Signature=sekretvalue1';
            $answer['items'][0]['url'] = 'https://app.example.test/_s3/o?' . $signed;
            $answer['items'][1]['url'] = 'https://app.example.test/_s3/i?' . $signed;
            if ($case === 'five') {
                unset($answer['items'][0]['headers']['If-None-Match']);
            } else if ($case === 'expired') {
                $answer['items'][0]['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', self::NOW - 1);
            } else {
                // Not https: the developer settings of the test let any host through, but not a plain connection.
                $answer['items'][0]['url'] = 'http://app.example.test/_s3/o?' . $signed;
            }
            $client = self::answering([new Response(200, [], json_encode($answer))]);
            unset($answer);
            try {
                $client->upload_urls(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN, [
                    ['kind' => 'object', 'name' => self::NAME_A, 'size' => 10, 'sha256' => self::SHA_A],
                    ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
                ]);
                $this->fail('No exception');
            } catch (\tool_secbackup\local\plugin_exception $e) {
                foreach (\tool_secbackup\tests\trace_strings::of($e) as $string) {
                    $this->assertStringNotContainsString('sekretvalue1', $string);
                    $this->assertStringNotContainsString('/_s3/', $string);
                }
                $this->assertStringNotContainsString('sekretvalue1', (string) $e->debuginfo);
                $this->assertSame([], array_diff(
                    \tool_secbackup\tests\trace_strings::objects_in_plugin_frames($e),
                    [signing_keypair::class, \SensitiveParameterValue::class, \Closure::class, endpoint::class]
                ));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    /**
     * Answers whose top level is wrong, by the call that reads them.
     *
     * @return array
     */
    public static function wrong_top_level_provider(): array {
        return [
            'upload urls' => ['urls'],
            'the list of official files' => ['core'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrong_top_level_provider')]
    /**
     * An answer with the wrong fields at its top level keeps its body, and the signed URLs in it, out of every trace.
     *
     * @dataProvider wrong_top_level_provider
     * @param string $case The call.
     */
    public function test_the_body_is_not_in_the_trace_of_an_answer_with_wrong_fields(string $case): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $url = 'https://app.example.test/_s3/o?X-Amz-Signature=sekretvalue2';
            $body = json_encode(['x' => $url, 'items' => [$url]]);
            $client = self::answering([new Response(200, [], $body)]);
            unset($body);
            try {
                if ($case === 'urls') {
                    $client->upload_urls(self::endpoint(), signing_keypair::generate(), fake_service::SITE_ID, self::RUN, [
                        ['kind' => 'index', 'name' => null, 'size' => 5, 'sha256' => self::SHA_A],
                    ]);
                } else {
                    $client->core_manifest(
                        self::endpoint(),
                        signing_keypair::generate(),
                        fake_service::SITE_ID,
                        '2024100703.10',
                        '20250113'
                    );
                }
                $this->fail('No exception');
            } catch (\tool_secbackup\local\plugin_exception $e) {
                $this->assertSame('api_response_invalid', $e->errorcode);
                foreach (\tool_secbackup\tests\trace_strings::of($e) as $string) {
                    $this->assertStringNotContainsString('sekretvalue2', $string);
                }
                $found = \tool_secbackup\tests\trace_strings::objects_in_plugin_frames($e);
                $this->assertNotContains(\tool_secbackup\local\net\response::class, $found);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }
}
