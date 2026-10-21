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
 * The site's key for the names of the chunks (chunk_name), kept in config_plugins and wrapped with Moodle's
 * \core\encryption, like the signing key (signing_keys).
 *
 * A restore does not need it: a lost key only gives the chunks new names, so the next backup sends them again. It is made
 * at the start of the first backup. Only one backup runs at a time (the lock of the run), so two keys are not made at once.
 *
 * A key belongs to a registration: the site ID and the fingerprint of the encryption public key (owner()), stored with it.
 * A registration again of the same site with the same encryption key keeps the key, so that the chunks that the storage holds
 * keep their names; any other registration gets a new one (chunks sealed for an old encryption key must not keep being used).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dedup_key {
    /** @var string Config name of the wrapped key. */
    private const NAME = 'dedupsecret';

    /** @var string Config name of the registration that the key belongs to. */
    private const OWNER = 'dedupowner';

    /**
     * The registration that a key belongs to.
     *
     * @param string $siteid The site ID at the service.
     * @param string $rawpublickey The encryption public key of the site (32 bytes).
     * @return string The site ID and the fingerprint of the key.
     */
    public static function owner(string $siteid, string $rawpublickey): string {
        return $siteid . ' ' . fingerprint::of($rawpublickey);
    }

    /**
     * Whether a key is stored and belongs to a registration.
     *
     * @param string $owner The registration, from owner().
     * @return bool
     */
    public static function belongs_to(string $owner): bool {
        $stored = get_config('tool_secbackup', self::NAME);
        return is_string($stored) && $stored !== '' && get_config('tool_secbackup', self::OWNER) === $owner;
    }

    /**
     * The key of a registration, made and saved when there is none for it (a key of another registration is replaced).
     *
     * @param string $owner The registration, from owner().
     * @return string 32 bytes.
     * @throws key_exception With dedupkey_corrupt when the stored value cannot be decrypted (the debug information is the
     *     error code of Moodle) or is not a key; it is not replaced. With dedupkey_savefailed when a new key cannot be
     *     encrypted; the failure is not chained, because the arguments in its trace include the key, and the debug
     *     information is only its class name.
     */
    public static function load_or_create(string $owner): string {
        if (self::belongs_to($owner)) {
            $stored = get_config('tool_secbackup', self::NAME);
            try {
                $key = base64_decode(\core\encryption::decrypt($stored), true);
            } catch (\moodle_exception $e) {
                throw new key_exception('dedupkey_corrupt', null, $e->errorcode);
            }
            if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES) {
                throw new key_exception('dedupkey_corrupt');
            }
            return $key;
        }
        $key = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
        try {
            $wrapped = \core\encryption::encrypt(base64_encode($key));
        } catch (\Throwable $e) {
            throw new key_exception('dedupkey_savefailed', null, $e::class);
        }
        set_config(self::NAME, $wrapped, 'tool_secbackup');
        set_config(self::OWNER, $owner, 'tool_secbackup');
        return $key;
    }

    /**
     * Removes the saved key and its registration.
     */
    public static function forget(): void {
        unset_config(self::NAME, 'tool_secbackup');
        unset_config(self::OWNER, 'tool_secbackup');
    }
}
