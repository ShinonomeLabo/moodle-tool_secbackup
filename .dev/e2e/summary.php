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
 * The amount of a site whose inspection is missing, failed or not of its form is not known: its cell says unknown, the site
 * has that problem, the line of the total says for how many sites, and the limit is judged on the amounts that are known.
 *
 * For each site the table shows the Moodle it was built with and whether its run had the official list of the files of that
 * build at the service (moodle of result.json), the waits of its runs that no failed step comes before, but in the stage of
 * the receipt (the requests that the service answered as busy, and the sends to S3 that are tried again), and its failed
 * steps (retries), and its minutes. For the scenario concurrent it says how long the runs of the ten sites ran together,
 * from the latest start to the earliest end of the last run of each site by the times of the service (started_at, and
 * completed_at or failed_at; created_at and updated_at stand in for them when the service did not give them, which is a
 * problem of the site, since updated_at moves on with writes after the end), and how late the latest site started
 * (numbers.start_late).
 *
 * It fails (exit 1) when there is no result at all, when the amount sent is over the limit, when a result did not pass or is
 * missing, when the inspection of a site is missing, failed (an error such as lock_read_failed or too_many_objects), or
 * shows an object that is not locked in COMPLIANCE mode (compliance false, or missing, expired or gone keys), when the amount
 * of a site is not known, and when the runs of concurrent never ran together or one has no times of its own. The scenario
 * data-only, which does not reach the service, has no inspection. Notes, which do not fail it: the sites that sent the code
 * of Moodle because the service has no official list of its build, and the sites of concurrent that started late.
 * Exit 2: wrong usage.
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
 * A time of the service as a UNIX time, or null.
 *
 * @param mixed $value The time, such as 2026-10-23T01:30:00+00:00.
 * @return int|null
 */
function secbk_e2e_time(mixed $value): ?int {
    if (!is_string($value) || $value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
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
$notes = [];
// The sites that sent the code of Moodle, by the build that the service has no official list of.
$codesent = [];
// The sites of concurrent: how many, the start and the end of their last runs, and how late each started.
$concurrent = 0;
$together = [];
$lates = [];
$total = 0;
// Sites whose amount is not known: their inspection is missing or failed.
$unknown = 0;
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

    // Null while the amount is not known. data-only does not reach the service: it sends nothing.
    $sent = $scenario === 'data-only' ? 0 : null;
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
        $sent = is_int($objects['bytes'] ?? null) ? $objects['bytes'] : null;
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
    if ($sent === null) {
        $unknown++;
        $problems[] = 'the amount that the service holds is not known';
    } else {
        $total += $sent;
    }

    $moodle = is_array($result['moodle'] ?? null) ? $result['moodle'] : [];
    $core = $moodle['core_manifest'] ?? null;
    // Empty for a result without the record (written before the Moodle was recorded).
    $version = is_string($moodle['release'] ?? null) ? $moodle['release'] : '';
    if ($version !== '') {
        $version .= $core === true ? ', list used' : ($core === false ? ', code sent' : '');
    }
    if ($core === false) {
        $build = is_string($moodle['build'] ?? null) && $moodle['build'] !== '' ? $moodle['build'] : 'unknown';
        $codesent[$build][] = "$scenario $name";
    }

    if ($scenario === 'concurrent') {
        $concurrent++;
        $late = $result['numbers']['start_late'] ?? null;
        if (is_int($late)) {
            $lates[] = $late;
            if ($late > 0) {
                $notes[] = "concurrent $name: started $late s after the start of the ten sites";
            }
        }
        // The last run of the site: from its start to its completion or its failure.
        $runlist = is_array($inspection['runs'] ?? null) && !isset($inspection['error']) ? $inspection['runs'] : [];
        $last = $runlist ? end($runlist) : null;
        if (is_array($last)) {
            $from = secbk_e2e_time($last['started_at'] ?? null);
            $to = secbk_e2e_time($last['completed_at'] ?? null) ?? secbk_e2e_time($last['failed_at'] ?? null);
            if ($from === null && ($from = secbk_e2e_time($last['created_at'] ?? null)) !== null) {
                $problems[] = 'the service gave no started_at of its last run: created_at is used instead';
            }
            if ($to === null && ($to = secbk_e2e_time($last['updated_at'] ?? null)) !== null) {
                $problems[] = 'the service gave neither completed_at nor failed_at of its last run: updated_at is used instead,'
                    . ' which later writes move on';
            }
            if ($from === null || $to === null) {
                $problems[] = 'the service gave no time of the start or of the end of its last run, so the time that the ten'
                    . ' ran together is not known';
            } else {
                $together[] = [$from, $to];
            }
        }
    }

    $retries = is_array($result['retries'] ?? null) ? $result['retries'] : [];
    $minutes = is_numeric($result['durations']['total'] ?? null) ? (int) round($result['durations']['total'] / 60) : '';
    $rows[] = [$scenario, $name, $problems ? 'FAILED' : 'passed', $sent === null ? 'unknown' : secbk_e2e_gb($sent), $runs, $lock,
        $classes, $version, (int) ($retries['waits_without_failure'] ?? 0), (int) ($retries['failed_steps'] ?? 0), $minutes,
        implode('; ', $problems)];
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
// The runs of concurrent ran together from the latest start to the earliest end.
$lines = [];
if ($together) {
    $start = max(array_column($together, 0));
    $end = min(array_column($together, 1));
    $sites = count($together) === $concurrent ? sprintf('the %d %s', $concurrent, $concurrent === 1 ? 'site' : 'sites')
        : sprintf('%d of the %d sites', count($together), $concurrent);
    if ($end > $start) {
        $lines[] = sprintf('Concurrent: the runs of %s ran together for %d s (%s to %s UTC); %s.', $sites, $end - $start,
            gmdate('H:i:s', $start), gmdate('H:i:s', $end), $lates
                ? sprintf('the latest site started %d s after the start of the ten sites', max($lates))
                : 'the latest start of a site is not known');
    } else {
        $failures[] = sprintf('the runs of %s of concurrent never ran together: the latest started at %s, after the earliest'
            . ' ended at %s (UTC)', $sites, gmdate('H:i:s', $start), gmdate('H:i:s', $end));
    }
}
ksort($codesent);
foreach ($codesent as $build => $names) {
    $notes[] = sprintf('sent the code of Moodle (%s): the service has no official list of the files of Build %s'
        . ' (.dev/e2e/README.md)', implode(', ', $names), $build);
}

echo "## Staging end-to-end test\n\n";
echo "| Scenario | Site | Result | Sent (GB) | Runs / objects | Lock | Storage classes | Moodle | Waits without a failed step |",
    " Failed steps | Minutes | Problems |\n";
echo "|---|---|---|---:|---|---|---|---|---:|---:|---:|---|\n";
foreach ($rows as $row) {
    echo '| ', implode(' | ', array_map('secbk_e2e_cell', $row)), " |\n";
}
echo "\nWaits without a failed step: the waits of the runs that no failed step comes before, but in the stage of the receipt.",
    " They count the requests that the service answered as busy (429, 503), which the plugin waits for without a failed step,",
    " and the sends to S3 that are tried again; the output of cli/backup.php does not tell them apart. The wait after a failed",
    " step is not among them.\n";
// The limit is judged on the amounts that are known; a site whose amount is not known has failed already.
printf("\nSent in total: %s GB (%d bytes) of the limit of %s GB%s.\n\n", secbk_e2e_gb($total), $total, $limittext,
    $unknown > 0 ? sprintf('; not known for %d %s whose inspection is missing or failed', $unknown,
        $unknown === 1 ? 'site' : 'sites') : '');
foreach ($lines as $line) {
    echo $line, "\n\n";
}
if ($notes) {
    echo "**Notes** (they do not fail the run):\n\n";
    foreach ($notes as $note) {
        echo '- ', secbk_e2e_cell($note), "\n";
    }
    echo "\n";
}
if ($failures) {
    echo "**Failed:**\n\n";
    foreach ($failures as $failure) {
        echo '- ', secbk_e2e_cell($failure), "\n";
    }
    exit(1);
}
echo "**Passed.**\n";
exit(0);
