# Fake backup service

A development server that answers like the backup service's enrolment and status API.
It uses tests/classes/fake_service.php, which does not use the plugin's classes.

    .dev/fakesec/start.sh 503 pgsql      # starts http://localhost:8099 in the web server container

Connection URL: http://localhost:8099/api/lms-backup/v1
Registration code: SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE
Public key of the site: secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=

The site must trust the fake service's key; .dev/env/devconfig.sh adds the settings to config.php.

Control (POST, answers 204): /_fake/reset, /_fake/newcode, /_fake/mode/<mode>, /_fake/status/<status>,
/_fake/clock/<seconds from now>, /_fake/encryptionkey (the body is the public key that the enrolment accepts, `secbk1:...`),
/_fake/core-manifest (the body is `{"version", "build", "release", "count", "content"}`, the list of official files compressed
and in base64). GET /_fake/state shows the state.
Modes: unavailable, rate_limited, ip_not_allowed, unexpected, untrusted_key, bad_signature, site_removed, bad_status.

## A whole run against it

Two scripts of this folder make a whole backup run against the fake service possible, outside Behat (`.dev/nfs`,
`.dev/objectfs` and the state `run` of `.dev/secureos` use them). Both are Moodle command line scripts in the site's copy of
the plugin.

- `prepare.php [--publickey=secbk1:...] [--fake=http://localhost:8099]` resets the fake service, tells it which encryption
  public key it accepts, registers the site (as the Behat step does), makes it send the settings of the backup with a window
  twelve hours away (so that only a start by hand begins a run), receives them with the regular status request, and publishes
  the list of the official files of this Moodle build, made from the site itself. The run then sends the added plugins as
  code but not the whole Moodle program: the fake service keeps every stored object in its one state file, which the whole
  program would make hundreds of megabytes large.
- `verify.php --secretkey=<file> [--state=<file>]` opens the last completed run that the fake service holds with the secret
  key of the key pair that `prepare.php` registered (the fixed key of the fake site has no known secret key), decrypts the
  list of the generation and every file of it, and checks each against the site: the SHA-1 of its pieces put together is the
  content hash, and its size is the size in the files table; and the set of contents of the backup is the set of contents of
  one byte or more of the site. It prints counts and `result: ok` or `result: mismatch`.

## Where it runs

`start.sh` starts PHP's built-in server in the web server container of the environment. It runs as root and listens on
127.0.0.1:8099 inside the container only; nothing is published to the host. It keeps its state in the container's temporary
directory, in the file `tool_secbackup_fakesec.json`, which holds the last request, with the registration code that was sent.
`.dev/env/behat.sh` starts it before it runs Behat, and every Behat scenario resets it first.

To stop it (the file of the state stays until the container is removed):

    .dev/env/run.sh 503 pgsql pkill -f 'php -S 127.0.0.1:8099'

To remove the state as well, add `rm -f /tmp/tool_secbackup_fakesec.json` with `.dev/env/run.sh 503 pgsql`. The file router.php
answers "not found" when it is not run by PHP's built-in server.
