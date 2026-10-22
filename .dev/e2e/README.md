# Staging end-to-end test

A whole backup from a real Moodle site, through the plugin, to the staging backup service and its real S3 storage, in four
scenarios, on a disposable runner. The staging service has test endpoints for it (under `/e2e/` of its API) that answer only
with a secret header: one removes the test sites, one makes a test site and gives its registration code, and one says what
the service holds of a test site (its runs, and the number, size, lock and storage class of its objects).

Development only; `.dev/` is not part of the plugin package.

## Files

| File | What it does |
|---|---|
| `run.sh` | One scenario: `run.sh <normal\|load\|abort\|concurrent\|data-only> --name=<name> --files-gb=<GB> [--start-at=<UNIX time>] [--skip=<component>[,...]]... [--part=site\|scenario]` |
| `moodle.json` | The Moodle of the sites: a commit of `MOODLE_405_STABLE`, with the `$version` and `$release` of its code (below) |
| `lib.sh` | The steps and the scenarios of `run.sh` (sourced): the site, the connection, the background run, the load, the firewall, the checks, the result |
| `sec.php` | The test endpoints: `reset`, `provision`, `inspect` (plain PHP, no Moodle) |
| `enroll.php` | Registers the site with the code and the key of the site, read as JSON on its standard input (a Moodle command line script) |
| `verdict.php` | Checks an answer of `inspect` against what a scenario expects (plain PHP) |
| `summary.php` | The Markdown summary of all the results of a run of the workflow, and its pass or fail (plain PHP) |
| `install-plugins.sh`, `plugins.json`, `check-data.php` | The extra plugins at fixed commits, and the check of the test data |
| `make-room.sh` | Removes large tools of a GitHub runner that the test does not use and picks the disk for the site (`--show`: the room left) |
| `mask.sh` | Masks the URL of the service and its host name in the log of a job (`::add-mask::`); stops the job when the URL is missing |
| `../../.github/workflows/staging-e2e.yml` | The workflow that runs the scenarios on GitHub's runners (below) |
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
and a signal (a cancelled job) reaches the trap of the script at once, which stops the load, opens the firewall, stops the
backup and the sleep or the request to the service that runs in the background, and writes the result.

## A scenario

Every scenario first checks that the disk has room (the files, 3 GB for Moodle and the rest, and the free space below which
the plugin does not back up), then builds the site: Moodle 4.5 at the commit of `moodle.json` (below) with the plugin,
installed on PostgreSQL; the extra plugins of `plugins.json` (but those that `--skip` names) and the upgrade that installs
them; the address `https://<name>.moodle.example.invalid` (a name and not an IP address: the firewall of the service refuses
an address that is an IP); dummy data, the data of the extra plugins and `--files-gb` GB (10^9 bytes) of files of random
content; and `check-data.php`. `data-only` stops there and does not reach the service.

`--part=site` does only this, without the secrets of the service in the environment: the code of Moodle, Composer and the
extra plugins run here. Its result says that the scenario has not run yet, and it ends with 0 once the site is built.
`--part=scenario`, with the same arguments, does the rest on that site and writes the one result of both parts (the
reasons, durations and numbers of the site part go on into it). Without `--part`, one call does both.

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
| `load` | `cli/backup.php --now` in the background. When the run has sent a chunk (32 MiB) in the stage of the files of the site, 4 processes per CPU that only use it raise the load above 2 per CPU, the limit of the plugin. The load stops when the command ends. After the recount of the service, `cli/backup.php --now` | The first command ends with 1 and the run is stopped with `server_busy`, after it sent something in the stage of the files (`numbers.files_sent_before_stop` above 0); the service has it as failed (`server_busy`) and no completed generation. The second command ends with 0; the service has two runs, failed (`server_busy`) and completed with a receipt; the second run did not send again what the first sent |
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

The points of the scenarios (the stage of the files, 10 %) are read from the record of the run in the database of the site
every second or two. A wait looks only at the run of the command it waits for, which is still going: with `--now`, a run
later than the latest one before the command (a stopped run stays in the stage of the files with its bytes sent), and
without `--now`, that latest run itself. If a run is faster than that, or leaves the stage of the files first, the scenario fails and
says so: the sizes are then to be made larger. When the database cannot be read (five times in a row), the scenario fails
with that reason.

## Results and secrets

`$SECBK_E2E_OUT/<name>/`:

- `result.json`: `{scenario, name, passed, reasons, sent_bytes, durations, retries, numbers, requested_bytes, moodle}`.
  `passed` is true when no condition failed and the script ran to its end; `reasons` says what failed, also the step where
  the script stopped. `sent_bytes` is what the service holds of the site at the end. The trap of the script writes it in
  every case.
  - `retries` counts lines of the output of `cli/backup.php`. `waits_without_failure`: the waits that no failed step comes
    before, in any stage but the one of the receipt (where the run asks for its receipt again and again). The requests that
    the service answered as busy (429, 503) are counted here: the plugin does not report them as failed steps, it waits as
    long as the service asks. So are the sends to S3 that are tried again (the plugin waits 15 minutes before it sends a
    chunk again). The output of the command does not tell the two apart. `failed_steps`: the steps that failed; the wait of
    five minutes after each of them is not among the waits.
  - `moodle` is the code of Moodle of the site: `commit`, `version`, `release`, `build`, and `core_manifest`, whether the
    last run of the site had the official list of the files of that build at the service (it then sends only the files
    that differ from it), from the record of the run on the site; null when it is not known.
  - `numbers` holds what the scenarios measure, such as `start_late` (concurrent) and `files_sent_before_stop` (load).
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
site whose inspection is missing, failed or not of its form is not known: the table says `unknown`, the site has that
problem, the line of the total says for how many sites, and the limit is judged on the amounts that are known.

The table also shows the Moodle of each site (its release, and `list used` or `code sent`), `Waits without a failed step` and
`Failed steps` (`retries` above; a line under the table says what the waits count). For `concurrent` a line says how long the
runs of the ten sites ran together, from the latest start to the earliest end of the last run of each site by the times that
the service gives: `started_at`, and `completed_at` or `failed_at`. When the service did not give them, `created_at` and
`updated_at` stand in for them, and the site has that problem (`updated_at` moves on with writes after the end). The line
also says how late the latest site started (`numbers.start_late`); runs that never ran together fail the summary. Notes,
which do not fail it: the sites that sent the code of Moodle because the service has no official list of the files of their
build (see "The Moodle of the sites"), and the sites of `concurrent` that started after the time of the ten.

## The workflow

`.github/workflows/staging-e2e.yml` runs the scenarios on GitHub's standard Ubuntu 24.04 runners, which are free for a
public repository. It starts only by hand, on the branch that holds the version of the test to run:

    gh workflow run staging-e2e.yml --ref main -f scenarios=normal -f normal_gb=1
    gh workflow run staging-e2e.yml --ref main -f scenarios=all

| Input | Default | What it is |
|---|---|---|
| `scenarios` | `data-only` | `all` (the four scenarios, 13 sites), `normal`, `load`, `abort`, `concurrent` (ten sites), or `data-only` |
| `normal_gb` | `10` | GB (10^9 bytes) of files of the site of `normal` |
| `load_gb` | `4` | GB of files of the site of `load` |
| `abort_gb` | `4` | GB of files of the site of `abort` |
| `concurrent_gb` | `0.8` | GB of files of each of the ten sites of `concurrent` |

| Job | Environment | What it does | Limits: the job, the steps of the site and of the scenario, one `cli/backup.php` (minutes) |
|---|---|---|---|
| `plan` | none | Refuses a size that is not a number of GB (up to nine decimals) or is below 0.2, and a selection whose files add up to more than 30 GB (`normal + load + abort + 10 x concurrent`); sets the start of the ten sites, 40 minutes later | 5 |
| `reset` | `staging-e2e` | `sec.php reset`: removes the test sites that earlier runs made | 15 |
| `normal` | `staging-e2e` | `run.sh normal --part=site`, then `--part=scenario` | 180, 25 + 130, 100 |
| `load` | `staging-e2e` | `run.sh load --part=site`, then `--part=scenario` | 180, 20 + 135, 50 |
| `abort` | `staging-e2e` | `run.sh abort --part=site`, then `--part=scenario` | 240, 20 + 195, 40 |
| `concurrent` (1 to 10) | `staging-e2e` | `run.sh concurrent --part=site`, then `--part=scenario`, on ten runners, which start their backups together at the time that `plan` set | 150, 8 + 117, 60 |
| `data-only` | none | `run.sh data-only` with 0.2 GB: Moodle, the extra plugins and the data are made and checked on the runner; the service is not reached | 60, 35 |
| `summary` | none | `summary.php` over the results of all the jobs, with the limit of 30 GB; it also fails when a job did not run to its end | 10 |

Approvals. Each job with the environment waits for a reviewer: first `reset`, then the jobs of the scenarios, which all wait
for `reset` and are approved together. The ten sites of `concurrent` start 40 minutes after `plan`, so approve both without
delay; a site that is ready after that time starts at once, and its result says how late (`numbers.start_late`).

Two steps. Each scenario runs in two steps of its job: the site (`--part=site`), which has no secret of the environment,
since the code of Moodle, Composer and the extra plugins run in it, and the scenario (`--part=scenario`), which alone is
given the URL and the secret of the service. A site step that fails writes `result.json` with the step where it stopped,
the scenario step does not run, and the result is still uploaded.

Limits. The two steps of a scenario have limits that add up to 25 minutes below that of its job: a run that hangs is
stopped by a step, whose log GitHub keeps, and the next steps still upload the result. Each step is `run.sh` itself
(`exec`), so the signal reaches its trap, which writes `result.json` with the step where it stopped. The site step has 25
minutes for 10 GB, 20 for 4 GB and 8 for 0.8 GB (`data-only` built its site of 0.2 GB in 85 seconds).
`SECBK_E2E_BACKUP_TIMEOUT` is set for each scenario so that the typical longest path of its scenario step ends within that
step: each point that depends on the speed of a run (the files reached, 10 % sent, the stop by the load) comes within 5
minutes, and every other wait is at its limit, also the check after a stop (`e2e_wait_stopped`: tries for 600 s, the last
request up to 300 s; it runs within the 20 minutes of the wait for the recount, which count from the stop) and the checks at
the end (three requests of up to 300 s, a minute apart). The comments of the workflow show the sums (`normal` 123 of 130,
`load` 103 of 135, `abort` 171 of 195, `concurrent` 114 of 117 minutes). A slower run reaches the limit of the step
instead, and the trap writes that step in `result.json`. Besides the site and the backups,
`load` waits 20 minutes for the recount of the service, and `abort` 20 minutes for the recount and about 30 minutes for the
plugin to give up the refused connections to S3. The limits are first guesses: they are to be reviewed once the first run
has measured the speed of the runner to S3.

Runs again. The jobs that reach the service (`reset` and the scenarios) refuse to run again, and fail at their first step
with that reason: GitHub runs a failed job again without `plan` and `reset`, so the job would take the name of its site,
the start of the ten sites and the limit of 30 GB from the first attempt. Start a new run of the workflow instead.
`data-only` may run again.

Results. Each scenario job uploads `result.json` and `inspect.json` of its site as the artifact `e2e-<scenario>`
(`e2e-concurrent-<n>`); never `logs/` or `private/`. `summary` adds the table of `summary.php` to the summary of the run and
keeps it as the artifact `summary`. The artifacts are kept 90 days: the clean-up below finds the IDs of the test sites in
them (`site_uuid` of `inspect.json`). Each job also prints the room left on its disk and in memory at its end.

Pace. One run that reaches the service at a time: the concurrency group `staging-e2e` holds a second such run until the
first ends (GitHub keeps one waiting run; a third replaces it). A run of `data-only` has a group of its own
(`staging-e2e-data-only-<run ID>`), so it neither waits for such a run nor takes the place of one that waits. Not more than
two runs in an hour: each registration of a site makes the service send a confirmation mail, it allows 30 an hour for the
test organization, and a run of `all` registers 13 sites.

What is sent. The 30 GB of `plan` counts the files of the sites. The database of each site and, until the service holds the
list of the files of this release of Moodle, the code of Moodle (a few hundred MB for each site) come on top; `summary`
judges what the service holds of all the sites at the end against 30 GB.

The runner. The first run of `data-only` (run 39850020594) had a root disk of 145 GB with 86 GB free, 111 GB once
`make-room.sh` had removed the tools, no disk at `/mnt`, and 16 GB of memory with 14.9 GB available after the build. It built
its site of 0.2 GB in 85 seconds: Moodle 4.5.15+ (build 20261021) on PHP 8.3 and PostgreSQL 13 with all seven extra plugins
of `plugins.json`, installed, up to date and with their data. None is skipped.

## The Moodle of the sites

The sites are built with Moodle 4.5 at one commit of `MOODLE_405_STABLE`, fixed in `moodle.json` with the `$version` and the
`$release` of its code, as the extra plugins are fixed in `plugins.json`. The head of the branch moves every week, and the
service compares the code of a site with the official list of the files of its build (by `$version` and the build of
`$release`): with the list, a run sends only the files that differ from it; without it, each site sends the whole code of
Moodle (a few hundred MB, not compressed), and the 13 sites of `all` can then pass the 30 GB of a run. The site step
fetches that commit alone, and stops when the code is not at it or its `version.php` does not have the `$version` and
`$release` of `moodle.json`. The result keeps the Moodle of the site and whether its last run had the list (`moodle` of
`result.json`), and the summary notes the sites that sent the code.

It is now the commit `cae172ee4aeec1d7a6eaba7bb5ae12a5f0fe3772` (weekly release 4.5.15+): `$version` 2024100715.01,
`$release` `4.5.15+ (Build: 20261021)`, the Moodle that the first runs of `data-only` built.

To move it to a later commit:

1. Take the commit (`git ls-remote https://github.com/moodle/moodle.git refs/heads/MOODLE_405_STABLE`) and the `$version`
   and `$release` of its `version.php`, and write the three values in `moodle.json`.
2. Run `data-only`: its site step checks the commit and the version.
3. Before the next run that reaches the service, have the official list of the files of the new build loaded at the staging
   service (its operators load it from the official package of that build). Until then each site sends the code of Moodle,
   and the summary notes it.

## The environment staging-e2e

A maintainer makes it once, in the settings of the repository (Environments):

1. A new environment `staging-e2e`.
2. Required reviewers: the maintainers who may send data to the staging service.
3. Deployment branches and tags: selected branches only, ones that only maintainers push to (for example `main`). Until
   the workflow is on `main`, the branch that the test runs from (`staging-e2e`) has to be among them too: a job of the
   environment on a branch that is not is refused.
4. Two environment secrets (not repository secrets, not variables):
   - `SECBK_E2E_BASE_URL`: the URL of the API of the staging service, `https://<host>/<path>`, without `/v1`.
   - `SECBK_E2E_SECRET`: the secret of the test endpoints, the same value that the staging service has (32 characters at
     least). `gh secret set SECBK_E2E_SECRET --env staging-e2e` reads it from its standard input, so that it is on no
     command line and in no history.
5. No AWS credentials, here or anywhere in GitHub: the runners reach S3 only with the upload URLs that the service signs.

The workflow has no trigger but `workflow_dispatch`, so no pull request (from a fork or not) runs it, and GitHub gives the
secrets of the environment only to the jobs that a reviewer approved. `data-only` uses no environment and needs no approval.

## Cost

GitHub: nothing. A run of `all` uses 13 standard runners at once (a free account runs 20 jobs at once), each for one to four
hours.

AWS: every object that a run sends stays in the staging storage, locked in COMPLIANCE mode for the lock period of the plan of
the test organization and 14 days more (about 15 days), in the storage class S3 Intelligent-Tiering: about JPY 1.8 per GB
for those 15 days (1 USD = JPY 160), so about JPY 55 for a run of 30 GB. The objects stay after their lock ends until they
are removed (below), at about JPY 3.7 per GB for each month. The summary of each run shows how much the service holds of its
sites.

## Clean-up, once a month

The service does not yet remove the objects of a site that it removed: the job `reset` removes the test sites, not their
objects. Once a month, an administrator of the AWS account of the staging service removes the objects of the test sites
whose lock has ended, with their own credentials on their own machine:

1. The IDs of the test sites of the runs that ended more than 15 days ago: `site_uuid` in `inspect.json` of the artifacts
   of those runs (kept 90 days), or the test sites that the service lists.
2. For each site, the versions and the delete markers under its folder:
   `aws s3api list-object-versions --bucket <bucket> --prefix sites/<site ID>/`.
3. For each version, its lock: `aws s3api get-object-retention --bucket <bucket> --key <key> --version-id <version>`.
   The versions whose `RetainUntilDate` has passed, those without a lock (the list of what the service holds of a site is not
   locked), and then the delete markers, are removed one by one:
   `aws s3api delete-object --bucket <bucket> --key <key> --version-id <version>`. S3 refuses to remove a version whose
   lock has not ended (COMPLIANCE mode binds every user, the root user too): those wait for the next month.
4. The folder is listed again: it is empty once every lock has ended.

These steps have not been run yet: the first objects of the test lose their lock about 15 days after the first run.
