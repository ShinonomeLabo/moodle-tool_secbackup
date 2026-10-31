#!/usr/bin/env bash
# Restores a backup from the material that make_fixture.php wrote into a new development environment, the way the restore
# host restores a temporary site (see README.md):
#  1. the material is copied into the web server container of the backed-up site, and cli/restore.php --extract runs there as
#     root, with drive_extract.php playing the restore host (it seals the key of the site to the one-time key and sends go);
#  2. the Moodle code that the extraction wrote becomes the Moodle folder of the new environment (SECBK_SITE=restored), with a
#     config.php of moodle-docker, and the plugin of this branch is mounted in it;
#  3. the new environment starts (the web server, the database and mailpit, which the web server needs), the data root and
#     the staging folder are copied into its web server container, and admin/cli/install_database.php installs Moodle there;
#  4. with the web closed (climaintenance.html), cli/restore.php --import runs as www-data with a new password of
#     secbackup_admin, then admin/cli/purge_caches.php, and the web opens again.
# A backed-up site on MySQL is restored to MariaDB. The plugin is installed with Moodle, before the import: the import writes
# its table tool_secbackup_restore_stops.
# The new environment has a web port of its own (192 and the version: 19245 for 4.5) and a network that this script makes
# with a free subnet of 10.99.0.0/16, which moodle-docker takes as external (the default pools of Docker can be used up). Its
# Moodle folder (moodle-<ver>-<site>) is shared by the databases of one version, as with every development environment: a
# restore replaces a folder that an earlier restore made, and refuses one that it did not make. A restored environment is
# never restored again: give another SECBK_RESTORE_SITE.
# The material and the staging folder are removed from both containers when the script ends, also when a step fails: the
# material holds the secret key of the site. The password of secbackup_admin stays only in SECBK_ENV_ROOT/restore-<project>.
# Usage: .dev/restore/restore.sh <ver> <db> <material folder>
#   SECBK_SOURCE_SITE  the site of the backed-up environment (default none, as .dev/env/env.sh without SECBK_SITE)
#   SECBK_RESTORE_SITE the site of the new environment (default restored)
set -euo pipefail
ver="${1:?usage: restore.sh <ver> <db> <material folder>}"
db="${2:?usage: restore.sh <ver> <db> <material folder>}"
fixture="$(cd "${3:?usage: restore.sh <ver> <db> <material folder>}" && pwd)"
# shellcheck source=lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"

for name in input.json fixture.json manifest site-secret-key chunks; do
    if [ ! -e "$fixture/$name" ]; then
        echo "$fixture has no $name: it is not a folder that make_fixture.php wrote" >&2
        exit 1
    fi
done
if ! running "$source_project"; then
    echo "the backed-up site does not run: SECBK_SITE=$source_site .dev/env/up.sh $ver $db" >&2
    exit 1
fi
if [ -f "$env_root/installed-$target_project" ]; then
    echo "$target_project is installed already; restore into another site with SECBK_RESTORE_SITE=<name>" >&2
    exit 1
fi
target_moodle="$(env_value "$target_site" "$target_db" SECBK_MOODLE)"
target_docker="$(env_value "$target_site" "$target_db" SECBK_DOCKER)"
target_marker="$env_root/restored-$(basename "$target_moodle")"
if [ -e "$target_moodle" ] && [ ! -f "$target_marker" ]; then
    echo "$target_moodle exists and was not made by restore.sh: give another SECBK_RESTORE_SITE" >&2
    exit 1
fi
port="192${ver:0:1}${ver:2:1}"
mkdir -p "$target_work"
chmod 700 "$target_work"
echo "restoring $source_project into $target_project (http://127.0.0.1:$port)"

# Removes the material, the output of the extraction and the staging folder from the containers. The restored site does not
# run yet in the first steps: its removal then fails, and nothing of the material is in it.
cleanup() {
    in_source rm -rf /tmp/secbk-restore > /dev/null 2>&1 || true
    if running "$target_project"; then
        in_target rm -rf /tmp/secbk-restore > /dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# 1. The extraction, in the backed-up site.
in_source rm -rf /tmp/secbk-restore
in_source mkdir -p /tmp/secbk-restore/fixture
host_tar -C "$fixture" --exclude ./restore -cf - . | in_source tar -C /tmp/secbk-restore/fixture -xf -
# The two named pipes join the restore host and the extraction. Each side opens its pipes in the order that the other side
# opens them, so that neither waits for the other forever.
# shellcheck disable=SC2016
in_source sh -c '
    d=/tmp/secbk-restore
    mkfifo -m 600 "$d/to-cli" "$d/from-cli"
    php "$1/.dev/restore/drive_extract.php" --fixture="$d/fixture" > "$d/to-cli" < "$d/from-cli" &
    driver=$!
    php "$1/cli/restore.php" --extract < "$d/to-cli" > "$d/from-cli"
    extract=$?
    wait "$driver"
    drive=$?
    rm -f "$d/to-cli" "$d/from-cli"
    [ "$extract" -eq 0 ] && [ "$drive" -eq 0 ]
' sh "$plugin" 2> >(tee "$target_work/extract.log" >&2)

# 2. The Moodle folder of the new environment.
if [ -e "$target_moodle" ]; then
    echo "replacing $target_moodle, which an earlier restore made"
    rm -rf "$target_moodle"
fi
mkdir -p "$target_moodle"
touch "$target_marker"
in_source tar -C /tmp/secbk-restore/fixture/restore/root -cf - . | host_tar -C "$target_moodle" -xf -
if [ ! -d "$target_docker" ]; then
    git clone -q "$env_root/moodle-docker-$ver" "$target_docker"
fi
/bin/cp -f "$target_docker/config.docker-template.php" "$target_moodle/config.php"
plugin_path="${plugin#/var/www/html/}"
mkdir -p "$target_moodle/$plugin_path"
repo="$(cd "$restore_here/../.." && pwd)"
cat > "$target_docker/local.yml" << YAML
# Written by .dev/restore/restore.sh: the plugin of this branch, a web port of its own and a network made beforehand.
services:
  webserver:
    environment:
      MOODLE_DOCKER_WEB_PORT: "127.0.0.1:$port"
    ports: !override
      - "127.0.0.1:$port:80"
    volumes:
      - "$repo:/var/www/html/$plugin_path"
networks:
  default:
    name: "\${COMPOSE_PROJECT_NAME}_default"
    external: true
YAML

# 3. The new environment, its data root and the install.
if ! docker network inspect "${target_project}_default" > /dev/null 2>&1; then
    made=
    for n in $(seq 20 250); do
        if error="$(docker network create --subnet "10.99.$n.0/24" --label secbk.restore=1 "${target_project}_default" 2>&1)"
        then
            made="10.99.$n.0/24"
            break
        fi
        # Only a subnet that another network has is tried again; any other error (Docker does not answer) ends the script.
        if ! printf '%s' "$error" | grep -qi 'overlap'; then
            echo "the network ${target_project}_default could not be made: $error" >&2
            exit 1
        fi
    done
    if [ -z "$made" ]; then
        echo "no free subnet of 10.99.0.0/16 for the network ${target_project}_default" >&2
        exit 1
    fi
    echo "network ${target_project}_default: $made"
fi
(
    export SECBK_SITE="$target_site"
    # shellcheck source=../env/env.sh
    source "$restore_env/env.sh" "$ver" "$target_db"
    "$SECBK_DOCKER/bin/moodle-docker-compose" up -d webserver
    "$SECBK_DOCKER/bin/moodle-docker-wait-for-db"
)
# moodle-docker waits 10 seconds for the database, which a first start of MariaDB can take longer than.
# shellcheck disable=SC2016
for _ in $(seq 1 60); do
    if in_target php -r '
        $name = getenv("MOODLE_DOCKER_DBNAME");
        $user = getenv("MOODLE_DOCKER_DBUSER");
        $pass = getenv("MOODLE_DOCKER_DBPASS");
        try {
            $ok = getenv("MOODLE_DOCKER_DBTYPE") === "pgsql"
                ? @pg_connect("host=db dbname=$name user=$user password=$pass") !== false
                : @mysqli_connect("db", $user, $pass, $name) !== false;
        } catch (Throwable $e) {
            $ok = false;
        }
        exit($ok ? 0 : 1);'; then
        break
    fi
    sleep 5
done
in_target mkdir -p /tmp/secbk-restore/staging
in_source tar -C /tmp/secbk-restore/fixture/restore/dataroot -cf - . | in_target tar -C /var/www/moodledata -xf -
in_source tar -C /tmp/secbk-restore/fixture/restore/staging -cf - . | in_target tar -C /tmp/secbk-restore/staging -xf -
in_target chown -R www-data:www-data /var/www/moodledata /tmp/secbk-restore/staging
if [ -f "$target_moodle/admin/cli/install_database.php" ]; then corecli=admin/cli; else corecli=public/admin/cli; fi
# The password of the install is never used: the import replaces the users.
in_target php "$corecli/install_database.php" --agree-license --fullname="Restored site" --shortname=restored \
    --summary="Restored by .dev/restore/restore.sh" --adminpass="$(openssl rand -hex 16)" --adminemail=admin@example.com \
    > "$target_work/install.log"
touch "$env_root/installed-$target_project"
echo "installed: $(tail -n 1 "$target_work/install.log")"

# 4. The import, with the web closed.
(umask 077 && openssl rand -base64 24 | tr '+/' '-_' | tr -d '=\n' > "$target_work/admin-password")
in_target sh -c 'echo "<h1>Restoring</h1>" > /var/www/moodledata/climaintenance.html'
expires="$(in_target php -r 'echo gmdate("Y-m-d\TH:i:s\Z", time() + 7 * 86400);')"
printf '{"admin":{"username":"secbackup_admin","password":"%s"},"expires_at":"%s","temp_host":"%s.example.test"}\n' \
    "$(cat "$target_work/admin-password")" "$expires" "$target_project" \
    | in_target runuser -u www-data -- php "$plugin/cli/restore.php" --import --staging=/tmp/secbk-restore/staging \
    > "$target_work/import.log"
in_target php "$corecli/purge_caches.php"
in_target rm -f /var/www/moodledata/climaintenance.html
# shellcheck disable=SC2016
in_target php -r '
    $done = json_decode((string) array_slice(file("php://stdin"), -1)[0], true)["summary"];
    $kinds = array_count_values(array_column($done["stopped"], "kind"));
    ksort($kinds);
    echo "imported: {$done["tables"]} tables, {$done["rows"]} rows; stopped: ", json_encode($kinds), "\n";
' < "$target_work/import.log"
echo "restored: http://127.0.0.1:$port (secbackup_admin, the password is in $target_work/admin-password)"
echo "compare it: .dev/restore/compare.sh $ver $db $fixture"
