#!/usr/bin/env bash
# Runs inside the Rocky Linux 9 VM: Apache, PHP 8.3 (Remi), PostgreSQL 16 and Moodle 4.5 with SELinux enforcing.
# Development only; not part of the plugin package. Usage (in the VM): sudo bash provision.sh
set -euo pipefail

# Remi's PHP needs libraries from EPEL (libsodium, libraqm, libimagequant, libavif) and CRB.
dnf -y install epel-release dnf-plugins-core
dnf config-manager --set-enabled crb epel
dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf -y module reset php
dnf -y module enable php:remi-8.3 postgresql:16
# curl and openssl are in php-common.
dnf -y install httpd git rsync php php-pgsql php-intl php-mbstring php-sodium php-xml php-zip php-gd php-soap php-opcache \
    postgresql-server policycoreutils-python-utils setroubleshoot-server audit

postgresql-setup --initdb
systemctl enable --now postgresql
sudo -u postgres psql -c "CREATE USER apache;" || true
sudo -u postgres psql -c "CREATE DATABASE moodle OWNER apache ENCODING 'UTF8';" || true

git clone --depth 1 --branch MOODLE_405_STABLE https://github.com/moodle/moodle.git /var/www/html/moodle
mkdir -p /var/moodledata
chown -R apache:apache /var/www/html/moodle /var/moodledata
semanage fcontext -a -t httpd_sys_rw_content_t '/var/moodledata(/.*)?'
restorecon -R /var/moodledata /var/www/html/moodle

sed -i 's|^DocumentRoot "/var/www/html"|DocumentRoot "/var/www/html/moodle"|' /etc/httpd/conf/httpd.conf
echo 'max_input_vars = 5000' > /etc/php.d/99-moodle.ini
systemctl enable --now httpd

sudo -u apache php /var/www/html/moodle/admin/cli/install.php --non-interactive --agree-license \
    --wwwroot=http://localhost:18080 --dataroot=/var/moodledata --dbtype=pgsql --dbhost= --dbname=moodle \
    --dbuser=apache --fullname="secbackup SELinux" --shortname=selinux --adminpass=test --adminemail=admin@example.com

# Marks this machine as the SELinux test machine (probe.php refuses to run elsewhere).
touch /etc/secbk-secureos-probe
echo "provisioned"
