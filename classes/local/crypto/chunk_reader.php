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
 * Reads the items of a chunk that chunk_writer wrote, with the site's encryption key pair. For the tests and for restoring.
 *
 * The constructor reads the header and opens the sealed data key. read_item() decrypts the frames of one item one by one, in
 * the sizes that the format gives (see chunk_writer), and checks the structure of the item as well as the authentication of
 * every frame: a frame that does not authenticate (a changed byte, another site, another name or another type) or an item
 * that does not have the form that chunk_writer writes is crypto_chunk_corrupt; a file that ends before the last frame of the
 * item is crypto_chunk_truncated.
 *
 * Anybody who can change a chunk at the storage can make these exceptions, so their traces must not show a secret: the
 * data key and the state of the secretstream stay in properties and are never passed to a method of this class, and the
 * constructor unsets its parameter with the site's secret key before anything can throw. The state of an item is wiped when
 * read_item() returns or throws, and the data key and the file are let go when the reader is destroyed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunk_reader {
    /** @var int Tag of a frame after which the item goes on. */
    private const MESSAGE = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

    /** @var int Tag of the last frame of an item. */
    private const FINAL = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

    /** @var int Bytes that the encryption adds to a frame. */
    private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    /** @var string Path of the chunk. */
    private string $path;

    /** @var resource The open file. */
    private $handle;

    /** @var int Size of the file. */
    private int $size;

    /** @var int Type of the chunk. */
    private int $type;

    /** @var string|null Data key of the chunk, until the reader is destroyed. */
    private ?string $key = null;

    /** @var string|null State of the secretstream of the item that is being read. */
    private ?string $state = null;

    /** @var string Additional data of every frame. */
    private string $aad;

    /**
     * Opens a chunk.
     *
     * The type of $sitesecretkey is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()); it is plain "string" for PHP.
     *
     * @param string $path Path of the chunk.
     * @param #[|\SensitiveParameter|]|string $sitesecretkey The site's X25519 secret key (32 bytes).
     * @param string $sitepublickey The site's X25519 public key (32 bytes).
     * @param string $siteid Site ID at the backup service.
     * @param string $name Name of the chunk.
     * @throws \coding_exception When a key does not have 32 bytes or the file cannot be opened.
     * @throws plugin_exception With crypto_chunk_truncated when the file is shorter than the header, and with
     *     crypto_chunk_corrupt for another format, version or type, or a data key that the key pair cannot open.
     */
    public function __construct(
        string $path,
        #[\SensitiveParameter] string $sitesecretkey,
        string $sitepublickey,
        string $siteid,
        string $name
    ) {
        // The trace of an exception shows the parameters as they are when the exception is made, and #[\SensitiveParameter]
        // hides them only from PHP 8.2 on. So the key moves to a local variable and the parameter is unset before anything
        // below can throw. An assignment such as $sitesecretkey = '' would not do: it is a dead store, which the optimiser
        // of OPcache removes.
        $secretkey = $sitesecretkey;
        unset($sitesecretkey);
        if (
            strlen($secretkey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES
            || strlen($sitepublickey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
        ) {
            throw new \coding_exception('An X25519 key has 32 bytes.');
        }
        $handle = is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new \coding_exception('The chunk cannot be opened.');
        }
        $this->path = $path;
        $this->handle = $handle;
        $this->size = fstat($handle)['size'];
        $header = $this->read(chunk_writer::HEADER);
        $type = ord($header[5]);
        if (
            !str_starts_with($header, chunk_writer::MAGIC)
            || ord($header[4]) !== chunk_writer::VERSION
            || !chunk_writer::valid_type($type)
        ) {
            throw self::corrupt();
        }
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretkey, $sitepublickey);
        $key = sodium_crypto_box_seal_open(substr($header, 6), $pair);
        sodium_memzero($pair);
        if ($key === false) {
            throw self::corrupt();
        }
        $this->key = $key;
        $this->type = $type;
        $this->aad = chunk_writer::additional_data($siteid, $name, $type);
    }

    /**
     * The type of the chunk.
     *
     * @return int chunk_writer::TYPE_DB, TYPE_FILES, TYPE_CODE or TYPE_MANIFEST.
     */
    public function type(): int {
        return $this->type;
    }

    /**
     * Decrypts one item.
     *
     * @param int $offset Byte offset of the item (chunk_info::$items).
     * @return string The payload, without the length prefix and the padding.
     * @throws \coding_exception When the offset is inside the header.
     * @throws plugin_exception With crypto_chunk_corrupt or crypto_chunk_truncated.
     */
    public function read_item(int $offset): string {
        if ($offset < chunk_writer::HEADER) {
            throw new \coding_exception('An item starts at offset 86 or later.');
        }
        fseek($this->handle, $offset);
        $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
            $this->read(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),
            $this->key
        );
        try {
            [$prefix, $tag] = $this->pull(chunk_writer::PREFIX + self::ABYTES);
            $length = unpack('J', $prefix)[1];
            if ($length < 0) {
                throw self::corrupt();
            }
            // The payload takes at least as many bytes as it is long: no need to decrypt up to the end of the file to see
            // that they are not there.
            if ($length > $this->size - ftell($this->handle)) {
                throw new plugin_exception('crypto_chunk_truncated');
            }
            $payload = '';
            for ($left = $length; $left > 0; $left -= $size) {
                // The frame before has to leave the item open.
                if ($tag !== self::MESSAGE) {
                    throw self::corrupt();
                }
                $size = min(chunk_writer::FRAME, $left);
                [$plaintext, $tag] = $this->pull($size + self::ABYTES);
                $payload .= $plaintext;
            }
            // An item that is still open has padding: only the last item has, so it runs to the end of the file.
            while ($tag !== self::FINAL) {
                if ($tag !== self::MESSAGE) {
                    throw self::corrupt();
                }
                $left = $this->size - ftell($this->handle);
                if ($left === 0) {
                    throw new plugin_exception('crypto_chunk_truncated');
                }
                [$padding, $tag] = $this->pull(min(chunk_writer::FRAME + self::ABYTES, $left));
                if (ltrim($padding, "\0") !== '' || ($tag === self::FINAL && ftell($this->handle) !== $this->size)) {
                    throw self::corrupt();
                }
            }
            return $payload;
        } finally {
            sodium_memzero($this->state);
        }
    }

    /**
     * Reads and decrypts one frame with the state of the item, which it updates.
     *
     * @param int $length Bytes of the encrypted frame.
     * @return array [plaintext, tag]
     * @throws plugin_exception With crypto_chunk_corrupt when the frame does not authenticate.
     */
    private function pull(int $length): array {
        $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $this->read($length), $this->aad);
        if ($opened === false) {
            throw self::corrupt();
        }
        return $opened;
    }

    /**
     * Reads bytes from where the file stands.
     *
     * @param int $length Bytes.
     * @return string
     * @throws plugin_exception With crypto_chunk_truncated when the file ends before.
     */
    private function read(int $length): string {
        $data = stream_get_contents($this->handle, $length);
        if (!is_string($data) || strlen($data) !== $length) {
            throw new plugin_exception('crypto_chunk_truncated');
        }
        return $data;
    }

    /**
     * The exception of a chunk that is not as it was written.
     *
     * @return plugin_exception
     */
    private static function corrupt(): plugin_exception {
        return new plugin_exception('crypto_chunk_corrupt');
    }

    /**
     * Closes the file and wipes the data key.
     *
     * Also called by hand, it can be called again: the second call does nothing.
     */
    public function __destruct() {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        if ($this->key !== null) {
            sodium_memzero($this->key);
        }
    }

    /**
     * Keeps the data key out of print_r(), var_dump() and debugging output.
     *
     * PHP matches magic method names without regard to case. The lower-case spelling is the one the Moodle code checker
     * accepts for this method.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['path' => $this->path, 'type' => $this->type, 'size' => $this->size];
    }

    /**
     * Refuses to be copied: the copy would share the file and the data key, and destroying it would close the file and
     * wipe the key of the original.
     *
     * PHP destroys the refused copy at once, so the copy first lets go of the file and the key.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        $this->handle = null;
        $this->key = null;
        throw new \coding_exception('A chunk reader must not be cloned.');
    }

    /**
     * Refuses to be serialised: the data key would be kept.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A chunk reader must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A chunk reader must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A chunk reader must not be unserialised.');
    }
}
