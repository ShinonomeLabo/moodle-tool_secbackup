# Development environments

One environment per Moodle version and database, built with moodle-docker.
Large files live outside the repository in `SECBK_ENV_ROOT`
(default `~/work/secbackup-env`).

    .dev/env/setup.sh 503                  # clone Moodle 5.3, moodle-docker and moodle-plugin-ci (once)
    .dev/env/up.sh 503 pgsql               # start the containers
    .dev/env/install.sh 503 pgsql          # install Moodle, PHPUnit and Behat (once per database)
    .dev/env/phpunit.sh 503 pgsql          # run the plugin's PHPUnit tests
    .dev/env/behat.sh 503 pgsql            # run the plugin's Behat features
    .dev/env/check.sh 503 pgsql            # phplint, phpcs, phpdoc, validate, savepoints
    .dev/env/down.sh 503 pgsql             # stop the containers; the installed data is kept
    .dev/env/down.sh 503 pgsql --volumes   # remove the containers and all their data

Web ports: 4.5 = 18045, 5.1 = 18051, 5.2 = 18052, 5.3 = 18053 (admin / test).
