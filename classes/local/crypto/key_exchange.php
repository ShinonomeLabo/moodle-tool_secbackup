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

use tool_secbackup\local\plugin_exception;

/**
 * A one-time X25519 key pair with which a restore receives the secret keys of the site from the backup service.
 *
 * The restore gives public_key() to the backup service, which seals each key of the site to it with crypto_box_seal, and
 * open() opens them. The one-time secret key opens one list only: open() wipes it, whether the list is accepted or refused,
 * and a second call is a coding error. The secret keys of the site then live only in the site_keys that open() returns, so
 * nothing that carries the sealed keys can open them afterwards.
 *
 * The one-time key pair is kept in a map of the class keyed by the object, not in a property, for the reason given in
 * site_keys. An exchange that is never used wipes it when it is destroyed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class key_exchange {
    /** @var \WeakMap|null The one-time key pair of each object (the secret key, then the public key), until open(). */
    private static ?\WeakMap $pairs = null;

    /** @var string The one-time public key (32 bytes). */
    private string $public;

    /**
     * Makes the one-time key pair.
     */
    public function __construct() {
        $pair = sodium_crypto_box_keypair();
        $this->public = sodium_crypto_box_publickey($pair);
        self::$pairs ??= new \WeakMap();
        self::$pairs[$this] = $pair;
    }

    /**
     * The one-time public key, to which the keys of the site are sealed.
     *
     * @return string 32 bytes.
     */
    public function public_key(): string {
        return $this->public;
    }

    /**
     * Opens the sealed secret keys of the site, and wipes the one-time secret key.
     *
     * The type of $sealedbase64 is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()); it is plain "array" for PHP.
     *
     * @param #[|\SensitiveParameter|]|array $sealedbase64 A list of 1 to 10 sealed keys in standard base64, the newest first.
     * @return site_keys The keys, in the same order.
     * @throws plugin_exception With restore_keys_invalid when the list is not a list of 1 to 10 texts, or a text is not
     *     canonical standard base64, or is not sealed to this exchange, or does not hold 32 bytes. Every key that was opened
     *     is wiped then.
     * @throws \coding_exception When open() was called before.
     */
    public function open(#[\SensitiveParameter] array $sealedbase64): site_keys {
        // The parameter is unset before anything can throw: the trace of an exception shows it otherwise, and
        // #[\SensitiveParameter] hides it only from PHP 8.2 on (see chunk_reader::__construct()).
        $sealed = $sealedbase64;
        unset($sealedbase64);
        $pair = self::$pairs[$this] ?? null;
        if ($pair === null) {
            throw new \coding_exception('The keys of an exchange can be opened only once.');
        }
        // The map lets go of its copy, so that the pair is not shared when it is wiped, and the exchange cannot open again.
        unset(self::$pairs[$this]);
        $keys = [];
        try {
            if (!array_is_list($sealed) || count($sealed) < 1 || count($sealed) > site_keys::MAX_KEYS) {
                throw self::invalid();
            }
            foreach ($sealed as $index => $text) {
                try {
                    // Unlike base64_decode(), which skips white space even when it is strict, libsodium takes only the
                    // canonical form: the padding, and no bits set that the encoder leaves at zero.
                    $box = is_string($text) ? sodium_base642bin($text, SODIUM_BASE64_VARIANT_ORIGINAL) : '';
                } catch (\SodiumException $e) {
                    $box = '';
                }
                // Kept before it is checked, so that the finally block wipes it too. A seal that does not open gives false,
                // which becomes '': sodium_memzero() throws for anything but a string.
                $keys[$index] = (string) sodium_crypto_box_seal_open($box, $pair);
                if (strlen($keys[$index]) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                    throw self::invalid();
                }
            }
            return new site_keys($keys);
        } finally {
            sodium_memzero($pair);
            // When the keys were accepted, site_keys holds them too: wiping a shared string only lets go of this copy.
            foreach (array_keys($keys) as $index) {
                sodium_memzero($keys[$index]);
            }
        }
    }

    /**
     * The exception of keys that cannot be used.
     *
     * @return plugin_exception
     */
    private static function invalid(): plugin_exception {
        return new plugin_exception('restore_keys_invalid');
    }

    /**
     * Wipes the one-time key pair when open() was not called.
     */
    public function __destruct() {
        $pair = self::$pairs[$this] ?? null;
        if ($pair !== null) {
            unset(self::$pairs[$this]);
            sodium_memzero($pair);
        }
    }

    /**
     * Keeps the one-time secret key out of print_r(), var_dump() and debugging output.
     *
     * PHP matches magic method names without regard to case. The lower-case spelling is the one the Moodle code checker
     * accepts for this method.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['public' => base64_encode($this->public), 'used' => !isset(self::$pairs[$this])];
    }

    /**
     * Refuses to be copied: the copy would have no one-time secret key.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        throw new \coding_exception('A key exchange must not be cloned.');
    }

    /**
     * Refuses to be serialised.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A key exchange must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A key exchange must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A key exchange must not be unserialised.');
    }
}
