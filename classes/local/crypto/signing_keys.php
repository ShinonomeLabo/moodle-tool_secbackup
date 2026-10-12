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
 * Stores the signing key pair in config_plugins, wrapped with Moodle's \core\encryption.
 *
 * \core\encryption protects the key when only the database leaks. Its own key lives in the
 * data root, so somebody who can read the data root can also read this key.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class signing_keys {
    /** @var string Config name of the wrapped secret key. */
    private const SECRET = 'signingsecret';

    /**
     * Saves a key pair after a successful enrolment.
     *
     * @param signing_keypair $pair The pair.
     */
    public static function save(signing_keypair $pair): void {
        set_config(self::SECRET, \core\encryption::encrypt(base64_encode($pair->export_secret())), 'tool_secbackup');
    }

    /**
     * Loads the saved key pair.
     *
     * @return signing_keypair|null Null when none is saved.
     * @throws key_exception When the stored value cannot be decrypted (for example when the key of the data root
     *     was lost) or is not a key. The debug information of the first case is the error code of Moodle.
     */
    public static function load(): ?signing_keypair {
        $stored = get_config('tool_secbackup', self::SECRET);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        try {
            $secret = base64_decode(\core\encryption::decrypt($stored), true);
        } catch (\moodle_exception $e) {
            throw new key_exception('signingkey_corrupt', null, $e->errorcode);
        }
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new key_exception('signingkey_corrupt');
        }
        return new signing_keypair($secret);
    }

    /**
     * Removes the saved key pair.
     */
    public static function forget(): void {
        unset_config(self::SECRET, 'tool_secbackup');
    }
}
