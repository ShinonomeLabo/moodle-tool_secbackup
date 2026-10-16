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
 * Collects the lines executed in one web request or command line run with pcov, for the coverage merge.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Collects the lines executed in one web request or CLI run with pcov, for the coverage merge.
// Loaded through auto_prepend_file in development and CI only; not part of the plugin package.
// It does nothing unless .dev/coverage/out/raw/ENABLED exists (and, for CLI, SECBK_COVERAGE_CLI=1).
// It records the plugin's files by their place in Moodle (admin/tool/secbackup), wherever this copy of the
// collector lives: in CI, the collector of the checkout records the copy that moodle-plugin-ci installed.
// For every file the run loaded it writes the executed lines ("files") and the lines pcov counts as executable at all
// ("executable"): pcov does not count every line a static analysis calls a statement, so the merge needs both.
// A report that is lost or half written would make the merge count fewer lines, so nothing here may fail quietly:
// - a request makes an empty <name>.pending file before it runs, and takes it away only when its report is in place;
//   a request that cannot make the file fails at once, and run.sh stops when one is left (a failed write, a killed process);
// - a report appears under its name only when it is complete: it is written as <name>.json.tmp first, and the merge
//   reads *.json only;
// - a failure is an exception, not a warning.

(function (): void {
    $raw = __DIR__ . '/out/raw';
    if (!is_file($raw . '/ENABLED') || !extension_loaded('pcov')) {
        return;
    }
    if (PHP_SAPI === 'cli' && getenv('SECBK_COVERAGE_CLI') !== '1') {
        return;
    }
    $base = $raw . '/' . (PHP_SAPI === 'cli' ? 'cli' : 'web') . '-' . bin2hex(random_bytes(8));
    $pending = $base . '.pending';
    if (file_put_contents($pending, '') === false) {
        throw new \RuntimeException("The coverage collector cannot create $pending");
    }
    \pcov\start();
    register_shutdown_function(static function () use ($base, $pending): void {
        \pcov\stop();
        $executed = [];
        $executable = [];
        foreach (\pcov\collect(\pcov\inclusive, \pcov\waiting()) as $file => $lines) {
            if (preg_match('~/admin/tool/secbackup/~', $file) !== 1 || str_contains($file, '/.dev/')
                    || str_contains($file, '/tests/')) {
                continue;
            }
            $executable[$file] = array_keys($lines);
            $hit = array_keys(array_filter($lines, fn($count) => $count > 0));
            if ($hit !== []) {
                $executed[$file] = $hit;
            }
        }
        \pcov\clear();
        if ($executable !== []) {
            $json = json_encode(['files' => $executed, 'executable' => $executable], JSON_THROW_ON_ERROR);
            $temporary = $base . '.json.tmp';
            if (file_put_contents($temporary, $json) !== strlen($json)) {
                @unlink($temporary);
                throw new \RuntimeException("The coverage collector cannot write $temporary");
            }
            if (!rename($temporary, $base . '.json')) {
                @unlink($temporary);
                throw new \RuntimeException("The coverage collector cannot rename $temporary to $base.json");
            }
        }
        if (!unlink($pending)) {
            throw new \RuntimeException("The coverage collector cannot remove $pending");
        }
    });
})();
