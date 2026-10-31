# Restore of a backup, from end to end

The scripts here back up a development site against the fake backup service (`.dev/fakesec`), copy the backup into a folder
(the material), restore it into a new development environment the way the restore host restores a temporary site, and compare
the restored site with the site it was backed up from. They are tools of the tests, not part of the plugin package.

| Script | Where it runs | What it does |
|---|---|---|
| `make_fixture.php --out=<dir> --secretkey=<file>` | the backed-up site (Moodle CLI) | copies the last completed run of the fake service into `<dir>`: `chunks/`, `manifest`, `index`, `input.json` (the input of `cli/restore.php --extract` with paths relative to `<dir>`), `signing-public-key`, `site-secret-key` (the secret key of the test site) and `fixture.json` (the plan that the backup service would give the restore host, the summary that the extraction must report, and the names of what the restore changes); only the owner can read the folder |
| `compare.php --snapshot=<file> [--login=<file>]` | a site (Moodle CLI) | writes a snapshot of the site: the hash of every row by table, the contents of the file store and whether each is in `filedir`, the plugin versions, and with `--login` whether `secbackup_admin` logs in (in the database and through the login page) |
| `compare.php --source=<snapshot> --target=<snapshot> [--fixture=<fixture.json>]` | anywhere with PHP | compares two snapshots, lists first what it leaves out, and prints `result: ok` or `result: mismatch` |
| `drive_extract.php --fixture=<dir>` | the backed-up site, started by `restore.sh` | plays the restore host for `cli/restore.php --extract`: seals the secret key of the site to the one-time public key, sends the input with absolute paths, and checks the summary against `fixture.json` |
| `restore.sh <ver> <db> <material>` | this machine | restores the material into a new environment (below) |
| `compare.sh <ver> <db> <material>` | this machine | snapshots the restored site and compares it with `source-snapshot.json` of the material |

The commands below use Moodle 4.5 on PostgreSQL and the site `p5` (`SECBK_SITE=p5`) as the backed-up site; any development
environment with the plugin of this branch can be one. `restore.sh` and `compare.sh` take it from `SECBK_SOURCE_SITE`, which
is none by default, as `.dev/env/env.sh` without `SECBK_SITE`. `<plugin>` is `admin/tool/secbackup`
(`public/admin/tool/secbackup` from Moodle 5.1), `<moodle>` the Moodle folder of the environment (`SECBK_MOODLE` of
`.dev/env/env.sh`), and `<material>` a folder for the material outside the repository, such as
`$SECBK_ENV_ROOT/fixtures/fixture-405-pgsql`: a folder in the repository is in the plugin, which a site serves and backs up.

## 1. The backed-up site

The site needs the developer settings of `.dev/env/devconfig.sh` (they trust the key of the fake service), an additional
plugin besides this one, so that a restore writes the code of a plugin where the restored Moodle has its type, and data
(`.dev/env/data.sh`), here with an activity of that plugin (the folder is `<moodle>/public/mod/attendance` from Moodle 5.1,
and the branch the one of the version). Then the fake service, a key pair of the site, the registration
without a list of official files (so that the run sends the whole Moodle program, which a restore needs), and a whole backup:

    export SECBK_SITE=p5
    git clone --depth 1 --branch MOODLE_405_STABLE https://github.com/danmarsden/moodle-mod_attendance.git <moodle>/mod/attendance
    .dev/env/run.sh 405 pgsql php admin/cli/upgrade.php --non-interactive
    .dev/env/data.sh 405 pgsql S
    .dev/env/run.sh 405 pgsql php -r '
        define("CLI_SCRIPT", true);
        require "config.php";
        require_once($CFG->libdir . "/testing/generator/lib.php");
        \core\session\manager::set_user(get_admin());
        $course = $DB->get_records_select("course", "id <> ?", [SITEID], "id", "*", 0, 1);
        (new testing_data_generator())->create_module("attendance", ["course" => reset($course)->id]);'
    .dev/fakesec/start.sh 405 pgsql
    key="$(.dev/env/run.sh 405 pgsql php -r '
        $pair = sodium_crypto_box_keypair();
        umask(077);
        file_put_contents("/tmp/secbk-site.key", base64_encode(sodium_crypto_box_secretkey($pair)));
        echo "secbk1:" . base64_encode(sodium_crypto_box_publickey($pair));')"
    .dev/env/run.sh 405 pgsql php <plugin>/.dev/fakesec/prepare.php --publickey="$key" --no-core-manifest
    .dev/env/run.sh 405 pgsql php <plugin>/cli/backup.php --now

The fake service keeps the stored objects as files under `/tmp/tool_secbackup_fakesec/objects/` in the web server container.

The backup waits while its temporary folder has less free space than the larger of 10% of its disk and 5 GB. On a machine
whose Docker disk is that full, give the web server a small file system for the temporary files in `local.yml` of the
moodle-docker folder, and make it the temporary folder of the plugin before the backup:

    services:
      webserver:
        tmpfs:
          - /var/secbk-tmp:size=6g,mode=1777

    .dev/env/run.sh 405 pgsql php admin/cli/cfg.php --component=tool_secbackup --name=tempdir --set=/var/secbk-tmp

A change of `local.yml` makes the web server container again, and the data root of moodle-docker is inside that container:
copy it out and back in (`tar` through `.dev/env/run.sh`) when the site has data already.

## 2. The material

Right after the backup, on the backed-up site: the material, and the snapshot of the site that the restored site is compared
with. Then copy the folder out of the container (`moodle-docker-compose cp`, with the variables of `.dev/env/env.sh`):

    .dev/env/run.sh 405 pgsql php <plugin>/.dev/restore/make_fixture.php --out=/tmp/secbk-fixture --secretkey=/tmp/secbk-site.key
    .dev/env/run.sh 405 pgsql php <plugin>/.dev/restore/compare.php --snapshot=/tmp/secbk-fixture/source-snapshot.json
    (umask 077 && mkdir -p "$SECBK_ENV_ROOT/fixtures")
    (source .dev/env/env.sh 405 pgsql && "$SECBK_DOCKER/bin/moodle-docker-compose" cp webserver:/tmp/secbk-fixture <material>)
    .dev/env/run.sh 405 pgsql rm -rf /tmp/secbk-fixture /tmp/secbk-site.key

The last line removes the material and the secret key from the container. The only secret of the material is
`site-secret-key`, the secret key of the test site: the key of a development site, never of a real one. The snapshot holds
the hash of each row, and the values of only a few settings that are checked (`siteadmins`, `noemailever`,
`alternateloginurl`) and of the scheduled tasks and the message processors; `fixture.json` holds names, never values.
Only the owner can read the folder.

## 3. The restore

    SECBK_SOURCE_SITE=p5 .dev/restore/restore.sh 405 pgsql <material>

`restore.sh` runs `cli/restore.php --extract` as root in the web server container of the backed-up site, with
`drive_extract.php` as the restore host (two named pipes join them; like the restore host, it makes the folders that the
extraction writes into, which the extraction requires). It then makes the new environment, `SECBK_SITE=restored`
(`SECBK_RESTORE_SITE` gives another name):

- the Moodle code that the extraction wrote becomes the Moodle folder `moodle-<ver>-restored` in `SECBK_ENV_ROOT`, with the
  `config.php` of moodle-docker, and the plugin of this branch is mounted in it (the extraction does not write the code of
  `tool_secbackup`, as on the restore host). The folder is shared by the databases of one version, like every development
  folder: a later restore of the same version replaces it, and `restore.sh` refuses a folder that it did not make;
- `moodle-docker-<ver>-restored` is a copy of `moodle-docker-<ver>` whose `local.yml` gives the web port 192 and the version
  (19245 for 4.5, 19253 for 5.3) and an external network `<project>_default`, which `restore.sh` makes with a free subnet of
  `10.99.0.0/16`;
- only the web server, the database and mailpit (the web server needs it) start. The data root and the staging folder of the
  extraction are copied into the web server container, and `admin/cli/install_database.php` installs Moodle with the plugin
  (the import needs the plugin installed: it writes the table `tool_secbackup_restore_stops`);
- with the web closed by `climaintenance.html`, `cli/restore.php --import` runs as `www-data` with a new password of
  `secbackup_admin`, then `admin/cli/purge_caches.php`, and the web opens again.

A backed-up site on MySQL is restored to MariaDB. An environment that is installed already is not restored again: give
another `SECBK_RESTORE_SITE`. The password of `secbackup_admin` and the output of each step are in
`SECBK_ENV_ROOT/restore-<project>/`; the password is never printed. When the script ends, also when a step fails, it removes
the material, the output of the extraction and the staging folder from both containers.

## 4. The comparison

    SECBK_SOURCE_SITE=p5 .dev/restore/compare.sh 405 pgsql <material>

The restored site writes its snapshot, with the login of `secbackup_admin`, and `compare.php` compares it with
`source-snapshot.json`. The output starts with what it leaves out: the tables that the backup does not hold (`sessions`,
`cache_flags`, and what the header says it left out, such as the logs without `include_logs`), the tables and the rows of
`tool_secbackup` (the restored site keeps its own plugin), the settings that `config.php` of the backed-up site forced (the
import writes them into the database), `siteadmins`, the user `secbackup_admin` and its context, what the import stopped
(as `tool_secbackup_restore_stops` records it), and what `purge_caches.php` after the import sets to the time it runs (the
revisions of the caches among the settings, and `cacherev` of every course). What is left out because the import changed
it is checked on its own: `siteadmins` is the list of the backed-up site with `secbackup_admin` added, what the import stopped
has the values that stop it, nothing is stopped but the tasks of the additional plugins and the core tasks that the import
stops, the header leaves out no table but the logs, and the registration of the plugin is not restored. Every other
difference of a row, of the contents of the file store, of a plugin version, or of the login, and every check that fails, is
a mismatch. A check that cannot be made is listed as `not checked` and is not a failure: without `fixture.json`, the header
and the tasks of the additional plugins; with a `fixture.json` that does not record `include_logs` (a material made before it
did), whether the header left out the logs. The snapshot of the restored site is taken
before its login; a login by hand before the comparison adds rows (preferences of `secbackup_admin`, an ad hoc task) that
show as differences.

## Stopping and removing the environments

Stop an environment and keep its data (`down.sh` without `--volumes` only stops the containers):

    SECBK_SITE=restored .dev/env/down.sh 405 pgsql
    SECBK_SITE=restored .dev/env/up.sh 405 pgsql      # starts it again with its data

The database is an anonymous volume of the database container, and the data root is inside the web server container itself.
Do not run `moodle-docker-compose down` (with or without `-v`), nor `down.sh --volumes`, unless the data is to go: they
remove the containers, the data root with them, and the restored site cannot be brought back without restoring it again.

To remove a restored environment for good (the removal cannot be undone):

    SECBK_SITE=restored .dev/env/down.sh 405 pgsql --volumes
    docker network rm secbk405pgsqlrestored_default
    rm -rf "$SECBK_ENV_ROOT/restore-secbk405pgsqlrestored"

and, when no restored environment of that version is left, the folders `moodle-405-restored` and
`moodle-docker-405-restored` and the file `restored-moodle-405-restored` in `SECBK_ENV_ROOT`. The fake service keeps its
objects in the container of the backed-up site until a reset or the removal of the container.
