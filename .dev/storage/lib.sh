# shellcheck shell=bash
# Shared by .dev/nfs/run.sh and .dev/objectfs/run.sh (sourced, not run). Development only.
# They run on a disposable Ubuntu machine with sudo, PHP and a PostgreSQL server that trusts the user postgres on
# 127.0.0.1: the jobs of .github/workflows/storage.yml. Nothing here is meant for a machine that someone uses.
#
# The site: Moodle from git (one branch, depth 1) in $SECBK_STORAGE_DIR/moodle, with this repository's files in
# admin/tool/secbackup; the database moodle on 127.0.0.1; the address http://localhost:8000 (served by PHP only when a script
# needs pages); the admin password is made for the run and kept in $SECBK_STORAGE_DIR/adminpass (mode 0600, never printed).

SECBK_STORAGE_DIR="${SECBK_STORAGE_DIR:-${RUNNER_TEMP:-/tmp}/secbk-storage}"
secbk_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
secbk_moodle="$SECBK_STORAGE_DIR/moodle"
secbk_wwwroot=http://localhost:8000
mkdir -p "$SECBK_STORAGE_DIR"

# Prints a line of the results, which the caller keeps in $SECBK_STORAGE_DIR/results.txt.
result() {
    echo "result: $*"
    echo "$*" >> "$SECBK_STORAGE_DIR/results.txt"
}

# The folder that the web server serves: public/ from Moodle 5.1 on.
storage_docroot() {
    if [ -d "$secbk_moodle/public" ]; then echo "$secbk_moodle/public"; else echo "$secbk_moodle"; fi
}

# The folder of the plugin in the site.
storage_plugin() { echo "$(storage_docroot)/admin/tool/secbackup"; }

# Runs PHP in the folder of the site with no memory limit (the scripts of the plugin and of Moodle find config.php from there).
storage_php() { (cd "$(storage_docroot)" && php -d memory_limit=-1 "$@"); }

# storage_moodle <branch>: clones Moodle and copies the tracked files of this repository into it as the plugin.
storage_moodle() {
    local branch="$1" plugin
    git clone --quiet --depth 1 --branch "$branch" https://github.com/moodle/moodle.git "$secbk_moodle"
    plugin="$(storage_plugin)"
    mkdir -p "$plugin"
    git -C "$secbk_repo" ls-files -z | tar -C "$secbk_repo" --null -T - -cf - | tar -C "$plugin" -xf -
}

# storage_install <dataroot>: installs the site, then adds the developer settings that trust the fake backup service.
storage_install() {
    local dataroot="$1" installer
    installer="$secbk_moodle/admin/cli/install.php"
    [ -f "$installer" ] || installer="$secbk_moodle/public/admin/cli/install.php"
    mkdir -p "$dataroot"
    (umask 077; openssl rand -hex 16 > "$SECBK_STORAGE_DIR/adminpass")
    php -d memory_limit=-1 "$installer" --non-interactive --agree-license --wwwroot="$secbk_wwwroot" --dataroot="$dataroot" \
        --dbtype=pgsql --dbhost=127.0.0.1 --dbname=moodle --dbuser=postgres --dbpass= --fullname="secbackup storage" \
        --shortname=storage --adminpass="$(cat "$SECBK_STORAGE_DIR/adminpass")" --adminemail=admin@example.com > /dev/null
    storage_config "$(cat <<'PHP'
// tool_secbackup development settings: the fake backup service.
define('TOOL_SECBACKUP_DEVELOPER', [
    'trustedkeys' => ['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw='],
    'allowhttp' => true,
    'allowprivatehosts' => true,
    'skipnetworkchecks' => true,
]);
PHP
)"
}

# storage_config <text>: puts PHP lines into config.php, before the line that loads Moodle.
storage_config() {
    php -r '
        $file = $argv[1];
        $text = file_get_contents($file);
        $at = strrpos($text, "require_once");
        if ($at === false) {
            fwrite(STDERR, "config.php has no require_once line\n");
            exit(1);
        }
        file_put_contents($file, substr($text, 0, $at) . $argv[2] . "\n\n" . substr($text, $at));
    ' "$secbk_moodle/config.php" "$1"
}

# Starts the fake backup service on 127.0.0.1:8099 (its state is the file tool_secbackup_fakesec.json in the temporary folder
# of this user, which verify.php reads). It keeps every stored object in that file, so it has no memory limit.
storage_fake_start() {
    nohup php -d memory_limit=-1 -S 127.0.0.1:8099 "$(storage_plugin)/.dev/fakesec/router.php" \
        > "$SECBK_STORAGE_DIR/fakesec.log" 2>&1 &
    for _ in $(seq 1 40); do
        if curl -fs -o /dev/null http://127.0.0.1:8099/_fake/state; then return 0; fi
        sleep 0.5
    done
    cat "$SECBK_STORAGE_DIR/fakesec.log" >&2
    return 1
}

# Starts the web server of the site on localhost:8000, with several workers (a page asks for many files at once).
storage_web_start() {
    PHP_CLI_SERVER_WORKERS=4 nohup php -d memory_limit=-1 -S localhost:8000 -t "$(storage_docroot)" \
        > "$SECBK_STORAGE_DIR/web.log" 2>&1 &
    for _ in $(seq 1 40); do
        if curl -s -o /dev/null "$secbk_wwwroot/login/index.php"; then return 0; fi
        sleep 0.5
    done
    cat "$SECBK_STORAGE_DIR/web.log" >&2
    return 1
}

# Makes an X25519 key pair for the site: the secret key goes to $SECBK_STORAGE_DIR/site.key (base64, mode 0600), and the
# public key is printed as the platform shows it (secbk1:...).
storage_keypair() {
    (umask 077; php -r '
        $pair = sodium_crypto_box_keypair();
        file_put_contents($argv[1], base64_encode(sodium_crypto_box_secretkey($pair)));
        echo "secbk1:" . base64_encode(sodium_crypto_box_publickey($pair));
    ' "$SECBK_STORAGE_DIR/site.key")
}

# Registers the site with the fake backup service, with a key pair made for the run (see .dev/fakesec/prepare.php).
storage_prepare() {
    storage_php "$(storage_plugin)/.dev/fakesec/prepare.php" --publickey="$(storage_keypair)"
}

# Opens the last completed backup that the fake service holds and checks its files (see .dev/fakesec/verify.php).
storage_verify() {
    storage_php "$(storage_plugin)/.dev/fakesec/verify.php" --secretkey="$SECBK_STORAGE_DIR/site.key"
}
