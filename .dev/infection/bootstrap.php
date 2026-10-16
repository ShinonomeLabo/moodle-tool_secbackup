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
 * Bootstrap of Infection: loads the development site as a command line script, for the classes of Moodle.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development only; not part of the plugin package.
//
// Infection reads the classes of the source folders with reflection in its own process, which does not load Moodle. The
// classes of this plugin extend those of Moodle (moodle_exception, ...), and Moodle finds all of its classes by its own
// autoloader, so this process loads the development site's config.php as a command line script. The development site is
// only read; the tests run in the PHPUnit site, in processes of their own.

define('CLI_SCRIPT', true);
$cwd = getcwd();
$root = __DIR__;
while (!is_file($root . '/config.php')) {
    $parent = dirname($root);
    if ($parent === $root) {
        throw new RuntimeException('config.php of Moodle not found above ' . __DIR__);
    }
    $root = $parent;
}
require($root . '/config.php');
// Moodle changes the working directory; the paths of the Infection configuration are relative to the plugin.
chdir($cwd);
