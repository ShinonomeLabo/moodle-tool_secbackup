# Shinonome LMS Backup (tool_secbackup)

[![CI](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml/badge.svg?branch=main&event=push)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)
[![Coverage](https://shinonomelabo.github.io/moodle-tool_secbackup/coverage.svg)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)

Shinonome LMS Backup is an admin tool plugin for Moodle™ that connects a site to the
Shinonome LMS Backup service (あんしんLMSバックアップ).

This repository is under development and is not ready for production use.

## What this version does

Version 0.1.0 registers the site with the service (the connection URL, the site's public key and a one-time registration
code), asks the service for the status of the site every five minutes, and checks the connection from the server, on a page
and on the command line. Sending backups to the service and restoring them come in later versions.

This version contains no embedded key of the service yet, so it can be registered only on a site whose `config.php` names
the service's key (the `trustedkeys` of the `TOOL_SECBACKUP_DEVELOPER` setting, see `.dev/env/README.md`). The key comes with
the release that accompanies the launch of the service.

## Requirements

- Moodle™ 4.5, 5.0, 5.1, 5.2 or 5.3
- PHP 8.1 to 8.4 (as supported by the Moodle version) with the sodium extension
- MySQL, MariaDB or PostgreSQL
- A contract for the Shinonome LMS Backup service

## Installation

Place the plugin in `admin/tool/secbackup` (Moodle 4.5 and 5.0) or `public/admin/tool/secbackup`
(Moodle 5.1 and later), then visit Site administration > Notifications.

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
Version 0.1.0 does not send backups yet.

## Security

The keys and the registration code are kept out of the stack traces of exceptions: on PHP 8.2 and later by
`#[\SensitiveParameter]`, on PHP 8.1 only with `zend.exception_ignore_args = On`, which is PHP's production setting.

## License

GNU GPL v3 or later. See `LICENSE`.

## Trademark

The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with, endorsed by or sponsored by Moodle Pty Ltd or its related affiliates.
