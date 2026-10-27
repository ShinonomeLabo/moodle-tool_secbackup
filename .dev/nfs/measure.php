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
 * Development only: measures the read speed of the file storage the way the watch of a backup run does.
 *
 * It calls the probe of the watch of the plugin (monitor::measure_nfs(), the value that the reason nfs_slow is judged by
 * until a run has read files of 1 MiB or more itself: 4 MiB from a random MiB of the largest stored file of 1 MiB or more,
 * timed from the opening of the file) the given number of times in one process, and prints one line "nfs_mbps <speed>" for
 * each, then the limit of the site ("limit_mbps <limit>") and the file that was read ("file <content hash> <size>"). A read
 * gets the file from the storage when nothing caches that part (the caller can drop the caches of the system before).
 *
 * Usage: php <plugin>/.dev/nfs/measure.php [--times=1]
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_secbackup\local\backup\limits;
use tool_secbackup\local\backup\monitor;

[$options, $unrecognised] = cli_get_params(['times' => 1, 'help' => false], ['h' => 'help']);
if ($unrecognised || $options['help'] || (int) $options['times'] < 1) {
    cli_writeln('Usage: php .dev/nfs/measure.php [--times=1]');
    exit($options['help'] ? 0 : 1);
}
$monitor = monitor::create();
for ($i = 0; $i < (int) $options['times']; $i++) {
    $speed = $monitor->measure_nfs();
    cli_writeln('nfs_mbps ' . ($speed === null ? 'unknown' : sprintf('%.1f', $speed)));
}
cli_writeln('limit_mbps ' . sprintf('%.1f', limits::current()->minnfsmbps));
$rows = $DB->get_records_sql(
    'SELECT id, contenthash, filesize FROM {files} WHERE filesize >= ? ORDER BY filesize DESC, id',
    [1048576],
    0,
    1
);
cli_writeln('file ' . ($rows ? reset($rows)->contenthash . ' ' . (int) reset($rows)->filesize : 'none'));
