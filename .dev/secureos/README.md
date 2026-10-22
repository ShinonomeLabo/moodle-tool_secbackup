# Test machines for the access control that the OS ships

SELinux and AppArmor cannot be reproduced in a Docker container: containers share the host kernel and run as
`container_t` or unconfined. These scripts build real virtual machines (Lima) and run the connection diagnostics of the
plugin inside them, from the web server and from the command line.

| Template | Access control | What is tested |
|---|---|---|
| `rocky-9`, `rocky-10`, `almalinux-9`, `almalinux-10` | SELinux, enforcing; php-fpm runs as `httpd_t` | state `selinux`: `httpd_can_network_connect` and `httpd_can_network_relay` off, relay only, connect on |
| `debian-12`, `debian-13`, `ubuntu-22.04`, `ubuntu-24.04` | AppArmor | state `standard`: as shipped, php-fpm has no profile |
| the same four | AppArmor | state `confined`: the `php-fpm` profile of the distribution (package `apparmor-profiles`) in enforce mode, with local rules |

Everything comes from the distribution: Apache with php-fpm, PHP, PostgreSQL (over the Unix socket, peer authentication as
the web user). Only on Rocky Linux and AlmaLinux are EPEL and CRB enabled, for an extension that AppStream lacks
(`sodium`; see the table of versions). Every command that reads the mirrors of the distribution (`dnf`, `apt-get`) is tried
up to three times, a minute apart, and the source package of PHP is read from the source repository of PHP's own repository
only (a run once failed on a mirror of `epel-source` with a bad certificate, which that package does not need), on the
primary server of the distribution (`dl.rockylinux.org`, `vault.almalinux.org`) with its metadata read again at each attempt:
the mirrors of a source repository can lag hours behind the binary one after an update (runs failed on a mirror that listed
no source package of the PHP just installed). The package is checked all the same: the signature of the distribution and the
version of the installed PHP. Moodle is 4.5 on
the older version of each pair, and on the newer one the newest
5.x stable branch whose `environment.xml` accepts the PHP and PostgreSQL of the distribution (so 5.1 and later, which serve
from `public/`, are covered too).

Development only. Nothing in this directory is part of the plugin package (`.dev` is `export-ignore`d).

## One machine, in one command

    .dev/secureos/run-template.sh [-p <host port>] [-u <connection URL>] [-m standin|real|both] [-s <state>] [-k] [-r] <template>

It creates the machine, provisions it, copies the plugin in, runs `check.sh`, compares the summary with `expect.txt`
(`assert.sh`) and deletes the machine, also when a step fails or the script is interrupted. `matrix.sh` and the job
`secureos` of the workflow call this script, so the local run and the CI run are the same code.

| Option | Meaning |
|---|---|
| `-u <URL>` | The connection URL of a real service, given as an argument only. Without it the environment variable `SECBK_SECUREOS_ENDPOINT` is used; when that is empty too, the stand-in is used. The URL is handed to `check.sh` as an argument. These scripts do not print it, and the case files hold `<endpoint>` instead. It cannot be kept out of the machine: it is in the access log of Apache (the probe's query string), in the journal of sudo (the command-line cases) and in the arguments of `limactl`, `curl` and `php` there. The machine is deleted at the end, so it goes with it, but a machine kept with `-k` holds the URL. |
| `-m` | Which expectations are checked: `standin`, `real` (needs a URL) or `both`. The default is `real` when there is a URL, otherwise `standin`. |
| `-s` | The state to test: `selinux` (Rocky Linux, AlmaLinux), `standard` or `confined` (Debian, Ubuntu); the default `all` runs every state that fits. The CI job runs one state in each machine. |
| `-p` | Host port forwarded to the web server of the machine (default 18200). |
| `-k` | Keep the machine at the end. |
| `-r` | Replace a machine of the same name. Without it the script stops, so that it never deletes a machine that someone else made. |

The output is the progress of the steps with their times, the summary lines of `check.sh` (case, check, result), the result of
`assert.sh` and the last line `result: <template> <ok|failed|mismatch>`. The exit status is 0 only for `ok`.

### The same, step by step

On the Mac, in the repository (Lima 2.x):

    export SECBK_VM=secbk-debian-12
    limactl create --tty=false --name=$SECBK_VM --cpus=2 --memory=4 --disk=20 --mount-none --containerd=none \
        --set '.portForwards=[{"guestPort":80,"hostPort":18080,"hostIP":"127.0.0.1"}]' template:debian-12
    limactl start --tty=false $SECBK_VM
    limactl shell $SECBK_VM sudo bash -s -- 18080 < .dev/secureos/provision.sh
    .dev/secureos/sync-plugin.sh
    .dev/secureos/check.sh all                       # stand-in connection URL
    .dev/secureos/check.sh all <connection URL>      # a real service: unsigned GET of its status path, nothing changes
    .dev/secureos/assert.sh standin .dev/secureos/out/$SECBK_VM/standin/summary.txt selinux   # or: standard confined
    limactl delete -f $SECBK_VM

The machines are made without containerd (`--containerd=none`; `start_vm` of `lib.sh`, which `run-template.sh` uses,
does the same): the tests do not use it, and the start of almalinux-10 machines waited for its installation until Lima
gave up.

The site is `http://localhost:18080` (admin / test). The port forward listens on `127.0.0.1` only, because of that
password. Use `localhost`, not `127.0.0.1`: Moodle redirects other host names. `SECBK_VM` names the machine
(default `secbk-$SECBK_TEMPLATE`, and `SECBK_TEMPLATE` defaults to `rocky-9`); `provision.sh` takes the host port (the
port of the site's address) as its argument.

`check.sh <selinux|standard|confined|all> [connection URL]` runs the states of the machine's access control, writes each
case to `out/<machine>/<standin|real>/<n>-<case>.txt` and prints a summary: one line per case and check, `<case> <check>
<result>`, never the detail text and never a host name. The access control (SELinux booleans, AppArmor profile and local
rules, the kernel's `printk_ratelimit`) is put back when it ends, also after an error. `check.sh` stops at once when the
machine is not reachable, and (SELinux) when it is not in enforcing mode.

The checks of the summary:

| Check | Meaning |
|---|---|
| `dns`, `connection` | result of the plugin's check (`pass`, `fail`, ...) |
| `dnsproblem`, `connectionerror` | the code of the outcome of those two checks (`dns_doh_unavailable`, `dns_outside_aws`, `dns_os_failed`, `http_401`, `none`), read by `probe.php` from the results themselves (`check_result::$code`); web cases only |
| `plugin-mac` | result of the plugin's own SELinux row (`unknown` on AppArmor machines: SELinux is not used) |
| `plugin-apparmor` | result of the plugin's own AppArmor row (`info` where the label can be read, `unknown` on SELinux machines) |
| `advice` | which advice the failing results of the name check and of the connection carry, as `<dns>:<connection>`; each is `selinux` (the text names `setsebool`), `apparmor` (it names `apparmor_parser`), `both` or `none` |
| `mac` | label of the process: SELinux type (`httpd_t`) or AppArmor profile (`php-fpm_(enforce)`, `unconfined`) |
| `netdenials` | `present` when the kernel logged a denied network connection during the case |
| `phpwarnings` | `present` when the error log of PHP (the web server's, php-fpm's or the command line's) has a warning, notice or deprecation that names `/admin/tool/secbackup/`, or the page or the command line printed one. Web and command-line cases both count; the `*-control` cases must say `present` |

`expect.txt` holds what each result must be, for both kinds of connection URL; a row named `<case>@<template>` holds for one
template only. Usage of the comparison:

    .dev/secureos/assert.sh <real|standin> <summary.txt> <state>...      # the states that ran: selinux, standard, confined

It fails when the summary is missing, unreadable or empty, when a line has no expectation, when a result differs, and when a
case of a named state (a case of `expect.txt` whose name starts with `selinux-`, `standard-`, or `confined-` and `nonet-`
for `confined`) or a check of a case that ran has no result. The template for a `<case>@<template>` row is read from
`machine.txt` next to the summary.

**The positive control of the warning count.** A count that always says `none` would pass without measuring anything. In every
`*-control` case something raises one PHP warning from a file in the plugin's directory: `probe.php` includes
`control-warning.php` when it is asked to (web cases), and the command-line cases run `php -d
auto_prepend_file=.../control-warning.php .../cli/diagnose.php`. `control-warning.php` does nothing unless
`/etc/secbk-secureos-probe` exists, which only `provision.sh` makes.

### The stand-in URL

Without a URL, `check.sh` uses a stand-in on a reserved name. Its address is not in the AWS ranges, so where the network
is open the name check ends with `dns_outside_aws`, and where it is closed with `dns_doh_unavailable`. That tells open from
closed without the real service (CI on a fork has no secret; the job takes the real URL from `SECBK_SECUREOS_ENDPOINT`). The
stand-in is refused as soon as the answers of the DNS over HTTPS services are outside the AWS ranges, so it cannot show
whether the name lookup of the operating system (UDP) works; the real URL shows that (`dns_os_failed`).

## The matrix

    .dev/secureos/matrix.sh [-j 2] [-u <connection URL>] [-k] [-r] [template...]

Runs `run-template.sh` for each template (2 CPUs, 4 GiB, 20 GiB, port forward to `127.0.0.1` on a port of its own); with
`-u` the stand-in and the real service are both checked (`-m both`). `-j 2` runs two at once (never more: the disk is the
limit), `-k` keeps the machines (at most two templates), `-r` replaces machines of the same names (without it the script stops
when one exists). The logs and the status (`ok`, `mismatch`, `failed`) are in `out/matrix/`; the exit status is 0 only when
every template is `ok`.

## In GitHub Actions

The job `secureos` of `.github/workflows/ci.yml` runs on every push to `main`, every pull request and every manual run with
the scope `all`, like the other jobs (so the CI badge covers it). It is a matrix of fourteen jobs: the four SELinux templates
(`rocky-9`, `rocky-10`, `almalinux-9`, `almalinux-10`, state `selinux`), the four AppArmor templates (`debian-12`,
`debian-13`, `ubuntu-22.04`, `ubuntu-24.04`), each in the states `standard` and `confined`, and the state `run` (a whole backup
run, below) in `rocky-9` and `ubuntu-24.04`; `fail-fast` is off. Each job
installs QEMU and Lima with `lima-vm/lima-actions/setup` (pinned by commit, Lima `v2.2.1`, the version the scripts were run
with on the Mac), builds one machine from the distribution's own packages and runs `run-template.sh -s <state> <template>`.
It has the workflow's read-only permissions, `timeout-minutes: 45`, and uploads nothing.

**What it checks.** For each state, from the web server and from the command line: whether the plugin's name check and
connection check pass or fail as the access control allows or denies (`dns`, `connection`, the codes behind them), that the
plugin's SELinux and AppArmor rows read the process label, which advice the failing results carry, that the kernel logged a
denial exactly when the network was closed, that the plugin raised no PHP warning (with a positive control for each state),
and that the four AppArmor rules named by the plugin's advice let the plugin connect. 68 results for each SELinux job, 34 for the
`standard` and 104 for the `confined` job, all compared with `expect.txt`.

**The endpoint.** The secret `SECBK_SECUREOS_ENDPOINT` holds the connection URL of a real service (the base URL of its API).
It is not registered yet: a maintainer sets it when the repository is on GitHub. A pull request from a fork gets no secret.
Without it the job runs the stand-in mode and its column of `expect.txt`; with it, the real mode and its column. The log
shows the mode (`template ..., mode standin|real`), the progress, the output of `provision.sh` and `limactl` (checked: it
holds no URL and no detail text), the summary lines and the result of `assert.sh`; no script prints the URL or a detail
text, and GitHub masks the secret in the log as well. The URL is an argument of `check.sh`; `check.sh` writes the case files
with the URL replaced by `<endpoint>`, and nothing prints them. Inside the machine the URL is in the access log of Apache and
in the journal of sudo; the machine is deleted when the job ends, and the runner is not reused. No step uses `set -x` where the URL is in scope. The setup action's own
steps run with `set -x` but do not see the secret: it is in the environment of the last step only.

**The same thing on the Mac.**

    .dev/secureos/run-template.sh -s confined ubuntu-24.04                 # the stand-in, as a fork would run it
    SECBK_SECUREOS_ENDPOINT=<URL> .dev/secureos/run-template.sh -s confined ubuntu-24.04
    .dev/secureos/matrix.sh -j 2 -u <URL>                                # all eight, both modes

**Read-only token.** `lima-vm/lima-actions/setup` verifies the download of Lima with `gh attestation verify --owner=lima-vm`
and the token of the job (`github.token`). Whether the workflow's read-only token (`contents: read`) is enough can only be
proved on GitHub, and is **unknown**. What is known: the call of the attestation API is refused without a token (HTTP 401
when tried on 2026-10-15), and the action's own test workflow (`lima-vm/lima-actions`, `.github/workflows/test.yml`) sets no
`permissions`. If the first run fails in that step with a 403, add `attestations: read` to the permissions of the job.

**Unverified until the first run on GitHub.** Everything was run on aarch64 with Lima's `vz` driver. A GitHub runner is x86_64
and Lima uses QEMU with KVM there, so these may differ:

- whether `/dev/kvm` is available (it is on the standard runners of public repositories; not confirmed for private ones) and
  how long the machines take to boot and to provision (measured on the Mac below; QEMU is likely slower);
- the images: Lima picks the x86_64 cloud image of each template, with other kernel and package versions than the ones in
  the tables below (the complain flags of the `php-fpm` profile, the wording of the kernel's denial lines, the packages of
  EPEL and CRB, and the build of `sodium` from the source package on Rocky Linux 9 and AlmaLinux 9 were only seen on aarch64);
- the network of the runner: the machines reach the DNS over HTTPS services and the service through QEMU's user-mode network;
- whether the read-only token is enough for the attestation check (above).

## Results (2026-10-15)

All twelve combinations match `expect.txt`, with the real service and with the stand-in, in the runs of 2026-10-15 (the
first run; and again with the plugin's AppArmor row and advice, through `run-template.sh`, by `matrix.sh -j 2 -u <URL>`, which
took 17 minutes: 68 results for each SELinux machine and 138 for each AppArmor machine, in each mode). Machines: aarch64 under
Lima 2.2.1. `dns /
connection` is the result of the plugin's two network checks; "denials" means a denied network connection in the kernel log.

**PHP warnings.** The first run counted warnings in logs that Moodle's default `debug` level never fills, so its "none"
proved nothing. `provision.sh` now sets `$CFG->debug` to developer level (not displayed) and sends PHP's errors to
`/var/moodledata/secbk-php-errors.log`; `check.sh` counts the lines that name `/admin/tool/secbackup/` (other lines are
counted in the case file but do not count), and each state has a control case in which `probe.php` raises one warning from a
file of the plugin's directory: `phpwarnings` must be `present` there (`selinux-web-control`, `standard-web-control`,
`confined-web-control`). Measured with this count on all eight templates on 2026-10-15: `none` in every other case, `present` in the
controls. The command-line cases count too, and each state has a control for the command line (`selinux-cli-control`,
`standard-cli-control`, `confined-cli-control`): the control runs `diagnose.php` with `-d auto_prepend_file=` a file that
raises one warning.

`out/` holds the page and command-line output of every case. The connection URL is replaced by `<endpoint>` in it, but the
rest (DNS answers, addresses) is not redacted: `out/` is a local, git-ignored folder and is never uploaded anywhere.

| Template | Moodle | PHP | PostgreSQL | Extra repositories |
|---|---|---|---|---|
| Rocky Linux 9.8 | 4.5.15 | 8.3.33 | 18.6 | EPEL and CRB, for libsodium; `sodium` built from the source package of the distribution's PHP |
| Rocky Linux 10.2 | 5.2.4+ | 8.3.33 | 16.15 | EPEL and CRB, for `php-sodium` |
| AlmaLinux 9.8 | 4.5.15 | 8.3.33 | 18.6 | as Rocky Linux 9 |
| AlmaLinux 10.2 | 5.2.4+ | 8.3.33 | 16.15 | as Rocky Linux 10 |
| Debian 12 | 4.5.15 | 8.2.34 | 15.19 | none |
| Debian 13 | 5.3+ | 8.4.26 | 17.11 | none |
| Ubuntu 22.04.5 | 4.5.15 | 8.1.2 | 14.24 | none |
| Ubuntu 24.04.5 | 5.2.4+ | 8.3.6 | 16.15 | none |

Rocky Linux and AlmaLinux 9 have no `php-sodium` for PHP 8.1 and later (EPEL's package is for PHP 8.0), so the extension is
built from the distribution's own PHP source package. The other extensions come from AppStream.

### Time (aarch64, Lima `vz`, images already downloaded, two machines at once)

Seconds for each step of `run-template.sh -m both` (the CI job runs one mode and one state, so its `check` steps are shorter;
machines of the same family are alike):

| Template | start | provision | copy | check, stand-in | check, real | all |
|---|---|---|---|---|---|---|
| Rocky Linux 9 | 50 | 109 | 7 | 32 | 34 | 237 |
| Rocky Linux 10 | 47 | 61 | 7 | 33 | 36 | 186 |
| AlmaLinux 9 | 44 | 95 | 7 | 32 | 35 | 215 |
| AlmaLinux 10 | 38 | 62 | 7 | 32 | 34 | 177 |
| Debian 12 | 91 | 89 | 5 | 68 | 68 | 328 |
| Debian 13 | 67 | 103 | 11 | 68 | 69 | 325 |
| Ubuntu 22.04 | 45 | 85 | 6 | 72 | 64 | 279 |
| Ubuntu 24.04 | 44 | 83 | 6 | 70 | 62 | 269 |

The first run of Ubuntu 24.04 on this Mac, with no image and no package in a cache, took 933 s: 153 s to start, 713 s to
provision (the packages and the clone of Moodle), 59 s to check. The 45 minutes of the job are about three times that, for the
slower QEMU and a runner whose network is not known (see the unverified list above).

### SELinux (state `selinux`): the four Rocky Linux and AlmaLinux machines behave the same

| Case | Web (`httpd_t`), real service | Web, stand-in | Command line |
|---|---|---|---|
| both booleans off | `dns` fail, `connection` fail (`dns_doh_unavailable`); denials present (`name_connect` to port 443) | the same | `dns` pass, `connection` pass (stand-in: fail), process type `unconfined_t`, no denials |
| `httpd_can_network_relay` on | pass / pass (HTTP 401), no denials | `dns_outside_aws` | the same as above |
| `httpd_can_network_connect` on | pass / pass, no denials | `dns_outside_aws` | the same as above |

`/proc/self/attr/current` and `/sys/fs/selinux/enforce` are readable from `httpd_t` on all four. `httpd_can_network_relay`
alone is enough. The command line (`sudo -u apache php .../cli/diagnose.php`) runs as `unconfined_t`: a passing command-line
diagnosis says nothing about the web server.

### AppArmor, state `standard`

| Template | Web | Command line | Process label | Denials |
|---|---|---|---|---|
| Debian 12, Debian 13, Ubuntu 22.04, Ubuntu 24.04 | pass / pass | pass / pass | `unconfined` (both) | none |

### AppArmor, state `confined` (real service; the stand-in gives the same open or closed answers)

| Template | allow | deny (`deny network inet stream,` and `inet6`) | deny removed | `audit deny` | profile without network rules | + `network inet stream,` + `network inet6 stream,` | + `network inet stream,` + `network inet dgram,` |
|---|---|---|---|---|---|---|---|
| Debian 12 | pass / pass | fail / fail, no log | pass / pass | fail / fail, **logged** | fail / fail, logged | fail / fail (`dns_os_failed`), logged | pass / pass |
| Debian 13 | pass / pass | fail / fail, no log | pass / pass | fail / fail, logged | fail / fail, logged | **pass / pass**, no log | pass / pass |
| Ubuntu 22.04 | pass / pass | fail / fail, no log | pass / pass | fail / fail, logged | fail / fail, logged | fail / fail (`dns_os_failed`), logged | pass / pass, **logged** (IPv6 UDP socket) |
| Ubuntu 24.04 | pass / pass | fail / fail, no log | pass / pass | fail / fail, logged | fail / fail, logged | fail / fail (`dns_os_failed`), logged | pass / pass |

## A whole backup run (state `run`)

    .dev/secureos/run-template.sh -s run rocky-9          # or ubuntu-24.04, or any other template

The state `run` is not part of `all`: instead of the connection diagnostics, `run-check.sh` makes whole backup runs as a site
does that runs the cron of Moodle from its web server. The run is started with the button "Back up now" of the status page and
moved on by requests of `/admin/cron.php`, so it runs in php-fpm: as `httpd_t` under SELinux, under the `php-fpm` profile under
AppArmor. Its connections to the service and to the storage, and its temporary files, are then those of the web server. (A
site whose cron runs from the command line runs as `unconfined_t` or `unconfined`, which the cases of `check.sh` show.)

Before the cases, from the command line (which no access control confines): `config.php` gets developer settings that trust
the fake backup service, the cron is allowed from the web (`cronclionly` off), the time limits of Apache are raised for the long
requests of that cron, the fake service is started as a service of systemd on `127.0.0.1:8099` (it keeps the backup in its own
storage on the same port), and `.dev/fakesec/prepare.php` registers the site, receives the settings and publishes the list of
the official files. All of it is put back at the end. Then two cases, each a new run:

| Case | SELinux (Rocky Linux, AlmaLinux) | AppArmor (Debian, Ubuntu) |
|---|---|---|
| `run-denied` | `httpd_can_network_connect` and `httpd_can_network_relay` off | the `php-fpm` profile in enforce mode, with the local rules of `check.sh` and of the request folder (below) and `audit deny network inet stream,` (and `inet6`) |
| `run-allowed` | `httpd_can_network_connect` on | the same profile with the local rules only |

**The cron of Moodle under the `php-fpm` profile needs one more local rule than the pages.** Each request of the cron makes
a folder in `$CFG->localrequestdir` (by default `/tmp/requestdir`), which no page of `check.sh` does. With only the rules of the
table "A sufficient set of local rules for Moodle" below, the profile denies `mkdir` of `/tmp/requestdir/` (`operation="mkdir"
class="file" ... denied_mask="c"`), Moodle ends every request of the cron with `invaliddatarootpermissions`, and a run that the
button started never moves (seen on Ubuntu 24.04 with Moodle 5.2 in run 39548264878). `run-check.sh` adds
`/tmp/requestdir/ rw,` and `/tmp/requestdir/** rwk,`. The profile also denies `/proc/loadavg` and `/proc/<pid>/mountinfo` to
php-fpm: the watch of the plugin then reads the load as unknown and pauses the run for 30 seconds at most once in five minutes.

Each case logs in with curl, presses the button, requests the cron until the run has ended (or 15 minutes have passed) and
opens the status page again. The checks of its summary (compared with the rows `run-*` of `expect.txt`): `start` (the answer of
the button), `status` and `reason` (the run in the table of the plugin), `page` (the reason whose text and advice the status
page shows, or `completed` for the last completed backup), `mac` (the label of php-fpm), `netdenials` and `phpwarnings` (as in
`check.sh`, for php-fpm). The fake service listens on a port of no special SELinux type, which `httpd_t` may connect to only with
`httpd_can_network_connect`; the real service and its storage are on port 443, where `httpd_can_network_relay` is enough (the
cases of `check.sh` above).

The job `secureos` of `ci.yml` has two rows of this state, `rocky-9` and `ubuntu-24.04` (60 minutes each, of which the step of the
test may take 45); the job `secureos` of the manual workflow `.github/workflows/storage.yml` runs the same two alone, with the
same limits.

### Results of the state `run`

Run 39571659756 of the workflow Storage (2026-10-21, on the plugin of origin/p2 8d8bf01; x86_64 GitHub runners, Lima 2.2.1
with QEMU); all fourteen results of each machine match `expect.txt`. The run 39554215612, on the plugin before 8d8bf01, matched
too (10 s and 40 s, 19 s and 72 s).

| Template | Moodle | `run-denied` | `run-allowed` |
|---|---|---|---|
| Rocky Linux 9 (php-fpm `httpd_t`) | 4.5.15 (Build: 20261005) | stopped after 15 s with `selinux_denied`; the kernel logged the denied connection of php-fpm; the status page shows the text of `abort_selinux_denied` and its advice | completed in 43 s (receipt checked); no denial |
| Ubuntu 24.04 (php-fpm under `php-fpm (enforce)`) | 5.2.4+ (Build: 20261013) | stopped after 22 s with `network_check_failed` (not `selinux_denied`: the plugin judges that reason only while SELinux is enforcing); the denial was logged (`audit deny`); the status page shows the text and advice of `network_check_failed` | completed in 73 s; no denial |

Under AppArmor the run is stopped, as it is under SELinux, but with the reason of a failed network check, whose advice sends the
administrator to the connection diagnostics; the diagnostics there name the AppArmor rules (the cases of `check.sh`). Neither
case raised a PHP warning from the plugin's folder.

## AppArmor facts (the base of the AppArmor check of the plugin)

Observed on 2026-10-15 on aarch64 machines under Lima 2.2.1 (vz driver), with the packages of that day: Debian 12 (kernel
6.1.0-53, apparmor 3.0.8-3), Ubuntu 22.04 (5.15.0-194, 3.0.4-2ubuntu2.5), Ubuntu 24.04 (6.8.0-142, 4.0.1-0ubuntu0.24.04.9);
Debian 13 (13.7, kernel 6.12.111+deb13-cloud-arm64, apparmor and apparmor-profiles 4.1.0-1). Not verified on x86_64: the log wording, the complain flags
shipped in a profile and the list of rules may differ with another kernel or package version. IPv6 is untested too: the
network of the machines has no IPv6 route.

**The profile.** `apparmor-profiles` ships `/etc/apparmor.d/php-fpm`, attached to `/usr/sbin/php-fpm*` (Debian 13:
`/usr/{bin,sbin}/php-fpm*`). Debian 12, Debian 13 and Ubuntu 22.04 ship it with `flags=(complain,...)`; Ubuntu 24.04 ships
it enforcing. The tests put it in enforce mode with `aa-enforce php-fpm` and restart php-fpm (except in the case `nonet-web-advice`, which only loads the profile again, as the plugin's advice says). php-fpm and all its workers,
that is all of Moodle, run under the profile `php-fpm`. Nothing is confined without the package: in the standard state php-fpm
is `unconfined`.

**What PHP can read.** Standard: `/proc/self/attr/current` and `/proc/self/attr/apparmor/current` contain `unconfined`,
`/sys/module/apparmor/parameters/enabled` contains `Y`. Confined: both attr files contain `php-fpm (enforce)` and are
readable; `/sys/module/apparmor/parameters/enabled` is **not** readable (denied). The profile also denies
`/proc/<pid>/mountinfo`, so the plugin's data directory check shows "Cannot be read" under the profile.

**A sufficient set of local rules for Moodle** (`/etc/apparmor.d/local/php-fpm`; `<root>` is the Moodle directory, the data
directory is `/var/moodledata`). Found from the `DENIED` lines, so sufficient; that no rule can be left out was tried only for the data directory itself. Without them the page is 403 or 503/500:

| Template | Rules |
|---|---|
| Debian 12, Debian 13 | `<root>/** r,` and `/var/moodledata/** rwk,` |
| Ubuntu 22.04, Ubuntu 24.04 | the same, and `/run/systemd/notify w,` (without it php-fpm cannot tell systemd that it has started, and the service fails with a start timeout) and `/run/postgresql/.s.PGSQL.* rw,` (the database socket) |

A directory rule for the data directory itself (`/var/moodledata/ r,`) is not needed. On Ubuntu 22.04, `/usr/share/zoneinfo-icu/**
r,` is denied too, but Moodle works without it (intl falls back to its own data).

**Network.** The stock profile includes `abstractions/nameservice`, which allows `network inet stream`, `inet6 stream`,
`inet dgram`, `inet6 dgram`: the plugin connects over HTTPS and looks names up without any change. A `deny network inet
stream,` rule in the local file makes the plugin report `dns_doh_unavailable` ("Neither DNS over HTTPS service answered") for
the name check and the connection. A plain `deny` rule **logs nothing**; `audit deny` (or a profile that simply has no
allowing rule) does:

    apparmor="DENIED" operation="create" profile="php-fpm" family="inet" sock_type="stream" protocol=6 requested_mask="create" denied_mask="create"      (Debian 12, Ubuntu 22.04)
    apparmor="DENIED" operation="create" class="net" profile="php-fpm" family="inet" sock_type="stream" ...                                         (Debian 13)
    apparmor="DENIED" operation="create" class="net" info="failed type match" error=-13 profile="php-fpm" family="inet" sock_type="stream" ...     (Ubuntu 24.04)

On the Ubuntu machines a first run that read only the kernel ring buffer (`journalctl -k`) found none of these messages,
while the whole journal had them; so use `journalctl --since "10 min ago" | grep 'apparmor="DENIED"'` (or `dmesg`). The kernel drops these messages when many come
within seconds (`kernel.printk_ratelimit`), so a test that counts them sets it to 0.

**The narrowest network rule that lets the plugin connect** when a profile has no network permission (a test-only copy of the
profile whose name-service abstraction has no `network inet` rules; its file rules, such as `resolv.conf` and the CA
bundle, are kept, so the result holds for a profile that already has those):

    network inet stream,      # HTTPS to the DNS over HTTPS services and to the service
    network inet dgram,       # the name lookup of the operating system (not needed on Debian 13)

Both for IPv4. The advice of the plugin names four rules, the two above and their IPv6 forms (`network inet6 stream,` and
`network inet6 dgram,`), because a server with an IPv6 route connects over it (CloudFront answers AAAA too). What was
verified, on all four AppArmor templates (the case `nonet-web-advice`): the four rules, taken from the plugin's English text,
are loaded by `apparmor_parser -r` into the kernel, and with them the plugin connects over IPv4 with nothing denied and
nothing logged, after a reload of the profile alone (`apparmor_parser -r`; php-fpm is not restarted). What was verified **only by the parser** (on Ubuntu 24.04, `apparmor_parser -QT` accepts the four rules, also on one line; it rejects
`network bogus stream,`, so it does check): that the IPv6 rules are valid syntax. Not verified by any connection: that a
connection over IPv6 passes, because the network of the machines has no IPv6 route. Ubuntu 22.04 tries an IPv6 UDP socket during the lookup; the lookup still works, but the attempt is
logged. The stream-only test carried `inet stream` and `inet6 stream` together, so "Debian 13 needs no UDP rule" is shown for
that pair, not for IPv4 stream alone; with only the stream rules the name check fails with `dns_os_failed` on the other three. An administrator loads a changed rule with

    sudo apparmor_parser -r /etc/apparmor.d/php-fpm

(or `sudo systemctl reload apparmor`); php-fpm needs a restart only when it started under another profile or under none.

**The command line.** `sudo -u www-data php .../cli/diagnose.php` runs `unconfined`, also while the php-fpm profile is in
enforce mode: the profile attaches to the `php-fpm` executable only. The same settings connect from the command line and
not from a page.

## Files

| File | What it does |
|---|---|
| `provision.sh` | in the VM: web server, php-fpm, PostgreSQL and Moodle from the distribution; writes `/etc/secbk-secureos-info` |
| `sync-plugin.sh` | copies the repository into Moodle (either layout), fixes owner and labels, upgrades Moodle |
| `probe.php` | in the VM: runs the diagnostics inside the web server and prints them, and what the process can read of the access control; refuses to run without `/etc/secbk-secureos-probe` |
| `apparmor-profile.sh` | in the VM: installs, changes and removes the `php-fpm` profile for `check.sh` |
| `check.sh`, `assert.sh`, `expect.txt` | the states, the summary, and what it must say |
| `control-warning.php` | raises one PHP warning from the plugin's directory, for the positive control of the warning count |
| `run-template.sh` | one template from the creation of the machine to its deletion; used by `matrix.sh` and by the CI job |
| `run-check.sh` | the state `run`: whole backup runs started with the button and moved on by the cron from the web server |
| `matrix.sh`, `lib.sh` | the eight templates in turn; shared functions (`vm`, `start_vm`, `free_port`, `vm_exists`) |

A machine sometimes does not come up in time (its image boots slowly while another VM starts): `matrix.sh` tries a failed
start once more with a new machine. Some images start with SELinux permissive (the VM manager turns it off for a while):
`provision.sh` and `check.sh` set it to enforcing and stop if that fails.
