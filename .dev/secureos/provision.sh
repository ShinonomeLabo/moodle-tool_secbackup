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
extrarepos=none
extensions="curl dom gd iconv intl json mbstring openssl pgsql simplexml soap sodium xml zip zlib ctype"

# The newest stream of a dnf module, or nothing when the distribution has no such module.
newest_stream() {
    dnf -q module list "$1" 2> /dev/null | awk -v name="$1" '$1 == name { print $2 }' | sort -V | tail -n 1
}

install_el() {
    local phpstream pgstream
    dnf -y install dnf-plugins-core
    phpstream="$(newest_stream php)"
    pgstream="$(newest_stream postgresql)"
    [ -z "$phpstream" ] || { dnf -y module reset php; dnf -y module enable "php:$phpstream"; }
    [ -z "$pgstream" ] || { dnf -y module reset postgresql; dnf -y module enable "postgresql:$pgstream"; }
    # php-fpm only: the "php" package is mod_php, which would run PHP inside Apache. A package AppStream lacks is skipped.
    dnf -y install --setopt=strict=0 httpd git rsync curl php-cli php-fpm php-pgsql php-intl php-mbstring php-xml php-zip \
        php-gd php-soap php-opcache php-sodium postgresql-server policycoreutils-python-utils audit setroubleshoot-server
    # Whatever AppStream did not provide comes from EPEL and CRB, and only that.
    local missing=()
    for ext in $extensions; do
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext" || missing+=("php-$ext")
    done
    if [ "${#missing[@]}" -gt 0 ]; then
        dnf -y install epel-release
        dnf config-manager --set-enabled crb epel
        dnf -y install "${missing[@]}"
        extrarepos="epel+crb for ${missing[*]}"
    fi
    postgresql-setup --initdb
    systemctl enable --now postgresql
}

install_deb() {
    local phpver
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get -y install apache2 git rsync curl postgresql apparmor apparmor-utils php-cli php-fpm php-pgsql php-intl php-mbstring \
        php-xml php-zip php-gd php-soap php-curl
    phpver="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    apt-get -y install "php$phpver-opcache" || true
    for ext in $extensions; do
        php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext" || { echo "missing PHP extension: $ext" >&2; exit 1; }
    done
    for dir in fpm cli; do
        echo 'max_input_vars = 5000' > "/etc/php/$phpver/$dir/conf.d/99-moodle.ini"
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
chown -R "$webuser:$webuser" "$root" "$dataroot"
if [ "$family" = el ]; then
    echo 'max_input_vars = 5000' > /etc/php.d/99-moodle.ini
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
    echo "port=\"$port\""
} > /etc/secbk-secureos-info
# Marks this machine as a test machine (probe.php refuses to run elsewhere).
touch /etc/secbk-secureos-probe
echo "provisioned"
