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
     * The type of $pair is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()). The attribute keeps the pair out of the trace of the exception thrown here.
     *
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The pair.
     * @throws key_exception When the secret cannot be encrypted: Moodle has no encryption key in the data root and may
     *     not create one, or anything else fails inside the encryption. The failure is not chained, because the
     *     arguments in its trace include the secret; the debug information is only its class name.
     */
    public static function save(#[\SensitiveParameter] signing_keypair $pair): void {
        try {
            $wrapped = \core\encryption::encrypt(base64_encode($pair->export_secret()));
        } catch (\Throwable $e) {
            throw new key_exception('signingkey_savefailed', null, $e::class);
        }
        set_config(self::SECRET, $wrapped, 'tool_secbackup');
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
