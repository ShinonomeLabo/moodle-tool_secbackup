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

/**
 * The names of the chunks at the storage: keyed BLAKE2b-256 of a text that says what the chunk holds, as 64 lower-case
 * hexadecimal digits.
 *
 * The key is the site's key for names (dedup_key). Without it, the storage cannot tell what a chunk holds, for example
 * whether the site has a well-known file; it can only tell whether two chunks have the same name. The texts are:
 *
 * - a piece of a file: "file|" + content hash + "|" + number of the piece (from 0);
 * - a chunk of small files: "pack|" + SHA-256 in hexadecimal of the content hashes in ascending order, joined by commas;
 * - a block of a table: "db|" + run UUID + "|" + table + "|" + first id + "-" + last id + "|" + SHA-256 of the block;
 * - a chunk of the rest of the data root, or of code: "dataroot|" or "code|", then run UUID + "|" + number (from 0);
 * - the manifest of a run: "manifest|" + run UUID.
 *
 * The storage keeps chunks under these names, so the texts must not change.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunk_name {
    /** @var string Pattern of a content hash of Moodle (SHA-1). It ends with \z: $ also matches before a final line feed. */
    private const CONTENTHASH = '/^[0-9a-f]{40}\z/';

    /** @var string Pattern of a SHA-256 in hexadecimal. */
    private const SHA256 = '/^[0-9a-f]{64}\z/';

    /** @var string Pattern of a run UUID, as the backup service writes it. */
    private const UUID = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

    /** @var string Pattern of a table name without the prefix, as XMLDB allows it. */
    private const TABLE = '/^[a-z0-9_]+\z/';

    /** @var string[] Series of chunks that are numbered within a run. */
    private const SERIES = ['dataroot', 'code'];

    /**
     * The name of a piece of a file.
     *
     * The type of $key is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()); it is plain "string" for PHP. The same holds for the other methods.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string $contenthash Content hash of the file.
     * @param int $piece Number of the piece, from 0.
     * @return string
     * @throws \coding_exception For a wrong argument.
     */
    public static function file(#[\SensitiveParameter] string $key, string $contenthash, int $piece): string {
        self::check(self::CONTENTHASH, $contenthash, 'content hash');
        self::check_number($piece);
        return self::name($key, 'file|' . $contenthash . '|' . $piece);
    }

    /**
     * The name of a chunk of small files. The order of the content hashes does not matter.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string[] $contenthashes Content hashes of the files in the chunk, at least one.
     * @return string
     * @throws \coding_exception For a wrong argument.
     */
    public static function pack(#[\SensitiveParameter] string $key, array $contenthashes): string {
        if ($contenthashes === []) {
            throw new \coding_exception('A chunk of files holds at least one file.');
        }
        foreach ($contenthashes as $contenthash) {
            if (!is_string($contenthash)) {
                throw new \coding_exception('A content hash is text.');
            }
            self::check(self::CONTENTHASH, $contenthash, 'content hash');
        }
        // As text: PHP would compare two content hashes that look like numbers (such as 1e00...) as numbers.
        sort($contenthashes, SORT_STRING);
        return self::name($key, 'pack|' . hash('sha256', implode(',', $contenthashes)));
    }

    /**
     * The name of a block of a table.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string $runuuid UUID of the run.
     * @param string $table Table name without the prefix.
     * @param int $from First id in the block.
     * @param int $to Last id in the block, at least $from.
     * @param string $sha256hex SHA-256 of the block, in hexadecimal.
     * @return string
     * @throws \coding_exception For a wrong argument.
     */
    public static function db(
        #[\SensitiveParameter] string $key,
        string $runuuid,
        string $table,
        int $from,
        int $to,
        string $sha256hex
    ): string {
        self::check(self::UUID, $runuuid, 'run UUID');
        self::check(self::TABLE, $table, 'table name');
        self::check_number($from);
        if ($to < $from) {
            throw new \coding_exception('The last id of a block is not before its first id.');
        }
        self::check(self::SHA256, $sha256hex, 'SHA-256');
        return self::name($key, 'db|' . $runuuid . '|' . $table . '|' . $from . '-' . $to . '|' . $sha256hex);
    }

    /**
     * The name of a numbered chunk of the rest of the data root or of code.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string $label "dataroot" or "code".
     * @param string $runuuid UUID of the run.
     * @param int $n Number of the chunk in the run, from 0.
     * @return string
     * @throws \coding_exception For a wrong argument.
     */
    public static function series(#[\SensitiveParameter] string $key, string $label, string $runuuid, int $n): string {
        if (!in_array($label, self::SERIES, true)) {
            throw new \coding_exception('A series is "dataroot" or "code".');
        }
        self::check(self::UUID, $runuuid, 'run UUID');
        self::check_number($n);
        return self::name($key, $label . '|' . $runuuid . '|' . $n);
    }

    /**
     * The name of the manifest of a run.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string $runuuid UUID of the run.
     * @return string
     * @throws \coding_exception For a wrong argument.
     */
    public static function manifest(#[\SensitiveParameter] string $key, string $runuuid): string {
        self::check(self::UUID, $runuuid, 'run UUID');
        return self::name($key, 'manifest|' . $runuuid);
    }

    /**
     * Keyed BLAKE2b-256 of a text, in hexadecimal.
     *
     * @param #[|\SensitiveParameter|]|string $key The key for names (32 bytes).
     * @param string $message The text.
     * @return string
     * @throws \coding_exception When the key does not have 32 bytes.
     */
    private static function name(#[\SensitiveParameter] string $key, string $message): string {
        if (strlen($key) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES) {
            throw new \coding_exception('The key for names has 32 bytes.');
        }
        return bin2hex(sodium_crypto_generichash($message, $key, SODIUM_CRYPTO_GENERICHASH_BYTES));
    }

    /**
     * Checks a value against a pattern.
     *
     * @param string $pattern The pattern.
     * @param string $value The value.
     * @param string $what What the value is, for the message.
     * @throws \coding_exception When the value does not match.
     */
    private static function check(string $pattern, string $value, string $what): void {
        if (preg_match($pattern, $value) !== 1) {
            throw new \coding_exception('Not a valid ' . $what . '.');
        }
    }

    /**
     * Checks that a number is not negative.
     *
     * @param int $number The number.
     * @throws \coding_exception When it is negative.
     */
    private static function check_number(int $number): void {
        if ($number < 0) {
            throw new \coding_exception('The number is not negative.');
        }
    }
}
