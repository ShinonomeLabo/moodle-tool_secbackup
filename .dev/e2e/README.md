# Staging end-to-end test

A whole backup from a real Moodle site, through the plugin, to the staging backup service and its real S3 storage, in four
scenarios, on a disposable runner. The staging service has test endpoints for it (under `/e2e/` of its API) that answer only
with a secret header: one removes the test sites, one makes a test site and gives its registration code, and one says what
the service holds of a test site (its runs, and the number, size, lock and storage class of its objects).

Development only; `.dev/` is not part of the plugin package.

## Files

| File | What it does |
|---|---|
| `run.sh` | One scenario: `run.sh <normal\|load\|abort\|concurrent\|data-only> --name=<name> --files-gb=<GB> [--start-at=<UNIX time>] [--skip=<component>[,...]]...` |
| `lib.sh` | The steps and the scenarios of `run.sh` (sourced): the site, the connection, the background run, the load, the firewall, the checks, the result |
| `sec.php` | The test endpoints: `reset`, `provision`, `inspect` (plain PHP, no Moodle) |
| `enroll.php` | Registers the site with the code and the key of the site, read as JSON on its standard input (a Moodle command line script) |
| `verdict.php` | Checks an answer of `inspect` against what a scenario expects (plain PHP) |
| `summary.php` | The Markdown summary of all the results of a run of the workflow, and its pass or fail (plain PHP) |
| `install-plugins.sh`, `plugins.json`, `check-data.php` | The extra plugins at fixed commits, and the check of the test data |
| `tests/run.php` | Tests of `sec.php` (against `tests/fake-service.php`), `verdict.php`, `summary.php`, the arguments of `run.sh`, and the functions and the order of the scenarios of `lib.sh` with stubs in the place of Moodle, the service, the firewall and the load (`php .dev/e2e/tests/run.php`; the job "Commit rules and development tools" of CI runs them) |

## Settings

From the environment:

- `SECBK_E2E_BASE_URL`: the URL of the API of the staging service, `https://<host>/<path>` (the plugin's connection URL is
  this URL with `/v1`). It is a secret of the workflow, so that no file or log names the host.
- `SECBK_E2E_SECRET`: the value of the header `X-E2E-Secret`. A wrong value is answered with 404.
- `SECBK_E2E_OUT`: the folder of the results (default `$RUNNER_TEMP/secbk-e2e`).
- `SECBK_STORAGE_DIR`: the folder of the site (default `$RUNNER_TEMP/secbk-storage`, as in `.dev/storage/lib.sh`); it has to
  be on a disk with room for the files.
- `SECBK_E2E_BACKUP_TIMEOUT`: seconds that one call of `cli/backup.php` may take (default 7200).
- `SECBK_E2E_RECOUNT_WAIT`: seconds from a stopped run to the next one (default 1200). The service recounts the site 17
  minutes (1020 seconds) after a stopped run, and only then lists what the stopped run sent. A run that starts before the
  recount makes the service skip it, and then sends again what the stopped run sent. The default is three minutes more than
  the delay of the service.

`run.sh` runs only where `GITHUB_ACTIONS=true` (or `SECBK_E2E_DISPOSABLE=yes`): it keeps all the CPUs busy and changes the
firewall. It needs sudo, PHP with curl, pgsql and sodium, the PostgreSQL client, and a PostgreSQL server that trusts the user
`postgres` on 127.0.0.1 (a connection that cannot be made fails after 10 seconds, `PGCONNECT_TIMEOUT`). Every wait has an end,
and a signal (a cancelled job) reaches the trap of the script at once, which stops the load, opens the firewall and writes
the result.

## A scenario

Every scenario first checks that the disk has room (the files, 3 GB for Moodle and the rest, and the free space below which
the plugin does not back up), then builds the site: Moodle 4.5 (`MOODLE_405_STABLE`) from git with the plugin, installed on
PostgreSQL; the extra plugins of `plugins.json` (but those that `--skip` names) and the upgrade that installs them; the
address `https://<name>.moodle.example.invalid` (a name and not an IP address: the firewall of the service refuses an address
that is an IP); dummy data, the data of the extra plugins and `--files-gb` GB (10^9 bytes) of files of random content; and
`check-data.php`. `data-only` stops there and does not reach the service.

The other scenarios then:

1. find the public address of the runner (`checkip.amazonaws.com`), which the service is told to allow;
2. get a test site (`sec.php provision`): a key pair that lives only in that process, the code sealed for it by the service;
3. put the signing key of the service in `config.php` as the one trusted key, as `.dev/env/remote-service.sh` does for an
   https URL (the network checks of the plugin stay on);
4. register the site (`enroll.php`) and ask for its status once, which brings the settings of the service;
5. read the record of the runs in the database of the site once (every wait of the scenarios reads it);
6. run the scenario:

| Scenario | How it runs | What has to hold |
|---|---|---|
| `normal` | `cli/backup.php --now` | It ends with 0. The service has one run, completed with a receipt |
| `load` | `cli/backup.php --now` in the background. When the run sends the files of the site, 4 processes per CPU that only use it raise the load above 2 per CPU, the limit of the plugin. The load stops when the command ends. After the recount of the service, `cli/backup.php --now` | The first command ends with 1 and the run is stopped with `server_busy`; the service has it as failed (`server_busy`) and no completed generation. The second command ends with 0; the service has two runs, failed (`server_busy`) and completed with a receipt; the second run did not send again what the first sent |
| `abort` | (b) `cli/backup.php --now` in the background; when the run has sent 10 % of its bytes in the stage of the files, every connection to S3 is refused with iptables until the command ends; then allowed again. After the recount of the service: (a) `cli/backup.php --now` in the background; when that run has sent 10 % of its bytes in the stage of the files, `kill -9` of its php, then `cli/backup.php` again, without `--now` | (b) The command ends with 1 and the run is stopped with `upload_failed` (the plugin tries a send three times, 15 minutes apart); the service has it as failed (`upload_failed`) and no completed generation. (a) The killed command ends with 137; the service has no completed generation and the run is still open. The command without `--now` ends with 0, and the run it went on with is the one that was killed, now completed. The service has two runs, failed (`upload_failed`) and completed with a receipt (the one completed generation); the run of (a) did not send again what the run of (b) sent |
| `concurrent` | Waits until `--start-at` (one time for the ten jobs of the workflow, at most 3 hours from now; a time that has passed starts at once, and the result says how late), then as `normal` | As `normal`. The waits and the failed steps of the run (for example when the service asked it to come back later) are counted in the result |

At the end of every scenario that reaches the service, each object of the site is locked in COMPLIANCE mode until a time to
come (the list of what the service holds is not locked, by design, and is not counted) and is in the storage class
`INTELLIGENT_TIERING`. The encryption of the objects is the default of the storage and is not checked object by object.

"Did not send again" is checked with three numbers: what the service held after the stop (`before`), what the next run sent
(the bytes uploaded in the record of the run on the site, `sent`), and what the service holds at the end (`after`). Without a
resend, `after` is `before` plus `sent` and the lists of the run, so `sent + before - after` is 0 or less; a run that sent
everything again would count `before` twice (a chunk that the storage has already is counted in `sent` too). A tenth of
`before` is allowed. What each run asked the service for (`requested_bytes`) is recorded but not judged: the plugin counts in
it every file below 4 MiB, sent before or not.

The points of the scenarios (the stage of the files, 10 %, 10 % more) are read from the record of the run in the database of
the site every second or two. If a run is faster than that, or leaves the stage of the files first, the scenario fails and
says so: the sizes are then to be made larger. When the database cannot be read (five times in a row), the scenario fails
with that reason.

## Results and secrets

`$SECBK_E2E_OUT/<name>/`:

- `result.json`: `{scenario, name, passed, reasons, sent_bytes, durations, retries, numbers, requested_bytes}`. `passed` is
  true when no condition failed and the script ran to its end; `reasons` says what failed, also the step where the script
  stopped. `sent_bytes` is what the service holds of the site at the end. The trap of the script writes it in every case.
- `inspect.json`: what the service said about the site at the end, with only the fields that `sec.php` knows; an error answer
  is `{"error": <code>, "http_status": <status>}`.
- `logs/`: the output of the commands and the answers of the service at the other checkpoints.
- `private/` (mode 0700): the registration code (removed once it is used) and the keys of the site (mode 0600). Not for the
  artifacts of the workflow.

The scripts print steps, states and numbers. They never print the URL of the service, the secret, the registration code or a
key; `sec.php` prints of an error answer only its status (and, for refused input, the names of the fields).

`summary.php <folder> --limit-gb=<GB>` reads every `result.json` and `inspect.json` under the folder and prints a Markdown
table. It ends with 1 when the sum of `objects.bytes` (what was sent) is over the limit, when a result did not pass or is
missing, or when the inspection of a site is missing, failed (for example `lock_read_failed` or `too_many_objects`) or shows
an object that is not locked (`compliance` false, or `missing_keys`, `expired_keys` or `gone_keys` above 0). The amount of a
site whose inspection is missing or failed is not known: the table says `unknown`, the line of the total says for how many
sites, and the limit is judged on the amounts that are known.
