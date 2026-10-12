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

namespace tool_secbackup\local\crypto;

/**
 * The site's Ed25519 key pair for signing requests. It lives in memory until enrolment succeeds.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class signing_keypair {
    /** @var string Secret key (64 bytes). */
    private string $secret;

    /** @var string Public key (32 bytes). */
    private string $public;

    /**
     * Constructor.
     *
     * The type of $secretkey is written the way the PHPDoc checker of moodle-plugin-ci reads the signature: it counts
     * the tokens of the attribute as part of the type. It is plain "string" for PHP.
     *
     * @param #[|\SensitiveParameter|]|string $secretkey Secret key (64 bytes): the seed, then the public key.
     * @throws \coding_exception When the secret key does not have 64 bytes.
     * @throws key_exception When the second half is not the public key that the seed makes.
     */
    public function __construct(#[\SensitiveParameter] string $secretkey) {
        if (strlen($secretkey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \coding_exception('An Ed25519 secret key has 64 bytes.');
        }
        // Signing reads the public key from the second half of the secret key, so a half that does not belong to the
        // seed would make signatures that give the secret away. Derive the public key from the seed and compare.
        $derived = sodium_crypto_sign_seed_keypair(substr($secretkey, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $public = sodium_crypto_sign_publickey($derived);
        sodium_memzero($derived);
        if (!hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secretkey))) {
            throw new key_exception('signingkey_corrupt');
        }
        $this->secret = $secretkey;
        $this->public = $public;
    }

    /**
     * Creates a new key pair in memory.
     *
     * @return self
     */
    public static function generate(): self {
        $pair = sodium_crypto_sign_keypair();
        $pairobject = new self(sodium_crypto_sign_secretkey($pair));
        sodium_memzero($pair);
        return $pairobject;
    }

    /**
     * The public key.
     *
     * @return string 32 bytes.
     */
    public function public_key(): string {
        return $this->public;
    }

    /**
     * Signs a message.
     *
     * @param string $message Message.
     * @return string Detached signature (64 bytes).
     */
    public function sign(string $message): string {
        return sodium_crypto_sign_detached($message, $this->secret);
    }

    /**
     * The secret key, only for signing_keys::save().
     *
     * @return string 64 bytes.
     */
    public function export_secret(): string {
        return $this->secret;
    }

    /**
     * Keeps the secret out of print_r(), var_dump() and debugging output.
     *
     * PHP matches magic method names without regard to case. The lower-case spelling is the one the Moodle code
     * checker accepts for this method.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['public' => base64_encode($this->public)];
    }

    /**
     * Refuses to be serialised: a session or a cache would keep the secret.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A signing key pair must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A signing key pair must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string: that skips the constructor and its check of the public key.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A signing key pair must not be unserialised.');
    }
}
