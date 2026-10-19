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

use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\endpoint\endpoint_exception;
use tool_secbackup\local\net\object_url;
use tool_secbackup\local\net\response;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\sec_trust;

/**
 * Calls the service's API: enrolment, status, and the calls of a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client {
    /** @var int Clock difference, in seconds, from which the pages warn. The service refuses more than 300. */
    public const CLOCK_SKEW_WARNING = 120;

    /** @var int Clock difference, in seconds, that the service accepts. */
    private const CLOCK_TOLERANCE = 300;

    /** @var string[] Error codes of the service that have their own message. */
    private const KNOWN_ERRORS = ['invalid_request', 'signature_required', 'signature_invalid', 'timestamp_out_of_range',
        'nonce_reused', 'enrollment_code_invalid', 'site_not_enrolled', 'ip_not_allowed', 'encryption_key_mismatch',
        'wwwroot_mismatch', 'body_too_large', 'run_in_progress', 'run_limit_reached', 'run_not_found', 'run_state_invalid',
        'capacity_recheck_required', 'artifact_signature_invalid', 'core_manifest_not_found', 'contract_inactive'];

    /** @var string[] Reasons with which a run can be stopped (the contract's list of 14). */
    public const FAIL_CODES = ['server_busy', 'disk_low', 'db_slow', 'nfs_slow', 'selinux_denied', 'capacity_refused',
        'network_check_failed', 'signature_invalid', 'tamper_detected', 'version_too_old', 'upload_failed', 'window_ended',
        'restarted', 'contract_inactive'];

    /** @var string[] The five headers that an upload URL binds, in lower case. */
    private const UPLOAD_HEADERS = ['content-length', 'x-amz-checksum-sha256', 'x-amz-object-lock-mode',
        'x-amz-object-lock-retain-until-date', 'x-amz-storage-class'];

    /** @var int Largest whole number that a JSON number of the service holds exactly (2^53 - 1). */
    private const MAX_EXACT = 9007199254740991;

    /** @var int Most items in one request for upload URLs. */
    private const MAX_UPLOAD_ITEMS = 256;

    /** @var int Largest time, in UNIX seconds, that is taken from an answer: 12 digits, as the service reads a timestamp. */
    private const MAX_TIME = 999999999999;

    /** @var string Pattern of a site ID. It ends with \z: $ also matches before a final line feed. */
    private const UUID = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

    /** @var transport Transport. */
    private transport $transport;

    /** @var sec_trust Trusted response keys. */
    private sec_trust $trust;

    /** @var developer_settings Developer settings. */
    private developer_settings $settings;

    /** @var callable Returns the current UNIX time. */
    private $clock;

    /**
     * Constructor.
     *
     * @param transport $transport Transport.
     * @param sec_trust $trust Trusted response keys.
     * @param developer_settings $settings Developer settings.
     * @param callable|null $clock Returns the current UNIX time; null for time().
     */
    public function __construct(transport $transport, sec_trust $trust, developer_settings $settings, ?callable $clock = null) {
        $this->transport = $transport;
        $this->trust = $trust;
        $this->settings = $settings;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * The client of this site.
     *
     * @return self
     */
    public static function create(): self {
        return new self(transport::create(), sec_trust::current(), developer_settings::current());
    }

    /**
     * Enrols this site.
     *
     * The types of $pair and $request are written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()). The attributes keep the key pair, and the request with the registration code, which
     * is still valid, out of the trace of an exception thrown here or in a call made from here.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The new key pair (not saved yet).
     * @param #[|\SensitiveParameter|]|enrollment_request $request Request body.
     * @return enroll_result The verified answer.
     * @throws api_exception|\tool_secbackup\local\trust\envelope_exception|\tool_secbackup\local\net\transport_exception
     */
    public function enroll(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        #[\SensitiveParameter] enrollment_request $request
    ): enroll_result {
        $now = ($this->clock)();
        $nonce = request_signer::new_nonce();
        $body = $request->body($pair);
        $headers = request_signer::headers($pair, 'POST', $endpoint, 'enroll', $body, null, $now, $nonce);
        $response = $this->transport->send('POST', $endpoint, 'enroll', $headers, $body);
        if ($response->status !== 201) {
            throw self::error($response, $now);
        }
        $payload = $this->trust->open($response->body);
        $fields = ['type', 'site_id', 'site_name', 'platform_name', 'encryption_key_fingerprint', 'signing_key_fingerprint',
            'request_nonce', 'enrolled_at', 'api_base'];
        foreach ($fields as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new api_exception('enroll_response_invalid', '', 201, null, null, null, $field);
            }
        }
        $this->check_binding($payload, $endpoint, $pair, $request, $nonce);
        if (preg_match(self::UUID, $payload['site_id']) !== 1) {
            throw new api_exception('enroll_response_invalid', '', 201, null, null, null, 'site_id');
        }
        $sitename = self::display_text($payload['site_name'], 255, 'site_name');
        $platformname = self::display_text($payload['platform_name'], 100, 'platform_name');
        $enrolledat = self::zulu_time($payload['enrolled_at']);
        return new enroll_result($payload['site_id'], $sitename, $platformname, $endpoint->url(), $enrolledat, $enrolledat - $now);
    }

    /**
     * Checks that a signed enrolment answer belongs to this request (and not to another one replayed here).
     *
     * The types of $pair and $request are written as in enroll().
     *
     * @param array $payload The answer.
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The key pair being enrolled.
     * @param #[|\SensitiveParameter|]|enrollment_request $request The request.
     * @param string $nonce The nonce sent.
     * @throws api_exception enroll_response_mismatch, naming the field in the debug information.
     */
    private function check_binding(
        array $payload,
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        #[\SensitiveParameter] enrollment_request $request,
        string $nonce
    ): void {
        try {
            $apibase = endpoint::parse($payload['api_base'], $this->settings);
        } catch (endpoint_exception $e) {
            $apibase = null;
        }
        $checks = [
            'type' => $payload['type'] === 'enrollment',
            'request_nonce' => hash_equals($nonce, $payload['request_nonce']),
            'api_base' => $apibase !== null && $apibase->equals($endpoint),
            'signing_key_fingerprint' =>
                fingerprint::normalize($payload['signing_key_fingerprint']) === fingerprint::of($pair->public_key()),
            'encryption_key_fingerprint' =>
                fingerprint::normalize($payload['encryption_key_fingerprint']) === $request->encryption_fingerprint(),
        ];
        foreach ($checks as $field => $ok) {
            if (!$ok) {
                throw new api_exception('enroll_response_mismatch', '', 201, null, null, null, $field);
            }
        }
    }

    /**
     * Asks the service for this site's status.
     *
     * The type of $pair is written as in enroll(): the attribute keeps the key pair out of the trace of an exception.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @return status_result
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function status(endpoint $endpoint, #[\SensitiveParameter] signing_keypair $pair, string $siteid): status_result {
        $now = ($this->clock)();
        $headers = request_signer::headers($pair, 'GET', $endpoint, 'status', '', $siteid, $now, request_signer::new_nonce());
        $response = $this->transport->send('GET', $endpoint, 'status', $headers, '');
        if ($response->status !== 200) {
            throw self::error($response, $now);
        }
        $data = json_decode($response->body, true, 8);
        // The status is shown on pages, so its pattern ends with \z: $ also matches before a final line feed.
        if (
            !is_array($data)
            || ($data['site_id'] ?? null) !== $siteid
            || !is_string($data['status'] ?? null)
            || preg_match('/^[a-z_]{1,40}\z/', $data['status']) !== 1
            || self::unix_time($data['server_time'] ?? null) === null
            || !is_array($data['instructions'] ?? null)
        ) {
            throw new api_exception('status_response_invalid', '', 200);
        }
        return new status_result($data['status'], $data['server_time'], $data['server_time'] - $now, $data['instructions']);
    }

    /**
     * Fetches the list of AWS address ranges that the service published.
     *
     * The answer is not signed. The caller checks it against the SHA-256 and the size that a signed instruction names.
     *
     * The type of $pair is written as in enroll().
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @return string The content, as it came.
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function aws_ranges(endpoint $endpoint, #[\SensitiveParameter] signing_keypair $pair, string $siteid): string {
        $now = ($this->clock)();
        $headers = request_signer::headers($pair, 'GET', $endpoint, 'aws-ranges', '', $siteid, $now, request_signer::new_nonce());
        $response = $this->transport->send('GET', $endpoint, 'aws-ranges', $headers, '');
        if ($response->status !== 200) {
            throw self::error($response, $now);
        }
        return $response->body;
    }

    /**
     * Starts a run.
     *
     * The types of $pair in the calls of a run are written as in enroll().
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $kind Kind of the run; only full for now.
     * @return run_start The verified answer.
     * @throws api_exception|\tool_secbackup\local\trust\envelope_exception|\tool_secbackup\local\net\transport_exception
     */
    public function start_run(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $kind = 'full'
    ): run_start {
        if (preg_match('/^[a-z_]{1,20}\z/', $kind) !== 1) {
            throw new \coding_exception('A kind of run is a lower case word.');
        }
        $response = $this->call($endpoint, $pair, $siteid, 'POST', 'runs', ['kind' => $kind], 201);
        $payload = $this->trust->open($response->body);
        foreach (['type', 'site_id', 'run_id', 'kind', 'started_at', 'present_url', 'present_url_expires_at'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw self::invalid(201, $field);
            }
        }
        $checks = [
            'type' => $payload['type'] === 'run',
            'site_id' => $payload['site_id'] === $siteid,
            'kind' => $payload['kind'] === $kind,
            'run_id' => preg_match(self::UUID, $payload['run_id']) === 1,
        ];
        foreach ($checks as $field => $ok) {
            if (!$ok) {
                throw self::invalid(201, $field);
            }
        }
        $startedat = self::zulu_field($payload['started_at'], 'started_at', 201);
        $expiresat = self::zulu_field($payload['present_url_expires_at'], 'present_url_expires_at', 201);
        if ($expiresat <= ($this->clock)()) {
            throw self::invalid(201, 'present_url_expires_at');
        }
        $presenturl = object_url::parse($payload['present_url'], $this->settings);
        return new run_start($payload['run_id'], $presenturl, $startedat, $expiresat);
    }

    /**
     * Asks the service whether a run of this expected size may go on.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @param int $estimatedbytes Size the run expects to store.
     * @return capacity_result
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function capacity(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        int $estimatedbytes
    ): capacity_result {
        self::check_run_id($runid);
        if ($estimatedbytes < 0 || $estimatedbytes > self::MAX_EXACT) {
            throw new \coding_exception('An estimate is not below zero, and not above 2^53 - 1.');
        }
        $response = $this->call(
            $endpoint,
            $pair,
            $siteid,
            'POST',
            'runs/' . $runid . '/capacity',
            ['estimated_bytes' => $estimatedbytes],
            200
        );
        $data = self::exact_object($response, ['decision', 'limit_bytes', 'used_bytes']);
        if (!in_array($data['decision'], ['continue', 'refuse'], true)) {
            throw self::invalid(200, 'decision');
        }
        $limit = $data['limit_bytes'];
        if ($limit !== null && self::count_of($limit) === null) {
            throw self::invalid(200, 'limit_bytes');
        }
        $used = self::count_of($data['used_bytes']);
        if ($used === null) {
            throw self::invalid(200, 'used_bytes');
        }
        return new capacity_result($data['decision'] === 'continue', $limit, $used);
    }

    /**
     * Asks for the URLs to which objects are sent.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @param array $items From 1 to 256 items, each with kind (object, manifest or index), name (a chunk name for an object,
     *        null for the others), size and sha256 (standard base64 of the SHA-256 of the content).
     * @return upload_target[] One for each item, in the same order.
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function upload_urls(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        array $items
    ): array {
        self::check_run_id($runid);
        $request = self::upload_request($items);
        $response = $this->call($endpoint, $pair, $siteid, 'POST', 'runs/' . $runid . '/upload-urls', ['items' => $request], 200);
        $data = self::exact_object($response, ['items']);
        if (!is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) !== count($items)) {
            throw self::invalid(200, 'items');
        }
        $now = ($this->clock)();
        $targets = [];
        foreach (array_values($items) as $i => $item) {
            $targets[] = $this->upload_target($data['items'][$i], $item, $now);
        }
        return $targets;
    }

    /**
     * Reports that all objects are sent, with the signed manifest and index and the figures of the run.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @param array $manifest sha256, size and signature of the manifest.
     * @param array $index sha256, size, count and signature of the index.
     * @param array $stats The figures of the run.
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     * @throws \coding_exception When the request is larger than the service accepts.
     */
    public function complete(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        array $manifest,
        array $index,
        array $stats
    ): void {
        self::check_run_id($runid);
        $response = $this->call(
            $endpoint,
            $pair,
            $siteid,
            'POST',
            'runs/' . $runid . '/complete',
            ['manifest' => $manifest, 'index' => $index, 'stats' => $stats],
            202
        );
        $data = self::exact_object($response, ['status']);
        if ($data['status'] !== 'verifying') {
            throw self::invalid(202, 'status');
        }
    }

    /**
     * Stops a run.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @param string $code Why the run stops: one of FAIL_CODES.
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function fail(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        string $code
    ): void {
        self::check_run_id($runid);
        if (!in_array($code, self::FAIL_CODES, true)) {
            throw new \coding_exception('Unknown reason for stopping a run.');
        }
        $response = $this->call($endpoint, $pair, $siteid, 'POST', 'runs/' . $runid . '/fail', ['code' => $code], 200);
        $data = self::exact_object($response, ['status']);
        if ($data['status'] !== 'failed') {
            throw self::invalid(200, 'status');
        }
    }

    /**
     * Asks for the state of a run, which carries the receipt when the run is verified.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @return run_state
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    public function run_state(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid
    ): run_state {
        self::check_run_id($runid);
        $response = $this->call($endpoint, $pair, $siteid, 'GET', 'runs/' . $runid, null, 200);
        $data = self::exact_object($response, ['run_id', 'status', 'failure_code', 'receipt']);
        if ($data['run_id'] !== $runid) {
            throw self::invalid(200, 'run_id');
        }
        // The patterns end with \z: $ also matches before a final line feed.
        if (!is_string($data['status']) || preg_match('/^[a-z_]{1,40}\z/', $data['status']) !== 1) {
            throw self::invalid(200, 'status');
        }
        if (
            $data['failure_code'] !== null && (!is_string($data['failure_code'])
                || preg_match('/^[a-z_]{1,64}\z/', $data['failure_code']) !== 1)
        ) {
            throw self::invalid(200, 'failure_code');
        }
        if ($data['receipt'] !== null && (!is_array($data['receipt']) || array_is_list($data['receipt']))) {
            throw self::invalid(200, 'receipt');
        }
        return new run_state($data['status'], $data['failure_code'], $data['receipt']);
    }

    /**
     * Asks where the list of the official files of a Moodle build is read, and what the list must be.
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $version Moodle version, such as 2024100703.10.
     * @param string $build Moodle build, such as 20250113.
     * @return core_manifest_ref|null Null when the service has no list for this build.
     * @throws api_exception|\tool_secbackup\local\trust\envelope_exception|\tool_secbackup\local\net\transport_exception
     */
    public function core_manifest(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $version,
        string $build
    ): ?core_manifest_ref {
        if (preg_match('/^\d{10}\.\d{2}\z/', $version) !== 1 || preg_match('/^\d{8}\z/', $build) !== 1) {
            throw new \coding_exception('A Moodle version has 10 digits, a dot and 2 digits; a build has 8 digits.');
        }
        try {
            $response = $this->call(
                $endpoint,
                $pair,
                $siteid,
                'POST',
                'core-manifests',
                ['version' => $version, 'build' => $build],
                200
            );
        } catch (api_exception $e) {
            if ($e->servercode === 'core_manifest_not_found' && $e->httpstatus === 404) {
                return null;
            }
            throw $e;
        }
        $data = self::exact_object($response, ['manifest', 'url', 'url_expires_at']);
        if (!is_array($data['manifest']) || !is_string($data['url']) || !is_string($data['url_expires_at'])) {
            throw self::invalid(200, 'manifest');
        }
        $payload = $this->trust->open_envelope($data['manifest']);
        $checks = [
            'type' => ($payload['type'] ?? null) === 'core_manifest',
            'version' => ($payload['version'] ?? null) === $version,
            'build' => ($payload['build'] ?? null) === $build,
            'sha256' => self::is_sha256($payload['sha256'] ?? null),
            'size' => self::count_of($payload['size'] ?? null) !== null,
            'count' => self::count_of($payload['count'] ?? null) !== null,
            'published_at' => is_string($payload['published_at'] ?? null),
        ];
        foreach ($checks as $field => $ok) {
            if (!$ok) {
                throw self::invalid(200, $field);
            }
        }
        self::zulu_field($payload['published_at'], 'published_at', 200);
        if (self::zulu_field($data['url_expires_at'], 'url_expires_at', 200) <= ($this->clock)()) {
            throw self::invalid(200, 'url_expires_at');
        }
        return new core_manifest_ref(
            $payload['sha256'],
            $payload['size'],
            $payload['count'],
            object_url::parse($data['url'], $this->settings)
        );
    }

    /**
     * Signs and sends one request of a run, and returns the answer if it has the status that was expected.
     *
     * The type of $pair is written as in enroll().
     *
     * @param endpoint $endpoint Connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $siteid The site's ID.
     * @param string $method GET or POST.
     * @param string $relative Name of the API call, such as "runs".
     * @param array|null $payload The body as it is encoded to JSON, or null for none.
     * @param int $expected The HTTP status of a success.
     * @return response
     * @throws api_exception|\tool_secbackup\local\net\transport_exception
     */
    private function call(
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $method,
        string $relative,
        ?array $payload,
        int $expected
    ): response {
        $now = ($this->clock)();
        $body = '';
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($body === false) {
                throw new \coding_exception('The body of a request cannot be encoded.');
            }
        }
        $headers = request_signer::headers($pair, $method, $endpoint, $relative, $body, $siteid, $now, request_signer::new_nonce());
        $response = $this->transport->send($method, $endpoint, $relative, $headers, $body);
        if ($response->status !== $expected) {
            throw self::error($response, $now);
        }
        return $response;
    }

    /**
     * Reads the body of an answer that is a JSON object with exactly these fields.
     *
     * @param response $response The answer.
     * @param string[] $fields The names of the fields.
     * @return array The object.
     * @throws api_exception When the body is not such an object.
     */
    private static function exact_object(response $response, array $fields): array {
        $data = json_decode($response->body, true, 8);
        if (!is_array($data) || array_is_list($data)) {
            throw self::invalid($response->status, 'body');
        }
        $names = array_keys($data);
        sort($names);
        sort($fields);
        if ($names !== $fields) {
            throw self::invalid($response->status, 'fields');
        }
        return $data;
    }

    /**
     * An answer that cannot be used.
     *
     * @param int $status HTTP status of the answer.
     * @param string $field The field that is wrong, for the debug information.
     * @return api_exception
     */
    private static function invalid(int $status, string $field): api_exception {
        return new api_exception('api_response_invalid', '', $status, null, null, null, $field);
    }

    /**
     * A whole number of bytes or items.
     *
     * @param mixed $value The value.
     * @return int|null The number, or null when the value is not an integer from zero.
     */
    private static function count_of(mixed $value): ?int {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * Checks that a run ID can be put into a path.
     *
     * @param string $runid The run's ID.
     * @throws \coding_exception When it is not a lower case UUID.
     */
    private static function check_run_id(string $runid): void {
        if (preg_match(self::UUID, $runid) !== 1) {
            throw new \coding_exception('A run ID is a lower case UUID.');
        }
    }

    /**
     * Whether a value is the standard base64 of 32 bytes, written the one way that base64 has.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_sha256(mixed $value): bool {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9+\/]{43}=\z/', $value) !== 1) {
            return false;
        }
        return base64_encode((string) base64_decode($value, true)) === $value;
    }

    /**
     * Checks the items of a request for upload URLs and puts them in the form the service reads.
     *
     * @param array $items The items.
     * @return array
     * @throws \coding_exception When an item is not in its shape.
     */
    private static function upload_request(array $items): array {
        if ($items === [] || count($items) > self::MAX_UPLOAD_ITEMS || !array_is_list($items)) {
            throw new \coding_exception('A request has from 1 to 256 items, in a list.');
        }
        $request = [];
        $seen = [];
        foreach ($items as $item) {
            // The name of a manifest or an index is null, and may be left out.
            if (is_array($item) && !array_key_exists('name', $item) && ($item['kind'] ?? null) !== 'object') {
                $item['name'] = null;
            }
            $keys = is_array($item) ? array_keys($item) : [];
            sort($keys);
            $kind = $item['kind'] ?? null;
            if (
                $keys !== ['kind', 'name', 'sha256', 'size']
                || !in_array($kind, ['object', 'manifest', 'index'], true)
                || !is_int($item['size']) || $item['size'] < 1 || $item['size'] > self::MAX_EXACT
                || !self::is_sha256($item['sha256'])
                || ($kind === 'object' ? !is_string($item['name']) || preg_match('/^[0-9a-f]{64}\z/', $item['name']) !== 1
                    : $item['name'] !== null)
            ) {
                throw new \coding_exception('An item of a request for upload URLs is not in its shape.');
            }
            $identity = $kind . '|' . (string) $item['name'];
            if (isset($seen[$identity])) {
                throw new \coding_exception('An object, the manifest or the index is asked for once in a request.');
            }
            $seen[$identity] = true;
            $entry = ['kind' => $kind];
            if ($kind === 'object') {
                $entry['name'] = $item['name'];
            }
            $request[] = $entry + ['size' => $item['size'], 'sha256' => $item['sha256']];
        }
        return $request;
    }

    /**
     * Checks one item of the answer for upload URLs against the item that was asked for.
     *
     * @param mixed $answer The item of the answer.
     * @param array $item The item of the request.
     * @param int $now This server's clock.
     * @return upload_target
     * @throws api_exception When the item does not match the request.
     */
    private function upload_target(mixed $answer, array $item, int $now): upload_target {
        $fields = ['kind', 'url', 'headers', 'expires_at'];
        if ($item['kind'] === 'object') {
            $fields[] = 'name';
        }
        $names = is_array($answer) ? array_keys($answer) : [];
        sort($names);
        sort($fields);
        if ($names !== $fields) {
            throw self::invalid(200, 'item');
        }
        if ($answer['kind'] !== $item['kind'] || ($item['kind'] === 'object' && $answer['name'] !== $item['name'])) {
            throw self::invalid(200, 'item');
        }
        if (!is_string($answer['url']) || !is_string($answer['expires_at'])) {
            throw self::invalid(200, 'url');
        }
        $expiresat = self::zulu_field($answer['expires_at'], 'expires_at', 200);
        if ($expiresat <= $now) {
            throw self::invalid(200, 'expires_at');
        }
        self::check_upload_headers($answer['headers'], $item);
        return new upload_target(
            $item['kind'],
            $item['kind'] === 'object' ? $item['name'] : null,
            object_url::parse($answer['url'], $this->settings),
            $answer['headers'],
            $expiresat
        );
    }

    /**
     * Checks that the headers of an upload URL are exactly the five that it binds (and, for an object, also the sixth
     * If-None-Match: *), and that the two that carry the request
     * are what was asked for.
     *
     * @param mixed $headers The headers of the answer.
     * @param array $item The item of the request.
     * @throws api_exception When they are not.
     */
    private static function check_upload_headers(mixed $headers, array $item): void {
        if (!is_array($headers) || array_is_list($headers)) {
            throw self::invalid(200, 'headers');
        }
        $lower = [];
        foreach ($headers as $name => $value) {
            if (!is_string($value)) {
                throw self::invalid(200, 'headers');
            }
            $lower[strtolower((string) $name)] = $value;
        }
        $expected = self::UPLOAD_HEADERS;
        // An object is bound to a sixth header, which is exactly "*": the storage then refuses to replace a stored object.
        // The manifest and the index are bound to the five only.
        if ($item['kind'] === 'object') {
            if (($lower['if-none-match'] ?? null) !== '*') {
                throw self::invalid(200, 'headers');
            }
            $expected[] = 'if-none-match';
            sort($expected);
        }
        $names = array_keys($lower);
        sort($names);
        // Two names that differ in case only would have been merged above, and then the count would not match.
        if (count($headers) !== count($expected) || $names !== $expected) {
            throw self::invalid(200, 'headers');
        }
        if ($lower['content-length'] !== (string) $item['size'] || $lower['x-amz-checksum-sha256'] !== $item['sha256']) {
            throw self::invalid(200, 'headers');
        }
    }

    /**
     * Reads a time such as 2026-10-12T03:04:05Z from a field of an answer.
     *
     * @param string $value The time.
     * @param string $field The field, for the debug information.
     * @param int $status HTTP status of the answer.
     * @return int UNIX time.
     * @throws api_exception When it is not such a time.
     */
    private static function zulu_field(string $value, string $field, int $status): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw self::invalid($status, $field);
        }
        return $time->getTimestamp();
    }

    /**
     * Turns an error answer into an exception with the plugin's own message.
     *
     * @param response $response The answer.
     * @param int $now This server's clock when the request was signed.
     * @return api_exception
     */
    private static function error(response $response, int $now): api_exception {
        $data = json_decode($response->body, true, 8);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        // The pattern ends with \z: $ also matches before a final line feed, which the exception would keep.
        $code = is_string($error['code'] ?? null) && preg_match('/^[a-z_]{1,64}\z/', $error['code']) === 1 ? $error['code'] : '';
        if ($code === 'run_limit_reached' && $response->status === 429) {
            // The header is preferred; the body gives the same figure when a proxy dropped the header.
            $seconds = $response->retryafter ?? (is_int($error['retry_after'] ?? null) && $error['retry_after'] >= 0
                && $error['retry_after'] <= 86400 ? $error['retry_after'] : null);
            return new api_exception('api_run_limit_reached', $code, 429, $seconds);
        }
        if ($response->status === 429 || $response->status === 503) {
            return new api_exception(
                $response->status === 429 ? 'api_rate_limited' : 'api_unavailable',
                $code,
                $response->status,
                $response->retryafter
            );
        }
        if (!in_array($code, self::KNOWN_ERRORS, true)) {
            return new api_exception('api_unexpected', '', $response->status, $response->retryafter, null, $response->status);
        }
        $servertime = self::unix_time($error['server_time'] ?? null);
        // Under the service's tolerance the clocks do not explain the refusal, so the message does not name minutes.
        if ($code === 'timestamp_out_of_range' && $servertime !== null && abs($servertime - $now) >= self::CLOCK_TOLERANCE) {
            return new api_exception(
                'api_timestamp_out_of_range_minutes',
                $code,
                $response->status,
                null,
                $servertime,
                (int) round(abs($servertime - $now) / 60)
            );
        }
        $ip = is_string($error['ip'] ?? null) ? filter_var($error['ip'], FILTER_VALIDATE_IP) : false;
        if ($code === 'ip_not_allowed' && $ip !== false) {
            return new api_exception('api_ip_not_allowed_address', $code, $response->status, null, null, $ip);
        }
        $runid = $code === 'run_in_progress' && is_string($error['run_id'] ?? null)
            && preg_match(self::UUID, $error['run_id']) === 1 ? $error['run_id'] : null;
        $runstatus = $code === 'run_state_invalid' && is_string($error['status'] ?? null)
            && preg_match('/^[a-z_]{1,40}\z/', $error['status']) === 1 ? $error['status'] : null;
        $recheck = $code === 'capacity_recheck_required';
        return new api_exception(
            'api_' . $code,
            $code,
            $response->status,
            $response->retryafter,
            $servertime,
            null,
            null,
            $runid,
            $runstatus,
            $recheck ? self::count_of($error['requested_bytes'] ?? null) : null,
            $recheck ? self::count_of($error['estimated_bytes'] ?? null) : null
        );
    }

    /**
     * A time that the service gave, if it can be one.
     *
     * The answers that give a time are not signed, and the times are subtracted from this server's clock and shown, so a
     * value that no clock gives (negative, or beyond the 12 digits of a timestamp) is not taken.
     *
     * @param mixed $value The value in the answer.
     * @return int|null UNIX time, or null when the value is not a whole number from 0 to MAX_TIME.
     */
    private static function unix_time(mixed $value): ?int {
        return is_int($value) && $value >= 0 && $value <= self::MAX_TIME ? $value : null;
    }

    /**
     * Checks a name to be shown on the pages.
     *
     * @param string $value The name.
     * @param int $max Longest length, in characters.
     * @param string $field Field name for the debug information.
     * @return string The name without surrounding white space.
     * @throws api_exception When the name is empty, too long or contains control characters.
     */
    private static function display_text(string $value, int $max, string $field): string {
        $text = trim($value);
        if ($text === '' || \core_text::strlen($text) > $max || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $text) !== 0) {
            throw new api_exception('enroll_response_invalid', '', 201, null, null, null, $field);
        }
        return $text;
    }

    /**
     * Reads a time such as 2026-10-12T03:04:05Z.
     *
     * @param string $value The time.
     * @return int UNIX time.
     * @throws api_exception When it is not such a time.
     */
    private static function zulu_time(string $value): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new api_exception('enroll_response_invalid', '', 201, null, null, null, 'enrolled_at');
        }
        return $time->getTimestamp();
    }
}
