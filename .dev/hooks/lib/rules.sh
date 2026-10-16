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

# The temporary file of secbackup_check_commit. Its name is kept here, not in a local variable, so that an entry
# point can remove the file when a signal stops it: an entry point that calls secbackup_check_commit runs
# "trap secbackup_remove_message_file EXIT" (bash runs an EXIT trap also when SIGINT, SIGTERM or SIGHUP ends it).
SECBACKUP_MESSAGE_FILE=

# Removes the temporary file of secbackup_check_commit, if there is one.
secbackup_remove_message_file() {
    [ -z "$SECBACKUP_MESSAGE_FILE" ] || rm -f "$SECBACKUP_MESSAGE_FILE"
    SECBACKUP_MESSAGE_FILE=
}

# Usage: secbackup_check_commit <sha> <label>. Checks the stored message of a commit and its author and committer.
# Returns 1 when the commit breaks a rule or cannot be read; the reasons and "  -> <label>" go to the standard
# error. pre-push and check-history.sh both check a commit this way, so that the two gates cannot differ. The
# caller has to run "trap secbackup_remove_message_file EXIT" (see above).
secbackup_check_commit() {
    local commit="$1" label="$2" status=0
    # With a template, mktemp makes the file in TMPDIR on every system (without one, macOS ignores TMPDIR).
    if ! SECBACKUP_MESSAGE_FILE="$(mktemp "${TMPDIR:-/tmp}/secbackup-message.XXXXXXXXXX")"; then
        echo "一時ファイルを作れませんでした。" >&2
        status=1
    elif ! git log -1 --format=%B "$commit" > "$SECBACKUP_MESSAGE_FILE"; then
        # A message that was read only in part must not be checked.
        echo "コミットのメッセージを読めませんでした。" >&2
        status=1
    elif ! secbackup_check_message "$SECBACKUP_MESSAGE_FILE"; then
        status=1
    fi
    secbackup_remove_message_file
    secbackup_check_identity "$(git log -1 --format='%an <%ae>' "$commit")" || status=1
    secbackup_check_identity "$(git log -1 --format='%cn <%ce>' "$commit")" || status=1
    [ "$status" -eq 0 ] && return 0
    echo "  -> $label" >&2
    return 1
}

# Prints the words of the local pattern file, one per line, for a search of the contents. Fails with the reason when the list
# cannot be read or holds no word, as secbackup_check_message does: a search without words would let everything through.
secbackup_scan_patterns() {
    local patterns
    if ! patterns="$(secbackup_patterns)"; then
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) を読めませんでした。権限と、言葉が ASCII の印字できる文字だけで書かれていること (全角の空白や NUL などの制御文字がないこと) を確かめてください。" >&2
        return 1
    fi
    if [ -z "$patterns" ]; then
        echo "拒む言葉の一覧 ($SECBACKUP_PATTERNS_FILE) がありません。用意するまで中身を確かめられません。" >&2
        return 1
    fi
    printf '%s\n' "$patterns"
}

# Usage: printf '%s\n' <text> | secbackup_holds_word <words>. Returns 0 when a line of the text holds one of the words, 1 when
# none does, and 2 or more when the search fails. Byte-wise and case-insensitive, like the search of the messages.
secbackup_holds_word() {
    LC_ALL=C grep -q -i -F -f <(printf '%s\n' "$1")
}

# Prints a name that may be shown: the name, or a stand-in when the name holds one of the words or cannot be searched. The log
# of a public repository is public, and so would be a word printed into it.
secbackup_shown_name() {
    local found=0
    printf '%s\n' "$1" | secbackup_holds_word "$2" || found=$?
    if [ "$found" -eq 1 ]; then
        printf '%s' "$1"
    else
        printf '%s' '(使えない言葉が入った名前)'
    fi
}

# Usage: secbackup_check_tree <revision> <label> <words>. Searches the files of a revision, and the names of its files, for the
# words. A hit prints the label, the name of the file and the number of its lines that hold a word, never a line: the line
# holds the word. Returns 1 when a word is found, and when a search cannot run (fail closed). Binary files are not searched
# (git grep -I). The settings of git that would change the output read here are given on the command line.
secbackup_check_tree() {
    local revision="$1" label="$2" words="$3" out names entry name found=0 result=0
    out="$(LC_ALL=C git -c grep.lineNumber=false -c grep.column=false -c grep.fullName=true -c core.quotePath=true \
        grep -I -i -F -c --no-color -f <(printf '%s\n' "$words") "$revision" --)" || found=$?
    case $found in
        0)
            while IFS= read -r entry; do
                entry="${entry#"$revision":}"
                name="${entry%:*}"
                echo "ファイルに使えない言葉が入っています: $label $(secbackup_shown_name "$name" "$words") (${entry##*:} 行)" >&2
            done <<< "$out"
            result=1
            ;;
        1) ;;
        *)
            echo "ファイルの中身を拒む言葉の一覧で探せませんでした: $label" >&2
            return 1
            ;;
    esac
    if ! names="$(git -c core.quotePath=true ls-tree -r --name-only "$revision")"; then
        echo "ファイルの名前の一覧を取れませんでした: $label" >&2
        return 1
    fi
    found=0
    printf '%s\n' "$names" | secbackup_holds_word "$words" || found=$?
    case $found in
        0)
            echo "ファイルの名前に使えない言葉が入っています: $label" >&2
            result=1
            ;;
        1) ;;
        *)
            echo "ファイルの名前を拒む言葉の一覧で探せませんでした: $label" >&2
            return 1
            ;;
    esac
    return "$result"
}

# Usage: secbackup_check_tag <object> <ref name> <words>. Checks a tag: its name, and for an annotated tag its message and its
# tagger by the rules of a commit (an annotated tag that points to another one is followed). The commits a tag points to are
# not checked here; the callers check them with the other commits. A tag that leads to anything but a commit is refused: its
# contents would not be checked. Returns 1 and prints the reasons when the tag breaks a rule or cannot be read. The caller has to
# run "trap secbackup_remove_message_file EXIT" (see secbackup_check_commit).
secbackup_check_tag() {
    local object="$1" label words="$3" type text status=0 found=0
    label="$(secbackup_shown_name "$2" "$words")"
    printf '%s\n' "${2#refs/tags/}" | secbackup_holds_word "$words" || found=$?
    if [ "$found" -ne 1 ]; then
        echo "タグの名前に使えない言葉が入っているか、名前を探せませんでした: $label" >&2
        status=1
    fi
    while :; do
        if ! type="$(git cat-file -t "$object")"; then
            echo "タグを読めませんでした: $label" >&2
            return 1
        fi
        [ "$type" = tag ] || break
        if ! text="$(git cat-file tag "$object")"; then
            echo "タグを読めませんでした: $label" >&2
            return 1
        fi
        # With a template, mktemp makes the file in TMPDIR on every system (as in secbackup_check_commit).
        if ! SECBACKUP_MESSAGE_FILE="$(mktemp "${TMPDIR:-/tmp}/secbackup-message.XXXXXXXXXX")"; then
            echo "一時ファイルを作れませんでした。" >&2
            return 1
        fi
        # The message is everything after the first empty line of the tag object, a signature included.
        if ! printf '%s\n' "$text" | LC_ALL=C sed -e '1,/^$/d' > "$SECBACKUP_MESSAGE_FILE"; then
            echo "タグのメッセージを読めませんでした: $label" >&2
            secbackup_remove_message_file
            return 1
        fi
        secbackup_check_message "$SECBACKUP_MESSAGE_FILE" || status=1
        secbackup_remove_message_file
        secbackup_check_identity "$(printf '%s\n' "$text" \
            | LC_ALL=C sed -n -e '/^$/q' -e 's/^tagger \(.*\) [0-9][0-9]* [-+][0-9][0-9][0-9][0-9]$/\1/p')" || status=1
        object="$(printf '%s\n' "$text" | LC_ALL=C sed -n -e '/^$/q' -e 's/^object //p')"
        if [ -z "$object" ]; then
            echo "タグが指すものを読めませんでした: $label" >&2
            return 1
        fi
    done
    if [ "$type" != commit ]; then
        echo "タグがコミット以外 ($type) を指しています。中身を確かめられないので、受け付けません: $label" >&2
        status=1
    fi
    [ "$status" -eq 0 ] && return 0
    echo "  -> $label" >&2
    return 1
}
