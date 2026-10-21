#!/usr/bin/env bash
# One backup run of a site whose data directory is on NFS. Development only, for a disposable Ubuntu machine with sudo, PHP
# and a PostgreSQL server that trusts the user postgres on 127.0.0.1 (the job nfs of .github/workflows/storage.yml).
# Usage: .dev/nfs/run.sh <NFS version: 3 or 4.2> [Moodle branch, default MOODLE_503_STABLE]
#
# The NFS server is the kernel's (package nfs-kernel-server) on this machine: it exports /srv/secbk-nfs to 127.0.0.1, and
# the export is mounted at /mnt/secbk-nfs with the version given (hard, over TCP). The site is installed with its data
# directory there (.dev/storage/lib.sh), so every file of the site, the temporary folder of the plugin and Moodle's caches
# are read and written over NFS. Then: dummy data (size S), the read speed that the reason nfs_slow is judged by (the watch
# of the plugin, .dev/nfs/measure.php: three times each with the caches of the system dropped, and three times in one
# process), the same file read with dd through the mount and straight from the exported folder, the data directory as the
# connection diagnostics see it, a backup with cli/backup.php --now against the fake backup service, and the check of the
# files of the backup (.dev/fakesec/verify.php). The results are in $SECBK_STORAGE_DIR/results.txt, also when a step fails.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
version="${1:?usage: run.sh <3|4.2> [Moodle branch]}"
branch="${2:-MOODLE_503_STABLE}"
case "$version" in 3 | 4 | 4.0 | 4.1 | 4.2) ;; *) echo "run.sh: the NFS version is 3, 4, 4.0, 4.1 or 4.2" >&2; exit 2 ;; esac
# shellcheck source-path=SCRIPTDIR source=../storage/lib.sh
. "$here/../storage/lib.sh"
export=/srv/secbk-nfs
mount=/mnt/secbk-nfs
dataroot="$mount/moodledata"
: > "$SECBK_STORAGE_DIR/results.txt"

drop_caches() { sync; echo 3 | sudo tee /proc/sys/vm/drop_caches > /dev/null; }

echo "== NFS server and mount (version $version)"
if ! dpkg -s nfs-kernel-server > /dev/null 2>&1; then
    sudo DEBIAN_FRONTEND=noninteractive apt-get -y -q install nfs-kernel-server > /dev/null
fi
sudo mkdir -p "$export" "$mount"
sudo chown "$(id -u):$(id -g)" "$export"
echo "$export 127.0.0.1(rw,sync,no_subtree_check)" | sudo tee /etc/exports.d/secbk.exports > /dev/null
sudo systemctl restart nfs-server
sudo exportfs -ra
sudo mount -t nfs -o "vers=$version,proto=tcp,hard" "127.0.0.1:$export" "$mount"
result "mount $(findmnt -n -o SOURCE,FSTYPE,OPTIONS --target "$mount")"

echo "== Moodle ($branch) with its data directory on NFS"
storage_moodle "$branch"
storage_install "$dataroot"
result "moodle $(storage_php -r 'define("CLI_SCRIPT", true); require "config.php"; echo $CFG->release;')"
result "dataroot $(findmnt -n -o FSTYPE --target "$dataroot") $dataroot"
storage_php "$(storage_plugin)/.dev/env/generate_site_data.php" --size=S
result "files $(storage_php -r 'define("CLI_SCRIPT", true); require "config.php";
    echo $DB->count_records_select("files", "filesize > 0"), " (", $DB->get_field_sql("SELECT SUM(filesize) FROM {files}"), " bytes)";')"
result "filedir $(find "$dataroot/filedir" -type f | wc -l) files on $(findmnt -n -o FSTYPE --target "$dataroot/filedir")"

echo "== The read speed that nfs_slow is judged by"
for i in 1 2 3; do
    drop_caches
    storage_php "$(storage_plugin)/.dev/nfs/measure.php" | tee "$SECBK_STORAGE_DIR/measure-cold-$i.txt"
    result "cold $i: $(sed -n 's/^nfs_mbps //p' "$SECBK_STORAGE_DIR/measure-cold-$i.txt") MiB/s"
done
storage_php "$(storage_plugin)/.dev/nfs/measure.php" --times=3 | tee "$SECBK_STORAGE_DIR/measure-warm.txt"
result "warm (one process): $(sed -n 's/^nfs_mbps //p' "$SECBK_STORAGE_DIR/measure-warm.txt" | paste -sd ' ' -) MiB/s"
result "limit (minnfsmbps) $(sed -n 's/^limit_mbps //p' "$SECBK_STORAGE_DIR/measure-warm.txt") MiB/s"
read -r _ hash bytes < <(grep '^file ' "$SECBK_STORAGE_DIR/measure-warm.txt")
result "file read: $bytes bytes"
relative="filedir/${hash:0:2}/${hash:2:2}/$hash"
# dd prints its speed on the last line of standard error.
for where in "$dataroot" "$export/moodledata"; do
    drop_caches
    speed="$(dd if="$where/$relative" of=/dev/null bs=1M count=4 2>&1 | tail -n 1 | awk -F, '{print $NF}')"
    result "dd 4 MiB from $where:$speed"
done

echo "== The data directory in the connection diagnostics"
diag="$(storage_php "$(storage_plugin)/cli/diagnose.php" --endpoint=http://localhost:8099/api/lms-backup/v1 || true)"
result "diagnostics: $(printf '%s\n' "$diag" | grep -E '^\[[A-Za-z]+\] Data directory:' | head -n 1)"

echo "== Backup with the fake backup service"
storage_fake_start
storage_prepare
started="$(date +%s)"
status=0
storage_php "$(storage_plugin)/cli/backup.php" --now || status=$?
result "backup: exit $status in $(( $(date +%s) - started )) s"
run="$(storage_php -r 'define("CLI_SCRIPT", true); require "config.php";
    $r = $DB->get_records("tool_secbackup_runs", null, "id DESC", "id, status, abortcode, bytesuploaded, chunksuploaded", 0, 1);
    $r = reset($r); echo "$r->status ", $r->abortcode ?? "-", " $r->chunksuploaded chunks $r->bytesuploaded bytes";')"
result "run: $run"
[ "$status" -eq 0 ] || exit 1

echo "== The files of the backup"
storage_verify | tee "$SECBK_STORAGE_DIR/verify.txt"
result "verify: $(tail -n 1 "$SECBK_STORAGE_DIR/verify.txt")"
