#!/usr/bin/env bash
# Checks that no PHP file under .dev/ can be run by a request to the web server of a site.
# .dev/ is left out of release archives, but a site installed with git clone has it under its web root, and a request
# without login would run the files there. So every PHP file starts - after the opening tag, blank lines and comments -
# with one of the guards below:
#   if (PHP_SAPI !== 'cli') {            command line scripts
#   if (PHP_SAPI !== 'cli-server') {     the development server (php -S) of .dev/fakesec
#   define('CLI_SCRIPT', true);          Moodle scripts: Moodle refuses to run them from the web
#   if (!is_file('/etc/secbk-NAME')) {   scripts that a test machine's web server must run (secureos/probe.php): they
#                                        answer 404 unless a marker file under /etc exists. Only root can create a file
#                                        there and a production site never has one, so a clone under a web root is inert.
#                                        The marker check must be the first code line, and the path must be /etc/secbk-...
# Two places are not checked, for these reasons:
#   coverage/collector.php            is loaded into web requests by design (auto_prepend_file) and does nothing unless
#                                     coverage/out/raw/ENABLED exists, which only the coverage run makes.
#   coverage/tests/fixtures/          holds data for the tests of the coverage tools, not scripts that anyone runs.
# Usage: .dev/hooks/check-dev-guards.sh [directory]   (default: the .dev directory of this repository)
set -u
here="$(cd "$(dirname "$0")" && pwd)"
root="${1:-$here/..}"
root="$(cd "$root" && pwd)"

# Prints the first line of code of a PHP file: not the opening tag, not blank, not inside a comment.
first_code_line() {
    awk '
        incomment { if (index($0, "*/") > 0) { incomment = 0 } next }
        /^<\?php[ \t]*$/ { next }
        /^[ \t]*$/ { next }
        /^[ \t]*(\/\/|#)/ { next }
        /^[ \t]*\/\*/ { if (index($0, "*/") == 0) { incomment = 1 } next }
        { sub(/[ \t]+$/, ""); print; exit }
    ' "$1"
}

status=0
count=0
while IFS= read -r -d '' file; do
    relative="${file#"$root"/}"
    case "$relative" in
        coverage/collector.php | coverage/tests/fixtures/* | coverage/out/*) continue ;;
    esac
    count=$((count + 1))
    line="$(first_code_line "$file")"
    case "$line" in
        "if (PHP_SAPI !== 'cli') {" | "if (PHP_SAPI !== 'cli-server') {" | "define('CLI_SCRIPT', true);") ;;
        *)
            if [[ "$line" =~ ^if\ \(!is_file\(\'/etc/secbk-[a-z0-9-]+\'\)\)\ \{$ ]]; then
                continue
            fi
            echo "no guard at the top of .dev/$relative (first code line: ${line:-none})" >&2
            status=1
            ;;
    esac
done < <(find "$root" -name '*.php' -print0)
if [ "$count" -eq 0 ]; then
    echo "no PHP file found under $root" >&2
    exit 1
fi
[ "$status" -eq 0 ] && echo "dev guards ok ($count files)"
exit "$status"
