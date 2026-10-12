<?php
// Collects the lines executed in one web request or CLI run with pcov, for the coverage merge.
// Loaded through auto_prepend_file in development and CI only; not part of the plugin package.
// It does nothing unless .dev/coverage/out/raw/ENABLED exists (and, for CLI, SECBK_COVERAGE_CLI=1).
// It records the plugin's files by their place in Moodle (admin/tool/secbackup), wherever this copy of the
// collector lives: in CI, the collector of the checkout records the copy that moodle-plugin-ci installed.
// For every file the run loaded it writes the executed lines ("files") and the lines pcov counts as executable at all
// ("executable"): pcov does not count every line a static analysis calls a statement, so the merge needs both.

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
        if ($executable !== []) {
            $kind = PHP_SAPI === 'cli' ? 'cli' : 'web';
            $json = json_encode(['files' => $executed, 'executable' => $executable]);
            file_put_contents($raw . '/' . $kind . '-' . bin2hex(random_bytes(8)) . '.json', $json);
        }
    });
})();
