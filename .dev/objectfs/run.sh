#!/usr/bin/env bash
# One backup run of a site whose files are in an S3-compatible storage through tool_objectfs. Development only, for a
# disposable Ubuntu machine with sudo, Docker, the AWS CLI, Google Chrome and ChromeDriver, PHP and a PostgreSQL server that
# trusts the user postgres on 127.0.0.1 (the job objectfs of .github/workflows/storage.yml; the runner image has them all).
# Usage: .dev/objectfs/run.sh [Moodle branch, default MOODLE_405_STABLE]
#
# The storage is MinIO in a container on 127.0.0.1:9000 (Bitnami's legacy build, see below), with credentials made for the
# run (never printed). tool_objectfs is not part of the plugin: it is fetched at the commit below, which supports Moodle 4.4
# and 4.5 (its version.php says supported = [404, 405]), and needs no other plugin there (Moodle 4.4 and later have the AWS
# SDK). After the site and dummy data (size S): config.php names the file system of tool_objectfs, its tasks copy every
# file to MinIO and delete the local copies, and the locations are counted (in the table of tool_objectfs, in the folder
# filedir and in the bucket). Then,
# against the fake backup service: the status page in Chrome (.dev/objectfs/screen.sh) must show the warning of an external
# file store before the backup is started with its button, cli/backup.php goes on with that backup until it ends, and the
# files of the backup are checked (.dev/fakesec/verify.php): with no local copy left, they can only have been read through
# the file API of Moodle. The results are in $SECBK_STORAGE_DIR/results.txt and the screenshots next to it.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
branch="${1:-MOODLE_405_STABLE}"
# MinIO no longer distributes its server (the image minio/minio is gone from Docker Hub, quay.io wants a login, and the
# binaries of dl.min.io answer 410). This is Bitnami's legacy build of the same release, RELEASE.2025-04-22T22-12-26Z, which
# is no longer updated; it is pinned by its digest as well as its tag. SECBK_MINIO_IMAGE may name another image of MinIO.
minio_tag=bitnamilegacy/minio:2025.4.22-debian-12-r2
minio_digest=sha256:50cec18ac4184af4671a78aedd5554942c8ae105d51a465fa82037949046da01
minio_image="${SECBK_MINIO_IMAGE:-$minio_tag@$minio_digest}"
objectfs_commit=e2ac620651c5ecdec003f1133a3a38e87195d4c9
bucket=secbk-objectfs
# shellcheck source-path=SCRIPTDIR source=../storage/lib.sh
. "$here/../storage/lib.sh"
: > "$SECBK_STORAGE_DIR/results.txt"
dataroot="$SECBK_STORAGE_DIR/moodledata"

echo "== MinIO"
AWS_ACCESS_KEY_ID="secbk$(openssl rand -hex 6)"
AWS_SECRET_ACCESS_KEY="$(openssl rand -hex 24)"
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION=us-east-1
# The image of Bitnami starts the server itself (its entry point), with the data in the container.
docker run -d --name secbk-minio -p 127.0.0.1:9000:9000 -e MINIO_ROOT_USER="$AWS_ACCESS_KEY_ID" \
    -e MINIO_ROOT_PASSWORD="$AWS_SECRET_ACCESS_KEY" -e MINIO_DOMAIN=localhost "$minio_image" > /dev/null
for _ in $(seq 1 120); do
    if curl -fs -o /dev/null http://127.0.0.1:9000/minio/health/live; then break; fi
    sleep 1
done
aws --endpoint-url http://127.0.0.1:9000 s3api create-bucket --bucket "$bucket" > /dev/null
result "minio $minio_image ($(docker image inspect --format '{{.Size}}' "$minio_image") bytes unpacked)"
result "minio server $(docker exec secbk-minio minio --version 2> /dev/null | head -n 1)"

echo "== Moodle ($branch) with tool_objectfs"
storage_moodle "$branch"
objectfs="$(storage_docroot)/admin/tool/objectfs"
git init --quiet "$objectfs"
git -C "$objectfs" fetch --quiet --depth 1 https://github.com/catalyst/moodle-tool_objectfs.git "$objectfs_commit"
git -C "$objectfs" checkout --quiet FETCH_HEAD
version="$(sed -n 's/^\$plugin->version *= *\([0-9]*\).*/\1/p' "$objectfs/version.php")"
result "tool_objectfs $(git -C "$objectfs" rev-parse HEAD) $version"
storage_install "$dataroot"
result "moodle $(storage_php -r 'define("CLI_SCRIPT", true); require "config.php"; echo $CFG->release;')"
storage_php "$(storage_plugin)/.dev/env/generate_site_data.php" --size=S
# The file system of tool_objectfs for the site, and its settings: every file of one byte or more goes to the bucket at once,
# and its local copy is deleted at once. The settings are forced in config.php, so that the credentials are not stored in
# the database and nothing prints them.
storage_config "$(cat <<PHP
// tool_objectfs with MinIO (.dev/objectfs/run.sh).
\$CFG->alternative_file_system_class = '\\tool_objectfs\\s3_file_system';
\$CFG->forced_plugin_settings['tool_objectfs'] = [
    'enabletasks' => 1,
    'filesystem' => '\\tool_objectfs\\s3_file_system',
    's3_key' => '$AWS_ACCESS_KEY_ID',
    's3_secret' => '$AWS_SECRET_ACCESS_KEY',
    's3_bucket' => '$bucket',
    's3_region' => 'us-east-1',
    's3_base_url' => 'http://localhost:9000',
    'sizethreshold' => 0,
    'minimumage' => 0,
    'deletelocal' => 1,
    'consistencydelay' => 0,
    'maxtaskruntime' => 600,
];
PHP
)"
for task in check_objects_location push_objects_to_storage delete_local_objects; do
    sleep 1
    storage_php admin/cli/scheduled_task.php --execute="\\tool_objectfs\\task\\$task" > "$SECBK_STORAGE_DIR/task-$task.log" 2>&1 \
        || { cat "$SECBK_STORAGE_DIR/task-$task.log"; exit 1; }
done
locations="$(storage_php -r 'define("CLI_SCRIPT", true); require "config.php";
    $names = [-2 => "orphaned", -1 => "error", 0 => "local", 1 => "duplicated", 2 => "external"];
    foreach ($DB->get_records_sql("SELECT location, COUNT(1) AS n FROM {tool_objectfs_objects} GROUP BY location") as $row) {
        echo $names[$row->location] ?? $row->location, " ", $row->n, "; ";
    }')"
result "tool_objectfs locations: $locations"
result "files of the site: $(storage_php -r 'define("CLI_SCRIPT", true); require "config.php";
    echo $DB->count_records_sql("SELECT COUNT(DISTINCT contenthash) FROM {files} WHERE filesize > 0");') contents"
result "filedir: $(find "$dataroot/filedir" -type f ! -name warning.txt | wc -l) files"
# What is left there, by content hash and size (tool_objectfs moves only files of more than sizethreshold bytes, here 0).
while read -r left; do
    result "filedir keeps: $left"
done < <(find "$dataroot/filedir" -type f ! -name warning.txt -printf '%f %s bytes\n')
result "bucket: $(aws --endpoint-url http://127.0.0.1:9000 s3 ls --recursive "s3://$bucket" | wc -l) objects"

echo "== The status page, and the backup started with its button"
storage_web_start
storage_fake_start
storage_prepare
screen=0
"$here/screen.sh" "$secbk_wwwroot" "$SECBK_STORAGE_DIR/adminpass" "$SECBK_STORAGE_DIR" \
    "$(storage_plugin)/lang/en/tool_secbackup.php" > "$SECBK_STORAGE_DIR/screen.txt" || screen=$?
cat "$SECBK_STORAGE_DIR/screen.txt"
while read -r line; do result "screen: $line"; done < <(grep '^check ' "$SECBK_STORAGE_DIR/screen.txt")
[ "$screen" -eq 0 ] || exit 1
started="$(date +%s)"
status=0
storage_backup || status=$?
result "backup (the one the button started): exit $status in $(( $(date +%s) - started )) s"
run="$(storage_php -r 'define("CLI_SCRIPT", true); require "config.php";
    $r = $DB->get_records("tool_secbackup_runs", null, "id DESC", "id, triggeredby, status, abortcode, chunksuploaded", 0, 1);
    $r = reset($r); echo "$r->triggeredby $r->status ", $r->abortcode ?? "-", " $r->chunksuploaded chunks";')"
result "run: $run"
result "filedir after the backup: $(find "$dataroot/filedir" -type f ! -name warning.txt | wc -l) files"
[ "$status" -eq 0 ] || exit 1

echo "== The files of the backup"
storage_verify | tee "$SECBK_STORAGE_DIR/verify.txt"
result "verify: $(tail -n 1 "$SECBK_STORAGE_DIR/verify.txt")"
