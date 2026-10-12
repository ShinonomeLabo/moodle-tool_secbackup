# Shinonome LMS Backup (tool_secbackup)

Shinonome LMS Backup is an admin tool plugin for Moodle™ that connects a site to the
Shinonome LMS Backup service (あんしんLMSバックアップ). It sends encrypted backups of the
site to the service and restores them.

This repository is under development and is not ready for production use.

## Requirements

- Moodle™ 4.5, 5.1, 5.2 or 5.3
- PHP 8.1 to 8.4 (as supported by the Moodle version) with the sodium extension
- MySQL, MariaDB or PostgreSQL
- A contract for the Shinonome LMS Backup service

## Installation

Place the plugin in `admin/tool/secbackup` (Moodle 4.5) or `public/admin/tool/secbackup`
(Moodle 5.1 and later), then visit Site administration > Notifications.

## Privacy

The plugin sends backups of the whole site, including the personal data of all users,
to the Shinonome LMS Backup service. Backups are encrypted on the site before they are sent.

## License

GNU GPL v3 or later. See `LICENSE`.

## Trademark

The word Moodle and associated Moodle logos are trademarks or registered trademarks of
Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with,
endorsed by or sponsored by Moodle HQ.
