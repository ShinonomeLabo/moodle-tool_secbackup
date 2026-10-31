# Changes

## 0.5.0

- The plugin's part of restoring a backup to a temporary site: `cli/restore.php`, which the restore host of the backup
  service runs on a server of its own, never on the backed-up site. It talks to the restore host over its standard input and
  output, one JSON line at a time, and its failures end with one line that holds a code, never a key of the site, a
  password, a path or a value of a row.
- `cli/restore.php --extract` makes a one-time key pair and receives the keys of the site sealed for it; the keys stay in the
  memory of the command and are not written anywhere. It checks the size, the SHA-256 and the signature of the site on the
  list of the generation, decrypts each chunk with the newest key that opens it, and checks every file and every block of
  the database against the list. It writes Moodle's code, the plugins that are not part of Moodle (each to the folder that
  the restored code gives its type), the data directory and the file store where the restored Moodle reads them, and keeps
  the blocks of the database for the import. A path of the list that is not a plain relative path is refused, a symbolic
  link on the way is not followed, and a file takes its name only when its size and hash are the ones of the list.
  A generation that holds only the files of Moodle's code that differ from the official release is refused for now
  (`restore_core_official_unsupported`): the restore does not fetch the official files yet.
- `cli/restore.php --import` runs in the Moodle that the restore host installed: it puts the rows of the backup in place of
  the rows of the install, each with its id, and sets the sequences after them. It can run again on the same extraction.
  The state of this plugin is not restored: its tables, its settings and its tasks stay those of the new install. The
  settings that `config.php` of the backed-up site forced are written to the database, except those that describe its
  server (paths, the database, the sessions, mail, proxies).
- The import makes the restored site a temporary site: no mail is ever sent, the notifications show on the page only, the
  scheduled tasks of the plugins that are not part of Moodle and the core tasks that talk to other systems are disabled,
  their ad hoc tasks are deleted, and the alternative login page is removed. Each of these changes is recorded with the
  value it replaced in the new table `tool_secbackup_restore_stops`, which holds no personal data. It makes the administrator
  `secbackup_admin`, with the password that the restore host gives, so that the site can be looked at.
- The Japanese texts of the status page and of the errors of a chunk use plainer words for the parts that a backup is sent
  in.

## 0.2.0

- Full backups of the whole site to the backup service: every table of the database except the sessions and the cache
  flags (the log tables only when the service's settings include them), the file directory, the rest of the data directory,
  the plugins that are not part of Moodle and the files of Moodle's code that differ from the official release, and a list
  of each generation that names every part.
- Every chunk is encrypted on the site before it is sent: a random key for each chunk, sealed for the site's X25519 public
  key, and XChaCha20-Poly1305 for the contents, with padding that hides the exact size. The plugin never holds the secret
  key; the service keeps it as its key-custody options describe.
  The chunks go straight to the storage of the service through URLs that the service signs; the address of the storage is
  checked and pinned as for the requests to the service. A backup is complete when the service has checked every chunk and
  returned a signed receipt. The report at the end of a backup gives the service counts without personal data (the README
  lists them).
- Backups start in the window that the service sets, a little after its start (a delay of its own for each site), or at once
  with the button "Back up now" of the status page or with `cli/backup.php --now`. A backup that the cron runs pauses at the
  end of its window and goes on in the next one when that one starts early enough for it to finish within 24 hours;
  otherwise (one window a day) it is stopped at the end of its window, and one that two windows did not finish is stopped.
  A backup that has sent everything waits for its receipt past the end of its window. While `cli/backup.php` drives a
  backup, the task of the cron leaves it alone, so the windows do not stop it between two steps of the command.
  A failed step is tried again, and an upload three times, before the backup is stopped.
- Registering the site again with the same site and the same encryption key keeps the key for the names of the chunks, so
  the next backup does not send again what the storage holds; a registration of another site or with another key gets a
  new one, and so does the removal of the registration.
- Before a start and while a backup runs, the plugin checks the server against the limits of the new page "Limits of the
  backup" (load per CPU core, free memory, database response, read speed of the file storage, free space for temporary
  files, upload speed) and waits while they are exceeded. A backup does not start while Moodle's automated course backup is
  working, when the clock is 5 minutes or more off, or with less than 256 MB of memory. The temporary folder of that page,
  which a backup leaves out, cannot be the data directory itself or a folder in it that already holds files, and cannot be
  changed while a backup runs.
- The report of a backup tells the service whether Moodle's code may have been modified: when it differs from the official
  release, and also when no official list could be compared with it (the whole code was then sent).
- The status page shows the running backup, the last completed backup and why the latest backup stopped, with advice. The
  site administrators get a message when backups are stopped three times in a row. Each time the status page is opened, it
  shows a warning when the files of the site are in an external storage (such as tool_objectfs), and when the last backup
  found that Moodle's code may have been modified; scheduled backups do not wait for it.
  The stage that the page and `cli/backup.php` show stays at the sending of the code until the code is all sent; it no longer
  says that the backup is being finished while the last chunks of the data are still being sent.
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
