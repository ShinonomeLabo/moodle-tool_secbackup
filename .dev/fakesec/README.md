# Fake backup service

A development server that answers like the backup service's enrolment and status API.
It uses tests/classes/fake_service.php, which does not use the plugin's classes.

    .dev/fakesec/start.sh 503 pgsql      # starts http://localhost:8099 in the web server container

Connection URL: http://localhost:8099/api/lms-backup/v1
Registration code: SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE
Public key of the site: secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=

The site must trust the fake service's key; .dev/env/devconfig.sh adds the settings to config.php.

Control (POST, answers 204): /_fake/reset, /_fake/newcode, /_fake/mode/<mode>, /_fake/status/<status>,
/_fake/clock/<seconds from now>. GET /_fake/state shows the state.
Modes: unavailable, rate_limited, ip_not_allowed, unexpected, untrusted_key, bad_signature, site_removed, bad_status.

## Where it runs

`start.sh` starts PHP's built-in server in the web server container of the environment. It runs as root and listens on
127.0.0.1:8099 inside the container only; nothing is published to the host. It keeps its state in the container's temporary
directory, in the file `tool_secbackup_fakesec.json`, which holds the last request, with the registration code that was sent.
`.dev/env/behat.sh` starts it before it runs Behat, and every Behat scenario resets it first.

To stop it (the file of the state stays until the container is removed):

    .dev/env/run.sh 503 pgsql pkill -f 'php -S 127.0.0.1:8099'

To remove the state as well, add `rm -f /tmp/tool_secbackup_fakesec.json` with `.dev/env/run.sh 503 pgsql`. The file router.php
answers "not found" when it is not run by PHP's built-in server.
