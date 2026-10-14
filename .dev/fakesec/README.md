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
