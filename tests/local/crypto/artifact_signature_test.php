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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\artifact_signature::class)]
/**
 * Tests for the signatures of the manifest and the index.
 *
 * The messages are the ones that the backup service builds and checks; their hexadecimal forms are fixed here.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\artifact_signature
 */
final class artifact_signature_test extends \basic_testcase {
    /** @var string Site ID of the vectors. */
    private const SITE = '11111111-1111-4111-8111-111111111111';

    /** @var string Run ID of the vectors. */
    private const RUN = '22222222-2222-4222-8222-222222222222';

    /** @var string Manifest message of the vectors, in hexadecimal. */
    private const MANIFEST_HEX = '5345434241434b55502d4d414e49464553542d56310a31313131313131312d313131312d34313131'
        . '2d383131312d3131313131313131313131310a32323232323232322d323232322d343232322d3832'
        . '32322d3232323232323232323232320a415145424151454241514542415145424151454241514542'
        . '415145424151454241514542415145424151453d';

    /** @var string Index message of the vectors with a count of 3, in hexadecimal. */
    private const INDEX_HEX = '5345434241434b55502d494e4445582d56310a31313131313131312d313131312d343131312d3831'
        . '31312d3131313131313131313131310a32323232323232322d323232322d343232322d383232322d'
        . '3232323232323232323232320a415145424151454241514542415145424151454241514542415145'
        . '424151454241514542415145424151453d0a33';

    /**
     * The SHA-256 of the vectors: 32 bytes of 0x01, in standard base64.
     *
     * @return string
     */
    private static function sha(): string {
        return base64_encode(str_repeat("\x01", 32));
    }

    public function test_the_manifest_message_is_the_one_of_the_service(): void {
        $this->assertSame(self::MANIFEST_HEX, bin2hex(artifact_signature::manifest_message(self::SITE, self::RUN, self::sha())));
    }

    public function test_the_index_message_is_the_one_of_the_service(): void {
        $this->assertSame(self::INDEX_HEX, bin2hex(artifact_signature::index_message(self::SITE, self::RUN, self::sha(), 3)));
    }

    public function test_the_signatures_verify_with_the_public_key_over_the_message(): void {
        $pair = signing_keypair::generate();
        $manifest = artifact_signature::manifest($pair, self::SITE, self::RUN, self::sha());
        $index = artifact_signature::index($pair, self::SITE, self::RUN, self::sha(), 3);
        foreach ([$manifest, $index] as $signature) {
            // Base64url without padding.
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{86}\z/', $signature);
        }
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64url::decode($manifest),
            hex2bin(self::MANIFEST_HEX),
            $pair->public_key()
        ));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64url::decode($index),
            hex2bin(self::INDEX_HEX),
            $pair->public_key()
        ));
        // A signature of one does not verify as the other.
        $this->assertFalse(sodium_crypto_sign_verify_detached(
            base64url::decode($manifest),
            hex2bin(self::INDEX_HEX),
            $pair->public_key()
        ));
    }

    public function test_an_index_of_no_chunk_names_has_the_count_zero(): void {
        $this->assertStringEndsWith("\n0", artifact_signature::index_message(self::SITE, self::RUN, self::sha(), 0));
    }

    public function test_the_count_is_part_of_the_signed_index(): void {
        $pair = signing_keypair::generate();
        $this->assertNotSame(
            artifact_signature::index($pair, self::SITE, self::RUN, self::sha(), 3),
            artifact_signature::index($pair, self::SITE, self::RUN, self::sha(), 4)
        );
    }

    /**
     * Arguments that are not what the service reads.
     *
     * @return array
     */
    public static function bad_provider(): array {
        $sha = base64_encode(str_repeat("\x01", 32));
        return [
            'a site that is not a UUID' => ['x', self::RUN, $sha, 1],
            'a run in capitals' => [self::SITE, 'AAAAAAAA-BBBB-4CCC-8DDD-000000000001', $sha, 1],
            'a run with a line feed' => [self::SITE, self::RUN . "\n", $sha, 1],
            'a digest of the wrong length' => [self::SITE, self::RUN, 'AAAA', 1],
            'a digest that is not canonical' => [self::SITE, self::RUN, 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQF=', 1],
            // Canonical base64 that ends like a digest of 32 bytes: only the start of the pattern refuses it.
            'a digest of 35 bytes' => [self::SITE, self::RUN, base64_encode(str_repeat("\x01", 35)), 1],
            // The base64 of "1", which is true as a string.
            'a digest of one byte' => [self::SITE, self::RUN, 'MQ==', 1],
            // The base64 of false, which is the empty string.
            'an empty digest' => [self::SITE, self::RUN, '', 1],
            'a negative count' => [self::SITE, self::RUN, $sha, -1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_provider')]
    /**
     * A wrong argument is a coding error.
     *
     * @dataProvider bad_provider
     * @param string $site Site ID.
     * @param string $run Run ID.
     * @param string $sha Digest.
     * @param int $count Count.
     */
    public function test_a_wrong_argument_is_a_coding_error(string $site, string $run, string $sha, int $count): void {
        $this->expectException(\coding_exception::class);
        artifact_signature::index_message($site, $run, $sha, $count);
    }

    public function test_the_key_is_not_in_the_trace_of_a_failed_signature(): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $pair = signing_keypair::generate();
            try {
                artifact_signature::manifest($pair, 'x', self::RUN, self::sha());
                $this->fail('No exception');
            } catch (\coding_exception $e) {
                $secret = $pair->export_secret();
                foreach (\tool_secbackup\tests\trace_strings::of($e) as $string) {
                    $this->assertStringNotContainsString($secret, $string);
                    $this->assertStringNotContainsString(base64_encode($secret), $string);
                }
                if (PHP_VERSION_ID >= 80200) {
                    // The pair is passed as a sensitive parameter: the trace keeps a wrapper, not the pair.
                    foreach ($e->getTrace() as $frame) {
                        foreach ($frame['args'] ?? [] as $argument) {
                            $this->assertNotInstanceOf(signing_keypair::class, $argument);
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function test_the_manifest_message_checks_its_arguments_too(): void {
        $this->expectException(\coding_exception::class);
        artifact_signature::manifest_message(self::SITE, 'x', self::sha());
    }

    public function test_a_signature_of_the_manifest_verifies(): void {
        $pair = signing_keypair::generate();
        $signature = artifact_signature::manifest($pair, self::SITE, self::RUN, self::sha());
        $this->assertTrue(artifact_signature::verify_manifest($pair->public_key(), self::SITE, self::RUN, self::sha(), $signature));
    }

    public function test_the_verified_message_is_the_one_of_the_service(): void {
        // A signature made by libsodium over the message written out above, not by manifest().
        $pair = sodium_crypto_sign_keypair();
        $raw = sodium_crypto_sign_detached(hex2bin(self::MANIFEST_HEX), sodium_crypto_sign_secretkey($pair));
        $signature = base64url::encode($raw);
        $this->assertTrue(artifact_signature::verify_manifest(
            sodium_crypto_sign_publickey($pair),
            self::SITE,
            self::RUN,
            self::sha(),
            $signature
        ));
    }

    /**
     * A manifest whose signature must not verify: what is changed from the signed values (32 bytes of 0x01 as the digest).
     *
     * @return array
     */
    public static function unverified_provider(): array {
        $sha = base64_encode(str_repeat("\x01", 32));
        $othersha = 'B' . substr($sha, 1);
        return [
            // The first character of the digest, still a canonical digest of 32 bytes.
            'a digest that differs in one character' => [['sha' => $othersha]],
            'another run' => [['run' => '33333333-3333-4333-8333-333333333333']],
            'another site' => [['site' => '44444444-4444-4444-8444-444444444444']],
            'another key' => [['key' => 'another']],
            'the signature of the index of the same run' => [['signature' => 'index']],
            'a signature with one bit changed' => [['signature' => 'flipped']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unverified_provider')]
    /**
     * A signature does not verify for any other manifest, site, run or key.
     *
     * @dataProvider unverified_provider
     * @param array $change What differs from the signed manifest.
     */
    public function test_a_signature_does_not_verify_for_anything_else(array $change): void {
        $pair = signing_keypair::generate();
        $signature = artifact_signature::manifest($pair, self::SITE, self::RUN, self::sha());
        $key = $pair->public_key();
        if (($change['key'] ?? null) === 'another') {
            $key = signing_keypair::generate()->public_key();
        }
        if (($change['signature'] ?? null) === 'index') {
            $signature = artifact_signature::index($pair, self::SITE, self::RUN, self::sha(), 1);
        } else if (($change['signature'] ?? null) === 'flipped') {
            $raw = base64url::decode($signature);
            $raw[10] = chr(ord($raw[10]) ^ 0x01);
            $signature = base64url::encode($raw);
        }
        $this->assertFalse(artifact_signature::verify_manifest(
            $key,
            $change['site'] ?? self::SITE,
            $change['run'] ?? self::RUN,
            $change['sha'] ?? self::sha(),
            $signature
        ));
        // The control: the signature of the unchanged manifest verifies.
        $this->assertTrue(artifact_signature::verify_manifest(
            $pair->public_key(),
            self::SITE,
            self::RUN,
            self::sha(),
            artifact_signature::manifest($pair, self::SITE, self::RUN, self::sha())
        ));
    }

    /**
     * Values that are not in their form. Each is given in place of the value of a valid signature, which then must not verify.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        $sha = base64_encode(str_repeat("\x01", 32));
        return [
            'a public key of 31 bytes' => ['key', fn(string $key) => substr($key, 1)],
            'a public key of 33 bytes' => ['key', fn(string $key) => $key . "\0"],
            'an empty public key' => ['key', fn(string $key) => ''],
            'the public key in base64' => ['key', fn(string $key) => base64_encode($key)],
            // A point of small order, which libsodium refuses as a public key.
            'a public key of zeros' => ['key', fn(string $key) => str_repeat("\0", 32)],
            'a signature in standard base64' => ['signature', fn(string $signature) => base64_encode(
                base64url::decode($signature)
            )],
            'a signature with padding' => ['signature', fn(string $signature) => $signature . '=='],
            'a signature with a line feed' => ['signature', fn(string $signature) => $signature . "\n"],
            'a signature of 63 bytes' => ['signature', fn(string $signature) => base64url::encode(
                substr(base64url::decode($signature), 1)
            )],
            'a signature of 65 bytes' => ['signature', fn(string $signature) => base64url::encode(
                base64url::decode($signature) . "\0"
            )],
            'an empty signature' => ['signature', fn(string $signature) => ''],
            // Signed as they are, by libsodium: only the check of the form refuses them.
            'a run with capital letters' => ['run', fn(string $run) => 'AAAAAAAA' . substr($run, 8)],
            'a site with a line feed' => ['site', fn(string $site) => $site . "\n"],
            'a digest that is not canonical' => ['sha', fn(string $sha) => substr($sha, 0, 42) . 'F='],
            'a digest of 35 bytes' => ['sha', fn(string $sha) => base64_encode(str_repeat("\x01", 35))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    /**
     * A value that is not in its form does not verify, and is not a coding error: it comes from outside.
     *
     * Where the message changes, the signature is made by libsodium over the changed message, so that only the check of the form
     * can refuse it.
     *
     * @dataProvider malformed_provider
     * @param string $field The value that is changed: key, signature, site, run or sha.
     * @param \Closure $change Makes the changed value from the valid one.
     */
    public function test_a_malformed_value_does_not_verify(string $field, \Closure $change): void {
        $pair = sodium_crypto_sign_keypair();
        $values = ['key' => sodium_crypto_sign_publickey($pair), 'site' => self::SITE, 'run' => self::RUN, 'sha' => self::sha()];
        if ($field !== 'signature') {
            $values[$field] = $change($values[$field]);
        }
        $message = "SECBACKUP-MANIFEST-V1\n" . $values['site'] . "\n" . $values['run'] . "\n" . $values['sha'];
        $values['signature'] = base64url::encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair)));
        if ($field === 'signature') {
            $values['signature'] = $change($values['signature']);
        }
        $this->assertFalse(artifact_signature::verify_manifest(
            $values['key'],
            $values['site'],
            $values['run'],
            $values['sha'],
            $values['signature']
        ));
    }
}
