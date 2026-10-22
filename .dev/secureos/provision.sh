#!/usr/bin/env bash
# Runs inside a test VM (Rocky Linux, AlmaLinux, Debian or Ubuntu) as root: Apache with php-fpm, PostgreSQL and Moodle,
# using only the packages of the distribution. The access control the OS ships (SELinux or AppArmor) stays as shipped.
# Development only; not part of the plugin package. Usage (in the VM): sudo bash provision.sh [host port of the site]
#
# PHP and PostgreSQL come from the distribution's own repositories (on Rocky and AlmaLinux 9, the newest module
# stream). EPEL and CRB are enabled on Rocky and AlmaLinux only for a PHP extension that Moodle or the plugin needs and
# AppStream lacks; the result is recorded in /etc/secbk-secureos-info.
set -euo pipefail

port="${1:-18080}"
. /etc/os-release
major="${VERSION_ID%%.*}"
case "$ID" in
    rocky | almalinux) family=el; webuser=apache ;;
    debian | ubuntu) family=deb; webuser=www-data ;;
    *) echo "unsupported distribution: $ID" >&2; exit 1 ;;
esac
# The older version of each pair gets Moodle 4.5; the newer one gets the newest Moodle its PHP and PostgreSQL accept.
case "$ID$major" in
    rocky9 | almalinux9 | debian12 | ubuntu22) older=1 ;;
    *) older=0 ;;
esac
root=/var/www/html/moodle
dataroot=/var/moodledata
# PHP writes its errors here, in the one place the web server and the command line may write to under SELinux and AppArmor.
errorlog="$dataroot/secbk-php-errors.log"
extrarepos=none
extensions="curl dom gd iconv intl json mbstring openssl pgsql simplexml soap sodium xml zip zlib ctype"

# Runs a command that reads the mirrors of the distribution, up to three times, a minute apart: a mirror that fails for a
# while (an expired certificate, a sync in progress) is usually not the one that the next attempt gets. Anything else that
# fails fails the same way three times, and then stops the script.
from_mirrors() {
    local attempt
    for attempt in 1 2 3; do
        if "$@"; then
            return 0
        fi
        [ "$attempt" = 3 ] || { echo "a mirror of the distribution failed (attempt $attempt of 3); again in 60 s" >&2; sleep 60; }
    done
    echo "the mirrors of the distribution failed three times: $*" >&2
    return 1
}

# The newest stream of a dnf module, or nothing when the distribution has no such module.
newest_stream() {
    { dnf -q module list "$1" 2> /dev/null || true; } | awk -v name="$1" '$1 == name { print $2 }' | sort -V | tail -n 1
}

# The source repository of a repository of the distribution (appstream, baseos or crb) on the primary server of the
# distribution, for the release of this machine. The mirrors of a source repository can lag hours behind the binary one
# after an update (a run failed on a mirror that listed no source package of the PHP just installed, three times), while the
# primary server is always current. What is read from it is checked as from a mirror: the signature of the distribution and
# the version of the installed PHP.
primary_source_url() {
    local folder
    case "$1" in
        appstream) folder=AppStream ;;
        baseos) folder=BaseOS ;;
        crb) folder=CRB ;;
        *) return 1 ;;
    esac
    case "$ID" in
        rocky) echo "https://dl.rockylinux.org/pub/rocky/$(rpm -E %rhel)/$folder/source/tree/" ;;
        almalinux) echo "https://vault.almalinux.org/$(rpm -E %rhel)/$folder/Source/" ;;
        *) return 1 ;;
    esac
}

# Rocky Linux 9 and AlmaLinux 9 have no php-sodium for PHP 8.1 and later: EPEL's package is for PHP 8.0. The extension is
# part of PHP, so it is built from the source package of the distribution's own PHP, with libsodium-devel from EPEL.
# The source repository is the one that belongs to the repository PHP comes from, read from the primary server of the
# distribution (primary_source_url), and the package is the one that the installed PHP was built from, by name, version and
# release: the binary comes from a mirror, which can be behind the primary server or ahead of the mirror of the sources.
# The source package must be signed by the distribution and must be the version that is installed; and the result must load
# in php-fpm and in the command line.
build_sodium() {
    local work repos repo source srpms srpm installed modules
    work="$(mktemp -d)"
    from_mirrors dnf -y install gcc make autoconf php-devel libsodium-devel rpm-build
    # The whole output is read first: a pipe into head can fail when head ends before dnf does (SIGPIPE under pipefail).
    repos="$(dnf -q repoquery --qf '%{repoid}\n' php-common)"
    repo="${repos%%$'\n'*}"
    [ -n "$repo" ] || { echo "cannot tell which repository PHP comes from" >&2; exit 1; }
    source="$(primary_source_url "$repo")" || { echo "no primary server is known for the source of $repo on $ID" >&2; exit 1; }
    # php-<version>-<release>.src.rpm, without .src.rpm: the name, version and release of the source package to download.
    srpms="$(rpm -q --qf '%{SOURCERPM}\n' php-common)"
    srpm="${srpms%%$'\n'*}"
    srpm="${srpm%.src.rpm}"
    [[ "$srpm" == php-* ]] || { echo "cannot tell which source package PHP was built from" >&2; exit 1; }
    installed="$(php-config --version)"
    (
        set -e
        cd "$work"
        # Only the repository of PHP and its source repository: dnf download --source would also enable the source repository
        # of every other enabled one (EPEL's too), whose mirrors are not needed here and can fail. The source repository is
        # read from the primary server instead of its mirror list, and every attempt reads its metadata again (--refresh):
        # without it, an attempt reuses the list that the first one cached.
        from_mirrors dnf -y --refresh --disablerepo='*' --enablerepo="$repo" --enablerepo="$repo-source" \
            --setopt="$repo-source.baseurl=$source" --setopt="$repo-source.mirrorlist=" --setopt="$repo-source.metalink=" \
            download --source "$srpm"
        signature="$(rpm -K ./php-*.src.rpm)"
        echo "$signature" >&2
        if [[ "$signature" != *'signatures OK'* || "$signature" =~ NOKEY|BAD|'NOT OK' ]]; then
            echo "the source package is not signed by the distribution" >&2
            exit 1
        fi
        rpm2cpio ./php-*.src.rpm | cpio -idm 'php-*.tar.xz'
        [ -f "php-$installed.tar.xz" ] || { echo "the source package is not PHP $installed" >&2; exit 1; }
        tar -xf "php-$installed.tar.xz" --wildcards 'php-*/ext/sodium'
        cd php-*/ext/sodium
        phpize
        ./configure
        make -j2
        make install
    )
    echo 'extension=sodium' > /etc/php.d/20-sodium.ini
    restorecon -R /usr/lib64/php/modules /etc/php.d
    # The modules are read into variables: a pipe into grep -q can fail when grep ends first (SIGPIPE under pipefail).
    modules="$(php -m)"
    [[ $'\n'"$modules"$'\n' == *$'\nsodium\n'* ]] || { echo "sodium does not load in the command line" >&2; exit 1; }
    modules="$(php-fpm -m)"
    [[ $'\n'"$modules"$'\n' == *$'\nsodium\n'* ]] || { echo "sodium does not load in php-fpm" >&2; exit 1; }
    extrarepos="$extrarepos; sodium built from the source package of the distribution's PHP (ext/sodium)"
    rm -rf "$work"
}

install_el() {
    local phpstream pgstream
    from_mirrors dnf -y install dnf-plugins-core
    phpstream="$(newest_stream php)"
    pgstream="$(newest_stream postgresql)"
    [ -z "$phpstream" ] || { from_mirrors dnf -y module reset php; from_mirrors dnf -y module enable "php:$phpstream"; }
    [ -z "$pgstream" ] || {
        from_mirrors dnf -y module reset postgresql
        from_mirrors dnf -y module enable "postgresql:$pgstream"
    }
    # php-fpm only: the "php" package is mod_php, which would run PHP inside Apache. A package AppStream lacks is skipped.
    from_mirrors dnf -y install --setopt=strict=0 httpd git rsync curl php-cli php-fpm php-pgsql php-intl php-mbstring php-xml php-zip \
        php-gd php-soap php-opcache php-sodium postgresql-server policycoreutils-python-utils audit setroubleshoot-server
    # Whatever AppStream did not provide comes from EPEL and CRB, and only that.
    local missing=() unbuilt=() ext
    for ext in $extensions; do
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext" || missing+=("$ext")
    done
    if [ "${#missing[@]}" -gt 0 ]; then
        from_mirrors dnf -y install epel-release
        dnf config-manager --set-enabled crb epel
        for ext in "${missing[@]}"; do
            dnf -y install "php-$ext" || unbuilt+=("$ext")
        done
        extrarepos="epel+crb for ${missing[*]}"
    fi
    for ext in ${unbuilt[@]+"${unbuilt[@]}"}; do
        [ "$ext" = sodium ] || { echo "no package for the PHP extension $ext" >&2; exit 1; }
        build_sodium
    done
    postgresql-setup --initdb
    systemctl enable --now postgresql
}

install_deb() {
    local phpver
    export DEBIAN_FRONTEND=noninteractive
    from_mirrors apt-get update
    from_mirrors apt-get -y install apache2 git rsync curl postgresql apparmor apparmor-utils php-cli php-fpm php-pgsql php-intl php-mbstring \
        php-xml php-zip php-gd php-soap php-curl
    phpver="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    apt-get -y install "php$phpver-opcache" || true
    for ext in $extensions; do
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext" || { echo "missing PHP extension: $ext" >&2; exit 1; }
    done
    for dir in fpm cli; do
        printf '%s\n' 'max_input_vars = 5000' 'log_errors = 1' "error_log = $errorlog" \
            > "/etc/php/$phpver/$dir/conf.d/99-moodle.ini"
    done
    a2enmod proxy proxy_fcgi setenvif
    a2enconf "php$phpver-fpm"
    sed -i "s|DocumentRoot .*|DocumentRoot $docroot|" /etc/apache2/sites-available/000-default.conf
    echo 'AcceptPathInfo On' > /etc/apache2/conf-available/secbk-moodle.conf
    a2enconf secbk-moodle
    systemctl enable --now "php$phpver-fpm" postgresql
    systemctl restart apache2
}

# The newest Moodle branch whose environment file accepts this PHP and PostgreSQL; 4.5 when none does.
# The file of a branch also lists the next version, so the block of the branch's own version is read.
pick_moodle_branch() {
    local pgmajor="$1" branch file xml version
    for branch in 503 502 501 500; do
        version="${branch:0:1}.$((10#${branch:1}))"
        for file in public/admin/environment.xml admin/environment.xml; do
            xml="$(curl -fsS "https://raw.githubusercontent.com/moodle/moodle/MOODLE_${branch}_STABLE/$file")" || continue
            if php -r '
                $xml = simplexml_load_string(stream_get_contents(STDIN));
                $env = $xml->xpath("//MOODLE[@version=\"" . $argv[2] . "\"]")[0];
                $phpmin = (string) $env->PHP["version"];
                $pgmin = (string) $env->xpath("DATABASE/VENDOR[@name=\"postgres\"]")[0]["version"];
                exit(version_compare(PHP_VERSION, $phpmin, ">=") && version_compare($argv[1], $pgmin, ">=") ? 0 : 1);
            ' "$pgmajor" "$version" <<< "$xml"; then
                echo "MOODLE_${branch}_STABLE"
                return 0
            fi
            break
        done
    done
    echo MOODLE_405_STABLE
}

# PHP is needed to choose the branch, so the packages come first; the document root is set after the clone
# (Moodle 5.1 serves from public/).
docroot="$root"
if [ "$family" = el ]; then
    install_el
else
    install_deb
fi

pgmajor="$(sudo -u postgres psql -Atc 'SHOW server_version_num' | cut -c1-2)"
if [ "$older" = 1 ]; then
    branch=MOODLE_405_STABLE
else
    branch="$(pick_moodle_branch "$pgmajor")"
fi
git clone --depth 1 --branch "$branch" https://github.com/moodle/moodle.git "$root"
if [ -d "$root/public" ]; then
    docroot="$root/public"
fi
mkdir -p "$dataroot"
touch "$errorlog"
chown -R "$webuser:$webuser" "$root" "$dataroot"
if [ "$family" = el ]; then
    printf '%s\n' 'max_input_vars = 5000' 'log_errors = 1' "error_log = $errorlog" > /etc/php.d/99-moodle.ini
    # The pool of the distribution sends the errors of the workers to a file of its own.
    sed -i '/^php_admin_value\[error_log\]/d; /^php_admin_flag\[log_errors\]/d' /etc/php-fpm.d/www.conf
    sed -i "s|^DocumentRoot .*|DocumentRoot \"$docroot\"|" /etc/httpd/conf/httpd.conf
    echo 'AcceptPathInfo On' > /etc/httpd/conf.d/secbk-moodle.conf
    # Moodle's data directory is the only place the web server writes to.
    semanage fcontext -a -t httpd_sys_rw_content_t "$dataroot(/.*)?"
    restorecon -R "$dataroot" "$root"
    systemctl enable php-fpm httpd
    systemctl restart php-fpm httpd
else
    sed -i "s|DocumentRoot .*|DocumentRoot $docroot|" /etc/apache2/sites-available/000-default.conf
    systemctl restart apache2
fi

sudo -u postgres psql -c "CREATE USER \"$webuser\";" || true
sudo -u postgres psql -c "CREATE DATABASE moodle OWNER \"$webuser\" ENCODING 'UTF8';" || true
installer="$root/admin/cli/install.php"
[ -f "$installer" ] || installer="$root/public/admin/cli/install.php"
sudo -u "$webuser" php "$installer" --non-interactive --agree-license \
    --wwwroot="http://localhost:$port" --dataroot="$dataroot" --dbtype=pgsql --dbhost= --dbname=moodle \
    --dbuser="$webuser" --fullname="secbackup secure OS" --shortname=secureos --adminpass=test \
    --adminemail=admin@example.com

# Report every PHP error and Moodle debugging message to the log, without showing it on a page (Moodle with debug 0 sets
# error_reporting(0), so nothing would be logged at all).
sed -i '/lib\/setup\.php/i $CFG->debug = (E_ALL | E_STRICT);\n$CFG->debugdisplay = 0;' "$root/config.php"
chown "$webuser:$webuser" "$root/config.php"
cli="$root/admin/cli"
[ -d "$cli" ] || cli="$root/public/admin/cli"
plugin="$root/admin/tool/secbackup"
[ -d "$root/public" ] && plugin="$root/public/admin/tool/secbackup"

versionfile="$root/version.php"
[ -f "$versionfile" ] || versionfile="$root/public/version.php"
{
    echo "os=\"$PRETTY_NAME\""
    echo "template_id=\"$ID$major\""
    echo "php=\"$(php -r 'echo PHP_VERSION;')\""
    echo "postgresql=\"$(sudo -u postgres psql -Atc 'SHOW server_version')\""
    echo "moodle_branch=\"$branch\""
    echo "moodle_release=\"$(sed -n "s/^\\\$release *= *'\\([^']*\\)'.*/\\1/p" "$versionfile")\""
    echo "extra_repositories=\"$extrarepos\""
    echo "web_user=\"$webuser\""
    echo "root=\"$root\""
    echo "admin_cli=\"$cli\""
    echo "plugin_dir=\"$plugin\""
    echo "php_error_log=\"$errorlog\""
    echo "port=\"$port\""
} > /etc/secbk-secureos-info
# Some images start with SELinux in permissive mode (a boot script of the VM manager turns it off for a while), and a test of
# SELinux in permissive mode proves nothing.
if [ "$family" = el ]; then
    setenforce 1
    [ "$(getenforce)" = Enforcing ] || { echo "SELinux is not enforcing" >&2; exit 1; }
fi
# Marks this machine as a test machine (probe.php refuses to run elsewhere).
touch /etc/secbk-secureos-probe
echo "provisioned"
