#!/usr/bin/env bash
# Runs the trace tests of the chunk reader with OPcache, whose optimiser the other PHPUnit runs do not have.
# Moodle's PHPUnit bootstrap resets OPcache (string_manager_standard::reset_caches() calls opcache_reset()), and on the
# command line a reset stays pending until the process ends: no file loaded after it is cached or optimised, so the classes
# of the plugin run unoptimised. The optimiser matters here because it removes dead stores, such as an assignment that was
# meant to clear a parameter before an exception is made (an unset is kept). With a cache in files only, OPcache keeps
# compiling and optimising after the reset. The run fails unless OPcache did compile the reader, so that a PHP without
# OPcache cannot pass it unchecked.
# The job `test` of .github/workflows/ci.yml and .dev/ci/run.sh run it, from the Moodle root.
# Usage: phpunit-opcache.sh <cache folder> [command that runs a command where PHP is...]
#   in CI:     (cd moodle && phpunit-opcache.sh "$RUNNER_TEMP/opcache")
#   in Docker: phpunit-opcache.sh /tmp/secbk-opcache .dev/env/run.sh 503 pgsql
set -euo pipefail
cache="${1:?cache folder}"
shift
"$@" rm -rf "$cache"
"$@" mkdir -p "$cache"
# Every setting that the run depends on is given here, over any ini file: the CI job writes opcache.enable=0 and
# opcache.enable_cli=0 for its coverage, and OPcache does not start on the command line unless both are on. The level of the
# optimiser is the default one, set again: with level 0, OPcache would still compile the reader, and the dead stores would
# stay. auto_prepend_file is emptied: the coverage collector of the CI job must not record this run.
"$@" php -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.optimization_level=0x7FFEBFFF \
    -d opcache.file_update_protection=0 -d "opcache.file_cache=$cache" -d opcache.file_cache_only=1 \
    -d auto_prepend_file= vendor/bin/phpunit --no-coverage --fail-on-warning --testsuite tool_secbackup_testsuite \
    --filter 'test_the_traces_of_a_damaged_chunk_hide_the_keys|test_the_helper_finds_a_secret_in_a_sensitive_parameter'
compiled="$("$@" find "$cache" -path '*/classes/local/crypto/chunk_reader.php.bin')"
if [ -z "$compiled" ]; then
    echo "OPcache did not compile classes/local/crypto/chunk_reader.php, so the run above checked nothing" >&2
    exit 1
fi
echo "trace tests passed with OPcache"
