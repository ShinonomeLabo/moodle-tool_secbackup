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

namespace tool_secbackup\tests;

/**
 * A stand-in for the backup service's enrolment and status API.
 *
 * It is written without the plugin's classes and without Moodle functions, so that it checks the
 * plugin's requests independently, and so that the development server in .dev/fakesec can load it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_service {
    /** @var string The only valid registration code. */
    public const CODE = 'SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE';

    /** @var string Site ID given at enrolment. */
    public const SITE_ID = '11111111-2222-4333-8444-555555555555';

    /** @var string Site name given at enrolment. */
    public const SITE_NAME = 'Example site';

    /** @var string Platform name given at enrolment. */
    public const PLATFORM_NAME = 'Example Platform';

    /** @var string The site's encryption public key, as the platform shows it (32 bytes of 0x01). */
    public const ENCRYPTION_KEY = 'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';

    /** @var int Accepted clock difference, in seconds. */
    private const MAX_SKEW = 300;

    /** @var array Mutable state; see initial_state(). */
    public array $state;

    /** @var string Connection URL of the fake service. */
    private string $apibase;

    /** @var string Path of the connection URL. */
    private string $basepath;

    /** @var envelope_builder Response signer. */
    private envelope_builder $signer;

    /**
     * Constructor.
     *
     * @param string $apibase Connection URL, such as https://app.example.test/api/lms-backup/v1.
     * @param array|null $state State from initial_state(), possibly changed.
     * @param envelope_builder|null $signer Response signer; null for the RFC 8032 TEST 2 key.
     */
    public function __construct(string $apibase, ?array $state = null, ?envelope_builder $signer = null) {
        $this->apibase = rtrim($apibase, '/');
        $this->basepath = (string) parse_url($this->apibase, PHP_URL_PATH);
        $this->state = $state ?? self::initial_state();
        $this->signer = $signer ?? new envelope_builder();
    }

    /**
     * The state of a fresh service.
     *
     * - now: fixed UNIX time, or null for the real clock.
     * - mode: '' or one of unavailable, rate_limited, ip_not_allowed, unexpected, untrusted_key, bad_signature,
     *   site_removed, bad_status.
     * - enrollpayload: fields that replace those of the enrolment answer.
     * - sitename and platformname: the names the enrolment answer gives.
     * - encryptionkey: the site's encryption public key that the enrolment accepts (ENCRYPTION_KEY unless a test, or a
     *   development run that opens its backup, registers with a key pair of its own).
     * - instructions: the signed instruction envelopes that the status answer carries, by kind.
     * - awsranges: the content that GET /aws-ranges gives, or null for none.
     * - objects: the bodies that PUT /_s3/<key> stored, by key (base64, so that the state can be kept as JSON).
     * - signed: the headers that the last upload URL of a key was signed with, by key, with lower-case names.
     * - runs: the runs by ID; nextrun: counter of run IDs; holdverify: true to leave a completed run in verifying until
     *   verify_run() is called; receiptpayload: fields that replace those of the receipt; coremanifests: published lists.
     * - mode (more): run_in_progress, run_limit_reached, run_not_found, run_state_invalid, capacity_recheck_required,
     *   artifact_signature_invalid, core_manifest_not_found, contract_inactive answer every request with that error.
     * - urllife: seconds that an upload URL stays valid (900 in the contract).
     * - contractinactive: true to refuse only a new run and the upload URLs with contract_inactive (the contract ended).
     *
     * @return array
     */
    public static function initial_state(): array {
        return [
            'now' => null,
            'mode' => '',
            'codeused' => false,
            'signingkey' => null,
            'nonces' => [],
            'enrollpayload' => [],
            'sitename' => self::SITE_NAME,
            'platformname' => self::PLATFORM_NAME,
            'encryptionkey' => self::ENCRYPTION_KEY,
            'status' => 'active',
            'instructions' => [],
            'awsranges' => null,
            'objects' => [],
            'signed' => [],
            'runs' => [],
            'nextrun' => 1,
            'holdverify' => false,
            'receiptpayload' => [],
            'contractinactive' => false,
            'urllife' => 900,
            'coremanifests' => [],
            'requests' => [],
            'lastbody' => null,
            'lastheaders' => null,
        ];
    }

    /**
     * Handles one request.
     *
     * @param string $method HTTP method.
     * @param string $host Host name without the port.
     * @param string $path Path.
     * @param string[] $headers Headers by lower-case name.
     * @param string $body Body.
     * @return array [HTTP status, headers by name, body]
     */
    public function handle(string $method, string $host, string $path, array $headers, string $body): array {
        $this->state['requests'][] = $method . ' ' . $path;
        $this->state['lastbody'] = $body;
        $this->state['lastheaders'] = $headers;
        switch ($this->state['mode']) {
            case 'unavailable':
                return [503, ['Retry-After' => '120'], self::error('service_unavailable')];
            case 'rate_limited':
                return [429, ['Retry-After' => '30'], self::error('too_many_requests')];
            case 'ip_not_allowed':
                return [403, [], self::error('ip_not_allowed', ['ip' => '198.51.100.7'])];
            case 'unexpected':
                return [500, ['Content-Type' => 'text/html'], '<html>error</html>'];
            case 'run_in_progress':
                return [409, [], self::error('run_in_progress', ['run_id' => '99999999-0000-4000-8000-000000000001'])];
            case 'run_limit_reached':
                return [429, ['Retry-After' => '3600'], self::error('run_limit_reached', ['retry_after' => 3600])];
            case 'run_not_found':
                return [404, [], self::error('run_not_found')];
            case 'run_state_invalid':
                return [409, [], self::error('run_state_invalid', ['status' => 'verifying'])];
            case 'capacity_recheck_required':
                return [409, [], self::error('capacity_recheck_required', ['requested_bytes' => 100, 'estimated_bytes' => 50])];
            case 'artifact_signature_invalid':
                return [422, [], self::error('artifact_signature_invalid', ['artifact' => 'manifest'])];
            case 'core_manifest_not_found':
                return [404, [], self::error('core_manifest_not_found')];
            case 'contract_inactive':
                return [403, [], self::error('contract_inactive')];
        }
        if (str_starts_with($path, '/_s3/')) {
            return $this->object($method, substr($path, strlen('/_s3/')), $headers, $body);
        }
        if ($method === 'POST' && $path === $this->basepath . '/enroll') {
            return $this->enroll($host, $path, $headers, $body);
        }
        if ($method === 'GET' && $path === $this->basepath . '/status') {
            return $this->status($host, $path, $headers);
        }
        if ($method === 'GET' && $path === $this->basepath . '/aws-ranges') {
            return $this->aws_ranges($host, $path, $headers);
        }
        if (str_starts_with($path, $this->basepath . '/runs') || $path === $this->basepath . '/core-manifests') {
            return $this->run_api($method, $host, $path, $headers, $body);
        }
        return [404, [], self::error('not_found')];
    }

    /**
     * Sets the instruction of a kind that the status answer carries, signed with the service's key.
     *
     * @param string $kind settings or aws_ranges.
     * @param int $seq Sequence number.
     * @param array $body Body of the instruction.
     * @param string|null $siteid Site ID in the instruction; null for the one given at enrolment, and for aws_ranges none.
     */
    public function set_instruction(string $kind, int $seq, array $body, ?string $siteid = null): void {
        $this->state['instructions'][$kind] = $this->signer->parts([
            'type' => 'instruction',
            'kind' => $kind,
            'site_id' => $kind === 'aws_ranges' ? null : ($siteid ?? self::SITE_ID),
            'seq' => $seq,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            'body' => $body,
        ]);
    }

    /**
     * Publishes a list of AWS ranges: stores the content for GET /aws-ranges and sets the instruction that names it.
     *
     * @param int $seq Sequence number.
     * @param string $content The content.
     */
    public function publish_aws_ranges(int $seq, string $content): void {
        $this->state['awsranges'] = $content;
        $this->set_instruction('aws_ranges', $seq, [
            'sync_token' => '1791766626',
            'create_date' => '2026-10-18-00-00-00',
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
        ]);
    }

    /**
     * Removes the instructions.
     */
    public function clear_instructions(): void {
        $this->state['instructions'] = [];
    }

    /**
     * A Guzzle handler that answers through handle().
     *
     * @return callable
     */
    public function handler(): callable {
        return function (\Psr\Http\Message\RequestInterface $request, array $options): \GuzzleHttp\Promise\PromiseInterface {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }
            [$status, $responseheaders, $body] = $this->handle(
                $request->getMethod(),
                $request->getUri()->getHost(),
                $request->getUri()->getPath(),
                $headers,
                (string) $request->getBody()
            );
            // A bare handler does not fill the sink option of a request; the MockHandler of Guzzle does.
            if (isset($options['sink']) && is_string($options['sink'])) {
                file_put_contents($options['sink'], $body);
            }
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($status, $responseheaders, $body));
        };
    }

    /**
     * Publishes the list of official files of a Moodle build: stores the gzip and keeps the signed envelope.
     *
     * @param string $version Moodle version, such as 2024100703.10.
     * @param string $build Build, such as 20250113.
     * @param string $gzip The content of the list (already compressed).
     * @param array $payload Fields that replace those of the envelope's payload.
     */
    public function publish_core_manifest(string $version, string $build, string $gzip, array $payload = []): void {
        $this->state['objects']['core-manifests/' . $version . '/' . $build . '.gz'] = base64_encode($gzip);
        $this->state['coremanifests'][$version . '/' . $build] = $this->signer->parts(array_merge([
            'type' => 'core_manifest',
            'version' => $version,
            'build' => $build,
            'release' => '4.5.3+ (Build: ' . $build . ')',
            'sha256' => base64_encode(hash('sha256', $gzip, true)),
            'size' => strlen($gzip),
            'count' => 3,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
        ], $payload));
    }

    /**
     * Verifies a run that was completed: the artifacts and the chunks must be in the storage as the report says. A run that
     * passes gets a signed receipt and is completed; one that does not is failed with the service's reason.
     *
     * @param string $id Run ID.
     */
    public function verify_run(string $id): void {
        $run = &$this->state['runs'][$id];
        $failure = $this->verification_failure($id, $run);
        if ($failure !== null) {
            $run['status'] = 'failed';
            $run['failure_code'] = $failure;
            return;
        }
        $run['status'] = 'completed';
        $run['receipt'] = $this->signer->parts(array_merge([
            'type' => 'receipt',
            'site_id' => self::SITE_ID,
            'run_id' => $id,
            'kind' => $run['kind'],
            'manifest_sha256' => $run['manifest']['sha256'],
            'index_sha256' => $run['index']['sha256'],
            'chunk_count' => $run['index']['count'],
            'stored_bytes' => $run['storedbytes'],
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
        ], $this->state['receiptpayload']));
    }

    /**
     * Why a completed run does not pass verification.
     *
     * @param string $id Run ID.
     * @param array $run The run; its storedbytes is set here.
     * @return string|null The failure code, or null when the run passes.
     */
    private function verification_failure(string $id, array &$run): ?string {
        $prefix = 'sites/' . self::SITE_ID . '/';
        $manifest = $this->stored($prefix . 'manifests/' . $id);
        $index = $this->stored($prefix . 'indexes/' . $id);
        if ($manifest === null || $index === null) {
            return 'verify_missing_chunks';
        }
        foreach ([[$manifest, $run['manifest']], [$index, $run['index']]] as [$stored, $declared]) {
            if (strlen($stored) !== $declared['size'] || base64_encode(hash('sha256', $stored, true)) !== $declared['sha256']) {
                return 'verify_artifact_mismatch';
            }
        }
        $text = gzdecode($index);
        $lines = $text === false ? [] : explode("\n", rtrim($text, "\n"));
        $header = json_decode((string) array_shift($lines), true);
        if (
            !is_array($header)
            || ($header['site_id'] ?? null) !== self::SITE_ID
            || ($header['run_id'] ?? null) !== $id
            || ($header['count'] ?? null) !== $run['index']['count']
            || count($lines) !== $run['index']['count']
        ) {
            return 'verify_index_invalid';
        }
        $total = strlen($manifest) + strlen($index);
        foreach ($lines as $line) {
            if (preg_match('/^([0-9a-f]{64}) (\d+)\z/', $line, $m) !== 1) {
                return 'verify_index_invalid';
            }
            $chunk = $this->stored($prefix . 'objects/' . $m[1]);
            if ($chunk === null) {
                return 'verify_missing_chunks';
            }
            if (strlen($chunk) !== (int) $m[2]) {
                return 'verify_size_mismatch';
            }
            $total += strlen($chunk);
        }
        $run['storedbytes'] = $total;
        return null;
    }

    /**
     * An object of the storage.
     *
     * @param string $key Key.
     * @return string|null The body, or null when there is none.
     */
    private function stored(string $key): ?string {
        return isset($this->state['objects'][$key]) ? (string) base64_decode($this->state['objects'][$key], true) : null;
    }

    /**
     * The origin of the connection URL (scheme, host and port), where the fake storage lives.
     *
     * @return string
     */
    private function origin(): string {
        return substr($this->apibase, 0, strlen($this->apibase) - strlen($this->basepath));
    }

    /**
     * A URL of the fake storage for an object key, in the shape of a pre-signed one.
     *
     * @param string $key Key.
     * @return string
     */
    private function storage_url(string $key): string {
        return $this->origin() . '/_s3/' . $key . '?X-Amz-Expires=900&X-Amz-Signature=0123456789abcdef';
    }

    /**
     * The run endpoints and the list of official files. The request is signed like every other one.
     *
     * @param string $method Method.
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @return array
     */
    private function run_api(string $method, string $host, string $path, array $headers, string $body): array {
        $problem = $this->authenticate($method, $host, $path, $headers, $body);
        if ($problem !== null) {
            return $problem;
        }
        $relative = substr($path, strlen($this->basepath));
        $data = json_decode($body, true);
        if ($method === 'POST' && $relative === '/core-manifests') {
            return $this->core_manifest($data);
        }
        if ($method === 'POST' && $relative === '/runs') {
            return $this->start_run($data);
        }
        if (preg_match('~^/runs/([0-9a-f-]{36})(?:/(capacity|upload-urls|complete|fail))?\z~', $relative, $m) !== 1) {
            return [404, [], self::error('not_found')];
        }
        $id = $m[1];
        $action = $m[2] ?? '';
        if (!isset($this->state['runs'][$id])) {
            return [404, [], self::error('run_not_found')];
        }
        if ($action === '' && $method === 'GET') {
            $run = $this->state['runs'][$id];
            return $this->json(200, ['run_id' => $id, 'status' => $run['status'], 'failure_code' => $run['failure_code'],
                'receipt' => $run['receipt']]);
        }
        if ($action === '' || $method !== 'POST') {
            return [404, [], self::error('not_found')];
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            return [422, [], self::error('invalid_request')];
        }
        return match ($action) {
            'capacity' => $this->capacity($id, $data),
            'upload-urls' => $this->upload_urls($id, $data),
            'complete' => $this->complete($id, $data),
            default => $this->fail($id, $data),
        };
    }

    /**
     * Authenticates a signed request: the headers, the site, the signature and the nonce.
     *
     * @param string $method Method.
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @return array|null An error answer, or null.
     */
    private function authenticate(string $method, string $host, string $path, array $headers, string $body): ?array {
        $problem = $this->header_problem($headers);
        if ($problem !== null) {
            return $problem;
        }
        if (($headers['x-secbackup-site'] ?? '') !== self::SITE_ID || $this->state['signingkey'] === null) {
            return [401, [], self::error('site_not_enrolled')];
        }
        return $this->verify_problem($method, $host, $path, $headers, $body, (string) base64_decode($this->state['signingkey']));
    }

    /**
     * A JSON answer.
     *
     * @param int $status HTTP status.
     * @param array $data The body.
     * @return array
     */
    private function json(int $status, array $data): array {
        return [$status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * POST /runs.
     *
     * @param mixed $data The decoded body.
     * @return array
     */
    private function start_run(mixed $data): array {
        if (!is_array($data) || array_keys($data) !== ['kind'] || $data['kind'] !== 'full') {
            return [422, [], self::error('invalid_request')];
        }
        if ($this->state['contractinactive']) {
            return [403, [], self::error('contract_inactive')];
        }
        foreach ($this->state['runs'] as $id => $run) {
            if (in_array($run['status'], ['started', 'uploading', 'verifying'], true)) {
                return [409, [], self::error('run_in_progress', ['run_id' => $id])];
            }
        }
        $id = sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $this->state['nextrun']++);
        $this->state['runs'][$id] = ['status' => 'started', 'kind' => 'full', 'failure_code' => null, 'receipt' => null,
            'estimated' => null, 'reserved' => 0, 'started' => $this->now(), 'manifest' => null, 'index' => null,
            'storedbytes' => 0];
        $parts = $this->signer->parts([
            'type' => 'run',
            'site_id' => self::SITE_ID,
            'run_id' => $id,
            'kind' => 'full',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            'present_url' => $this->storage_url('sites/' . self::SITE_ID . '/indexes/present'),
            'present_url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now() + 900),
        ]);
        return [201, ['Content-Type' => 'application/json'], json_encode($parts, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * POST /runs/{run}/capacity: the fake continues unless the estimate is 1 TB or more (limit 1 TB, used 100 bytes).
     *
     * @param string $id Run ID.
     * @param array $data The decoded body.
     * @return array
     */
    private function capacity(string $id, array $data): array {
        if (array_keys($data) !== ['estimated_bytes'] || !is_int($data['estimated_bytes']) || $data['estimated_bytes'] < 0) {
            return [422, [], self::error('invalid_request')];
        }
        $run = &$this->state['runs'][$id];
        if (!in_array($run['status'], ['started', 'uploading'], true)) {
            return [409, [], self::error('run_state_invalid', ['status' => $run['status']])];
        }
        $continue = $data['estimated_bytes'] < 1000000000000;
        $run['estimated'] = $continue ? $data['estimated_bytes'] : null;
        return $this->json(200, ['decision' => $continue ? 'continue' : 'refuse', 'limit_bytes' => 1000000000000,
            'used_bytes' => 100]);
    }

    /**
     * POST /runs/{run}/upload-urls.
     *
     * @param string $id Run ID.
     * @param array $data The decoded body.
     * @return array
     */
    private function upload_urls(string $id, array $data): array {
        $items = $data['items'] ?? null;
        if (
            array_keys($data) !== ['items']
            || !is_array($items) || !array_is_list($items) || $items === [] || count($items) > 256
        ) {
            return [422, [], self::error('invalid_request')];
        }
        $total = 0;
        foreach ($items as $item) {
            if (!self::upload_item_valid($item)) {
                return [422, [], self::error('invalid_request')];
            }
            $total += $item['size'];
        }
        if ($this->state['contractinactive']) {
            return [403, [], self::error('contract_inactive')];
        }
        $run = &$this->state['runs'][$id];
        if (!in_array($run['status'], ['started', 'uploading'], true)) {
            return [409, [], self::error('run_state_invalid', ['status' => $run['status']])];
        }
        $requested = $run['reserved'] + $total;
        if ($run['estimated'] === null || $requested > $run['estimated'] * 1.1) {
            // The figure is what was granted so far: the refused batch is not in it.
            return [409, [], self::error('capacity_recheck_required', ['requested_bytes' => $run['reserved'],
                'estimated_bytes' => $run['estimated']])];
        }
        $run['reserved'] = $requested;
        $run['status'] = 'uploading';
        // Lock of the personal plan (14 days) and 14 days to spare after the URL is issued, rounded up to a midnight of UTC.
        $retain = gmdate('Y-m-d\T00:00:00\Z', (int) (ceil(($this->now() + 28 * 86400) / 86400) * 86400));
        $issued = [];
        foreach ($items as $item) {
            $prefix = 'sites/' . self::SITE_ID . '/';
            $key = match ($item['kind']) {
                'object' => $prefix . 'objects/' . $item['name'],
                'manifest' => $prefix . 'manifests/' . $id,
                default => $prefix . 'indexes/' . $id,
            };
            $entry = ['kind' => $item['kind']];
            if (isset($item['name'])) {
                $entry['name'] = $item['name'];
            }
            $headers = [
                'Content-Length' => (string) $item['size'],
                'x-amz-checksum-sha256' => $item['sha256'],
                'x-amz-storage-class' => 'STANDARD',
                'x-amz-object-lock-mode' => 'COMPLIANCE',
                'x-amz-object-lock-retain-until-date' => $retain,
            ];
            if ($item['kind'] === 'object') {
                // An object is stored once; the manifest and the index are named by the run and can be sent again.
                $headers['If-None-Match'] = '*';
            }
            // A real storage checks every header that a URL was signed with: the PUT must send each, with the same value.
            $this->state['signed'][$key] = array_change_key_case($headers, CASE_LOWER);
            $issued[] = $entry + [
                'url' => $this->storage_url($key),
                'headers' => $headers,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now() + $this->state['urllife']),
            ];
        }
        return $this->json(200, ['items' => $issued]);
    }

    /**
     * Whether an item of an upload URL request has the service's shape.
     *
     * @param mixed $item The item.
     * @return bool
     */
    private static function upload_item_valid(mixed $item): bool {
        if (!is_array($item) || !in_array($item['kind'] ?? null, ['object', 'manifest', 'index'], true)) {
            return false;
        }
        $keys = $item['kind'] === 'object' ? ['kind', 'name', 'size', 'sha256'] : ['kind', 'size', 'sha256'];
        $given = array_keys($item);
        sort($given);
        sort($keys);
        return $given === $keys
            && is_int($item['size']) && $item['size'] >= 0
            && self::is_sha256($item['sha256'])
            && ($item['kind'] !== 'object' || (is_string($item['name']) && preg_match('/^[0-9a-f]{64}\z/', $item['name']) === 1));
    }

    /**
     * Whether a value is the standard base64 of 32 bytes.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_sha256(mixed $value): bool {
        return is_string($value) && preg_match('/^[A-Za-z0-9+\/]{43}=\z/', $value) === 1
            && base64_encode((string) base64_decode($value, true)) === $value;
    }

    /**
     * POST /runs/{run}/complete: checks the shape and the signatures, then verifies at once (unless holdverify is set).
     *
     * @param string $id Run ID.
     * @param array $data The decoded body.
     * @return array
     */
    private function complete(string $id, array $data): array {
        $manifest = $data['manifest'] ?? null;
        $index = $data['index'] ?? null;
        if (
            array_keys($data) !== ['manifest', 'index', 'stats']
            || !is_array($manifest) || array_keys($manifest) !== ['sha256', 'size', 'signature']
            || !is_array($index) || array_keys($index) !== ['sha256', 'size', 'count', 'signature']
            || !self::is_sha256($manifest['sha256']) || !is_int($manifest['size']) || !is_string($manifest['signature'])
            || !self::is_sha256($index['sha256']) || !is_int($index['size']) || !is_int($index['count'])
            || !is_string($index['signature']) || !is_array($data['stats'])
        ) {
            return [422, [], self::error('invalid_request')];
        }
        $run = &$this->state['runs'][$id];
        if ($run['status'] !== 'uploading') {
            return [409, [], self::error('run_state_invalid', ['status' => $run['status']])];
        }
        if ($run['estimated'] === null) {
            // A refused decision is no decision: the report is a state error, as it is for a run that is not uploading.
            return [409, [], self::error('run_state_invalid', ['status' => $run['status']])];
        }
        $key = (string) base64_decode($this->state['signingkey']);
        $manifesttext = "SECBACKUP-MANIFEST-V1\n" . self::SITE_ID . "\n" . $id . "\n" . $manifest['sha256'];
        $indextext = "SECBACKUP-INDEX-V1\n" . self::SITE_ID . "\n" . $id . "\n" . $index['sha256'] . "\n" . $index['count'];
        foreach (['manifest' => [$manifest, $manifesttext], 'index' => [$index, $indextext]] as $artifact => [$part, $text]) {
            $signature = self::b64url_decode($part['signature']);
            if ($signature === null || strlen($signature) !== 64 || !sodium_crypto_sign_verify_detached($signature, $text, $key)) {
                return [422, [], self::error('artifact_signature_invalid', ['artifact' => $artifact])];
            }
        }
        $run['manifest'] = $manifest;
        $run['index'] = $index;
        $run['status'] = 'verifying';
        if (!$this->state['holdverify']) {
            $this->verify_run($id);
        }
        return $this->json(202, ['status' => 'verifying']);
    }

    /**
     * POST /runs/{run}/fail.
     *
     * @param string $id Run ID.
     * @param array $data The decoded body.
     * @return array
     */
    private function fail(string $id, array $data): array {
        $codes = ['server_busy', 'disk_low', 'db_slow', 'nfs_slow', 'selinux_denied', 'capacity_refused', 'network_check_failed',
            'signature_invalid', 'tamper_detected', 'version_too_old', 'upload_failed', 'window_ended', 'restarted',
            'contract_inactive'];
        if (array_keys($data) !== ['code'] || !in_array($data['code'], $codes, true)) {
            return [422, [], self::error('invalid_request')];
        }
        $run = &$this->state['runs'][$id];
        if (!in_array($run['status'], ['started', 'uploading'], true)) {
            return [409, [], self::error('run_state_invalid', ['status' => $run['status']])];
        }
        $run['status'] = 'failed';
        $run['failure_code'] = $data['code'];
        return $this->json(200, ['status' => 'failed']);
    }

    /**
     * POST /core-manifests.
     *
     * @param mixed $data The decoded body.
     * @return array
     */
    private function core_manifest(mixed $data): array {
        if (
            !is_array($data) || array_keys($data) !== ['version', 'build']
            || !is_string($data['version']) || preg_match('/^\d{10}\.\d{2}\z/', $data['version']) !== 1
            || !is_string($data['build']) || preg_match('/^\d{8}\z/', $data['build']) !== 1
        ) {
            return [422, [], self::error('invalid_request')];
        }
        $name = $data['version'] . '/' . $data['build'];
        if (!isset($this->state['coremanifests'][$name])) {
            return [404, [], self::error('core_manifest_not_found')];
        }
        return $this->json(200, ['manifest' => $this->state['coremanifests'][$name],
            'url' => $this->storage_url('core-manifests/' . $name . '.gz'),
            'url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now() + 900)]);
    }

    /**
     * The storage: PUT /_s3/<key> keeps a body, GET gives it back.
     *
     * A real storage refuses a PUT whose signed headers differ from the request, so does this one: Content-Length and
     * x-amz-checksum-sha256 must match the body, both object-lock headers must be there (MinIO does not check them), and a
     * key that an upload URL was issued for needs every header that the URL was signed with, with the same value.
     *
     * @param string $method HTTP method.
     * @param string $key Key of the object.
     * @param string[] $headers Headers by lower-case name.
     * @param string $body Body.
     * @return array
     */
    private function object(string $method, string $key, array $headers, string $body): array {
        if ($method === 'PUT') {
            foreach ($this->state['signed'][$key] ?? [] as $name => $value) {
                if (($headers[$name] ?? null) !== $value) {
                    return [400, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>'];
                }
            }
            if (
                ($headers['content-length'] ?? null) !== (string) strlen($body)
                || ($headers['x-amz-checksum-sha256'] ?? null) !== base64_encode(hash('sha256', $body, true))
                || !isset($headers['x-amz-object-lock-mode'], $headers['x-amz-object-lock-retain-until-date'])
            ) {
                return [400, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>'];
            }
            $condition = $headers['if-none-match'] ?? null;
            if ($condition !== null && $condition !== '*') {
                return [400, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>'];
            }
            if ($condition === '*' && isset($this->state['objects'][$key])) {
                return [412, [], '<Error><Code>PreconditionFailed</Code></Error>'];
            }
            $this->state['objects'][$key] = base64_encode($body);
            return [200, [], ''];
        }
        if ($method === 'GET' && isset($this->state['objects'][$key])) {
            return [200, [], (string) base64_decode($this->state['objects'][$key], true)];
        }
        return [404, [], '<Error><Code>NoSuchKey</Code></Error>'];
    }

    /**
     * Current time of the service.
     *
     * @return int
     */
    private function now(): int {
        return $this->state['now'] ?? time();
    }

    /**
     * Enrolment.
     *
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @return array
     */
    private function enroll(string $host, string $path, array $headers, string $body): array {
        $problem = $this->header_problem($headers);
        if ($problem !== null) {
            return $problem;
        }
        $data = json_decode($body, true);
        $key = false;
        if (is_array($data) && is_string($data['signing_public_key'] ?? null)) {
            $key = base64_decode($data['signing_public_key'], true);
        }
        if (
            $key === false
            || strlen($key) !== 32
            || !self::body_follows_the_rules($data)
            || !is_string($data['enrollment_code'] ?? null)
            || !is_string($data['encryption_key_fingerprint'] ?? null)
            || !is_array($data['site'] ?? null)
            || !is_string($data['plugin_version'] ?? null)
            || isset($headers['x-secbackup-site'])
        ) {
            return [422, [], self::error('invalid_request')];
        }
        $problem = $this->verify_problem('POST', $host, $path, $headers, $body, $key);
        if ($problem !== null) {
            return $problem;
        }
        if (
            $this->state['codeused']
            || strtoupper((string) preg_replace('/[\s-]/', '', $data['enrollment_code'])) !== str_replace('-', '', self::CODE)
        ) {
            return [401, [], self::error('enrollment_code_invalid')];
        }
        $encryptionkey = $this->state['encryptionkey'] ?? self::ENCRYPTION_KEY;
        $encryptionfp = self::fingerprint((string) base64_decode(substr($encryptionkey, 7)));
        if (strtoupper(str_replace(' ', '', $data['encryption_key_fingerprint'])) !== $encryptionfp) {
            return [409, [], self::error('encryption_key_mismatch')];
        }
        $this->state['codeused'] = true;
        $this->state['signingkey'] = base64_encode($key);
        $payload = array_merge([
            'type' => 'enrollment',
            'site_id' => self::SITE_ID,
            'site_name' => $this->state['sitename'] ?? self::SITE_NAME,
            'platform_name' => $this->state['platformname'] ?? self::PLATFORM_NAME,
            'encryption_key_fingerprint' => $encryptionfp,
            'signing_key_fingerprint' => self::fingerprint($key),
            'request_nonce' => $headers['x-secbackup-nonce'],
            'enrolled_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            'api_base' => $this->apibase,
        ], $this->state['enrollpayload']);
        $signer = $this->state['mode'] === 'untrusted_key' ? new envelope_builder(str_repeat('07', 32)) : $this->signer;
        $parts = $signer->parts($payload);
        if ($this->state['mode'] === 'bad_signature') {
            $parts['sig'] = envelope_builder::b64url(str_repeat("\0", 64));
        }
        return [201, ['Content-Type' => 'application/json'], json_encode($parts, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * The service's rules for the enrolment body: only the known fields, and each in its form.
     *
     * @param array $data The decoded body; its signing key has been decoded already.
     * @return bool
     */
    private static function body_follows_the_rules(array $data): bool {
        $site = $data['site'] ?? null;
        if (
            !is_array($site)
            || !is_string($data['plugin_version'] ?? null)
            || array_diff(array_keys($data), ['enrollment_code', 'signing_public_key', 'encryption_key_fingerprint', 'site',
                'plugin_version']) !== []
            || array_diff(array_keys($site), ['wwwroot', 'moodle_release', 'moodle_version', 'db_family']) !== []
            || strlen((string) $data['signing_public_key']) !== 44
            || !is_string($site['wwwroot'] ?? null)
            || !is_string($site['moodle_release'] ?? null)
            || !is_string($site['moodle_version'] ?? null)
            || !is_string($site['db_family'] ?? null)
        ) {
            return false;
        }
        // The patterns end with \z, not $, which also matches before a final line feed.
        return filter_var($site['wwwroot'], FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($site['wwwroot'], PHP_URL_SCHEME)), ['http', 'https'], true)
            && strlen($site['wwwroot']) <= 255
            && $site['moodle_release'] !== ''
            && mb_strlen($site['moodle_release']) <= 100
            && preg_match('/\p{C}/u', $site['moodle_release']) === 0
            && preg_match('/^\d{10}(\.\d{2})?\z/', $site['moodle_version']) === 1
            && in_array($site['db_family'], ['mysql', 'mariadb', 'postgres'], true)
            && preg_match('/^\d{10}\z/', $data['plugin_version']) === 1;
    }

    /**
     * Status.
     *
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @return array
     */
    private function status(string $host, string $path, array $headers): array {
        $problem = $this->header_problem($headers);
        if ($problem !== null) {
            return $problem;
        }
        if (
            $this->state['mode'] === 'site_removed'
            || ($headers['x-secbackup-site'] ?? '') !== self::SITE_ID
            || $this->state['signingkey'] === null
        ) {
            return [401, [], self::error('site_not_enrolled')];
        }
        $problem = $this->verify_problem('GET', $host, $path, $headers, '', (string) base64_decode($this->state['signingkey']));
        if ($problem !== null) {
            return $problem;
        }
        if ($this->state['mode'] === 'bad_status') {
            return [200, [], '{"site_id":"00000000-0000-4000-8000-000000000000","status":"active"}'];
        }
        return [200, ['Content-Type' => 'application/json'], json_encode(['site_id' => self::SITE_ID,
            'status' => $this->state['status'], 'server_time' => $this->now(),
            'instructions' => array_values($this->state['instructions'])])];
    }

    /**
     * The list of AWS ranges.
     *
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @return array
     */
    private function aws_ranges(string $host, string $path, array $headers): array {
        $problem = $this->header_problem($headers);
        if ($problem !== null) {
            return $problem;
        }
        if (($headers['x-secbackup-site'] ?? '') !== self::SITE_ID || $this->state['signingkey'] === null) {
            return [401, [], self::error('site_not_enrolled')];
        }
        $problem = $this->verify_problem('GET', $host, $path, $headers, '', (string) base64_decode($this->state['signingkey']));
        if ($problem !== null) {
            return $problem;
        }
        if ($this->state['awsranges'] === null) {
            return [404, [], self::error('not_found')];
        }
        return [200, ['Content-Type' => 'application/json'], $this->state['awsranges']];
    }

    /**
     * Checks the shape of the signature headers and the clock, as the service does first.
     *
     * @param string[] $headers Headers.
     * @return array|null An error answer, or null.
     */
    private function header_problem(array $headers): ?array {
        $timestamp = $headers['x-secbackup-timestamp'] ?? '';
        $signature = self::b64url_decode($headers['x-secbackup-signature'] ?? '');
        $nonce = $headers['x-secbackup-nonce'] ?? '';
        // The patterns end with \z, not $, which also matches before a final line feed.
        if (
            preg_match('/^\d{1,12}\z/', $timestamp) !== 1
            || preg_match('/^[A-Za-z0-9_-]{22}\z/', $nonce) !== 1
            || $signature === null
            || strlen($signature) !== 64
        ) {
            return [401, [], self::error('signature_required')];
        }
        if (abs($this->now() - (int) $timestamp) > self::MAX_SKEW) {
            return [401, [], self::error('timestamp_out_of_range', ['server_time' => $this->now()])];
        }
        return null;
    }

    /**
     * Verifies the signature and claims the nonce.
     *
     * @param string $method Method.
     * @param string $host Host.
     * @param string $path Path.
     * @param string[] $headers Headers.
     * @param string $body Body.
     * @param string $publickey Raw public key of the site.
     * @return array|null An error answer, or null.
     */
    private function verify_problem(
        string $method,
        string $host,
        string $path,
        array $headers,
        string $body,
        string $publickey
    ): ?array {
        $canonical = implode("\n", ['SECBACKUP-V1', strtoupper($method), strtolower($host), $path, '',
            $headers['x-secbackup-timestamp'], $headers['x-secbackup-nonce'], hash('sha256', $body)]);
        $signature = (string) self::b64url_decode($headers['x-secbackup-signature']);
        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publickey)) {
            return [401, [], self::error('signature_invalid')];
        }
        if (in_array($headers['x-secbackup-nonce'], $this->state['nonces'], true)) {
            return [401, [], self::error('nonce_reused')];
        }
        $this->state['nonces'][] = $headers['x-secbackup-nonce'];
        return null;
    }

    /**
     * An error body in the service's format.
     *
     * @param string $code Error code.
     * @param array $extra Extra fields.
     * @return string
     */
    private static function error(string $code, array $extra = []): string {
        return json_encode(['error' => ['code' => $code, 'message' => 'Fake service message.'] + $extra]);
    }

    /**
     * Fingerprint of a raw key, computed independently of the plugin.
     *
     * @param string $raw Raw key.
     * @return string
     */
    private static function fingerprint(string $raw): string {
        return strtoupper(substr(hash('sha256', 'secbackup:fp:v1|' . $raw), 0, 24));
    }

    /**
     * Decodes URL-safe base64 without padding.
     *
     * @param string $text Text.
     * @return string|null
     */
    private static function b64url_decode(string $text): ?string {
        if (preg_match('/^[A-Za-z0-9_-]+\z/', $text) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
