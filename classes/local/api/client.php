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
use tool_secbackup\local\net\response;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\sec_trust;

/**
 * Calls the service's API: enrolment and status.
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
        'wwwroot_mismatch', 'body_too_large'];

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
        return new status_result($data['status'], $data['server_time'], $data['server_time'] - $now, count($data['instructions']));
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
        return new api_exception('api_' . $code, $code, $response->status, $response->retryafter, $servertime);
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
