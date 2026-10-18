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
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dedup_key {
    /** @var string Config name of the wrapped key. */
    private const NAME = 'dedupsecret';

    /**
     * The key, made and saved when there is none.
     *
     * @return string 32 bytes.
     * @throws key_exception With dedupkey_corrupt when the stored value cannot be decrypted (the debug information is the
     *     error code of Moodle) or is not a key; it is not replaced. With dedupkey_savefailed when a new key cannot be
     *     encrypted; the failure is not chained, because the arguments in its trace include the key, and the debug
     *     information is only its class name.
     */
    public static function load_or_create(): string {
        $stored = get_config('tool_secbackup', self::NAME);
        if (is_string($stored) && $stored !== '') {
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
        return $key;
    }

    /**
     * Removes the saved key.
     */
    public static function forget(): void {
        unset_config(self::NAME, 'tool_secbackup');
    }
}
