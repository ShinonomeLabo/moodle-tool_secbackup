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
 * URL-safe base64 without padding (RFC 4648 section 5), as used in the service's headers and envelopes.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class base64url {
    /**
     * Encodes binary data.
     *
     * @param string $data Binary data.
     * @return string Encoded text.
     */
    public static function encode(string $data): string {
        return sodium_bin2base64($data, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Decodes text, or returns null when it is not URL-safe base64 without padding.
     *
     * @param string $text Encoded text.
     * @return string|null Binary data.
     */
    public static function decode(string $text): ?string {
        // Use \z rather than $, which lets a final line break through. Libsodium refuses a length that cannot occur
        // and a last character with bits set that the encoder never writes.
        if (preg_match('/^[A-Za-z0-9_-]+\z/', $text) !== 1) {
            return null;
        }
        try {
            return sodium_base642bin($text, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            return null;
        }
    }
}
