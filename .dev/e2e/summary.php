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
 * Development only: the summary of one run of the staging end-to-end test, as Markdown.
 *
 * Usage: php .dev/e2e/summary.php <folder> --limit-gb=<GB>
 *
 * Every folder under <folder> that holds a result.json or an inspect.json is one site: the files that run.sh leaves for it
 * (the workflow downloads them, one folder for each job). The amount sent is the sum of objects.bytes of the inspections:
 * what the service holds of each site at the end. A GB is 10^9 bytes.
 *
 * It fails (exit 1) when there is no result at all, when the amount sent is over the limit, when a result did not pass or is
 * missing, and when the inspection of a site is missing, failed (an error such as lock_read_failed or too_many_objects), or
 * shows an object that is not locked in COMPLIANCE mode (compliance false, or missing, expired or gone keys). The scenario
 * data-only, which does not reach the service, has no inspection. Exit 2: wrong usage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Only the command line runs it: a site installed with git clone has this directory under its web root.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$folder = $argv[1] ?? '';
$limit = null;
$limittext = '';
if (count($argv) === 3 && preg_match('/^--limit-gb=([0-9]+(\.[0-9]+)?)\z/', $argv[2], $m) === 1) {
    $limittext = $m[1];
    $limit = (float) $limittext;
}
if ($folder === '' || !is_dir($folder) || $limit === null) {
    fwrite(STDERR, "Usage: php summary.php <folder> --limit-gb=<GB>\n");
    exit(2);
}

/**
 * Reads a JSON file.
 *
 * @param string $file The file.
 * @return array|null Null when the file is not a JSON object.
 */
function secbk_e2e_read(string $file): ?array {
    $text = @file_get_contents($file);
    $data = is_string($text) ? json_decode($text, true) : null;
    return is_array($data) ? $data : null;
}

/**
 * Makes a value safe for a cell of a Markdown table.
 *
 * @param mixed $value The value.
 * @return string
 */
function secbk_e2e_cell(mixed $value): string {
    $text = is_scalar($value) ? (string) $value : '';
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $text);
}

/**
 * Bytes as GB of 10^9 bytes.
 *
 * @param int $bytes The bytes.
 * @return string
 */
function secbk_e2e_gb(int $bytes): string {
    return sprintf('%.3f', $bytes / 1e9);
}

// The folders that hold a result or an inspection.
$sites = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile() && in_array($file->getFilename(), ['result.json', 'inspect.json'], true)) {
        $sites[$file->getPath()] = true;
    }
}
$sites = array_keys($sites);
sort($sites);

$rows = [];
$failures = [];
$total = 0;
foreach ($sites as $site) {
    $result = is_file("$site/result.json") ? secbk_e2e_read("$site/result.json") : null;
    $inspection = is_file("$site/inspect.json") ? secbk_e2e_read("$site/inspect.json") : null;
    $name = is_string($result['name'] ?? null) ? $result['name'] : basename($site);
    $scenario = is_string($result['scenario'] ?? null) ? $result['scenario'] : '?';
    $problems = [];
    if (!is_file("$site/result.json")) {
        $problems[] = 'no result.json';
    } else if ($result === null) {
        $problems[] = 'result.json cannot be read';
    } else if (($result['passed'] ?? null) !== true) {
        $reasons = array_filter(is_array($result['reasons'] ?? null) ? $result['reasons'] : [], 'is_string');
        $problems[] = 'did not pass' . ($reasons ? ': ' . implode('; ', $reasons) : '');
    }

    $sent = 0;
    $runs = '';
    $lock = '';
    $classes = '';
    if (!is_file("$site/inspect.json")) {
        if ($scenario !== 'data-only') {
            $problems[] = 'no inspect.json';
        }
    } else if ($inspection === null) {
        $problems[] = 'inspect.json cannot be read';
    } else if (isset($inspection['error'])) {
        $problems[] = 'the inspection failed (' . secbk_e2e_cell($inspection['error']) . ')';
    } else {
        $objects = is_array($inspection['objects'] ?? null) ? $inspection['objects'] : [];
        $sent = is_int($objects['bytes'] ?? null) ? $objects['bytes'] : 0;
        $states = [];
        foreach (is_array($inspection['runs'] ?? null) ? $inspection['runs'] : [] as $run) {
            $code = is_string($run['failure_code'] ?? null) ? " ({$run['failure_code']})" : '';
            $state = (string) ($run['status'] ?? '?') . $code;
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        $runs = implode(', ', array_map(static fn($state, $n) => "$state $n", array_keys($states), $states));
        $locked = is_array($objects['lock'] ?? null) ? $objects['lock'] : [];
        $missing = (int) ($locked['missing_keys'] ?? 0);
        $expired = (int) ($locked['expired_keys'] ?? 0);
        $gone = (int) ($locked['gone_keys'] ?? 0);
        $counts = "missing $missing, expired $expired, gone $gone";
        if (($locked['compliance'] ?? false) !== true || $missing + $expired + $gone > 0) {
            $lock = "not all locked ($counts)";
            $problems[] = "objects not all locked in COMPLIANCE mode ($counts)";
        } else {
            $lock = 'COMPLIANCE' . (is_string($locked['min_retain_until'] ?? null) ? ' until ' . $locked['min_retain_until'] : '');
        }
        $byclass = is_array($objects['storage_classes'] ?? null) ? $objects['storage_classes'] : [];
        $classes = implode(', ', array_map(static fn($class, $n) => "$class $n", array_keys($byclass), $byclass));
        $runs .= ' / ' . (int) ($objects['count'] ?? 0) . ' objects';
    }
    $total += $sent;
    $retries = is_array($result['retries'] ?? null) ? $result['retries'] : [];
    $minutes = is_numeric($result['durations']['total'] ?? null) ? (int) round($result['durations']['total'] / 60) : '';
    $rows[] = [$scenario, $name, $problems ? 'FAILED' : 'passed', secbk_e2e_gb($sent), $runs, $lock, $classes,
        (int) ($retries['waits'] ?? 0) . ' / ' . (int) ($retries['failed_steps'] ?? 0), $minutes, implode('; ', $problems)];
    foreach ($problems as $problem) {
        $failures[] = "$scenario $name: $problem";
    }
}

$limitbytes = (int) round($limit * 1e9);
if (!$sites) {
    $failures[] = 'no result was found: no job left its files';
}
if ($total > $limitbytes) {
    $failures[] = sprintf('the amount sent, %s GB (%d bytes), is over the limit of %s GB', secbk_e2e_gb($total), $total,
        $limittext);
}

echo "## Staging end-to-end test\n\n";
echo "| Scenario | Site | Result | Sent (GB) | Runs / objects | Lock | Storage classes | Waits / failed steps | Minutes |",
    " Problems |\n";
echo "|---|---|---|---:|---|---|---|---:|---:|---|\n";
foreach ($rows as $row) {
    echo '| ', implode(' | ', array_map('secbk_e2e_cell', $row)), " |\n";
}
printf("\nSent in total: %s GB (%d bytes) of the limit of %s GB.\n\n", secbk_e2e_gb($total), $total, $limittext);
if ($failures) {
    echo "**Failed:**\n\n";
    foreach ($failures as $failure) {
        echo '- ', secbk_e2e_cell($failure), "\n";
    }
    exit(1);
}
echo "**Passed.**\n";
exit(0);
