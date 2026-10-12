<?php
// Tests for .dev/coverage/merge.php. Run: php .dev/coverage/tests/run.php
// Development only; not part of the plugin package.

$here = __DIR__;
$merge = escapeshellarg(dirname($here) . '/merge.php');
$fixtures = $here . '/fixtures';
$pass = 0;
$fail = 0;

/**
 * Runs merge.php and returns [exit code, output].
 *
 * @param string $args Command line arguments.
 * @return array
 */
function secbk_run_merge(string $args): array {
    global $merge;
    $output = [];
    $code = 0;
    // Development tooling may run commands; the plugin itself never does.
    \exec("php $merge $args 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

/**
 * Records one expectation.
 *
 * @param string $label Test label.
 * @param bool $ok Whether it held.
 * @param string $detail Output to show on failure.
 */
function secbk_expect(string $label, bool $ok, string $detail): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $label\n$detail\n";
    }
}

// Only the 5.3 Clover: line 4 of a.php and both lines of index.php are not covered.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ok.txt $fixtures/phpunit.xml");
secbk_expect('uncovered lines fail', $code === 1, $out);
secbk_expect('reports a.php line 4', str_contains($out, 'classes/a.php: 4'), $out);
secbk_expect('reports index.php lines', str_contains($out, 'index.php: 20, 21'), $out);

// Adding the 4.5 Clover (other path prefix) covers line 4; adding Behat covers index.php.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ok.txt $fixtures/phpunit.xml $fixtures/phpunit-405.xml $fixtures/behat-1.json");
secbk_expect('merged runs reach 100%', $code === 0, $out);
secbk_expect('prints the total', str_contains($out, 'Total: 5/5 lines (100.00%)'), $out);
secbk_expect('ignores JSON lines that are not statements', !str_contains($out, '99'), $out);

// A shipped file without any Clover entry must fail even when everything else is covered.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-missing.txt $fixtures/phpunit.xml $fixtures/phpunit-405.xml $fixtures/behat-1.json");
secbk_expect('missing file fails', $code === 1, $out);
secbk_expect('names the missing file', str_contains($out, 'lib.php: not in any coverage report'), $out);

// No reports at all.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ok.txt");
secbk_expect('no reports fails', $code === 2, $out);

// The Clover of a file that no covered test loaded comes from a static analysis (lines 25 and 29 here), and pcov never
// counts those lines. Once a run has loaded the file, only the lines of its table of executable lines are statements.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/run-full.json");
secbk_expect('lines pcov never counts are not statements', $code === 0, $out);
secbk_expect('counts only the executable lines', str_contains($out, 'Total: 2/2 lines (100.00%)'), $out);

// A file that no run loaded keeps every line of the static analysis.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml");
secbk_expect('an unloaded file keeps its static lines', $code === 1 && str_contains($out, "db/b.php: 25, 27, 29, 30\n"), $out);

// An executable line that did not run is in the table, so it stays a miss.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/run-partial.json");
secbk_expect('an executable line that did not run is a miss', $code === 1 && str_contains($out, "db/b.php: 30\n"), $out);

// The tables and the executed lines of several runs are united.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/run-line27.json $fixtures/run-line30.json");
secbk_expect('the tables of several runs are united', $code === 0 && str_contains($out, 'Total: 2/2 lines (100.00%)'), $out);

echo "merge: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
