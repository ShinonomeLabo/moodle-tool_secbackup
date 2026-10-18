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
 * Padmé, the padding of lengths of Nikitin et al., "Reducing Metadata Leakage from Encrypted Files and Communication
 * with PURBs", Proceedings on Privacy Enhancing Technologies 2019(4).
 *
 * A length L is rounded up so that only its highest floor(log2 E) + 1 bits can be set, where E = floor(log2 L): the
 * padded length tells about O(log log L) bits of L, and the padding is less than 12% of L (at most 11.6%, for L = 129, and
 * less for longer lengths).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class padme {
    /**
     * The padded length.
     *
     * E = floor(log2 L), S = floor(log2 E) + 1, and the lowest E - S bits are rounded up to zero.
     *
     * @param int $length Length in bytes. A length below 2 is returned as it is.
     * @return int At least $length.
     */
    public static function length(int $length): int {
        if ($length < 2) {
            return $length;
        }
        // The number of binary digits of a positive integer is its floor(log2) + 1.
        $e = strlen(decbin($length)) - 1;
        $s = strlen(decbin($e));
        $mask = (1 << ($e - $s)) - 1;
        return ($length + $mask) & ~$mask;
    }
}
