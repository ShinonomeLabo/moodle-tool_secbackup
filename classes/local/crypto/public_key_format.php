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
 * The site's encryption public key as the platform shows it: "secbk1:" followed by base64 of 32 bytes.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class public_key_format {
    /** @var string Prefix of the encoded key. */
    public const PREFIX = 'secbk1:';

    /**
     * Reads a pasted key. Whitespace anywhere (including full-width spaces) and the prefix case are ignored.
     *
     * @param string $input Pasted text.
     * @return string The raw 32-byte key.
     * @throws key_exception When the text is not a key.
     */
    public static function parse(string $input): string {
        $compact = (string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', $input);
        if (strncasecmp($compact, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            throw new key_exception('publickey_prefix');
        }
        $raw = base64_decode(substr($compact, strlen(self::PREFIX)), true);
        if ($raw === false) {
            throw new key_exception('publickey_base64');
        }
        if (strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new key_exception('publickey_length');
        }
        return $raw;
    }

    /**
     * Encodes a raw key the way the platform shows it.
     *
     * @param string $rawkey The raw 32-byte key.
     * @return string
     */
    public static function encode(string $rawkey): string {
        if (strlen($rawkey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \coding_exception('A public key has 32 bytes.');
        }
        return self::PREFIX . base64_encode($rawkey);
    }
}
