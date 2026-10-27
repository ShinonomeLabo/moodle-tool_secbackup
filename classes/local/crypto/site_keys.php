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
 * The X25519 encryption keys of a site that a restore holds in memory, and the reader of a chunk with the key that opens it.
 *
 * A site whose key was made again has chunks of more than one key in one run: the chunks that the run shares with older runs
 * keep the key they were sealed with. So reader() tries the keys in the order they were given, which is the newest first.
 *
 * The keys are kept in a map of the class keyed by the object, not in a property: var_export() and a cast to an array show
 * the private properties of an object, which __debugInfo() hides only from print_r() and var_dump(). They are wiped when the
 * object is destroyed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_keys {
    /** @var int The most keys that the backup service gives a restore, the newest first. */
    public const MAX_KEYS = 10;

    /** @var \WeakMap|null The key pairs of each object: [secret key, public key] each, in the order of trying. */
    private static ?\WeakMap $pairs = null;

    /**
     * Keeps the keys.
     *
     * The type of $secretkeys is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()); it is plain "array" for PHP.
     *
     * @param #[|\SensitiveParameter|]|array $secretkeys A list of 1 to 10 X25519 secret keys of 32 bytes, the newest first.
     * @throws \coding_exception For another list.
     */
    public function __construct(#[\SensitiveParameter] array $secretkeys) {
        // The parameter is unset before anything can throw: the trace of an exception shows it otherwise, and
        // #[\SensitiveParameter] hides it only from PHP 8.2 on (see chunk_reader::__construct()).
        $secrets = $secretkeys;
        unset($secretkeys);
        if (!array_is_list($secrets) || count($secrets) < 1 || count($secrets) > self::MAX_KEYS) {
            throw self::wrong_keys();
        }
        $pairs = [];
        foreach ($secrets as $secret) {
            if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
                throw self::wrong_keys();
            }
            $pairs[] = [$secret, sodium_crypto_box_publickey_from_secretkey($secret)];
        }
        self::$pairs ??= new \WeakMap();
        self::$pairs[$this] = $pairs;
    }

    /**
     * The exception of a list that is not site keys.
     *
     * @return \coding_exception
     */
    private static function wrong_keys(): \coding_exception {
        return new \coding_exception('Site keys are 1 to 10 X25519 secret keys of 32 bytes, in a list.');
    }

    /**
     * The number of keys.
     *
     * @return int
     */
    public function count(): int {
        return count(self::$pairs[$this]);
    }

    /**
     * Opens a chunk with the first key that opens it.
     *
     * The header of a chunk holds its data key sealed to one key of the site, so it tells which key opens the chunk. The site,
     * the name and the type are in the authenticated data of every frame, so the first item, which every chunk has at offset 86,
     * is read too: a chunk of another site or put in the place of another chunk does not open, even with the right key.
     * That tells one chunk from another, not where its bytes come from: the seal does not authenticate its sender, so anybody
     * who knows the public key of the site can make a chunk that opens. The digests in the signed manifest show that.
     *
     * @param string $path Path of the chunk.
     * @param string $siteid Site ID at the backup service.
     * @param string $name Name of the chunk.
     * @return chunk_reader A reader that has read the first item.
     * @throws plugin_exception With restore_chunk_missing when there is no file at the path, and with restore_chunk_corrupt
     *     when no key opens the chunk or its first item (a chunk that is damaged, cut short, of another site or of another
     *     name, or sealed with a key that was not given).
     */
    public function reader(string $path, string $siteid, string $name): chunk_reader {
        if (!is_file($path)) {
            throw new plugin_exception('restore_chunk_missing');
        }
        foreach (self::$pairs[$this] as [$secret, $public]) {
            try {
                $reader = new chunk_reader($path, $secret, $public, $siteid, $name);
                $reader->read_item(chunk_writer::HEADER);
                return $reader;
            } catch (plugin_exception $e) {
                // The chunk reader throws only crypto_chunk_corrupt and crypto_chunk_truncated: the next key is tried. The
                // exception is not chained to the one below, so that its trace stays here.
                unset($e);
            }
        }
        throw new plugin_exception('restore_chunk_corrupt');
    }

    /**
     * Wipes the keys.
     */
    public function __destruct() {
        $pairs = self::$pairs[$this] ?? [];
        // The map lets go of its copy first, so that the strings are not shared when they are wiped.
        unset(self::$pairs[$this]);
        foreach (array_keys($pairs) as $index) {
            sodium_memzero($pairs[$index][0]);
            sodium_memzero($pairs[$index][1]);
        }
    }

    /**
     * Keeps the keys out of print_r(), var_dump() and debugging output.
     *
     * PHP matches magic method names without regard to case. The lower-case spelling is the one the Moodle code checker
     * accepts for this method.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['count' => $this->count()];
    }

    /**
     * Refuses to be copied: the copy would hold no keys.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        throw new \coding_exception('Site keys must not be cloned.');
    }

    /**
     * Refuses to be serialised.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('Site keys must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('Site keys must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('Site keys must not be unserialised.');
    }
}
