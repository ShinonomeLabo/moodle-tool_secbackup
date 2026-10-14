<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Draws the coverage badge from the numbers that .dev/coverage/merge.php --summary writes.
// Development only; not part of the plugin package.
//
// Usage: php badge.php <summary.json> <out.svg>
//
// The summary must be an object with exactly two keys, "covered" and "total", both integers, with 0 <= covered <= total and
// total > 0. Anything else is refused (exit code 1, a message on standard error, no file written), because a badge that
// shows a number it could not check would be worse than no badge. The percentage is rounded down, so that 99.999% never
// shows as 100.00%. The badge is green only when every line is covered, and red otherwise. It is a plain SVG: rectangles
// and text, a title for screen readers, no script and no reference to another file or address.

/**
 * Stops with a message on standard error.
 *
 * @param string $message What is wrong.
 */
function secbk_badge_refuse(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if ($argc !== 3) {
    secbk_badge_refuse('usage: badge.php <summary.json> <out.svg>');
}
[, $input, $output] = $argv;

$text = is_file($input) ? @file_get_contents($input) : false;
if ($text === false) {
    secbk_badge_refuse("cannot read $input");
}
try {
    $summary = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    secbk_badge_refuse("$input: not valid JSON ({$e->getMessage()})");
}
if (!is_array($summary) || array_is_list($summary)) {
    secbk_badge_refuse("$input: an object with \"covered\" and \"total\" is expected");
}
$keys = array_keys($summary);
sort($keys);
if ($keys !== ['covered', 'total']) {
    secbk_badge_refuse("$input: exactly the keys \"covered\" and \"total\" are expected");
}
$covered = $summary['covered'];
$total = $summary['total'];
if (!is_int($covered) || !is_int($total)) {
    secbk_badge_refuse("$input: \"covered\" and \"total\" must be integers");
}
if ($covered < 0 || $total <= 0) {
    secbk_badge_refuse("$input: \"covered\" must not be negative and \"total\" must be above zero");
}
if ($covered > $total) {
    secbk_badge_refuse("$input: \"covered\" is above \"total\"");
}

// Hundredths of a percent, rounded down, in integers only.
$hundredths = intdiv($covered * 10000, $total);
$value = sprintf('%d.%02d%%', intdiv($hundredths, 100), $hundredths % 100);
$colour = $covered === $total ? '#1a7f37' : '#c62828';
$label = 'coverage';
$labelwidth = 62;
$valuewidth = 8 * strlen($value) + 8;
$width = $labelwidth + $valuewidth;
$description = "$label: $value";

$labelcentre = intdiv($labelwidth, 2);
$valuecentre = $labelwidth + intdiv($valuewidth, 2);

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$width" height="20" role="img" aria-label="$description">
<title>$description</title>
<rect width="$labelwidth" height="20" fill="#555555"/>
<rect x="$labelwidth" width="$valuewidth" height="20" fill="$colour"/>
<g fill="#ffffff" font-family="Verdana, DejaVu Sans, sans-serif" font-size="11" text-anchor="middle">
<text x="$labelcentre" y="14">$label</text>
<text x="$valuecentre" y="14">$value</text>
</g>
</svg>

SVG;
if (file_put_contents($output, $svg) !== strlen($svg)) {
    secbk_badge_refuse("cannot write $output");
}
