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

/**
 * Tests for the signing key pair and its storage, with a stand-in for sodium_memzero() in the namespace of the key pair.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\crypto;

defined('MOODLE_INTERNAL') || die();

/**
 * Wipes a buffer as libsodium does, and keeps a copy of it for the tests of signing_keys_test.
 *
 * PHP looks up a function that is called without a namespace in the namespace of the caller first, so while the tests run,
 * the classes of this namespace call this function instead of the one of libsodium. PHPUnit loads this file, which declares
 * it, before any test runs.
 *
 * The parameter has no type of string: PHP would turn a value of another type, such as false, into a string before the call,
 * and hide the SodiumException that libsodium throws for it.
 *
 * @param mixed $buffer The buffer, which is wiped.
 */
function sodium_memzero(mixed &$buffer): void {
    signing_keys_test::$wiped[] = $buffer;
    \sodium_memzero($buffer);
}

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\signing_keypair::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\signing_keys::class)]
/**
 * Tests for the signing key pair and its storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\signing_keypair
 * @covers     \tool_secbackup\local\crypto\signing_keys
 */
final class signing_keys_test extends \advanced_testcase {
    /** @var string[] Copies of the buffers that sodium_memzero() wiped, in order. */
    public static array $wiped = [];

    public function test_the_key_pair_derived_from_the_seed_is_wiped(): void {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        self::$wiped = [];
        new signing_keypair($secret);
        // The constructor makes the key pair of the seed again to check the public half, and wipes that copy of the secret.
        $this->assertSame([sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES))], self::$wiped);
    }

    public function test_the_generated_key_pair_is_wiped(): void {
        self::$wiped = [];
        $pair = signing_keypair::generate();
        // The constructor wipes its copy, then generate() wipes the key pair that libsodium made.
        $this->assertCount(2, self::$wiped);
        $this->assertSame($pair->export_secret(), sodium_crypto_sign_secretkey(self::$wiped[1]));
    }

    public function test_generated_pair_signs_verifiably(): void {
        $pair = signing_keypair::generate();
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($pair->public_key()));
        $signature = $pair->sign('message');
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'message', $pair->public_key()));
    }

    public function test_debug_output_hides_the_secret(): void {
        $pair = signing_keypair::generate();
        ob_start();
        var_dump($pair);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString($pair->export_secret(), $dump);
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $dump);
        $this->assertStringContainsString(base64_encode($pair->public_key()), $dump);
    }

    public function test_rejects_a_wrong_secret_length(): void {
        $this->expectException(\coding_exception::class);
        new signing_keypair('short');
    }

    public function test_rejects_a_secret_that_is_too_long(): void {
        $this->expectException(\coding_exception::class);
        new signing_keypair(str_repeat('a', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES + 1));
    }

    public function test_a_pair_cannot_be_serialised(): void {
        $pair = signing_keypair::generate();
        $this->expectException(\coding_exception::class);
        serialize($pair);
    }

    public function test_a_pair_cannot_be_put_to_sleep(): void {
        // PHP calls __serialize() first when a class has both hooks, so only a direct call reaches this one.
        $pair = signing_keypair::generate();
        $this->expectException(\coding_exception::class);
        $pair->__sleep();
    }

    public function test_a_crafted_string_cannot_build_a_pair(): void {
        // The serialised form of an object whose constructor never ran: the seed of one pair, the public key of another.
        $class = signing_keypair::class;
        $secret = self::mismatching_secret();
        $crafted = 'O:' . strlen($class) . ':"' . $class . '":2:{'
            . self::serialised_private_property($class, 'secret', $secret)
            . self::serialised_private_property($class, 'public', substr($secret, SODIUM_CRYPTO_SIGN_SEEDBYTES))
            . '}';
        $this->expectException(\coding_exception::class);
        unserialize($crafted, ['allowed_classes' => [$class]]);
    }

    public function test_rejects_a_public_half_that_does_not_belong_to_the_seed(): void {
        try {
            new signing_keypair(self::mismatching_secret());
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_corrupt', $e->errorcode);
        }
    }

    public function test_the_secret_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(signing_keypair::class, '__construct'))->getParameters()[0];
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_nothing_is_stored_until_saved(): void {
        $this->resetAfterTest();
        signing_keypair::generate();
        $this->assertNull(signing_keys::load());
    }

    public function test_save_load_and_forget(): void {
        $this->resetAfterTest();
        $pair = signing_keypair::generate();
        signing_keys::save($pair);

        $stored = get_config('tool_secbackup', 'signingsecret');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $stored);

        $loaded = signing_keys::load();
        $this->assertNotNull($loaded);
        $this->assertSame($pair->public_key(), $loaded->public_key());
        // The public key is a copy of the second half of the secret: the first half has to come back as well.
        $signature = $loaded->sign('message');
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'message', $pair->public_key()));

        signing_keys::forget();
        $this->assertNull(signing_keys::load());
    }

    public function test_corrupt_storage_is_reported(): void {
        $this->resetAfterTest();
        set_config('signingsecret', \core\encryption::encrypt(base64_encode('short')), 'tool_secbackup');
        try {
            signing_keys::load();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_corrupt', $e->errorcode);
        }
    }

    public function test_a_stored_secret_that_is_too_long_is_reported(): void {
        $this->resetAfterTest();
        $toolong = str_repeat('a', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES + 1);
        set_config('signingsecret', \core\encryption::encrypt(base64_encode($toolong)), 'tool_secbackup');
        try {
            signing_keys::load();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_corrupt', $e->errorcode);
        }
    }

    public function test_a_stored_secret_with_a_public_half_that_does_not_match_is_reported(): void {
        $this->resetAfterTest();
        set_config('signingsecret', \core\encryption::encrypt(base64_encode(self::mismatching_secret())), 'tool_secbackup');
        try {
            signing_keys::load();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_corrupt', $e->errorcode);
        }
    }

    public function test_an_empty_stored_value_means_no_key(): void {
        $this->resetAfterTest();
        set_config('signingsecret', '', 'tool_secbackup');
        $this->assertNull(signing_keys::load());
    }

    public function test_unreadable_storage_is_reported_with_its_cause(): void {
        $this->resetAfterTest();
        // Not encrypted by Moodle at all, and encrypted with another key (the data root's key was lost).
        $cases = [
            'encryption_wrongmethod' => 'not encrypted at all',
            'encryption_decryptfailed' => 'sodium:' . base64_encode(str_repeat("\x00", 64)),
        ];
        foreach ($cases as $cause => $stored) {
            set_config('signingsecret', $stored, 'tool_secbackup');
            try {
                signing_keys::load();
                $this->fail('No exception');
            } catch (key_exception $e) {
                $this->assertSame('signingkey_corrupt', $e->errorcode);
                $this->assertSame($cause, $e->debuginfo);
            }
        }
    }

    /**
     * Failures inside the encryption of Moodle that save() has to report.
     *
     * @return array
     */
    public static function failing_encryption_provider(): array {
        return [
            'Moodle finds no key' => ['nokey'],
            'an exception that is not one of Moodle' => ['bare'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_encryption_provider')]
    /**
     * A failed encryption is reported as a key_exception that shows no secret, and nothing is stored.
     *
     * @dataProvider failing_encryption_provider
     * @param string $failure What fails, as in save_while_encryption_fails().
     */
    public function test_a_failed_encryption_is_reported_without_the_secret(string $failure): void {
        $this->resetAfterTest();
        [$cause, $e, $needles] = $this->save_while_encryption_fails($failure);

        $this->assertSame('signingkey_savefailed', $e->errorcode);
        $this->assertSame($cause, $e->debuginfo);
        // No string of the plugin uses a value yet, so nothing else would notice a secret handed over as $a.
        $this->assertNull($e->a);
        $this->assertNull($e->getPrevious());
        $this->assertFalse(self::trace_shows($e, $needles));
        $this->assertFalse(get_config('tool_secbackup', 'signingsecret'));
        $this->assertNull(signing_keys::load());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing_encryption_provider')]
    /**
     * The key pair itself is not left in the arguments of the trace either.
     *
     * @dataProvider failing_encryption_provider
     * @param string $failure What fails, as in save_while_encryption_fails().
     */
    public function test_a_failed_encryption_does_not_leave_the_key_pair_in_the_trace(string $failure): void {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Before PHP 8.2 #[\SensitiveParameter] does nothing, so the key pair stays in the'
                . ' arguments of the trace there.');
        }
        $this->resetAfterTest();
        [, $e] = $this->save_while_encryption_fails($failure);
        $this->assertFalse(self::trace_holds_a_pair($e));
    }

    /**
     * Makes the encryption of Moodle fail, saves a key pair and returns what save() throws.
     *
     * A trace holds the arguments of the calls only while zend.exception_ignore_args is off, so it is off during the
     * call: with it on, the checks of a trace would find nothing whatever the code does. Controls show that the
     * encryption does fail and shows its own argument in its trace, and that the checks do find a text and a key pair
     * in a trace.
     *
     * The key pair is made here and not passed in: a pair given as an argument would be in the trace of the exception.
     *
     * @param string $failure "nokey": Moodle has no key and may not create one. "bare": the exception that leaves
     *     encrypt() is not one of Moodle's.
     * @return array The class of the exception that encrypt() throws, the key_exception that save() throws, and the
     *     texts of the secret that must not appear in it (the first 15 characters, which is what getTraceAsString()
     *     shows of a string, of the secret itself and of its base64).
     */
    private function save_while_encryption_fails(string $failure): array {
        global $CFG;
        $pair = signing_keypair::generate();
        $needles = [substr($pair->export_secret(), 0, 15), substr(base64_encode($pair->export_secret()), 0, 15)];
        if ($failure === 'nokey') {
            // An empty folder for the key of the data root, and Moodle may not create one: encrypt() finds no key.
            $CFG->secretdataroot = make_request_directory();
            $CFG->nokeygeneration = true;
        } else {
            // The folder of the key cannot be turned into text, so encrypt() fails with an exception of PHP.
            $CFG->secretdataroot = new class implements \Stringable {
                /**
                 * Always fails.
                 *
                 * @return string
                 */
                public function __toString(): string {
                    throw new \RuntimeException('Provoked by the test');
                }
            };
        }
        $previous = ini_set('zend.exception_ignore_args', '0');
        $this->assertNotFalse($previous);
        try {
            $cause = null;
            try {
                \core\encryption::encrypt('probe');
            } catch (\Throwable $thrown) {
                $cause = $thrown;
            }
            $this->assertNotNull($cause, 'encrypt() has to fail for this test to mean anything');
            if ($failure === 'bare') {
                $this->assertNotInstanceOf(\moodle_exception::class, $cause);
            } else {
                $this->assertInstanceOf(\moodle_exception::class, $cause);
            }
            $this->assertTrue(self::trace_shows($cause, ['probe']), 'The trace of encrypt() shows its argument.');
            $this->assertTrue(self::trace_holds_a_pair(self::exception_created_with($pair)));

            try {
                signing_keys::save($pair);
            } catch (key_exception $e) {
                return [get_class($cause), $e, $needles];
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->fail('save() has to throw a key_exception');
    }

    /**
     * A 64-byte secret whose second half is the public key of another pair than the one its first half (the seed) makes.
     *
     * @return string
     */
    private static function mismatching_secret(): string {
        $seedowner = signing_keypair::generate();
        $other = signing_keypair::generate();
        return substr($seedowner->export_secret(), 0, SODIUM_CRYPTO_SIGN_SEEDBYTES) . $other->public_key();
    }

    /**
     * The serialised form of a private property of a class: its name has the class between NUL bytes.
     *
     * @param string $class The class that declares the property.
     * @param string $name The name of the property.
     * @param string $value The value, a string.
     * @return string
     */
    private static function serialised_private_property(string $class, string $name, string $value): string {
        $key = "\0" . $class . "\0" . $name;
        return 's:' . strlen($key) . ':"' . $key . '";s:' . strlen($value) . ':"' . $value . '";';
    }

    /**
     * Returns an exception created in a call with the argument, so that its trace holds the argument when PHP keeps
     * arguments in traces.
     *
     * @param mixed $argument Anything.
     * @return \Exception
     */
    private static function exception_created_with(mixed $argument): \Exception {
        return new \Exception('Control exception for ' . gettype($argument));
    }

    /**
     * Tells whether the trace of an exception shows one of the texts, as an argument or in the trace as text.
     *
     * @param \Throwable $exception The exception.
     * @param string[] $needles Texts that must not appear.
     * @return bool
     */
    private static function trace_shows(\Throwable $exception, array $needles): bool {
        $text = $exception->getMessage() . "\n" . $exception->getTraceAsString();
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return self::any_argument($exception, static function ($argument) use ($needles): bool {
            foreach ($needles as $needle) {
                if (is_string($argument) && str_contains($argument, $needle)) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Tells whether an argument in the trace of an exception is a key pair, which holds the secret.
     *
     * @param \Throwable $exception The exception.
     * @return bool
     */
    private static function trace_holds_a_pair(\Throwable $exception): bool {
        return self::any_argument($exception, static fn($argument): bool => $argument instanceof signing_keypair);
    }

    /**
     * Tells whether the test is true for an argument in the trace of an exception, or for an element of an array
     * argument (followed five levels deep).
     *
     * @param \Throwable $exception The exception.
     * @param callable $test Gets one value and returns a bool.
     * @return bool
     */
    private static function any_argument(\Throwable $exception, callable $test): bool {
        foreach ($exception->getTrace() as $frame) {
            if (self::any_value($frame['args'] ?? [], $test, 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tells whether the test is true for a value or for an element of it, when it is an array.
     *
     * @param mixed $value The value.
     * @param callable $test Gets one value and returns a bool.
     * @param int $depth How deep the arrays have been followed.
     * @return bool
     */
    private static function any_value(mixed $value, callable $test, int $depth): bool {
        if ($test($value)) {
            return true;
        }
        if (is_array($value) && $depth < 5) {
            foreach ($value as $item) {
                if (self::any_value($item, $test, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }
}
