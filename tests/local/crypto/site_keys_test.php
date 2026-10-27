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

// The stand-in for sodium_memzero() that keeps a copy of every wiped buffer.
require_once(__DIR__ . '/signing_keys_test.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\site_keys::class)]
/**
 * Tests for the keys of a site that a restore holds: which of them opens a chunk.
 *
 * A site whose key was made again has chunks of both keys in one run: the chunks of the database are sealed with the new key,
 * and chunks of files that the run shares with older runs with the old one.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\site_keys
 */
final class site_keys_test extends \basic_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Another site. */
    private const OTHER_SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeef0';

    /** @var string Name of the chunk of the tests. */
    private const NAME = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';

    /** @var string Another name. */
    private const OTHER_NAME = '7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c';

    /** @var int Seed of the newer key of the site. */
    private const NEW = 1;

    /** @var int Seed of the older key of the site. */
    private const OLD = 2;

    /**
     * An encryption key pair of the site, the same in every call for one seed.
     *
     * @param int $seed Byte of the seed.
     * @return string
     */
    private static function pair(int $seed): string {
        return sodium_crypto_box_seed_keypair(str_repeat(chr($seed), 32));
    }

    /**
     * A secret key of the site.
     *
     * @param int $seed Byte of the seed.
     * @return string
     */
    private static function secret(int $seed): string {
        return sodium_crypto_box_secretkey(self::pair($seed));
    }

    /**
     * A public key of the site.
     *
     * @param int $seed Byte of the seed.
     * @return string
     */
    private static function public(int $seed): string {
        return sodium_crypto_box_publickey(self::pair($seed));
    }

    /**
     * Writes a chunk of two items.
     *
     * @param int $seed Seed of the key of the site that the chunk is sealed with.
     * @param string $site Site of the chunk.
     * @param string $name Name of the chunk.
     * @return array [path, offset of the second item]
     */
    private static function chunk(int $seed, string $site = self::SITE, string $name = self::NAME): array {
        $path = make_request_directory() . '/' . self::NAME;
        $writer = new chunk_writer($path, chunk_writer::TYPE_DB, $name, self::public($seed), $site);
        $writer->add_item('first', 5);
        $writer->add_item('second', 6);
        return [$path, $writer->finish()->items[1]['offset']];
    }

    /**
     * Site keys of the given seeds, in that order.
     *
     * @param int ...$seeds Seeds.
     * @return site_keys
     */
    private static function keys(int ...$seeds): site_keys {
        return new site_keys(array_map([self::class, 'secret'], $seeds));
    }

    /**
     * Opens a chunk and returns the error code of the plugin_exception that it causes.
     *
     * @param site_keys $keys The keys.
     * @param string $path Path of the chunk.
     * @return string
     */
    private function failure_of(site_keys $keys, string $path): string {
        try {
            $keys->reader($path, self::SITE, self::NAME);
        } catch (plugin_exception $e) {
            $this->assertNull($e->getPrevious());
            return $e->errorcode;
        }
        $this->fail('The chunk was opened');
    }

    public function test_the_count_of_keys(): void {
        $this->assertSame(1, self::keys(self::NEW)->count());
        $this->assertSame(2, self::keys(self::NEW, self::OLD)->count());
        $this->assertSame(10, self::keys(...range(1, 10))->count());
    }

    public function test_a_chunk_of_the_old_key_opens_with_the_new_and_the_old_key(): void {
        [$path, $second] = self::chunk(self::OLD);
        $reader = self::keys(self::NEW, self::OLD)->reader($path, self::SITE, self::NAME);
        $this->assertSame(chunk_writer::TYPE_DB, $reader->type());
        $this->assertSame('first', $reader->read_item(86));
        $this->assertSame('second', $reader->read_item($second));
    }

    public function test_a_chunk_of_the_new_key_opens_with_the_new_and_the_old_key(): void {
        [$path, $second] = self::chunk(self::NEW);
        $reader = self::keys(self::NEW, self::OLD)->reader($path, self::SITE, self::NAME);
        $this->assertSame('first', $reader->read_item(86));
        $this->assertSame('second', $reader->read_item($second));
    }

    public function test_a_chunk_of_the_old_key_does_not_open_with_the_new_key_alone(): void {
        [$path] = self::chunk(self::OLD);
        $this->assertSame('restore_chunk_corrupt', $this->failure_of(self::keys(self::NEW), $path));
    }

    public function test_the_keys_are_tried_in_their_order_until_one_opens_the_chunk(): void {
        [$old] = self::chunk(self::OLD);
        [$new] = self::chunk(self::NEW);
        $keys = self::keys(self::NEW, self::OLD);
        // The chunk reader wipes the key pair with which it opens the header of a chunk: one for each key that is tried.
        $tried = fn() => array_values(array_filter(signing_keys_test::$wiped, fn(string $buffer) => strlen($buffer) === 64));

        $newpair = self::secret(self::NEW) . self::public(self::NEW);
        $oldpair = self::secret(self::OLD) . self::public(self::OLD);

        signing_keys_test::$wiped = [];
        $keys->reader($old, self::SITE, self::NAME);
        $this->assertSame([$newpair, $oldpair], $tried());

        // The first key opens the chunk: the second one is not tried.
        signing_keys_test::$wiped = [];
        $keys->reader($new, self::SITE, self::NAME);
        $this->assertSame([$newpair], $tried());

        // The other order of the same keys.
        signing_keys_test::$wiped = [];
        self::keys(self::OLD, self::NEW)->reader($new, self::SITE, self::NAME);
        $this->assertSame([$oldpair, $newpair], $tried());
    }

    public function test_a_chunk_of_another_site_does_not_open_with_the_right_key(): void {
        // The header opens with the key, which is the same: the site is in the authenticated data of the frames.
        [$path] = self::chunk(self::NEW, self::OTHER_SITE);
        $this->assertSame('restore_chunk_corrupt', $this->failure_of(self::keys(self::NEW, self::OLD), $path));
        // The control: it opens as the chunk of the other site.
        $reader = self::keys(self::NEW)->reader($path, self::OTHER_SITE, self::NAME);
        $this->assertSame('first', $reader->read_item(86));
    }

    public function test_a_chunk_of_another_name_does_not_open(): void {
        // A chunk of the site put in the place of another one.
        [$path] = self::chunk(self::NEW, self::SITE, self::OTHER_NAME);
        $this->assertSame('restore_chunk_corrupt', $this->failure_of(self::keys(self::NEW, self::OLD), $path));
    }

    /**
     * Damage done to a chunk of the new key, whose header is 86 bytes and whose first item starts at offset 86.
     *
     * @return array
     */
    public static function damaged_provider(): array {
        $flip = fn(int $position) => function (string $bytes) use ($position): string {
            $bytes[$position] = chr(ord($bytes[$position]) ^ 0x01);
            return $bytes;
        };
        return [
            'a changed byte in the sealed data key' => [$flip(40)],
            'a changed type' => [$flip(5)],
            'a changed byte in the first item' => [$flip(120)],
            'the header cut short' => [fn(string $bytes) => substr($bytes, 0, 50)],
            'the first item cut short' => [fn(string $bytes) => substr($bytes, 0, 120)],
            'an empty file' => [fn(string $bytes) => ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damaged_provider')]
    /**
     * A damaged chunk does not open with any key.
     *
     * @dataProvider damaged_provider
     * @param \Closure $damage Changes the bytes of the chunk.
     */
    public function test_a_damaged_chunk_does_not_open(\Closure $damage): void {
        [$path] = self::chunk(self::NEW);
        file_put_contents($path, $damage((string) file_get_contents($path)));
        $this->assertSame('restore_chunk_corrupt', $this->failure_of(self::keys(self::NEW, self::OLD), $path));
    }

    public function test_a_chunk_that_is_not_there_is_missing(): void {
        $dir = make_request_directory();
        $keys = self::keys(self::NEW);
        $this->assertSame('restore_chunk_missing', $this->failure_of($keys, $dir . '/' . self::NAME));
        // A folder in its place.
        mkdir($dir . '/' . self::NAME);
        $this->assertSame('restore_chunk_missing', $this->failure_of($keys, $dir . '/' . self::NAME));
    }

    public function test_the_exceptions_of_a_chunk_that_does_not_open_hide_the_keys(): void {
        [$path] = self::chunk(self::OLD, self::OTHER_SITE);
        $keys = self::keys(self::NEW, self::OLD);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $keys->reader($path, self::SITE, self::NAME);
            $this->fail('The chunk was opened');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_chunk_corrupt', $e->errorcode);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertNotEmpty(
            array_filter($e->getTrace(), fn($frame) => !empty($frame['args'])),
            'The trace keeps the arguments, so the check means something.'
        );
        $this->assertNull($e->getPrevious());
        $secrets = [];
        foreach ([self::secret(self::NEW), self::secret(self::OLD)] as $secret) {
            array_push($secrets, $secret, base64_encode($secret), bin2hex($secret));
        }
        foreach (array_merge(trace_strings::of($e), [$e->getMessage(), $e->getTraceAsString()]) as $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }
        $this->assertSame([], trace_strings::objects_in_plugin_frames($e));
    }

    public function test_the_keys_are_wiped_when_they_are_destroyed(): void {
        $keys = self::keys(self::NEW, self::OLD);
        signing_keys_test::$wiped = [];
        unset($keys);
        $this->assertSame([
            self::secret(self::NEW),
            self::public(self::NEW),
            self::secret(self::OLD),
            self::public(self::OLD),
        ], signing_keys_test::$wiped);
    }

    public function test_site_keys_made_one_after_the_other_keep_and_wipe_their_own_keys(): void {
        [$old] = self::chunk(self::OLD);
        $first = self::keys(self::OLD);
        $second = self::keys(self::NEW);
        $this->assertSame('first', $first->reader($old, self::SITE, self::NAME)->read_item(86));
        $this->assertSame('restore_chunk_corrupt', $this->failure_of($second, $old));

        signing_keys_test::$wiped = [];
        unset($first);
        $this->assertSame([self::secret(self::OLD), self::public(self::OLD)], signing_keys_test::$wiped);
        $this->assertSame(1, $second->count());
    }

    public function test_a_reader_still_reads_when_the_keys_are_destroyed(): void {
        [$path, $second] = self::chunk(self::OLD);
        $keys = self::keys(self::NEW, self::OLD);
        $reader = $keys->reader($path, self::SITE, self::NAME);
        unset($keys);
        $this->assertSame('second', $reader->read_item($second));
    }

    /**
     * Lists of secret keys that the constructor refuses. Each closure makes its list, so that the keys are not arguments of
     * the test, whose call is in the trace too.
     *
     * @return array
     */
    public static function wrong_keys_provider(): array {
        return [
            'no key' => [fn() => []],
            'eleven keys' => [fn() => array_fill(0, 11, str_repeat("\x01", 32))],
            'a key of 31 bytes' => [fn() => [str_repeat("\x01", 31)]],
            'a key of 33 bytes' => [fn() => [str_repeat("\x01", 32), str_repeat("\x01", 33)]],
            'a key that is not text' => [fn() => [null]],
            'an object of keys' => [fn() => ['newest' => str_repeat("\x01", 32)]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrong_keys_provider')]
    /**
     * Site keys are made from 1 to 10 secret keys of 32 bytes, in a list; anything else is a coding error, whose trace does not
     * hold the keys.
     *
     * @dataProvider wrong_keys_provider
     * @param \Closure $secrets Makes the secret keys.
     */
    public function test_wrong_keys_are_a_coding_error(\Closure $secrets): void {
        $list = $secrets();
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new site_keys($list);
            $this->fail('Site keys were made');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('1 to 10 X25519 secret keys', $e->getMessage());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $strings = trace_strings::of($e);
        foreach (array_filter($list, fn($secret) => is_string($secret)) as $secret) {
            foreach ($strings as $text) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }
        $this->assertSame([], trace_strings::objects_in_plugin_frames($e, [\Closure::class]));
    }

    public function test_ten_keys_are_the_most(): void {
        $this->assertSame(10, site_keys::MAX_KEYS);
    }

    public function test_the_keys_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(site_keys::class, '__construct'))->getParameters()[0];
        $this->assertSame('secretkeys', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_debug_output_and_var_export_do_not_show_the_keys(): void {
        $keys = self::keys(self::NEW, self::OLD);
        $this->assertSame(['count' => 2], $keys->__debugInfo());
        // Print_r() is forbidden by the Moodle code checker; it reads the object through __debugInfo(), as var_dump() does.
        $texts = [
            'var_export' => var_export($keys, true),
            'array cast' => var_export((array) $keys, true),
        ];
        ob_start();
        var_dump($keys);
        $texts['var_dump'] = (string) ob_get_clean();
        foreach ($texts as $what => $text) {
            foreach ([self::secret(self::NEW), self::secret(self::OLD)] as $secret) {
                foreach ([$secret, base64_encode($secret), bin2hex($secret)] as $spelling) {
                    $this->assertStringNotContainsString($spelling, $text, $what);
                }
            }
        }
    }

    public function test_keys_cannot_be_cloned_and_the_original_still_opens(): void {
        [$path] = self::chunk(self::OLD);
        $keys = self::keys(self::NEW, self::OLD);
        signing_keys_test::$wiped = [];
        try {
            $copy = clone $keys;
            $this->fail('The keys were cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be cloned', $e->getMessage());
        }
        // The refused copy, which PHP destroys, wipes nothing of the original.
        gc_collect_cycles();
        $this->assertSame([], signing_keys_test::$wiped);
        $this->assertSame(2, $keys->count());
        $this->assertSame('first', $keys->reader($path, self::SITE, self::NAME)->read_item(86));
    }

    public function test_keys_cannot_be_serialised(): void {
        $keys = self::keys(self::NEW);
        foreach ([fn() => serialize($keys), fn() => $keys->__sleep()] as $call) {
            try {
                $call();
                $this->fail('The keys were serialised');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must not be serialised', $e->getMessage());
            }
        }
        $class = site_keys::class;
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}', ['allowed_classes' => [$class]]);
            $this->fail('Keys were unserialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be unserialised', $e->getMessage());
        }
    }
}
