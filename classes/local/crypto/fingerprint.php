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
 * Key fingerprints, computed exactly as the service computes them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fingerprint {
    /** @var string Domain separation prefix shared with the service. */
    private const DOMAIN = 'secbackup:fp:v1|';

    /**
     * Fingerprint of a raw key: 24 upper-case hexadecimal characters.
     *
     * @param string $rawkey Raw key bytes.
     * @return string
     */
    public static function of(string $rawkey): string {
        return strtoupper(substr(hash('sha256', self::DOMAIN . $rawkey), 0, 24));
    }

    /**
     * Groups a fingerprint by four characters for display.
     *
     * @param string $fingerprint 24 hexadecimal characters.
     * @return string
     */
    public static function format(string $fingerprint): string {
        return implode(' ', str_split($fingerprint, 4));
    }

    /**
     * Normalises typed or pasted text, or returns null when it is not a fingerprint.
     *
     * @param string $input Text.
     * @return string|null
     */
    public static function normalize(string $input): ?string {
        $value = strtoupper((string) preg_replace('/\s+/', '', $input));
        return preg_match('/^[0-9A-F]{24}$/', $value) === 1 ? $value : null;
    }
}
