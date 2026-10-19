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
 * The signatures that the backup service checks on the manifest and the index of a run.
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
     * @param signing_keypair $pair The site's signing key pair.
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 of the manifest chunk, in standard base64.
     * @return string The signature, in base64url without padding.
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function manifest(
        signing_keypair $pair,
        string $siteid,
        string $runid,
        string $sha256base64
    ): string {
        return base64url::encode($pair->sign(self::manifest_message($siteid, $runid, $sha256base64)));
    }

    /**
     * Signs the index.
     *
     * @param signing_keypair $pair The site's signing key pair.
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @param string $sha256base64 SHA-256 of the index, in standard base64.
     * @param int $count Number of chunk names in the index.
     * @return string The signature, in base64url without padding.
     * @throws \coding_exception For an argument that is not in its form.
     */
    public static function index(
        signing_keypair $pair,
        string $siteid,
        string $runid,
        string $sha256base64,
        int $count
    ): string {
        return base64url::encode($pair->sign(self::index_message($siteid, $runid, $sha256base64, $count)));
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
        $raw = preg_match('/^[A-Za-z0-9+\/]{43}=\z/', $sha256base64) === 1 ? base64_decode($sha256base64, true) : false;
        if (
            preg_match(self::UUID, $siteid) !== 1 || preg_match(self::UUID, $runid) !== 1
            || $raw === false || base64_encode($raw) !== $sha256base64
        ) {
            throw new \coding_exception('A site and a run are lower-case UUIDs, and a digest is the base64 of 32 bytes.');
        }
    }
}
