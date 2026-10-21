# Running the CI checks locally

The workflow `.github/workflows/ci.yml` is the only definition of the checks.
`.dev/ci/run.sh <ver> <db>` runs the same checks, in the same order, in the development environments of `.dev/env`, and
leaves one log per step in `.dev/ci/out/<ver>-<db>/`. It exits with 0 only when every step passed.

    .dev/ci/run.sh 503 pgsql
    SECBK_PHP=8.1 .dev/ci/run.sh 405 mariadb     # another PHP version, in its own containers
    SECBK_BROWSER=firefox .dev/ci/run.sh 503 pgsql   # Behat in another browser: firefox, edge or safari (see "Behat in four browsers")

The default branch of the repository is `main`.

## The workflow

| Job | What it does |
|---|---|
| `rules` | The commit rules (tests of the hooks, every commit of the history, the title and description of a pull request), the files of every commit, their names and the tags (`.dev/hooks/check-contents.sh`), the check that no development script can be run from the web, the tests of the coverage tools and of the badge, and the tests of the tools of the mutation testing with the check of its shards (below). |
| `inventory` | `tests/behat/inventory.txt` against the code (below), and the tests of that check. It needs no secret, so a pull request from a fork gets it. |
| `test` | One job for each combination of Moodle, PHP and database (18 in all, below): every check of moodle-plugin-ci, PHPUnit, Behat in Chrome and the command line scripts, with the coverage of the combination; then the command line scripts once more with developer debugging, after a registration with the fake service, with a whole run of `cli/backup.php --now` (`.dev/ci/backup-developer-debug.sh`: a PHP message or a `debugging()` message in the output fails it). Nothing in it is optional: Behat runs in every combination and a scenario has to pass the first time (`--auto-rerun 0`). |
| `behat` | Behat in Firefox and in Edge on Moodle 4.5, 5.0, 5.1, 5.2 and 5.3, each with PostgreSQL and MariaDB: 20 jobs, with the newest PHP of the Moodle version. The same install as `test`, without the coverage; `moodle-plugin-ci behat --profile <browser> --selenium selenium/standalone-<browser>:4 --auto-rerun 0`. The Edge profile is added to `config.php` by the job (moodle-plugin-ci knows only chrome and firefox). On failure the Behat dump is uploaded (`Behat Faildump <browser> ...`; no artifact name but the coverage ones starts with `coverage-run-`). |
| `behat-safari` | Behat in Safari on a macOS runner (below): the same ten Moodle and database pairs, 10 jobs. |
| `secureos` | Twelve jobs: the access control that the operating system ships, in real virtual machines (Lima with QEMU and KVM). `rocky-9`, `rocky-10`, `almalinux-9`, `almalinux-10` (SELinux), and `debian-12`, `debian-13`, `ubuntu-22.04`, `ubuntu-24.04` (AppArmor) in the states `standard` and `confined`. Each runs `.dev/secureos/run-template.sh -s <state> <template>`; the secret `SECBK_SECUREOS_ENDPOINT` (the connection URL of a real service) selects the real expectations, and without it the stand-in's are checked. See `.dev/secureos/README.md`. |
| `coverage` | Checks that one artifact `coverage-run-<Moodle>-<PHP>-<database>` arrived for each row of the matrix of `test`, with its PHPUnit report, and no other (`.dev/ci/check-coverage-runs.sh`; no other artifact name may start with `coverage-run-`), merges the coverage of all combinations, writes `coverage-summary.json` (the artifact `coverage-summary`) and fails unless every line of every shipped file ran. |

Every job has `timeout-minutes` (in `badges.yml` too): `rules` 15, `inventory` 10, `coverage` 15, `behat` 55, `test` 130,
`behat-safari` 75, `secureos` 45 (60 for the state `run`) and the badge job 10. When GitHub stops a job at its own limit, the
log of the step that was running is lost (a PHPUnit step that stalled in run 39493954359 left no log), so these steps have
limits of their own, which in each job add up to less than the limit of the job:

| Job | Steps with a limit of their own |
|---|---|
| `test` | Install Moodle with the plugin 20 minutes (a failed download of Node.js makes nvm build it from its source for more than an hour), PHPUnit tests 40, PHPUnit trace tests with OPcache 5, Behat features 25, Command line scripts 5, The backup on the command line with developer debugging 20 (115 of 130) |
| `behat` | Install Moodle with the plugin 20, Behat features 25 (45 of 55) |
| `behat-safari` | Install Moodle with the plugin 20, Behat features 35 (55 of 75) |

A stall in one of these fails that step with its log. The other steps (the set-up of PHP and moodle-plugin-ci, the database
and safaridriver of the Safari job, the static checks, the two installs of the site in `test`, the uploads) have no limit
of their own: they take a few minutes in all, and a stall in one of them would stop the job at its limit without the log of
that step. On GitHub (2026-10-20) the `test` jobs took 19 to 41 minutes (PHPUnit 11 to 24, Behat 5 to 11), a `behat` job 6
to 10 minutes, a `behat-safari` job 7 to 16. Under act, which is slower than a GitHub runner (amd64 emulated on Apple
silicon), the `test` job took 44 minutes (the install 7 minutes, PHPUnit 14, Behat 12), a `behat` job 22 minutes (Firefox) or
26 (Edge, with Behat 14 of them), `inventory` 2 minutes. `rules` and `coverage` take a few minutes at most. A hung job is
stopped, and a slow day does not fail a job.

**Known failures of a browser or a runner.** Two failures that are not the plugin's were seen on GitHub, and the comments of
the jobs `behat` and `behat-safari` in `ci.yml` have the evidence: safaridriver answering "no such window" while a page is
being replaced (4 of 188 Safari jobs from 2026-10-19 to 2026-10-21; the steps of the plugin now wait through it), and a front
page that did not finish its JavaScript in 10 seconds before the first step of a scenario (2 of about 220 Edge jobs, both
Moodle 5.3 with MariaDB; the time limits of Behat are now doubled for Edge on GitHub). When the Behat
step of a job fails, the next step (`.dev/ci/behat-known-failures.sh`) names either of them in the summary of the run, with
how many of the failures that Behat lists it explains and how many others there are, so that a known name never covers a
failure of the plugin (`bash .dev/hooks/tests/run.sh` tests this). The job fails all the same: a scenario is never run again
to make a run green.

**Mutation testing** runs in its own manual workflow, `.github/workflows/infection.yml`: Infection with the configuration of
`.dev/infection/run.sh`, split into the shards of `.dev/infection/shards.txt`, one job each, and a last job that adds them up
into one covered code MSI and checks it against `minCoveredMsi` (`.dev/infection/merge.php`). `.dev/infection/REVIEW.md` has the
result and the review of every escaped mutant.

The workflow `.github/workflows/badges.yml` (`Badges`) turns the summary of a push to `main` into the coverage badge of the README.

### Tested combinations

The newest PHP of each Moodle version runs with each database listed for it. Moodle 4.5, 5.1, 5.2 and 5.3 also run an older PHP, with PostgreSQL only; Moodle 5.0 has no older PHP row. The database versions are the
lowest ones that the Moodle version supports; `.dev/env/env.sh` holds the same values.

| Moodle | Newer PHP | Older PHP | PostgreSQL | MariaDB | MySQL |
|---|---|---|---|---|---|
| 4.5 | 8.3 | 8.1 | 13 | 10.6.7 | 8.0 |
| 5.0 | 8.4 | - | 14 | 10.11 | - |
| 5.1 | 8.4 | 8.2 | 15 | 10.11 | 8.4 |
| 5.2 | 8.4 | 8.3 | 16 | 10.11 | 8.4 |
| 5.3 | 8.4 | 8.3 | 17 | 11.4 | 8.4 |

### Which steps run where

| Workflow step | Local step (`.dev/ci/run.sh` and friends) |
|---|---|
| Commit rules and development tools | `bash .dev/hooks/tests/run.sh`, `bash .dev/hooks/check-history.sh`, `bash .dev/hooks/check-contents.sh`, `bash .dev/hooks/check-dev-guards.sh`, `php .dev/coverage/tests/run.php`, `php .dev/coverage/tests/badge.php`, `php .dev/infection/tests/run.php`, `php .dev/infection/shards.php check` |
| Install Moodle with the plugin | `.dev/env/setup.sh`, `up.sh`, `install.sh`, `devconfig.sh` |
| PHP Lint, Mess Detector, Code Checker, PHPDoc, Validating, Savepoints, Mustache, Grunt | moodle-plugin-ci in the web server container |
| UI inventory | `php .dev/ci/check-ui-inventory.php` (inside `run.sh`, in the web server container) |
| PHPUnit trace tests with OPcache | `.dev/ci/phpunit-opcache.sh /tmp/secbk-opcache .dev/env/run.sh <ver> <db>` (inside `run.sh`) |
| PHPUnit tests, Behat features, command line scripts, coverage of this combination | `.dev/coverage/run.sh <ver> <db>` (PHPUnit with `--fail-on-warning`, Behat in Chrome with the fake service of `.dev/fakesec`), then the run of the diagnostics under open_basedir (`.dev/ci/diagnose-open-basedir.sh`, the last run of "Command line scripts") |
| Behat in Firefox, Edge, Safari | `SECBK_BROWSER=<browser> .dev/ci/run.sh <ver> <db>` (below) |
| Line coverage of all combinations | `php .dev/coverage/merge.php --shipped=... .dev/coverage/out/*/*` |

### Steps whose local counterpart differs

| Workflow step | What `run.sh` does instead, and why |
|---|---|
| Grunt (`moodle-plugin-ci grunt`) | The Gherkin lint of `tests/behat/*.feature`, which is the only Grunt task that applies to this plugin, with the configuration `.gherkin-lintrc` of the environment's Moodle checkout and the version of gherkin-lint that the Moodle branch pins in `npm-shrinkwrap.json`. `moodle-plugin-ci grunt` cannot run in the development environments: the web server image has no Node.js, and the command copies the plugin folder away and mirrors it back, which here is this repository with its `.git`. No check may write to the repository. gherkin-lint is installed once with npm into `.dev/ci/out/tools/gherkin-lint` (nothing global). The step fails when the plugin gains `amd/src`, `yui/src`, CSS, SCSS or LESS files, because CI would then run Grunt tasks that this step does not. |
| Install Moodle with the plugin, Turn the coverage collector on | Done once by `.dev/env` scripts, and by `.dev/coverage/run.sh` for the collector. |
| `behat` and `behat-safari` jobs | `SECBK_BROWSER=<firefox|edge|safari> run.sh`. The browsers run in Selenium containers (Firefox, Edge) or on this Mac (Safari), and the Behat site is served by the Apache of the environment, not by the web server of PHP. `behat-safari`'s own steps (Homebrew databases, `safaridriver` on the runner, `php -S`) have no local counterpart. |
| `inventory` job: the tests of the check | Part of `bash .dev/hooks/tests/run.sh`, which `run.sh` does not run (it runs the check itself). |
| Upload the coverage, Upload Behat Faildump | No counterpart: the results stay in `.dev/coverage/out/<ver>-<db>/` and `.dev/ci/out/<ver>-<db>/`. |
| `coverage` job (merge of all combinations, summary, 100% gate) | Not part of `run.sh`: one environment is one combination. `.dev/coverage/run.sh` merges the combination alone, and the merge of several is `php .dev/coverage/merge.php`. |
| `Badges` workflow | Cannot run outside GitHub (`workflow_run`, Pages). `badge.php` and its tests run locally. |
| The backup on the command line with developer debugging | Not run: it registers the site with the fake service, which would replace a registration made by hand on the development site. `bash .dev/ci/backup-developer-debug.sh <Moodle folder>` runs it on a site that may be changed and runs with developer debugging. |
| Name a known failure of the browser or the runner | Not run: `bash .dev/ci/behat-known-failures.sh <output of Behat>` names them in any saved output. |

## Behat in four browsers

Behat proves that every page and every operation of the plugin works (`tests/behat/inventory.txt`, below). Every feature file
has `@javascript`, so each scenario runs in a real browser, with the JavaScript that Moodle's forms run for an administrator.

| Browser | In CI | Locally (`SECBK_BROWSER=...`) |
|---|---|---|
| Chrome | `test`, all 18 combinations, with the coverage | The default; `run.sh` and `.dev/coverage/run.sh` run it in the Selenium container of the environment, with the coverage. |
| Firefox | `behat`, 10 jobs | `.dev/env/browser.sh` starts `selenium/standalone-firefox:4` next to the environment; `run.sh` runs only the inventory and Behat. |
| Edge | `behat`, 10 jobs | The same with `selenium/standalone-edge:4`, as linux/amd64 under emulation (no Edge for Linux on arm64): slow, so the time limits of Behat are 8 times longer. |
| Safari | `behat-safari`, 10 jobs | Safari on this Mac through `safaridriver` (allowed once with `sudo safaridriver --enable`): see `.dev/env/README.md` for what the script sets up and undoes. Safari opens an automation window on the desktop. |

Only Chrome has coverage. `run.sh` for another browser makes no coverage and does not run the static checks, PHPUnit or the
command line scripts (they do not depend on the browser).

The Safari job (`behat-safari`) runs on `macos-26` (the newest macOS image of GitHub's runner list, Apple silicon, Safari 26).
There is no Docker on macOS runners, so: PHP comes from setup-php; the databases come from Homebrew at the lowest version that
Homebrew still has and the Moodle version supports (PostgreSQL 13 is no longer in Homebrew and PostgreSQL 14 is deprecated there and disabled on 2026-11-12, so Moodle 4.5 and 5.0 use
PostgreSQL 15 there, where `ci.yml`'s services use 13 and 14; PostgreSQL 15, 16 and 17 and MariaDB 10.11 and 11.4 are the same as in
the other jobs; MariaDB 10.6 for Moodle 4.5 is Homebrew's 10.6.x instead of 10.6.7, and the formula `mariadb@10.6` is deprecated since
2026-06-01 (no disable date yet), so that row needs a look when Homebrew drops it); `sudo safaridriver --enable` allows the automation; `safaridriver -p 4444`
and PHP's own web server (`php -S localhost:8000`, four workers) are started by the job, and `moodle-plugin-ci behat` is told
not to start Selenium in Docker (`MOODLE_START_BEHAT_SERVERS=NO`). The Safari profile (`wd_host` without `/wd/hub`) is added to
`config.php` by the job. This job cannot run outside GitHub: the local Safari run (`SECBK_BROWSER=safari`) uses another set-up
(Docker, relays), so it proves the scenarios in Safari but not these job steps. Their first run is on GitHub.

## The UI inventory

`tests/behat/inventory.txt` has one line for each operation a person can do: `page | operation | feature file: scenario title`.
The pages are the web entry files of the plugin, `settings.php` for the entries of the administration tree, and the one page of
Moodle that the plugin adds a row to (Scheduled tasks; its controls are Moodle's, so only the plugin's task name is listed).
`.dev/ci/check-ui-inventory.php` (job `inventory`, and `run.sh`) fails with the file and the missing name when: a feature file lacks
`@tool_secbackup` (moodle-plugin-ci runs only the features that carry the tag of the component) or `@javascript`; a web entry
file at the root is not a page of the inventory (`version.php`, `settings.php` and `lib.php` are not pages, for the reasons in
the script); a button label of a form, a link text of the renderer or an entry of the administration tree is not an operation of
the inventory; a listed scenario does not exist, or has no step that does the operation (press, click on, follow, set the field,
open) with its label, or no step that checks what the page shows. Its tests are part of `bash .dev/hooks/tests/run.sh`.

The step `I open "Plugins > ... > Status" in site administration` of the plugin's Behat context replaces Moodle's `I navigate
to ...`: safaridriver gives the control back after a click before the page has changed, and Moodle's step looks for the tab of
the administration page at once, on the old page. The plugin's step waits for the new page (a marker on the old one) and tries the
click again until the element is there; it finds the same tab and link, in every browser.

## The commit rules and the history

The commit rules need every commit of the history to have a Japanese message and the maintainers' identity, and `rules` checks
every commit it can reach. The history is therefore linear and made by the maintainers' identity: GitHub's merge button is not used,
because the merge commit it makes has neither. A pull request is brought in by the maintainers with the commits rewritten as
they must be.

On a pull request, `rules` checks out the head of the pull request, not the merge commit that GitHub makes for the test.
A pull request from a fork gets no secret, so `rules` fails there by design, and its log says so; the jobs `test` and
`coverage` need no secret and run for it.

## The badges

The README shows two badges.

- CI is GitHub's own badge of the last push to `main`. It is red when any job failed, the Behat runs of every combination included.
- Coverage is `https://shinonomelabo.github.io/moodle-tool_secbackup/coverage.svg` on GitHub Pages. The `coverage` job writes the
  merged line counts to `coverage-summary.json` and uploads it as the artifact `coverage-summary`, also when it finds lines that
  no run executed. When a run of `main` has finished, `Badges` downloads that artifact, draws the badge with `.dev/ci/badge.php`
  and deploys it. A run without the artifact (a test job failed, so `coverage` did not run) leaves the last badge as it is.
- `.dev/ci/badge.php <summary.json> <out.svg>` refuses a summary that is not exactly `{"covered": N, "total": M}` with
  integers and `0 <= N <= M`, `M > 0`. The percentage is rounded down, and the badge is green only at 100%.

GitHub Pages must be switched on once (Settings > Pages > Source: GitHub Actions). The environment `github-pages` deploys from
`main` only.

## Updating the actions

Every `uses:` line names the full commit of a release, with the release in a comment on the same line:

    uses: actions/checkout@<40 hex digits> # v7.0.1

A tag can be moved by whoever controls the repository of an action, so a tag is not used. To update one:

1. List the releases: `git ls-remote --tags https://github.com/<owner>/<repo>` and pick the newest.
2. Take its commit. For an annotated tag it is the line that ends with `^{}`; for a lightweight tag it is the line of the tag.
3. Write the commit and the release into every `uses:` line of that action in both workflow files.
4. If the action is a composite action, read its `action.yml` at that commit: the actions it calls must be pinned to commits too.
5. Run actionlint (below).

There is no Dependabot configuration: its pull requests could not pass the commit rules.

## act

`.dev/ci/act.sh` runs a job of the workflow with act in a clone of the committed state (the clone is made next to the
repository, in `secbackup-act`, because the coverage collector ignores every path that contains `/.dev/`). It needs act and
Docker, and builds the image `secbk-act-ubuntu:24.04` on first use: catthehacker's `ubuntu:act-24.04` plus what GitHub's own
runner image has and the workflow uses (locale-gen, the PostgreSQL and MySQL clients, nvm). The images are amd64, so on Apple
Silicon they run emulated and slowly.

    SECBACKUP_PATTERNS="$(printf '%s\n' some made-up words)" .dev/ci/act.sh rules
    .dev/ci/act.sh test moodle:5.3 php:8.4 database:pgsql

Never give act the real list of refused words. SECBK_ACT_EVENT=<file> runs a `pull_request` event from a JSON file. Jobs: `rules`, `inventory`, `test` and `behat` (`.dev/ci/act.sh behat browser:firefox moodle:5.3 database:pgsql`; `browser:edge` for Edge). act makes one Docker network for each job, and Docker's pool of networks can run out when many environments exist ("all predefined address pools have been fully subnetted"): remove networks that are not in use. A failed job can leave its containers (`act-CI-...` and `selenium`) behind, which keep ports 3306, 5432 and 4444 busy for the next run.

What ran, and what did not:

| Job | Result under act |
|---|---|
| `rules` (push and pull_request events) | Runs completely: the tests of the hooks, every commit, the files of every commit and the tags, the title and description of the pull request, the check for the development scripts, the tests of the coverage tools and of the badge. With a commit that has a made-up refused word, it fails at "Check every commit" with the reason and the commit, and neither the word nor the list is in the log. With a made-up word that only the files of older commits hold, it fails at "Check the files of every commit and the tags", naming each commit, the file and the number of lines, and the word is not in the log. |
| `inventory` | Runs completely (2 minutes). |
| `test`, Moodle 5.3 with PHP 8.4 and PostgreSQL | Runs to the end in about 44 minutes: the install (7 minutes), every static check, PHPUnit (14 minutes, 1105 tests), Behat in Chrome (12 minutes, 21 scenarios), the command line scripts with their five exit codes and the run under open_basedir, and the coverage files, which merge to 1556/1556 lines with `merge.php` (the same report, line by line, as `run.sh`'s merge). Only "Upload the coverage" fails, because act has no artifact service (no `ACTIONS_RUNTIME_TOKEN`). `SECBK_ACT_KEEP=1` keeps the clone, so the coverage files can be merged by hand. |
| `behat`, Moodle 5.3 with PostgreSQL, Firefox and Edge | Firefox runs to the end in about 22 minutes and Edge in 26, 21 scenarios each. Only the upload of the Behat dump fails when a scenario fails (no artifact service). Edge runs emulated and misses Moodle's time limit of 10 seconds now and then, so `act.sh` sets `SECBK_BEHAT_TIMEOUT_FACTOR` to 8 (the job adds `$CFG->behat_increasetimeout`; 2 on GitHub). |
| `behat-safari` | Cannot run: it needs a macOS runner. `SECBK_BROWSER=safari .dev/ci/run.sh` runs the scenarios in Safari on this Mac (another set-up), and the YAML of the job is checked by actionlint only. |
| `coverage` | Not run: it needs the artifacts of `test`. The merge of the files that the `test` run left was run by hand with the commands of the job. The check that every combination sent its coverage (`check-coverage-runs.sh`) is tested in `bash .dev/hooks/tests/run.sh` with planted folders. |
| `secureos` | Cannot run: act has no virtual machine. `act -n -j secureos` (a dry run, with `-P ubuntu-24.04=secbk-act-ubuntu:24.04 --container-architecture linux/amd64`) parses the job and plans its steps. The part that matters runs on the Mac: `.dev/secureos/matrix.sh` runs the same script (`run-template.sh`) in Lima machines for all eight templates. actionlint passes. |
| `Badges` | Cannot run: act has no `workflow_run` and no Pages. It first runs on GitHub; `badge.php` is tested locally. |
| The other 17 combinations | Not run with act (about 25 minutes each); `run.sh` runs them. |

What act cannot do, and what stands in for it:

- `actions/checkout` with `ref`, `repository` or `path` would fetch from GitHub, which has no commit of this repository yet.
  A local action (`.dev/ci/act/local-checkout`) takes its place and copies the files that git tracks into the folder that the step names (`plugin/`).
  The checkout of the head of a pull request (`ref`) is therefore not tried by act.
- The job container is catthehacker's, not GitHub's: its PATH does not name Node.js, and it lacks `locale-gen`, the database clients
  and nvm. `act.sh` sets the PATH and the image adds the rest.
- Uploading and downloading artifacts, `workflow_run`, Pages and the environment `github-pages`.
- macOS (the job `behat-safari`).
- `actions/checkout`: act skips every checkout step unless `--no-skip-checkout` is given, which `act.sh` does so that the local
  action runs. The PHP web server that serves the Behat site gets four workers (`PHP_CLI_SERVER_WORKERS` in the Behat steps),
  because with one worker the AJAX calls of Moodle queued behind each other and Behat's 10-second limit failed 18 of 21
  scenarios in Chrome under act. The job `behat-safari` runs the web server as one process, with the JIT of OPcache off: on the
  macOS runner a forked worker crashed in CoreFoundation ("multi-threaded process forked" in the crash report), and the JIT
  that setup-php turns on crashed the web server (SIGSEGV in zend_jit_* frames).

Two defects of the workflow were found by act and fixed: the site itself was never installed (moodle-plugin-ci prepares only the
PHPUnit and Behat sites, so the reinstall with the collector and the command line scripts failed), and the `rules` job relied on a PHP that the
runner image happens to have.

## Changing the workflows

Run the change with act first, and with `run.sh` for the parts act cannot run (the `Badges` workflow: `badge.php` and its tests;
the combinations that act does not run). Only then run it on GitHub.

    docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.7 -color .github/workflows/*.yml
