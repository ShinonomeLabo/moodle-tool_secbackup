# SELinux test machine

SELinux cannot be reproduced in a Docker container: containers share the host kernel and run as container_t.
This Rocky Linux 9 VM (Lima) runs Apache as httpd_t, like university servers.

    limactl create --name=secbk-selinux --cpus=2 --memory=4 --disk=30 \
        --set '.portForwards=[{"guestPort":80,"hostPort":18080}]' template:rocky-9
    limactl start secbk-selinux
    limactl shell secbk-selinux sudo bash "$PWD/.dev/selinux/provision.sh"
    .dev/selinux/sync-plugin.sh
    .dev/selinux/check.sh <connection URL of a real service>

Moodle: http://localhost:18080 (admin / test). Use `localhost`, not `127.0.0.1`: the site's wwwroot is
`http://localhost:18080`, and Moodle redirects other host names. The database is PostgreSQL on a Unix socket.

The image has PHP 8.3 from Remi, which needs libraries from EPEL and CRB (provision.sh enables both).
`ausearch` reads standard input when it is a pipe, so check.sh runs it with `--input-logs`.

## Results (2026-10-15)

Rocky Linux 9.8 (aarch64), Moodle 4.5.15, PHP 8.3.35, SELinux Enforcing, against a real service
(unsigned GET of the status path; nothing is changed there).

| Case | Web (httpd_t) | Command line |
|---|---|---|
| Both booleans off | `dns` fails: neither DNS over HTTPS service answered (`dns_doh_unavailable`); `connection` fails with the same text. Both carry the SELinux advice. | All pass: `Connection to the service: ... (HTTP 401)`. Process type `unconfined_t`. |
| httpd_can_network_relay on | `dns` and `connection` pass (HTTP 401). Enough on its own. | - |
| httpd_can_network_connect on | `dns` and `connection` pass (HTTP 401). | - |
| /proc/self/attr/current and /sys/fs/selinux/enforce from httpd_t | Readable: `Enforcing. Process type: httpd_t.` | Readable: `unconfined_t` |
| AVC denials (`ausearch --input-logs -m avc -ts recent`) | `name_connect` denied from `httpd_t` (php-fpm) to port 443, `http_port_t`, 12 records | - |
| PHP warnings in the web server log | 0 | - |

The web server and the command line are treated differently: `sudo -u apache php cli/diagnose.php` does not run
as httpd_t, so the same settings connect from the command line and not from a page. A passing command-line
diagnosis does not show that the web server can connect.

The advice text names `httpd_can_network_relay` first (HTTPS only) and `httpd_can_network_connect` second.
