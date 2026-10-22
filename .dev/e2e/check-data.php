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
 * Development only: checks the test data of a site of the staging end-to-end test before the site is backed up.
 *
 * Each check prints one line, "ok" or "FAIL":
 *  - the files of the site (every file in the folder filedir, which holds one file per content) add up to --files-gb within
 *    5 % (a GB is 10^9 bytes, as for generate_site_data.php --files-gb), and the largest of them is larger than 4 MiB (the
 *    backup cuts such files into parts, and the test has to go through that);
 *  - each extra plugin of plugins.json (put by install-plugins.sh) is installed and up to date, and its main tables have rows
 *    (generate_site_data.php --plugin-data writes them). --skip leaves out the plugins that install-plugins.sh was told to
 *    skip; every other plugin of the list has to be there, so that a site without the extra plugins cannot pass.
 * It ends with 0 when every check passes, and with 1 otherwise.
 *
 * Usage: php <plugin>/.dev/e2e/check-data.php --files-gb=<number> [--skip=<component>[,<component>...]]
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// The same relative path reaches config.php on 4.5 (admin/tool/...) and on 5.1+ (public/admin/tool/...).
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['files-gb' => null, 'skip' => '', 'help' => false], ['h' => 'help']);
if ($unrecognised || $options['help'] || !is_numeric($options['files-gb']) || (float) $options['files-gb'] <= 0) {
    cli_writeln('Usage: php .dev/e2e/check-data.php --files-gb=<number> [--skip=<component>[,<component>...]]');
    exit($options['help'] ? 0 : 1);
}

// The main tables of each extra plugin, with a condition on their rows: at least one row has to match it.
$tables = [
    'mod_attendance' => [
        'attendance' => '',
        'attendance_sessions' => '',
        'attendance_log' => '',
    ],
    'block_autoattend' => [
        'block_instances' => "blockname = 'autoattend'",
        'autoattend_sessions' => '',
        'autoattend_students' => '',
    ],
    'mod_autoattendmod' => [
        'autoattendmod' => '',
    ],
    'block_sharing_cart' => [
        'block_instances' => "blockname = 'sharing_cart'",
        'block_sharing_cart_items' => 'file_id IS NOT NULL',
    ],
    'mod_videoassessment' => [
        'videoassessment' => '',
        'videoassessment_videos' => '',
        'files' => "component = 'mod_videoassessment' AND filearea = 'video' AND filename <> '.'",
    ],
    'mod_questionnaire' => [
        'questionnaire' => '',
        'questionnaire_question' => '',
        'questionnaire_response' => '',
    ],
    'block_xp' => [
        'block_instances' => "blockname = 'xp'",
        'block_xp' => '',
        'block_xp_config' => '',
    ],
];

/**
 * Prints the line of one check and returns whether it passed.
 *
 * @param bool $ok Whether the check passed.
 * @param string $text What was checked, with the values found.
 * @return bool
 */
function secbk_e2e_check(bool $ok, string $text): bool {
    cli_writeln(($ok ? 'ok    ' : 'FAIL  ') . $text);
    return $ok;
}

/**
 * Returns the number of bytes, the number of files and the size of the largest file in a folder and its subfolders.
 * generate_site_data.php counts the folder filedir the same way.
 *
 * @param string $dir The folder.
 * @return int[] Bytes, files, largest.
 */
function secbk_e2e_filedir(string $dir): array {
    $bytes = 0;
    $count = 0;
    $largest = 0;
    if (!is_dir($dir)) {
        return [0, 0, 0];
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $size = $file->getSize();
            $bytes += $size;
            $count++;
            $largest = max($largest, $size);
        }
    }
    return [$bytes, $count, $largest];
}

$rows = json_decode((string) file_get_contents(__DIR__ . '/plugins.json'), true);
if (!is_array($rows) || !$rows) {
    cli_error('plugins.json cannot be read.');
}
$components = array_values(array_unique(array_column($rows, 'component')));
$skip = array_values(array_filter(array_map('trim', explode(',', (string) $options['skip']))));
foreach ($skip as $component) {
    if (!in_array($component, $components, true)) {
        cli_error("--skip names $component, which plugins.json does not list.");
    }
}

$passed = true;

[$bytes, $count, $largest] = secbk_e2e_filedir($CFG->filedir ?? $CFG->dataroot . '/filedir');
$wanted = (int) round((float) $options['files-gb'] * 1e9);
$passed = secbk_e2e_check(abs($bytes - $wanted) <= $wanted * 0.05,
    sprintf('filedir: %d bytes in %d files (wanted %d bytes +- 5 %%)', $bytes, $count, $wanted)) && $passed;
$passed = secbk_e2e_check($largest > 4 * 1048576,
    sprintf('filedir: the largest file has %d bytes (wanted more than 4 MiB)', $largest)) && $passed;

$manager = core_plugin_manager::instance();
foreach ($components as $component) {
    if (in_array($component, $skip, true)) {
        cli_writeln("skip  $component");
        continue;
    }
    if (!isset($tables[$component])) {
        // A plugin added to plugins.json needs its tables here too, or it would never be checked.
        $passed = secbk_e2e_check(false, "$component: this script has no tables to check for it") && $passed;
        continue;
    }
    $info = $manager->get_plugin_info($component);
    $status = $info ? $info->get_status() : 'not in the code of the site';
    if (!secbk_e2e_check($status === core_plugin_manager::PLUGIN_STATUS_UPTODATE, "$component: installed ($status)")) {
        $passed = false;
        continue;
    }
    foreach ($tables[$component] as $table => $select) {
        $rowcount = $DB->count_records_select($table, $select);
        $where = $select === '' ? '' : " where $select";
        $passed = secbk_e2e_check($rowcount > 0, "$component: $rowcount rows in $table$where") && $passed;
    }
}
exit($passed ? 0 : 1);
