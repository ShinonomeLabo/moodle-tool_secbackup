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
            'status' => 'active',
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
        }
        if ($method === 'POST' && $path === $this->basepath . '/enroll') {
            return $this->enroll($host, $path, $headers, $body);
        }
        if ($method === 'GET' && $path === $this->basepath . '/status') {
            return $this->status($host, $path, $headers);
        }
        return [404, [], self::error('not_found')];
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
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($status, $responseheaders, $body));
        };
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
        $encryptionfp = self::fingerprint((string) base64_decode(substr(self::ENCRYPTION_KEY, 7)));
        if (strtoupper(str_replace(' ', '', $data['encryption_key_fingerprint'])) !== $encryptionfp) {
            return [409, [], self::error('encryption_key_mismatch')];
        }
        $this->state['codeused'] = true;
        $this->state['signingkey'] = base64_encode($key);
        $payload = array_merge([
            'type' => 'enrollment',
            'site_id' => self::SITE_ID,
            'site_name' => self::SITE_NAME,
            'platform_name' => self::PLATFORM_NAME,
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
            'status' => $this->state['status'], 'server_time' => $this->now(), 'instructions' => []])];
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
        if (
            preg_match('/^\d{1,12}$/', $timestamp) !== 1
            || preg_match('/^[A-Za-z0-9_-]{22}$/', $nonce) !== 1
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
        if (preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
