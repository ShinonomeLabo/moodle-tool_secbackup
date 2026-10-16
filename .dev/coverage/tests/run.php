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
 * Tests for .dev/coverage/merge.php.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Tests for .dev/coverage/merge.php. Run: php .dev/coverage/tests/run.php
// Development only; not part of the plugin package.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$here = __DIR__;
$merge = escapeshellarg(dirname($here) . '/merge.php');
$fixtures = $here . '/fixtures';
$pass = 0;
$fail = 0;
$temporary = [];

/**
 * Runs merge.php and returns [exit code, output].
 *
 * @param string $args Command line arguments.
 * @param bool $withroot Whether to look for the shipped files in the tree of stand-ins of the fixtures (default), not in
 *                       the plugin.
 * @return array
 */
function secbk_run_merge(string $args, bool $withroot = true): array {
    global $merge, $fixtures;
    if ($withroot) {
        $args = "--root=$fixtures/source $args";
    }
    $output = [];
    $code = 0;
    // Development tooling may run commands; the plugin itself never does.
    \exec("php $merge $args 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

/**
 * Writes a report for one test and remembers it for the clean-up at the end.
 *
 * @param string $content Content of the file.
 * @param string $extension json or xml.
 * @return string The path.
 */
function secbk_temp_report(string $content, string $extension = 'json'): string {
    global $temporary;
    $path = sys_get_temp_dir() . '/secbk-merge-test-' . getmypid() . '-' . count($temporary) . '.' . $extension;
    file_put_contents($path, $content);
    $temporary[] = $path;
    return $path;
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

// --summary writes the counted lines for the badge: also when the merge fails, never when an input cannot be used.
$summary = sys_get_temp_dir() . '/secbk-merge-summary-' . getmypid() . '.json';
$temporary[] = $summary;
@unlink($summary);
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ok.txt --summary=$summary $fixtures/phpunit.xml");
secbk_expect('a failing merge still writes the summary', $code === 1 && is_file($summary), $out);
secbk_expect(
    'the summary has the counted lines',
    json_decode((string) @file_get_contents($summary), true) === ['covered' => 2, 'total' => 5],
    (string) @file_get_contents($summary)
);
[$code, $out] = secbk_run_merge(
    "--shipped=$fixtures/shipped-ok.txt --summary=$summary $fixtures/phpunit.xml $fixtures/phpunit-405.xml $fixtures/behat-1.json"
);
secbk_expect(
    'a merge at 100% writes covered equal to total',
    $code === 0 && json_decode((string) @file_get_contents($summary), true) === ['covered' => 5, 'total' => 5],
    (string) @file_get_contents($summary)
);
@unlink($summary);
// Only missed lines leave a valid measurement. Any other problem writes no summary, so that the badge keeps the last one.
foreach (
    [
        'a shipped file in no report' => "--shipped=$fixtures/shipped-missing.txt $fixtures/phpunit.xml $fixtures/phpunit-405.xml $fixtures/behat-1.json",
        'codeCoverageIgnore in a shipped file' => "--shipped=$fixtures/shipped-ignore.txt $fixtures/phpunit-ignore.xml",
        'a file that is not on the release list' => "--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-extra.xml",
        'a file with nothing to count' => "--shipped=$fixtures/shipped-lib.txt $fixtures/phpunit-lib.xml $fixtures/run-lib-disjoint.json",
        'no statement at all' => "--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-nostmt.xml",
    ] as $label => $arguments
) {
    @unlink($summary);
    [$code, $out] = secbk_run_merge("--summary=$summary $arguments");
    secbk_expect("$label exits 1 and writes no summary", $code === 1 && !is_file($summary), $out . (string) @file_get_contents($summary));
}
@unlink($summary);
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ok.txt --summary=$summary $fixtures/does-not-exist.json");
secbk_expect('an input that cannot be used writes no summary', $code === 2 && !is_file($summary), $out);
[$code, $out] = secbk_run_merge(
    "--shipped=$fixtures/shipped-ok.txt --summary=/no/such/folder/summary.json $fixtures/phpunit.xml $fixtures/phpunit-405.xml"
    . " $fixtures/behat-1.json"
);
secbk_expect('a summary that cannot be written exits 2', $code === 2 && str_contains($out, 'cannot write /no/such/folder'), $out);

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

// A report of the collector that is lost or damaged must stop the merge: the tables of the runs decide the denominator, so
// a run that is dropped without a word could turn a failure into a pass.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/does-not-exist.json");
secbk_expect('a missing JSON exits 2', $code === 2 && str_contains($out, 'does-not-exist.json'), $out);

$plugin = '/var/www/html/admin/tool/secbackup';
$damaged = [
    'a truncated JSON' => '{"files": {"' . $plugin . '/db/b.php": [27, 3',
    'an empty JSON file' => '',
    'a JSON that is not an object' => '"text"',
    'a JSON object without files' => '{"executable": {}}',
    'an empty JSON object' => '{}',
    'files that is not a map' => '{"files": "x"}',
    'a file that maps to a number' => '{"files": {"' . $plugin . '/db/b.php": 5}}',
    'a line number that is a string' => '{"files": {"' . $plugin . '/db/b.php": ["27"]}}',
    'a line number that is a float' => '{"files": {"' . $plugin . '/db/b.php": [27.5]}}',
    'a file that maps to a map' => '{"files": {"' . $plugin . '/db/b.php": {"a": 27}}}',
    'executable that is not a map' => '{"files": {}, "executable": 3}',
    'a table that is not a list' => '{"files": {}, "executable": {"' . $plugin . '/db/b.php": 27}}',
    'a list where files must be a map' => '{"files": [[27]]}',
    'a list where executable must be a map' => '{"files": {}, "executable": [[27, 30]]}',
    'a number as the name of a file' => '{"files": {"5": [27]}}',
    'an empty name of a file' => '{"files": {"": [27]}}',
    'a name with a parent folder' => '{"files": {"' . $plugin . '/tests/../db/b.php": [27]}}',
    'a name with a parent folder before the plugin' => '{"files": {"/var/www/html/../html/admin/tool/secbackup/db/b.php": [27]}}',
    'a name with a current folder' => '{"files": {"' . $plugin . '/./db/b.php": [27]}}',
    'a name with a double slash' => '{"files": {"' . $plugin . '//db/b.php": [27]}}',
    'the table of a name with a parent folder' => '{"files": {}, "executable": {"' . $plugin . '/tests/../db/b.php": [27]}}',
];
foreach ($damaged as $label => $content) {
    $file = secbk_temp_report($content);
    [$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $file");
    secbk_expect("$label exits 2", $code === 2 && str_contains($out, $file), $out);
}

// The same holds for a Clover report that is missing or damaged.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/does-not-exist.xml");
secbk_expect('a missing Clover exits 2', $code === 2 && str_contains($out, 'does-not-exist.xml'), $out);

$file = secbk_temp_report('<coverage><project><file name="' . $plugin . '/db/b.php"><line num="27" type="stm', 'xml');
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $file");
secbk_expect('a truncated Clover exits 2', $code === 2 && str_contains($out, $file), $out);

// A list with something in it is told apart from a map: the message says what is wrong.
foreach (['files' => '{"files": [[27]]}', 'executable' => '{"files": {}, "executable": [[27, 30]]}'] as $key => $content) {
    $file = secbk_temp_report($content);
    [$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $file");
    secbk_expect("a list where $key must be a map is said so", str_contains($out, "\"$key\" is not a map"), $out);
}

// A run that executed nothing writes "files" as an empty list; that is a good report and the lines stay misses.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-b.txt $fixtures/phpunit-static.xml $fixtures/run-nothing-executed.json");
secbk_expect('a run that executed nothing is accepted', $code === 1 && str_contains($out, "db/b.php: 27, 30\n"), $out);

// A name with two dots in a file name is a good name: it is only unknown to the release list.
$file = secbk_temp_report('{"files": {"' . $plugin . '/classes/a..b.php": [3]}}');
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-a.xml $file");
secbk_expect(
    'a file name with two dots is not a damaged report',
    $code === 1 && str_contains($out, 'in a coverage report but not in the release list: classes/a..b.php'),
    $out
);

// Nothing to count must not read as a pass.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-empty.txt $fixtures/phpunit.xml");
secbk_expect('an empty list of shipped files exits 2', $code === 2 && str_contains($out, 'shipped-empty.txt'), $out);

[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-nostmt.xml");
secbk_expect('no statement at all fails', $code === 1 && str_contains($out, 'no statement was counted'), $out);

// A file whose statements are all outside the table of the runs has nothing left to count: it fails by name.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-lib.txt $fixtures/phpunit-lib.xml $fixtures/run-lib-disjoint.json");
secbk_expect('a table that does not overlap the statements fails', $code === 1, $out);
secbk_expect('names the file and its lines', str_contains($out, 'lib.php: nothing to count') && str_contains($out, '10-12'), $out);

[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a-lib.txt $fixtures/phpunit-a-lib.xml $fixtures/run-a-lib.json");
secbk_expect(
    'a file with nothing to count fails next to a good file',
    $code === 1 && str_contains($out, 'lib.php: nothing to count') && str_contains($out, 'Total: 3/3 lines (100.00%)'),
    $out
);

[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-lib.txt $fixtures/phpunit-lib.xml $fixtures/run-lib-empty.json");
secbk_expect('an empty table fails', $code === 1, $out);
secbk_expect('names the file of the empty table', str_contains($out, 'lib.php: nothing to count'), $out);

// A shipped file that the Clover knows but without a single statement is named, so that it is not forgotten.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a-lib.txt $fixtures/phpunit-a-nostmt.xml");
secbk_expect('a file without a statement is named', $code === 0 && str_contains($out, "lib.php: no statement to count\n"), $out);
secbk_expect('and the others are counted', str_contains($out, 'Total: 3/3 lines (100.00%)'), $out);

// The lines that the table of the runs takes away are listed, so that nobody has to trust the 100%.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-access.txt $fixtures/phpunit-access.xml $fixtures/run-access.json");
secbk_expect('lists the lines that pcov does not count', str_contains($out, "db/access.php: not executable per pcov: 25, 29-35\n"), $out);
secbk_expect('counts the line that pcov counts', $code === 0 && str_contains($out, 'Total: 1/1 lines (100.00%)'), $out);

// Lines that php-code-coverage removes with an annotation never reach the Clover, so the source is searched.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-ignore.txt $fixtures/phpunit-ignore.xml");
secbk_expect('codeCoverageIgnore in a shipped file fails', $code === 1, $out);
secbk_expect('names the line of the annotation', str_contains($out, 'db/ignored.php:26:'), $out);
secbk_expect('finds the start of a block', str_contains($out, 'db/ignored-block.php:25:'), $out);
secbk_expect('finds the end of a block in any case', str_contains($out, 'db/ignored-block.php:27:'), $out);

// The source of a shipped file is looked up in the plugin unless --root says otherwise.
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-real.txt $fixtures/phpunit-real.xml", false);
secbk_expect('the plugin is the default source root', $code === 0 && str_contains($out, 'Total: 6/6 lines (100.00%)'), $out);

[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-nosource.txt $fixtures/phpunit-a.xml");
secbk_expect('a shipped file without a source exits 2', $code === 2 && str_contains($out, 'classes/missing-source.php'), $out);

// A report must not name a file outside tests/ and .dev/ that the release does not contain (an uncommitted file).
[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-extra.xml");
secbk_expect('a file that is not on the release list fails', $code === 1, $out);
secbk_expect(
    'names the file of the Clover',
    str_contains($out, 'in a coverage report but not in the release list: classes/extra.php'),
    $out
);
secbk_expect('files of tests/ are no problem', !str_contains($out, 'tests/generator'), $out);

[$code, $out] = secbk_run_merge("--shipped=$fixtures/shipped-a.txt $fixtures/phpunit-a.xml $fixtures/run-extra.json");
secbk_expect('a file of a JSON report that is not on the release list fails', $code === 1, $out);
secbk_expect(
    'names the file of the JSON',
    str_contains($out, 'in a coverage report but not in the release list: classes/extra2.php'),
    $out
);
secbk_expect('files of .dev/ are no problem', !str_contains($out, 'collector.php'), $out);

// merge.php answers a request to a web server (a checkout of the repository keeps .dev/) with nothing.
$cgi = [];
\exec('command -v php-cgi 2>/dev/null', $cgi);
if ($cgi === []) {
    // In CI nothing is skipped: setup-php installs php-cgi, so a missing one is a defect of the job.
    secbk_expect(
        'php-cgi is there when CI is set',
        getenv('CI') === false || getenv('CI') === '',
        'php-cgi was not found, so the refusal to run outside the command line is not tested'
    );
    echo "SKIP: php-cgi was not found, so the refusal to run outside the command line is not tested\n";
} else {
    $output = [];
    $code = 0;
    \exec(escapeshellarg($cgi[0]) . " -q -f $merge 2>&1", $output, $code);
    secbk_expect('merge.php refuses to run outside the command line', $code === 1 && implode('', $output) === '', "exit $code: " . implode("\n", $output));
}

foreach ($temporary as $path) {
    @unlink($path);
}
echo "merge: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
