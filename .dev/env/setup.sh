#!/usr/bin/env bash
# Clones Moodle, moodle-docker and moodle-plugin-ci for one Moodle version (once), and mounts the plugin.
# Usage: .dev/env/setup.sh <ver>
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# The database does not matter for the checkout; pgsql is a placeholder.
# shellcheck source=env.sh
source "$here/env.sh" "${1:?usage: setup.sh <405|500|501|502|503>}" pgsql

mkdir -p "$SECBK_ENV_ROOT"
if [ ! -d "$SECBK_MOODLE/.git" ]; then
    git clone --depth 1 --branch "$SECBK_BRANCH" https://github.com/moodle/moodle.git "$SECBK_MOODLE"
fi
if [ ! -d "$SECBK_DOCKER/.git" ]; then
    git clone --depth 1 https://github.com/moodlehq/moodle-docker.git "$SECBK_DOCKER"
fi
if [ ! -d "$SECBK_ENV_ROOT/moodle-plugin-ci" ]; then
    composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci "$SECBK_ENV_ROOT/moodle-plugin-ci" ^4
fi
# Infection (mutation testing, .dev/infection), only in the 5.3 environment. The version is fixed because the results in
# .dev/infection/REVIEW.md were made with it, on PHP 8.4 and PHPUnit 11.5, and the ignore entries there name mutants of
# its mutators. Raising it means running .dev/infection/run.sh again and reviewing the escaped mutants again.
if [ ! -d "$SECBK_ENV_ROOT/infection" ]; then
    composer create-project -n --no-dev --prefer-dist infection/infection:0.29.14 "$SECBK_ENV_ROOT/infection"
fi
if [ ! -d "$SECBK_MOODLE/${SECBK_PLUGIN_PATH%admin/tool/secbackup}local/moodlecheck" ]; then
    git clone --depth 1 https://github.com/moodlehq/moodle-local_moodlecheck.git \
        "$SECBK_MOODLE/${SECBK_PLUGIN_PATH%admin/tool/secbackup}local/moodlecheck"
fi

# Moodle's config.php for moodle-docker.
if [ ! -f "$SECBK_MOODLE/config.php" ]; then
    /bin/cp -f "$SECBK_DOCKER/config.docker-template.php" "$SECBK_MOODLE/config.php"
fi

# Mount the plugin repository and moodle-plugin-ci into the web server.
cat > "$SECBK_DOCKER/local.yml" <<YAML
services:
  webserver:
    volumes:
      - "$SECBK_REPO:/var/www/html/$SECBK_PLUGIN_PATH"
      - "$SECBK_ENV_ROOT/moodle-plugin-ci:/opt/moodle-plugin-ci"
      - "$SECBK_ENV_ROOT/infection:/opt/infection"
YAML
mkdir -p "$SECBK_MOODLE/$SECBK_PLUGIN_PATH"
echo "ready: $SECBK_MOODLE ($SECBK_BRANCH), plugin at $SECBK_PLUGIN_PATH"
