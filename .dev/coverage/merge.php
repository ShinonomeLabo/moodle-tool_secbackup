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
// Usage: php merge.php --shipped=<file list> [--report=<output file>] <report files...>

$shippedfile = null;
$reportfile = null;
$inputs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--shipped=')) {
        $shippedfile = substr($arg, 10);
    } else if (str_starts_with($arg, '--report=')) {
        $reportfile = substr($arg, 9);
    } else {
        $inputs[] = $arg;
    }
}
if ($shippedfile === null || $inputs === []) {
    fwrite(STDERR, "usage: merge.php --shipped=<file list> [--report=<output>] <reports...>\n");
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

$statements = [];
$covered = [];
$executable = [];
foreach ($inputs as $input) {
    if (str_ends_with($input, '.json')) {
        $data = json_decode((string) file_get_contents($input), true);
        foreach (($data['files'] ?? []) as $path => $lines) {
            $relative = secbk_relative($path);
            if ($relative === null) {
                continue;
            }
            foreach ($lines as $line) {
                $covered[$relative][(int) $line] = true;
            }
        }
        foreach (($data['executable'] ?? []) as $path => $lines) {
            $relative = secbk_relative($path);
            if ($relative === null) {
                continue;
            }
            $executable[$relative] = $executable[$relative] ?? [];
            foreach ($lines as $line) {
                $executable[$relative][(int) $line] = true;
            }
        }
        continue;
    }
    $xml = simplexml_load_file($input);
    if ($xml === false) {
        fwrite(STDERR, "cannot read $input\n");
        exit(2);
    }
    foreach ($xml->xpath('//file') as $file) {
        $relative = secbk_relative((string) $file['name']);
        if ($relative === null) {
            continue;
        }
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

$shipped = array_values(array_filter(array_map('trim', file($shippedfile)), fn($l) => $l !== ''));
$problems = [];
$total = 0;
$hit = 0;
foreach ($shipped as $file) {
    if (!array_key_exists($file, $statements)) {
        $problems[] = "$file: not in any coverage report";
        continue;
    }
    $missing = [];
    foreach (array_keys($statements[$file]) as $num) {
        if (isset($executable[$file]) && !isset($executable[$file][$num])) {
            continue;
        }
        $total++;
        if (isset($covered[$file][$num])) {
            $hit++;
        } else {
            $missing[] = $num;
        }
    }
    if ($missing !== []) {
        sort($missing);
        $problems[] = "$file: " . implode(', ', $missing);
    }
}

$percent = $total > 0 ? 100 * $hit / $total : 0.0;
$lines = array_merge($problems, [sprintf('Total: %d/%d lines (%.2f%%)', $hit, $total, $percent)]);
$text = implode("\n", $lines) . "\n";
echo $text;
if ($reportfile !== null) {
    file_put_contents($reportfile, $text);
}
exit($problems === [] ? 0 : 1);
