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

/**
 * Coverage configuration: every shipped PHP file is in the denominator.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the declaration folders and files to the default folders and files of the coverage report.
 */
return new class extends phpunit_coverage_info {
    /** @var array Folders to include. */
    protected $includelistfolders = ['classes', 'cli', 'db', 'lang'];

    /** @var array Files to include. */
    protected $includelistfiles = ['version.php', 'settings.php', 'index.php', 'connect.php', 'diagnostics.php',
        'restore_mtrace.php'];
};
