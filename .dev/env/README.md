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

    .dev/env/data.sh 503 pgsql S --plugin-data --files-gb=2

Two options add to the dummy courses, also on a site that has them already (no `--force` needed):

- `--plugin-data`: an activity or a block of each extra plugin of the staging end-to-end test (`.dev/e2e/plugins.json`) that
  the site has installed, with data in it (attendance sessions, an item of the sharing cart, a questionnaire with answers,
  videos, points), in every dummy course.
- `--files-gb=<number>`: files of random content, in a folder "Bulk files" in each course, until the folder `filedir` holds
  that many GB (10^9 bytes) in total. Of the files it adds, 70 % have 64 KiB to 1 MiB, 25 % 1 to 4 MiB and 5 % 4 to 64 MiB.

`.dev/e2e/check-data.php --files-gb=<number>` checks the result: the total within 5 %, a file larger than 4 MiB, and rows in
the main tables of each extra plugin. It wants every plugin of `plugins.json` installed, except the ones left out with
`--skip`; give it the same `--skip` as `.dev/e2e/install-plugins.sh`, which puts the plugins:

    .dev/e2e/install-plugins.sh <Moodle folder> --skip mod_videoassessment
    php admin/tool/secbackup/.dev/e2e/check-data.php --files-gb=0.2 --skip mod_videoassessment

Both scripts take `--skip` more than once (`--skip a --skip=b`) and with a list (`--skip=a,b`). block_autoattend and
mod_autoattendmod are left out together (`--skip=block_autoattend,mod_autoattendmod`): the activity needs the block in its
course and the library of the block.

## Development settings in config.php

`.dev/env/devconfig.sh 503` adds a block to the `config.php` of the development site of that Moodle version (once; a second run
says "already set"). The block defines `TOOL_SECBACKUP_DEVELOPER`: it trusts the key of the fake backup service, accepts
`http://localhost:8099` and skips the network checks. The block starts with the comment `tool_secbackup development
settings` and ends with the comment `End of tool_secbackup development settings.`. To undo it, delete the lines from the first
comment to the last one. The plugin's pages then show no developer warning, and registration with the fake service no longer
works. Nothing else of the site is changed.

## Another backup service

`.dev/env/remote-service.sh <ver>` connects the sites of one Moodle version to a backup service other than the fake one, for
example a copy of the backup service that runs on this machine. It reads two environment variables:

    SECBK_SERVICE_URL=http://host.docker.internal:8080/api/backup/v1 \
    SECBK_SERVICE_KEY=<standard base64 of the service's Ed25519 public key> \
    .dev/env/remote-service.sh 503

`SECBK_SERVICE_URL` is the connection URL that the site is registered with, and `SECBK_SERVICE_KEY` the key that the service
signs its answers with. The script writes a block to the `config.php` of that version (shared by its databases) that defines
`TOOL_SECBACKUP_DEVELOPER` with that key as the one trusted key. For an `http://` URL the block also accepts http, private hosts
and other ports, and skips the network checks (a service reached over http runs on this machine or in a private network); for
an `https://` URL it trusts the key and changes nothing else. Only one block may define the constant, so the block takes the
place of the development settings of `devconfig.sh`; a second run replaces the block. The block starts with the comment
`tool_secbackup remote service settings` and ends with `End of tool_secbackup remote service settings.`. While it is there,
`devconfig.sh` does not add the settings of the fake service. To go back:

    .dev/env/remote-service.sh 503 --off   # removes the block
    .dev/env/devconfig.sh 503              # adds the settings of the fake service again

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
Both relays listen on `127.0.0.1` only and are stopped by the script. While the Safari run lasts, the relay on the Mac rewrites Host to `localhost`, so safaridriver's own Host check (a protection against web pages that reach it) does not protect `127.0.0.1:4446` from other local processes, containers and web pages: run Safari only while you use it. Safari is slower here than the other browsers: every page
of the development site loads many files through Docker's port forwarding.
