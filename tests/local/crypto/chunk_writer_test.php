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
use tool_secbackup\tests\trace_strings;

defined('MOODLE_INTERNAL') || die();

// The stand-in for sodium_memzero() that records the wiped buffers (signing_keys_test::$wiped).
require_once(__DIR__ . '/signing_keys_test.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\chunk_writer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\chunk_reader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\chunk_info::class)]
/**
 * Tests for the format of the chunks: the writer, the reader and the description of a written chunk.
 *
 * The expected sizes are written as numbers, not with the constants of the classes: the format is fixed, and a change of
 * a constant on both sides would still read back.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\chunk_writer
 * @covers     \tool_secbackup\local\crypto\chunk_reader
 * @covers     \tool_secbackup\local\crypto\chunk_info
 */
final class chunk_writer_test extends \basic_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Name of the chunk of the tests. */
    private const NAME = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';

    /** @var int Bytes of the payload of one_item_chunk(). */
    private const ONE_ITEM_PAYLOAD = 1000;

    /** @var int Tag of a frame that does not end the item. */
    private const MESSAGE = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

    /** @var int Tag of the last frame of an item. */
    private const FINAL = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

    /** @var int Tag that the format does not use. */
    private const PUSH = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH;

    /**
     * The encryption key pair of the site of the tests.
     *
     * @return string
     */
    private static function pair(): string {
        return sodium_crypto_box_seed_keypair(str_repeat("\x07", 32));
    }

    /**
     * The public key of the site.
     *
     * @return string
     */
    private static function public_key(): string {
        return sodium_crypto_box_publickey(self::pair());
    }

    /**
     * The secret key of the site.
     *
     * @return string
     */
    private static function secret_key(): string {
        return sodium_crypto_box_secretkey(self::pair());
    }

    /**
     * The additional authenticated data of every frame, written out here.
     *
     * @param int $type Type of the chunk.
     * @return string
     */
    private static function aad(int $type): string {
        return 'secbackup:v1|' . self::SITE . '|' . self::NAME . '|' . $type;
    }

    /**
     * A writer for the site of the tests.
     *
     * @param string $path Final path.
     * @param int $type Type.
     * @return chunk_writer
     */
    private static function writer(string $path, int $type = chunk_writer::TYPE_FILES): chunk_writer {
        return new chunk_writer($path, $type, self::NAME, self::public_key(), self::SITE);
    }

    /**
     * A reader for the site of the tests.
     *
     * @param string $path Path.
     * @return chunk_reader
     */
    private static function reader(string $path): chunk_reader {
        return new chunk_reader($path, self::secret_key(), self::public_key(), self::SITE, self::NAME);
    }

    /**
     * Opens the sealed data key in the header of a chunk with the key pair of the site.
     *
     * @param string $path Path of the chunk.
     * @return string 32 bytes.
     */
    private function data_key(string $path): string {
        $key = sodium_crypto_box_seal_open((string) file_get_contents($path, false, null, 6, 80), self::pair());
        $this->assertIsString($key);
        $this->assertSame(32, strlen($key));
        return $key;
    }

    /**
     * Decrypts the frames of one item with libsodium, in the sizes that the test expects, and checks their tags.
     *
     * @param string $path Path of the chunk.
     * @param int $offset Offset of the item.
     * @param array $frames Expected frames: [plaintext length, tag] each.
     * @param int $type Type of the chunk.
     * @return string The plaintext of the item: the length prefix, the payload and the padding.
     */
    private function assert_frames(string $path, int $offset, array $frames, int $type): string {
        $length = 24;
        foreach ($frames as [$size]) {
            $length += $size + 17;
        }
        $bytes = (string) file_get_contents($path, false, null, $offset, $length);
        $this->assertSame($length, strlen($bytes));
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($bytes, 0, 24), $this->data_key($path));
        $position = 24;
        $plaintext = '';
        foreach ($frames as $index => [$size, $tag]) {
            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                substr($bytes, $position, $size + 17),
                self::aad($type)
            );
            $this->assertIsArray($opened, "frame $index");
            $this->assertSame($size, strlen($opened[0]), "frame $index");
            $this->assertSame($tag, $opened[1], "frame $index");
            $plaintext .= $opened[0];
            $position += $size + 17;
        }
        return $plaintext;
    }

    /**
     * Writes a chunk of one item of 1000 bytes and returns its path and payload.
     *
     * Its layout: the header (0 to 85), the item header (86 to 109), the frame of the length prefix (110 to 134), the
     * payload (135 to 1151) and one frame of 16 bytes of padding (1152 to 1184), since padme(8 + 1000) is 1024.
     *
     * @return array [path, payload]
     */
    private static function one_item_chunk(): array {
        $path = make_request_directory() . '/chunk';
        $payload = random_bytes(self::ONE_ITEM_PAYLOAD);
        $writer = self::writer($path);
        $writer->add_item($payload, self::ONE_ITEM_PAYLOAD);
        $writer->finish();
        return [$path, $payload];
    }

    /**
     * Builds a chunk by hand: the header and one item of the given frames, then any bytes.
     *
     * @param array $frames Frames of the item: [plaintext, tag] each.
     * @param string $trailer Bytes after the item.
     * @return string Path of the chunk.
     */
    private static function forge(array $frames, string $trailer = ''): string {
        $key = str_repeat("\x42", 32);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $bytes = 'SECB' . chr(1) . chr(2) . sodium_crypto_box_seal($key, self::public_key()) . $header;
        foreach ($frames as [$plaintext, $tag]) {
            $bytes .= sodium_crypto_secretstream_xchacha20poly1305_push($state, $plaintext, self::aad(2), $tag);
        }
        $path = make_request_directory() . '/forged';
        file_put_contents($path, $bytes . $trailer);
        return $path;
    }

    /**
     * Reads the item at an offset and returns the error code of the plugin_exception that the reader throws.
     *
     * @param string $path Path of the chunk.
     * @param int $offset Offset of the item.
     * @return string
     */
    private function failure_of(string $path, int $offset = 86): string {
        try {
            self::reader($path)->read_item($offset);
        } catch (plugin_exception $e) {
            return $e->errorcode;
        }
        $this->fail('The chunk was read');
    }

    public function test_the_constants_of_the_format(): void {
        $this->assertSame(1048576, chunk_writer::FRAME);
        $this->assertSame(1, chunk_writer::TYPE_DB);
        $this->assertSame(2, chunk_writer::TYPE_FILES);
        $this->assertSame(3, chunk_writer::TYPE_CODE);
        $this->assertSame(4, chunk_writer::TYPE_MANIFEST);
        $this->assertSame(
            'secbackup:v1|' . self::SITE . '|' . self::NAME . '|3',
            chunk_writer::additional_data(self::SITE, self::NAME, 3)
        );
    }

    public function test_a_chunk_of_one_item(): void {
        $path = make_request_directory() . '/chunk';
        $payload = random_bytes(1000);
        $writer = self::writer($path);
        // Without the padding: the length that the item has when another item follows it.
        $this->assertSame(['offset' => 86, 'length' => 24 + 25 + 1017], $writer->add_item($payload, 1000));
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($path . '.part');
        $info = $writer->finish();

        $this->assertFileDoesNotExist($path . '.part');
        $this->assertSame($path, $info->path);
        $this->assertSame(self::NAME, $info->name);
        $this->assertSame(1185, $info->size);
        $this->assertSame(1185, filesize($path));
        $this->assertSame(base64_encode(hash_file('sha256', $path, true)), $info->sha256base64);
        $this->assertSame([['offset' => 86, 'length' => 1099]], $info->items);

        $bytes = (string) file_get_contents($path);
        $this->assertSame('SECB', substr($bytes, 0, 4));
        $this->assertSame(1, ord($bytes[4]));
        $this->assertSame(2, ord($bytes[5]));
        $this->data_key($path);
        $plaintext = $this->assert_frames($path, 86, [[8, self::MESSAGE], [1000, self::MESSAGE], [16, self::FINAL]], 2);
        $this->assertSame(pack('J', 1000) . $payload . str_repeat("\0", 16), $plaintext);
        $this->assertSame(1024, strlen($plaintext));

        $reader = self::reader($path);
        $this->assertSame(chunk_writer::TYPE_FILES, $reader->type());
        $this->assertSame($payload, $reader->read_item(86));
        // The same item again, from the same reader.
        $this->assertSame($payload, $reader->read_item(86));
    }

    public function test_a_chunk_of_three_items_is_padded_as_a_whole(): void {
        $path = make_request_directory() . '/pack';
        $second = random_bytes(5000);
        $third = random_bytes(300);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $second . 'more data after the item');
        rewind($stream);

        $writer = self::writer($path, chunk_writer::TYPE_DB);
        $this->assertSame(['offset' => 86, 'length' => 67], $writer->add_item('a', 1));
        $this->assertSame(['offset' => 153, 'length' => 5066], $writer->add_item($stream, 5000));
        $this->assertSame(['offset' => 5219, 'length' => 366], $writer->add_item($third, 300));
        $info = $writer->finish();
        fclose($stream);

        // The plaintext adds up to 9 + 5008 + 308 = 5325 bytes, and Padmé makes 5325 into 5376: 51 bytes of padding for the
        // last item only, in a frame of their own.
        $this->assertSame([['offset' => 86, 'length' => 67], ['offset' => 153, 'length' => 5066],
            ['offset' => 5219, 'length' => 434]], $info->items);
        $this->assertSame(5653, $info->size);
        $this->assertSame(5653, filesize($path));
        $this->assertSame(base64_encode(hash_file('sha256', $path, true)), $info->sha256base64);
        $this->assertSame(1, ord(((string) file_get_contents($path))[5]));
        $total = strlen($this->assert_frames($path, 86, [[8, self::MESSAGE], [1, self::FINAL]], 1))
            + strlen($this->assert_frames($path, 153, [[8, self::MESSAGE], [5000, self::FINAL]], 1))
            + strlen($this->assert_frames($path, 5219, [[8, self::MESSAGE], [300, self::MESSAGE], [51, self::FINAL]], 1));
        $this->assertSame(padme::length(5325), $total);
        $this->assertSame(5376, $total);

        $reader = self::reader($path);
        $this->assertSame(chunk_writer::TYPE_DB, $reader->type());
        $this->assertSame($third, $reader->read_item(5219));
        $this->assertSame('a', $reader->read_item(86));
        $this->assertSame($second, $reader->read_item(153));
    }

    public function test_an_item_of_two_mebibytes_and_one_byte_spans_three_frames(): void {
        $dir = make_request_directory();
        $payload = random_bytes(2097153);
        file_put_contents($dir . '/source', $payload);
        $source = fopen($dir . '/source', 'rb');
        $digest = hash_init('sha1');

        $writer = self::writer($dir . '/chunk', chunk_writer::TYPE_MANIFEST);
        $writer->add_item($source, 2097153, $digest);
        $info = $writer->finish();
        fclose($source);

        $this->assertSame(sha1($payload), hash_final($digest));
        // Padmé makes 8 + 2097153 into 2162688: 65527 bytes of padding.
        $frames = [[8, self::MESSAGE], [1048576, self::MESSAGE], [1048576, self::MESSAGE], [1, self::MESSAGE],
            [65527, self::FINAL]];
        $this->assertSame(2162688, strlen($this->assert_frames($dir . '/chunk', 86, $frames, 4)));
        $this->assertSame([['offset' => 86, 'length' => 24 + 2162688 + 5 * 17]], $info->items);
        $reader = self::reader($dir . '/chunk');
        $this->assertSame(chunk_writer::TYPE_MANIFEST, $reader->type());
        $this->assertSame($payload, $reader->read_item(86));
    }

    public function test_a_payload_of_several_frames_given_as_text(): void {
        $path = make_request_directory() . '/chunk';
        $payload = random_bytes(2097153);
        $writer = self::writer($path);
        $writer->add_item($payload, 2097153);
        $writer->finish();
        $frames = [[8, self::MESSAGE], [1048576, self::MESSAGE], [1048576, self::MESSAGE], [1, self::MESSAGE],
            [65527, self::FINAL]];
        $this->assertSame(pack('J', 2097153) . $payload . str_repeat("\0", 65527), $this->assert_frames($path, 86, $frames, 2));
        $this->assertSame($payload, self::reader($path)->read_item(86));
    }

    public function test_an_item_of_exactly_one_frame(): void {
        $path = make_request_directory() . '/chunk';
        $payload = random_bytes(1048576);
        $writer = self::writer($path);
        $writer->add_item($payload, 1048576);
        $info = $writer->finish();
        // Padmé makes 1048584 into 1081344: 32760 bytes of padding.
        $this->assertSame(1081344, padme::length(1048584));
        $frames = [[8, self::MESSAGE], [1048576, self::MESSAGE], [32760, self::FINAL]];
        $this->assertSame(1081344, strlen($this->assert_frames($path, 86, $frames, 2)));
        $this->assertSame(86 + 24 + 1081344 + 3 * 17, $info->size);
        $this->assertSame($payload, self::reader($path)->read_item(86));
    }

    public function test_the_keys_are_wiped(): void {
        $dir = make_request_directory();
        signing_keys_test::$wiped = [];
        $writer = self::writer($dir . '/done');
        $writer->add_item('x', 1);
        $key = $this->data_key($dir . '/done.part');
        $writer->finish();
        // The data key, then the state of the secretstream (52 bytes).
        $this->assertCount(2, signing_keys_test::$wiped);
        $this->assertSame($key, signing_keys_test::$wiped[0]);
        $this->assertSame(52, strlen(signing_keys_test::$wiped[1]));

        signing_keys_test::$wiped = [];
        $writer = self::writer($dir . '/empty');
        $key = $this->data_key($dir . '/empty.part');
        try {
            $writer->finish();
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('at least one item', $e->getMessage());
        }
        // Given up before any item: there is no state yet.
        $this->assertSame([$key], signing_keys_test::$wiped);

        $donekey = $this->data_key($dir . '/done');
        signing_keys_test::$wiped = [];
        self::reader($dir . '/done');
        // The key pair that opened the seal (the secret key, then the public key), then the data key, when the reader,
        // which is not kept, is destroyed.
        $this->assertSame([self::secret_key() . self::public_key(), $donekey], signing_keys_test::$wiped);
    }

    public function test_the_digest_sees_the_payload_of_a_string_too(): void {
        $digest = hash_init('sha256');
        $writer = self::writer(make_request_directory() . '/chunk');
        $writer->add_item('payload', 7, $digest);
        $writer->finish();
        $this->assertSame(hash('sha256', 'payload'), hash_final($digest));
    }

    public function test_an_empty_item(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path, chunk_writer::TYPE_CODE);
        $this->assertSame(['offset' => 86, 'length' => 49], $writer->add_item('', 0));
        $info = $writer->finish();
        // Padmé keeps 8: no padding, so the frame of the length prefix ends the item.
        $this->assertSame([['offset' => 86, 'length' => 49]], $info->items);
        $this->assertSame(135, $info->size);
        $this->assertSame(pack('J', 0), $this->assert_frames($path, 86, [[8, self::FINAL]], 3));
        $this->assertSame('', self::reader($path)->read_item(86));
    }

    public function test_empty_items_among_others(): void {
        // 8 + 10 + 8 = 26 bytes of plaintext, which padme keeps: the last item ends without padding.
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('', 0);
        $writer->add_item('xy', 2);
        $writer->add_item('', 0);
        $info = $writer->finish();
        $this->assertSame([['offset' => 86, 'length' => 49], ['offset' => 135, 'length' => 68],
            ['offset' => 203, 'length' => 49]], $info->items);
        $this->assertSame(pack('J', 0), $this->assert_frames($path, 203, [[8, self::FINAL]], 2));
        $reader = self::reader($path);
        $this->assertSame('', $reader->read_item(86));
        $this->assertSame('xy', $reader->read_item(135));
        $this->assertSame('', $reader->read_item(203));

        // 11 + 8 = 19 bytes, which padme makes 20: an empty last item carries one byte of padding.
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('abc', 3);
        $writer->add_item('', 0);
        $info = $writer->finish();
        $this->assertSame([['offset' => 86, 'length' => 69], ['offset' => 155, 'length' => 67]], $info->items);
        $this->assertSame(pack('J', 0) . "\0", $this->assert_frames($path, 155, [[8, self::MESSAGE], [1, self::FINAL]], 2));
        $this->assertSame('', self::reader($path)->read_item(155));
        $this->assertSame('abc', self::reader($path)->read_item(86));
    }

    public function test_an_item_without_padding_ends_with_its_payload(): void {
        // 8 + 1016 = 1024, which padme keeps.
        $path = make_request_directory() . '/chunk';
        $payload = random_bytes(1016);
        $writer = self::writer($path);
        $writer->add_item($payload, 1016);
        $info = $writer->finish();
        $this->assertSame(86 + 24 + 25 + 1033, $info->size);
        $frames = [[8, self::MESSAGE], [1016, self::FINAL]];
        $this->assertSame(pack('J', 1016) . $payload, $this->assert_frames($path, 86, $frames, 2));
        $this->assertSame($payload, self::reader($path)->read_item(86));
    }

    public function test_padding_longer_than_a_frame(): void {
        // 64 items of 1 MiB of plaintext each (with their length prefixes), then one of a byte: 2^26 + 9 bytes, which
        // padme makes 2^26 + 2^21. The last item carries 2^21 - 9 bytes of padding, in two frames.
        $path = make_request_directory() . '/chunk';
        $block = str_repeat('b', 1048568);
        $writer = self::writer($path);
        for ($item = 0; $item < 64; $item++) {
            $writer->add_item($block, 1048568);
        }
        $last = $writer->add_item('z', 1);
        $info = $writer->finish();
        $this->assertSame(86 + 64 * 1048634, $last['offset']);
        $this->assertSame(['offset' => $last['offset'], 'length' => 24 + 25 + 18 + 1048593 + 1048584], $info->items[64]);
        $this->assertSame(86 + 64 * 1048634 + 24 + 25 + 18 + 1048593 + 1048584, $info->size);
        $frames = [[8, self::MESSAGE], [1, self::MESSAGE], [1048576, self::MESSAGE], [1048567, self::FINAL]];
        $this->assertSame(2097152, strlen($this->assert_frames($path, $last['offset'], $frames, 2)));
        $reader = self::reader($path);
        $this->assertSame('z', $reader->read_item($last['offset']));
        $this->assertSame($block, $reader->read_item(86 + 10 * 1048634));
    }

    /**
     * Bytes of the chunk of one_item_chunk() to change, by what they belong to.
     *
     * @return array
     */
    public static function changed_byte_provider(): array {
        return [
            'magic' => [0],
            'version' => [4],
            'type' => [5],
            'first byte of the seal' => [6],
            'last byte of the seal' => [85],
            'item header' => [86],
            'end of the item header' => [109],
            'length prefix' => [110],
            'payload' => [600],
            'last frame' => [1184],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changed_byte_provider')]
    /**
     * Changing one byte anywhere makes the chunk unreadable.
     *
     * @dataProvider changed_byte_provider
     * @param int $position Byte to change.
     */
    public function test_a_changed_byte_is_found(int $position): void {
        [$path] = self::one_item_chunk();
        $bytes = (string) file_get_contents($path);
        $bytes[$position] = chr(ord($bytes[$position]) ^ 0x01);
        file_put_contents($path, $bytes);
        $this->assertSame('crypto_chunk_corrupt', $this->failure_of($path));
    }

    /**
     * Header bytes that the reader refuses when it opens the chunk.
     *
     * @return array
     */
    public static function refused_header_provider(): array {
        return [
            'magic' => [0, 'R'],
            'version 0' => [4, chr(0)],
            'version 2' => [4, chr(2)],
            'type 0' => [5, chr(0)],
            'type 5' => [5, chr(5)],
            'type 255' => [5, chr(255)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_header_provider')]
    /**
     * A header of another format, version or type is refused before any item is read.
     *
     * @dataProvider refused_header_provider
     * @param int $position Byte to change.
     * @param string $value New value.
     */
    public function test_a_header_of_another_format_is_refused_when_opened(int $position, string $value): void {
        [$path] = self::one_item_chunk();
        $bytes = (string) file_get_contents($path);
        $bytes[$position] = $value;
        file_put_contents($path, $bytes);
        try {
            self::reader($path);
            $this->fail('The chunk was opened');
        } catch (plugin_exception $e) {
            $this->assertSame('crypto_chunk_corrupt', $e->errorcode);
        }
    }

    public function test_a_changed_type_that_exists_is_found_by_the_authentication(): void {
        [$path] = self::one_item_chunk();
        $bytes = (string) file_get_contents($path);
        $bytes[5] = chr(1);
        file_put_contents($path, $bytes);
        $reader = self::reader($path);
        $this->assertSame(chunk_writer::TYPE_DB, $reader->type());
        $this->assertSame('crypto_chunk_corrupt', $this->failure_of($path));
    }

    /**
     * Ways to cut a chunk short, and what the reader reports.
     *
     * @return array
     */
    public static function cut_provider(): array {
        return [
            'without the last frame of the padding' => [1152, 'crypto_chunk_truncated'],
            'inside the last frame of the padding' => [1180, 'crypto_chunk_corrupt'],
            'inside the payload' => [1000, 'crypto_chunk_truncated'],
            'without the payload' => [135, 'crypto_chunk_truncated'],
            'without the length prefix' => [110, 'crypto_chunk_truncated'],
            'inside the item header' => [100, 'crypto_chunk_truncated'],
            'without the item' => [86, 'crypto_chunk_truncated'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cut_provider')]
    /**
     * A chunk that ends early is reported.
     *
     * @dataProvider cut_provider
     * @param int $length Bytes to keep.
     * @param string $expected Error code.
     */
    public function test_a_chunk_cut_short_is_found(int $length, string $expected): void {
        [$path] = self::one_item_chunk();
        file_put_contents($path, substr((string) file_get_contents($path), 0, $length));
        $this->assertSame($expected, $this->failure_of($path));
    }

    public function test_an_unpadded_item_without_its_last_frame_is_truncated(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item(random_bytes(1016), 1016);
        $writer->finish();
        file_put_contents($path, substr((string) file_get_contents($path), 0, 86 + 24 + 25));
        $this->assertSame('crypto_chunk_truncated', $this->failure_of($path));
    }

    public function test_a_header_cut_short_is_truncated(): void {
        [$path] = self::one_item_chunk();
        foreach ([85, 4, 0] as $length) {
            file_put_contents($path, substr((string) file_get_contents($path), 0, $length));
            try {
                self::reader($path);
                $this->fail('The chunk was opened');
            } catch (plugin_exception $e) {
                $this->assertSame('crypto_chunk_truncated', $e->errorcode);
            }
        }
    }

    public function test_the_items_before_a_cut_can_still_be_read(): void {
        $path = make_request_directory() . '/pack';
        $writer = self::writer($path);
        $writer->add_item('first', 5);
        $second = $writer->add_item('second', 6);
        $writer->add_item('third', 5);
        $writer->finish();
        file_put_contents($path, substr((string) file_get_contents($path), 0, $second['offset'] + $second['length']));
        $reader = self::reader($path);
        $this->assertSame('first', $reader->read_item(86));
        $this->assertSame('second', $reader->read_item($second['offset']));
        $this->assertSame('crypto_chunk_truncated', $this->failure_of($path, $second['offset'] + $second['length']));
    }

    public function test_another_site_name_or_key_cannot_read_the_chunk(): void {
        [$path] = self::one_item_chunk();
        $other = sodium_crypto_box_keypair();
        $cases = [
            'another site' => fn() => new chunk_reader(
                $path,
                self::secret_key(),
                self::public_key(),
                '0b1c2d3e-4f50-4617-8899-aabbccddeef0',
                self::NAME
            ),
            'another name' => fn() => new chunk_reader(
                $path,
                self::secret_key(),
                self::public_key(),
                self::SITE,
                str_repeat('5a', 31) . '5b'
            ),
            'another key pair' => fn() => new chunk_reader(
                $path,
                sodium_crypto_box_secretkey($other),
                sodium_crypto_box_publickey($other),
                self::SITE,
                self::NAME
            ),
            'another public key' => fn() => new chunk_reader(
                $path,
                self::secret_key(),
                sodium_crypto_box_publickey($other),
                self::SITE,
                self::NAME
            ),
        ];
        foreach ($cases as $case => $open) {
            try {
                $open()->read_item(86);
                $this->fail("$case: the chunk was read");
            } catch (plugin_exception $e) {
                $this->assertSame('crypto_chunk_corrupt', $e->errorcode, $case);
            }
        }
    }

    /**
     * Chunks built by hand that the writer never makes, and what the reader says.
     *
     * @return array
     */
    public static function forged_provider(): array {
        $m = self::MESSAGE;
        $f = self::FINAL;
        $p = self::PUSH;
        $frame = str_repeat("\0", 1048576);
        return [
            'negative length' => [[["\xff\xff\xff\xff\xff\xff\xff\xff", $m], ['', $f]], '', 'crypto_chunk_corrupt'],
            // The bytes after it could hold the payload, so it is the tag that is wrong.
            'item ended by the length prefix' => [[[pack('J', 5), $f]], str_repeat('x', 22), 'crypto_chunk_corrupt'],
            'length prefix pushed' => [[[pack('J', 1), $p], ['a', $f]], '', 'crypto_chunk_corrupt'],
            'item ended inside the payload' => [[[pack('J', 1048577), $m], [str_repeat('a', 1048576), $f], ['a', $f]], '',
                'crypto_chunk_corrupt'],
            'payload pushed' => [[[pack('J', 3), $m], ['abc', $p]], '', 'crypto_chunk_corrupt'],
            'padding that is not zero' => [[[pack('J', 1), $m], ['a', $m], ["\0\0\x01", $f]], '', 'crypto_chunk_corrupt'],
            'padding pushed' => [[[pack('J', 1), $m], ['a', $m], ["\0", $p]], '', 'crypto_chunk_corrupt'],
            'bytes after a full last frame' => [[[pack('J', 1), $m], ['a', $m], [$frame, $f]], 'x', 'crypto_chunk_corrupt'],
            'a frame too short to be one' => [[[pack('J', 1), $m], ['a', $m]], str_repeat('x', 16), 'crypto_chunk_corrupt'],
            'length beyond the end' => [[[pack('J', 1 << 40), $m]], '', 'crypto_chunk_truncated'],
            // Refused right after the length prefix: decrypting first would find the bytes that follow not authentic.
            'length beyond the rest of the file' => [[[pack('J', 1 << 40), $m]], str_repeat('x', 2097152),
                'crypto_chunk_truncated'],
            'padding without its last frame' => [[[pack('J', 1), $m], ['a', $m], ["\0", $m]], '', 'crypto_chunk_truncated'],
            'two frames of padding' => [[[pack('J', 1), $m], ['a', $m], [$frame, $m], ["\0\0\0", $f]], '', 'a'],
            'a full last frame of padding' => [[[pack('J', 1), $m], ['a', $m], [$frame, $f]], '', 'a'],
            'padding in the frame of the length prefix' => [[[pack('J', 0), $m], ["\0", $f]], '', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forged_provider')]
    /**
     * The reader checks the structure of an item, not only the authentication of its frames.
     *
     * @dataProvider forged_provider
     * @param array $frames Frames of the item.
     * @param string $trailer Bytes after the item.
     * @param string $expected An error code, or the payload that is read.
     */
    public function test_a_forged_item(array $frames, string $trailer, string $expected): void {
        $path = self::forge($frames, $trailer);
        if (str_starts_with($expected, 'crypto_')) {
            $this->assertSame($expected, $this->failure_of($path));
        } else {
            $this->assertSame($expected, self::reader($path)->read_item(86));
        }
    }

    public function test_an_offset_inside_the_header_is_a_mistake(): void {
        [$path, $payload] = self::one_item_chunk();
        $reader = self::reader($path);
        foreach ([85, 0, -1] as $offset) {
            try {
                $reader->read_item($offset);
                $this->fail("offset $offset");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('offset', $e->getMessage());
            }
        }
        $this->assertSame($payload, $reader->read_item(86));
    }

    /**
     * Arguments of the constructor of the writer that are mistakes of the caller.
     *
     * @return array
     */
    public static function invalid_writer_provider(): array {
        $public = self::public_key();
        return [
            'type 0' => [0, self::NAME, $public, self::SITE],
            'type 5' => [5, self::NAME, $public, self::SITE],
            'upper-case name' => [2, strtoupper(self::NAME), $public, self::SITE],
            'short name' => [2, substr(self::NAME, 1), $public, self::SITE],
            'name before a line feed' => [2, self::NAME . "\n", $public, self::SITE],
            'name after another character' => [2, 'x' . self::NAME, $public, self::SITE],
            'name with a separator' => [2, substr(self::NAME, 1) . '|', $public, self::SITE],
            'short public key' => [2, self::NAME, substr($public, 1), self::SITE],
            'long public key' => [2, self::NAME, $public . 'x', self::SITE],
            'upper-case site' => [2, self::NAME, $public, strtoupper(self::SITE)],
            'site with a separator' => [2, self::NAME, $public, substr(self::SITE, 1) . '|'],
            'site before a line feed' => [2, self::NAME, $public, self::SITE . "\n"],
            'site after another character' => [2, self::NAME, $public, 'x' . self::SITE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_writer_provider')]
    /**
     * A writer is not made for a wrong type, name, key or site, and no file is created.
     *
     * @dataProvider invalid_writer_provider
     * @param int $type Type.
     * @param string $name Name.
     * @param string $public Public key.
     * @param string $site Site ID.
     */
    public function test_a_writer_refuses_wrong_arguments(int $type, string $name, string $public, string $site): void {
        $path = make_request_directory() . '/chunk';
        try {
            new chunk_writer($path, $type, $name, $public, $site);
            $this->fail('A writer was made');
        } catch (\coding_exception $e) {
            $this->assertFileDoesNotExist($path . '.part');
        }
    }

    /**
     * Items that are mistakes of the caller.
     *
     * @return array
     */
    public static function invalid_item_provider(): array {
        return [
            'negative length' => [fn() => fopen('php://memory', 'w+b'), -1],
            'string shorter than the length' => [fn() => 'abc', 4],
            'string longer than the length' => [fn() => 'abc', 2],
            'number' => [fn() => 3, 1],
            'closed stream' => [function () {
                $stream = fopen('php://memory', 'w+b');
                fclose($stream);
                return $stream;
            }, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_item_provider')]
    /**
     * A wrong item is refused, and the writer gives up the chunk.
     *
     * @dataProvider invalid_item_provider
     * @param \Closure $source Makes the source.
     * @param int $length Length.
     */
    public function test_a_wrong_item_is_refused_and_the_chunk_given_up(\Closure $source, int $length): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('first', 5);
        try {
            $writer->add_item($source(), $length);
            $this->fail('The item was added');
        } catch (\coding_exception $e) {
            $this->assertFileDoesNotExist($path . '.part');
            $this->assertFileDoesNotExist($path);
        }
        $this->expectException(\coding_exception::class);
        $writer->finish();
    }

    public function test_a_source_that_ends_early_gives_up_the_chunk(): void {
        $dir = make_request_directory();
        $cases = [
            'in the first frame' => [10, 20],
            'in the second frame' => [1048576 + 10, 1048576 + 20],
        ];
        foreach ($cases as $case => [$available, $declared]) {
            $source = fopen('php://temp', 'w+b');
            fwrite($source, str_repeat('s', $available));
            rewind($source);
            $writer = self::writer($dir . '/chunk');
            try {
                $writer->add_item($source, $declared);
                $this->fail("$case: the item was added");
            } catch (plugin_exception $e) {
                $this->assertSame('crypto_chunk_sourceshort', $e->errorcode, $case);
                $this->assertNull($e->debuginfo, $case);
                $this->assertFileDoesNotExist($dir . '/chunk.part', $case);
            }
            fclose($source);
            try {
                $writer->add_item('x', 1);
                $this->fail("$case: the writer was used again");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('finished or given up', $e->getMessage());
            }
        }
    }

    public function test_a_chunk_without_items_is_not_written(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        try {
            $writer->finish();
            $this->fail('The chunk was finished');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('at least one item', $e->getMessage());
        }
        $this->assertFileDoesNotExist($path . '.part');
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_finished_writer_cannot_be_used_again(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('x', 1);
        $writer->finish();
        foreach ([fn() => $writer->add_item('y', 1), fn() => $writer->finish()] as $call) {
            try {
                $call();
                $this->fail('The writer was used again');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('finished or given up', $e->getMessage());
            }
        }
        $this->assertSame('x', self::reader($path)->read_item(86));
    }

    /**
     * Runs a call that has to fail with a plugin_exception, and checks that no warning of PHP reached any handler.
     *
     * The handler of the test is in place during the call: the writer must put its own above it and take it away again,
     * which the notice raised afterwards shows. A warning that went to the standard handler of PHP would be the last error.
     *
     * @param \Closure $call The call.
     * @param string $code The expected error code.
     * @return plugin_exception
     */
    private function quiet_failure(\Closure $call, string $code = 'temp_write_failed'): plugin_exception {
        $seen = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
            $seen[] = $errstr;
            return true;
        });
        error_clear_last();
        try {
            $call();
            $failure = null;
        } catch (plugin_exception $e) {
            $failure = $e;
        } finally {
            $last = error_get_last();
            trigger_error('probe', E_USER_NOTICE);
            restore_error_handler();
        }
        $this->assertNull($last);
        $this->assertSame(['probe'], $seen);
        $this->assertNotNull($failure, 'The call did not fail');
        $this->assertSame($code, $failure->errorcode);
        return $failure;
    }

    public function test_a_folder_that_does_not_exist(): void {
        $path = make_request_directory() . '/missing/chunk';
        $e = $this->quiet_failure(fn() => self::writer($path));
        $this->assertSame('open: fopen(' . $path . '.part): Failed to open stream: No such file or directory', $e->debuginfo);
    }

    public function test_a_disk_that_is_full(): void {
        $path = make_request_directory() . '/chunk';
        symlink('/dev/full', $path . '.part');
        $e = $this->quiet_failure(fn() => self::writer($path));
        $this->assertSame('write: fwrite(): Write of 86 bytes failed with errno=28 No space left on device', $e->debuginfo);
        $this->assertFalse(is_link($path . '.part'));
    }

    public function test_a_name_that_cannot_be_given(): void {
        $path = make_request_directory() . '/chunk';
        // A folder with a file in it stands where the chunk would go: a file cannot take its name.
        mkdir($path);
        touch($path . '/inside');
        $writer = self::writer($path);
        $writer->add_item('x', 1);
        $e = $this->quiet_failure(fn() => $writer->finish());
        $this->assertSame('rename: rename(' . $path . '.part,' . $path . '): Is a directory', $e->debuginfo);
        $this->assertFileDoesNotExist($path . '.part');
        $this->assertFileExists($path . '/inside');
        $this->expectException(\coding_exception::class);
        $writer->finish();
    }

    public function test_the_file_is_closed_when_the_writer_ends(): void {
        $dir = make_request_directory();
        $source = fopen('php://temp', 'w+b');
        fwrite($source, 'abc');
        rewind($source);
        $open = count(get_resources('stream'));

        $writer = self::writer($dir . '/done');
        $this->assertCount($open + 1, get_resources('stream'));
        $writer->add_item($source, 3);
        $writer->finish();
        $this->assertCount($open, get_resources('stream'));

        $writer = self::writer($dir . '/failed');
        try {
            $writer->add_item($source, 3);
        } catch (plugin_exception $e) {
            $this->assertSame('crypto_chunk_sourceshort', $e->errorcode);
        }
        $this->assertCount($open, get_resources('stream'));
        fclose($source);
    }

    public function test_a_source_that_cannot_be_read(): void {
        $dir = make_request_directory();
        // A folder opens as a stream on Linux, and every read of it fails with a warning (EISDIR).
        $source = fopen($dir, 'rb');
        $writer = self::writer($dir . '/chunk');
        $e = $this->quiet_failure(fn() => $writer->add_item($source, 5), 'crypto_chunk_sourceshort');
        fclose($source);
        $this->assertStringContainsString('Is a directory', $e->debuginfo);
        $this->assertFileDoesNotExist($dir . '/chunk.part');
    }

    public function test_a_public_key_of_low_order_is_refused(): void {
        $dir = make_request_directory();
        signing_keys_test::$wiped = [];
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new chunk_writer($dir . '/chunk', 2, self::NAME, str_repeat("\0", 32), self::SITE);
            $this->fail('A writer was made');
        } catch (key_exception $e) {
            $this->assertSame('publickey_invalid', $e->errorcode);
            // The exception of libsodium tells no more than this one does, so it is not chained.
            $this->assertNull($e->getPrevious());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertFileDoesNotExist($dir . '/chunk.part');
        $this->assertCount(1, signing_keys_test::$wiped);
        $this->assert_trace_hides($e, [signing_keys_test::$wiped[0]]);
    }

    public function test_an_abandoned_writer_removes_its_part_file_and_wipes_its_keys(): void {
        $dir = make_request_directory();
        $writer = self::writer($dir . '/chunk');
        $writer->add_item('x', 1);
        $key = $this->data_key($dir . '/chunk.part');
        $open = count(get_resources('stream'));
        signing_keys_test::$wiped = [];
        unset($writer);
        $this->assertFileDoesNotExist($dir . '/chunk.part');
        $this->assertFileDoesNotExist($dir . '/chunk');
        $this->assertCount($open - 1, get_resources('stream'));
        // The data key, then the state of the secretstream.
        $this->assertCount(2, signing_keys_test::$wiped);
        $this->assertSame($key, signing_keys_test::$wiped[0]);
        $this->assertSame(52, strlen(signing_keys_test::$wiped[1]));
    }

    public function test_abort_gives_up_the_chunk(): void {
        $dir = make_request_directory();
        $writer = self::writer($dir . '/chunk');
        $writer->add_item('x', 1);
        $key = $this->data_key($dir . '/chunk.part');
        $open = count(get_resources('stream'));
        signing_keys_test::$wiped = [];
        $writer->abort();
        $this->assertFileDoesNotExist($dir . '/chunk.part');
        $this->assertCount($open - 1, get_resources('stream'));
        $this->assertCount(2, signing_keys_test::$wiped);
        $this->assertSame($key, signing_keys_test::$wiped[0]);
        // A second abort() does nothing.
        $writer->abort();
        $this->assertCount(2, signing_keys_test::$wiped);
        try {
            $writer->add_item('y', 1);
            $this->fail('The writer was used again');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('finished or given up', $e->getMessage());
        }
    }

    public function test_abort_after_finish_does_nothing(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('x', 1);
        $info = $writer->finish();
        signing_keys_test::$wiped = [];
        $writer->abort();
        unset($writer);
        $this->assertSame([], signing_keys_test::$wiped);
        $this->assertSame($info->sha256base64, base64_encode(hash_file('sha256', $path, true)));
        $this->assertSame('x', self::reader($path)->read_item(86));
    }

    public function test_a_crafted_writer_does_not_remove_a_file(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/victim', 'kept');
        $class = chunk_writer::class;
        $properties = ['open' => 'b:1;', 'part' => 's:' . strlen($dir . '/victim') . ':"' . $dir . '/victim";'];
        $body = '';
        foreach ($properties as $name => $value) {
            $key = "\0" . $class . "\0" . $name;
            $body .= 's:' . strlen($key) . ':"' . $key . '";' . $value;
        }
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":2:{' . $body . '}', ['allowed_classes' => [$class]]);
            $this->fail('A writer was unserialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be unserialised', $e->getMessage());
        }
        gc_collect_cycles();
        $this->assertSame('kept', file_get_contents($dir . '/victim'));
    }

    public function test_the_reader_wipes_the_state_of_each_item_and_its_data_key(): void {
        [$path] = self::one_item_chunk();
        $key = $this->data_key($path);
        $reader = self::reader($path);
        signing_keys_test::$wiped = [];
        $reader->read_item(86);
        $this->assertCount(1, signing_keys_test::$wiped);
        $this->assertSame(52, strlen(signing_keys_test::$wiped[0]));

        // When the item is not authentic, too.
        $bytes = (string) file_get_contents($path);
        $bytes[600] = chr(ord($bytes[600]) ^ 0x01);
        file_put_contents($path . '.changed', $bytes);
        signing_keys_test::$wiped = [];
        $this->assertSame('crypto_chunk_corrupt', $this->failure_of($path . '.changed'));
        // The reader that failure_of() made: the key pair, the state of the item, then its data key.
        $this->assertCount(3, signing_keys_test::$wiped);
        $this->assertSame(52, strlen(signing_keys_test::$wiped[1]));

        $open = count(get_resources('stream'));
        signing_keys_test::$wiped = [];
        $reader->__destruct();
        $this->assertCount($open - 1, get_resources('stream'));
        $this->assertSame([$key], signing_keys_test::$wiped);
        // Destroying it again does nothing.
        $reader->__destruct();
        $this->assertSame([$key], signing_keys_test::$wiped);
    }

    /**
     * Checks that no argument in the trace of an exception holds one of the secrets.
     *
     * @param \Throwable $exception The exception, made while zend.exception_ignore_args was off.
     * @param string[] $secrets The secrets.
     */
    private function assert_trace_hides(\Throwable $exception, array $secrets): void {
        $this->assertNotEmpty(
            array_filter($exception->getTrace(), fn($frame) => !empty($frame['args'])),
            'The trace keeps the arguments, so the check means something.'
        );
        foreach (trace_strings::of($exception) as $argument) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $argument);
            }
        }
    }

    /**
     * Damage done to the chunk of one_item_chunk() that the reader reports from inside an item, and from its header.
     *
     * @return array
     */
    public static function failing_read_provider(): array {
        $abytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        // The end of the payload frame: the header of the chunk, the header of the stream, the frame of the length prefix
        // and the frame of the payload (1151 for a payload of 1000 bytes).
        $payloadend = chunk_writer::HEADER + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            + chunk_writer::PREFIX + $abytes + self::ONE_ITEM_PAYLOAD + $abytes;
        return [
            'changed payload' => [fn(string $bytes) => substr_replace($bytes, chr(ord($bytes[600]) ^ 1), 600, 1),
                'crypto_chunk_corrupt', true, true],
            'last frame cut off' => [fn(string $bytes) => substr($bytes, 0, $payloadend), 'crypto_chunk_truncated', true, false],
            // Shorter than the declared length: refused right after the length prefix.
            'payload cut short' => [fn(string $bytes) => substr($bytes, 0, 1000), 'crypto_chunk_truncated', true, false],
            // One byte short of the payload frame: the length passes its check, and the frame ends early inside pull().
            'payload frame cut short' => [fn(string $bytes) => substr($bytes, 0, $payloadend - 1), 'crypto_chunk_truncated',
                true, true],
            'changed seal' => [fn(string $bytes) => substr_replace($bytes, chr(ord($bytes[40]) ^ 1), 40, 1),
                'crypto_chunk_corrupt', false, false],
            'header cut off' => [fn(string $bytes) => substr($bytes, 0, 50), 'crypto_chunk_truncated', false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_read_provider')]
    /**
     * The exceptions that a damaged chunk causes show neither a key nor the state of the secretstream in their traces,
     * which an attacker who can change a chunk at the storage can provoke.
     *
     * @dataProvider failing_read_provider
     * @param \Closure $damage Changes the bytes of the chunk.
     * @param string $code The expected error code.
     * @param bool $initem Whether the reader opens the chunk and fails on the item.
     * @param bool $inpull Whether the exception is made inside pull(), whose frame used to hold the state.
     */
    public function test_the_traces_of_a_damaged_chunk_hide_the_keys(
        \Closure $damage,
        string $code,
        bool $initem,
        bool $inpull
    ): void {
        [$path] = self::one_item_chunk();
        $key = $this->data_key($path);
        $header = (string) file_get_contents($path, false, null, 86, 24);
        // The first 32 bytes of the state are the key that the secretstream derives for the item.
        $subkey = substr(sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key), 0, 32);
        file_put_contents($path, $damage((string) file_get_contents($path)));
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            if ($initem) {
                $reader = self::reader($path);
            }
            try {
                $initem ? $reader->read_item(86) : self::reader($path);
                $this->fail('The chunk was read');
            } catch (plugin_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertSame($inpull, in_array('pull', array_column($e->getTrace(), 'function'), true));
        $this->assert_trace_hides($e, [$key, $subkey, self::secret_key(), self::secret_key() . self::public_key()]);
    }

    public function test_the_helper_finds_a_secret_in_a_sensitive_parameter(): void {
        // A control for trace_strings(): an argument that only #[\SensitiveParameter] hides is found.
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $e = (static fn(#[\SensitiveParameter] string $secret): \Exception => new \Exception('control'))('hidden secret');
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertContains('hidden secret', trace_strings::of($e));
    }

    public function test_a_writer_cannot_be_cloned(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('x', 1);
        try {
            $copy = clone $writer;
            $this->fail('The writer was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be cloned', $e->getMessage());
        }
        // PHP destroys the copy that __clone() refused; that must not give up the chunk of the original.
        gc_collect_cycles();
        $this->assertFileExists($path . '.part');
        $writer->add_item('y', 1);
        $info = $writer->finish();
        $reader = self::reader($path);
        $this->assertSame('x', $reader->read_item(86));
        $this->assertSame('y', $reader->read_item($info->items[1]['offset']));
    }

    public function test_a_reader_cannot_be_cloned(): void {
        [$path, $payload] = self::one_item_chunk();
        $reader = self::reader($path);
        signing_keys_test::$wiped = [];
        try {
            $copy = clone $reader;
            $this->fail('The reader was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be cloned', $e->getMessage());
        }
        // The refused copy does not close the file of the original or wipe its key.
        gc_collect_cycles();
        $this->assertSame([], signing_keys_test::$wiped);
        $this->assertSame($payload, $reader->read_item(86));
    }

    public function test_debug_output_hides_the_keys(): void {
        $path = make_request_directory() . '/chunk';
        $writer = self::writer($path);
        $writer->add_item('item', 4);
        $key = $this->data_key($path . '.part');
        $this->assertSame(['path' => $path, 'name' => self::NAME, 'type' => 2, 'size' => 135], $writer->__debugInfo());
        ob_start();
        var_dump($writer);
        $writerdump = (string) ob_get_clean();
        $writer->finish();

        $reader = self::reader($path);
        $this->assertSame(['path' => $path, 'type' => 2, 'size' => 135 + 21], $reader->__debugInfo());
        ob_start();
        var_dump($reader);
        $readerdump = (string) ob_get_clean();

        foreach ([$writerdump, $readerdump] as $dump) {
            $this->assertStringContainsString($path, $dump);
            foreach ([$key, base64_encode($key), bin2hex($key), self::secret_key(), base64_encode(self::secret_key())] as $secret) {
                $this->assertStringNotContainsString($secret, $dump);
            }
        }
    }

    public function test_writers_and_readers_cannot_be_serialised(): void {
        [$path] = self::one_item_chunk();
        $objects = ['writer' => self::writer(make_request_directory() . '/chunk'), 'reader' => self::reader($path)];
        foreach ($objects as $kind => $object) {
            foreach ([fn() => serialize($object), fn() => $object->__sleep()] as $call) {
                try {
                    $call();
                    $this->fail("A $kind was serialised");
                } catch (\coding_exception $e) {
                    $this->assertStringContainsString('must not be serialised', $e->getMessage());
                }
            }
            // The serialised form of an object whose constructor never ran.
            $class = get_class($object);
            try {
                unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}', ['allowed_classes' => [$class]]);
                $this->fail("A $kind was unserialised");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must not be unserialised', $e->getMessage());
            }
        }
    }

    public function test_a_reader_refuses_wrong_arguments(): void {
        [$path] = self::one_item_chunk();
        $cases = [
            'short secret key' => fn() => new chunk_reader(
                $path,
                substr(self::secret_key(), 1),
                self::public_key(),
                self::SITE,
                self::NAME
            ),
            'long public key' => fn() => new chunk_reader(
                $path,
                self::secret_key(),
                self::public_key() . 'x',
                self::SITE,
                self::NAME
            ),
            'missing file' => fn() => new chunk_reader(
                $path . '.missing',
                self::secret_key(),
                self::public_key(),
                self::SITE,
                self::NAME
            ),
        ];
        foreach ($cases as $case => $open) {
            try {
                $open();
                $this->fail("$case: a reader was made");
            } catch (\coding_exception $e) {
                $this->assertInstanceOf(\coding_exception::class, $e, $case);
            }
        }
    }

    public function test_the_secret_key_parameter_of_the_reader_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(chunk_reader::class, '__construct'))->getParameters()[1];
        $this->assertSame('sitesecretkey', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }
}
