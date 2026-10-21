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
 * Tests for .dev/infection/shards.php and .dev/infection/merge.php.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Tests for the shards and the merge of the Infection run. Run: php .dev/infection/tests/run.php
// Development only; not part of the plugin package. Each test builds a small plugin tree of its own in the temporary folder.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$tools = dirname(__DIR__);
$pass = 0;
$fail = 0;
$trees = [];

/**
 * Runs one of the tools and returns [exit code, output].
 *
 * @param string $tool shards.php or merge.php.
 * @param string $args Command line arguments (already quoted).
 * @return array
 */
function secbk_run_tool(string $tool, string $args): array {
    global $tools;
    $output = [];
    $code = 0;
    // Development tooling may run commands; the plugin itself never does.
    \exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tools/$tool") . " $args 2>&1", $output, $code);
    return [$code, implode("\n", $output)];
}

/**
 * Makes a plugin tree with the source files, shards.txt and infection.json5 given.
 *
 * @param string[] $files Paths of the source files, from the plugin root.
 * @param string $shards Content of shards.txt.
 * @param int $floor minCoveredMsi.
 * @return string The root of the tree.
 */
function secbk_tree(array $files, string $shards, int $floor = 95): string {
    global $trees;
    $root = sys_get_temp_dir() . '/secbk-infection-test-' . getmypid() . '-' . count($trees);
    $trees[] = $root;
    mkdir("$root/.dev/infection", 0777, true);
    foreach ($files as $file) {
        @mkdir(dirname("$root/$file"), 0777, true);
        file_put_contents("$root/$file", "<?php\n");
    }
    file_put_contents("$root/.dev/infection/shards.txt", $shards);
    file_put_contents("$root/.dev/infection/infection.json5", "{\n    // A comment, as in the real file.\n    \"source\": {\n"
        . "        \"directories\": [\"classes/local/a\", \"classes/local/b\"]\n    },\n    \"minCoveredMsi\": $floor,\n}\n");
    return $root;
}

/**
 * Writes the logs of one shard as the workflow downloads them.
 *
 * Each list of the json log gets as many mutants as its count in the summary: first the named ones, then mutants of
 * classes/local/a/one.php:1 of the mutator Filler.
 *
 * @param string $folder The folder of the shard folders.
 * @param string $shard The shard.
 * @param array $counts Counts that differ from 0.
 * @param array $named Named mutants by list (escaped, errored, ...), each as [file, line, mutator].
 */
function secbk_shard_logs(string $folder, string $shard, array $counts, array $named = []): void {
    $stats = array_merge(array_fill_keys(['totalMutantsCount', 'killedCount', 'notCoveredCount', 'escapedCount', 'errorCount',
        'syntaxErrorCount', 'skippedCount', 'ignoredCount', 'timeOutCount'], 0), $counts);
    @mkdir("$folder/infection-$shard", 0777, true);
    file_put_contents("$folder/infection-$shard/summary.json", json_encode(['stats' => $stats]));
    $log = ['stats' => $stats, 'killed' => [], 'ignored' => []];
    $lists = ['escaped' => 'escapedCount', 'timeouted' => 'timeOutCount', 'uncovered' => 'notCoveredCount',
        'errored' => 'errorCount', 'syntaxErrors' => 'syntaxErrorCount'];
    foreach ($lists as $list => $key) {
        $mutants = array_pad($named[$list] ?? [], $stats[$key], ['classes/local/a/one.php', 1, 'Filler']);
        $log[$list] = [];
        foreach ($mutants as [$file, $line, $mutator]) {
            $log[$list][] = ['mutator' => ['mutatorName' => $mutator,
                'originalFilePath' => "/runner/moodle/public/admin/tool/secbackup/$file", 'originalStartLine' => $line]];
        }
    }
    file_put_contents("$folder/infection-$shard/infection.json", json_encode($log));
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

$files = ['classes/local/a/one.php', 'classes/local/a/one_two.php', 'classes/local/b/deep/three.php'];
$good = "# comment\none classes/local/a/one.php\none classes/local/a/one_two.php\n\ntwo classes/local/b/deep/three.php\n";

// The shards of the tree are listed in the order of the file, with the files of each.
$root = secbk_tree($files, $good);
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' list');
secbk_expect('list gives the shards as JSON', $code === 0 && $out === '["one","two"]', "exit $code: $out");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' filter one');
secbk_expect('filter gives the files of a shard', $code === 0 && $out === 'classes/local/a/one.php,classes/local/a/one_two.php',
    "exit $code: $out");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' filter three');
secbk_expect('an unknown shard fails', $code === 1, "exit $code: $out");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' check');
secbk_expect('a complete list passes the check', $code === 0 && str_contains($out, '2 shards, 3 files'), "exit $code: $out");

// A file in no shard, a file in two shards and a listed file that is not a source file each fail the check.
$root = secbk_tree($files, "one classes/local/a/one.php\ntwo classes/local/b/deep/three.php\n");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' check');
secbk_expect('a file in no shard fails', $code === 1 && str_contains($out, 'classes/local/a/one_two.php is in no shard'),
    "exit $code: $out");
$root = secbk_tree($files, $good . "two classes/local/a/one.php\n");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' check');
secbk_expect('a file in two shards fails', $code === 1 && str_contains($out, 'classes/local/a/one.php is in 2 shards (one, two)'),
    "exit $code: $out");
$root = secbk_tree($files, $good . "two classes/local/c/gone.php\n");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' check');
secbk_expect('a listed file outside the sources fails',
    $code === 1 && str_contains($out, 'classes/local/c/gone.php of the shard two'), "exit $code: $out");
$root = secbk_tree($files, "one classes/local/a/one.php extra\n");
[$code, $out] = secbk_run_tool('shards.php', escapeshellarg("--root=$root") . ' check');
secbk_expect('a damaged line cannot be used', $code === 2, "exit $code: $out");

// The shards of this repository cover its source folders.
[$code, $out] = secbk_run_tool('shards.php', 'check');
secbk_expect('the shards of the plugin pass the check', $code === 0, "exit $code: $out");

// The merge adds up the counts: 199 + 5 detected of 200 + 10 is 97.14%, above the floor, while the average of the MSI of the
// two shards (99.5% and 50%) would be below it.
$root = secbk_tree($files, $good);
$folder = "$root/results";
secbk_shard_logs($folder, 'one', ['totalMutantsCount' => 200, 'killedCount' => 199, 'escapedCount' => 1],
    ['escaped' => [['classes/local/a/one.php', 12, 'CastString']]]);
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 10, 'killedCount' => 5, 'escapedCount' => 5]);
$markdown = "$root/summary.md";
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg("--markdown=$markdown") . ' '
    . escapeshellarg($folder));
secbk_expect('the sums decide',
    $code === 0 && str_contains($out, 'Covered code MSI: 97.14% (204 of 210 tested mutants detected; floor 95%)'),
    "exit $code: $out");
secbk_expect('an escaped mutant is listed from the plugin root',
    str_contains($out, '  classes/local/a/one.php:12 CastString'), $out);
secbk_expect('the Markdown summary is written', str_contains((string) @file_get_contents($markdown), 'Covered code MSI: 97.14%'),
    (string) @file_get_contents($markdown));

// Below the floor it fails; mutants that no test covers are not in the covered code MSI; timed out ones count as found.
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 30, 'killedCount' => 5, 'escapedCount' => 25]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('below the floor fails',
    $code === 1 && str_contains($out, 'FAILED: the covered code MSI 88.70% is below the floor of 95%'), "exit $code: $out");
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 30, 'killedCount' => 3, 'timeOutCount' => 2, 'notCoveredCount' => 25]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('not covered mutants are outside the covered code MSI', $code === 0
    && str_contains($out, 'Covered code MSI: 99.51% (204 of 205 tested mutants detected'), "exit $code: $out");

// Ignored mutants are outside the covered code MSI too: 190 of 200, not of 210.
secbk_shard_logs($folder, 'one', ['totalMutantsCount' => 210, 'killedCount' => 190, 'escapedCount' => 10, 'ignoredCount' => 10]);
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 1, 'killedCount' => 1]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('ignored mutants are outside the covered code MSI', $code === 0
    && str_contains($out, 'Covered code MSI: 95.02% (191 of 201 tested mutants detected'), "exit $code: $out");

// An error counts as detected, as Infection counts it, but fails the merge, and the mutant is listed.
secbk_shard_logs($folder, 'one', ['totalMutantsCount' => 200, 'killedCount' => 200]);
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 10, 'killedCount' => 9, 'errorCount' => 1],
    ['errored' => [['classes/local/b/deep/three.php', 7, 'MethodCallRemoval']]]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('an errored mutant fails the merge', $code === 1
    && str_contains($out, 'Covered code MSI: 100.00% (210 of 210')
    && str_contains($out, 'FAILED: 1 mutants ended with an error')
    && str_contains($out, "Errors: 1\n  classes/local/b/deep/three.php:7 MethodCallRemoval"), "exit $code: $out");
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 10, 'killedCount' => 9, 'syntaxErrorCount' => 1]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a syntax error fails the merge', $code === 1 && str_contains($out, 'FAILED: 1 mutants ended with an error')
    && str_contains($out, 'Syntax errors: 1'), "exit $code: $out");

// A shard that tested nothing fails, also when the others tested enough: its filter found nothing.
secbk_shard_logs($folder, 'two', []);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a shard without mutants fails', $code === 1 && str_contains($out, 'FAILED: the shard two tested no mutant')
    && str_contains($out, 'Covered code MSI: 100.00% (200 of 200'), "exit $code: $out");
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 4, 'notCoveredCount' => 4]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a shard whose mutants are all not covered fails', $code === 1
    && str_contains($out, 'FAILED: the shard two tested no mutant'), "exit $code: $out");

// A json log that is missing, damaged or short of mutants cannot be used, even when the summary is whole.
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 10, 'killedCount' => 9, 'escapedCount' => 1]);
unlink("$folder/infection-two/infection.json");
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a missing json log cannot be used', $code === 2 && str_contains($out, 'infection-two/infection.json is missing'),
    "exit $code: $out");
file_put_contents("$folder/infection-two/infection.json", '{"escaped": [');
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a damaged json log cannot be used', $code === 2 && str_contains($out, 'infection-two/infection.json is missing'),
    "exit $code: $out");
file_put_contents("$folder/infection-two/infection.json", json_encode(['escaped' => [], 'timeouted' => [], 'uncovered' => [],
    'errored' => [], 'syntaxErrors' => []]));
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a json log that lists fewer mutants than the summary cannot be used', $code === 2
    && str_contains($out, 'does not list the 1 mutants of escapedCount in escaped'), "exit $code: $out");

// A skipped mutant fails the merge; a shard without its logs cannot be used; nothing tested fails.
secbk_shard_logs($folder, 'two', ['totalMutantsCount' => 10, 'killedCount' => 9, 'skippedCount' => 1]);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a skipped mutant fails', $code === 1 && str_contains($out, 'FAILED: 1 mutants were skipped'), "exit $code: $out");
unlink("$folder/infection-two/summary.json");
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('a missing shard cannot be used', $code === 2 && str_contains($out, 'infection-two/summary.json is missing'),
    "exit $code: $out");
secbk_shard_logs($folder, 'one', []);
secbk_shard_logs($folder, 'two', []);
[$code, $out] = secbk_run_tool('merge.php', escapeshellarg("--root=$root") . ' ' . escapeshellarg($folder));
secbk_expect('no tested mutant fails', $code === 1 && str_contains($out, 'FAILED: no mutant was tested'), "exit $code: $out");

foreach (array_reverse($trees) as $tree) {
    // Development tooling may run commands; the plugin itself never does.
    \exec('rm -rf ' . escapeshellarg($tree));
}
echo "infection tools: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
