# shellcheck shell=bash
# Shared by .dev/e2e/run.sh (sourced, not run). Development only. It runs on a disposable Ubuntu runner with sudo, PHP (with
# curl, pgsql and sodium), the PostgreSQL client and a PostgreSQL server that trusts the user postgres on 127.0.0.1: the jobs
# of the staging end-to-end test. Nothing here is meant for a machine that someone uses: it changes the firewall of the
# machine and keeps all its CPUs busy.
#
# The site is the one of .dev/storage/lib.sh (Moodle in $SECBK_STORAGE_DIR/moodle, the database moodle on 127.0.0.1), with
# the address https://<name>.moodle.example.invalid. The files of a scenario are in $SECBK_E2E_OUT/<name>:
#   result.json   what run.sh found (see e2e_write_result); the workflow keeps it
#   inspect.json  what the service said about the site at the end (sec.php inspect); the workflow keeps it
#   logs/         the output of the commands, the answers of the service at the other checkpoints, the lists of the result
#   private/      mode 0700: the registration code (removed once it is used) and the keys of the site, mode 0600; nothing
#                 prints them and the workflow does not keep them
# What is printed are steps, states and numbers: never the address of the service, the secret, the code or a key.

e2e_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=../storage/lib.sh
. "$e2e_dir/../storage/lib.sh"
SECBK_E2E_OUT="${SECBK_E2E_OUT:-${RUNNER_TEMP:-/tmp}/secbk-e2e}"
# Seconds that one call of cli/backup.php may take.
e2e_backup_timeout="${SECBK_E2E_BACKUP_TIMEOUT:-7200}"
# Seconds from a stopped run to the next one. Some minutes after a run ends (about 17), the service lists again what it holds
# of the site; a run that starts before that does not know what the stopped run sent.
e2e_recount_wait="${SECBK_E2E_RECOUNT_WAIT:-1020}"
# Name of the chain of iptables that refuses the connections to S3.
e2e_chain=secbk-e2e-s3

e2e_step_name='the start'
e2e_finished=no
e2e_pid=''
e2e_log=''
e2e_exit=0
e2e_site=''
e2e_load_pids=()
e2e_s3_blocked=no
e2e_run_id=''
e2e_run_stage=''
e2e_run_status=''
e2e_run_code=''
e2e_run_bytes=0
e2e_run_estimate=0

# Prints the name of a step with the time, so that the log of the job shows where a scenario is.
e2e_step() {
    e2e_step_name="$*"
    echo "== $(date -u +%H:%M:%S) $*"
}

# Records a condition that does not hold: the scenario fails, and the line goes to the reasons of its result.
e2e_fail() {
    local text="${*//$'\n'/ }"
    echo "FAIL: $text"
    printf '%s\n' "$text" >> "$e2e_logs/reasons.txt"
}

# e2e_duration <name> <start>: records the seconds from <start> (UNIX time) to now.
e2e_duration() {
    printf '%s %d\n' "$1" $(( $(date +%s) - $2 )) >> "$e2e_logs/durations.txt"
}

# e2e_number <name> <whole number>: records a number for the result.
e2e_number() {
    printf '%s %d\n' "$1" "$2" >> "$e2e_logs/numbers.txt"
}

# e2e_begin <scenario> <name>: makes the folders of the scenario and sets the trap that cleans up and writes the result.
e2e_begin() {
    e2e_scenario="$1"
    e2e_name="$2"
    e2e_folder="$SECBK_E2E_OUT/$e2e_name"
    e2e_logs="$e2e_folder/logs"
    e2e_private="$e2e_folder/private"
    mkdir -p "$e2e_logs" "$e2e_private"
    chmod 700 "$e2e_private"
    rm -f "$e2e_folder/result.json" "$e2e_folder/inspect.json"
    : > "$e2e_logs/reasons.txt"
    : > "$e2e_logs/durations.txt"
    : > "$e2e_logs/numbers.txt"
    e2e_started="$(date +%s)"
    trap e2e_end EXIT
    # A cancelled job stops with a signal: the trap of EXIT then cleans up all the same.
    trap 'exit 143' TERM
    trap 'exit 130' INT
}

# Marks the scenario as run to its end. Without it, the result says at which step the script stopped.
e2e_finish() {
    e2e_finished=yes
}

# The trap of EXIT: stops the load, opens the firewall again, stops a backup that still runs, removes the registration code,
# and writes the result. The script ends with 0 only when the scenario ran to its end and every condition held.
e2e_end() {
    local status=$?
    set +e
    e2e_load_stop
    e2e_s3_unblock
    if [ -n "$e2e_pid" ] && e2e_alive "$e2e_pid"; then
        kill "$e2e_pid" 2> /dev/null
    fi
    rm -f "$e2e_private/code"
    if [ "$e2e_finished" != yes ]; then
        e2e_fail "the script stopped (exit $status) at the step: $e2e_step_name"
    fi
    e2e_duration total "$e2e_started"
    e2e_write_result
    if [ -s "$e2e_logs/reasons.txt" ]; then
        echo "result: FAILED ($(wc -l < "$e2e_logs/reasons.txt" | tr -d ' ') reasons in result.json)"
        exit 1
    fi
    echo "result: passed"
    exit 0
}

# Writes result.json: {scenario, name, passed, reasons, sent_bytes, durations, retries, numbers, requested_bytes}.
# sent_bytes is objects.bytes of inspect.json (what the service holds of the site at the end; 0 without it), retries counts
# the lines of the output of cli/backup.php that say that it waited ("Waiting until", for example when the service asked it
# to come back later) or that a step failed, and requested_bytes lists the bytes that each run of the site asked the
# service for. passed is true when no reason was recorded.
e2e_write_result() {
    # shellcheck disable=SC2016
    php -r '
        [, $folder, $scenario, $name] = $argv;
        $lines = static function (string $file): array {
            $text = is_file($file) ? (string) file_get_contents($file) : "";
            return array_values(array_filter(explode("\n", $text), "strlen"));
        };
        $pairs = static function (string $file) use ($lines): array {
            $values = [];
            foreach ($lines($file) as $line) {
                if (preg_match("/^([a-z0-9_]+) (-?[0-9]+)\z/", $line, $m) === 1) {
                    $values[$m[1]] = (int) $m[2];
                }
            }
            return $values;
        };
        $reasons = $lines("$folder/logs/reasons.txt");
        $file = "$folder/inspect.json";
        $inspection = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $runs = is_array($inspection) && is_array($inspection["runs"] ?? null) ? $inspection["runs"] : [];
        $waits = 0;
        $failed = 0;
        foreach (glob("$folder/logs/backup-*.log") ?: [] as $log) {
            $text = (string) file_get_contents($log);
            $waits += preg_match_all("/^Waiting until /m", $text);
            $failed += preg_match_all("/^A step of the backup failed /m", $text);
        }
        $result = [
            "scenario" => $scenario,
            "name" => $name,
            "passed" => $reasons === [],
            "reasons" => $reasons,
            "sent_bytes" => is_array($inspection) && is_int($inspection["objects"]["bytes"] ?? null)
                ? $inspection["objects"]["bytes"] : 0,
            "durations" => (object) $pairs("$folder/logs/durations.txt"),
            "retries" => ["waits" => $waits, "failed_steps" => $failed],
            "numbers" => (object) $pairs("$folder/logs/numbers.txt"),
            "requested_bytes" => array_map(static fn($run) => is_array($run) ? ($run["requested_bytes"] ?? null) : null, $runs),
        ];
        $text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents("$folder/result.json.part", $text);
        rename("$folder/result.json.part", "$folder/result.json");
    ' "$e2e_folder" "$e2e_scenario" "$e2e_name"
}

# e2e_check_disk <files GB>: stops the scenario before anything is made when the disk that will hold the site has less room
# than the files (GB of 10^9 bytes), 3 GB for Moodle, its database and the temporary files of the backup, and the free space
# that the plugin keeps (it does not back up below the larger of 10 % of the disk and 5 GiB).
e2e_check_disk() {
    local free size need
    read -r free size < <(df -B1 --output=avail,size "$SECBK_STORAGE_DIR" | tail -n 1)
    # shellcheck disable=SC2016
    need="$(php -r 'echo (int) ceil((float) $argv[1] * 1e9) + 3000000000 + max(intdiv((int) $argv[2], 10), 5 * 1073741824);' \
        "$1" "$size")"
    echo "disk: $free bytes free of $size; $need needed for $1 GB of files"
    if [ "$free" -lt "$need" ]; then
        e2e_fail "the disk has $free bytes free, $need are needed for $1 GB of files"
        return 1
    fi
}

# e2e_config wwwroot <URL> | e2e_config service <key>: sets the address of the site in config.php, or puts the settings of the
# connection to the service in the place of the development settings that storage_install wrote (those of the fake service).
# As .dev/env/remote-service.sh writes them for an https URL: the key that the service signs its answers with (the standard
# base64 of 32 bytes) is the one trusted key, and the network checks of the plugin stay on. config.php is written next to
# itself and renamed.
e2e_config() {
    local code
    code="$(cat <<'PHP'
[, $file, $what, $value] = $argv;
$text = (string) file_get_contents($file);
if ($what === 'wwwroot' && preg_match('~^https://[a-z0-9.-]+\z~', $value) === 1) {
    $pattern = '~^\$CFG->wwwroot\s*=\s*\'[^\']*\';~m';
    $block = '$CFG->wwwroot = ' . var_export($value, true) . ';';
} else if ($what === 'service' && ($raw = base64_decode($value, true)) !== false && strlen($raw) === 32
    && base64_encode($raw) === $value) {
    // The block of storage_install, or the one that an earlier call wrote.
    $pattern = '~^// tool_secbackup (development settings: the fake backup service\.|remote service settings[^\n]*)\n'
        . 'define\(\'TOOL_SECBACKUP_DEVELOPER\', \[\n.*?^\]\);\n(// End of tool_secbackup remote service settings\.\n)?~ms';
    $block = "// tool_secbackup remote service settings (written by .dev/e2e/lib.sh).\n"
        . "define('TOOL_SECBACKUP_DEVELOPER', [\n    'trustedkeys' => ['$value'],\n]);\n"
        . "// End of tool_secbackup remote service settings.\n";
} else {
    fwrite(STDERR, "e2e_config: $what is not a setting of this function, or its value is not valid\n");
    exit(1);
}
$text = preg_replace_callback($pattern, static fn(array $m): string => $block, $text, -1, $count);
if ($count !== 1) {
    fwrite(STDERR, "e2e_config: config.php does not have the one place for $what\n");
    exit(1);
}
$part = $file . '.e2e.part';
if (@file_put_contents($part, $text) !== strlen($text) || !chmod($part, fileperms($file) & 0777) || !rename($part, $file)) {
    @unlink($part);
    fwrite(STDERR, "e2e_config: config.php cannot be written\n");
    exit(1);
}
PHP
)"
    php -r "$code" "$secbk_moodle/config.php" "$1" "$2"
}

# e2e_build_site <name> <files GB> [--skip=<component>[,<component>...]]...: Moodle 4.5 with the plugin and the extra plugins
# (the ones that --skip names are left out), installed on PostgreSQL, with the address https://<name>.moodle.example.invalid,
# dummy data, the data of the extra plugins and files of <files GB> GB (10^9 bytes) in all; then check-data.php.
e2e_build_site() {
    local name="$1" gb="$2" since
    shift 2
    since="$(date +%s)"
    e2e_step "Moodle MOODLE_405_STABLE with the plugin"
    storage_moodle MOODLE_405_STABLE
    e2e_step "the site on PostgreSQL"
    storage_install "$SECBK_STORAGE_DIR/moodledata"
    e2e_step "the extra plugins"
    bash "$e2e_dir/install-plugins.sh" "$secbk_moodle" "$@"
    e2e_step "the upgrade of Moodle, which installs them"
    if ! storage_php admin/cli/upgrade.php --non-interactive > "$e2e_logs/upgrade.log" 2>&1; then
        tail -n 40 "$e2e_logs/upgrade.log"
        return 1
    fi
    tail -n 3 "$e2e_logs/upgrade.log"
    e2e_step "the address https://$name.moodle.example.invalid"
    e2e_config wwwroot "https://$name.moodle.example.invalid"
    storage_php admin/cli/purge_caches.php
    e2e_step "dummy data, the data of the extra plugins and $gb GB of files"
    storage_php "$(storage_plugin)/.dev/env/generate_site_data.php" --size=S --plugin-data --files-gb="$gb"
    e2e_step "the check of the data"
    storage_php "$(storage_plugin)/.dev/e2e/check-data.php" --files-gb="$gb" "$@"
    e2e_duration build "$since"
}

# The public address that this runner sends from (one line of checkip.amazonaws.com), which the service is told to allow.
# It is not printed.
e2e_source_ip() {
    local ip
    ip="$(curl -fsS --max-time 30 --retry 3 https://checkip.amazonaws.com | tr -d '[:space:]')" || return 1
    # shellcheck disable=SC2016
    if ! php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP) === false ? 1 : 0);' "$ip"; then
        echo "the address that this runner sends from could not be found" >&2
        return 1
    fi
    printf '%s' "$ip"
}

# e2e_site_field <field>: a field of private/site.json.
e2e_site_field() {
    # shellcheck disable=SC2016
    php -r '$site = json_decode((string) file_get_contents($argv[1]), true); echo is_array($site) ? ($site[$argv[2]] ?? "") : "";' \
        "$e2e_private/site.json" "$1"
}

# Registers the site with enroll.php. The values go to it on its standard input, so that none of them is an argument of a
# process; the connection URL of the plugin is the URL of the API with /v1. The code is removed after it, used or not.
e2e_enroll() {
    local status=0
    # shellcheck disable=SC2016
    php -r '
        $site = json_decode((string) file_get_contents($argv[1] . "/site.json"), true);
        echo json_encode([
            "url" => rtrim((string) getenv("SECBK_E2E_BASE_URL"), "/") . "/v1",
            "site" => is_array($site) ? (string) ($site["site_uuid"] ?? "") : "",
            "key" => is_array($site) ? (string) ($site["site_public_key"] ?? "") : "",
            "code" => trim((string) @file_get_contents($argv[1] . "/code")),
        ]);
    ' "$e2e_private" | storage_php "$(storage_plugin)/.dev/e2e/enroll.php" || status=$?
    rm -f "$e2e_private/code"
    return "$status"
}

# e2e_connect <name>: the test site at the service (sec.php provision, from the address of this runner), the key of the
# service in config.php, and the registration.
e2e_connect() {
    local name="$1" since ip
    since="$(date +%s)"
    e2e_step "the address that this runner sends from"
    ip="$(e2e_source_ip)"
    e2e_step "the test site at the service"
    php "$e2e_dir/sec.php" provision --name="$name" --moodle-url="https://$name.moodle.example.invalid" --source-ip="$ip" \
        --out="$e2e_private"
    e2e_site="$(e2e_site_field site_uuid)"
    e2e_step "the key of the service in config.php"
    e2e_config service "$(e2e_site_field signing_public_key)"
    e2e_step "the registration"
    e2e_enroll
    e2e_duration connect "$since"
}

# Reads the latest run of the plugin on the site into e2e_run_id, e2e_run_stage, e2e_run_status, e2e_run_code,
# e2e_run_bytes (the bytes it uploaded) and e2e_run_estimate (the bytes it expected to send); empty and 0 when there is none.
e2e_read_run() {
    local row
    row="$(psql -h 127.0.0.1 -U postgres -d moodle -AtF '|' -c "SELECT id, stage, status, COALESCE(abortcode, ''),
        bytesuploaded, COALESCE(estimatedbytes, 0) FROM mdl_tool_secbackup_runs ORDER BY id DESC LIMIT 1" 2> /dev/null || true)"
    IFS='|' read -r e2e_run_id e2e_run_stage e2e_run_status e2e_run_code e2e_run_bytes e2e_run_estimate <<< "$row"
    [[ "$e2e_run_bytes" =~ ^[0-9]+$ ]] || e2e_run_bytes=0
    [[ "$e2e_run_estimate" =~ ^[0-9]+$ ]] || e2e_run_estimate=0
}

# Prints the state of the latest run.
e2e_progress() {
    e2e_read_run
    if [ -n "$e2e_run_id" ]; then
        echo "  run $e2e_run_id: stage $e2e_run_stage, $e2e_run_status${e2e_run_code:+ ($e2e_run_code)}," \
            "$e2e_run_bytes of about $e2e_run_estimate bytes sent"
    fi
}

# e2e_alive <pid>: whether the process runs (a process that has ended and is not yet waited for does not).
e2e_alive() {
    local state
    state="$(ps -o stat= -p "$1" 2> /dev/null)" || return 1
    [[ "$state" != Z* ]]
}

# e2e_backup_start <log> [--now]: starts cli/backup.php in the background, with its output in <log>. e2e_pid is the process
# of php itself, so that kill -9 reaches it.
e2e_backup_start() {
    e2e_log="$1"
    shift
    echo "cli/backup.php $* in the background"
    (cd "$(storage_docroot)" && exec php -d memory_limit=-1 "$(storage_plugin)/cli/backup.php" "$@") >> "$e2e_log" 2>&1 &
    e2e_pid=$!
}

# e2e_wait_exit <seconds>: waits until the command of e2e_backup_start ends, and sets e2e_exit to its exit code (124 when
# it took more than <seconds>: it is stopped then). Prints the state of the run every minute and the output of the command
# at the end.
e2e_wait_exit() {
    local deadline next=0
    deadline=$(( $(date +%s) + $1 ))
    e2e_exit=''
    while e2e_alive "$e2e_pid"; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            kill "$e2e_pid" 2> /dev/null || true
            sleep 10
            kill -9 "$e2e_pid" 2> /dev/null || true
            wait "$e2e_pid" 2> /dev/null || true
            e2e_exit=124
            break
        fi
        if [ "$(date +%s)" -ge "$next" ]; then
            e2e_progress
            next=$(( $(date +%s) + 60 ))
        fi
        sleep 2
    done
    if [ -z "$e2e_exit" ]; then
        e2e_exit=0
        wait "$e2e_pid" || e2e_exit=$?
    fi
    e2e_pid=''
    sed 's/^/  | /' "$e2e_log"
    echo "cli/backup.php ended with $e2e_exit"
    e2e_progress
}

# e2e_backup <log> [--now]: runs cli/backup.php until it ends (SECBK_E2E_BACKUP_TIMEOUT seconds at most); e2e_exit is its
# exit code.
e2e_backup() {
    e2e_backup_start "$@"
    e2e_wait_exit "$e2e_backup_timeout"
}

# e2e_wait_sending <percent> [<bytes>]: waits until the run of the command of e2e_backup_start is in the stage files (it
# sends the files of the site) and has sent <percent> % of the bytes it expects, and at least <bytes> bytes. Returns 1 when
# the command ends first, when the run leaves the stage files first, or after SECBK_E2E_BACKUP_TIMEOUT seconds.
e2e_wait_sending() {
    local percent="$1" least="${2:-1}" deadline next=0 wanted
    deadline=$(( $(date +%s) + e2e_backup_timeout ))
    while e2e_alive "$e2e_pid"; do
        e2e_read_run
        if [ "$e2e_run_stage" = files ] && [ "$e2e_run_estimate" -gt 0 ]; then
            wanted=$(( e2e_run_estimate * percent / 100 ))
            if [ "$wanted" -lt "$least" ]; then wanted="$least"; fi
            if [ "$e2e_run_bytes" -ge "$wanted" ]; then
                e2e_progress
                return 0
            fi
        fi
        case "$e2e_run_stage" in
            dataroot | code | manifest | index | complete | receipt | done)
                echo "the run left the stage files before it got there"
                e2e_progress
                return 1
                ;;
        esac
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "the run did not get there in $e2e_backup_timeout s"
            return 1
        fi
        if [ "$(date +%s)" -ge "$next" ]; then
            e2e_progress
            next=$(( $(date +%s) + 60 ))
        fi
        sleep 1
    done
    echo "cli/backup.php ended before the run got there"
    return 1
}

# Starts four processes for each CPU that do nothing but use it, so that the load (of one minute) passes 2 per CPU, the
# highest load of the plugin by default, within about a minute. Each stops by itself after an hour at the latest.
e2e_load_start() {
    local count i
    count=$(( $(nproc) * 4 ))
    for (( i = 0; i < count; i++ )); do
        timeout 3600 sh -c 'while :; do :; done' &
        e2e_load_pids+=("$!")
    done
    echo "$count processes keep the $(nproc) CPUs busy"
}

# Stops the processes of e2e_load_start.
e2e_load_stop() {
    local pid
    if [ "${#e2e_load_pids[@]}" -eq 0 ]; then return 0; fi
    echo "load of the last minute: $(cut -d ' ' -f 1 /proc/loadavg) for $(nproc) CPUs; the processes are stopped"
    for pid in "${e2e_load_pids[@]}"; do
        kill "$pid" 2> /dev/null || true
    done
    e2e_load_pids=()
}

# The networks of S3 that the plugin sends to, one a.b.c.d/n on each line: those of the list of address ranges that the site
# uses (the one that the service sent it, else the one of the plugin). The plugin connects to S3 only at an IPv4 address of
# them (the network checks), so refusing them stops every send to S3 and nothing else.
e2e_s3_networks() {
    # shellcheck disable=SC2016
    storage_php -r '
        define("CLI_SCRIPT", true);
        require "config.php";
        $ranges = \tool_secbackup\local\net\aws_ranges::current();
        // The networks are private to the class: a closure bound to the object reads them.
        $networks = (fn() => $this->networks)->call($ranges);
        foreach ($networks[\tool_secbackup\local\net\aws_ranges::SERVICE_S3] as [$network, $mask]) {
            echo long2ip($network), "/", substr_count(decbin($mask), "1"), "\n";
        }
    '
}

# Refuses every connection to S3 (a reset at once, so that each send fails quickly) with a chain of iptables of its own.
e2e_s3_block() {
    local networks network count=0
    networks="$(e2e_s3_networks)"
    sudo iptables -w -N "$e2e_chain"
    e2e_s3_blocked=yes
    while read -r network; do
        if [[ ! "$network" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$ ]]; then
            echo "not a network of S3: $network" >&2
            return 1
        fi
        sudo iptables -w -A "$e2e_chain" -d "$network" -p tcp -j REJECT --reject-with tcp-reset
        count=$(( count + 1 ))
    done <<< "$networks"
    if [ "$count" -lt 5 ]; then
        echo "the site lists $count networks of S3, too few to be the list of address ranges" >&2
        return 1
    fi
    sudo iptables -w -I OUTPUT 1 -j "$e2e_chain"
    echo "the connections to the $count networks of S3 are refused"
}

# Allows the connections to S3 again.
e2e_s3_unblock() {
    if [ "$e2e_s3_blocked" != yes ]; then return 0; fi
    sudo iptables -w -D OUTPUT -j "$e2e_chain" 2> /dev/null || true
    sudo iptables -w -F "$e2e_chain" 2> /dev/null || true
    sudo iptables -w -X "$e2e_chain" 2> /dev/null || true
    e2e_s3_blocked=no
    echo "the connections to S3 are allowed again"
}

# e2e_inspect <file>: what the service says about the site, in <file> (sec.php inspect); three tries a minute apart.
e2e_inspect() {
    local try
    for try in 1 2 3; do
        if php "$e2e_dir/sec.php" inspect --site="$e2e_site" --out="$1"; then return 0; fi
        if [ "$try" -lt 3 ]; then sleep 60; fi
    done
    return 1
}

# e2e_verdict <checkpoint> <inspection file> <options of verdict.php>...: prints the verdict, and records each of its reasons
# with the name of the checkpoint.
e2e_verdict() {
    local checkpoint="$1" output status=0 line
    shift
    output="$(php "$e2e_dir/verdict.php" "$@")" || status=$?
    printf '%s\n' "$output" | sed 's/^/  /'
    while IFS= read -r line; do
        case "$line" in
            'reason: '*) e2e_fail "$checkpoint: ${line#reason: }" ;;
        esac
    done <<< "$output"
    return "$status"
}

# e2e_wait_stopped <file> <reason>: waits (ten minutes at most, a request every 30 s) until the service has the last run of
# the site as failed with <reason> and no completed run, then records the verdict on its last answer.
e2e_wait_stopped() {
    local deadline
    deadline=$(( $(date +%s) + 600 ))
    while true; do
        if php "$e2e_dir/sec.php" inspect --site="$e2e_site" --out="$1" \
            && php "$e2e_dir/verdict.php" "$1" --stopped="$2" > /dev/null; then
            break
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then break; fi
        sleep 30
    done
    e2e_verdict "after the stop" "$1" --stopped="$2" || true
}

# e2e_final <options of verdict.php>...: what the service holds at the end, in inspect.json, and the verdict on it.
e2e_final() {
    e2e_step "the state of the site at the service"
    e2e_inspect "$e2e_folder/inspect.json" || e2e_fail "the service did not answer the inspection"
    e2e_verdict "at the end" "$e2e_folder/inspect.json" --done "$@" || true
}

# e2e_inspected_bytes <file>: objects.bytes of an inspection; 0 when it has none.
e2e_inspected_bytes() {
    # shellcheck disable=SC2016
    php -r '$data = json_decode((string) @file_get_contents($argv[1]), true);
        echo is_array($data) && is_int($data["objects"]["bytes"] ?? null) ? $data["objects"]["bytes"] : 0;' "$1"
}

# e2e_check_resend <S3 bytes after the stop> <bytes that the next run sent> <S3 bytes at the end>: the next run does not send
# again what the stopped one sent. Without a resend, what the service holds at the end is what it held after the stop plus
# what the next run sent (and its lists), so the bytes counted twice, sent + before - after, stay at or below 0; a run that
# sent everything again would count all of "before" twice. A tenth of it is allowed.
e2e_check_resend() {
    local before="$1" sent="$2" after="$3" twice
    twice=$(( sent + before - after ))
    e2e_number s3_bytes_after_stop "$before"
    e2e_number next_run_sent_bytes "$sent"
    e2e_number s3_bytes_at_end "$after"
    echo "resend: $before bytes at the service after the stop, $sent bytes sent by the next run, $after bytes at the end:" \
        "$twice bytes counted twice"
    if [ "$before" -le 0 ]; then
        e2e_fail "the service held nothing of the site after the stop, so a resend cannot be seen"
    elif [ "$twice" -gt $(( before / 10 )) ]; then
        e2e_fail "the next run sent again about $twice of the $before bytes that the service held after the stop"
    fi
}

# e2e_wait_recount <UNIX time of the stop>: waits until the service has listed again what it holds of the site, which it does
# some minutes after a run ends: SECBK_E2E_RECOUNT_WAIT seconds after the stop.
e2e_wait_recount() {
    e2e_sleep_until $(( $1 + e2e_recount_wait )) "the service has listed what it holds of the site"
    e2e_duration wait "$1"
}

# e2e_sleep_until <UNIX time> <what>: waits until then, with a line every five minutes.
e2e_sleep_until() {
    local left
    while true; do
        left=$(( $1 - $(date +%s) ))
        if [ "$left" -le 0 ]; then return 0; fi
        echo "  $left s until $2"
        sleep $(( left < 300 ? left : 300 ))
    done
}
