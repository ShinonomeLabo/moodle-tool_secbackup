# Changes

## 0.2.0

- Full backups of the whole site to the backup service: every table of the database except the sessions and the cache
  flags (the log tables only when the service's settings include them), the file directory, the rest of the data directory,
  the plugins that are not part of Moodle and the files of Moodle's code that differ from the official release, and a list
  of each generation that names every part.
- Every chunk is encrypted on the site before it is sent: a random key for each chunk, sealed for the site's X25519 public
  key, and XChaCha20-Poly1305 for the contents, with padding that hides the exact size. The plugin holds only the public key.
  The chunks go straight to the storage of the service through URLs that the service signs; the address of the storage is
  checked and pinned as for the requests to the service. A backup is complete when the service has checked every chunk and
  returned a signed receipt.
- Backups start in the window that the service sets, a little after its start (a delay of its own for each site), or at once
  with the button "Back up now" of the status page or with `cli/backup.php --now`. A backup that the cron runs pauses at the
  end of its window and goes on in the next one when that one starts early enough for it to finish within 24 hours;
  otherwise (one window a day) it is stopped at the end of its window, and one that two windows did not finish is stopped.
  A failed step is tried again, and an upload three times, before the backup is stopped.
- Before a start and while a backup runs, the plugin checks the server against the limits of the new page "Limits of the
  backup" (load per CPU core, free memory, database response, read speed of the file storage, free space for temporary
  files, upload speed) and waits while they are exceeded. A backup does not start while Moodle's automated course backup is
  working, when the clock is 5 minutes or more off, or with less than 256 MB of memory.
- The report of a backup tells the service whether Moodle's code may have been modified: when it differs from the official
  release, and also when no official list could be compared with it (the whole code was then sent).
- The status page shows the running backup, the last completed backup and why the latest backup stopped, with advice. The
  site administrators get a message when backups are stopped three times in a row. Before a backup, a dialog warns when the
  files of the site are in an external storage (such as tool_objectfs) or when Moodle's code may have been modified.
- The connection diagnostics also check the server load, the free memory, the database response, the temporary folder, the
  clock and Moodle's automated backup.
- Tested also with a data directory on NFS (v3 and v4.2), with files in an S3-compatible storage through tool_objectfs, and
  with whole backups under SELinux and AppArmor.

## 0.1.0

- Registration with the backup service in two steps: the connection URL and the public key of the site,
  then a check of the key's fingerprint and a one-time registration code. Registration again with a new
  code, and removal of the registration (confirmed by typing the site name).
- Only answers signed by a trusted key of the service are accepted, and an enrolment answer must belong to
  the request (nonce, connection URL, both key fingerprints). Requests are signed with the site's Ed25519 key.
- Before anything is sent: the host name must lead to Amazon CloudFront by DNS over HTTPS and by this server's
  DNS, the connection is pinned to the checked address, and only the bundled Amazon root certificates are trusted.
- Status request every five minutes, a status page, and connection diagnostics on a page and on the command line.
  The diagnostics also show SELinux and AppArmor, and tell an administrator how to let the web server connect
  when one of them blocks it.
- Messages to the site administrators and log events when the site is registered, registered again or removed.
