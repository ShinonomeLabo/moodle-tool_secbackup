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
#   logs/         the output of the commands, the answers of the service at the other checkpoints, the lists of the result,
#                 the Moodle of the site (moodle.json) and whether its run had the official list of its files (core.txt)
#   private/      mode 0700: the registration code (removed once it is used) and the keys of the site, mode 0600; nothing
#                 prints them and the workflow does not keep them
# What is printed are steps, states and numbers: never the address of the service, the secret, the code or a key.

e2e_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=../storage/lib.sh
. "$e2e_dir/../storage/lib.sh"
SECBK_E2E_OUT="${SECBK_E2E_OUT:-${RUNNER_TEMP:-/tmp}/secbk-e2e}"
# The secret of the test endpoints of the service goes to sec.php only (e2e_sec). It leaves the environment here, so that no
# other child of the script inherits it: cli/backup.php, enroll.php and the other scripts of Moodle (which load the code of
# the plugins of the site), psql. The URL stays: the registration writes it into the settings of Moodle anyway.
e2e_secret="${SECBK_E2E_SECRET:-}"
unset SECBK_E2E_SECRET
# Seconds that one call of cli/backup.php may take.
e2e_backup_timeout="${SECBK_E2E_BACKUP_TIMEOUT:-7200}"
# Seconds from a stopped run to the next one. The service recounts the site 17 minutes (1020 seconds: the life of its upload
# URLs, 900, and two minutes) after a stopped run, and only then lists what the stopped run sent. A run that starts before
# the recount makes the service skip it: that run reads the old list and sends again what the stopped one sent. Three
# minutes more than the delay of the service.
e2e_recount_wait="${SECBK_E2E_RECOUNT_WAIT:-1200}"
# A connection to the database of the site that cannot be made fails after 10 seconds, instead of holding a wait.
export PGCONNECT_TIMEOUT="${PGCONNECT_TIMEOUT:-10}"
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
e2e_before=0
e2e_sent=0
# The latest run before the command of e2e_backup_start; a wait looks only at the runs after it.
e2e_after_id=0
# What the run of the last e2e_wait_sending had sent when it was first seen in the stage files.
e2e_files_from=0
# The Moodle that moodle.json fixes (e2e_moodle_pin).
e2e_moodle_branch=''
e2e_moodle_commit=''
e2e_moodle_version=''
e2e_moodle_release=''
# The stage of cli/backup.php whose waits are not counted: the run asks the service for its receipt until it has it.
e2e_receipt_stage='Waiting for the receipt of the backup service'
# The process that e2e_sleep or e2e_sec runs in the background, which the trap stops.
e2e_bg=''

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

# e2e_sleep <seconds>: sleeps in the background and waits for it. bash runs a trap only when the command in the foreground
# ends, but it ends the builtin wait at once: so a signal (a cancelled job) reaches the trap of EXIT without delay, and the
# trap stops the sleep (e2e_bg).
e2e_sleep() {
    local status=0
    sleep "$1" &
    e2e_bg=$!
    wait "$e2e_bg" || status=$?
    e2e_bg=''
    return "$status"
}

# e2e_sec <arguments of sec.php>: runs sec.php (which may wait minutes for the service) in the background and waits for it,
# for the same reason as e2e_sleep; the trap stops it too. It alone is given the secret. Its exit code is the one of sec.php.
e2e_sec() {
    local status=0
    SECBK_E2E_SECRET="$e2e_secret" php "$e2e_dir/sec.php" "$@" &
    e2e_bg=$!
    wait "$e2e_bg" || status=$?
    e2e_bg=''
    return "$status"
}

# e2e_duration <name> <start>: records the seconds from <start> (UNIX time) to now.
e2e_duration() {
    printf '%s %d\n' "$1" $(( $(date +%s) - $2 )) >> "$e2e_logs/durations.txt"
}

# e2e_number <name> <whole number>: records a number for the result.
e2e_number() {
    printf '%s %d\n' "$1" "$2" >> "$e2e_logs/numbers.txt"
}

# e2e_begin <scenario> <name> [all|site|scenario]: makes the folders of the scenario and sets the trap that cleans up and writes
# the result. A scenario runs whole (all), or in two parts, each a step of the job: the site, which needs no secret, and then
# the scenario, which reaches the service. The scenario part goes on with what the site part recorded (the reasons, the
# durations, the numbers, the time of the start), so that one result holds both.
e2e_begin() {
    local started
    e2e_scenario="$1"
    e2e_name="$2"
    e2e_folder="$SECBK_E2E_OUT/$e2e_name"
    e2e_logs="$e2e_folder/logs"
    e2e_private="$e2e_folder/private"
    mkdir -p "$e2e_logs" "$e2e_private"
    chmod 700 "$e2e_private"
    if [ "${3:-all}" = scenario ]; then
        rm -f "$e2e_logs/pending.txt"
        touch "$e2e_logs/reasons.txt" "$e2e_logs/durations.txt" "$e2e_logs/numbers.txt"
        started="$(cat "$e2e_logs/started.txt" 2> /dev/null)" || started=''
        [[ "$started" =~ ^[0-9]+$ ]] || started="$(date +%s)"
        e2e_started="$started"
    else
        rm -f "$e2e_folder/result.json" "$e2e_folder/inspect.json" "$e2e_logs/pending.txt" "$e2e_logs/site.done" \
            "$e2e_logs/moodle.json" "$e2e_logs/core.txt"
        : > "$e2e_logs/reasons.txt"
        : > "$e2e_logs/durations.txt"
        : > "$e2e_logs/numbers.txt"
        e2e_started="$(date +%s)"
        echo "$e2e_started" > "$e2e_logs/started.txt"
    fi
    trap e2e_end EXIT
    # A cancelled job stops with a signal: the trap of EXIT then cleans up all the same.
    trap 'exit 143' TERM
    trap 'exit 130' INT
}

# Marks the scenario as run to its end. Without it, the result says at which step the script stopped.
e2e_finish() {
    e2e_finished=yes
}

# The end of the site part of a scenario that reaches the service: the result says that the scenario has not run, until the
# scenario part takes this away. The site part still ends with 0, so that the job goes on to the scenario.
e2e_pending() {
    echo "the scenario has not run yet: only the site was built (run.sh --part=scenario runs it)" > "$e2e_logs/pending.txt"
}

# The start of the scenario part: the site part has built the site (e2e_build_site leaves logs/site.done at its end).
e2e_require_site() {
    if [ ! -e "$e2e_logs/site.done" ]; then
        e2e_fail "the site was not built: run.sh --part=site comes first"
        return 1
    fi
}

# The trap of EXIT: stops the load, opens the firewall again, stops a backup that still runs and the sleep or sec.php that a
# wait runs in the background, removes the registration code, and writes the result. The script ends with 0 only when the
# scenario ran to its end and every condition held.
e2e_end() {
    local status=$?
    set +e
    e2e_load_stop
    e2e_s3_unblock
    if [ -n "$e2e_pid" ] && e2e_alive "$e2e_pid"; then
        kill "$e2e_pid" 2> /dev/null
    fi
    if [ -n "$e2e_bg" ] && e2e_alive "$e2e_bg"; then
        kill "$e2e_bg" 2> /dev/null
        # sec.php may be writing a file of the scenario: it ends before the result is written.
        wait "$e2e_bg" 2> /dev/null
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
    if [ -s "$e2e_logs/pending.txt" ]; then
        echo "result: the site is built; the scenario runs in the next step"
        exit 0
    fi
    echo "result: passed"
    exit 0
}

# Writes result.json: {scenario, name, passed, reasons, sent_bytes, durations, retries, numbers, requested_bytes, moodle}.
# sent_bytes is objects.bytes of inspect.json (what the service holds of the site at the end; 0 without it), and
# requested_bytes lists the bytes that each run of the site asked the service for. retries counts lines of the output of
# cli/backup.php. waits_without_failure: the waits ("Waiting until") that no failed step comes before, in any stage but the
# one of the receipt (where the run asks again and again until it has it). The plugin waits without a failed step when the
# service answers that it is busy (429, 503: api_rate_limited, api_unavailable), and when a send to S3 is to be tried
# again, so both are counted here; the command prints no line that tells them apart. failed_steps: the steps that failed
# ("A step of the backup failed"); the wait of five minutes after each of them is not among the waits. moodle is the code of
# Moodle of the site (logs/moodle.json) with core_manifest, whether its last run had the official list of the files of that
# build (logs/core.txt; null when it is not known). passed is true when no reason was recorded; the site part of a scenario
# adds the reason that the scenario has not run yet (logs/pending.txt).
e2e_write_result() {
    # shellcheck disable=SC2016
    php -r '
        [, $folder, $scenario, $name, $receipt] = $argv;
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
        $reasons = array_merge($lines("$folder/logs/reasons.txt"), $lines("$folder/logs/pending.txt"));
        $file = "$folder/inspect.json";
        $inspection = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $runs = is_array($inspection) && is_array($inspection["runs"] ?? null) ? $inspection["runs"] : [];
        $waits = 0;
        $failed = 0;
        foreach (glob("$folder/logs/backup-*.log") ?: [] as $log) {
            // A pass of the command prints a failed step (when one failed), its stage, and the wait (when it waits): the
            // wait of a pass that began with a failed step belongs to that step.
            $stage = "";
            $afterfailure = 0;
            foreach ($lines($log) as $line) {
                if (str_starts_with($line, "Stage: ")) {
                    $stage = substr($line, 7);
                    $afterfailure = $afterfailure === 1 ? 2 : 0;
                } else if (str_starts_with($line, "Waiting until ")) {
                    $waits += $afterfailure === 0 && $stage !== $receipt ? 1 : 0;
                    $afterfailure = 0;
                } else if (str_starts_with($line, "A step of the backup failed ")) {
                    $failed++;
                    $afterfailure = 1;
                }
            }
        }
        $moodle = json_decode((string) @file_get_contents("$folder/logs/moodle.json"), true);
        if (is_array($moodle)) {
            $core = trim((string) @file_get_contents("$folder/logs/core.txt"));
            $moodle = array_map("strval", array_intersect_key($moodle, array_flip(["commit", "version", "release", "build"])))
                + ["core_manifest" => $core === "1" ? true : ($core === "0" ? false : null)];
        }
        $result = [
            "scenario" => $scenario,
            "name" => $name,
            "passed" => $reasons === [],
            "reasons" => $reasons,
            "sent_bytes" => is_array($inspection) && is_int($inspection["objects"]["bytes"] ?? null)
                ? $inspection["objects"]["bytes"] : 0,
            "durations" => (object) $pairs("$folder/logs/durations.txt"),
            "retries" => ["waits_without_failure" => $waits, "failed_steps" => $failed],
            "numbers" => (object) $pairs("$folder/logs/numbers.txt"),
            "requested_bytes" => array_map(static fn($run) => is_array($run) ? ($run["requested_bytes"] ?? null) : null, $runs),
            "moodle" => is_array($moodle) ? $moodle : null,
        ];
        $text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents("$folder/result.json.part", $text);
        rename("$folder/result.json.part", "$folder/result.json");
    ' "$e2e_folder" "$e2e_scenario" "$e2e_name" "$e2e_receipt_stage"
}

# e2e_check_disk <files GB>: stops the scenario before anything is made when the disk that will hold the site has less room
# than the files (GB of 10^9 bytes), 3 GB for Moodle, its database and the temporary files of the backup, and the free space
# that the plugin keeps (it does not back up below the larger of 10 % of the disk and 5 GiB).
e2e_check_disk() {
    local free='' size='' need
    read -r free size < <(df -B1 --output=avail,size "$SECBK_STORAGE_DIR" 2> /dev/null | tail -n 1) || true
    if [[ ! "$free" =~ ^[0-9]+$ ]] || [[ ! "$size" =~ ^[0-9]+$ ]]; then
        e2e_fail "the free space of the disk of the site cannot be read"
        return 1
    fi
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

# e2e_moodle_pin [<file>]: reads the Moodle that the sites are built with (moodle.json): the branch, the commit (40
# hexadecimal digits), and the $version and $release of the code of that commit. The commit is fixed, as the extra plugins
# are: the head of the branch moves every week, and the service holds the official list of the files of one build only, so a
# site of another build sends the whole code of Moodle. Sets e2e_moodle_branch, e2e_moodle_commit, e2e_moodle_version and
# e2e_moodle_release; a file that is not of that form is a reason, and 1.
e2e_moodle_pin() {
    local file="${1:-$e2e_dir/moodle.json}" values code
    code="$(cat <<'PHP'
$pin = json_decode((string) @file_get_contents($argv[1]), true);
if (
    !is_array($pin) || array_keys($pin) !== ['branch', 'commit', 'version', 'release']
    || !is_string($pin['branch']) || preg_match('/^MOODLE_[0-9]+_STABLE\z/', $pin['branch']) !== 1
    || !is_string($pin['commit']) || preg_match('/^[0-9a-f]{40}\z/', $pin['commit']) !== 1
    || !is_string($pin['version']) || preg_match('/^[0-9]{10}\.[0-9]{2}\z/', $pin['version']) !== 1
    || !is_string($pin['release']) || preg_match('/^[0-9][^\n]{0,60} \(Build: [0-9]{8}\)\z/', $pin['release']) !== 1
) {
    exit(1);
}
echo implode("\n", $pin), "\n";
PHP
)"
    if ! values="$(php -r "$code" "$file")"; then
        e2e_fail "moodle.json does not fix the code of Moodle: it has branch (MOODLE_<n>_STABLE), commit (40 hexadecimal" \
            "digits), version and release (with its build), in that order and nothing else"
        return 1
    fi
    {
        read -r e2e_moodle_branch
        read -r e2e_moodle_commit
        read -r e2e_moodle_version
        IFS= read -r e2e_moodle_release
    } <<< "$values"
}

# Checks that the code of Moodle of the site is the one that moodle.json fixes (e2e_moodle_pin): its commit, and the $version
# and $release of its version.php. Writes them, with the build, to logs/moodle.json for the result; a difference is a reason.
e2e_check_moodle() {
    local head found code
    head="$(git -C "$secbk_moodle" rev-parse HEAD 2> /dev/null)" || head=''
    if [ "$head" != "$e2e_moodle_commit" ]; then
        e2e_fail "the code of Moodle is at commit ${head:-unknown}, moodle.json fixes $e2e_moodle_commit"
        return 1
    fi
    code="$(cat <<'PHP'
[, $root, $commit, $file] = $argv;
$text = '';
foreach (["$root/version.php", "$root/public/version.php"] as $path) {
    if ($text === '' && is_file($path)) {
        $text = (string) file_get_contents($path);
    }
}
$version = preg_match('~^\s*\$version\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*;~m', $text, $v) === 1 ? $v[1] : '';
$release = preg_match('~^\s*\$release\s*=\s*(?:\'([^\']*)\'|"([^"]*)")\s*;~m', $text, $r) === 1 ? $r[1] . ($r[2] ?? '') : '';
$build = preg_match('/Build: ([0-9]{8})/', $release, $b) === 1 ? $b[1] : '';
file_put_contents($file, json_encode(['commit' => $commit, 'version' => $version, 'release' => $release, 'build' => $build],
    JSON_UNESCAPED_SLASHES) . "\n");
echo "$version\n$release\n";
PHP
)"
    found="$(php -r "$code" "$secbk_moodle" "$head" "$e2e_logs/moodle.json")" || found=''
    if [ "$found" != "$e2e_moodle_version"$'\n'"$e2e_moodle_release" ]; then
        e2e_fail "the code of Moodle at commit $head has \$version ${found%%$'\n'*} and \$release ${found#*$'\n'}," \
            "moodle.json says $e2e_moodle_version and $e2e_moodle_release"
        return 1
    fi
    echo "Moodle $e2e_moodle_release, \$version $e2e_moodle_version, commit $head"
}

# Whether the last run of the site that compared the code of Moodle with the official list of its build had that list, from
# the progress that the plugin keeps in the record of the run (run.core.official): logs/core.txt, 1 or 0. Nothing is written
# when no run compared or the database cannot be read; the result then says that it is not known. It is a note, not a
# condition: a site without the list sends the code of Moodle, and the backup is still whole.
e2e_read_core() {
    local rows
    rows="$(psql -h 127.0.0.1 -U postgres -d moodle -At -c 'SELECT progress FROM mdl_tool_secbackup_runs ORDER BY id DESC' \
        2>> "$e2e_logs/psql.log")" || return 0
    # shellcheck disable=SC2016
    if printf '%s\n' "$rows" | php -r '
        while (($line = fgets(STDIN)) !== false) {
            $progress = json_decode(trim($line), true);
            $official = is_array($progress) ? ($progress["run"]["core"]["official"] ?? null) : null;
            if (is_bool($official)) {
                echo $official ? "1" : "0", "\n";
                exit(0);
            }
        }
        exit(1);
    ' > "$e2e_logs/core.txt.part"; then
        mv "$e2e_logs/core.txt.part" "$e2e_logs/core.txt"
    else
        rm -f "$e2e_logs/core.txt.part"
    fi
}

# e2e_build_site <name> <files GB> [--skip=<component>[,<component>...]]...: Moodle 4.5 at the commit of moodle.json with the
# plugin and the extra plugins (the ones that --skip names are left out), installed on PostgreSQL, with the address
# https://<name>.moodle.example.invalid, dummy data, the data of the extra plugins and files of <files GB> GB (10^9 bytes) in
# all; then check-data.php. Leaves logs/site.done at its end, for the scenario part.
e2e_build_site() {
    local name="$1" gb="$2" since
    shift 2
    since="$(date +%s)"
    e2e_moodle_pin
    e2e_step "Moodle $e2e_moodle_branch at commit $e2e_moodle_commit with the plugin"
    storage_moodle "$e2e_moodle_branch" "$e2e_moodle_commit"
    e2e_check_moodle
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
    : > "$e2e_logs/site.done"
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
    e2e_sec provision --name="$name" --moodle-url="https://$name.moodle.example.invalid" --source-ip="$ip" --out="$e2e_private"
    e2e_site="$(e2e_site_field site_uuid)"
    e2e_step "the key of the service in config.php"
    e2e_config service "$(e2e_site_field signing_public_key)"
    e2e_step "the registration"
    e2e_enroll
    e2e_duration connect "$since"
}

# Reads the latest run of the plugin on the site into e2e_run_id, e2e_run_stage, e2e_run_status, e2e_run_code,
# e2e_run_bytes (the bytes it uploaded) and e2e_run_estimate (the bytes it expected to send); empty and 0 when there is none.
# Returns 1 when the database cannot be read (what psql said is in logs/psql.log).
e2e_read_run() {
    local row
    e2e_run_id=''
    e2e_run_stage=''
    e2e_run_status=''
    e2e_run_code=''
    e2e_run_bytes=0
    e2e_run_estimate=0
    if ! row="$(psql -h 127.0.0.1 -U postgres -d moodle -AtF '|' -c "SELECT id, stage, status, COALESCE(abortcode, ''),
        bytesuploaded, COALESCE(estimatedbytes, 0) FROM mdl_tool_secbackup_runs ORDER BY id DESC LIMIT 1" \
        2>> "$e2e_logs/psql.log")"; then
        return 1
    fi
    IFS='|' read -r e2e_run_id e2e_run_stage e2e_run_status e2e_run_code e2e_run_bytes e2e_run_estimate <<< "$row" || true
    [[ "$e2e_run_bytes" =~ ^[0-9]+$ ]] || e2e_run_bytes=0
    [[ "$e2e_run_estimate" =~ ^[0-9]+$ ]] || e2e_run_estimate=0
}

# Reads the record of the runs once, before a scenario: its waits read it, and a database that cannot be read (a wrong name,
# a server that does not answer) would otherwise give them reasons that miss the point.
e2e_check_db() {
    e2e_step "the database of the site can be read"
    if ! e2e_read_run; then
        e2e_fail "the database of the site cannot be read (logs/psql.log)"
        return 1
    fi
}

# e2e_read_sent <checkpoint>: e2e_sent is what the latest run uploaded, from its record on the site (and e2e_run_* are read).
# When the database cannot be read: 0, a reason, and 1.
e2e_read_sent() {
    e2e_sent=0
    if ! e2e_read_run; then
        e2e_fail "$1: the database of the site cannot be read (logs/psql.log)"
        return 1
    fi
    e2e_sent="$e2e_run_bytes"
}

# Prints the state of the latest run.
e2e_progress() {
    if ! e2e_read_run; then
        echo "  the database of the site cannot be read (logs/psql.log)"
    elif [ -n "$e2e_run_id" ]; then
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

# e2e_backup_start <log> [--now]: starts cli/backup.php in the background, with its output in <log>. It first reads the
# latest run of the site, so that the waits look only at the run of this command (e2e_after_id): with --now, the command
# makes a new run, later than that one, and a run from before (stopped, but still in the stage files with its bytes sent)
# would satisfy a wait at once; without --now, the command goes on with that latest run itself. A database that cannot be
# read stops the scenario here.
e2e_backup_start() {
    e2e_log="$1"
    shift
    if ! e2e_read_run; then
        e2e_fail "the database of the site cannot be read before cli/backup.php starts (logs/psql.log)"
        return 1
    fi
    e2e_after_id="${e2e_run_id:-0}"
    if [ "${1:-}" != --now ] && [ "$e2e_after_id" -gt 0 ]; then
        e2e_after_id=$(( e2e_after_id - 1 ))
    fi
    echo "cli/backup.php $* in the background"
    e2e_launch_backup "$@"
}

# e2e_launch_backup [--now]: the command itself, in the background, with its output in e2e_log. e2e_pid is the process of
# php itself, so that kill -9 reaches it.
e2e_launch_backup() {
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
            e2e_sleep 10
            kill -9 "$e2e_pid" 2> /dev/null || true
            wait "$e2e_pid" 2> /dev/null || true
            e2e_exit=124
            break
        fi
        if [ "$(date +%s)" -ge "$next" ]; then
            e2e_progress
            next=$(( $(date +%s) + 60 ))
        fi
        e2e_sleep 2
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

# e2e_wait_sending <percent> [<bytes>] [<bytes in the stage files>]: waits until the run of the command of e2e_backup_start (a
# run later than e2e_after_id, active or paused) is in the stage files (it sends the files of the site) and has sent
# <percent> % of the bytes it expects, at least <bytes> bytes, and at least <bytes in the stage files> more than when it was
# first seen in that stage (e2e_files_from: what it had sent before, in the stage db, does not count). Until the command
# has written the record of its run, the latest record is from before it, and is not looked at. Returns 1 when the command
# ends first, when its run ends or leaves the stage files first, after SECBK_E2E_BACKUP_TIMEOUT seconds, or when the
# database of the site cannot be read five times in a row (with that reason).
e2e_wait_sending() {
    local percent="$1" least="${2:-1}" files="${3:-0}" deadline next=0 wanted misses=0
    deadline=$(( $(date +%s) + e2e_backup_timeout ))
    e2e_files_from=''
    while e2e_alive "$e2e_pid"; do
        if ! e2e_read_run; then
            misses=$(( misses + 1 ))
            if [ "$misses" -ge 5 ]; then
                e2e_fail "the database of the site cannot be read (logs/psql.log)"
                return 1
            fi
            e2e_sleep 1
            continue
        fi
        misses=0
        if [[ ! "$e2e_run_id" =~ ^[0-9]+$ ]] || [ "$e2e_run_id" -le "$e2e_after_id" ]; then
            if [ "$(date +%s)" -ge "$deadline" ]; then
                echo "the command did not write the record of its run in $e2e_backup_timeout s"
                return 1
            fi
            e2e_sleep 1
            continue
        fi
        case "$e2e_run_status" in
            active | paused) ;;
            *)
                echo "the run ended ($e2e_run_status) before it got there"
                e2e_progress
                return 1
                ;;
        esac
        if [ "$e2e_run_stage" = files ] && [ -z "$e2e_files_from" ]; then
            e2e_files_from="$e2e_run_bytes"
        fi
        if [ "$e2e_run_stage" = files ] && [ "$e2e_run_estimate" -gt 0 ]; then
            wanted=$(( e2e_run_estimate * percent / 100 ))
            if [ "$wanted" -lt "$least" ]; then wanted="$least"; fi
            if [ "$e2e_run_bytes" -ge "$wanted" ] && [ $(( e2e_run_bytes - e2e_files_from )) -ge "$files" ]; then
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
        e2e_sleep 1
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
        if e2e_sec inspect --site="$e2e_site" --out="$1"; then return 0; fi
        if [ "$try" -lt 3 ]; then e2e_sleep 60; fi
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

# e2e_wait_stopped <file> <reason> <checkpoint>: waits (ten minutes at most, a request every 30 s) until the service has the
# last run of the site as failed with <reason> and no completed run, then records the verdict on its last answer under the
# name of the checkpoint.
e2e_wait_stopped() {
    local deadline
    deadline=$(( $(date +%s) + 600 ))
    while true; do
        if e2e_sec inspect --site="$e2e_site" --out="$1" && php "$e2e_dir/verdict.php" "$1" --stopped="$2" > /dev/null; then
            break
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then break; fi
        e2e_sleep 30
    done
    e2e_verdict "$3" "$1" --stopped="$2" || true
}

# e2e_final <options of verdict.php>...: what the service holds at the end, in inspect.json, and the verdict on it.
e2e_final() {
    e2e_read_core
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

# e2e_wait_recount <UNIX time of the stop>: waits until the service has counted again what it holds of the site, which it
# does 17 minutes after a stopped run: SECBK_E2E_RECOUNT_WAIT seconds after the stop (see e2e_recount_wait).
e2e_wait_recount() {
    e2e_sleep_until $(( $1 + e2e_recount_wait )) "the service has counted again what it holds of the site"
    e2e_duration wait "$1"
}

# e2e_sleep_until <UNIX time> <what>: waits until then, with a line every five minutes.
e2e_sleep_until() {
    local left
    while true; do
        left=$(( $1 - $(date +%s) ))
        if [ "$left" -le 0 ]; then return 0; fi
        echo "  $left s until $2"
        e2e_sleep $(( left < 300 ? left : 300 ))
    done
}

# The scenarios of run.sh. They are here, and not in run.sh, so that the tests can run them with the steps that reach the
# site, the service, the firewall and other processes replaced.

# The scenario normal: cli/backup.php --now ends with 0, and the site has one run, completed with a receipt.
e2e_scenario_normal() {
    local since
    e2e_step "backup: cli/backup.php --now"
    since="$(date +%s)"
    e2e_backup "$e2e_logs/backup-1.log" --now
    e2e_duration backup "$since"
    if [ "$e2e_exit" -ne 0 ]; then
        e2e_fail "cli/backup.php --now ended with $e2e_exit, expected 0"
    fi
    e2e_final --runs=1
}

# e2e_scenario_concurrent <UNIX time>: waits until then (the start of the ten sites), or starts at once when the time has
# passed and records how late it is, then as normal.
e2e_scenario_concurrent() {
    local now
    now="$(date +%s)"
    if [ "$now" -lt "$1" ]; then
        e2e_step "waiting for the start of the ten sites"
        e2e_sleep_until "$1" "the start of the ten sites"
        e2e_number start_late 0
    else
        echo "the site is ready $(( now - $1 )) s after the start of the ten sites"
        e2e_number start_late $(( now - $1 ))
    fi
    e2e_scenario_normal
}

# e2e_stopped_run <reason> <what was to stop it>: whether the command that just ended stopped its run with <reason> (exit 1,
# the run aborted with that reason); records a reason of the scenario when it did not.
e2e_stopped_run() {
    if ! e2e_read_run; then
        e2e_fail "$2: the database of the site cannot be read, so the stop of the run cannot be checked"
        return 1
    fi
    if [ "$e2e_exit" -ne 1 ] || [ "$e2e_run_status" != aborted ] || [ "$e2e_run_code" != "$1" ]; then
        e2e_fail "$2 did not stop the run: cli/backup.php ended with $e2e_exit and the run is ${e2e_run_status:-unknown}" \
            "(${e2e_run_code:-no reason}), expected 1 and aborted ($1)"
        return 1
    fi
}

# e2e_checks_after_stop <label> <reason> <UNIX time of the stop>: the service has the run as failed with <reason> and no
# completed generation; e2e_before is what it holds of the site then. Then the wait for its recount.
e2e_checks_after_stop() {
    local prefix="${1:+$1 }"
    e2e_step "${prefix}the service has the run as failed ($2) and no completed generation"
    e2e_wait_stopped "$e2e_logs/inspect-stopped.json" "$2" "${prefix}after the stop"
    e2e_before="$(e2e_inspected_bytes "$e2e_logs/inspect-stopped.json")"
    e2e_step "${prefix}waiting until the service has counted again what it holds of the site"
    e2e_wait_recount "$3"
}

# The scenario load: the load stops the run (server_busy) while it sends the files of the site; after the recount, the next
# run completes and does not send again what the first one sent. The load starts once the run has sent a chunk (32 MiB) in
# the stage files, so that the check of a resend covers chunks of files and not only those of the database; what the run
# sent in that stage until the stop is a number of the result, and nothing is a reason.
e2e_scenario_load() {
    local since loaded files
    e2e_step "backup: cli/backup.php --now, in the background"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-1.log" --now
    e2e_step "waiting until the run has sent a chunk of the files of the site"
    if ! e2e_wait_sending 0 1 33554432; then
        e2e_fail "the run did not get to the stage files, where the load is to stop it"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=1
        return
    fi
    e2e_step "load above 2 per CPU, until the plugin stops the run"
    loaded="$(date +%s)"
    e2e_load_start
    e2e_wait_exit "$e2e_backup_timeout"
    e2e_load_stop
    e2e_duration first_run "$since"
    e2e_duration load_to_stop "$loaded"
    if ! e2e_stopped_run server_busy "the load"; then
        e2e_final --runs=1
        return
    fi
    files=$(( e2e_run_bytes - e2e_files_from ))
    if [ "$files" -lt 0 ]; then files=0; fi
    e2e_number files_sent_before_stop "$files"
    if [ "$files" -eq 0 ]; then
        e2e_fail "the load stopped the run before it sent anything in the stage files, so the check of a resend does not" \
            "cover the files of the site"
    fi
    e2e_checks_after_stop '' server_busy "$(date +%s)"
    e2e_step "backup again: cli/backup.php --now"
    since="$(date +%s)"
    e2e_backup "$e2e_logs/backup-2.log" --now
    e2e_duration backup "$since"
    if [ "$e2e_exit" -ne 0 ]; then
        e2e_fail "cli/backup.php --now after the stop ended with $e2e_exit, expected 0"
    fi
    e2e_read_sent "the run after the stop" || true
    e2e_final --runs=2 --failed=server_busy
    e2e_check_resend "$e2e_before" "$e2e_sent" "$(e2e_inspected_bytes "$e2e_folder/inspect.json")"
}

# The scenario abort, in two parts that each cut a run off in the middle and then see the rest of it complete with nothing
# half-made left at the service. (b) first: the connections to S3 are refused while the run sends the files of the site,
# until the plugin stops it (upload_failed); after the recount, (a): the next run (--now) is killed with kill -9 while it
# sends the files, and cli/backup.php without --now goes on with the same run to its end. That run is the one completed
# generation of the site, and it did not send again what the run of (b) sent.
e2e_scenario_abort() {
    local since blocked run
    e2e_step "(b) backup: cli/backup.php --now, in the background"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-1.log" --now
    e2e_step "(b) waiting until the run has sent 10 % of its bytes in the stage files"
    if ! e2e_wait_sending 10; then
        e2e_fail "(b) the run did not get to the point where S3 is blocked"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=1
        return
    fi
    e2e_step "(b) the connections to S3 are refused, until the plugin stops the run"
    blocked="$(date +%s)"
    e2e_s3_block
    # The plugin tries a send three times, 15 minutes apart, before it stops the run.
    e2e_wait_exit 3600
    e2e_s3_unblock
    e2e_duration first_run "$since"
    e2e_duration s3_blocked "$blocked"
    if ! e2e_stopped_run upload_failed "(b) the refused connections to S3"; then
        e2e_final --runs=1
        return
    fi
    e2e_checks_after_stop '(b)' upload_failed "$(date +%s)"
    e2e_step "(a) backup again: cli/backup.php --now, in the background"
    since="$(date +%s)"
    e2e_backup_start "$e2e_logs/backup-2.log" --now
    e2e_step "(a) waiting until the run has sent 10 % of its bytes in the stage files"
    if ! e2e_wait_sending 10; then
        e2e_fail "(a) the run did not get to the point where it is killed"
        e2e_wait_exit "$e2e_backup_timeout"
        e2e_final --runs=2 --failed=upload_failed
        return
    fi
    run="$e2e_run_id"
    e2e_step "(a) kill -9 of the php of cli/backup.php"
    if ! kill -9 "$e2e_pid"; then
        e2e_fail "(a) cli/backup.php ended before kill -9"
    fi
    e2e_wait_exit 60
    if [ "$e2e_exit" -ne 137 ]; then
        e2e_fail "(a) cli/backup.php ended with $e2e_exit after kill -9, expected 137"
    fi
    e2e_step "(a) the service has no completed generation, and the run is open"
    e2e_inspect "$e2e_logs/inspect-killed.json" || true
    e2e_verdict "(a) after kill -9" "$e2e_logs/inspect-killed.json" --stopped=open || true
    e2e_step "(a) cli/backup.php again, without --now: the same run goes on to its end"
    e2e_backup "$e2e_logs/backup-3.log"
    e2e_duration second_run "$since"
    if [ "$e2e_exit" -ne 0 ]; then
        e2e_fail "(a) cli/backup.php without --now ended with $e2e_exit, expected 0"
    fi
    # The bytes of the run that went on are counted in its one record, before and after the kill. When the record cannot be
    # read, that is the reason, and the run is not compared.
    if e2e_read_sent "(a) the run that went on"; then
        if [ "$e2e_run_id" != "$run" ]; then
            e2e_fail "(a) cli/backup.php went on with run ${e2e_run_id:-none}, expected run $run"
        elif [ "$e2e_run_status" != completed ]; then
            e2e_fail "(a) the run that went on is ${e2e_run_status:-unknown}, expected completed"
        fi
    fi
    e2e_final --runs=2 --failed=upload_failed
    e2e_check_resend "$e2e_before" "$e2e_sent" "$(e2e_inspected_bytes "$e2e_folder/inspect.json")"
}
