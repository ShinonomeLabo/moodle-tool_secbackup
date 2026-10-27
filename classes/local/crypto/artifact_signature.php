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
 * The signatures that the backup service checks on the manifest and the index of a run, and that a restore checks on the
 * manifest before it trusts it.
 *
 * The messages are the contract's: a name and a version, then the site, the run and the SHA-256 (standard base64), and for
 * the index the count, each on a line of its own. They are not the messages of request_signer, so a signature of a request
 * cannot be taken for one of these.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class artifact_signature {
    /** @var string Pattern of an ID (a lower-case UUID); it ends with \z: $ also matches before a final line feed. */
    private const UUID = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

    /**
     * Signs the manifest.
     *
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's signing key pair.
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 of the manifest chunk, in standard base64.
     * @return string The signature, in base64url without padding.
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function manifest(
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        string $sha256base64
    ): string {
        return base64url::encode($pair->sign(self::manifest_message($siteid, $runid, $sha256base64)));
    }

    /**
     * Signs the index.
     *
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's signing key pair.
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 of the index, in standard base64.
     * @param int $count Number of chunk names in the index.
     * @return string The signature, in base64url without padding.
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function index(
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        string $runid,
        string $sha256base64,
        int $count
    ): string {
        return base64url::encode($pair->sign(self::index_message($siteid, $runid, $sha256base64, $count)));
    }

    /**
     * Whether a signature of the manifest is valid.
     *
     * Every value comes from outside (the restore gets them from the backup service and from the storage), so a value that is
     * not in its form makes the signature invalid rather than a coding error: a public key that is not 32 bytes, a signature
     * that is not 64 bytes in base64url without padding, and a site, run or digest that manifest() would refuse to sign. The
     * message is the text itself, so another spelling of the same digest is another message.
     *
     * @param string $publickey The Ed25519 public key of the plugin of the site (32 bytes).
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 of the manifest chunk, in standard base64.
     * @param string $signaturebase64url The signature, in base64url without padding.
     * @return bool
     */
    public static function verify_manifest(
        string $publickey,
        string $siteid,
        string $runid,
        string $sha256base64,
        string $signaturebase64url
    ): bool {
        $signature = base64url::decode($signaturebase64url);
        if (
            strlen($signature ?? '') !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publickey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !self::well_formed($siteid, $runid, $sha256base64)
        ) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signature, self::manifest_message($siteid, $runid, $sha256base64), $publickey);
    }

    /**
     * The text that is signed for the manifest.
     *
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 in standard base64.
     * @return string
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function manifest_message(string $siteid, string $runid, string $sha256base64): string {
        self::check($siteid, $runid, $sha256base64);
        return "SECBACKUP-MANIFEST-V1\n" . $siteid . "\n" . $runid . "\n" . $sha256base64;
    }

    /**
     * The text that is signed for the index.
     *
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 in standard base64.
     * @param int $count Number of chunk names.
     * @return string
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function index_message(string $siteid, string $runid, string $sha256base64, int $count): string {
        self::check($siteid, $runid, $sha256base64);
        if ($count < 0) {
            throw new \coding_exception('A count is not below zero.');
        }
        return "SECBACKUP-INDEX-V1\n" . $siteid . "\n" . $runid . "\n" . $sha256base64 . "\n" . $count;
    }

    /**
     * Checks the parts of a message.
     *
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 in standard base64.
     * @throws \coding_exception When one is not in its form.
     */
    private static function check(string $siteid, string $runid, string $sha256base64): void {
        if (!self::well_formed($siteid, $runid, $sha256base64)) {
            throw new \coding_exception('A site and a run are lower-case UUIDs, and a digest is the base64 of 32 bytes.');
        }
    }

    /**
     * Whether the parts of a message are in their form.
     *
     * @param string $siteid Site ID: a lower-case UUID.
     * @param string $runid Run ID: a lower-case UUID.
     * @param string $sha256base64 SHA-256: the canonical standard base64 of 32 bytes.
     * @return bool
     */
    private static function well_formed(string $siteid, string $runid, string $sha256base64): bool {
        $raw = preg_match('/^[A-Za-z0-9+\/]{43}=\z/', $sha256base64) === 1 ? base64_decode($sha256base64, true) : false;
        return preg_match(self::UUID, $siteid) === 1 && preg_match(self::UUID, $runid) === 1
            && $raw !== false && base64_encode($raw) === $sha256base64;
    }
}
