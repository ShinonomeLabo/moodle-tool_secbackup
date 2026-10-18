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

use tool_secbackup\local\crypto\base64url;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;

/**
 * Signs requests to the service with the site's Ed25519 key.
 *
 * The signed text must stay exactly the same as the service's: changing it breaks every request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_signer {
    /** @var string First line of the signed text. */
    private const CONTEXT = 'SECBACKUP-V1';

    /** @var int Largest body, in bytes, that the service accepts (it answers 413 body_too_large for more). */
    public const MAX_BODY = 65536;

    /**
     * The text that is signed.
     *
     * @param string $method HTTP method.
     * @param string $host Host name.
     * @param string $path Path ("/" when empty).
     * @param string $query Query string as sent (empty when none).
     * @param string $timestamp UNIX time as sent in X-SecBackup-Timestamp.
     * @param string $nonce Nonce as sent in X-SecBackup-Nonce.
     * @param string $body Request body.
     * @return string
     */
    public static function canonical(
        string $method,
        string $host,
        string $path,
        string $query,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return implode("\n", [self::CONTEXT, strtoupper($method), strtolower($host), $path === '' ? '/' : $path, $query,
            $timestamp, $nonce, hash('sha256', $body)]);
    }

    /**
     * A new nonce: 16 random bytes as URL-safe base64 without padding.
     *
     * @return string 22 characters.
     */
    public static function new_nonce(): string {
        return base64url::encode(random_bytes(16));
    }

    /**
     * Headers of a signed request.
     *
     * The types of $pair and $body are written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()). The attributes keep the key pair and the body, which holds the registration code
     * of an enrolment, out of the trace of an exception thrown here or in a call made from here.
     *
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param string $method Exactly GET or POST.
     * @param endpoint $endpoint Connection URL.
     * @param string $relative Name of the API call, such as "status".
     * @param #[|\SensitiveParameter|]|string $body JSON body, or an empty string.
     * @param string|null $siteid The site's ID, or null for the enrolment request.
     * @param int $timestamp UNIX time, from 0 to 999999999999.
     * @param string $nonce Nonce from new_nonce().
     * @return string[] Header values by name.
     * @throws \coding_exception When an argument is not in its shape, or the body is larger than the service accepts: that is
     *         a mistake of the caller.
     */
    public static function headers(
        #[\SensitiveParameter] signing_keypair $pair,
        string $method,
        endpoint $endpoint,
        string $relative,
        #[\SensitiveParameter] string $body,
        ?string $siteid,
        int $timestamp,
        string $nonce
    ): array {
        // Both values become HTTP header values, so each pattern ends with \z: $ also matches before a final line feed.
        if (preg_match('/^[A-Za-z0-9_-]{22}\z/', $nonce) !== 1) {
            throw new \coding_exception('A nonce has 22 URL-safe base64 characters.');
        }
        if ($siteid !== null && preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $siteid) !== 1) {
            throw new \coding_exception('A site ID is a lower-case UUID.');
        }
        // The method is a line of the signed text, so it has to be exactly one of two words.
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \coding_exception('A method is GET or POST.');
        }
        if (strlen($body) > self::MAX_BODY) {
            throw new \coding_exception('A request body has at most ' . self::MAX_BODY . ' bytes.');
        }
        // The service reads 1 to 12 digits.
        if ($timestamp < 0 || $timestamp > 999999999999) {
            throw new \coding_exception('A timestamp is UNIX time with 1 to 12 digits.');
        }
        $text = self::canonical($method, $endpoint->host(), $endpoint->path_of($relative), '', (string) $timestamp, $nonce, $body);
        $headers = [
            'X-SecBackup-Timestamp' => (string) $timestamp,
            'X-SecBackup-Nonce' => $nonce,
            'X-SecBackup-Signature' => base64url::encode($pair->sign($text)),
            'Accept' => 'application/json',
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        if ($siteid !== null) {
            $headers['X-SecBackup-Site'] = $siteid;
        }
        return $headers;
    }
}
