#!/usr/bin/env bash
# Tests for .dev/coverage/collector.php in the web server container of one environment (the container has pcov).
# Usage: bash .dev/coverage/tests/collector.sh <ver> <db> [collector file in the plugin, default .dev/coverage/collector.php]
# A throwaway plugin tree is made under /tmp in the container and removed again; the environment itself is not touched.
# Development only; not part of the plugin package.
set -u
repo="$(cd "$(dirname "$0")/../../.." && pwd)"
# shellcheck source=../../env/env.sh
source "$repo/.dev/env/env.sh" "${1:?ver}" "${2:?db}"
collector="/var/www/html/$SECBK_PLUGIN_PATH/${3:-.dev/coverage/collector.php}"
pass=0
fail=0

# expect <label> <wanted> <got>
expect() {
    if [ "$2" = "$3" ]; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1))
        echo "FAIL: $1"
        echo "   wanted: $2"
        echo "   got:    $3"
    fi
}

# The container runs every case in a fresh tree and prints "<case> <fact>=<value>" lines.
# (php -r does not run auto_prepend_file, so the requests are script files.)
# shellcheck disable=SC2016
script='
base=/tmp/covtest/admin/tool/secbackup
raw=$base/.dev/coverage/out/raw
fresh() {
    rm -rf /tmp/covtest
    mkdir -p $raw $base/classes
    cp "@COLLECTOR@" $base/.dev/coverage/collector.php
    printf "<?php\n\$x = 1;\n" > $base/classes/good.php
    printf "<?php\n\$x = 1;\n" > "$base/classes/bad$(printf "\377").php"
    printf "<?php\nrequire \"$base/classes/good.php\";\necho \"done\\n\";\n" > /tmp/covtest/good.php
    printf "<?php\nrequire \"$base/classes/bad\\xff.php\";\necho \"done\\n\";\n" > /tmp/covtest/bad.php
    printf "<?php\necho \"done\\n\";\n" > /tmp/covtest/none.php
    printf "<?php\nchmod(\"$raw\", 0555);\nrequire \"$base/classes/good.php\";\necho \"done\\n\";\n" > /tmp/covtest/lock.php
    chmod -R a+rX /tmp/covtest
}
# request <case> <user> <enabled> <cli flag> <script> [owner: the web server user owns the folder of the reports]
request() {
    fresh
    [ "$3" = yes ] && touch $raw/ENABLED
    [ "${6:-}" = owner ] && chown www-data $raw
    chmod -R a+rX /tmp/covtest
    cmd="SECBK_COVERAGE_CLI=$4 php -d pcov.enabled=1 -d pcov.directory=/tmp/covtest -d auto_prepend_file=$base/.dev/coverage/collector.php /tmp/covtest/$5.php"
    if [ "$2" = root ]; then sh -c "$cmd" > /tmp/covtest/out.txt 2>&1; else su -s /bin/sh www-data -c "$cmd" > /tmp/covtest/out.txt 2>&1; fi
    echo "$1 exit=$?"
    echo "$1 raw=$(ls -A $raw | sed "s/[0-9a-f]\{16\}/X/g" | tr "\n" " ")"
    echo "$1 json=$(for f in $raw/*.json; do [ -e "$f" ] && php -r "json_decode(file_get_contents(\$argv[1]), true, 512, JSON_THROW_ON_ERROR); echo \"valid\";" "$f"; done)"
    echo "$1 said=$(tr "\n" " " < /tmp/covtest/out.txt | sed "s#$raw/##g; s/[0-9a-f]\{16\}/X/g" | cut -c1-260)"
}
request good root yes 1 good
request badname root yes 1 bad
request unwritable www yes 1 good
request writefail www yes 1 lock owner
request noplugin root yes 1 none
request disabled root no 1 good
request noflag root yes 0 good
rm -rf /tmp/covtest
'
facts="$("$repo/.dev/env/run.sh" "$secbk_ver" "$secbk_db" sh <<< "${script//@COLLECTOR@/$collector}" 2>&1)"
fact() { # <case> <name>
    sed -n "s/^$1 $2=//p" <<< "$facts"
}

expect "a good request: the process ends well" "0" "$(fact good exit)"
expect "a good request: a report and no marker or temporary file" "ENABLED cli-X.json " "$(fact good raw)"
expect "a good request: the report is valid JSON" "valid" "$(fact good json)"

# json_encode() cannot encode the name of the file (not valid UTF-8): the request fails, the marker stays, no report.
expect "a report that cannot be made: the request fails" "255" "$(fact badname exit)"
expect "a report that cannot be made: the marker stays, there is no report" "ENABLED cli-X.pending " "$(fact badname raw)"

# The folder of the reports cannot be written (the web server's user): the request fails at once, before it runs.
expect "no marker can be made: the request fails" "255" "$(fact unwritable exit)"
expect "no marker can be made: nothing is left" "ENABLED " "$(fact unwritable raw)"
expect "no marker can be made: the message says so" "yes" \
    "$([[ "$(fact unwritable said)" == *"cannot create"*".pending"* ]] && echo yes || echo no)"

# The marker is made, then the folder becomes read-only: the report cannot be written. The request fails and the marker
# stays, so that run.sh can tell that a report is missing.
expect "a report that cannot be written: the request fails" "255" "$(fact writefail exit)"
expect "a report that cannot be written: the marker stays, there is no report" "ENABLED cli-X.pending " "$(fact writefail raw)"
expect "a report that cannot be written: the message says so" "yes" \
    "$([[ "$(fact writefail said)" == *"cannot write"* ]] && echo yes || echo no)"

expect "a request without a plugin file leaves nothing" "ENABLED " "$(fact noplugin raw)"
expect "a request without a plugin file ends well" "0" "$(fact noplugin exit)"
expect "without ENABLED nothing is recorded" "" "$(fact disabled raw)"
expect "a CLI run without SECBK_COVERAGE_CLI records nothing" "ENABLED " "$(fact noflag raw)"

echo "collector: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
