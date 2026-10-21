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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\chunk_name::class)]
/**
 * Tests for the names of the chunks.
 *
 * The expected names are fixed: the storage keeps chunks under these names, so a change of a formula would make every
 * later backup send everything again.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\chunk_name
 */
final class chunk_name_test extends \basic_testcase {
    /** @var string SHA-1 of the empty file, a content hash of Moodle. */
    private const HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    /** @var string A content hash that PHP reads as the number 2. */
    private const NUMERIC_TWO = '0000000000000000000000000000000000000002';

    /** @var string A content hash that PHP reads as the number 1 (1e0), although it sorts after NUMERIC_TWO as text. */
    private const NUMERIC_ONE = '1e00000000000000000000000000000000000000';

    /** @var string UUID of a run. */
    private const RUN = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    /**
     * The key of the tests: the bytes 0 to 31.
     *
     * @return string
     */
    private static function key(): string {
        $key = '';
        for ($byte = 0; $byte < 32; $byte++) {
            $key .= chr($byte);
        }
        return $key;
    }

    /**
     * The name of a message, computed here with libsodium.
     *
     * @param string $message Message.
     * @return string
     */
    private static function expected(string $message): string {
        return bin2hex(sodium_crypto_generichash($message, self::key(), 32));
    }

    public function test_a_file_piece(): void {
        $name = chunk_name::file(self::key(), self::HASH, 0);
        $this->assertSame('64352c0b01c266215eaad9c428ce8ec46608b560077f446fbb3c2adf89fb7ec9', $name);
        $this->assertSame(self::expected('file|' . self::HASH . '|0'), $name);
        $this->assertSame(self::expected('file|' . self::HASH . '|7'), chunk_name::file(self::key(), self::HASH, 7));
    }

    public function test_a_pack_does_not_depend_on_the_order(): void {
        $hashes = [self::NUMERIC_ONE, self::HASH, self::NUMERIC_TWO];
        $sorted = [self::NUMERIC_TWO, self::NUMERIC_ONE, self::HASH];
        $expected = self::expected('pack|' . hash('sha256', implode(',', $sorted)));
        $this->assertSame('c03785a59677ca3d159a1884dce0d39bd294fe790f101fa95b1140425bbccff8', $expected);
        $this->assertSame($expected, chunk_name::pack(self::key(), $hashes));
        $this->assertSame($expected, chunk_name::pack(self::key(), array_reverse($hashes)));
        $keyed = ['x' => self::HASH, 'y' => self::NUMERIC_TWO, 3 => self::NUMERIC_ONE];
        $this->assertSame($expected, chunk_name::pack(self::key(), $keyed));
    }

    public function test_a_pack_of_one_file(): void {
        $this->assertSame(self::expected('pack|' . hash('sha256', self::HASH)), chunk_name::pack(self::key(), [self::HASH]));
    }

    public function test_a_database_block(): void {
        $sha = hash('sha256', 'block');
        $name = chunk_name::db(self::key(), self::RUN, 'user', 0, 4999, $sha);
        $this->assertSame('3624bae678a29de2d1a6044467453196a39301a7a8b8c46333896f4066dc275b', $name);
        $this->assertSame(self::expected('db|' . self::RUN . '|user|0-4999|' . $sha), $name);
        // A block of one row: the first and the last id are the same.
        $this->assertSame(
            self::expected('db|' . self::RUN . '|course_modules|12-12|' . $sha),
            chunk_name::db(self::key(), self::RUN, 'course_modules', 12, 12, $sha)
        );
    }

    public function test_a_series(): void {
        $name = chunk_name::series(self::key(), 'dataroot', self::RUN, 3);
        $this->assertSame('54afe8e0b5ad0879a4afff3f0a101674a00c7f58af265212e2edf4f27e0cfc87', $name);
        $this->assertSame(self::expected('dataroot|' . self::RUN . '|3'), $name);
        $code = chunk_name::series(self::key(), 'code', self::RUN, 0);
        $this->assertSame('c747e47bb496bea2804677b70df8502b5ca50d7735bddcba2c79733c6fb72a23', $code);
        $this->assertSame(self::expected('code|' . self::RUN . '|0'), $code);
    }

    public function test_the_manifest_is_named_without_the_key(): void {
        // BLAKE2b-256 of "manifest|" + run UUID without a key, computed with another implementation (hashlib.blake2b of
        // Python, digest_size=32): a restore has the site's secret key but not the key for names, and must open the manifest.
        $name = chunk_name::manifest(self::RUN);
        $this->assertSame('d427ee36fcbbd3ca43108a5718a9f5d6d16594bcbdd3eab60318adc8cc5b4bc9', $name);
        $this->assertSame(bin2hex(sodium_crypto_generichash('manifest|' . self::RUN, '', 32)), $name);
        $this->assertNotSame(self::expected('manifest|' . self::RUN), $name);
    }

    public function test_another_key_gives_another_name(): void {
        $other = str_repeat("\x01", 32);
        $this->assertSame(
            bin2hex(sodium_crypto_generichash('file|' . self::HASH . '|0', $other, 32)),
            chunk_name::file($other, self::HASH, 0)
        );
        $this->assertNotSame(chunk_name::file(self::key(), self::HASH, 0), chunk_name::file($other, self::HASH, 0));
    }

    /**
     * Calls that the class refuses as mistakes of the caller.
     *
     * @return array
     */
    public static function invalid_call_provider(): array {
        $key = str_repeat("\x02", 32);
        $sha = str_repeat('a', 64);
        $run = self::RUN;
        $hash = self::HASH;
        return [
            'short key' => [fn() => chunk_name::file(str_repeat("\x02", 31), $hash, 0)],
            'long key' => [fn() => chunk_name::file(str_repeat("\x02", 33), $hash, 0)],
            'upper-case content hash' => [fn() => chunk_name::file($key, strtoupper($hash), 0)],
            'short content hash' => [fn() => chunk_name::file($key, substr($hash, 1), 0)],
            'content hash after another character' => [fn() => chunk_name::file($key, 'x' . $hash, 0)],
            'content hash before another character' => [fn() => chunk_name::file($key, $hash . 'x', 0)],
            'content hash before a line feed' => [fn() => chunk_name::file($key, $hash . "\n", 0)],
            'negative piece' => [fn() => chunk_name::file($key, $hash, -1)],
            'empty pack' => [fn() => chunk_name::pack($key, [])],
            'pack with a hash that is not text' => [fn() => chunk_name::pack($key, [$hash, null])],
            'pack with a wrong hash' => [fn() => chunk_name::pack($key, [$hash, 'abc'])],
            'upper-case run' => [fn() => chunk_name::manifest(strtoupper($run))],
            'run without dashes' => [fn() => chunk_name::manifest(str_replace('-', '', $run))],
            'run before a line feed' => [fn() => chunk_name::manifest($run . "\n")],
            'run after another character' => [fn() => chunk_name::manifest('x' . $run)],
            'db with a wrong run' => [fn() => chunk_name::db($key, 'run', 'user', 0, 1, $sha)],
            'empty table' => [fn() => chunk_name::db($key, $run, '', 0, 1, $sha)],
            'table with a separator' => [fn() => chunk_name::db($key, $run, 'user|1-2', 0, 1, $sha)],
            'table with upper case' => [fn() => chunk_name::db($key, $run, 'User', 0, 1, $sha)],
            'table after another character' => [fn() => chunk_name::db($key, $run, '-user', 0, 1, $sha)],
            'table before another character' => [fn() => chunk_name::db($key, $run, 'user-', 0, 1, $sha)],
            'table before a line feed' => [fn() => chunk_name::db($key, $run, "user\n", 0, 1, $sha)],
            'negative first id' => [fn() => chunk_name::db($key, $run, 'user', -1, 1, $sha)],
            'last id before the first' => [fn() => chunk_name::db($key, $run, 'user', 5, 4, $sha)],
            'upper-case sha256' => [fn() => chunk_name::db($key, $run, 'user', 0, 1, strtoupper(hash('sha256', 'x')))],
            'short sha256' => [fn() => chunk_name::db($key, $run, 'user', 0, 1, substr($sha, 1))],
            'sha256 before a line feed' => [fn() => chunk_name::db($key, $run, 'user', 0, 1, $sha . "\n")],
            'unknown series' => [fn() => chunk_name::series($key, 'files', $run, 0)],
            'series in upper case' => [fn() => chunk_name::series($key, 'Code', $run, 0)],
            'series with a wrong run' => [fn() => chunk_name::series($key, 'code', 'run', 0)],
            'negative series number' => [fn() => chunk_name::series($key, 'dataroot', $run, -1)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_call_provider')]
    /**
     * A wrong argument is a mistake of the caller.
     *
     * @dataProvider invalid_call_provider
     * @param \Closure $call The call.
     */
    public function test_wrong_arguments_are_refused(\Closure $call): void {
        $this->expectException(\coding_exception::class);
        $call();
    }

    public function test_the_message_names_what_is_wrong(): void {
        try {
            chunk_name::file(self::key(), 'abc', 0);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString(': Not a valid content hash.', $e->getMessage());
        }
    }

    public function test_the_lowest_numbers_are_accepted(): void {
        $sha = str_repeat('a', 64);
        $expected = self::expected('db|' . self::RUN . '|a_1|0-0|' . $sha);
        $this->assertSame($expected, chunk_name::db(self::key(), self::RUN, 'a_1', 0, 0, $sha));
        $this->assertSame(self::expected('code|' . self::RUN . '|0'), chunk_name::series(self::key(), 'code', self::RUN, 0));
    }

    public function test_the_key_parameters_are_marked_sensitive(): void {
        foreach (['file', 'pack', 'db', 'series'] as $method) {
            $parameter = (new \ReflectionMethod(chunk_name::class, $method))->getParameters()[0];
            $this->assertSame('key', $parameter->getName(), $method);
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), $method);
        }
        // The manifest has no key: its only parameter is the run.
        $parameters = (new \ReflectionMethod(chunk_name::class, 'manifest'))->getParameters();
        $this->assertSame(['runuuid'], array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters));
    }
}
