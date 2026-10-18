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
 * Writes one encrypted chunk to a file, in the format of section 7.2 of the design of the backup service.
 *
 * The file:
 *
 * - bytes 0 to 3: "SECB"; byte 4: the version of the format, 1; byte 5: the type (TYPE_DB, TYPE_FILES, TYPE_CODE or
 *   TYPE_MANIFEST);
 * - bytes 6 to 85: the data key of the chunk (32 random bytes), sealed with crypto_box_seal for the site's X25519 public
 *   key (80 bytes);
 * - from byte 86: the items, one after the other.
 *
 * An item is a header of crypto_secretstream_xchacha20poly1305 (24 bytes, its own random nonce under the data key of the
 * chunk) and frames, each encrypted with the additional data "secbackup:v1|" + site ID + "|" + chunk name + "|" + type, and
 * 17 bytes longer than its plaintext:
 *
 * - the length of the payload, 8 bytes big-endian, alone in the first frame;
 * - the payload, in frames of FRAME bytes, the last one shorter;
 * - in the last item of the chunk only, the padding: zero bytes, in frames of FRAME bytes, the last one shorter.
 *
 * The last frame of an item has TAG_FINAL, every other frame TAG_MESSAGE. The padding makes the plaintext of the whole chunk
 * (the length prefixes, payloads and padding of all its items) padme::length() of the plaintext without padding. The
 * additional data has no number of the item: the content hashes in the manifest find items that were swapped.
 *
 * Why the frames are cut there: given only the offset of an item (as chunk_reader is), a reader knows the size of the first
 * frame, learns the length of the payload from it and so the sizes of the payload frames, and the tag of the last payload
 * frame says whether padding follows. Padding in the same frame as the end of the payload would hide where the payload
 * frames end. Padding only ever follows the last item, so it runs to the end of the file.
 *
 * add_item() reads its source frame by frame and writes each frame at once, except the last frame of the item: it holds that
 * one back, because only the next add_item() (TAG_FINAL, no padding) or finish() (the padding, which needs the length of
 * every item) knows how the item ends. So at most one frame of plaintext is in memory, and the length that add_item()
 * returns is the length of the item without padding; finish() gives the final lengths in chunk_info::$items.
 *
 * The file is written as <path>.part in the same folder and gets its name in finish(). abort() gives the chunk up (for
 * example when the content of a file does not match its hash), and so does any failure and a writer that is destroyed
 * before finish(): the part file is removed, the keys are wiped and the writer cannot be used again. The data key and the
 * state of the secretstream stay in properties and are never passed to a method of this class, so the trace of an
 * exception does not show them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunk_writer {
    /** @var int Type of a chunk with a block of a table. */
    public const TYPE_DB = 1;

    /** @var int Type of a chunk with files (of the file directory and the rest of the data root). */
    public const TYPE_FILES = 2;

    /** @var int Type of a chunk with code (added plugins and the differences of the core). */
    public const TYPE_CODE = 3;

    /** @var int Type of the chunk with the manifest of a run. */
    public const TYPE_MANIFEST = 4;

    /** @var int Bytes of plaintext in a frame, except the last of the payload or of the padding. */
    public const FRAME = 1048576;

    /** @var string The first four bytes of a chunk. */
    public const MAGIC = 'SECB';

    /** @var int Version of the format. */
    public const VERSION = 1;

    /** @var int Bytes before the first item: the magic, the version, the type and the sealed data key. */
    public const HEADER = 86;

    /** @var int Bytes of the length prefix of an item. */
    public const PREFIX = 8;

    /** @var int Tag of a frame after which the item goes on. */
    private const MESSAGE = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

    /** @var int Tag of the last frame of an item. */
    private const FINAL = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

    /** @var int Bytes that the encryption adds to a frame. */
    private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    /** @var string Pattern of a chunk name. It ends with \z: $ also matches before a final line feed. */
    private const NAME = '/^[0-9a-f]{64}\z/';

    /** @var string Pattern of a site ID. */
    private const SITE = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

    /** @var string Final path. */
    private string $path;

    /** @var string Path of the file while it is written. */
    private string $part;

    /** @var string Name of the chunk. */
    private string $name;

    /** @var int Type of the chunk. */
    private int $type;

    /** @var string Additional data of every frame. */
    private string $aad;

    /** @var bool Whether items can still be added. */
    private bool $open = false;

    /** @var resource|null The part file. */
    private $handle = null;

    /** @var string|null Data key of the chunk, until the writer ends. */
    private ?string $key = null;

    /** @var string|null State of the secretstream of the item that is being written. */
    private ?string $state = null;

    /** @var string|null Plaintext of the last frame of that item, not written yet. */
    private ?string $held = null;

    /** @var \HashContext SHA-256 of the bytes written so far. */
    private \HashContext $hash;

    /** @var int Bytes written so far. */
    private int $size = 0;

    /** @var int Bytes of plaintext of the items without padding: 8 + the length of each. */
    private int $plain = 0;

    /** @var array[] Offsets and lengths of the items. */
    private array $items = [];

    /**
     * Creates the part file and writes the header of the chunk.
     *
     * @param string $path Final path of the chunk.
     * @param int $type TYPE_DB, TYPE_FILES, TYPE_CODE or TYPE_MANIFEST.
     * @param string $name Name of the chunk (chunk_name).
     * @param string $sitepublickey The site's X25519 encryption public key (32 bytes).
     * @param string $siteid Site ID at the backup service.
     * @throws \coding_exception For a wrong argument.
     * @throws key_exception With publickey_invalid for a public key that cannot encrypt (a point of low order).
     * @throws plugin_exception With temp_write_failed when the part file cannot be created or written.
     */
    public function __construct(string $path, int $type, string $name, string $sitepublickey, string $siteid) {
        if (!self::valid_type($type)) {
            throw new \coding_exception('Unknown type of chunk.');
        }
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \coding_exception('A chunk name has 64 lower-case hexadecimal digits.');
        }
        if (preg_match(self::SITE, $siteid) !== 1) {
            throw new \coding_exception('A site ID is a lower-case UUID.');
        }
        if (strlen($sitepublickey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \coding_exception('A public key has 32 bytes.');
        }
        $this->path = $path;
        $this->part = $path . '.part';
        $this->name = $name;
        $this->type = $type;
        $this->aad = self::additional_data($siteid, $name, $type);
        $this->hash = hash_init('sha256');
        $part = $this->part;
        $handle = self::quietly(static fn() => fopen($part, 'wb'), $warning);
        if ($handle === false) {
            throw self::write_failed('open', $warning);
        }
        $this->handle = $handle;
        $this->open = true;
        $this->key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $this->guarded(function () use ($sitepublickey): void {
            try {
                $sealed = sodium_crypto_box_seal($this->key, $sitepublickey);
            } catch (\SodiumException $e) {
                // Not chained: libsodium's message ("internal error") tells no more than the error code does.
                throw new key_exception('publickey_invalid');
            }
            $this->write(self::MAGIC . chr(self::VERSION) . chr($this->type) . $sealed);
        });
    }

    /**
     * Whether a number is a type of chunk.
     *
     * @param int $type The number.
     * @return bool
     */
    public static function valid_type(int $type): bool {
        return $type >= self::TYPE_DB && $type <= self::TYPE_MANIFEST;
    }

    /**
     * The additional data of every frame of a chunk.
     *
     * @param string $siteid Site ID.
     * @param string $name Name of the chunk.
     * @param int $type Type of the chunk.
     * @return string
     */
    public static function additional_data(string $siteid, string $name, int $type): string {
        return 'secbackup:v1|' . $siteid . '|' . $name . '|' . $type;
    }

    /**
     * Adds an item: reads its payload from the source and encrypts it.
     *
     * @param resource|string $source The payload, or an open stream from which its bytes are read (from where it stands).
     * @param int $length Bytes of the payload.
     * @param \HashContext|null $digest Updated with every byte of the payload, as it is read.
     * @return array ['offset' => byte offset of the item in the chunk, 'length' => its bytes, without the padding that
     *     finish() adds when this is the last item].
     * @throws \coding_exception When the writer has ended, the length is negative, a string is not $length bytes long or
     *     the source is neither a string nor an open stream.
     * @throws plugin_exception With crypto_chunk_sourceshort when the stream ends or fails before $length bytes, and with
     *     temp_write_failed when the part file cannot be written. After any exception the chunk is given up.
     */
    public function add_item($source, int $length, ?\HashContext $digest = null): array {
        $this->check_open();
        return $this->guarded(function () use ($source, $length, $digest): array {
            if ($length < 0) {
                throw new \coding_exception('A length is not negative.');
            }
            if (is_string($source)) {
                if (strlen($source) !== $length) {
                    throw new \coding_exception('A payload given as text has the given length.');
                }
            } else if (!is_resource($source)) {
                throw new \coding_exception('A payload is text or an open stream.');
            }
            $this->push_held(self::FINAL);
            $offset = $this->size;
            [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            $this->write($header);
            $this->held = pack('J', $length);
            $read = 0;
            foreach (self::frame_sizes($length) as $size) {
                // Another frame follows the one that is held, so it is written before the next one is read.
                $this->push_held(self::MESSAGE);
                $frame = self::read($source, $read, $size);
                $read += $size;
                if ($digest !== null) {
                    hash_update($digest, $frame);
                }
                $this->held = $frame;
            }
            $this->plain += self::PREFIX + $length;
            // The held frame is the rest of the item; with its 17 bytes of authentication it will be written next.
            $item = ['offset' => $offset, 'length' => $this->size - $offset + strlen($this->held) + self::ABYTES];
            $this->items[] = $item;
            return $item;
        });
    }

    /**
     * Pads and ends the last item, and gives the file its name.
     *
     * @return chunk_info
     * @throws \coding_exception When the writer has ended or no item was added.
     * @throws plugin_exception With temp_write_failed when the part file cannot be written or renamed. After any exception
     *     the chunk is given up.
     */
    public function finish(): chunk_info {
        $this->check_open();
        $this->guarded(function (): void {
            if ($this->items === []) {
                throw new \coding_exception('A chunk holds at least one item.');
            }
            $padding = self::frame_sizes(padme::length($this->plain) - $this->plain);
            $this->push_held($padding === [] ? self::FINAL : self::MESSAGE);
            $lastframe = array_key_last($padding);
            foreach ($padding as $index => $size) {
                $this->push(str_repeat("\0", $size), $index === $lastframe ? self::FINAL : self::MESSAGE);
            }
            $last = array_key_last($this->items);
            $this->items[$last]['length'] = $this->size - $this->items[$last]['offset'];
            fclose($this->handle);
            $part = $this->part;
            $path = $this->path;
            $renamed = self::quietly(static fn() => rename($part, $path), $warning);
            if (!$renamed) {
                throw self::write_failed('rename', $warning);
            }
        });
        $this->end();
        return new chunk_info($this->path, $this->name, $this->size, base64_encode(hash_final($this->hash, true)), $this->items);
    }

    /**
     * The sizes of the frames for some bytes of plaintext: whole frames, then the rest, if any.
     *
     * @param int $length Bytes.
     * @return int[]
     */
    private static function frame_sizes(int $length): array {
        $sizes = array_pad([], intdiv($length, self::FRAME), self::FRAME);
        $rest = $length % self::FRAME;
        if ($rest > 0) {
            $sizes[] = $rest;
        }
        return $sizes;
    }

    /**
     * Reads the bytes of one frame of a payload.
     *
     * @param resource|string $source The payload, or a stream.
     * @param int $offset Offset of the frame in a payload given as text.
     * @param int $length Bytes of the frame.
     * @return string
     * @throws plugin_exception With crypto_chunk_sourceshort when the stream gives fewer bytes; the debug information is
     *     the warning of PHP about the failed read, if there was one (none at the end of the stream).
     */
    private static function read($source, int $offset, int $length): string {
        $warning = '';
        $data = is_string($source) ? substr($source, $offset, $length)
            : self::quietly(static fn() => stream_get_contents($source, $length), $warning);
        if (!is_string($data) || strlen($data) !== $length) {
            throw new plugin_exception('crypto_chunk_sourceshort', null, $warning === '' ? null : $warning);
        }
        return $data;
    }

    /**
     * Encrypts the frame that is held back, if there is one, with a tag.
     *
     * @param int $tag TAG_FINAL to end the item, TAG_MESSAGE when padding follows or the payload goes on.
     */
    private function push_held(int $tag): void {
        if ($this->held !== null) {
            $this->push($this->held, $tag);
            $this->held = null;
        }
    }

    /**
     * Encrypts and writes a frame.
     *
     * @param string $plaintext Plaintext of the frame.
     * @param int $tag Tag.
     */
    private function push(string $plaintext, int $tag): void {
        $this->write(sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plaintext, $this->aad, $tag));
    }

    /**
     * Writes bytes to the part file.
     *
     * @param string $bytes Bytes.
     * @throws plugin_exception With temp_write_failed when not all of them are written.
     */
    private function write(string $bytes): void {
        $handle = $this->handle;
        $written = self::quietly(static fn() => fwrite($handle, $bytes), $warning);
        if ($written !== strlen($bytes)) {
            throw self::write_failed('write', $warning);
        }
        hash_update($this->hash, $bytes);
        $this->size += $written;
    }

    /**
     * Gives up the chunk: closes and removes the part file and wipes the keys. It does nothing once the writer has ended,
     * so it can be called again, and after finish().
     */
    public function abort(): void {
        if (!$this->open) {
            return;
        }
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $part = $this->part;
        self::quietly(static fn() => unlink($part), $warning);
        $this->end();
    }

    /**
     * Gives up a chunk that was not finished.
     */
    public function __destruct() {
        $this->abort();
    }

    /**
     * Runs a step of the writing; on any exception, gives up the chunk and throws the exception again.
     *
     * @param \Closure $step The step.
     * @return mixed What the step returns.
     */
    private function guarded(\Closure $step): mixed {
        try {
            return $step();
        } catch (\Throwable $e) {
            $this->abort();
            throw $e;
        }
    }

    /**
     * Wipes the keys and closes the writer for good.
     */
    private function end(): void {
        sodium_memzero($this->key);
        if ($this->state !== null) {
            sodium_memzero($this->state);
        }
        $this->held = null;
        $this->open = false;
    }

    /**
     * Refuses a writer that has ended.
     *
     * @throws \coding_exception When it has.
     */
    private function check_open(): void {
        if (!$this->open) {
            throw new \coding_exception('The chunk was already finished or given up.');
        }
    }

    /**
     * Runs a file operation without letting PHP report its warning, and keeps the text of the warning.
     *
     * @param \Closure $operation The operation.
     * @param string|null $warning Set to the text of the last warning, or to an empty string.
     * @return mixed What the operation returns.
     */
    private static function quietly(\Closure $operation, ?string &$warning): mixed {
        $warning = '';
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The exception of a failed file operation.
     *
     * @param string $operation What failed: open, write or rename.
     * @param string $warning Warning of PHP, which names the file (the temporary folder holds no secret).
     * @return plugin_exception
     */
    private static function write_failed(string $operation, string $warning): plugin_exception {
        return new plugin_exception('temp_write_failed', null, $operation . ': ' . $warning);
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
        return ['path' => $this->path, 'name' => $this->name, 'type' => $this->type, 'size' => $this->size];
    }

    /**
     * Refuses to be copied: the copy would share the part file and the keys, and destroying it would give up the chunk of
     * the original.
     *
     * PHP destroys the refused copy at once, so the copy first marks itself as ended, which makes its destructor do nothing.
     *
     * @throws \coding_exception Always.
     */
    public function __clone() {
        $this->open = false;
        throw new \coding_exception('A chunk writer must not be cloned.');
    }

    /**
     * Refuses to be serialised: the data key would be kept.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __serialize(): array {
        throw new \coding_exception('A chunk writer must not be serialised.');
    }

    /**
     * Refuses to be serialised, for code that calls the older hook. PHP calls __serialize() first when both exist.
     *
     * @return array
     * @throws \coding_exception Always.
     */
    public function __sleep(): array {
        throw new \coding_exception('A chunk writer must not be serialised.');
    }

    /**
     * Refuses to be built from a serialised string, which would skip the constructor.
     *
     * @param array $data The serialised properties, ignored.
     * @throws \coding_exception Always.
     */
    public function __unserialize(array $data): void {
        throw new \coding_exception('A chunk writer must not be unserialised.');
    }
}
