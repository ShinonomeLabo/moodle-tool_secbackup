#!/usr/bin/env bash
# Shared rules for commit messages and identities in this repository.
# Every message must be written in Japanese and must not contain any pattern listed in the
# local pattern file, and every commit must use the repository identity.
# The rules fail closed: when a tool they depend on fails, the message is rejected.
# perl is required: it checks the pattern list and the Japanese text. Without it every message is rejected,
# and install.sh refuses to enable the hooks.

# git reads a commit through the replacement refs (refs/replace/*), but a push sends the original object. The
# hooks have to check what is sent, so every git command that runs after this file is sourced ignores them.
export GIT_NO_REPLACE_OBJECTS=1

SECBACKUP_IDENTITY='Takayuki Fuwa <fuwa@shinonome.co.jp>'
secbackup_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
: "${SECBACKUP_PATTERNS_FILE:=$secbackup_root/.dev/hooks/patterns.local}"

# Prints the message as git stores it when it is edited: without the "#" lines, and without the scissors line of
# git commit -v and the diff below it (git drops that line and everything after it). Fails when the message
# cannot be read, also when sed fails after it printed a part.
secbackup_strip_comments() {
    LC_ALL=C sed -e '/^# ------------------------ >8 ------------------------$/,$d' -e '/^#/d' -- "$1"
}

# Returns 0 when the file is valid UTF-8 text without control characters other than tab, CR and LF.
# Anything else (UTF-16, binary data, a damaged file) cannot be trusted: the words read from it would
# silently not match. The whole file is checked at once, before any word is read.
secbackup_is_plain_text() {
    LC_ALL=C perl -MEncode -e '
        local $/;
        open(my $fh, "<:raw", $ARGV[0]) or exit 1;
        my $text = eval { Encode::decode("UTF-8", scalar <$fh>, Encode::FB_CROAK | Encode::LEAVE_SRC) };
        exit 1 unless defined $text;
        exit($text =~ /(?![\t\r\n])\p{Cc}/ ? 1 : 0);
    ' -- "$1"
}

# Prints the patterns of the local pattern file, one per line. The file may come from an editor or a
# secret, so a CR counts as a line break (CRLF lists work), a UTF-8 BOM at the start of a line is
# removed, blanks around a pattern are removed (blanks inside it are kept) and lines that are empty
# after that are skipped. A word must be printable ASCII (0x20-0x7E) once trimmed: any other character
# (a full-width or no-break space, a zero-width character, a tab inside the word, ...) could make it
# silently not match. Returns 1 when the file is not plain UTF-8 text, has such a word or cannot be read
# completely.
secbackup_patterns() {
    # Byte-wise, so that the blank trimming and the ASCII range below do not depend on the locale: bash 3.2
    # reads a range by the collation order of a UTF-8 locale, which makes it reject ordinary words.
    local LC_ALL=C line bom=$'\xef\xbb\xbf' statuses
    [ -s "$SECBACKUP_PATTERNS_FILE" ] || return 0
    secbackup_is_plain_text "$SECBACKUP_PATTERNS_FILE" || return 1
    LC_ALL=C tr '\r' '\n' 2>/dev/null < "$SECBACKUP_PATTERNS_FILE" |
        while IFS= read -r line || [ -n "$line" ]; do
            line="${line#"$bom"}"
            line="${line#"${line%%[![:blank:]]*}"}"
            line="${line%"${line##*[![:blank:]]}"}"
            if [ -n "$line" ]; then
                case $line in
                    *[!\ -~]*) exit 1 ;;
                esac
                printf '%s\n' "$line"
            fi
        done
    statuses=("${PIPESTATUS[@]}")
    [ "${statuses[0]}" -eq 0 ] && [ "${statuses[1]}" -eq 0 ]
}

# Usage: secbackup_check_message <file>. Returns 1 and prints the reason when the message breaks a rule.
secbackup_check_message() {
    local file="$1" body pattern patterns
    if ! patterns="$(secbackup_patterns)"; then
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) を読めませんでした。権限と、言葉が ASCII の印字できる文字だけで書かれていること (全角の空白や NUL などの制御文字がないこと) を確かめてください。" >&2
        return 1
    fi
    # Fail closed: a missing or empty list, or one without any usable pattern, rejects every message.
    if [ -z "$patterns" ]; then
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) がありません。用意するまでコミットできません。" >&2
        return 1
    fi
    if ! body="$(secbackup_strip_comments "$file")"; then
        echo "コミットのメッセージを読めませんでした。" >&2
        return 1
    fi
    if [ -z "$(printf '%s' "$body" | LC_ALL=C tr -d '[:space:]')" ]; then
        echo "コミットのメッセージが空です。" >&2
        return 1
    fi
    # The words are searched in every line of the file, "#" lines and the part below the scissors line included:
    # git removes them only when the message is edited, it keeps them for -m and -F, and the stored messages that
    # pre-push and check-history.sh read are final text. Only the empty and Japanese checks leave them out.
    # The search is byte-wise (LC_ALL=C), so that no locale can turn it into an error. grep exits with 0
    # when it finds the word, 1 when it does not and 2 or more when it fails.
    # The loop can fail without running a single round: before bash 5.1 a here-string is written to a temporary
    # file, and when that fails bash skips the loop. So the status of the loop is checked as well.
    while IFS= read -r pattern; do
        LC_ALL=C grep -qiF -- "$pattern" "$file"
        case $? in
            0)
                echo "コミットのメッセージに、使えない言葉が入っています。" >&2
                return 1
                ;;
            1) ;;
            *)
                echo "拒む言葉の一覧で探せませんでした。" >&2
                return 1
                ;;
        esac
    done <<< "$patterns" || {
        echo "拒む言葉の一覧で探せませんでした。" >&2
        return 1
    }
    if ! printf '%s' "$body" | perl -CSD -ne 'BEGIN { $found = 0 } $found = 1 if /[\p{Hiragana}\p{Katakana}\p{Han}]/; END { exit($found ? 0 : 1) }'; then
        echo "コミットのメッセージは日本語で書いてください (日本語の文字がありません)。" >&2
        return 1
    fi
    return 0
}

# Usage: secbackup_check_identity "<name> <email>". Returns 1 when it is not the repository identity.
secbackup_check_identity() {
    if [ "$1" != "$SECBACKUP_IDENTITY" ]; then
        echo "作者かコミッターが ${SECBACKUP_IDENTITY} ではありません: $1" >&2
        return 1
    fi
    return 0
}

# Usage: secbackup_check_commit <sha> <label>. Checks the stored message of a commit and its author and committer.
# Returns 1 when the commit breaks a rule or cannot be read; the reasons and "  -> <label>" go to the standard
# error. pre-push and check-history.sh both check a commit this way, so that the two gates cannot differ.
secbackup_check_commit() {
    local commit="$1" label="$2" tmp status=0
    # With a template, mktemp makes the file in TMPDIR on every system (without one, macOS ignores TMPDIR).
    if ! tmp="$(mktemp "${TMPDIR:-/tmp}/secbackup-message.XXXXXXXXXX")"; then
        echo "一時ファイルを作れませんでした。" >&2
        status=1
    elif ! git log -1 --format=%B "$commit" > "$tmp"; then
        # A message that was read only in part must not be checked.
        echo "コミットのメッセージを読めませんでした。" >&2
        status=1
    elif ! secbackup_check_message "$tmp"; then
        status=1
    fi
    [ -z "$tmp" ] || rm -f "$tmp"
    secbackup_check_identity "$(git log -1 --format='%an <%ae>' "$commit")" || status=1
    secbackup_check_identity "$(git log -1 --format='%cn <%ce>' "$commit")" || status=1
    [ "$status" -eq 0 ] && return 0
    echo "  -> $label" >&2
    return 1
}
