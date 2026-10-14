# Shinonome LMS Backup (tool_secbackup)

[![CI](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml/badge.svg?branch=main&event=push)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)
[![Coverage](https://shinonomelabo.github.io/moodle-tool_secbackup/coverage.svg)](https://github.com/ShinonomeLabo/moodle-tool_secbackup/actions/workflows/ci.yml?query=branch%3Amain)

Shinonome LMS Backup is an admin tool plugin for Moodle™ that connects a site to the
Shinonome LMS Backup service (あんしんLMSバックアップ). It sends encrypted backups of the
site to the service and restores them.

This repository is under development and is not ready for production use.

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

## Privacy

The plugin sends backups of the whole site, including the personal data of all users,
to the Shinonome LMS Backup service. Backups are encrypted on the site before they are sent.

## License

GNU GPL v3 or later. See `LICENSE`.

## Trademark

The word Moodle and associated Moodle logos are trademarks or registered trademarks of
Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with,
endorsed by or sponsored by Moodle HQ.
