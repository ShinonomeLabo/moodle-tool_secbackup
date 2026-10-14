<?php
// Merges line coverage from Clover reports (PHPUnit) and JSON reports (collector.php) across
// Moodle versions and fails unless every statement of every shipped PHP file was executed.
// Development only; not part of the plugin package.
//
// The statements of a file are the "stmt" lines of the Clover reports. PHPUnit takes them from a static analysis for a file
// that no covered test loaded, and that analysis counts lines pcov never counts (the MOODLE_INTERNAL check, the item
// lines of an array literal), which no run can execute. So once collected runs (JSON) have loaded a file, only the lines
// in the union of their tables of executable lines ("executable", executed or not) stay statements: the rule PHPUnit
// applies inside one run. A line that is executable but was not executed is in a table, so a real miss is never hidden.
// A file that no collected run loaded keeps all the lines of the Clover reports.
//
// Nothing may make the result look better than it is, so the merge fails (exit code 1) when:
// - a statement was not executed, or a shipped file is in no Clover report;
// - every statement of a shipped file is outside the tables of the runs, or no statement was counted at all;
// - the source of a shipped file contains codeCoverageIgnore: php-code-coverage removes such lines before it writes the
//   Clover report, so they would never be counted;
// - a report names a file that is neither on the release list nor in tests/ or .dev/ (a file that was not committed).
// It stops with exit code 2 when an input cannot be used: a report that is missing, damaged or of another shape (also a
// list where a map of files is expected, and a file name that is not a clean path), an empty list of shipped files, or a
// shipped file whose source cannot be found. The lines that the tables of the runs take away are listed in the output,
// for every file, so that the 100% can be checked; so is every shipped file that has no statement in the Clover reports.
//
// --summary=<file> writes the counted lines as {"covered": N, "total": M}, also when the merge fails on a missed line (the
// numbers are the ones of the output line "Total"). It is not written when an input cannot be used (exit code 2).
//
// Usage: php merge.php --shipped=<file list> [--report=<output file>] [--summary=<json file>] [--root=<plugin source>]
//                      <report files...>
// The source of the shipped files is searched in --root (default: the plugin that holds this .dev/).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$shippedfile = null;
$reportfile = null;
$summaryfile = null;
$root = dirname(__DIR__, 2);
$inputs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--shipped=')) {
        $shippedfile = substr($arg, 10);
    } else if (str_starts_with($arg, '--report=')) {
        $reportfile = substr($arg, 9);
    } else if (str_starts_with($arg, '--summary=')) {
        $summaryfile = substr($arg, 10);
    } else if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } else {
        $inputs[] = $arg;
    }
}
if ($shippedfile === null || $inputs === []) {
    fwrite(STDERR, "usage: merge.php --shipped=<file list> [--report=<output>] [--summary=<json>] [--root=<plugin source>]"
        . " <reports...>\n");
    exit(2);
}

/**
 * Stops the merge because an input cannot be used.
 *
 * @param string $message What is wrong, naming the input.
 */
function secbk_fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(2);
}

/**
 * Turns an absolute path inside any environment into a path relative to the plugin root.
 *
 * @param string $path Absolute path.
 * @return string|null Relative path, or null when the file is not in the plugin.
 */
function secbk_relative(string $path): ?string {
    if (preg_match('~/admin/tool/secbackup/(.+)$~', $path, $m) !== 1) {
        return null;
    }
    return $m[1];
}

/**
 * Tells whether the name of a file in a report is a clean path: not empty, no empty folder name (//) and no folder . or
 * .. in it. Such a name would be read as another file (tests/../lib.php is in tests/), so it is not accepted.
 *
 * @param string $path The name.
 * @return bool
 */
function secbk_clean_path(string $path): bool {
    return $path !== '' && !str_contains($path, '//') && preg_match('~(^|/)\.\.?(/|$)~', $path) !== 1;
}

/**
 * Reads a JSON report of collector.php and checks its shape: an object with "files" and, optionally, "executable",
 * each a map of clean file names to lists of line numbers.
 *
 * @param string $input Path of the report.
 * @return array The report.
 */
function secbk_read_json(string $input): array {
    $text = is_file($input) ? @file_get_contents($input) : false;
    if ($text === false) {
        secbk_fail("cannot read $input");
    }
    try {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        secbk_fail("$input: not valid JSON ({$e->getMessage()})");
    }
    if (!is_array($data) || !array_key_exists('files', $data)) {
        secbk_fail("$input: not a report of the collector (an object with \"files\" is expected)");
    }
    foreach (['files', 'executable'] as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        // An empty map and an empty list are the same in PHP, but a list with something in it is not a map.
        if (!is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key]))) {
            secbk_fail("$input: \"$key\" is not a map of file names to lists of line numbers");
        }
        foreach ($data[$key] as $path => $lines) {
            if (!is_string($path) || !secbk_clean_path($path)) {
                secbk_fail("$input: \"$key\" has a file name that is not a clean path: " . json_encode($path));
            }
            if (!is_array($lines) || !array_is_list($lines) || count(array_filter($lines, 'is_int')) !== count($lines)) {
                secbk_fail("$input: \"$key\" of $path is not a list of line numbers");
            }
        }
    }
    return $data;
}

/**
 * Writes line numbers as ranges: 25, 29-35.
 *
 * @param int[] $lines Line numbers.
 * @return string
 */
function secbk_ranges(array $lines): string {
    sort($lines);
    $parts = [];
    $i = 0;
    while ($i < count($lines)) {
        $j = $i;
        while (isset($lines[$j + 1]) && $lines[$j + 1] === $lines[$j] + 1) {
            $j++;
        }
        $parts[] = $j > $i ? $lines[$i] . '-' . $lines[$j] : (string) $lines[$i];
        $i = $j + 1;
    }
    return implode(', ', $parts);
}

libxml_use_internal_errors(true);
$statements = [];
$covered = [];
$executable = [];
$seen = [];
foreach ($inputs as $input) {
    if (str_ends_with($input, '.json')) {
        $data = secbk_read_json($input);
        foreach ($data['files'] as $path => $lines) {
            $relative = secbk_relative((string) $path);
            if ($relative === null) {
                continue;
            }
            $seen[$relative] = true;
            foreach ($lines as $line) {
                $covered[$relative][$line] = true;
            }
        }
        foreach (($data['executable'] ?? []) as $path => $lines) {
            $relative = secbk_relative((string) $path);
            if ($relative === null) {
                continue;
            }
            $seen[$relative] = true;
            $executable[$relative] = $executable[$relative] ?? [];
            foreach ($lines as $line) {
                $executable[$relative][$line] = true;
            }
        }
        continue;
    }
    $text = is_file($input) ? @file_get_contents($input) : false;
    $xml = $text === false ? false : simplexml_load_string($text);
    if ($xml === false) {
        secbk_fail("cannot read $input");
    }
    foreach ($xml->xpath('//file') as $file) {
        $relative = secbk_relative((string) $file['name']);
        if ($relative === null) {
            continue;
        }
        $seen[$relative] = true;
        $statements[$relative] = $statements[$relative] ?? [];
        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }
            $num = (int) $line['num'];
            $statements[$relative][$num] = true;
            if ((int) $line['count'] > 0) {
                $covered[$relative][$num] = true;
            }
        }
    }
}

$shippedlines = @file($shippedfile);
if ($shippedlines === false) {
    secbk_fail("cannot read $shippedfile");
}
$shipped = array_values(array_filter(array_map('trim', $shippedlines), fn($l) => $l !== ''));
if ($shipped === []) {
    secbk_fail("$shippedfile names no shipped file");
}

$problems = [];
$notes = [];
$total = 0;
$hit = 0;
foreach ($shipped as $file) {
    $source = @file($root . '/' . $file);
    if ($source === false) {
        secbk_fail("cannot read the source of $file in $root");
    }
    foreach ($source as $index => $text) {
        if (stripos($text, 'codeCoverageIgnore') !== false) {
            $problems[] = sprintf('%s:%d: codeCoverageIgnore removes lines from the coverage report', $file, $index + 1);
        }
    }
    if (!array_key_exists($file, $statements)) {
        $problems[] = "$file: not in any coverage report";
        continue;
    }
    if ($statements[$file] === []) {
        $notes[] = "$file: no statement to count";
        continue;
    }
    $missing = [];
    $dropped = [];
    foreach (array_keys($statements[$file]) as $num) {
        if (isset($executable[$file]) && !isset($executable[$file][$num])) {
            $dropped[] = $num;
            continue;
        }
        $total++;
        if (isset($covered[$file][$num])) {
            $hit++;
        } else {
            $missing[] = $num;
        }
    }
    if ($dropped !== [] && count($dropped) === count($statements[$file])) {
        $problems[] = "$file: nothing to count, all statements are not executable per pcov: " . secbk_ranges($dropped);
    } else if ($dropped !== []) {
        $notes[] = "$file: not executable per pcov: " . secbk_ranges($dropped);
    }
    if ($missing !== []) {
        sort($missing);
        $problems[] = "$file: " . implode(', ', $missing);
    }
}

$outside = array_filter(
    array_keys($seen),
    fn($file) => !in_array($file, $shipped, true) && !str_starts_with($file, 'tests/') && !str_starts_with($file, '.dev/')
);
sort($outside);
foreach ($outside as $file) {
    $problems[] = "in a coverage report but not in the release list: $file";
}
if ($total === 0) {
    $problems[] = 'no statement was counted in any shipped file';
}

$percent = $total > 0 ? 100 * $hit / $total : 0.0;
$lines = array_merge($problems, $notes, [sprintf('Total: %d/%d lines (%.2f%%)', $hit, $total, $percent)]);
$text = implode("\n", $lines) . "\n";
echo $text;
if ($reportfile !== null) {
    file_put_contents($reportfile, $text);
}
if ($summaryfile !== null) {
    $summary = json_encode(['covered' => $hit, 'total' => $total]) . "\n";
    if (file_put_contents($summaryfile, $summary) !== strlen($summary)) {
        secbk_fail("cannot write $summaryfile");
    }
}
exit($problems === [] ? 0 : 1);
