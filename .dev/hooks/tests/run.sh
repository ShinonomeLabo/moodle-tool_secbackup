#!/usr/bin/env bash
# Tests for the commit message and identity rules in ../lib/rules.sh.
set -u

here="$(cd "$(dirname "$0")" && pwd)"
patterns="$(mktemp)"
message="$(mktemp)"
trap 'rm -f "$patterns" "$message"' EXIT
printf 'forbiddenword\nsecond phrase\n' > "$patterns"
export SECBACKUP_PATTERNS_FILE="$patterns"
# shellcheck source=../lib/rules.sh
source "$here/../lib/rules.sh"

pass=0
fail=0

expect_message() {
    local expected="$1" label="$2" body="$3"
    printf '%s' "$body" > "$message"
    if secbackup_check_message "$message" 2>/dev/null; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL message: $label (expected $expected, got $actual)"; fi
}

expect_identity() {
    local expected="$1" label="$2" ident="$3"
    if secbackup_check_identity "$ident" 2>/dev/null; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL identity: $label (expected $expected, got $actual)"; fi
}

expect_message ok "Japanese subject" $'リポジトリの土台を用意する\n'
expect_message ok "Japanese subject with English identifiers" $'tool_secbackup の version.php を足す\n\n本文も日本語で書く。\n'
expect_message ng "English only" $'Add repository skeleton\n'
expect_message ng "empty" ''
expect_message ng "only comments" $'# Please enter the commit message\n'
expect_message ok "comment lines are ignored" $'日本語の件名\n# forbiddenword in a git comment line\n'
expect_message ng "pattern in subject" $'forbiddenword を足す\n'
expect_message ng "pattern in body, upper case" $'変更を足す\n\nFORBIDDENWORD\n'
expect_message ng "second pattern with a space" $'変更を足す\n\nSecond Phrase\n'
expect_message ok "similar but different word" $'変更を足す\n\nforbidden word\n'

# Fail closed: without the pattern list every message is rejected.
SECBACKUP_PATTERNS_FILE="$patterns.missing"
expect_message ng "missing pattern list" $'日本語の件名\n'
printf '' > "$patterns.empty"
SECBACKUP_PATTERNS_FILE="$patterns.empty"
expect_message ng "empty pattern list" $'日本語の件名\n'
rm -f "$patterns.empty"
SECBACKUP_PATTERNS_FILE="$patterns"

# The list may come from an editor or a secret: CRLF line endings, blanks around a pattern and a
# UTF-8 BOM at the start must not switch it off.
printf 'forbiddenword\r\nsecond phrase\r\n' > "$patterns.crlf"
SECBACKUP_PATTERNS_FILE="$patterns.crlf"
expect_message ng "CRLF pattern list" $'変更を足す\n\nSecond Phrase\n'
printf '\t forbiddenword \t\nsecond phrase\n' > "$patterns.blanks"
SECBACKUP_PATTERNS_FILE="$patterns.blanks"
expect_message ng "pattern with leading and trailing blanks" $'変更を足す\n\nforbiddenword\n'
printf '\xef\xbb\xbfforbiddenword\nsecond phrase\n' > "$patterns.bom"
SECBACKUP_PATTERNS_FILE="$patterns.bom"
expect_message ng "pattern list starting with a UTF-8 BOM" $'変更を足す\n\nforbiddenword\n'
# Fail closed: a list with no pattern left after normalising rejects every message.
printf ' \t\r\n\n   \r\n\t' > "$patterns.blank"
SECBACKUP_PATTERNS_FILE="$patterns.blank"
expect_message ng "pattern list with only blank and CR lines" $'日本語の件名\n'
rm -f "$patterns.crlf" "$patterns.blanks" "$patterns.bom" "$patterns.blank"
SECBACKUP_PATTERNS_FILE="$patterns"

expect_identity ok "configured identity" 'Takayuki Fuwa <fuwa@shinonome.co.jp>'
expect_identity ng "other e-mail" 'Takayuki Fuwa <fuwa@example.com>'
expect_identity ng "other name" 'Someone Else <fuwa@shinonome.co.jp>'
expect_identity ng "empty" ''

echo "rules: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
