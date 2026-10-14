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

namespace tool_secbackup\local\enrollment;

/**
 * Reads a typed registration code with the same rules as the service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrollment_code {
    /** @var string Crockford base32 alphabet. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * The code in its normal form, SEC-BK-XXXX-XXXX-XXXX-XXXX-XXXX.
     *
     * Full-width letters, digits and spaces become half-width; O becomes 0, I and L become 1;
     * spaces and hyphens are removed. Invalid UTF-8 and control characters other than white space give null.
     * The SECBK prefix is removed only when 25 characters remain.
     *
     * @param #[|\SensitiveParameter|]|string $input Typed text.
     * @return string|null Null when the text cannot be a code.
     */
    public static function normalize(#[\SensitiveParameter] string $input): ?string {
        // Before any conversion: PHP 8.1 mb_convert_kana drops a trailing NUL, and mbstring can be set to drop bad bytes.
        if (!mb_check_encoding($input, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F\x7F]/', $input) === 1) {
            return null;
        }
        $value = strtoupper((string) preg_replace('/[\s\-]+/u', '', mb_convert_kana($input, 'as', 'UTF-8')));
        $value = strtr($value, ['O' => '0', 'I' => '1', 'L' => '1']);
        if ($value === '' || strspn($value, self::ALPHABET) !== strlen($value)) {
            return null;
        }
        if (strlen($value) === 25 && str_starts_with($value, 'SECBK')) {
            $value = substr($value, 5);
        }
        return strlen($value) === 20 ? 'SEC-BK-' . implode('-', str_split($value, 4)) : null;
    }
}
