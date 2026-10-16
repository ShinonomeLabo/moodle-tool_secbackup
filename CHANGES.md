# Changes

## Unreleased

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
