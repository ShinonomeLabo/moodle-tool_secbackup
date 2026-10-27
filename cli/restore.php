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
 * The restore on the command line, for the restore host: extracts a downloaded generation (--extract), or imports it into the
 * database (--import).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// The standard output is for the lines of the extraction: whatever PHP shows or something echoes, also while Moodle starts,
// goes to the error stream.
ini_set('display_errors', 'stderr');
ob_start(static function (string $text): string {
    fwrite(STDERR, $text);
    return '';
}, 1);
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'extract' => false, 'import' => false],
    ['h' => 'help']
);
if ($options['help']) {
    \tool_secbackup\local\cli\restore_command::help('cli_writeln');
    exit(0);
}
// Exactly one of the two options, and no other. The import is not wired yet: it is not run here.
if ($unrecognised || !$options['extract'] || $options['import']) {
    \tool_secbackup\local\cli\restore_command::help(static fn(string $line) => fwrite(STDERR, $line . "\n"));
    exit(1);
}
// The lines of the agreement go to the standard output: the extraction can take hours.
core_php_time_limit::raise();
exit((new \tool_secbackup\local\cli\restore_command(STDIN, STDOUT, STDERR))->extract());
