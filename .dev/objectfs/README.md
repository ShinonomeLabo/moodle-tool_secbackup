# Files in an external storage (tool_objectfs)

`run.sh` backs up a site whose files are in an S3-compatible storage through `tool_objectfs`, the case of the design of the
plugin, 7.1: the status page must warn about the external store in its dialog before a backup is started from the page, and
the backup must read the files through Moodle's file API. Development only: it runs on a disposable Ubuntu machine with sudo,
Docker, the AWS CLI, Google Chrome and ChromeDriver, as the job `objectfs` of `.github/workflows/storage.yml` does on a GitHub
runner (whose image has them). Nothing in this directory is part of the plugin package (`.dev` is `export-ignore`d), and
`tool_objectfs` is never part of it either.

    .dev/objectfs/run.sh            # a second Moodle branch can be given; MOODLE_405_STABLE by default

| Step | What happens |
|---|---|
| Storage | MinIO in a container on `127.0.0.1:9000`, with credentials made for the run and never printed; one bucket made with the AWS CLI. |
| Site | Moodle 4.5 from git with the plugin and `tool_objectfs` at a pinned commit (`e2ac620`, branch `MOODLE_404_STABLE`, whose `version.php` says `supported = [404, 405]`; Moodle 4.4 and later bring the AWS SDK, so `local_aws` is not needed). Dummy data of size S. |
| Files moved | `config.php` names `\tool_objectfs\s3_file_system` as the file system of the site and forces the settings of `tool_objectfs` (every file of one byte or more is copied at once, and its local copy is deleted at once). The tasks `check_objects_location`, `push_objects_to_storage` and `delete_local_objects` run once each; the locations in the table of `tool_objectfs`, the files left in `filedir` and the objects in the bucket are counted. |
| Status page | `screen.sh` drives Chrome with ChromeDriver over the WebDriver protocol (curl and jq only): it logs in, opens the status page, waits for the dialog, compares its text with the English strings `modal_external_title` and `modal_external` of the plugin (with the platform name of the fake service), saves `1-modal.png`, closes the dialog with OK, presses "Back up now" and waits for the answer `start_started` (`2-started.png`). |
| Backup | `cli/backup.php` (without `--now`) goes on with the backup that the button started, until it ends. |
| Check | `.dev/fakesec/verify.php`: every file of the backup, decrypted, has the SHA-1 of its content hash and the size of the site, and the backup holds every content of the site. With no local copy left in `filedir`, the files can only have been read from the bucket, through the file API. |

The results are written to `$SECBK_STORAGE_DIR/results.txt` (default `$RUNNER_TEMP/secbk-storage`), which the job prints and
keeps as an artifact with the two screenshots and the output of the check.

## Results

| CI run | MinIO | tool_objectfs | Locations after the tasks | `filedir` | Dialog | Started by the button | Backup | Check |
|---|---|---|---|---|---|---|---|---|
| | | | | | | | | |
