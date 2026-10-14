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
    .dev/env/behat.sh 503 pgsql            # run the plugin's Behat features
    .dev/env/check.sh 503 pgsql            # phplint, phpcs, phpdoc, validate, savepoints
    .dev/env/down.sh 503 pgsql             # stop the containers; the installed data is kept
    .dev/env/down.sh 503 pgsql --volumes   # remove the containers and all their data

Web ports: 4.5 = 18045, 5.1 = 18051, 5.2 = 18052, 5.3 = 18053 (admin / test).

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
