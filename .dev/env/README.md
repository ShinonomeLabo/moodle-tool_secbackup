# Development environments

One environment per Moodle version and database, built with moodle-docker.
Large files (the Moodle checkouts, moodle-docker and moodle-plugin-ci) live outside the repository, in the
directory `SECBK_ENV_ROOT` (default `$HOME/secbackup-env`). To keep them somewhere else on your machine, export
`SECBK_ENV_ROOT` or write it once into the untracked file `.dev/env/local.env`, which `env.sh` reads (so every
script here does) and `.gitignore` lists:

    SECBK_ENV_ROOT="$HOME/work/secbackup-env"

An exported `SECBK_ENV_ROOT` wins over `local.env`, which wins over the default.

    .dev/env/setup.sh 503                  # clone Moodle 5.3, moodle-docker and moodle-plugin-ci (once)
    .dev/env/up.sh 503 pgsql               # start the containers
    .dev/env/install.sh 503 pgsql          # install Moodle, PHPUnit and Behat (once per database)
    .dev/env/phpunit.sh 503 pgsql          # run the plugin's PHPUnit tests
    .dev/env/behat.sh 503 pgsql            # run the plugin's Behat features (in Chrome)
    SECBK_BROWSER=firefox .dev/env/behat.sh 503 pgsql   # ... in firefox, edge or safari (below)
    .dev/env/check.sh 503 pgsql            # phplint, phpcs, phpdoc, validate, savepoints
    .dev/env/down.sh 503 pgsql             # stop the containers; the installed data is kept
    .dev/env/down.sh 503 pgsql --volumes   # remove the containers and all their data

Web ports: 4.5 = 18045, 5.0 = 18050, 5.1 = 18051, 5.2 = 18052, 5.3 = 18053 (admin / test).

## Dummy data

    .dev/env/data.sh 503 pgsql S          # small site (3 courses, 30 users, about 60 files)
    .dev/env/data.sh 503 pgsql M --seed=2 # medium site (10 courses, 200 users, about 600 files)

Users are named `secbk_<time>_<n>` and courses `secbk-<time>-<n>`. A second run does nothing
unless `--force` is given.

## Development settings in config.php

`.dev/env/devconfig.sh 503` adds a block to the `config.php` of the development site of that Moodle version (once; a second run
says "already set"). The block defines `TOOL_SECBACKUP_DEVELOPER`: it trusts the key of the fake backup service, accepts
`http://localhost:8099` and skips the network checks. The block starts with the comment `tool_secbackup development
settings` and ends with the comment `End of tool_secbackup development settings.`. To undo it, delete the lines from the first
comment to the last one. The plugin's pages then show no developer warning, and registration with the fake service no longer
works. Nothing else of the site is changed.

## Behat in other browsers

`.dev/env/devconfig.sh` also adds the Behat profiles `firefox`, `edge` and `safari` to the same `config.php` (a second block,
`tool_secbackup Behat browsers`; delete it the same way). `SECBK_BROWSER=<browser> .dev/env/behat.sh <ver> <db>` (and
`.dev/ci/run.sh`) selects the browser; extra arguments go to Behat.

| Browser | How it runs here |
|---|---|
| chrome | The Selenium container of the environment (moodle-docker). |
| firefox | `.dev/env/browser.sh <ver> <db> firefox` starts `selenium/standalone-firefox:4` next to the environment (`behat.sh` does it). |
| edge | The same with `selenium/standalone-edge:4`. Microsoft has no Linux build of Edge for arm64, so on Apple silicon the image runs as linux/amd64 under emulation: it is slow, and `behat.sh` makes the time limits of Behat 8 times longer for it. |
| safari | Safari on this Mac through `safaridriver` (it must be allowed once with `sudo safaridriver --enable`; nothing here changes a setting of Safari or of the system). Safari opens its own automation window on the desktop while the run lasts. |

`.dev/env/browser.sh <ver> <db> <firefox|edge> stop` removes the container of a browser (only that one).

Safari runs outside Docker, so `behat.sh` does three things for the run and undoes them when it ends. (1) It writes the address
`http://localhost:<web port>` of the Behat site to the file `.secbk-behat-wwwroot` in the Moodle checkout, which `config.php`
reads (the Mac cannot resolve `http://webserver`), and enables the Behat site again; the file and the address are removed
afterwards. (2) It starts `.dev/env/relay.php` in the web server container, so that the Behat command can reach that address from
inside the container. (3) It starts `safaridriver` and a second relay on the Mac, which changes the Host header of each request
to `localhost`, because safaridriver refuses every other Host and the container reaches the Mac as `host.docker.internal`.
Both relays listen on `127.0.0.1` only and are stopped by the script. Safari is slower here than the other browsers: every page
of the development site loads many files through Docker's port forwarding.
