# Running the CI checks locally

The workflow `.github/workflows/ci.yml` is the only definition of the checks.
`.dev/ci/run.sh <ver> <db>` runs the same checks, in the same order, in the development environments of `.dev/env`, and
leaves one log per step in `.dev/ci/out/<ver>-<db>/`. It exits with 0 only when every step passed.

    .dev/ci/run.sh 503 pgsql
    SECBK_PHP=8.1 .dev/ci/run.sh 405 mariadb     # another PHP version, in its own containers

The default branch of the repository is `main`.

## The workflow

| Job | What it does |
|---|---|
| `rules` | The commit rules (tests of the hooks, every commit of the history, the title and description of a pull request), the check that no development script can be run from the web, and the tests of the coverage tools and of the badge. |
| `test` | One job for each combination of Moodle, PHP and database (18 in all, below): every check of moodle-plugin-ci, PHPUnit, Behat in Chrome and the command line scripts, with the coverage of the combination. Nothing in it is optional: Behat runs in every combination and a scenario has to pass the first time (`--auto-rerun 0`). |
| `coverage` | Merges the coverage of all combinations, writes `coverage-summary.json` (the artifact `coverage-summary`) and fails unless every line of every shipped file ran. |

The workflow `.github/workflows/badges.yml` (`Badges`) turns the summary of a push to `main` into the coverage badge of the README.

### Tested combinations

The newest PHP of each Moodle version runs with each database, the oldest PHP runs with PostgreSQL. The database versions are the
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
| Commit rules and development tools | `bash .dev/hooks/tests/run.sh`, `bash .dev/hooks/check-history.sh`, `bash .dev/hooks/check-dev-guards.sh`, `php .dev/coverage/tests/run.php`, `php .dev/coverage/tests/badge.php` |
| Install Moodle with the plugin | `.dev/env/setup.sh`, `up.sh`, `install.sh`, `devconfig.sh` |
| PHP Lint, Mess Detector, Code Checker, PHPDoc, Validating, Savepoints, Mustache, Grunt | moodle-plugin-ci in the web server container |
| PHPUnit tests, Behat features, command line scripts, coverage of this combination | `.dev/coverage/run.sh <ver> <db>` (PHPUnit with `--fail-on-warning`, Behat with the fake service of `.dev/fakesec`) |
| Line coverage of all combinations | `php .dev/coverage/merge.php --shipped=... .dev/coverage/out/*/*` |

### Steps whose local counterpart differs

| Workflow step | What `run.sh` does instead, and why |
|---|---|
| Grunt (`moodle-plugin-ci grunt`) | The Gherkin lint of `tests/behat/*.feature`, which is the only Grunt task that applies to this plugin, with the configuration `.gherkin-lintrc` of the environment's Moodle checkout and the version of gherkin-lint that the Moodle branch pins in `npm-shrinkwrap.json`. `moodle-plugin-ci grunt` cannot run in the development environments: the web server image has no Node.js, and the command copies the plugin folder away and mirrors it back, which here is this repository with its `.git`. No check may write to the repository. gherkin-lint is installed once with npm into `.dev/ci/out/tools/gherkin-lint` (nothing global). The step fails when the plugin gains `amd/src`, `yui/src`, CSS, SCSS or LESS files, because CI would then run Grunt tasks that this step does not. |
| Install Moodle with the plugin, Turn the coverage collector on | Done once by `.dev/env` scripts, and by `.dev/coverage/run.sh` for the collector. |
| Upload the coverage, Upload Behat Faildump | No counterpart: the results stay in `.dev/coverage/out/<ver>-<db>/` and `.dev/ci/out/<ver>-<db>/`. |
| `coverage` job (merge of all combinations, summary, 100% gate) | Not part of `run.sh`: one environment is one combination. `.dev/coverage/run.sh` merges the combination alone, and the merge of several is `php .dev/coverage/merge.php`. |
| `Badges` workflow | Cannot run outside GitHub (`workflow_run`, Pages). `badge.php` and its tests run locally. |

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

## Changing the workflows

Run the change with act first, and with `run.sh` for the parts act cannot run. Only then run it on GitHub.

    docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.7 -color .github/workflows/*.yml
