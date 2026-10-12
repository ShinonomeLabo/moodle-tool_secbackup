#!/usr/bin/env bash
# Tests for the commit hooks: the message and identity rules in ../lib/rules.sh, and the fail-closed
# behaviour of ../pre-push and ../check-history.sh when a tool they depend on fails, and ../install.sh.
set -u

here="$(cd "$(dirname "$0")" && pwd)"
patterns="$(mktemp)"
message="$(mktemp)"
work="$(mktemp -d)"
trap 'rm -f "$patterns" "$message"; rm -rf "$work"' EXIT
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
expect_message ng "# lines are searched too (git keeps them for -m and -F)" $'日本語の件名\n# forbiddenword in a git comment line\n'
# git removes "#" lines only when the message is edited; for -m and -F it keeps them. So a word on a "#"
# line is a word of the message, while the empty and Japanese checks still ignore those lines.
expect_message ok "# line without a forbidden word is fine" $'日本語の件名\n# メモ\n'
expect_message ng "Japanese only in a # line does not count" $'Add a change\n# 日本語だけの行\n'
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
# Fail closed: a list that is not plain UTF-8 text is unreadable, because words read from it could
# silently not match. That covers UTF-16, an invalid byte sequence, and NUL or any other control
# character except tab, CR and LF.
perl -e 'print "\xff\xfe", map { "$_\0" } split //, "forbiddenword\nsecond phrase\n"' > "$patterns.bin"
SECBACKUP_PATTERNS_FILE="$patterns.bin"
expect_message ng "UTF-16 pattern list" $'日本語の件名\n'
printf 'caf\xe9\nforbiddenword\nsecond phrase\n' > "$patterns.bin"
expect_message ng "pattern list with an invalid UTF-8 byte sequence" $'日本語の件名\n'
for bytes in '\0' '\x1b' '\x7f' '\xc2\x85'; do
    printf 'forbiddenword%b\nsecond phrase\n' "$bytes" > "$patterns.bin"
    expect_message ng "pattern list with the control character $bytes" $'日本語の件名\n'
done
# Only printable ASCII (0x20-0x7E) is accepted in a word, after the trimming: a full-width space, a
# no-break space, a zero-width character, a non-ASCII word or a tab inside a word could make a word
# silently not match, so such a list is unreadable. The bytes are written as escapes on purpose.
printf 'forbiddenword\xe3\x80\x80\nsecond phrase\n' > "$patterns.bin"
expect_message ng "word followed by a full-width space (U+3000)" $'日本語の件名\n'
printf 'forbiddenword\xc2\xa0\nsecond phrase\n' > "$patterns.bin"
expect_message ng "word followed by a no-break space (U+00A0)" $'日本語の件名\n'
printf 'forbidden\xe2\x80\x8bword\nsecond phrase\n' > "$patterns.bin"
expect_message ng "word with a zero-width space (U+200B) inside" $'日本語の件名\n'
printf '\xe7\x94\x9f\xe6\x88\x90\nsecond phrase\n' > "$patterns.bin"
expect_message ng "non-ASCII word (valid UTF-8, but only printable ASCII is accepted)" $'日本語の件名\n'
printf 'second\tphrase\n' > "$patterns.bin"
expect_message ng "tab inside a word" $'日本語の件名\n'
all="a$(perl -e 'print join("", map { chr } 0x20..0x7e)')z"
printf '%s\n' "$all" > "$patterns.bin"
expect_message ok "word made of every printable ASCII character" $'日本語の件名\n'
expect_message ng "message containing that word" "$(printf '日本語の件名\n\n%s' "$all")"
rm -f "$patterns.crlf" "$patterns.blanks" "$patterns.bom" "$patterns.blank" "$patterns.bin"
SECBACKUP_PATTERNS_FILE="$patterns"

expect_identity ok "configured identity" 'Takayuki Fuwa <fuwa@shinonome.co.jp>'
expect_identity ng "other e-mail" 'Takayuki Fuwa <fuwa@example.com>'
expect_identity ng "other name" 'Someone Else <fuwa@shinonome.co.jp>'
expect_identity ng "empty" ''

# Fail closed: a tool that fails must never let a message, a commit or a push through. The tools
# (grep, tr, git) are replaced by test doubles that fail on request, so nothing below depends on the
# locale or on the state of the machine.
zeros="$(printf '%040d' 0)"
ones="${zeros//0/1}"
mkdir "$work/fakebin" || exit 2
FAKE_REAL_GREP="$(command -v grep)"
FAKE_REAL_TR="$(command -v tr)"
FAKE_REAL_GIT="$(command -v git)"
export FAKE_REAL_GREP FAKE_REAL_TR FAKE_REAL_GIT
cat > "$work/fakebin/grep" <<'EOF'
#!/usr/bin/env bash
# grep that fails on request. Mode fail: the word search (-qiF) exits with 2. Mode strip: the comment
# stripping (-v) prints one line and then exits with 2. Mode bytes: a call that does not run
# byte-wise (LC_ALL=C) exits with 2.
case "${FAKE_GREP_MODE-}" in
    bytes) [ "${LC_ALL-}" = C ] || exit 2 ;;
    fail) for argument in "$@"; do [ "$argument" = -qiF ] && exit 2; done ;;
    strip) for argument in "$@"; do [ "$argument" = -v ] && { head -n 1 "${!#}"; exit 2; }; done ;;
esac
exec "$FAKE_REAL_GREP" "$@"
EOF
cat > "$work/fakebin/tr" <<'EOF'
#!/usr/bin/env bash
# tr that fails on request. Mode partial: the pattern list (tr '\r' ...) is cut after one line and the
# call fails. Mode bytes: a call that does not run byte-wise (LC_ALL=C) fails.
case "${FAKE_TR_MODE-}" in
    bytes) [ "${LC_ALL-}" = C ] || exit 1 ;;
    partial)
        if [ "${1-}" = '\r' ]; then
            "$FAKE_REAL_TR" "$@" | head -n 1
            exit 1
        fi
        ;;
esac
exec "$FAKE_REAL_TR" "$@"
EOF
cat > "$work/fakebin/git" <<'EOF'
#!/usr/bin/env bash
# git that fails on request: the Nth "git log -1 --format=%B" call prints a first line and exits with 1.
if [ "${1-}" = log ] && [ "${3-}" = '--format=%B' ] && [ -n "${FAKE_GIT_FAIL_ON-}" ]; then
    count=$(( $(cat "$FAKE_GIT_COUNTER" 2>/dev/null || echo 0) + 1 ))
    echo "$count" > "$FAKE_GIT_COUNTER"
    if [ "$count" -eq "$FAKE_GIT_FAIL_ON" ]; then
        echo '日本語の件名'
        exit 1
    fi
fi
exec "$FAKE_REAL_GIT" "$@"
EOF
chmod +x "$work/fakebin/grep" "$work/fakebin/tr" "$work/fakebin/git"

# Like expect_message, but secbackup_check_message runs in a child shell with the given environment.
expect_message_env() {
    local expected="$1" label="$2" body="$3"
    shift 3
    printf '%s' "$body" > "$message"
    # shellcheck disable=SC2016
    if env "$@" "$BASH" -c 'source "$1/../lib/rules.sh"; secbackup_check_message "$2"' _ "$here" "$message" 2>/dev/null; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL message: $label (expected $expected, got $actual)"; fi
}

# Runs a hook script in a repository with the given standard input and extra environment assignments.
run_hook() {
    local repo="$1" script="$2" input="$3"
    shift 3
    (cd "$repo" && printf '%s' "$input" | env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE GIT_CEILING_DIRECTORIES="$work" "$@" "$BASH" "$here/../$script" origin remote.test)
}

expect_hook() {
    local expected="$1" label="$2"
    shift 2
    if run_hook "$@" >/dev/null 2>&1; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL hook: $label (expected $expected, got $actual)"; fi
}

# Commits for the temporary repositories: no hooks, no signing, no user configuration involved.
commit_in() {
    env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE \
        GIT_AUTHOR_NAME='Takayuki Fuwa' GIT_AUTHOR_EMAIL='fuwa@shinonome.co.jp' \
        GIT_COMMITTER_NAME='Takayuki Fuwa' GIT_COMMITTER_EMAIL='fuwa@shinonome.co.jp' \
        git -C "$1" -c core.hooksPath=/dev/null -c commit.gpgsign=false commit -q --allow-empty -m "$2"
}

good="$work/good"
bad="$work/bad"
hashed="$work/hashed"
for repo in "$good" "$bad" "$hashed"; do git init -q "$repo" 2>/dev/null || exit 2; done
commit_in "$good" '最初の変更を足す' || exit 2
commit_in "$good" '次の変更を足す' || exit 2
commit_in "$bad" '最初の変更を足す' || exit 2
commit_in "$bad" 'Add a second change' || exit 2
commit_in "$hashed" '最初の変更を足す' || exit 2
commit_in "$hashed" $'次の変更を足す\n\n# forbiddenword on a # line' || exit 2
git -c protocol.file.allow=always clone -q --depth 1 "file://$good" "$work/shallow" 2>/dev/null || exit 2
mkdir "$work/notrepo" || exit 2
printf -v push_good 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$good" rev-parse HEAD)" "$zeros"
printf -v push_bad 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$bad" rev-parse HEAD)" "$zeros"
printf -v push_hashed 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$hashed" rev-parse HEAD)" "$zeros"
printf -v push_bad_unterminated 'refs/heads/main %s refs/heads/main %s' "$(git -C "$bad" rev-parse HEAD)" "$zeros"
printf -v push_unknown 'refs/heads/main %s refs/heads/main %s\n' "$ones" "$zeros"

# The word search: when grep itself fails (status 2) the message is rejected, and every search and
# every cut of the text runs byte-wise (LC_ALL=C) whatever the locale of the caller is.
expect_message_env ng "word search that fails" $'日本語の件名\n' PATH="$work/fakebin:$PATH" FAKE_GREP_MODE=fail
expect_message_env ok "searches run byte-wise" $'日本語の件名\n' PATH="$work/fakebin:$PATH" FAKE_GREP_MODE=bytes FAKE_TR_MODE=bytes LC_ALL=POSIX
# A message or a pattern list that was read only in part must not be used.
expect_message_env ng "comment stripping that fails half-way" $'日本語の件名\n\nforbiddenword\n' PATH="$work/fakebin:$PATH" FAKE_GREP_MODE=strip
expect_message_env ng "pattern list read that fails half-way" $'変更を足す\n\nsecond phrase\n' PATH="$work/fakebin:$PATH" FAKE_TR_MODE=partial

# pre-push and check-history.sh read the history with git; when that fails they must fail too.
expect_hook ok "pre-push of a good commit" "$good" pre-push "$push_good"
expect_hook ng "pre-push of a commit with an English message" "$bad" pre-push "$push_bad"
expect_hook ng "pre-push with an unknown object id" "$good" pre-push "$push_unknown"
expect_hook ng "pre-push input whose last line has no newline" "$bad" pre-push "$push_bad_unterminated"
printf 0 > "$work/counter"
expect_hook ng "pre-push, message of the 2nd commit read in part" "$good" pre-push "$push_good" PATH="$work/fakebin:$PATH" FAKE_GIT_FAIL_ON=2 FAKE_GIT_COUNTER="$work/counter"
expect_hook ok "check-history of a good history" "$good" check-history.sh ''
expect_hook ng "check-history with an English commit" "$bad" check-history.sh ''
# A stored message is final text: git has already decided which "#" lines stay, so the words are
# searched in all of its lines.
expect_hook ng "pre-push, forbidden word on a # line of a stored message" "$hashed" pre-push "$push_hashed"
expect_hook ng "check-history, forbidden word on a # line of a stored message" "$hashed" check-history.sh ''
expect_hook ng "check-history outside a repository" "$work/notrepo" check-history.sh ''
expect_hook ng "check-history of a shallow clone" "$work/shallow" check-history.sh ''
printf 0 > "$work/counter"
expect_hook ng "check-history, message of the 2nd commit read in part" "$good" check-history.sh '' PATH="$work/fakebin:$PATH" FAKE_GIT_FAIL_ON=2 FAKE_GIT_COUNTER="$work/counter"

# install.sh needs perl (the hooks use it) and stops with a clear message when it is missing; with perl it
# enables the hooks of the repository it lives in. Both run in a throw-away copy, never on this repository.
mkdir "$work/empty" || exit 2
install_stops_without_perl() {
    local out rc
    out="$(env -i PATH="$work/empty" "$BASH" "$here/../install.sh" 2>&1)"; rc=$?
    [ "$rc" -eq 1 ] || return 1
    case "$out" in *perl*) return 0 ;; *) return 1 ;; esac
}
install_enables_the_hooks() {
    local copy="$work/install" out
    mkdir -p "$copy/.dev/hooks/tests" && git init -q "$copy" 2>/dev/null || return 1
    /bin/cp -f "$here/../install.sh" "$here/../commit-msg" "$here/../pre-push" "$here/../check-history.sh" "$copy/.dev/hooks/" || return 1
    /bin/cp -f "$here/run.sh" "$copy/.dev/hooks/tests/" || return 1
    out="$(cd "$copy" && env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE "$BASH" .dev/hooks/install.sh 2>&1)" || return 1
    case "$out" in *'hooks enabled: .dev/hooks'*) ;; *) return 1 ;; esac
    [ "$(git -C "$copy" config core.hooksPath)" = '.dev/hooks' ] && [ "$(git -C "$copy" config user.email)" = 'fuwa@shinonome.co.jp' ]
}
expect_check() {
    local expected="$1" label="$2"
    shift 2
    if "$@" >/dev/null 2>&1; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL check: $label (expected $expected, got $actual)"; fi
}
expect_check ok "install.sh stops with a message about perl when perl is missing" install_stops_without_perl
expect_check ok "install.sh enables the hooks of its repository" install_enables_the_hooks

echo "rules: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
