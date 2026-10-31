#!/usr/bin/env bash
# Compares a site that restore.sh restored with the site it was backed up from, as the snapshot in the material shows it
# (source-snapshot.json, which compare.php --snapshot wrote on the backed-up site right after make_fixture.php). The restored
# site writes its own snapshot, with the login of secbackup_admin (the password that restore.sh left in
# restore-<project>/admin-password of SECBK_ENV_ROOT), and compare.php compares the two in its container. The snapshot of the
# restored site and the output are also written to restore-<project>/; the files in the container are removed when the
# script ends, also when a step fails.
# Usage: .dev/restore/compare.sh <ver> <db> <material folder>   (SECBK_SOURCE_SITE and SECBK_RESTORE_SITE as for restore.sh)
# Ends with 0 for result: ok.
set -euo pipefail
ver="${1:?usage: compare.sh <ver> <db> <material folder>}"
db="${2:?usage: compare.sh <ver> <db> <material folder>}"
fixture="$(cd "${3:?usage: compare.sh <ver> <db> <material folder>}" && pwd)"
# shellcheck source=lib.sh
source "$(cd "$(dirname "$0")" && pwd)/lib.sh"

for name in fixture.json source-snapshot.json; do
    if [ ! -f "$fixture/$name" ]; then
        echo "$fixture has no $name (see README.md)" >&2
        exit 1
    fi
done
if [ ! -f "$target_work/admin-password" ]; then
    echo "$target_work has no admin-password: restore the site with restore.sh first" >&2
    exit 1
fi
if ! running "$target_project"; then
    echo "the restored site does not run: SECBK_SITE=$target_site .dev/env/up.sh $ver $target_db" >&2
    exit 1
fi
trap 'in_target rm -rf /tmp/secbk-compare > /dev/null 2>&1 || true' EXIT
in_target rm -rf /tmp/secbk-compare
in_target sh -c 'umask 077 && mkdir /tmp/secbk-compare'
host_tar -C "$fixture" -cf - fixture.json source-snapshot.json | in_target tar -C /tmp/secbk-compare -xf -
in_target sh -c 'umask 077 && cat > /tmp/secbk-compare/admin-password' < "$target_work/admin-password"
in_target php "$plugin/.dev/restore/compare.php" --snapshot=/tmp/secbk-compare/target-snapshot.json \
    --login=/tmp/secbk-compare/admin-password
(umask 077 && in_target cat /tmp/secbk-compare/target-snapshot.json > "$target_work/target-snapshot.json")
status=0
in_target php "$plugin/.dev/restore/compare.php" --source=/tmp/secbk-compare/source-snapshot.json \
    --target=/tmp/secbk-compare/target-snapshot.json --fixture=/tmp/secbk-compare/fixture.json \
    > "$target_work/compare.txt" || status=$?
cat "$target_work/compare.txt"
exit "$status"
