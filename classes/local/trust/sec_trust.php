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

namespace tool_secbackup\local\trust;

use tool_secbackup\local\crypto\base64url;

/**
 * Verifies the service's signed responses: {"v": 1, "kid": "...", "payload": "...", "sig": "..."}.
 *
 * Only the keys embedded here, and on development sites the keys from developer_settings, are trusted.
 * A response signed by any other key is refused, whatever the connection URL is.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sec_trust {
    /**
     * @var string[] Production response signing keys (standard base64), two at most.
     * Empty until the production key exists; the release plan fills it in.
     */
    private const EMBEDDED_KEYS = [];

    /** @var string Signing context shared with the service. */
    private const CONTEXT = "SECBACKUP-RESP-V1\n";

    /** @var int Largest response body accepted, in bytes. */
    public const MAX_BODY = 65536;

    /** @var string[] Raw public keys by key ID. */
    private array $keys = [];

    /**
     * Constructor.
     *
     * @param string[] $rawkeys Raw 32-byte Ed25519 public keys.
     */
    public function __construct(array $rawkeys) {
        foreach ($rawkeys as $raw) {
            if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \coding_exception('A response signing key has 32 bytes.');
            }
            $this->keys[self::kid($raw)] = $raw;
        }
    }

    /**
     * The keys this site trusts: the embedded keys and the developer keys from config.php.
     *
     * @return self
     */
    public static function current(): self {
        $embedded = array_map(static fn(string $key): string => (string) base64_decode($key, true), self::EMBEDDED_KEYS);
        return new self(array_merge($embedded, developer_settings::current()->trusted_keys()));
    }

    /**
     * Key ID of a public key, computed as the service computes it.
     *
     * @param string $rawkey Raw public key.
     * @return string 16 lower-case hexadecimal characters.
     */
    public static function kid(string $rawkey): string {
        return substr(hash('sha256', $rawkey), 0, 16);
    }

    /**
     * IDs of the trusted keys.
     *
     * @return string[]
     */
    public function key_ids(): array {
        // PHP makes an integer of an array key that is all decimal digits, so give the IDs back as text.
        return array_map(static fn(int|string $kid): string => (string) $kid, array_keys($this->keys));
    }

    /**
     * Verifies a signed response and returns its payload.
     *
     * @param string $body Response body.
     * @return array The payload (a JSON object).
     * @throws envelope_exception When the body is not a response signed by a trusted key.
     */
    public function open(string $body): array {
        if (strlen($body) > self::MAX_BODY) {
            throw new envelope_exception('envelope_malformed');
        }
        $envelope = json_decode($body, true, 4);
        if (!is_array($envelope) || !self::well_formed($envelope)) {
            throw new envelope_exception('envelope_malformed');
        }
        $payloadjson = base64url::decode($envelope['payload']);
        $signature = base64url::decode($envelope['sig']);
        if ($payloadjson === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new envelope_exception('envelope_malformed');
        }
        if (!array_key_exists($envelope['kid'], $this->keys)) {
            throw new envelope_exception('envelope_untrusted');
        }
        $message = self::CONTEXT . $envelope['kid'] . "\n" . $envelope['payload'];
        if (!sodium_crypto_sign_verify_detached($signature, $message, $this->keys[$envelope['kid']])) {
            throw new envelope_exception('envelope_signature');
        }
        $payload = json_decode($payloadjson, true, 16);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new envelope_exception('envelope_malformed');
        }
        return $payload;
    }

    /**
     * Whether a decoded body has exactly the four fields, with the right types.
     *
     * @param array $envelope Decoded body.
     * @return bool
     */
    private static function well_formed(array $envelope): bool {
        $names = array_keys($envelope);
        sort($names);
        // Use \z rather than $, which lets a final line break through.
        return $names === ['kid', 'payload', 'sig', 'v']
            && $envelope['v'] === 1
            && is_string($envelope['kid'])
            && preg_match('/^[0-9a-f]{16}\z/', $envelope['kid']) === 1
            && is_string($envelope['payload'])
            && is_string($envelope['sig']);
    }
}
