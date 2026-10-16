#!/usr/bin/env bash
# Checks that the coverage of every combination of the job `test` arrived, before the job `coverage` merges it: one folder
# coverage-run-<Moodle>-<PHP>-<database> for each row of the matrix of ci.yml (read by matrix.sh --list), each with its PHPUnit
# report, and no other folder of that name. A combination whose coverage is missing (an artifact that expired, a job run again
# alone) would otherwise be left out of the merge, which could still count 100%.
# Usage: check-coverage-runs.sh <folder of the downloaded artifacts>    (SECBK_CI_YML names another ci.yml, as for matrix.sh)
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
dir="${1:?usage: check-coverage-runs.sh <folder of the downloaded artifacts>}"
# The names that the step "Upload the coverage" of the job `test` gives: coverage-run-${{ matrix.moodle }}-${{ matrix.php }}-
# ${{ matrix.database }}, where matrix.sh prints the Moodle version as 503 for 5.3.
if ! combinations="$(bash "$here/matrix.sh" --list)"; then
    echo "The combinations of the job test could not be read from ci.yml." >&2
    exit 1
fi
expected="$(while read -r ver db php; do
    printf 'coverage-run-%s.%s-%s-%s\n' "${ver:0:1}" "${ver:2:1}" "$php" "$db"
done <<< "$combinations" | LC_ALL=C sort)"
if ! found="$(find "$dir" -mindepth 1 -maxdepth 1 -name 'coverage-run-*' -print)"; then
    echo "The folder $dir could not be read." >&2
    exit 1
fi
found="$(printf '%s\n' "$found" | sed -e 's|^.*/||' -e '/^$/d' | LC_ALL=C sort)"
status=0
missing="$(LC_ALL=C comm -23 <(printf '%s\n' "$expected") <(printf '%s\n' "$found") | sed '/^$/d')"
extra="$(LC_ALL=C comm -13 <(printf '%s\n' "$expected") <(printf '%s\n' "$found") | sed '/^$/d')"
if [ -n "$missing" ]; then
    echo "No coverage arrived from these combinations:" >&2
    printf '  %s\n' $missing >&2
    status=1
fi
if [ -n "$extra" ]; then
    echo "These folders are not a combination of the job test:" >&2
    printf '  %s\n' $extra >&2
    status=1
fi
while read -r name; do
    [ -n "$name" ] || continue
    if [ ! -f "$dir/$name/phpunit.xml" ]; then
        echo "The coverage of $name has no PHPUnit report (phpunit.xml)." >&2
        status=1
    fi
done <<< "$found"
count="$(printf '%s\n' "$found" | sed '/^$/d' | wc -l | tr -d ' ')"
total="$(printf '%s\n' "$expected" | sed '/^$/d' | wc -l | tr -d ' ')"
echo "coverage of ${count} combinations, ${total} in the matrix"
exit "$status"
