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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\key_exchange::class)]
/**
 * Tests for the one-time key pair with which a restore receives the keys of the site.
 *
 * The tests play the part of the backup service: they seal the secret keys of the site to the public key of the exchange, as
 * the service does with crypto_box_seal.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\key_exchange
 */
final class key_exchange_test extends \basic_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Name of the chunk of the tests. */
    private const NAME = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';

    /**
     * An encryption key pair of the site, the same in every call for one seed.
     *
     * @param int $seed Byte of the seed: 1 is the newer key of the site, 2 the older one.
     * @return string
     */
    private static function site_pair(int $seed): string {
        return sodium_crypto_box_seed_keypair(str_repeat(chr($seed), 32));
    }

    /**
     * A secret key of the site.
     *
     * @param int $seed Byte of the seed.
     * @return string 32 bytes.
     */
    private static function site_secret(int $seed): string {
        return sodium_crypto_box_secretkey(self::site_pair($seed));
    }

    /**
     * Seals bytes to the public key of an exchange, in standard base64, as the backup service does.
     *
     * @param string $bytes The bytes.
     * @param key_exchange $exchange The exchange.
     * @return string
     */
    private static function seal(string $bytes, key_exchange $exchange): string {
        return base64_encode(sodium_crypto_box_seal($bytes, $exchange->public_key()));
    }

    /**
     * Writes a chunk of one item with a public key of the site.
     *
     * @param string $publickey The public key.
     * @return string Path of the chunk.
     */
    private static function chunk(string $publickey): string {
        $path = make_request_directory() . '/' . self::NAME;
        $writer = new chunk_writer($path, chunk_writer::TYPE_FILES, self::NAME, $publickey, self::SITE);
        $writer->add_item('payload', 7);
        $writer->finish();
        return $path;
    }

    /**
     * The one-time key pair of an exchange, as its open() wiped it: the secret key, then the public key.
     *
     * @param key_exchange $exchange The exchange, which open() has used.
     * @return string 64 bytes.
     */
    private function wiped_pair(key_exchange $exchange): string {
        $pairs = array_values(array_filter(
            signing_keys_test::$wiped,
            fn(string $buffer) => strlen($buffer) === 64 && str_ends_with($buffer, $exchange->public_key())
        ));
        $this->assertCount(1, $pairs, 'The one-time key pair is wiped once.');
        return $pairs[0];
    }

    public function test_the_public_key_is_a_new_x25519_key_each_time(): void {
        $first = new key_exchange();
        $second = new key_exchange();
        $this->assertSame(32, strlen($first->public_key()));
        $this->assertNotSame($first->public_key(), $second->public_key());
        // The same key every time it is asked for.
        $this->assertSame($first->public_key(), $first->public_key());
    }

    public function test_two_sealed_keys_open_in_their_order(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange), self::seal(self::site_secret(2), $exchange)];
        $keys = $exchange->open($sealed);
        $this->assertSame(2, $keys->count());

        // The keys are the ones that were sealed: a chunk of the older key opens.
        $reader = $keys->reader(self::chunk(sodium_crypto_box_publickey(self::site_pair(2))), self::SITE, self::NAME);
        $this->assertSame('payload', $reader->read_item(86));

        // In the order given: the site keys wipe them in that order when they are destroyed.
        signing_keys_test::$wiped = [];
        unset($keys);
        $this->assertSame([
            self::site_secret(1),
            sodium_crypto_box_publickey(self::site_pair(1)),
            self::site_secret(2),
            sodium_crypto_box_publickey(self::site_pair(2)),
        ], signing_keys_test::$wiped);
    }

    public function test_one_and_ten_sealed_keys_are_enough_and_the_most(): void {
        foreach ([1, 10] as $count) {
            $exchange = new key_exchange();
            $sealed = [];
            for ($i = 1; $i <= $count; $i++) {
                $sealed[] = self::seal(self::site_secret($i), $exchange);
            }
            $this->assertSame($count, $exchange->open($sealed)->count());
        }
    }

    /**
     * Lists of sealed keys that are not keys of the site. Each closure makes the list for the exchange it is given.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        $seal = fn(string $bytes, key_exchange $exchange) => self::seal($bytes, $exchange);
        $one = fn(key_exchange $exchange) => $seal(self::site_secret(1), $exchange);
        return [
            'no key' => [fn(key_exchange $exchange) => []],
            'eleven keys' => [fn(key_exchange $exchange) => array_fill(0, 11, $one($exchange))],
            'a damaged seal' => [function (key_exchange $exchange) use ($one): array {
                $raw = base64_decode($one($exchange));
                $raw[40] = chr(ord($raw[40]) ^ 0x01);
                return [base64_encode($raw)];
            }],
            'a sealed key of 33 bytes' => [fn(key_exchange $exchange) => [$seal(self::site_secret(1) . "\x01", $exchange)]],
            'a sealed key of 31 bytes' => [fn(key_exchange $exchange) => [$seal(substr(self::site_secret(1), 1), $exchange)]],
            'a sealed empty key' => [fn(key_exchange $exchange) => [$seal('', $exchange)]],
            'a key sealed to another exchange' => [fn(key_exchange $exchange) => [$one(new key_exchange())]],
            'a key that is not sealed' => [fn(key_exchange $exchange) => [base64_encode(self::site_secret(1))]],
            'a good key, then a damaged one' => [function (key_exchange $exchange) use ($one): array {
                return [$one($exchange), substr($one($exchange), 4)];
            }],
            'text that is not base64' => [fn(key_exchange $exchange) => ['@@@@']],
            'an empty text' => [fn(key_exchange $exchange) => ['']],
            'base64 with a line feed inside' => [fn(key_exchange $exchange) => [substr_replace($one($exchange), "\n", 20, 0)]],
            'base64 with a line feed at the end' => [fn(key_exchange $exchange) => [$one($exchange) . "\n"]],
            'base64 without its padding' => [fn(key_exchange $exchange) => [rtrim($one($exchange), '=')]],
            'base64url' => [fn(key_exchange $exchange) => [strtr(rtrim($one($exchange), '='), '+/', '-_')]],
            // The last character before the padding carries two bits that the encoder leaves at zero.
            'base64 that is not canonical' => [function (key_exchange $exchange) use ($one): array {
                $text = $one($exchange);
                $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
                $last = strpos($alphabet, $text[106]);
                $text[106] = $alphabet[$last | 0x03];
                return [$text];
            }],
            'a number' => [fn(key_exchange $exchange) => [12345678]],
            'null' => [fn(key_exchange $exchange) => [null]],
            'an array' => [fn(key_exchange $exchange) => [[$one($exchange)]]],
            'an object of keys instead of a list' => [fn(key_exchange $exchange) => ['newest' => $one($exchange)]],
            'a list that does not start at 0' => [fn(key_exchange $exchange) => [1 => $one($exchange)]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * Anything but a list of 1 to 10 keys of 32 bytes, each sealed to the exchange in standard base64, is refused.
     *
     * @dataProvider invalid_provider
     * @param \Closure $sealed Makes the list for an exchange.
     */
    public function test_keys_that_cannot_be_used_are_refused(\Closure $sealed): void {
        $exchange = new key_exchange();
        try {
            $exchange->open($sealed($exchange));
            $this->fail('The keys were opened');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_keys_invalid', $e->errorcode);
        }
    }

    public function test_the_one_time_secret_key_is_wiped_when_the_keys_are_opened(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange), self::seal(self::site_secret(2), $exchange)];
        signing_keys_test::$wiped = [];
        $keys = $exchange->open($sealed);
        // The one-time key pair first; then the copies of the opened keys, which the site keys now hold alone.
        $this->assertSame(
            [self::site_secret(1), self::site_secret(2)],
            array_slice(signing_keys_test::$wiped, 1)
        );
        $pair = signing_keys_test::$wiped[0];
        $this->assertSame($exchange->public_key(), sodium_crypto_box_publickey($pair));
        $this->assertSame(2, $keys->count());
    }

    public function test_the_one_time_secret_key_and_the_opened_keys_are_wiped_when_a_key_is_refused(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange), self::seal(self::site_secret(2) . "\x01", $exchange)];
        signing_keys_test::$wiped = [];
        try {
            $exchange->open($sealed);
            $this->fail('The keys were opened');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_keys_invalid', $e->errorcode);
        }
        // The one-time key pair, the key that had been opened, and the bytes of 33 that were opened from the second seal.
        $this->assertSame($exchange->public_key(), sodium_crypto_box_publickey(signing_keys_test::$wiped[0]));
        $this->assertSame([self::site_secret(1), self::site_secret(2) . "\x01"], array_slice(signing_keys_test::$wiped, 1));
    }

    public function test_the_one_time_secret_key_is_wiped_when_the_list_is_refused_before_any_seal_is_opened(): void {
        $exchange = new key_exchange();
        signing_keys_test::$wiped = [];
        try {
            $exchange->open([]);
            $this->fail('The keys were opened');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_keys_invalid', $e->errorcode);
        }
        $this->assertCount(1, signing_keys_test::$wiped);
        $this->assertSame($exchange->public_key(), sodium_crypto_box_publickey(signing_keys_test::$wiped[0]));
    }

    public function test_an_exchange_that_is_not_used_wipes_its_secret_key_when_it_is_destroyed(): void {
        $exchange = new key_exchange();
        $public = $exchange->public_key();
        signing_keys_test::$wiped = [];
        unset($exchange);
        $this->assertCount(1, signing_keys_test::$wiped);
        $this->assertSame($public, sodium_crypto_box_publickey(signing_keys_test::$wiped[0]));

        // A used exchange has nothing left to wipe.
        $exchange = new key_exchange();
        $keys = $exchange->open([self::seal(self::site_secret(1), $exchange)]);
        signing_keys_test::$wiped = [];
        unset($exchange);
        $this->assertSame([], signing_keys_test::$wiped);
        $this->assertSame(1, $keys->count());
    }

    public function test_the_keys_can_be_opened_only_once(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange)];
        $exchange->open($sealed);
        try {
            $exchange->open($sealed);
            $this->fail('The keys were opened twice');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('only once', $e->getMessage());
        }

        // Also after a refused list: the one-time secret key is gone.
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange)];
        try {
            $exchange->open([]);
        } catch (plugin_exception $e) {
            $this->assertSame('restore_keys_invalid', $e->errorcode);
        }
        try {
            $exchange->open($sealed);
            $this->fail('The keys were opened after a refused list');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('only once', $e->getMessage());
        }
    }

    /**
     * The forms in which a secret could show in text: the bytes, base64 and hexadecimal.
     *
     * @param string[] $secrets The secrets.
     * @return string[]
     */
    private static function spellings(array $secrets): array {
        $spellings = [];
        foreach ($secrets as $secret) {
            array_push($spellings, $secret, base64_encode($secret), bin2hex($secret));
        }
        return $spellings;
    }

    /**
     * Checks that a text holds none of the secrets.
     *
     * @param string $text The text.
     * @param string[] $secrets The secrets, in every spelling.
     * @param string $what What the text is.
     */
    private function assert_hides(string $text, array $secrets, string $what): void {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $text, $what);
        }
    }

    public function test_debug_output_and_var_export_do_not_show_the_keys(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange)];
        // Print_r() is forbidden by the Moodle code checker; it reads the object through __debugInfo(), as var_dump() does.
        $before = [
            'var_export' => var_export($exchange, true),
            'array cast' => var_export((array) $exchange, true),
        ];
        ob_start();
        var_dump($exchange);
        $before['var_dump'] = (string) ob_get_clean();
        $this->assertSame(['public' => base64_encode($exchange->public_key()), 'used' => false], $exchange->__debugInfo());

        signing_keys_test::$wiped = [];
        $exchange->open($sealed);
        $secrets = self::spellings([$this->wiped_pair($exchange), substr($this->wiped_pair($exchange), 0, 32)]);
        foreach ($before as $what => $text) {
            $this->assert_hides($text, $secrets, $what);
        }
        $this->assertStringContainsString(base64_encode($exchange->public_key()), $before['var_dump']);
        $this->assertSame(['public' => base64_encode($exchange->public_key()), 'used' => true], $exchange->__debugInfo());
    }

    /**
     * Lists that open() refuses after it has opened a key, or before, for the tests of the traces.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'a good key, then 33 bytes' => [fn(key_exchange $exchange) => [
                self::seal(self::site_secret(1), $exchange),
                self::seal(self::site_secret(2) . "\x01", $exchange),
            ]],
            'a good key, then text that is not base64' => [fn(key_exchange $exchange) => [
                self::seal(self::site_secret(1), $exchange),
                '@' . self::seal(self::site_secret(2), $exchange),
            ]],
            'eleven keys' => [fn(key_exchange $exchange) => array_fill(0, 11, self::seal(self::site_secret(1), $exchange))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    /**
     * The exception of a refused list shows neither a key nor a sealed key: not in its message, its debug information, its
     * trace or its printed form.
     *
     * @dataProvider refused_provider
     * @param \Closure $sealed Makes the list for an exchange.
     */
    public function test_the_exception_of_refused_keys_hides_them(\Closure $sealed): void {
        $exchange = new key_exchange();
        $list = $sealed($exchange);
        signing_keys_test::$wiped = [];
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $exchange->open($list);
            $this->fail('The keys were opened');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_keys_invalid', $e->errorcode);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertNotEmpty(
            array_filter($e->getTrace(), fn($frame) => array_key_exists('args', $frame)),
            'The trace keeps the arguments, so the check means something.'
        );
        $pair = $this->wiped_pair($exchange);
        $secrets = self::spellings(array_merge(
            [$pair, substr($pair, 0, 32), self::site_secret(1), self::site_secret(2)],
            array_map(fn($text) => base64_decode(ltrim($text, '@')), $list)
        ));
        $secrets = array_merge($secrets, $list);
        $this->assertNull($e->getPrevious());
        $texts = array_merge(trace_strings::of($e), [$e->getMessage(), (string) $e->debuginfo, $e->getTraceAsString()]);
        foreach ($texts as $text) {
            $this->assert_hides($text, $secrets, 'exception');
        }
        // The list is a sensitive parameter, which open() lets go of before it can throw. The closure is the argument of the test.
        $this->assertSame([], trace_strings::objects_in_plugin_frames($e, [\Closure::class]));
    }

    public function test_the_list_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(key_exchange::class, 'open'))->getParameters()[0];
        $this->assertSame('sealedbase64', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_an_exchange_cannot_be_cloned_and_the_original_still_opens(): void {
        $exchange = new key_exchange();
        $sealed = [self::seal(self::site_secret(1), $exchange)];
        signing_keys_test::$wiped = [];
        try {
            $copy = clone $exchange;
            $this->fail('The exchange was cloned');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be cloned', $e->getMessage());
        }
        // The refused copy, which PHP destroys, wipes nothing of the original.
        gc_collect_cycles();
        $this->assertSame([], signing_keys_test::$wiped);
        $this->assertSame(1, $exchange->open($sealed)->count());
    }

    public function test_an_exchange_cannot_be_serialised(): void {
        $exchange = new key_exchange();
        foreach ([fn() => serialize($exchange), fn() => $exchange->__sleep()] as $call) {
            try {
                $call();
                $this->fail('The exchange was serialised');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must not be serialised', $e->getMessage());
            }
        }
        $class = key_exchange::class;
        try {
            unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}', ['allowed_classes' => [$class]]);
            $this->fail('An exchange was unserialised');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('must not be unserialised', $e->getMessage());
        }
    }
}
