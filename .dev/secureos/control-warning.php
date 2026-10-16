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
 * Raises one PHP warning from inside the directory of the plugin: the positive control of the count of check.sh.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development only (test VMs): raises one PHP warning from inside the plugin's directory, as a positive control for the
// count of PHP warnings of check.sh. Included by probe.php when it is asked for it. Not part of the plugin package.
// Like probe.php it does nothing unless /etc/secbk-secureos-probe exists (made by provision.sh).

if (!is_file('/etc/secbk-secureos-probe')) {
    return;
}

trigger_error('secbk positive control', E_USER_WARNING);
