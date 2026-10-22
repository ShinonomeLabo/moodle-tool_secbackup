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
 * Development only: checks what the staging backup service says about a site against what a scenario expects.
 *
 * Usage:
 *   php .dev/e2e/verdict.php <inspect.json> --done --runs=<n> [--failed=<reason>]
 *   php .dev/e2e/verdict.php <inspect.json> --stopped=<open|reason>
 *
 * <inspect.json> is what sec.php inspect wrote. --done is the end of a scenario: the site has <n> runs, the last one is
 * completed with a receipt, it is the only completed one, and with --failed every run before it failed with that reason;
 * the service holds objects of the site, each one locked in COMPLIANCE mode until a time to come (the list of what the
 * service holds is not locked, by design, and the service leaves it out of the count), and every object is in the storage
 * class INTELLIGENT_TIERING. --stopped is the moment after a run was cut off: no run completed, the last one is still open
 * (open: neither completed nor failed) or failed with the reason given, and no object is unlocked.
 *
 * It prints the runs and the objects (states and numbers), a line "reason: ..." for each condition that does not hold, and
 * "verdict: pass" or "verdict: fail". Exit codes: 0 pass, 1 fail, 2 wrong usage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Only the command line runs it: a site installed with git clone has this directory under its web root.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$usage = "Usage: php verdict.php <inspect.json> --done --runs=<n> [--failed=<reason>]\n"
    . "       php verdict.php <inspect.json> --stopped=<open|reason>\n";
$given = array_slice($argv, 1);
$file = (string) array_shift($given);
$options = [];
foreach ($given as $argument) {
    if (preg_match('/^--(done|runs|failed|stopped)(=([a-z0-9_]{1,40}))?\z/', $argument, $m) !== 1 || isset($options[$m[1]])) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    $options[$m[1]] = $m[3] ?? true;
}
$done = ($options['done'] ?? null) === true && is_string($options['runs'] ?? null) && ctype_digit($options['runs'])
    && !isset($options['stopped']) && ($options['failed'] ?? '') !== true;
$stopped = is_string($options['stopped'] ?? null) && count($options) === 1;
if ($file === '' || (!$done && !$stopped)) {
    fwrite(STDERR, $usage);
    exit(2);
}

$reasons = [];

/**
 * How a run is shown: its status, with its reason when it has one.
 *
 * @param array $run The run.
 * @return string
 */
function secbk_e2e_run_text(array $run): string {
    $status = is_string($run['status'] ?? null) ? $run['status'] : 'unknown';
    $code = is_string($run['failure_code'] ?? null) ? ' (' . $run['failure_code'] . ')' : '';
    return $status . $code;
}

$text = is_file($file) ? file_get_contents($file) : false;
$data = is_string($text) ? json_decode($text, true) : null;
if (!is_array($data)) {
    $reasons[] = 'the inspection cannot be read';
} else if (isset($data['error'])) {
    $reasons[] = 'the inspection failed (' . (is_string($data['error']) ? $data['error'] : 'unknown') . ')';
} else if (!is_array($data['runs'] ?? null) || !is_array($data['objects']['lock'] ?? null)) {
    $reasons[] = 'the inspection does not have the form of sec.php inspect';
}

if (!$reasons) {
    $runs = array_values(array_filter($data['runs'], 'is_array'));
    $objects = $data['objects'];
    $lock = $objects['lock'];
    $classes = is_array($objects['storage_classes'] ?? null) ? $objects['storage_classes'] : [];
    $count = (int) ($objects['count'] ?? 0);
    $shown = [];
    foreach ($runs as $i => $run) {
        $shown[] = ($i + 1) . ' ' . secbk_e2e_run_text($run) . (($run['has_receipt'] ?? false) === true ? ' with a receipt' : '')
            . (is_int($run['requested_bytes'] ?? null) ? ', ' . $run['requested_bytes'] . ' bytes asked for' : '');
    }
    echo 'runs: ', count($runs), $shown ? ': ' . implode('; ', $shown) : '', "\n";
    echo 'objects: ', $count, ', ', (int) ($objects['bytes'] ?? 0), ' bytes; lock: compliance ',
        ($lock['compliance'] ?? false) === true ? 'yes' : 'no', ', missing ', (int) ($lock['missing_keys'] ?? 0),
        ', expired ', (int) ($lock['expired_keys'] ?? 0), ', gone ', (int) ($lock['gone_keys'] ?? 0), '; classes: ',
        $classes ? implode(', ', array_map(static fn($class, $n) => "$class $n", array_keys($classes), $classes)) : 'none', "\n";

    $completed = count(array_filter($runs, static fn(array $run): bool => ($run['status'] ?? null) === 'completed'));
    $last = $runs ? $runs[count($runs) - 1] : null;
    $unlocked = (int) ($lock['missing_keys'] ?? 0) + (int) ($lock['expired_keys'] ?? 0) + (int) ($lock['gone_keys'] ?? 0);
    $locktext = sprintf('(missing %d, expired %d, gone %d)', (int) ($lock['missing_keys'] ?? 0),
        (int) ($lock['expired_keys'] ?? 0), (int) ($lock['gone_keys'] ?? 0));
    if ($done) {
        $wanted = (int) $options['runs'];
        if (count($runs) !== $wanted) {
            $reasons[] = "expected $wanted runs, found " . count($runs);
        }
        if ($completed !== 1) {
            $reasons[] = "expected one completed generation, found $completed";
        }
        if ($last === null) {
            $reasons[] = 'the site has no run';
        } else if (($last['status'] ?? null) !== 'completed') {
            $reasons[] = 'the last run is ' . secbk_e2e_run_text($last) . ', not completed with a receipt';
        } else if (($last['has_receipt'] ?? false) !== true) {
            $reasons[] = 'the last run is completed without a receipt';
        }
        if (isset($options['failed'])) {
            foreach (array_slice($runs, 0, -1) as $i => $run) {
                if (($run['status'] ?? null) !== 'failed' || ($run['failure_code'] ?? null) !== $options['failed']) {
                    $reasons[] = 'run ' . ($i + 1) . ' is ' . secbk_e2e_run_text($run) . ", expected failed ({$options['failed']})";
                }
            }
        }
        if ($count <= 0) {
            $reasons[] = 'the service holds no object of the site';
        }
        if (($lock['compliance'] ?? false) !== true || $unlocked > 0) {
            $reasons[] = "not all objects are locked in COMPLIANCE mode $locktext";
        }
        $other = array_diff_key($classes, ['INTELLIGENT_TIERING' => true]);
        if ($other) {
            $reasons[] = 'objects not in INTELLIGENT_TIERING: '
                . implode(', ', array_map(static fn($class, $n) => "$class $n", array_keys($other), $other));
        } else if ($count > 0 && (int) ($classes['INTELLIGENT_TIERING'] ?? 0) !== $count) {
            $reasons[] = 'the storage classes do not count every object';
        }
    } else {
        if ($completed > 0) {
            $reasons[] = 'a run completed';
        }
        $status = $last['status'] ?? null;
        if ($last === null) {
            $reasons[] = 'the site has no run';
        } else if ($options['stopped'] === 'open' && in_array($status, ['completed', 'failed'], true)) {
            $reasons[] = 'the last run is ' . secbk_e2e_run_text($last) . ', expected it to be still open';
        } else if (
            $options['stopped'] !== 'open'
            && ($status !== 'failed' || ($last['failure_code'] ?? null) !== $options['stopped'])
        ) {
            $reasons[] = 'the last run is ' . secbk_e2e_run_text($last) . ", expected failed ({$options['stopped']})";
        }
        if ($unlocked > 0) {
            $reasons[] = "not all objects are locked in COMPLIANCE mode $locktext";
        }
    }
}

foreach ($reasons as $reason) {
    echo "reason: $reason\n";
}
echo 'verdict: ', $reasons ? 'fail' : 'pass', "\n";
exit($reasons ? 1 : 0);
