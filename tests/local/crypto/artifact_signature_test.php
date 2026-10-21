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
}
