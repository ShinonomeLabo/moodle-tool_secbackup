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
 * The shards of the Infection run on GitHub (.dev/infection/shards.txt): lists them, gives the filter of one, checks them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Development only; not part of the plugin package.
//
// Usage: php shards.php [--root=<plugin>] list            the names of the shards, as a JSON list (the matrix of the workflow)
//        php shards.php [--root=<plugin>] filter <shard>  the files of one shard, separated by commas (Infection's --filter)
//        php shards.php [--root=<plugin>] check           every PHP file of the source folders of infection.json5 is in
//                                                          exactly one shard, and every file of a shard is such a file
// Infection's --filter keeps a file when one of the filters is a part of its real path. Each filter here is a whole path
// from the plugin root (classes/...), and no such path is a part of another one (each starts at classes/ and ends with
// .php), so a filter keeps exactly its file. The check compares the paths in the same way.
// Exit code 0 when it worked, 1 when the check failed or the shard is unknown, 2 when an input cannot be used.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } else {
        $args[] = $arg;
    }
}
$command = $args[0] ?? '';
if (!in_array($command, ['list', 'filter', 'check'], true) || ($command === 'filter') !== (count($args) === 2)) {
    fwrite(STDERR, "usage: shards.php [--root=<plugin>] list | filter <shard> | check\n");
    exit(2);
}

/**
 * Stops with exit code 2 and a message.
 *
 * @param string $message What is wrong.
 */
function secbk_shards_input_error(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(2);
}

// The shards, in the order of the file: name => list of files.
$listfile = $root . '/.dev/infection/shards.txt';
$lines = @file($listfile, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    secbk_shards_input_error("$listfile cannot be read");
}
$shards = [];
foreach ($lines as $number => $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (!preg_match('~^([a-z0-9-]+) (classes/[a-z0-9_/]+\.php)$~', $line, $m)) {
        secbk_shards_input_error("$listfile line " . ($number + 1) . ' is not "<shard> <classes/....php>": ' . $line);
    }
    $shards[$m[1]][] = $m[2];
}
if ($shards === []) {
    secbk_shards_input_error("$listfile names no shard");
}

if ($command === 'list') {
    echo json_encode(array_keys($shards)), "\n";
    exit(0);
}
if ($command === 'filter') {
    if (!isset($shards[$args[1]])) {
        fwrite(STDERR, "unknown shard: {$args[1]}\n");
        exit(1);
    }
    echo implode(',', $shards[$args[1]]), "\n";
    exit(0);
}

// The source folders of infection.json5. The file is JSON5 (with comments), so the list is taken out with a pattern.
$configfile = $root . '/.dev/infection/infection.json5';
$config = @file_get_contents($configfile);
if ($config === false || !preg_match('~"directories"\s*:\s*\[([^\]]*)\]~', $config, $m)) {
    secbk_shards_input_error("$configfile cannot be read or has no source directories");
}
preg_match_all('~"([^"]+)"~', $m[1], $found);
$directories = $found[1];
if ($directories === []) {
    secbk_shards_input_error("$configfile lists no source directory");
}
$sources = [];
foreach ($directories as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        secbk_shards_input_error("source directory $directory is not in $root");
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory,
        FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($sources);

$problems = [];
foreach ($sources as $source) {
    $in = [];
    foreach ($shards as $name => $files) {
        foreach ($files as $filter) {
            if (str_contains('/' . $source, '/' . $filter)) {
                $in[] = $name;
            }
        }
    }
    if (count($in) !== 1) {
        $problems[] = "$source is in " . (count($in) === 0 ? 'no shard' : count($in) . ' shards (' . implode(', ', $in) . ')');
    }
}
foreach ($shards as $name => $files) {
    foreach ($files as $file) {
        if (!in_array($file, $sources, true)) {
            $problems[] = "$file of the shard $name is not a PHP file of the source directories";
        }
    }
}
if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo 'shards ok (' . count($shards) . ' shards, ' . count($sources) . " files)\n";
exit(0);
