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

namespace tool_secbackup\local\diagnostics;

/**
 * The outcome of one check. Texts are plain (not escaped).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class check_result {
    /** @var string Nothing to fix. */
    public const PASS = 'pass';

    /** @var string Works, but needs attention. */
    public const WARN = 'warn';

    /** @var string Must be fixed. */
    public const FAIL = 'fail';

    /** @var string Information only. */
    public const INFO = 'info';

    /** @var string Could not be found out. */
    public const UNKNOWN = 'unknown';

    /** @var string Name of the check (check_<name> language string). */
    public readonly string $check;

    /** @var string One of the constants. */
    public readonly string $result;

    /** @var string What was found. */
    public readonly string $detail;

    /** @var string What to do, or empty. */
    public readonly string $advice;

    /**
     * Constructor.
     *
     * @param string $check Name of the check.
     * @param string $result One of the constants.
     * @param string $detail What was found.
     * @param string $advice What to do.
     */
    public function __construct(string $check, string $result, string $detail, string $advice = '') {
        $this->check = $check;
        $this->result = $result;
        $this->detail = $detail;
        $this->advice = $advice;
    }
}
