<?php
// Collects the lines executed in one web request or CLI run with pcov, for the coverage merge.
// Loaded through auto_prepend_file in development and CI only; not part of the plugin package.
// It does nothing unless .dev/coverage/out/raw/ENABLED exists (and, for CLI, SECBK_COVERAGE_CLI=1).
// It records the plugin's files by their place in Moodle (admin/tool/secbackup), wherever this copy of the
// collector lives: in CI, the collector of the checkout records the copy that moodle-plugin-ci installed.
// For every file the run loaded it writes the executed lines ("files") and the lines pcov counts as executable at all
// ("executable"): pcov does not count every line a static analysis calls a statement, so the merge needs both.
// A report that is lost or half written would make the merge count fewer lines, so a failure here is loud (an
// exception) and a report appears under its name only when it is complete: it is written under a temporary name
// first, and the merge reads *.json only.

(function (): void {
    $raw = __DIR__ . '/out/raw';
    if (!is_file($raw . '/ENABLED') || !extension_loaded('pcov')) {
        return;
    }
    if (PHP_SAPI === 'cli' && getenv('SECBK_COVERAGE_CLI') !== '1') {
        return;
    }
    \pcov\start();
    register_shutdown_function(static function () use ($raw): void {
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
        if ($executable === []) {
            return;
        }
        $json = json_encode(['files' => $executed, 'executable' => $executable], JSON_THROW_ON_ERROR);
        $target = $raw . '/' . (PHP_SAPI === 'cli' ? 'cli' : 'web') . '-' . bin2hex(random_bytes(8)) . '.json';
        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $json) !== strlen($json)) {
            @unlink($temporary);
            throw new \RuntimeException("The coverage collector cannot write $temporary");
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException("The coverage collector cannot rename $temporary to $target");
        }
    });
})();
