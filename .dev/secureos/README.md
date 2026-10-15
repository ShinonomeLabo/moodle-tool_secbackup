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
(`sodium`; see the table of versions). Moodle is 4.5 on the older version of each pair, and on the newer one the newest
5.x stable branch whose `environment.xml` accepts the PHP and PostgreSQL of the distribution (so 5.1 and later, which serve
from `public/`, are covered too).

Development only. Nothing in this directory is part of the plugin package (`.dev` is `export-ignore`d).

## One machine

On the Mac, in the repository (Lima 2.x):

    export SECBK_VM=secbk-debian-12
    limactl create --tty=false --name=$SECBK_VM --cpus=2 --memory=4 --disk=20 --mount-none \
        --set '.portForwards=[{"guestPort":80,"hostPort":18080,"hostIP":"127.0.0.1"}]' template:debian-12
    limactl start --tty=false $SECBK_VM
    limactl shell $SECBK_VM sudo bash -s -- 18080 < .dev/secureos/provision.sh
    .dev/secureos/sync-plugin.sh
    .dev/secureos/check.sh all                       # stand-in connection URL
    .dev/secureos/check.sh all <connection URL>      # a real service: unsigned GET of its status path, nothing changes
    .dev/secureos/assert.sh standin .dev/secureos/out/$SECBK_VM/standin/summary.txt
    limactl delete -f $SECBK_VM

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
| `dnsproblem`, `connectionerror` | the error code behind them (`dns_doh_unavailable`, `dns_outside_aws`, `dns_os_failed`, `http_401`, `none`); read by `probe.php` |
| `plugin-mac` | result of the plugin's own SELinux check (`unknown` on AppArmor machines until the plugin has an AppArmor check) |
| `mac` | label of the process: SELinux type (`httpd_t`) or AppArmor profile (`php-fpm_(enforce)`, `unconfined`) |
| `netdenials` | `present` when the kernel logged a denied network connection during the case |
| `phpwarnings` | `present` when the web server's or php-fpm's error log, or the page, got a PHP warning |

`expect.txt` holds what each result must be, for both kinds of connection URL; `assert.sh` fails when one differs. A row
named `<case>@<template>` holds for one template only.

### The stand-in URL

Without a URL, `check.sh` uses a stand-in on a reserved name. Its address is not in the AWS ranges, so where the network
is open the name check ends with `dns_outside_aws`, and where it is closed with `dns_doh_unavailable`. That tells open from
closed without the real service (CI on a fork has no secret; CI will take the real URL from `SECBK_SECUREOS_ENDPOINT`). The
stand-in is refused as soon as the answers of the DNS over HTTPS services are outside the AWS ranges, so it cannot show
whether the name lookup of the operating system (UDP) works; the real URL shows that (`dns_os_failed`).

## The matrix

    .dev/secureos/matrix.sh [-j 2] [-u <connection URL>] [-k] [template...]

For each template: create the machine (2 CPUs, 4 GiB, 20 GiB, port forward to `127.0.0.1` on a port of its own),
provision, copy the plugin, run `check.sh` with the stand-in and with the URL, compare with `expect.txt`, delete the
machine. `-j 2` runs two at once (never more: the disk is the limit), `-k` keeps the machines. The logs and the status
(`ok`, `mismatch`, `failed`) are in `out/matrix/`. The eight templates took 26 minutes with `-j 2`.

## Results (2026-10-15)

All twelve combinations match `expect.txt`, with the real service and with the stand-in (36 results for each SELinux
combination, 72 for each AppArmor one). Machines: aarch64 under Lima 2.2.1. `dns / connection` is the result of the
plugin's two network checks; "denials" means a denied network connection in the kernel log; PHP warnings: none anywhere.

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

## AppArmor facts (the base of the AppArmor check of the plugin)

**The profile.** `apparmor-profiles` ships `/etc/apparmor.d/php-fpm`, attached to `/usr/sbin/php-fpm*` (Debian 13:
`/usr/{bin,sbin}/php-fpm*`). Debian 12, Debian 13 and Ubuntu 22.04 ship it with `flags=(complain,...)`; Ubuntu 24.04 ships
it enforcing. The tests put it in enforce mode with `aa-enforce php-fpm` and restart php-fpm. php-fpm and all its workers,
that is all of Moodle, run under the profile `php-fpm`. Nothing is confined without the package: in the standard state php-fpm
is `unconfined`.

**What PHP can read.** Standard: `/proc/self/attr/current` and `/proc/self/attr/apparmor/current` contain `unconfined`,
`/sys/module/apparmor/parameters/enabled` contains `Y`. Confined: both attr files contain `php-fpm (enforce)` and are
readable; `/sys/module/apparmor/parameters/enabled` is **not** readable (denied). The profile also denies
`/proc/<pid>/mountinfo`, so the plugin's data directory check shows "Cannot be read" under the profile.

**The smallest local rules Moodle needs** (`/etc/apparmor.d/local/php-fpm`; `<root>` is the Moodle directory, the data
directory is `/var/moodledata`). Found from the `DENIED` lines; without them the page is 403 or 503/500:

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

**The narrowest rule that lets the plugin connect** when a profile has no network permission (a test-only copy of the profile
whose name-service abstraction has no `network inet` rules):

    network inet stream,      # HTTPS to the DNS over HTTPS services and to the service
    network inet dgram,       # the name lookup of the operating system (not needed on Debian 13)

Both for IPv4; add `network inet6 stream,` and `network inet6 dgram,` for a server whose resolver or route is IPv6 (Ubuntu
22.04 tries an IPv6 UDP socket during the lookup; the lookup still works, but the attempt is logged). With the stream rules
only, the name check fails with `dns_os_failed` on three of the four. An administrator loads a changed rule with

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
| `matrix.sh`, `lib.sh` | the eight templates in turn; shared functions |

A machine sometimes does not come up in time (its image boots slowly while another VM starts): `matrix.sh` tries a failed
start once more with a new machine. Some images start with SELinux permissive (the VM manager turns it off for a while):
`provision.sh` and `check.sh` set it to enforcing and stop if that fails.
