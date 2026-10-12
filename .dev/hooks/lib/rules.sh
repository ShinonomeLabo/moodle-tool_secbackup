#!/usr/bin/env bash
# Shared rules for commit messages and identities in this repository.
# Every message must be written in Japanese and must not contain any pattern listed in the
# local pattern file, and every commit must use the repository identity.

SECBACKUP_IDENTITY='Takayuki Fuwa <fuwa@shinonome.co.jp>'
secbackup_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
: "${SECBACKUP_PATTERNS_FILE:=$secbackup_root/.dev/hooks/patterns.local}"

# Prints the message without git comment lines.
secbackup_strip_comments() {
    grep -v '^#' "$1" || true
}

# Usage: secbackup_check_message <file>. Returns 1 and prints the reason when the message breaks a rule.
secbackup_check_message() {
    local file="$1" body pattern
    if [ ! -s "$SECBACKUP_PATTERNS_FILE" ]; then
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) がありません。用意するまでコミットできません。" >&2
        return 1
    fi
    body="$(secbackup_strip_comments "$file")"
    if [ -z "$(printf '%s' "$body" | tr -d '[:space:]')" ]; then
        echo "コミットのメッセージが空です。" >&2
        return 1
    fi
    while IFS= read -r pattern || [ -n "$pattern" ]; do
        [ -z "$pattern" ] && continue
        if printf '%s' "$body" | grep -qiF -- "$pattern"; then
            echo "コミットのメッセージに、使えない言葉が入っています。" >&2
            return 1
        fi
    done < "$SECBACKUP_PATTERNS_FILE"
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
