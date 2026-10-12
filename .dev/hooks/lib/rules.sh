#!/usr/bin/env bash
# Shared rules for commit messages and identities in this repository.
# Every message must be written in Japanese and must not contain any pattern listed in the
# local pattern file, and every commit must use the repository identity.
# The rules fail closed: when a tool they depend on fails, the message is rejected.

SECBACKUP_IDENTITY='Takayuki Fuwa <fuwa@shinonome.co.jp>'
secbackup_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
: "${SECBACKUP_PATTERNS_FILE:=$secbackup_root/.dev/hooks/patterns.local}"

# Prints the message without git comment lines. Returns 1 when the message cannot be read: grep exits
# with 1 when no line is left, which is not an error here, and with 2 or more when it fails.
secbackup_strip_comments() {
    local status=0
    LC_ALL=C grep -v '^#' "$1" || status=$?
    [ "$status" -le 1 ]
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
# after that are skipped. Returns 1 when the file is not plain UTF-8 text or cannot be read completely.
secbackup_patterns() {
    local line bom=$'\xef\xbb\xbf' statuses
    [ -s "$SECBACKUP_PATTERNS_FILE" ] || return 0
    secbackup_is_plain_text "$SECBACKUP_PATTERNS_FILE" || return 1
    LC_ALL=C tr '\r' '\n' 2>/dev/null < "$SECBACKUP_PATTERNS_FILE" |
        while IFS= read -r line || [ -n "$line" ]; do
            line="${line#"$bom"}"
            line="${line#"${line%%[![:blank:]]*}"}"
            line="${line%"${line##*[![:blank:]]}"}"
            if [ -n "$line" ]; then
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
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) を読めませんでした。権限と、UTF-8 の文字だけで書かれていること (NUL などの制御文字がないこと) を確かめてください。" >&2
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
    # The words are searched byte-wise (LC_ALL=C), so that no locale can turn the search into an error.
    # grep exits with 0 when it finds the word, 1 when it does not and 2 or more when it fails.
    while IFS= read -r pattern; do
        printf '%s' "$body" | LC_ALL=C grep -qiF -- "$pattern"
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
    done <<< "$patterns"
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
