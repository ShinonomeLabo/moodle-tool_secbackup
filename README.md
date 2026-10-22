# Shinonome LMS Backup (tool_secbackup)

[![CI](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml/badge.svg?branch=main&event=push)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)
[![Coverage](https://shinonomelabo.github.io/moodle-tool_secbackup/coverage.svg)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)

Shinonome LMS Backup is an admin tool plugin for Moodle™ that connects a site to the
Shinonome LMS Backup service (あんしんLMSバックアップ).

This repository is under development and is not ready for production use.

## What this version does

Version 0.2.0 sends full backups of the site to the backup service. Version 0.1.0 brought the registration with the service
(the connection URL, the site's public key and a one-time registration code), the status request every five minutes and the
connection diagnostics, on a page and on the command line. Restoring a backup comes in a later version.

- **When.** The service sends the settings of the backup: a daily window (in a time zone), the number of backups a day, the
  upload speed limit and whether the logs are included. The plugin starts a backup in the window, a little after its start
  (a delay of its own for each site, at most a quarter of the window and at most 30 minutes, so that the sites of one window
  do not all start at once). The button "Back up now" of the status page and `cli/backup.php --now` start one at once, in or
  out of the window. A backup that the cron runs pauses at the end of its window and goes on in the next one when that one
  starts early enough for the backup to finish within the 24 hours that the service allows a backup (with several windows a
  day). Otherwise (with one window a day, the next one starts about 24 hours later) it is stopped at the end of its window,
  and the next window starts a new backup, which does not send again the files of the file directory that the storage has.
  A backup that is not finished at the end of the second window is stopped too. The window limits only the making and
  sending of the chunks: a backup that has sent everything waits for the receipt of the service past the end of its window.
  `cli/backup.php` does not pause (see "Command line" for large sites).
- **Before a start**, the plugin checks that the site is registered and has received the settings, that no backup runs,
  that Moodle's automated course backup is not working, that the clock of the server is within 5 minutes of the service's,
  that a backup would have 256 MB of memory or more, and that the server is within the limits of the page "Limits of the
  backup". While a backup runs, it waits while the server is above those limits, and stops when they hold too long.
- **What a backup holds.** Every table of the database except `sessions` and `cache_flags` (the log tables
  `logstore_standard_log` and `log` only when the settings of the service include the logs); every file of the file
  directory; the rest of the data directory without `cache`, `filedir`, `localcache`, `lock`, `sessions`, `temp`, `trashdir`
  and the temporary and cache folders that `config.php` places inside it (with `$CFG->secretdataroot` when it is outside);
  the code of the plugins that are not part of Moodle and the files of Moodle's code that differ from the official release;
  and a list of the generation, which names every part. Symbolic links are not followed; the list names them.
- **How it is sent.** The plugin cuts the backup into chunks of up to about 32 MiB and encrypts each chunk on the site before
  it leaves the server: a random key for each chunk, sealed for the site's public key (X25519, the key registered on the
  platform), and the contents encrypted with XChaCha20-Poly1305. The plugin never holds the secret key that opens the
  chunks, so neither the server of the site nor the storage can read a backup; the service keeps that key as its
  key-custody options describe, and can use it to read a backup (to restore it, for example). The chunks are padded, so
  their sizes tell little about their contents. They go straight to the storage of the service (Amazon S3), through URLs
  that the service signs for each chunk; the service checks that every chunk arrived before it gives a receipt. Before a
  chunk is sent, the URL must name the S3 storage of the service's region, its address must be one of Amazon S3 in that
  region, the connection is pinned to that address, and only the bundled root certificates are trusted.
- **What the service receives unencrypted.** The registration sends the address of the site, the release and version of
  Moodle, the database family and the version of the plugin. The requests of a backup and the index of the chunks carry the
  names of the chunks (keyed hashes that reveal nothing of the contents), their sizes and SHA-256 sums, the expected size of
  a backup and the code of the reason when a backup stops. At the end of a backup, the plugin reports counts without personal
  data: the number of users active in the last 365 days and the number of courses, the enabled authentication methods, the
  number of rows of each table, the bytes and the duration of the backup, the release, version and build of Moodle, the
  database family, and whether Moodle's code may have been modified and whether the files are in an external storage.
- **When something goes wrong**, the status page shows why the backup stopped and what to do. When a file of the site
  stopped it, it names where the file is in the data directory and the component and file area of the site's file, not its
  name, which can be personal data. When backups stop three times in a row, the site administrators get a message (the message
  provider "Backups that were stopped several times in a row"). After a backup, the status page and `cli/backup.php` say how
  many files it does not hold (missing from the file store, not readable, symbolic links), and warn when the secret folder
  of the site, which holds the keys that Moodle encrypts some settings with, could not be read. A file that is not found is
  looked for again after 1, 5 and 15 seconds before it counts as missing, unless it is missing for certain.

This version contains no embedded key of the service yet, so it can be registered only on a site whose `config.php` names
the service's key (the `trustedkeys` of the `TOOL_SECBACKUP_DEVELOPER` setting, see `.dev/env/README.md`). The key comes with
the release that accompanies the launch of the service.

## Requirements

- Moodle™ 4.5, 5.0, 5.1, 5.2 or 5.3
- PHP 8.1 to 8.4 (as supported by the Moodle version) with the sodium extension
- MySQL, MariaDB or PostgreSQL
- A Linux server. The tests run on Linux only (and the access control tests in Linux virtual machines); Windows is neither
  supported nor tested.
- The cron of Moodle running every minute: the status request is a scheduled task, and a backup goes on only while the cron
  runs (as an ad hoc task).
- A memory limit of 256 MB or more for the backup: PHP's `memory_limit`, or Moodle's extra memory limit
  (`$CFG->extramemorylimit`, 512 MB by default), which Moodle raises the limit to for the cron.
- Free space for the temporary files of a backup: by default the smaller of 10% of the free space and 2,048 MB, in the
  folder `tool_secbackup` of Moodle's temporary folder (both can be changed on the page "Limits of the backup"). Moodle's
  cleanup of its temporary folder removes files that have not changed for some hours (the setting "Clean up temporary data
  files older than"); the plugin keeps the files of a running backup fresh at every status request (every five minutes) and
  every step, so even the shortest setting does not remove them while the cron runs.
- Outgoing HTTPS from the server to the service and to its storage (see the connection diagnostics).
- A contract for the Shinonome LMS Backup service

## Installation

Place the plugin in `admin/tool/secbackup` (Moodle 4.5 and 5.0) or `public/admin/tool/secbackup`
(Moodle 5.1 and later), then visit Site administration > Notifications.

## Settings

Site administration > Plugins > Admin tools > Shinonome LMS Backup has four pages:

- **Connection settings**: the registration. Paste the connection URL and the public key of the site from the platform
  screen, check the fingerprint of the key, and enter the one-time registration code. The same page registers the site again
  and removes the registration.
- **Status**: the registration and the last status request, the running backup and its stage, the last completed backup,
  why the latest backup stopped and what to do, and the button "Back up now". Each time the page is opened, it shows a
  warning when the files of the site are in an external storage (see below), and when the last backup found that Moodle's
  code may differ from the official release. Scheduled backups do not wait for anyone to see these warnings.
- **Connection diagnostics**: the name resolution, the proxy and the connection to the service, SELinux and AppArmor, and the
  state of the server that a backup depends on (PHP extensions, load, memory, database response, data directory, temporary
  folder, clock, Moodle's automated backup), with advice where a check fails.
- **Limits of the backup**: the highest server load per CPU core (2), the least available memory (256 MB), the slowest
  database response (500 ms), the slowest file storage read speed (5 MB per second), the budget of the temporary files (0: the
  default above), an upload speed limit of this site (0: none; the service's limit applies as well) and the temporary folder.
  The defaults suit most sites. A backup leaves its temporary folder out, so the page refuses the data directory itself and a
  folder inside it that already holds files (a new or empty one is accepted), and it refuses a change of the folder while a
  backup runs.

The schedule (the window, the number of backups a day, the logs) is set on the platform screen and comes with the status
request.

## Command line

Run the scripts as the user that runs the cron (for example `sudo -u www-data /usr/bin/php ...`); from Moodle 5.1 on they
are in `public/admin/tool/secbackup/cli/`.

    php admin/tool/secbackup/cli/backup.php [--now] [--quiet]
    php admin/tool/secbackup/cli/diagnose.php [--endpoint=URL]

`backup.php` goes on with the running backup until it ends, also past the end of the window; with `--now` it starts one when
none is running, in or out of the window. A step that fails is tried again after 5 minutes. Exit codes: 0 the backup is
complete, 1 it was stopped or the service did not start it, 2 no backup was run here (none is running, or its temporary
files are on another server), 3 the command failed and the backup goes on (with the cron, or when the command is run
again). The cron runs backups without it.

A large site whose backup does not finish within its window can run `backup.php` from the cron of the system, as the user
that runs Moodle's cron, once a day 35 minutes after the start of the window (the schedule starts the backup at most 30
minutes after the start). Without `--now` it takes over the backup that the schedule started and goes on with it until it
ends, past the end of the window and in steps of an hour instead of ten minutes; when none was started, it does nothing.
For example, for a window from 02:00 in the time zone of the server:

    35 2 * * * www-data /usr/bin/php /var/www/moodle/admin/tool/secbackup/cli/backup.php --quiet

`diagnose.php` runs the connection diagnostics; `--endpoint` checks a connection URL before the site is registered. Both
print their help with `--help`.

## Servers and storage

- **A data directory on NFS** works: the plugin reads the files through it, and the connection diagnostics name the file
  system with a warning. While a backup runs, the plugin measures the speed of its own reads of the files of 1 MiB or more
  (before the first one, it reads a part of the largest file), and stops the backup when it is below the limit of the page
  "Limits of the backup". Tested with NFS v3 and v4.2 (`.dev/nfs/README.md`).
- **Files in an external storage** (for example `tool_objectfs` with S3): the backup reads the files through Moodle's file
  API, so a backup reads every file from the external storage, which may cost money there. The status page shows a warning
  about it each time it is opened; scheduled backups do not wait for it. Tested with `tool_objectfs` and an S3-compatible
  storage (`.dev/objectfs/README.md`).
- **SELinux and AppArmor** confine the processes of the web server, not the command line. A backup started with the button
  and moved on by a cron that runs in the web server (`admin/cron.php` requested over HTTP) is subject to them; a backup run
  by `cli/backup.php` or by a cron on the command line usually is not. Under SELinux in enforcing mode, the web server needs
  the boolean `httpd_can_network_relay` (or `httpd_can_network_connect`) to reach the service and its storage on port 443,
  a temporary folder on another disk needs the label `httpd_sys_rw_content_t` (`semanage fcontext` and `restorecon`), and a
  data directory on NFS needs `httpd_use_nfs`. A backup stops with the reason that names SELinux only when the web server, in
  its confined domain, was denied a connection or the temporary folder (a timeout, a refused connection or a full disk keep
  their own reasons); the status page and the connection diagnostics give the commands.
  Under AppArmor, a profile of the web server that denies the network stops the backup as a failed network check; the
  diagnostics show the AppArmor rules. The connection diagnostics were tested in virtual machines with Rocky Linux,
  AlmaLinux, Debian and Ubuntu, and whole backups from the web server with Rocky Linux 9 and Ubuntu 24.04
  (`.dev/secureos/README.md`).
- **Several web servers** share Moodle's temporary folder, which holds the temporary files of a backup by default, so any of
  them goes on with a running backup. When "Temporary folder" on the page "Limits of the backup" names a folder of one server,
  only that server goes on with a backup that it started, and `cli/backup.php` names that server when it is run on another
  one. When that server does not come back (replaced or renamed), any server stops the backup 24 hours and 10 minutes after
  its start, so that the next backups can start; its temporary files stay on the server that started it.

## Tested combinations

Every change runs the plugin's checks, PHPUnit tests and Behat features on these combinations
(the workflow `.github/workflows/ci.yml` is the only definition):

| Moodle | PHP | Databases |
|---|---|---|
| 4.5 | 8.3 | PostgreSQL 13, MariaDB 10.6.7, MySQL 8.0 |
| 4.5 | 8.1 | PostgreSQL 13 |
| 5.0 | 8.4 | PostgreSQL 14, MariaDB 10.11 |
| 5.1 | 8.4 | PostgreSQL 15, MariaDB 10.11, MySQL 8.4 |
| 5.1 | 8.2 | PostgreSQL 15 |
| 5.2 | 8.4 | PostgreSQL 16, MariaDB 10.11, MySQL 8.4 |
| 5.2 | 8.3 | PostgreSQL 16 |
| 5.3 | 8.4 | PostgreSQL 17, MariaDB 11.4, MySQL 8.4 |
| 5.3 | 8.3 | PostgreSQL 17 |

Behat (every page and every operation of the plugin, see `tests/behat/inventory.txt`) also runs in Chrome on every
combination above, and in Firefox, Edge and Safari on every Moodle version with PostgreSQL and MariaDB (the newest PHP of
the version; Safari on a macOS runner, with the databases of Homebrew).

A manual run of the workflow can choose the scope `phpunit`: PHPUnit and the code checks on every combination above,
without Behat, the secure OS machines and the coverage, for a quicker answer while a change is made. A change is ready only
after a run with the scope `all`, which every push to `main` and every pull request have.

The access control that the operating system ships is tested in real virtual machines (the job `Secure OS ...`): SELinux
on Rocky Linux 9 and 10 and AlmaLinux 9 and 10, and AppArmor on Debian 12 and 13 and Ubuntu 22.04 and 24.04 (as shipped, and
with the `php-fpm` profile of the distribution in enforce mode). Each job runs the plugin's connection diagnostics from the
web server and from the command line, and checks that the diagnostics tell a closed network from an open one and name the
right advice. The job reads the connection URL of a real service from the secret `SECBK_SECUREOS_ENDPOINT`; where that is
not set (a fork) it checks against a stand-in. `.dev/secureos/README.md` has the details and how to run the same thing on
your machine.

## Privacy

The plugin sends backups of the whole site, including the personal data of all users,
to the Shinonome LMS Backup service. Backups are encrypted on the site before they are sent.

## Security

The keys and the registration code are kept out of the stack traces of exceptions: on PHP 8.2 and later by
`#[\SensitiveParameter]`, on PHP 8.1 only with `zend.exception_ignore_args = On`, which is PHP's production setting.

## License

GNU GPL v3 or later. See `LICENSE`.

## Trademark

The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with, endorsed by or sponsored by Moodle Pty Ltd or its related affiliates.
