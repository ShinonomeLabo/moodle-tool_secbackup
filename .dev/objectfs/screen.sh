#!/usr/bin/env bash
# The status page in Chrome, with ChromeDriver and its WebDriver protocol only (curl and jq): logs in as admin, opens the status
# page of the plugin and waits for the dialog of the external file store, compares its text with the English strings of the
# plugin, closes it with OK, presses "Back up now" and waits for the answer. Development only (.dev/objectfs/run.sh).
# Usage: screen.sh <wwwroot> <file with the admin password> <folder for the screenshots> <lang/en/tool_secbackup.php>
# Prints "check <name> <ok|failed>" lines and saves 1-modal.png and 2-started.png; the exit status is 0 only when every check
# is ok. The password is typed into the page and never printed.
set -euo pipefail
www="${1:?wwwroot}"
passfile="${2:?password file}"
out="${3:?folder}"
lang="${4:?lang file}"
port=9515
driver="${CHROMEWEBDRIVER:+$CHROMEWEBDRIVER/}chromedriver"

"$driver" --port="$port" > "$out/chromedriver.log" 2>&1 &
driverpid=$!
trap 'kill "$driverpid" 2> /dev/null || true' EXIT
for _ in $(seq 1 40); do
    if curl -fs -o /dev/null "http://127.0.0.1:$port/status"; then break; fi
    sleep 0.5
done

# wd <method> <path> [JSON body]: one WebDriver command; prints the answer.
wd() { curl -s -X "$1" "http://127.0.0.1:$port$2" -H 'Content-Type: application/json' ${3:+-d "$3"}; }
session="$(wd POST /session '{"capabilities": {"alwaysMatch": {"browserName": "chrome", "goog:chromeOptions":
    {"args": ["--headless=new", "--no-sandbox", "--disable-dev-shm-usage", "--window-size=1280,1100"]}}}}' \
    | jq -r .value.sessionId)"
[ -n "$session" ] && [ "$session" != null ] || { cat "$out/chromedriver.log" >&2; exit 1; }
s="/session/$session"
go() { wd POST "$s/url" "$(jq -n --arg u "$1" '{url: $u}')" > /dev/null; }
# find <xpath>: the ID of the first element, or nothing.
find_xpath() {
    wd POST "$s/element" "$(jq -n --arg v "$1" '{using: "xpath", value: $v}')" \
        | jq -r '.value["element-6066-11e4-a52e-4f735466cecf"] // empty'
}
# wait_xpath <xpath> [seconds]: the ID of the element when it is there and shown, or nothing after the time.
wait_xpath() {
    local id shown
    for _ in $(seq 1 $(( ${2:-30} * 2 ))); do
        id="$(find_xpath "$1")"
        if [ -n "$id" ]; then
            shown="$(wd GET "$s/element/$id/displayed" | jq -r .value)"
            if [ "$shown" = true ]; then echo "$id"; return 0; fi
        fi
        sleep 0.5
    done
    return 0
}
type_into() { wd POST "$s/element/$1/value" "$(jq -n --arg t "$2" '{text: $t}')" > /dev/null; }
click() { wd POST "$s/element/$1/click" '{}' > /dev/null; }
text_of() { wd GET "$s/element/$1/text" | jq -r .value; }
shot() { wd GET "$s/screenshot" | jq -r .value | base64 -d > "$out/$1"; }
# The English string of the plugin with this ID, with {$a} replaced.
string() {
    php -r 'define("MOODLE_INTERNAL", true); $string = []; include $argv[1];
        echo str_replace("{\$a}", $argv[3], $string[$argv[2]]);' "$lang" "$1" "${2:-}"
}
# Text with its white space made single spaces, to compare a page with a string.
flat() { tr -s ' \n\t' '   ' | sed 's/^ //; s/ $//'; }
failed=0
check() {
    echo "check $1 $2"
    [ "$2" = ok ] || failed=1
}

go "$www/login/index.php"
type_into "$(wait_xpath '//input[@id="username"]')" admin
type_into "$(wait_xpath '//input[@id="password"]')" "$(cat "$passfile")"
click "$(wait_xpath '//button[@id="loginbtn"]')"
sleep 2
go "$www/admin/tool/secbackup/index.php"
dialog="$(wait_xpath '//div[contains(@class, "modal-dialog")]' 60)"
sleep 1
shot 1-modal.png
if [ -n "$dialog" ]; then
    shown="$(text_of "$dialog" | flat)"
    title="$(string modal_external_title | flat)"
    body="$(string modal_external 'Example Platform' | flat)"
    case "$shown" in *"$title"*"$body"*) check modal ok ;; *) check modal failed; echo "dialog text: $shown" ;; esac
else
    check modal failed
fi
# The button is under the dialog: it is pressed after the dialog is closed, as a person would.
ok="$(wait_xpath '//div[contains(@class, "modal-dialog")]//button[normalize-space()="OK"]' 10)"
[ -z "$ok" ] || click "$ok"
sleep 1
button="$(wait_xpath '//button[normalize-space()="Back up now"]' 30)"
if [ -n "$button" ]; then
    click "$button"
    queued="$(string start_started | flat)"
    notice="$(wait_xpath "//*[contains(normalize-space(), \"$queued\")]" 60)"
    sleep 1
    shot 2-started.png
    if [ -n "$notice" ]; then check started ok; else check started failed; fi
else
    shot 2-started.png
    check started failed
fi
wd DELETE "$s" > /dev/null || true
exit "$failed"
