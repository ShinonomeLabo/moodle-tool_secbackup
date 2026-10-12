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
 * Builds signed responses the way the service builds them, without the plugin's own classes.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class envelope_builder {
    /** @var string The service's test seed (RFC 8032 section 7.1, TEST 2). */
    public const SEED_HEX = '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb';

    /** @var string Public key of that seed, standard base64. */
    public const PUBLIC_KEY = 'PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw=';

    /** @var string Key ID of that public key. */
    public const KID = '39f713d0a644253f';

    /** @var string Secret key. */
    private string $secret;

    /** @var string Public key. */
    private string $public;

    /**
     * Constructor.
     *
     * @param string $seedhex Seed (32 bytes, hexadecimal).
     */
    public function __construct(string $seedhex = self::SEED_HEX) {
        $pair = sodium_crypto_sign_seed_keypair(hex2bin($seedhex));
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = sodium_crypto_sign_publickey($pair);
    }

    /**
     * The public key, standard base64.
     *
     * @return string
     */
    public function public_key_base64(): string {
        return base64_encode($this->public);
    }

    /**
     * The key ID: the first 16 hexadecimal characters of the SHA-256 of the public key.
     *
     * @return string
     */
    public function kid(): string {
        return substr(hash('sha256', $this->public), 0, 16);
    }

    /**
     * URL-safe base64 without padding.
     *
     * @param string $data Data.
     * @return string
     */
    public static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Signs an encoded payload under a key ID.
     *
     * @param string $kid Key ID written into the signed text.
     * @param string $encodedpayload Payload as URL-safe base64.
     * @return string Signature as URL-safe base64.
     */
    public function signature(string $kid, string $encodedpayload): string {
        return self::b64url(sodium_crypto_sign_detached("SECBACKUP-RESP-V1\n" . $kid . "\n" . $encodedpayload, $this->secret));
    }

    /**
     * Returns the four fields of a signed response.
     *
     * @param array $payload Payload.
     * @return array
     */
    public function parts(array $payload): array {
        $encoded = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['v' => 1, 'kid' => $this->kid(), 'payload' => $encoded, 'sig' => $this->signature($this->kid(), $encoded)];
    }

    /**
     * Returns a signed response body.
     *
     * @param array $payload Payload.
     * @return string
     */
    public function body(array $payload): string {
        return json_encode($this->parts($payload), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Makes the test site trust this key through the developer settings.
     *
     * The plugin reads the developer settings only from the copy of config.php that Moodle keeps in
     * $CFG->config_php_settings, so they are written there.
     *
     * @param array $extra Other developer settings.
     */
    public function trust(array $extra = []): void {
        global $CFG;
        $settings = array_merge(['trustedkeys' => [$this->public_key_base64()]], $extra);
        $CFG->config_php_settings['tool_secbackup_developer'] = $settings;
    }
}
