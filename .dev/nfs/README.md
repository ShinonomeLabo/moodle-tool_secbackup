# A data directory on NFS

`run.sh` backs up a site whose data directory is on NFS, with NFS v3 or v4.2, and measures the read speed by which the watch of
a run judges the reason `nfs_slow` (design of the plugin, 6.3, 6.4 and 7.2). Development only: it runs on a disposable Ubuntu
machine with sudo, as the job `nfs` of `.github/workflows/storage.yml` does on a GitHub runner. Nothing in this directory is
part of the plugin package (`.dev` is `export-ignore`d).

    .dev/nfs/run.sh 3       # or 4.2; a second argument names the Moodle branch (MOODLE_503_STABLE by default)

| Step | What happens |
|---|---|
| NFS | The kernel's server (`nfs-kernel-server` from the distribution) exports `/srv/secbk-nfs` to `127.0.0.1` (`rw,sync,no_subtree_check`), and the export is mounted at `/mnt/secbk-nfs` with `vers=<version>,proto=tcp,hard`. |
| Site | Moodle from git with the plugin (`.dev/storage/lib.sh`), installed with its data directory on the mount: the files of the site, the temporary folder of the plugin (`$CFG->tempdir`) and Moodle's caches are all on NFS. PostgreSQL on `127.0.0.1`. Dummy data of size S (`.dev/env/generate_site_data.php`: 60 files, two of 5 to 20 MiB). |
| Speed | `measure.php` calls the watch of the plugin, `monitor::measure_nfs()`: up to 4 MiB of the first stored file of 1 MiB or more, timed from the opening of the file. Three times in a new process each after the caches of the system are dropped (`cold`), and three times in one process (`warm`). For comparison, `dd` reads the first 4 MiB of the same file through the mount and straight from the exported folder, each after the caches are dropped. |
| Diagnostics | The data directory row of `cli/diagnose.php` (it must name the NFS file system and warn). |
| Backup | `.dev/fakesec/prepare.php` with a key pair of the run, then `cli/backup.php --now` with the default limits of the plugin (`minnfsmbps` 5 MB/s). The watch looks at the speed after each chunk it makes (the speed of the run's own reads of files of 1 MiB or more, and before the first one the probe of `measure.php`); a value under the limit stops the run with `nfs_slow`. |
| Check | `.dev/fakesec/verify.php`: every file of the backup, decrypted, has the SHA-1 of its content hash and the size of the site, and the backup holds every content of the site. |

The results are written to `$SECBK_STORAGE_DIR/results.txt` (default `$RUNNER_TEMP/secbk-storage`), which the job prints and
keeps as an artifact with the measurements and the output of the check.

**What the numbers mean.** The server and the client are the same machine, so the network is the loopback interface: the
speeds are those of the NFS protocol and of the runner's disk, not of a network between two servers. A site whose NFS server is
elsewhere will read slower; the default limit of 5 MB/s is far below what was measured here, so the measurement shows that the
watch reads the file through NFS and gets a usable value, not what a real network gives.

## Results

Run 39571659756 of the workflow Storage (2026-10-21, on the plugin of origin/p2 8d8bf01; GitHub's `ubuntu-24.04` runner,
Moodle 5.3+ (Build: 20261013), PHP 8.4, PostgreSQL 17). The file that the watch reads is a file of 12 MiB of the dummy data (it
reads 4 MiB of it). The run 39548262101, on the plugin before 8d8bf01, gave the same outcome with speeds of the same size.

| NFS | Mount options (as `findmnt` shows them) | Cold reads (MiB/s) | Warm reads (MiB/s) | `dd` 4 MiB through the mount / from the export | Backup | Check |
|---|---|---|---|---|---|---|
| v3 | `vers=3,rsize=1048576,wsize=1048576,hard,proto=tcp,timeo=600,retrans=2,sec=sys,local_lock=none` | 496.1, 389.4, 448.5 | 1538.5, 1546.7, 1637.9 | 607 MB/s / 1.6 GB/s | completed in 60 s, 105 chunks, 111,191,796 bytes | 104 of 104 contents, SHA-1 and size match |
| v4.2 | `vers=4.2,rsize=1048576,wsize=1048576,hard,proto=tcp,timeo=600,retrans=2,sec=sys,local_lock=none` | 642.5, 588.1, 611.6 | 1681.4, 1949.5, 1956.1 | 748 MB/s / 1.3 GB/s | completed in 63 s, 105 chunks, 111,191,845 bytes | 104 of 104 contents, SHA-1 and size match |

In both, the connection diagnostics show the data directory as a network file system with the warning (`[Warning] Data
directory: File system: nfs` and `nfs4`), the list of the generation (566 entries: 97 database blocks, 104 files, 3 dataroot
and 362 code entries) has no missing and no unreadable file, and the run was not stopped with `nfs_slow`: the watch measured
the speed after every chunk with the default limit of 5 MB/s, about a hundredth of what was measured here.
