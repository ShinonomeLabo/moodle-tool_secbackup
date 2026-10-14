<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Tests for .dev/ci/badge.php. Run: php .dev/coverage/tests/badge.php
// Development only; not part of the plugin package.

$badge = escapeshellarg(dirname(__DIR__, 2) . '/ci/badge.php');
$work = sys_get_temp_dir() . '/secbk-badge-test-' . getmypid();
mkdir($work);
$pass = 0;
$fail = 0;

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

/**
 * Runs badge.php on a summary text and returns [exit code, output of both streams, the SVG or null].
 *
 * @param string $summary The content of the summary file.
 * @return array
 */
function secbk_badge(string $summary): array {
    global $badge, $work;
    $in = "$work/summary.json";
    $out = "$work/badge.svg";
    @unlink($out);
    file_put_contents($in, $summary);
    $lines = [];
    $code = 0;
    // Development tooling may run commands; the plugin itself never does.
    \exec("php $badge " . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines), is_file($out) ? file_get_contents($out) : null];
}

// Every line covered: green, 100.00%.
[$code, $output, $svg] = secbk_badge('{"covered": 1554, "total": 1554}');
secbk_expect('full coverage is accepted', $code === 0 && $output === '' && $svg !== null, $output);
secbk_expect('full coverage shows 100.00%', str_contains((string) $svg, '>100.00%</text>'), (string) $svg);
secbk_expect('full coverage is green', str_contains((string) $svg, '#1a7f37') && !str_contains((string) $svg, '#c62828'), (string) $svg);
secbk_expect('the label is coverage', str_contains((string) $svg, '>coverage</text>'), (string) $svg);

// The SVG is plain: a title for screen readers, and no script and no reference to another file or address.
secbk_expect('has a title', str_contains((string) $svg, '<title>coverage: 100.00%</title>'), (string) $svg);
secbk_expect('has an accessible label', str_contains((string) $svg, 'role="img" aria-label="coverage: 100.00%"'), (string) $svg);
foreach (['<script', 'href', 'url(', '<image', '<use', '@import', '<style', '<foreignObject', 'onload', 'xlink'] as $forbidden) {
    secbk_expect("has no $forbidden", !str_contains((string) $svg, $forbidden), (string) $svg);
}
$addresses = preg_match_all('~[a-z][a-z0-9+.-]*://[^"\s]+~i', (string) $svg, $found) ? $found[0] : [];
secbk_expect('the only address is the SVG namespace', $addresses === ['http://www.w3.org/2000/svg'], implode(', ', $addresses));
$xml = new DOMDocument();
secbk_expect('is well-formed XML', $xml->loadXML((string) $svg) !== false, (string) $svg);

// Below 100%: red, rounded down.
[$code, $output, $svg] = secbk_badge('{"covered": 99999, "total": 100000}');
secbk_expect('99.999% shows 99.99%, not 100.00%', $code === 0 && str_contains((string) $svg, '>99.99%</text>'), (string) $svg);
secbk_expect('below 100% is red', str_contains((string) $svg, '#c62828') && !str_contains((string) $svg, '#1a7f37'), (string) $svg);

[$code, $output, $svg] = secbk_badge('{"covered": 1553, "total": 1554}');
secbk_expect('one line missing shows 99.93%', $code === 0 && str_contains((string) $svg, '>99.93%</text>'), (string) $svg);

[$code, $output, $svg] = secbk_badge('{"covered": 1, "total": 3}');
secbk_expect('a third shows 33.33%, rounded down', str_contains((string) $svg, '>33.33%</text>'), (string) $svg);

[$code, $output, $svg] = secbk_badge('{"covered": 2, "total": 3}');
secbk_expect('two thirds shows 66.66%, rounded down', str_contains((string) $svg, '>66.66%</text>'), (string) $svg);

[$code, $output, $svg] = secbk_badge('{"covered": 0, "total": 5}');
secbk_expect('nothing covered shows 0.00% in red', $code === 0 && str_contains((string) $svg, '>0.00%</text>')
    && str_contains((string) $svg, '#c62828'), (string) $svg);

[$code, $output, $svg] = secbk_badge('{"total": 8, "covered": 8}');
secbk_expect('the order of the keys does not matter', $code === 0 && str_contains((string) $svg, '>100.00%</text>'), (string) $svg);

// A value that is not exactly what merge.php writes is refused with a message and without a file.
$refused = [
    'a file that is not JSON' => 'covered=1',
    'an empty file' => '',
    'a JSON text that is not an object' => '"100%"',
    'a list' => '[1, 2]',
    'an empty object' => '{}',
    'a missing key total' => '{"covered": 1}',
    'a missing key covered' => '{"total": 1}',
    'another key' => '{"covered": 1, "total": 1, "percent": 100}',
    'another key instead of one' => '{"covered": 1, "totals": 1}',
    'a string number' => '{"covered": "1", "total": 1}',
    'a float' => '{"covered": 1.0, "total": 1}',
    'a float total' => '{"covered": 1, "total": 2.5}',
    'a boolean' => '{"covered": true, "total": 1}',
    'null' => '{"covered": null, "total": 1}',
    'an array as a number' => '{"covered": [1], "total": 1}',
    'a negative covered' => '{"covered": -1, "total": 5}',
    'a negative total' => '{"covered": 0, "total": -5}',
    'covered above total' => '{"covered": 6, "total": 5}',
    'a total of zero' => '{"covered": 0, "total": 0}',
    'a number beyond the integers' => '{"covered": 1, "total": 99999999999999999999}',
    'markup in a value' => '{"covered": "<script>", "total": 1}',
];
foreach ($refused as $label => $content) {
    [$code, $output, $svg] = secbk_badge($content);
    secbk_expect("$label exits 1 with a message", $code === 1 && $output !== '', "exit $code: $output");
    secbk_expect("$label writes no badge", $svg === null, (string) $svg);
}

// A missing summary and a wrong number of arguments.
$lines = [];
\exec("php $badge $work/does-not-exist.json $work/badge.svg 2>&1", $lines, $code);
secbk_expect('a missing summary exits 1 and names it', $code === 1 && str_contains(implode("\n", $lines), 'does-not-exist.json'), implode("\n", $lines));
$lines = [];
\exec("php $badge 2>&1", $lines, $code);
secbk_expect('no arguments exit 1 with the usage', $code === 1 && str_contains(implode("\n", $lines), 'usage'), implode("\n", $lines));

// An output that cannot be written exits 1.
file_put_contents("$work/summary.json", '{"covered": 1, "total": 1}');
$lines = [];
\exec("php $badge " . escapeshellarg("$work/summary.json") . ' ' . escapeshellarg("$work/no-such-folder/badge.svg") . ' 2>&1', $lines, $code);
secbk_expect('an output that cannot be written exits 1', $code === 1 && str_contains(implode("\n", $lines), 'cannot write'), implode("\n", $lines));

// badge.php prints nothing when a web server runs it.
$cgi = [];
\exec('command -v php-cgi 2>/dev/null', $cgi);
if ($cgi === []) {
    echo "SKIP: php-cgi was not found, so the refusal to run outside the command line is not tested\n";
} else {
    $lines = [];
    \exec(escapeshellarg($cgi[0]) . " -q -f $badge 2>&1", $lines, $code);
    secbk_expect('badge.php refuses to run outside the command line', $code === 1 && implode('', $lines) === '', "exit $code: " . implode("\n", $lines));
}

foreach (glob("$work/*") ?: [] as $file) {
    @unlink($file);
}
@rmdir($work);
echo "badge: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
