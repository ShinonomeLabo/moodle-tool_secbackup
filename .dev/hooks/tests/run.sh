#!/usr/bin/env bash
# Tests for the commit hooks: the message and identity rules in ../lib/rules.sh, and the fail-closed
# behaviour of ../pre-push and ../check-history.sh when a tool they depend on fails, and ../install.sh.
# They do not depend on the machine they run on: every file they make is under $work, which one trap
# removes, and every git command works in a temporary repository whatever the git configuration of the
# machine says (commit.cleanup, user.email, core.hooksPath, ...) or which repository variables are set.
set -u

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d)" || exit 2
trap 'rm -rf "$work"' EXIT
# The temporary files of the hooks (and of the shells that run them) go there too.
export TMPDIR="$work"
patterns="$work/patterns"
message="$work/message"
printf 'forbiddenword\nsecond phrase\n' > "$patterns"
export SECBACKUP_PATTERNS_FILE="$patterns"
# shellcheck source=../lib/rules.sh
source "$here/../lib/rules.sh"
# git sets variables such as GIT_DIR when it runs a hook, and a shell may have them set: they would send every
# git command below to another repository. GIT_NO_REPLACE_OBJECTS is one of them: rules.sh has just set it,
# and the hooks must set it themselves, so it must not reach them from here.
for name in $(git rev-parse --local-env-vars); do unset "$name"; done

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
# git commit -v puts the diff below a "scissors" line, and git drops that line and everything after it when it
# stores the message. So the empty and Japanese checks must not count that part either, while the words are
# still searched in all of it (the file may be kept as it is, and a word in a diff is worth stopping at).
expect_message ng "Japanese only in the diff below the scissors line of git commit -v" $'Add a change\n\n# ------------------------ >8 ------------------------\n# Do not modify or remove the line above.\n# Everything below it will be ignored.\ndiff --git a/x b/x\n+日本語の行を足す\n'
expect_message ng "nothing above the scissors line of git commit -v" $'\n# ------------------------ >8 ------------------------\ndiff --git a/x b/x\n+日本語の行を足す\n'
expect_message ok "Japanese message above the scissors line of git commit -v" $'日本語の件名\n\n# ------------------------ >8 ------------------------\ndiff --git a/x b/x\n+Add a line\n'
expect_message ng "forbidden word below the scissors line is still found" $'日本語の件名\n\n# ------------------------ >8 ------------------------\ndiff --git a/x b/x\n+forbiddenword\n'
expect_message ng "pattern in subject" $'forbiddenword を足す\n'
expect_message ng "pattern in body, upper case" $'変更を足す\n\nFORBIDDENWORD\n'
expect_message ng "second pattern with a space" $'変更を足す\n\nSecond Phrase\n'
expect_message ok "similar but different word" $'変更を足す\n\nforbidden word\n'

# A word that looks like a path (a slash at both ends, or a slash inside) is plain text like any other word, in
# any case, so that the path of a machine cannot get into a message. The words below are stand-ins: the real ones
# are only in the untracked list, and no tracked file may hold them.
printf 'forbiddenword\n/stand-in/\nwork/stand-in\n' > "$patterns.paths"
SECBACKUP_PATTERNS_FILE="$patterns.paths"
expect_message ng "word with a slash at both ends" $'変更を足す\n\n/stand-in/file を直す\n'
expect_message ng "word with a slash at both ends, upper case" $'変更を足す\n\n/STAND-IN/FILE\n'
expect_message ng "word with a slash inside" $'変更を足す\n\nsee work/stand-in/x\n'
expect_message ng "word with a slash inside, mixed case, in the subject" $'~/Work/Stand-In を直す\n'
expect_message ng "word with a slash at both ends, on a # line" $'変更を足す\n# /stand-in/ in a git comment line\n'
expect_message ok "similar text without the slashes" $'変更を足す\n\nstand-in と work stand-in\n'
expect_message ok "word with a slash at both ends needs both slashes" $'変更を足す\n\n/stand-in だけ\n'
SECBACKUP_PATTERNS_FILE="$patterns"

# Fail closed: without the pattern list every message is rejected.
SECBACKUP_PATTERNS_FILE="$patterns.missing"
expect_message ng "missing pattern list" $'日本語の件名\n'
printf '' > "$patterns.empty"
SECBACKUP_PATTERNS_FILE="$patterns.empty"
expect_message ng "empty pattern list" $'日本語の件名\n'
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
SECBACKUP_PATTERNS_FILE="$patterns"

expect_identity ok "configured identity" 'Takayuki Fuwa <fuwa@shinonome.co.jp>'
expect_identity ng "other e-mail" 'Takayuki Fuwa <fuwa@example.com>'
expect_identity ng "other name" 'Someone Else <fuwa@shinonome.co.jp>'
expect_identity ng "empty" ''

# Fail closed: a tool that fails must never let a message, a commit or a push through. The tools
# (grep, sed, tr, mktemp, git) are replaced by test doubles that fail on request, so nothing below depends on
# the locale or on the state of the machine.
zeros="$(printf '%040d' 0)"
ones="${zeros//0/1}"
mkdir "$work/fakebin" || exit 2
FAKE_REAL_GREP="$(command -v grep)"
FAKE_REAL_SED="$(command -v sed)"
FAKE_REAL_TR="$(command -v tr)"
FAKE_REAL_MKTEMP="$(command -v mktemp)"
FAKE_REAL_GIT="$(command -v git)"
export FAKE_REAL_GREP FAKE_REAL_SED FAKE_REAL_TR FAKE_REAL_MKTEMP FAKE_REAL_GIT
cat > "$work/fakebin/grep" <<'EOF'
#!/usr/bin/env bash
# grep that fails on request. Mode fail: the word search (-qiF) exits with 2. Mode bytes: a call that does
# not run byte-wise (LC_ALL=C) exits with 2.
case "${FAKE_GREP_MODE-}" in
    bytes) [ "${LC_ALL-}" = C ] || exit 2 ;;
    fail) for argument in "$@"; do [ "$argument" = -qiF ] && exit 2; done ;;
esac
exec "$FAKE_REAL_GREP" "$@"
EOF
cat > "$work/fakebin/sed" <<'EOF'
#!/usr/bin/env bash
# sed that fails on request. Mode strip: the stripping of the comments prints one line and then exits with 2.
# Mode bytes: a call that does not run byte-wise (LC_ALL=C) exits with 2.
case "${FAKE_SED_MODE-}" in
    bytes) [ "${LC_ALL-}" = C ] || exit 2 ;;
    strip) head -n 1 "${!#}"; exit 2 ;;
esac
exec "$FAKE_REAL_SED" "$@"
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
cat > "$work/fakebin/mktemp" <<'EOF'
#!/usr/bin/env bash
# mktemp that fails on request (FAKE_MKTEMP_FAIL is set).
[ -n "${FAKE_MKTEMP_FAIL-}" ] && exit 1
exec "$FAKE_REAL_MKTEMP" "$@"
EOF
cat > "$work/fakebin/git" <<'EOF'
#!/usr/bin/env bash
# git that fails or answers oddly on request. FAKE_GIT_FAIL_ON=N: the Nth "git log -1 --format=%B" call prints a
# first line and exits with 1. FAKE_GIT_SIGNAL=NAME: the first such call prints a first line, sends that signal
# to the process that called git (the hook) and ends at once, so the hook is stopped while it reads a message.
# FAKE_GIT_SHALLOW: "git rev-parse --is-shallow-repository" prints that text, or fails when it is "fail" (git
# before 2.15 prints the option itself).
if [ "${1-}" = rev-parse ] && [ "${2-}" = --is-shallow-repository ] && [ -n "${FAKE_GIT_SHALLOW-}" ]; then
    [ "$FAKE_GIT_SHALLOW" = fail ] && exit 1
    echo "$FAKE_GIT_SHALLOW"
    exit 0
fi
if [ "${1-}" = log ] && [ "${3-}" = '--format=%B' ] && [ -n "${FAKE_GIT_SIGNAL-}" ]; then
    echo '日本語の件名'
    kill -s "$FAKE_GIT_SIGNAL" "$PPID"
    exit 0
fi
if [ "${1-}" = log ] && [ "${3-}" = '--format=%B' ] && [ -n "${FAKE_GIT_FAIL_ON-}" ]; then
    count=$(( $(cat "$FAKE_GIT_COUNTER" 2>/dev/null || echo 0) + 1 ))
    echo "$count" > "$FAKE_GIT_COUNTER"
    if [ "$count" -eq "$FAKE_GIT_FAIL_ON" ]; then
        echo '日本語の件名'
        exit 1
    fi
fi
# FAKE_GIT_FAIL_GREP: "git grep" fails as git does on an error (128). FAKE_GIT_FAIL_LSTREE: the same for "git ls-tree".
for argument in "$@"; do
    [ -n "${FAKE_GIT_FAIL_GREP-}" ] && [ "$argument" = grep ] && exit 128
    [ -n "${FAKE_GIT_FAIL_LSTREE-}" ] && [ "$argument" = ls-tree ] && exit 128
done
exec "$FAKE_REAL_GIT" "$@"
EOF
chmod +x "$work/fakebin/grep" "$work/fakebin/sed" "$work/fakebin/tr" "$work/fakebin/mktemp" "$work/fakebin/git"

# Like expect_message, but secbackup_check_message runs in a child shell with the given environment.
expect_message_env() {
    local expected="$1" label="$2" body="$3"
    shift 3
    printf '%s' "$body" > "$message"
    # shellcheck disable=SC2016
    if env "$@" "$BASH" -c 'source "$1/../lib/rules.sh"; secbackup_check_message "$2"' _ "$here" "$message" 2>/dev/null; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL message: $label (expected $expected, got $actual)"; fi
}

# Like expect_message, but secbackup_check_message runs in a child shell that cannot write any file
# (ulimit -f 0; SIGXFSZ is ignored, so that a write fails instead of killing the shell). bash before 5.1 writes a
# here-string to a temporary file: when that fails, the search of the words must reject the message instead of
# being skipped. bash 5 keeps a short here-string in a pipe and finds the word itself, so the expected result is
# the same for every version.
expect_message_readonly() {
    local expected="$1" label="$2" body="$3"
    printf '%s' "$body" > "$message"
    # shellcheck disable=SC2016
    if "$BASH" -c 'trap "" XFSZ; ulimit -f 0; source "$1/../lib/rules.sh"; secbackup_check_message "$2"' _ "$here" "$message" 2>/dev/null; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL message: $label (expected $expected, got $actual)"; fi
}

# Runs a hook script in a repository with the given standard input and extra environment assignments.
run_hook() {
    local repo="$1" script="$2" input="$3"
    shift 3
    (cd "$repo" && printf '%s' "$input" | env GIT_CEILING_DIRECTORIES="$work" "$@" "$BASH" "$here/../$script" origin remote.test)
}

expect_hook() {
    local expected="$1" label="$2"
    shift 2
    if run_hook "$@" >/dev/null 2>&1; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL hook: $label (expected $expected, got $actual)"; fi
}

# Runs a check function and compares its result (ok: it returned 0) with the expected one.
expect_check() {
    local expected="$1" label="$2"
    shift 2
    if "$@" >/dev/null 2>&1; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL check: $label (expected $expected, got $actual)"; fi
}

# Runs a command with the repository identity as author and committer. Environment assignments passed before the
# command come after the identity in the env call, so they replace it.
as_owner() {
    env GIT_AUTHOR_NAME='Takayuki Fuwa' GIT_AUTHOR_EMAIL='fuwa@shinonome.co.jp' \
        GIT_COMMITTER_NAME='Takayuki Fuwa' GIT_COMMITTER_EMAIL='fuwa@shinonome.co.jp' "$@"
}

# Commits for the temporary repositories: no hooks, no signing, and the message stored exactly as given
# (--cleanup=verbatim), whatever commit.cleanup or the other user configuration of the machine says. Further
# arguments are environment assignments that replace the identity.
commit_in() {
    local repo="$1" text="$2"
    shift 2
    as_owner "$@" git -C "$repo" -c core.hooksPath=/dev/null -c commit.gpgsign=false commit -q --cleanup=verbatim --allow-empty -m "$text"
}

good="$work/good"
bad="$work/bad"
hashed="$work/hashed"
reached="$work/reached"
replaced="$work/replaced"
wrong_author="$work/wrong-author"
wrong_committer="$work/wrong-committer"
for repo in "$good" "$bad" "$hashed" "$reached" "$replaced" "$wrong_author" "$wrong_committer"; do git init -q "$repo" 2>/dev/null || exit 2; done
commit_in "$good" '最初の変更を足す' || exit 2
good_first="$(git -C "$good" rev-parse HEAD)"
commit_in "$good" '次の変更を足す' || exit 2
commit_in "$bad" '最初の変更を足す' || exit 2
commit_in "$bad" 'Add a second change' || exit 2
commit_in "$hashed" '最初の変更を足す' || exit 2
commit_in "$hashed" $'次の変更を足す\n\n# forbiddenword on a # line' || exit 2
# A forbidden word in the middle of a history, which the remote-tracking ref other/main reaches.
commit_in "$reached" '最初の変更を足す' || exit 2
commit_in "$reached" $'次の変更を足す\n\nforbiddenword' || exit 2
reached_word="$(git -C "$reached" rev-parse HEAD)"
commit_in "$reached" '三つ目の変更を足す' || exit 2
git -C "$reached" update-ref refs/remotes/other/main "$reached_word" || exit 2
# A commit with an English message, replaced by one with a Japanese message (git replace): git reads the
# replacement, a push sends the original.
commit_in "$replaced" '最初の変更を足す' || exit 2
replaced_first="$(git -C "$replaced" rev-parse HEAD)"
commit_in "$replaced" 'Add a second change' || exit 2
replaced_english="$(git -C "$replaced" rev-parse HEAD)"
replaced_japanese="$(printf '次の変更を足す\n' | as_owner git -C "$replaced" commit-tree "$replaced_first^{tree}" -p "$replaced_first")" || exit 2
git -C "$replaced" replace "$replaced_english" "$replaced_japanese" || exit 2
commit_in "$wrong_author" '最初の変更を足す' || exit 2
commit_in "$wrong_author" '次の変更を足す' GIT_AUTHOR_NAME='Someone Else' GIT_AUTHOR_EMAIL='someone@example.com' || exit 2
commit_in "$wrong_committer" '最初の変更を足す' || exit 2
commit_in "$wrong_committer" '次の変更を足す' GIT_COMMITTER_NAME='Someone Else' GIT_COMMITTER_EMAIL='someone@example.com' || exit 2
git -c protocol.file.allow=always -c core.hooksPath=/dev/null clone -q --depth 1 "file://$good" "$work/shallow" 2>/dev/null || exit 2
mkdir "$work/notrepo" || exit 2
printf -v push_good 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$good" rev-parse HEAD)" "$zeros"
printf -v push_good_update 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$good" rev-parse HEAD)" "$good_first"
printf -v push_bad 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$bad" rev-parse HEAD)" "$zeros"
printf -v push_hashed 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$hashed" rev-parse HEAD)" "$zeros"
printf -v push_bad_unterminated 'refs/heads/main %s refs/heads/main %s' "$(git -C "$bad" rev-parse HEAD)" "$zeros"
printf -v push_unknown 'refs/heads/main %s refs/heads/main %s\n' "$ones" "$zeros"
printf -v push_reached 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$reached" rev-parse HEAD)" "$zeros"
printf -v push_reached_known 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$reached" rev-parse HEAD)" "$reached_word"
printf -v push_replaced 'refs/heads/main %s refs/heads/main %s\n' "$replaced_english" "$zeros"
printf -v push_wrong_author 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$wrong_author" rev-parse HEAD)" "$zeros"
printf -v push_wrong_committer 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$wrong_committer" rev-parse HEAD)" "$zeros"

# The word search: when grep itself fails (status 2) the message is rejected, and every search and
# every cut of the text runs byte-wise (LC_ALL=C) whatever the locale of the caller is.
expect_message_env ng "word search that fails" $'日本語の件名\n' PATH="$work/fakebin:$PATH" FAKE_GREP_MODE=fail
expect_message_env ok "searches run byte-wise" $'日本語の件名\n' PATH="$work/fakebin:$PATH" FAKE_GREP_MODE=bytes FAKE_SED_MODE=bytes FAKE_TR_MODE=bytes LC_ALL=POSIX
expect_message_readonly ng "forbidden word while no file can be written (before bash 5.1 a here-string is a file)" $'日本語の件名\n\nforbiddenword\n'
# bash 5.1 and later keep a here-string in a pipe as long as it fits (about 64 KiB) and write a longer one to a
# temporary file. So the same test with a pattern list well over that size reaches the check in every version of
# bash; the two other tests show that the long list itself works (the word is listed last, after 100 long words).
perl -e 'print "x" x 1000, "\n" for 1 .. 100; print "forbiddenword\n"' > "$patterns.big"
[ "$(( $(wc -c < "$patterns.big") ))" -gt 90000 ] || { echo "test setup: the long pattern list is too short" >&2; exit 2; }
SECBACKUP_PATTERNS_FILE="$patterns.big"
expect_message ok "pattern list over 64 KiB, message without a forbidden word" $'日本語の件名\n'
expect_message ng "pattern list over 64 KiB, forbidden word listed last" $'日本語の件名\n\nforbiddenword\n'
expect_message_readonly ng "forbidden word while no file can be written, pattern list over 64 KiB (bash 5 writes a file then)" $'日本語の件名\n\nforbiddenword\n'
SECBACKUP_PATTERNS_FILE="$patterns"
# A message or a pattern list that was read only in part must not be used. The message of the first test is
# clean on purpose: with a forbidden word in it, the search of the words would reject it by itself and the
# test would not reach the check that the stripping of the comments succeeded.
expect_message_env ng "comment stripping that fails half-way" $'日本語の件名\n\n本文も日本語で書く。\n' PATH="$work/fakebin:$PATH" FAKE_SED_MODE=strip
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

# The message of a commit is read through a temporary file; without one the commit is not checked, so it is refused.
expect_hook ng "pre-push when no temporary file can be made" "$good" pre-push "$push_good" PATH="$work/fakebin:$PATH" FAKE_MKTEMP_FAIL=1
expect_hook ng "check-history when no temporary file can be made" "$good" check-history.sh '' PATH="$work/fakebin:$PATH" FAKE_MKTEMP_FAIL=1

# pre-push checks every commit that the pushed commit reaches, not only the ones the remote lacks: a commit
# that another remote-tracking ref reaches, or that the remote already has, would go unchecked otherwise.
expect_hook ok "pre-push of an update of a good history" "$good" pre-push "$push_good_update"
expect_hook ng "pre-push, forbidden word in a commit that only another remote-tracking ref reaches" "$reached" pre-push "$push_reached"
expect_hook ng "pre-push, forbidden word in a commit the remote already has" "$reached" pre-push "$push_reached_known"

# git reads a replaced commit through refs/replace/*, a push sends the original: the original is checked.
expect_hook ng "pre-push of a commit that a replacement shows with a Japanese message" "$replaced" pre-push "$push_replaced"
expect_hook ng "check-history of a commit that a replacement shows with a Japanese message" "$replaced" check-history.sh ''

# Both hooks check the author and the committer of every commit.
expect_hook ng "pre-push, author of a commit is not the repository identity" "$wrong_author" pre-push "$push_wrong_author"
expect_hook ng "pre-push, committer of a commit is not the repository identity" "$wrong_committer" pre-push "$push_wrong_committer"
expect_hook ng "check-history, author of a commit is not the repository identity" "$wrong_author" check-history.sh ''
expect_hook ng "check-history, committer of a commit is not the repository identity" "$wrong_committer" check-history.sh ''

# check-history.sh goes on only when git says that the history is not shallow: any other answer (a failure,
# or the option printed back by a git before 2.15) stops it.
expect_hook ok "check-history when git says the history is complete" "$good" check-history.sh '' PATH="$work/fakebin:$PATH" FAKE_GIT_SHALLOW=false
expect_hook ng "check-history when git prints back the option (git before 2.15)" "$good" check-history.sh '' PATH="$work/fakebin:$PATH" FAKE_GIT_SHALLOW=--is-shallow-repository
expect_hook ng "check-history when git cannot tell whether the history is shallow" "$good" check-history.sh '' PATH="$work/fakebin:$PATH" FAKE_GIT_SHALLOW=fail

# The contents (check-contents.sh, and pre-push for what it sends): the files of every commit and their names, and the tags.
# Each repository below has files; the words of the list are planted where each test says.
file_commit() {
    # file_commit <repo> <file> <content or - to remove it> <message>
    local repo="$1" file="$2" content="$3" text="$4"
    if [ "$content" = - ]; then
        git -C "$repo" rm -q -- "$file" || return 1
    else
        mkdir -p "$(dirname "$repo/$file")" && printf '%s\n' "$content" > "$repo/$file" && git -C "$repo" add -- "$file" || return 1
    fi
    commit_in "$repo" "$text"
}
tag_in() {
    # tag_in <repo> <name> <message> <object> [environment assignments]: an annotated tag, by the repository identity unless
    # the assignments replace it.
    local repo="$1" name="$2" text="$3" object="$4"
    shift 4
    as_owner "$@" git -C "$repo" -c tag.gpgSign=false tag -a --cleanup=verbatim -m "$text" "$name" "$object"
}
contents_repo() {
    # contents_repo <name>: a new repository with one clean file and one commit; prints its path.
    local repo="$work/contents-$1"
    git init -q "$repo" 2>/dev/null && file_commit "$repo" docs/a.txt 'ordinary words' '最初の変更を足す' > /dev/null && printf '%s' "$repo"
}
c_clean="$(contents_repo clean)" || exit 2
file_commit "$c_clean" docs/b.txt 'more ordinary words' '次の変更を足す' || exit 2
tag_in "$c_clean" v1 '最初の版を出す' HEAD || exit 2
c_head="$(contents_repo head)" || exit 2
file_commit "$c_head" docs/a.txt 'a line with a FORBIDDENword in it' '次の変更を足す' || exit 2
c_old="$(contents_repo old)" || exit 2
file_commit "$c_old" notes/old.txt 'a Second Phrase here' '次の変更を足す' || exit 2
c_old_hit="$(git -C "$c_old" rev-parse HEAD)"
file_commit "$c_old" notes/old.txt - '三つ目の変更を足す' || exit 2
c_name="$(contents_repo name)" || exit 2
file_commit "$c_name" docs/forbiddenword-notes.txt 'ordinary words' '次の変更を足す' || exit 2
c_tagword="$(contents_repo tagword)" || exit 2
tag_in "$c_tagword" v1 $'最初の版を出す\n\nforbiddenword' HEAD || exit 2
c_tagenglish="$(contents_repo tagenglish)" || exit 2
tag_in "$c_tagenglish" v1 'Release the first version' HEAD || exit 2
c_tagger="$(contents_repo tagger)" || exit 2
tag_in "$c_tagger" v1 '最初の版を出す' HEAD GIT_COMMITTER_NAME='Someone Else' GIT_COMMITTER_EMAIL='someone@example.com' || exit 2
c_tagname="$(contents_repo tagname)" || exit 2
git -C "$c_tagname" tag forbiddenword HEAD || exit 2
c_tagtree="$(contents_repo tagtree)" || exit 2
tag_in "$c_tagtree" tree1 'ツリーに付けたタグ' 'HEAD^{tree}' || exit 2
git -c protocol.file.allow=always -c core.hooksPath=/dev/null clone -q --depth 1 "file://$c_clean" "$work/contents-shallow" 2>/dev/null \
    || exit 2
printf -v push_c_clean 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$c_clean" rev-parse HEAD)" "$zeros"
printf -v push_c_clean_tag 'refs/tags/v1 %s refs/tags/v1 %s\n' "$(git -C "$c_clean" rev-parse v1)" "$zeros"
printf -v push_c_head 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$c_head" rev-parse HEAD)" "$zeros"
printf -v push_c_old 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$c_old" rev-parse HEAD)" "$zeros"
printf -v push_c_name 'refs/heads/main %s refs/heads/main %s\n' "$(git -C "$c_name" rev-parse HEAD)" "$zeros"
printf -v push_c_tagword 'refs/tags/v1 %s refs/tags/v1 %s\n' "$(git -C "$c_tagword" rev-parse v1)" "$zeros"
printf -v push_c_tagger 'refs/tags/v1 %s refs/tags/v1 %s\n' "$(git -C "$c_tagger" rev-parse v1)" "$zeros"
printf -v push_c_tagname 'refs/tags/forbiddenword %s refs/tags/forbiddenword %s\n' "$(git -C "$c_tagname" rev-parse HEAD)" "$zeros"

expect_hook ok "check-contents of a clean repository with an annotated tag" "$c_clean" check-contents.sh ''
expect_hook ng "check-contents, a word in a file at HEAD" "$c_head" check-contents.sh ''
expect_hook ng "check-contents, a word in a file of an older commit only" "$c_old" check-contents.sh ''
expect_hook ng "check-contents, a word in the name of a file" "$c_name" check-contents.sh ''
expect_hook ng "check-contents, a word in the message of an annotated tag" "$c_tagword" check-contents.sh ''
expect_hook ng "check-contents, an annotated tag with an English message" "$c_tagenglish" check-contents.sh ''
expect_hook ng "check-contents, an annotated tag by another tagger" "$c_tagger" check-contents.sh ''
expect_hook ng "check-contents, a word in the name of a tag" "$c_tagname" check-contents.sh ''
expect_hook ng "check-contents, a tag of a tree (its contents are not checked)" "$c_tagtree" check-contents.sh ''
expect_hook ng "check-contents without the pattern list" "$c_clean" check-contents.sh '' SECBACKUP_PATTERNS_FILE="$patterns.missing"
expect_hook ng "check-contents outside a repository" "$work/notrepo" check-contents.sh ''
expect_hook ng "check-contents of a shallow clone" "$work/contents-shallow" check-contents.sh ''
expect_hook ng "check-contents when git grep fails" "$c_clean" check-contents.sh '' PATH="$work/fakebin:$PATH" FAKE_GIT_FAIL_GREP=1
expect_hook ng "check-contents when the names of the files cannot be listed" "$c_clean" check-contents.sh '' \
    PATH="$work/fakebin:$PATH" FAKE_GIT_FAIL_LSTREE=1
expect_hook ok "pre-push of a clean branch with files" "$c_clean" pre-push "$push_c_clean"
expect_hook ok "pre-push of a clean annotated tag" "$c_clean" pre-push "$push_c_clean_tag"
expect_hook ng "pre-push, a word in a file at the pushed commit" "$c_head" pre-push "$push_c_head"
expect_hook ng "pre-push, a word in a file of an older commit only" "$c_old" pre-push "$push_c_old"
expect_hook ng "pre-push, a word in the name of a file" "$c_name" pre-push "$push_c_name"
expect_hook ng "pre-push, a word in the message of the pushed annotated tag" "$c_tagword" pre-push "$push_c_tagword"
expect_hook ng "pre-push, the pushed annotated tag is by another tagger" "$c_tagger" pre-push "$push_c_tagger"
expect_hook ng "pre-push, a word in the name of the pushed tag" "$c_tagname" pre-push "$push_c_tagname"
expect_hook ng "pre-push without the pattern list" "$c_clean" pre-push "$push_c_clean" SECBACKUP_PATTERNS_FILE="$patterns.missing"
expect_hook ng "pre-push when git grep fails" "$c_clean" pre-push "$push_c_clean" PATH="$work/fakebin:$PATH" FAKE_GIT_FAIL_GREP=1

# What the content checks print: the commit, the file and the number of lines, never the line or a word (a word in the name of
# a file or a tag is not printed either).
# Usage: contents_output_says <repo> <script> <input> <text that must be in the output...>
contents_output_says() {
    local repo="$1" script="$2" input="$3" out wanted
    shift 3
    out="$(run_hook "$repo" "$script" "$input" 2>&1)" && return 1
    if printf '%s\n' "$out" | LC_ALL=C grep -q -i -e forbiddenword -e 'second phrase'; then
        return 1
    fi
    for wanted in "$@"; do
        case "$out" in *"$wanted"*) ;; *) return 1 ;; esac
    done
}
expect_check ok "check-contents names the file of HEAD and the number of lines, without the word" \
    contents_output_says "$c_head" check-contents.sh '' 'HEAD docs/a.txt (1 行)'
expect_check ok "check-contents names the older commit and its file, without the word" \
    contents_output_says "$c_old" check-contents.sh '' "$c_old_hit notes/old.txt (1 行)"
expect_check ok "check-contents does not print a file name that holds the word" \
    contents_output_says "$c_name" check-contents.sh '' 'ファイルの名前に使えない言葉が入っています'
expect_check ok "check-contents does not print a tag name that holds the word" \
    contents_output_says "$c_tagname" check-contents.sh '' '(使えない言葉が入った名前)'
expect_check ok "pre-push does not print the word of a tag message" \
    contents_output_says "$c_tagword" pre-push "$push_c_tagword" 'コミットのメッセージに、使えない言葉が入っています。'
expect_check ok "pre-push names the older commit and its file, without the word" \
    contents_output_says "$c_old" pre-push "$push_c_old" "$c_old_hit (refs/heads/main -> refs/heads/main) notes/old.txt (1 行)"

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
    /bin/cp -f "$here/../install.sh" "$here/../commit-msg" "$here/../pre-push" "$here/../check-history.sh" "$here/../check-contents.sh" \
        "$copy/.dev/hooks/" || return 1
    /bin/cp -f "$here/run.sh" "$copy/.dev/hooks/tests/" || return 1
    out="$(cd "$copy" && "$BASH" .dev/hooks/install.sh 2>&1)" || return 1
    case "$out" in *'hooks enabled: .dev/hooks'*) ;; *) return 1 ;; esac
    # Only what install.sh wrote into the repository counts: the configuration of the machine may hold the same values.
    [ "$(git -C "$copy" config --local core.hooksPath)" = '.dev/hooks' ] \
        && [ "$(git -C "$copy" config --local user.name)" = 'Takayuki Fuwa' ] \
        && [ "$(git -C "$copy" config --local user.email)" = 'fuwa@shinonome.co.jp' ]
}
expect_check ok "install.sh stops with a message about perl when perl is missing" install_stops_without_perl
expect_check ok "install.sh enables the hooks of its repository" install_enables_the_hooks

# A hook that a signal stops while it reads the message of a commit has to remove the temporary file it made for it.
# The fake git sends the signal to the hook (the process that called it) and ends at once, so nothing depends on
# timing: the hook has to die of the signal (status 128 + 15 for SIGTERM) and leave nothing in its own, empty
# TMPDIR (under $work, like the rest). SIGINT and SIGHUP are not tried: a shell that was started in the background
# or by nohup ignores them, and the result would depend on who runs the tests.
interrupted_hook_leaves_nothing() {
    local script="$1" input="$2" rc dir="$work/interrupted-$1"
    mkdir "$dir" || return 1
    run_hook "$good" "$script" "$input" TMPDIR="$dir" PATH="$work/fakebin:$PATH" FAKE_GIT_SIGNAL=TERM > /dev/null 2>&1
    rc=$?
    [ "$rc" -eq 143 ] && [ -z "$(ls -A "$dir")" ]
}
expect_check ok "pre-push stopped by SIGTERM while it reads a message removes its temporary file" interrupted_hook_leaves_nothing pre-push "$push_good"
expect_check ok "check-history stopped by SIGTERM while it reads a message removes its temporary file" interrupted_hook_leaves_nothing check-history.sh ''

# Every hook run above that ran to its end (the interrupted ones have their own TMPDIR) made a temporary file in
# $TMPDIR, that is under $work, for the message of each commit and has to remove it again.
no_temporary_file_left() { ! compgen -G "$work/secbackup-message.*" > /dev/null; }
expect_check ok "the hooks leave no temporary file behind" no_temporary_file_left

# The rule that keeps the PHP files of .dev/ from running in a request to the web server: it is tried on a planted
# directory, with a file that has no guard (fails) and with each guard that is accepted (passes).
dev_guards_pass_for() {
    local dir="$work/devguards-$1"
    mkdir -p "$dir/sub" || return 1
    printf '%s\n' '<?php' '// A comment.' '/**' ' * A block comment.' ' */' '' "$2" 'echo 1;' > "$dir/sub/script.php"
    "$here/../check-dev-guards.sh" "$dir" > /dev/null 2>&1
}
expect_check ng "the dev guard rule refuses a file without a guard" dev_guards_pass_for none 'echo 1;'
expect_check ng "the dev guard rule refuses a guard that is not the first statement" dev_guards_pass_for late 'require "x.php";'
expect_check ok "the dev guard rule accepts the command line guard" dev_guards_pass_for cli "if (PHP_SAPI !== 'cli') {"
expect_check ok "the dev guard rule accepts the built-in server guard" dev_guards_pass_for server "if (PHP_SAPI !== 'cli-server') {"
expect_check ok "the dev guard rule accepts CLI_SCRIPT" dev_guards_pass_for moodle "define('CLI_SCRIPT', true);"
expect_check ok "the dev guard rule accepts the marker file guard under /etc" dev_guards_pass_for marker "if (!is_file('/etc/secbk-secureos-probe')) {"
expect_check ng "the dev guard rule refuses a marker file guard that is not the first statement" dev_guards_pass_for markerlate 'require "x.php";
if (!is_file('"'"'/etc/secbk-secureos-probe'"'"')) {'
expect_check ng "the dev guard rule refuses a marker file outside /etc/secbk-" dev_guards_pass_for markertmp "if (!is_file('/tmp/secbk-secureos-probe')) {"
expect_check ng "the dev guard rule refuses a marker file whose name is not secbk-" dev_guards_pass_for markername "if (!is_file('/etc/passwd')) {"
dev_guards_skip_the_named_exceptions() {
    local dir="$work/devguards-exceptions"
    mkdir -p "$dir/coverage/tests/fixtures/source" "$dir/tools" || return 1
    printf '%s\n' '<?php' 'echo 1;' > "$dir/coverage/collector.php"
    printf '%s\n' '<?php' 'echo 1;' > "$dir/coverage/tests/fixtures/source/a.php"
    printf '%s\n' '<?php' "define('CLI_SCRIPT', true);" > "$dir/tools/tool.php"
    "$here/../check-dev-guards.sh" "$dir" > /dev/null 2>&1
}
expect_check ok "the dev guard rule skips the collector and the fixtures only" dev_guards_skip_the_named_exceptions
dev_guards_refuse_another_file_in_the_exception_folder() {
    local dir="$work/devguards-other"
    mkdir -p "$dir/coverage" || return 1
    printf '%s\n' '<?php' 'echo 1;' > "$dir/coverage/other.php"
    "$here/../check-dev-guards.sh" "$dir" > /dev/null 2>&1
}
expect_check ng "the dev guard rule checks the other files next to the collector" dev_guards_refuse_another_file_in_the_exception_folder
the_dev_directory_of_this_repository_has_its_guards() { "$here/../check-dev-guards.sh" > /dev/null 2>&1; }
expect_check ok "every PHP file of .dev has its guard" the_dev_directory_of_this_repository_has_its_guards

# The check of the UI inventory (.dev/ci/check-ui-inventory.php): a small plugin is planted in a temporary directory, with a
# page (index.php, its page class, a form with a button and a cancel button, a link of the renderer), an entry of the
# administration tree and a feature file that does everything; the check passes on it, and fails after each kind of damage.
inventory_check="$here/../../ci/check-ui-inventory.php"
plant_plugin() {
    local dir="$1"
    rm -rf "$dir"
    mkdir -p "$dir/classes/form" "$dir/classes/output" "$dir/classes/local/page" "$dir/lang/en" "$dir/tests/behat" || return 1
    printf '%s\n' '<?php' 'echo (new \tool_secbackup\local\page\main_page())->render();' > "$dir/index.php"
    printf '%s\n' '<?php' 'echo 1;' > "$dir/version.php"
    printf '%s\n' '<?php' "\$ADMIN->add('tools', new admin_externalpage('x', new lang_string('page_status', 'tool_secbackup'), 'u'));" \
        > "$dir/settings.php"
    printf '%s\n' '<?php' 'class main_page {' '    public function render() {' '        $form = new go_form();' \
        '        return $renderer->links();' '    }' '}' > "$dir/classes/local/page/main_page.php"
    printf '%s\n' '<?php' 'class go_form {' '    public function definition() {' \
        '        // $this->add_action_buttons(false, get_string("commented", "tool_secbackup"));' \
        "        \$this->add_action_buttons(true, get_string('action_go', 'tool_secbackup'));" '    }' '}' \
        > "$dir/classes/form/go_form.php"
    printf '%s\n' '<?php' 'class renderer {' '    public function links() {' \
        "        return html_writer::link(new moodle_url('/a.php'), get_string('page_other', 'tool_secbackup'));" '    }' '}' \
        > "$dir/classes/output/renderer.php"
    printf '%s\n' '<?php' "\$string['action_go'] = 'Go';" "\$string['action_more'] = 'More';" \
        "\$string['page_other'] = 'Other page';" "\$string['page_status'] = 'Status';" \
        "\$string['commented'] = 'Never shown';" > "$dir/lang/en/tool_secbackup.php"
    printf '%s\n' '@tool @tool_secbackup @javascript' 'Feature: F' '' '  Scenario: Does everything' \
        '    When I open "Plugins > Status" in site administration' '    And I press "Go"' '    And I press "Cancel"' \
        '    And I follow "Other page"' '    Then I should see "Done"' > "$dir/tests/behat/f.feature"
    printf '%s\n' '# comment' 'index.php | Go | f.feature: Does everything' 'index.php | Cancel | f.feature: Does everything' \
        'index.php | Other page | f.feature: Does everything' 'settings.php | Status | f.feature: Does everything' \
        > "$dir/tests/behat/inventory.txt"
}
inventory_check_passes() { php "$inventory_check" "$1" > /dev/null 2>&1; }
inventory_after() {
    # inventory_after <name> <file> <sed expression>: plants the plugin, changes one file, runs the check.
    local dir="$work/inventory-$1"
    plant_plugin "$dir" || return 1
    sed -i.bak "$3" "$dir/$2" && inventory_check_passes "$dir"
}
inventory_with_file() {
    # inventory_with_file <name> <file> <text>: plants the plugin, adds one file (the text is PHP after the tag), checks.
    local dir="$work/inventory-$1"
    plant_plugin "$dir" || return 1
    mkdir -p "$(dirname "$dir/$2")" && printf '%s\n' '<?php' "$3" > "$dir/$2"
    inventory_check_passes "$dir"
}
inventory_with_feature() {
    # inventory_with_feature <name> <steps...>: the scenario of the planted plugin gets these steps instead.
    local dir="$work/inventory-$1"
    shift
    plant_plugin "$dir" || return 1
    printf '%s\n' '@tool @tool_secbackup @javascript' 'Feature: F' '' '  Scenario: Does everything' "$@" \
        > "$dir/tests/behat/f.feature"
    inventory_check_passes "$dir"
}
planted_plugin_passes() { plant_plugin "$work/inventory-ok" && inventory_check_passes "$work/inventory-ok"; }
expect_check ok "the inventory check accepts a planted plugin that is complete" planted_plugin_passes
expect_check ng "the inventory check refuses a page that is not in the inventory" inventory_with_file page second.php 'echo 2;'
expect_check ok "the inventory check ignores a label in a comment of the code" planted_plugin_passes
expect_check ng "the inventory check refuses a button label that is not in the inventory" \
    inventory_after button tests/behat/inventory.txt '/| Go |/d'
expect_check ng "the inventory check refuses the cancel button of a form that is not in the inventory (probe D)" \
    inventory_after cancel tests/behat/inventory.txt '/| Cancel |/d'
expect_check ng "the inventory check refuses a link of the renderer that is not in the inventory" \
    inventory_after link tests/behat/inventory.txt '/| Other page |/d'
expect_check ng "the inventory check refuses an entry of the administration tree that is not in the inventory" \
    inventory_after entry tests/behat/inventory.txt '/settings.php/d'
expect_check ng "the inventory check refuses a scenario that does not exist" \
    inventory_after noscenario tests/behat/inventory.txt 's/Does everything/Does nothing/'
expect_check ng "the inventory check refuses a feature file that does not exist" \
    inventory_after nofeature tests/behat/inventory.txt 's/f.feature/g.feature/'
expect_check ng "the inventory check refuses a page that does not exist" \
    inventory_after nopage tests/behat/inventory.txt 's/^index.php/gone.php/'
expect_check ng "the inventory check refuses a scenario that does not use the label of the operation" \
    inventory_after nolabel tests/behat/f.feature 's/I press "Go"/I press "Stop"/'
expect_check ng "the inventory check refuses a label that is only seen, not done" \
    inventory_after onlyseen tests/behat/f.feature 's/I press "Go"/I should see "Go"/'
expect_check ng "the inventory check refuses a scenario that checks nothing" \
    inventory_after nocheck tests/behat/f.feature 's/Then I should see "Done"/And I press "Done"/'
expect_check ng "the inventory check refuses a feature without @tool_secbackup" \
    inventory_after notag tests/behat/f.feature '1s/@tool_secbackup //'
expect_check ng "the inventory check refuses a feature without @javascript" \
    inventory_after nojs tests/behat/f.feature '1s/ @javascript//'
expect_check ng "the inventory check refuses a line with the wrong number of fields" \
    inventory_after fields tests/behat/inventory.txt 's/ | Cancel | / | /'
expect_check ng "the inventory check refuses a label that it cannot read" \
    inventory_after unreadable classes/output/renderer.php 's/get_string(.page_other., .tool_secbackup.)/$text/'
# The probes of the review: buttons that the first version did not see, and lines that nothing held up.
second_page_with_the_same_label() {
    local dir="$work/inventory-probe-a"
    plant_plugin "$dir" || return 1
    printf '%s\n' '<?php' 'echo (new \tool_secbackup\local\page\other_page())->render();' > "$dir/other.php"
    printf '%s\n' '<?php' 'class other_page {' '    public function render() {' '        $form = new other_form();' '    }' '}' \
        > "$dir/classes/local/page/other_page.php"
    printf '%s\n' '<?php' 'class other_form {' '    public function definition() {' \
        "        \$this->add_action_buttons(false, get_string('action_go', 'tool_secbackup'));" '    }' '}' \
        > "$dir/classes/form/other_form.php"
    printf '%s\n' 'other.php | Go | f.feature: Does everything' >> "$dir/tests/behat/inventory.txt"
    inventory_check_passes "$dir"
}
expect_check ok "the inventory check accepts a second page that has its own line for the same label" second_page_with_the_same_label
second_page_without_its_line() {
    local dir="$work/inventory-probe-a2"
    plant_plugin "$dir" || return 1
    printf '%s\n' '<?php' 'echo (new \tool_secbackup\local\page\other_page())->render();' > "$dir/other.php"
    printf '%s\n' '<?php' 'class other_page {' '    public function render() {' '        $form = new other_form();' '    }' '}' \
        > "$dir/classes/local/page/other_page.php"
    printf '%s\n' '<?php' 'class other_form {' '    public function definition() {' \
        "        \$this->add_action_buttons(false, get_string('action_go', 'tool_secbackup'));" '    }' '}' \
        > "$dir/classes/form/other_form.php"
    printf '%s\n' 'other.php | Other page | f.feature: Does everything' >> "$dir/tests/behat/inventory.txt"
    inventory_check_passes "$dir"
}
expect_check ng "the inventory check refuses a button of another page that only the line of the first page seems to cover (probe A)" \
    second_page_without_its_line
renderer_with_single_button() {
    local dir="$work/inventory-probe-b"
    plant_plugin "$dir" || return 1
    sed -i.bak 's/return \$renderer->links();/return $renderer->links() . $renderer->extra();/' "$dir/classes/local/page/main_page.php"
    printf '%s\n' '<?php' 'class renderer {' '    public function links() {' \
        "        return html_writer::link(new moodle_url('/a.php'), get_string('page_other', 'tool_secbackup'));" '    }' \
        '    public function extra() {' \
        "        return \$this->output->single_button(new moodle_url('/b.php'), get_string('action_more', 'tool_secbackup'));" \
        '    }' '}' > "$dir/classes/output/renderer.php"
    inventory_check_passes "$dir"
}
expect_check ng "the inventory check refuses a single_button of the renderer that is not in the inventory (probe B)" \
    renderer_with_single_button
form_with_a_button_in_a_group() {
    local dir="$work/inventory-probe-c"
    plant_plugin "$dir" || return 1
    printf '%s\n' '<?php' 'class go_form {' '    public function definition() {' \
        "        \$this->add_action_buttons(true, get_string('action_go', 'tool_secbackup'));" \
        "        \$buttons = [\$mform->createElement('submit', 'more', get_string('action_more', 'tool_secbackup'))];" \
        "        \$mform->addGroup(\$buttons, 'group', '', ' ', false);" '    }' '}' > "$dir/classes/form/go_form.php"
    inventory_check_passes "$dir"
}
expect_check ng "the inventory check refuses a createElement button inside a group (probe C)" form_with_a_button_in_a_group
form_in_a_subfolder() {
    local dir="$work/inventory-probe-e"
    plant_plugin "$dir" || return 1
    mkdir -p "$dir/classes/form/sub"
    sed -i.bak 's/\$form = new go_form();/$form = new go_form(); $more = new more_form();/' "$dir/classes/local/page/main_page.php"
    printf '%s\n' '<?php' 'class more_form {' '    public function definition() {' \
        "        \$this->_form->addElement('submit', 'more', get_string('action_more', 'tool_secbackup'));" '    }' '}' \
        > "$dir/classes/form/sub/more_form.php"
    inventory_check_passes "$dir"
}
expect_check ng "the inventory check scans the folders below classes/form, and the form element of \$this->_form (probe E)" \
    form_in_a_subfolder
expect_check ok "the inventory check reads add_action_buttons with TRUE" \
    inventory_after upper classes/form/go_form.php 's/add_action_buttons(true/add_action_buttons(TRUE/'
expect_check ng "the inventory check reads add_action_buttons with named arguments (no cancel button then)" \
    inventory_after named classes/form/go_form.php "s/add_action_buttons(true, get_string('action_go', 'tool_secbackup'))/add_action_buttons(cancel: false, submitlabel: get_string('action_go', 'tool_secbackup'))/"
expect_check ng "the inventory check reads add_action_buttons without a label as Save changes" \
    inventory_after nolabeled classes/form/go_form.php "s/add_action_buttons(true, get_string('action_go', 'tool_secbackup'))/add_action_buttons()/"
expect_check ng "the inventory check refuses a line whose button is no longer in the code (probe F)" \
    inventory_after stale classes/form/go_form.php "s/.*add_action_buttons(true.*//"
expect_check ng "the inventory check refuses a line whose link is no longer in the code" \
    inventory_after stalelink classes/output/renderer.php "s/html_writer::link/html_writer::tag/"
expect_check ng "the inventory check refuses a form that no page reaches" \
    inventory_with_file orphan classes/form/orphan_form.php "class orphan_form { public function d() { \$this->add_action_buttons(false, get_string('action_more', 'tool_secbackup')); } }"
expect_check ng "the inventory check refuses a path name that the step does not use (a name between the row and the link)" \
    inventory_with_feature middle '    When I open "Plugins > Heading > Status > Gone" in site administration' \
        '    And I press "Go"' '    And I press "Cancel"' '    And I follow "Other page"' '    Then I should see "Done"'
expect_check ng "the inventory check refuses a result that is checked only before the operation (probe H)" \
    inventory_with_feature before '    When I open "Plugins > Status" in site administration' '    And I press "Go"' \
        '    And I press "Cancel"' '    Then I should see "Done"' '    And I follow "Other page"'
expect_check ok "the inventory check passes in this repository" inventory_check_passes "$here/../../.."

# .dev/ci/matrix.sh --list prints the combinations of the `test` job of ci.yml (SECBK_CI_YML names another file for the test),
# so a combination added to ci.yml is run by the matrix without a second list to keep.
matrix="$here/../../ci/matrix.sh"
matrix_yml() {
    local file="$work/matrix-ci.yml"
    {
        printf '%s\n' 'jobs:' '  other:' "    - {moodle: '9.9', php: '8.9', database: other}" '  test:' '    strategy:' '      matrix:' '        include:'
        printf '%s\n' "$@"
        printf '%s\n' '    services:' '  later:' "    - {moodle: '8.8', php: '8.8', database: later}"
    } > "$file"
    SECBK_CI_YML="$file" bash "$matrix" --list 2>&1
}
matrix_lists() {
    local expected="$1" out
    shift
    out="$(matrix_yml "$@")" && [ "$out" = "$expected" ]
}
expect_check ok "the matrix lists the rows of the test job only, in the order of ci.yml, with the PHP version of each row"     matrix_lists $'405 pgsql 8.3\n503 mariadb 8.4\n503 pgsql 8.3' \
        "          - {moodle: '4.5', branch: MOODLE_405_STABLE, php: '8.3', database: pgsql, image: 'postgres:13'}" \
        "          - {moodle: '5.3', branch: MOODLE_503_STABLE, php: '8.4', database: mariadb, image: 'mariadb:11.4'}" \
        "          - {moodle: '5.3', branch: MOODLE_503_STABLE, php: '8.3', database: pgsql, image: 'postgres:17'}"
expect_check ok "the matrix lists a combination that is added to ci.yml" \
    matrix_lists $'405 pgsql 8.3\n502 mysqli 8.4' \
        "          - {moodle: '4.5', branch: MOODLE_405_STABLE, php: '8.3', database: pgsql, image: 'postgres:13'}" \
        "          - {moodle: '5.2', branch: MOODLE_502_STABLE, php: '8.4', database: mysqli, image: 'mysql:8.4'}"
expect_check ng "the matrix fails when the test job has no combination" matrix_lists ''
matrix_real() {
    local listed rows
    listed="$(bash "$matrix" --list | wc -l)" || return 1
    rows="$(awk '/^  test:/ {in_test = 1} /^    services:/ {in_test = 0} in_test && /- \{moodle: / {n++} END {print n + 0}' \
        "$here/../../../.github/workflows/ci.yml")" || return 1
    [ "$listed" -eq "$rows" ] && [ "$listed" -gt 0 ]
}
expect_check ok "the matrix lists as many combinations as ci.yml has (the other jobs have rows of their own)" matrix_real

# The matrix removes an environment with its data only when its own run installed it: no marker of install.sh before the
# run and one after it. A copy of matrix.sh runs with stand-ins for the steps, and the stand-in of down.sh records how it
# was called. Usage: matrix_down <marker before: yes|no> <install: fails|installs|skips> <down.sh arguments> <ok|failed>
matrix_down() {
    local before="$1" install="$2" expected="$3" result="$4" root status step
    root="$work/matrix-down-$before-$install"
    mkdir -p "$root/repo/.dev/ci" "$root/repo/.dev/env" "$root/env" || return 1
    cp "$matrix" "$root/repo/.dev/ci/matrix.sh" || return 1
    printf '%s\n' 'secbk_php=8.4' 'COMPOSE_PROJECT_NAME=secbkstub' "SECBK_ENV_ROOT=$root/env" > "$root/repo/.dev/env/env.sh"
    for step in env/setup env/devconfig env/up ci/run; do
        printf '#!/usr/bin/env bash\nexit 0\n' > "$root/repo/.dev/$step.sh"
    done
    case $install in
        fails) printf '#!/usr/bin/env bash\nexit 1\n' ;;
        installs) printf '#!/usr/bin/env bash\ntouch "%s/env/installed-secbkstub"\n' "$root" ;;
        skips) printf '#!/usr/bin/env bash\nexit 0\n' ;;
    esac > "$root/repo/.dev/env/install.sh"
    printf '#!/usr/bin/env bash\necho "$*" >> "%s/down.log"\n' "$root" > "$root/repo/.dev/env/down.sh"
    chmod +x "$root"/repo/.dev/env/*.sh "$root"/repo/.dev/ci/*.sh || return 1
    if [ "$before" = yes ]; then touch "$root/env/installed-secbkstub" || return 1; fi
    printf '%s\n' 'jobs:' '  test:' \
        "          - {moodle: '5.3', branch: MOODLE_503_STABLE, php: '8.4', database: pgsql, image: 'postgres:17'}" > "$root/ci.yml"
    SECBK_CI_YML="$root/ci.yml" SECBK_MATRIX_KEEP='' bash "$root/repo/.dev/ci/matrix.sh" > "$root/out.log" 2>&1 < /dev/null
    status=$?
    [ "$(cat "$root/down.log")" = "$expected" ] || return 1
    if [ "$result" = ok ]; then [ "$status" -eq 0 ]; else [ "$status" -ne 0 ]; fi
}
expect_check ok "the matrix keeps the data of an environment without a marker whose install fails, and reports the failure" \
    matrix_down no fails '503 pgsql' failed
expect_check ok "the matrix removes with its data an environment that its own run installed" \
    matrix_down no installs '503 pgsql --volumes' ok
expect_check ok "the matrix only stops an environment that was installed before (it has a marker)" \
    matrix_down yes skips '503 pgsql' ok

echo "rules: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
