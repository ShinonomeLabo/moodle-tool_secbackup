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

    /** @var string Path prefix of the API. */
    private const BASE = '/api/lms-backup/v1';

    /** @var string A digest, standard base64. */
    private const SHA = 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs=';

    /** @var string The first run that the fake service makes. */
    private const RUN = '/runs/aaaaaaaa-bbbb-4ccc-8ddd-000000000001';

    /**
     * A fake service where a key pair made here is enrolled.
     *
     * @return array [fake_service, secret key]
     */
    private static function enrolled(): array {
        $pair = sodium_crypto_sign_keypair();
        $service = new fake_service(self::API, array_merge(fake_service::initial_state(), [
            'now' => self::NOW, 'signingkey' => base64_encode(sodium_crypto_sign_publickey($pair))]));
        return [$service, sodium_crypto_sign_secretkey($pair)];
    }

    /**
     * Sends a request signed as the plugin signs its requests, written out here from the contract.
     *
     * @param fake_service $service The service.
     * @param string $secret Secret key.
     * @param string $method Method.
     * @param string $path Path after the API prefix.
     * @param string $body Body.
     * @param string|null $signedpath Path that is signed, when it is not the one that is sent.
     * @return array [HTTP status, headers, decoded body]
     */
    private static function signed(
        fake_service $service,
        string $secret,
        string $method,
        string $path,
        string $body = '',
        ?string $signedpath = null
    ): array {
        static $counter = 0;
        $nonce = envelope_builder::b64url(str_pad((string) ++$counter, 16, '0', STR_PAD_LEFT));
        $canonical = implode("\n", ['SECBACKUP-V1', $method, 'app.example.test', self::BASE . ($signedpath ?? $path), '',
            (string) self::NOW, $nonce, hash('sha256', $body)]);
        $headers = ['x-secbackup-timestamp' => (string) self::NOW, 'x-secbackup-nonce' => $nonce,
            'x-secbackup-signature' => envelope_builder::b64url(sodium_crypto_sign_detached($canonical, $secret)),
            'x-secbackup-site' => fake_service::SITE_ID];
        $answer = $service->handle($method, 'app.example.test', self::BASE . $path, $headers, $body);
        return [$answer[0], $answer[1], json_decode($answer[2], true)];
    }

    /**
     * The code of the error in an answer.
     *
     * @param array $answer An answer of signed().
     * @return string
     */
    private static function code(array $answer): string {
        return $answer[2]['error']['code'];
    }

    public function test_the_run_endpoints_check_the_signature_over_the_path_they_received(): void {
        [$service, $secret] = self::enrolled();
        $this->assertSame(201, self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}')[0]);
        $body = '{"estimated_bytes":5}';
        // The signature over the path of another call is refused.
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/capacity', $body, '/runs');
        $this->assertSame([401, 'signature_invalid'], [$answer[0], self::code($answer)]);
        $this->assertSame(200, self::signed($service, $secret, 'POST', self::RUN . '/capacity', $body)[0]);
        // Without a signature or an enrolment, the run endpoints answer as the others do.
        $this->assertSame(401, $service->handle('POST', 'app.example.test', self::BASE . '/runs', [], '{"kind":"full"}')[0]);
        $other = new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW]));
        $answer = self::signed($other, $secret, 'POST', '/runs', '{"kind":"full"}');
        $this->assertSame([401, 'site_not_enrolled'], [$answer[0], self::code($answer)]);
    }

    /**
     * Requests that the service refuses as malformed: path, body.
     *
     * @return array
     */
    public static function malformed_run_request_provider(): array {
        $item = ['kind' => 'index', 'size' => 1, 'sha256' => self::SHA];
        $urls = self::RUN . '/upload-urls';
        return [
            'start with another kind' => ['/runs', '{"kind":"partial"}'],
            'start with a field more' => ['/runs', '{"kind":"full","x":1}'],
            'start with no body' => ['/runs', ''],
            'capacity as text' => [self::RUN . '/capacity', '{"estimated_bytes":"5"}'],
            'capacity below zero' => [self::RUN . '/capacity', '{"estimated_bytes":-5}'],
            'capacity with a field more' => [self::RUN . '/capacity', '{"estimated_bytes":5,"x":1}'],
            'urls without items' => [$urls, '{"items":[]}'],
            'urls with 257 items' => [$urls, json_encode(['items' => array_fill(0, 257, $item)])],
            'urls with an item more' => [$urls, json_encode(['items' => [$item + ['x' => 1]]])],
            'urls of an object without a name' => [$urls, json_encode(['items' => [['kind' => 'object'] + $item]])],
            'urls with a short name' => [$urls, json_encode(['items' => [['kind' => 'object', 'name' => 'ab'] + $item]])],
            'urls with a digest in hexadecimal' => [$urls, json_encode(['items' => [['sha256' => 'ab'] + $item]])],
            'urls as a list of items only' => [$urls, json_encode([$item])],
            'complete without stats' => [self::RUN . '/complete', json_encode(['manifest' => [], 'index' => []])],
            'complete with other keys' => [self::RUN . '/complete', json_encode(['manifest' => ['sha256' => 'a'], 'index' => [],
                'stats' => []])],
            'fail with an unknown reason' => [self::RUN . '/fail', '{"code":"expired"}'],
            'fail with a field more' => [self::RUN . '/fail', '{"code":"disk_low","x":1}'],
            'a list as the body' => [self::RUN . '/fail', '[1]'],
            'core manifest with a short build' => ['/core-manifests', '{"version":"2024100703.10","build":"2025"}'],
            'core manifest with a long version' => ['/core-manifests', '{"version":"2024100703.100","build":"20250113"}'],
            'core manifest with a field more' => ['/core-manifests', '{"version":"2024100703.10","build":"20250113","x":1}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_run_request_provider')]
    /**
     * The service refuses a request body that is not in its shape.
     *
     * @dataProvider malformed_run_request_provider
     * @param string $path Path after the API prefix.
     * @param string $body Body.
     */
    public function test_a_run_request_in_a_wrong_shape_is_refused(string $path, string $body): void {
        [$service, $secret] = self::enrolled();
        $service->state['runs']['aaaaaaaa-bbbb-4ccc-8ddd-000000000001'] = ['status' => 'uploading', 'kind' => 'full',
            'failure_code' => null, 'receipt' => null, 'estimated' => 100, 'reserved' => 0, 'started' => self::NOW,
            'manifest' => null, 'index' => null, 'storedbytes' => 0];
        $answer = self::signed($service, $secret, 'POST', $path, $body);
        $this->assertSame([422, 'invalid_request'], [$answer[0], self::code($answer)]);
    }

    public function test_upload_urls_follow_the_decision_on_the_size(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        $item = static fn(int $size): string => json_encode(['items' => [['kind' => 'index', 'size' => $size,
            'sha256' => self::SHA]]]);
        $urls = self::RUN . '/upload-urls';
        // No decision yet.
        $answer = self::signed($service, $secret, 'POST', $urls, $item(10));
        $error = $answer[2]['error'];
        // The figure is what was granted so far: the refused batch is not in it.
        $this->assertSame(['capacity_recheck_required', 0, null], [$error['code'], $error['requested_bytes'],
            $error['estimated_bytes']]);
        // A refused decision is no decision.
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":2000000000000}');
        $this->assertSame(409, self::signed($service, $secret, 'POST', $urls, $item(10))[0]);
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        // 110% of the estimate is the most that is given: 110 bytes pass, 111 do not.
        $this->assertSame(200, self::signed($service, $secret, 'POST', $urls, $item(110))[0]);
        $answer = self::signed($service, $secret, 'POST', $urls, $item(1));
        $this->assertSame([409, 110, 100], [$answer[0], $answer[2]['error']['requested_bytes'],
            $answer[2]['error']['estimated_bytes']]);
    }

    public function test_the_headers_of_an_upload_url_are_the_five_of_the_contract_and_an_object_has_a_sixth(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        $name = str_repeat('a', 64);
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', json_encode(['items' => [
            ['kind' => 'object', 'name' => $name, 'size' => 7, 'sha256' => self::SHA]]]));
        $item = $answer[2]['items'][0];
        $this->assertSame(['kind', 'name', 'url', 'headers', 'expires_at'], array_keys($item));
        $this->assertSame(
            ['Content-Length' => '7', 'x-amz-checksum-sha256' => self::SHA, 'x-amz-storage-class' => 'STANDARD',
            'x-amz-object-lock-mode' => 'COMPLIANCE', 'x-amz-object-lock-retain-until-date' => '2025-11-07T00:00:00Z',
            'If-None-Match' => '*'],
            $item['headers']
        );
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', json_encode(['items' => [
            ['kind' => 'manifest', 'size' => 7, 'sha256' => self::SHA]]]));
        $this->assertCount(5, $answer[2]['items'][0]['headers']);
        $this->assertArrayNotHasKey('If-None-Match', $answer[2]['items'][0]['headers']);
        $this->assertSame('https://app.example.test/_s3/sites/' . fake_service::SITE_ID . '/objects/' . $name
            . '?X-Amz-Expires=900&X-Amz-Signature=0123456789abcdef', $item['url']);
    }

    public function test_calls_that_the_state_of_the_run_does_not_allow_are_refused_with_the_status(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/fail', '{"code":"disk_low"}');
        foreach (['/capacity' => '{"estimated_bytes":5}', '/fail' => '{"code":"disk_low"}'] as $suffix => $body) {
            $answer = self::signed($service, $secret, 'POST', self::RUN . $suffix, $body);
            $this->assertSame([409, 'run_state_invalid', 'failed'], [$answer[0], self::code($answer),
                $answer[2]['error']['status']]);
        }
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', json_encode(['items' => [['kind' => 'index',
            'size' => 1, 'sha256' => self::SHA]]]));
        $this->assertSame([409, 'run_state_invalid'], [$answer[0], self::code($answer)]);
        // A new run can start after a failed one.
        $this->assertSame(201, self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}')[0]);
    }

    public function test_unknown_runs_and_paths(): void {
        [$service, $secret] = self::enrolled();
        $unknown = '/runs/aaaaaaaa-bbbb-4ccc-8ddd-000000000009';
        $answer = self::signed($service, $secret, 'GET', $unknown);
        $this->assertSame([404, 'run_not_found'], [$answer[0], self::code($answer)]);
        $this->assertSame(404, self::signed($service, $secret, 'POST', $unknown . '/capacity', '{"estimated_bytes":5}')[0]);
        $this->assertSame(404, self::signed($service, $secret, 'GET', '/runs/not-a-run')[0]);
        $this->assertSame(404, self::signed($service, $secret, 'GET', '/runs')[0]);
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        $this->assertSame(404, self::signed($service, $secret, 'POST', self::RUN, '{}')[0]);
        $this->assertSame(404, self::signed($service, $secret, 'GET', self::RUN . '/capacity')[0]);
    }

    /**
     * The error modes, which answer every request with their error.
     *
     * @return array
     */
    public static function mode_provider(): array {
        return [
            ['run_in_progress', 409, 'run_id'],
            ['run_limit_reached', 429, 'retry_after'],
            ['run_not_found', 404, null],
            ['run_state_invalid', 409, 'status'],
            ['capacity_recheck_required', 409, 'requested_bytes'],
            ['artifact_signature_invalid', 422, 'artifact'],
            ['core_manifest_not_found', 404, null],
            ['contract_inactive', 403, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mode_provider')]
    /**
     * Each new mode gives the error of its name with the details of the contract.
     *
     * @dataProvider mode_provider
     * @param string $mode The mode.
     * @param int $status HTTP status.
     * @param string|null $detail A detail that the error carries.
     */
    public function test_the_new_modes(string $mode, int $status, ?string $detail): void {
        [$service] = self::enrolled();
        $service->state['mode'] = $mode;
        $answer = $service->handle('POST', 'app.example.test', self::BASE . '/runs', [], '');
        $this->assertSame($status, $answer[0]);
        $error = json_decode($answer[2], true)['error'];
        $this->assertSame($mode, $error['code']);
        $this->assertSame($detail === null, !array_key_exists((string) $detail, $error));
    }

    public function test_the_contract_can_end_in_the_middle_of_a_run(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        $service->state['contractinactive'] = true;
        $urls = json_encode(['items' => [['kind' => 'index', 'size' => 1, 'sha256' => self::SHA]]]);
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', $urls);
        $this->assertSame([403, 'contract_inactive'], [$answer[0], self::code($answer)]);
        $answer = self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        $this->assertSame([403, 'contract_inactive'], [$answer[0], self::code($answer)]);
        // The other calls are not about the contract.
        $this->assertSame(200, self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}')[0]);
        $this->assertSame(200, self::signed($service, $secret, 'POST', self::RUN . '/fail', '{"code":"contract_inactive"}')[0]);
    }

    public function test_a_body_in_a_wrong_shape_is_refused_before_the_contract_is_looked_at(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        $service->state['contractinactive'] = true;
        $answer = self::signed($service, $secret, 'POST', '/runs', '{"kind":"partial"}');
        $this->assertSame([422, 'invalid_request'], [$answer[0], self::code($answer)]);
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', '{"items":[]}');
        $this->assertSame([422, 'invalid_request'], [$answer[0], self::code($answer)]);
    }

    public function test_complete_needs_a_decided_estimate(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        $urls = json_encode(['items' => [['kind' => 'index', 'size' => 1, 'sha256' => self::SHA]]]);
        $this->assertSame(200, self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', $urls)[0]);
        // A refused decision is no decision: the report is a state error, as it is when the run is not uploading.
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":2000000000000}');
        $body = json_encode([
            'manifest' => ['sha256' => self::SHA, 'size' => 1, 'signature' => 'x'],
            'index' => ['sha256' => self::SHA, 'size' => 1, 'count' => 0, 'signature' => 'x'],
            'stats' => [],
        ]);
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/complete', $body);
        $this->assertSame([409, 'run_state_invalid', 'uploading'], [$answer[0], self::code($answer),
            $answer[2]['error']['status']]);
    }

    public function test_the_fake_storage_refuses_a_put_without_a_header_that_its_url_was_signed_with(): void {
        [$service, $secret] = self::enrolled();
        self::signed($service, $secret, 'POST', '/runs', '{"kind":"full"}');
        self::signed($service, $secret, 'POST', self::RUN . '/capacity', '{"estimated_bytes":100}');
        $name = str_repeat('b', 64);
        $body = 'content';
        $answer = self::signed($service, $secret, 'POST', self::RUN . '/upload-urls', json_encode(['items' => [
            ['kind' => 'object', 'name' => $name, 'size' => 7, 'sha256' => base64_encode(hash('sha256', $body, true))]]]));
        $signed = array_change_key_case($answer[2]['items'][0]['headers'], CASE_LOWER);
        $path = '/_s3/sites/' . fake_service::SITE_ID . '/objects/' . $name;
        foreach (array_keys($signed) as $left) {
            $headers = $signed;
            unset($headers[$left]);
            $refused = $service->handle('PUT', 'app.example.test', $path, $headers, $body);
            $this->assertSame(400, $refused[0], $left);
            $this->assertStringContainsString('SignatureDoesNotMatch', $refused[2], $left);
        }
        // Another value than the signed one is refused too; the signed headers as they are pass.
        $this->assertSame(400, $service->handle(
            'PUT',
            'app.example.test',
            $path,
            ['x-amz-storage-class' => 'GLACIER'] + $signed,
            $body
        )[0]);
        $this->assertSame(200, $service->handle('PUT', 'app.example.test', $path, $signed, $body)[0]);
    }

    public function test_the_fake_storage_keeps_an_object_that_is_stored_when_the_put_is_conditional(): void {
        $service = self::service();
        $put = static fn(string $body, array $more): array => $service->handle('PUT', 'app.example.test', '/_s3/k', [
            'content-length' => (string) strlen($body),
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', $body, true)),
            'x-amz-object-lock-mode' => 'COMPLIANCE',
            'x-amz-object-lock-retain-until-date' => '2025-11-07T00:00:00Z',
        ] + $more, $body);
        $this->assertSame(200, $put('first', ['if-none-match' => '*'])[0]);
        $answer = $put('second', ['if-none-match' => '*']);
        $this->assertSame(412, $answer[0]);
        $this->assertStringContainsString('PreconditionFailed', $answer[2]);
        $this->assertSame('first', base64_decode($service->state['objects']['k']));
        // A PUT that is not conditional replaces it, and another value of the header is not "*".
        $this->assertSame(400, $put('third', ['if-none-match' => '"x"'])[0]);
        $this->assertSame(200, $put('third', [])[0]);
        $this->assertSame('third', base64_decode($service->state['objects']['k']));
    }
}
