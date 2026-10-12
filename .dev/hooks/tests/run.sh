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
    out="$(cd "$copy" && "$BASH" .dev/hooks/install.sh 2>&1)" || return 1
    case "$out" in *'hooks enabled: .dev/hooks'*) ;; *) return 1 ;; esac
    # Only what install.sh wrote into the repository counts: the configuration of the machine may hold the same values.
    [ "$(git -C "$copy" config --local core.hooksPath)" = '.dev/hooks' ] \
        && [ "$(git -C "$copy" config --local user.name)" = 'Takayuki Fuwa' ] \
        && [ "$(git -C "$copy" config --local user.email)" = 'fuwa@shinonome.co.jp' ]
}
expect_check() {
    local expected="$1" label="$2"
    shift 2
    if "$@" >/dev/null 2>&1; then actual=ok; else actual=ng; fi
    if [ "$actual" = "$expected" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL check: $label (expected $expected, got $actual)"; fi
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

echo "rules: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
