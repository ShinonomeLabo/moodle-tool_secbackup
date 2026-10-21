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
 * Adds up the results of the shards of an Infection run into one covered code MSI and checks it against the floor.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development only; not part of the plugin package.
//
// Each shard of .github/workflows/infection.yml leaves a folder infection-<shard> with the logs summaryJson (summary.json)
// and json (infection.json) of infection.json5. The counts of the summaries are added up, and the covered code MSI is
// computed from the sums as Infection computes it for one run: (killed + timed out + errors + syntax errors) divided by
// (all mutants - skipped - ignored - not covered). A covered code MSI of the shards cannot be averaged: the shards have
// different numbers of mutants.
//
// The merge fails (exit code 1) when the covered code MSI is below minCoveredMsi of infection.json5, when a mutant was
// skipped (its tests were not run, so it would leave the count without a word), or when no mutant was tested. It stops with
// exit code 2 when an input cannot be used: a shard of shards.txt without its folder or with a damaged log.
// It lists every escaped, timed out and not covered mutant (file:line mutator), from the plugin root, for the review in
// REVIEW.md, also when it fails.
//
// Usage: php merge.php [--root=<plugin>] [--markdown=<file>] <folder of the shard folders>
// --markdown appends the result as Markdown to the file (the workflow gives $GITHUB_STEP_SUMMARY).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$markdownfile = null;
$inputs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } else if (str_starts_with($arg, '--markdown=')) {
        $markdownfile = substr($arg, 11);
    } else {
        $inputs[] = $arg;
    }
}
if (count($inputs) !== 1) {
    fwrite(STDERR, "usage: merge.php [--root=<plugin>] [--markdown=<file>] <folder of the shard folders>\n");
    exit(2);
}
$folder = rtrim($inputs[0], '/');

/**
 * Stops with exit code 2 and a message.
 *
 * @param string $message What is wrong.
 */
function secbk_infection_input_error(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(2);
}

/**
 * Reads a JSON file that holds an object.
 *
 * @param string $path The file.
 * @return array The decoded object.
 */
function secbk_infection_read(string $path): array {
    $text = @file_get_contents($path);
    $data = $text === false ? null : json_decode($text, true);
    if (!is_array($data)) {
        secbk_infection_input_error("$path is missing or is not JSON");
    }
    return $data;
}

// The shards and the floor.
$shardoutput = [];
$code = 0;
// Development tooling may run commands; the plugin itself never does.
\exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/shards.php') . ' ' . escapeshellarg('--root=' . $root)
    . ' list', $shardoutput, $code);
$shards = $code === 0 ? json_decode(implode('', $shardoutput), true) : null;
if (!is_array($shards) || $shards === []) {
    secbk_infection_input_error('the shards cannot be listed (.dev/infection/shards.php list)');
}
$config = @file_get_contents($root . '/.dev/infection/infection.json5');
if ($config === false || !preg_match('~"minCoveredMsi"\s*:\s*([0-9]+(?:\.[0-9]+)?)~', $config, $m)) {
    secbk_infection_input_error('infection.json5 cannot be read or has no minCoveredMsi');
}
$floortext = $m[1];
$floor = (float) $floortext;

$keys = ['totalMutantsCount', 'killedCount', 'notCoveredCount', 'escapedCount', 'errorCount', 'syntaxErrorCount',
    'skippedCount', 'ignoredCount', 'timeOutCount'];
$sums = array_fill_keys($keys, 0);
$rows = [];
$listed = ['escaped' => [], 'timeouted' => [], 'uncovered' => []];
foreach ($shards as $shard) {
    $stats = secbk_infection_read("$folder/infection-$shard/summary.json")['stats'] ?? null;
    if (!is_array($stats)) {
        secbk_infection_input_error("$folder/infection-$shard/summary.json has no stats");
    }
    foreach ($keys as $key) {
        if (!is_int($stats[$key] ?? null) || $stats[$key] < 0) {
            secbk_infection_input_error("$folder/infection-$shard/summary.json has no count $key");
        }
        $sums[$key] += $stats[$key];
    }
    $rows[] = sprintf('%-16s %5d mutants, %5d killed, %3d escaped, %3d not covered, %3d timed out, %3d errors, %3d skipped, '
        . '%3d ignored', $shard, $stats['totalMutantsCount'], $stats['killedCount'], $stats['escapedCount'],
        $stats['notCoveredCount'], $stats['timeOutCount'], $stats['errorCount'] + $stats['syntaxErrorCount'],
        $stats['skippedCount'], $stats['ignoredCount']);
    $log = secbk_infection_read("$folder/infection-$shard/infection.json");
    foreach (array_keys($listed) as $kind) {
        foreach ($log[$kind] ?? [] as $result) {
            $path = (string) ($result['mutator']['originalFilePath'] ?? '');
            $at = strpos($path, '/classes/');
            $listed[$kind][] = ($at === false ? $path : substr($path, $at + 1)) . ':'
                . (int) ($result['mutator']['originalStartLine'] ?? 0) . ' ' . ($result['mutator']['mutatorName'] ?? '?');
        }
    }
}

$detected = $sums['killedCount'] + $sums['timeOutCount'] + $sums['errorCount'] + $sums['syntaxErrorCount'];
$tested = $sums['totalMutantsCount'] - $sums['skippedCount'] - $sums['ignoredCount'] - $sums['notCoveredCount'];
$msi = $tested > 0 ? 100 * $detected / $tested : 0.0;
$failures = [];
if ($tested <= 0) {
    $failures[] = 'no mutant was tested';
} else if ($msi < $floor) {
    $failures[] = sprintf('the covered code MSI %.2f%% is below the floor of %s%%', $msi, $floortext);
}
if ($sums['skippedCount'] > 0) {
    $failures[] = $sums['skippedCount'] . ' mutants were skipped, so their tests were not run';
}

$lines = $rows;
$lines[] = sprintf('Total: %d mutants, %d killed, %d escaped, %d not covered, %d timed out, %d errors, %d skipped, %d ignored',
    $sums['totalMutantsCount'], $sums['killedCount'], $sums['escapedCount'], $sums['notCoveredCount'], $sums['timeOutCount'],
    $sums['errorCount'] + $sums['syntaxErrorCount'], $sums['skippedCount'], $sums['ignoredCount']);
$lines[] = sprintf('Covered code MSI: %.2f%% (%d of %d tested mutants detected; floor %s%%)', $msi, $detected, $tested,
    $floortext);
foreach (['escaped' => 'Escaped', 'timeouted' => 'Timed out', 'uncovered' => 'Not covered'] as $kind => $title) {
    sort($listed[$kind]);
    $lines[] = "$title: " . count($listed[$kind]);
    foreach ($listed[$kind] as $mutant) {
        $lines[] = '  ' . $mutant;
    }
}
foreach ($failures as $failure) {
    $lines[] = 'FAILED: ' . $failure;
}
echo implode("\n", $lines), "\n";
if ($markdownfile !== null) {
    file_put_contents($markdownfile, "## Mutation testing\n\n```\n" . implode("\n", $lines) . "\n```\n", FILE_APPEND);
}
exit($failures === [] ? 0 : 1);
