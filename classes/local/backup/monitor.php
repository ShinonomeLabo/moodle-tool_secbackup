<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_secbackup\local\backup;

use tool_secbackup\local\diagnostics\system_files;

/**
 * The watch on the server while a run goes on: load, memory, response of the database, free space and the file storage.
 *
 * A value that cannot be read is null: it is never guessed, and never a PHP warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class monitor {
    /** @var string[] The reasons that verdict() gives; each is also the code of the error that stops a run. */
    public const REASONS = ['server_busy', 'disk_low', 'db_slow', 'nfs_slow'];

    /** @var int One MiB. */
    private const MIB = 1048576;

    /** @var int One GiB. */
    private const GIB = 1073741824;

    /** @var int The most that is read from a file to measure the speed of the file storage. */
    private const NFS_READ_BYTES = 4 * self::MIB;

    /** @var int Smallest file that is used to measure the speed of the file storage. */
    private const NFS_MIN_FILE_BYTES = self::MIB;

    /** @var int Seconds to wait between the steps of a run when something cannot be read. */
    private const UNKNOWN_PAUSE = 30;

    /** @var int Most cores that are believed from the list of CPUs that are online. */
    private const MAX_CORES = 65536;

    /** @var system_files Reader of /proc and /sys. */
    private system_files $files;

    /** @var limits The limits. */
    private limits $limits;

    /** @var callable Clock: the time in seconds as a float. */
    private $clock;

    /** @var callable Function that gives [free bytes, total bytes] of the disk that holds a folder. */
    private $diskspace;

    /** @var \file_system|null File system of Moodle; the one of the site when null. */
    private ?\file_system $filesystem;

    /** @var int|null ID of the file that the speed is measured with; 0 when there is none; null before the first choice. */
    private ?int $fileid = null;

    /**
     * Constructor.
     *
     * @param system_files $files Reader of /proc and /sys.
     * @param limits $limits The limits.
     * @param callable|null $clock Function () that returns the time in seconds as a float; microtime(true) when null.
     * @param callable|null $diskspace Function (string $folder) that returns [free bytes, total bytes]; the disk when null.
     * @param \file_system|null $filesystem File system of Moodle; the one of the site when null.
     */
    public function __construct(
        system_files $files,
        limits $limits,
        ?callable $clock = null,
        ?callable $diskspace = null,
        ?\file_system $filesystem = null
    ) {
        $this->files = $files;
        $this->limits = $limits;
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->diskspace = $diskspace ?? [temp_area::class, 'disk_space'];
        $this->filesystem = $filesystem;
    }

    /**
     * The watch of this server with the limits that are set.
     *
     * @return self
     */
    public static function create(): self {
        return new self(new system_files(), limits::current());
    }

    /**
     * The limits that this watch keeps to.
     *
     * @return limits
     */
    public function limits(): limits {
        return $this->limits;
    }

    /**
     * The free and the total space of the disk that holds the temporary folder.
     *
     * @return array{0: ?int, 1: ?int} Free and total bytes; null for what cannot be read.
     */
    public function disk_space(): array {
        return ($this->diskspace)($this->limits->tempdir);
    }

    /**
     * Reads everything.
     *
     * disk_total_bytes is the total size of the disk of the temporary folder: verdict() needs it for the floor of the free
     * space.
     *
     * not_applicable lists the keys that cannot apply to this site, so that a null there is not "unreadable": nfs_mbps when the
     * file system is not the local one (an object store, for example) or when no file of 1 MiB or more is stored.
     *
     * @return array{load_per_core: ?float, memory_available_bytes: ?int, db_ms: ?float, disk_free_bytes: ?int,
     *     disk_total_bytes: ?int, nfs_mbps: ?float, not_applicable: string[]}
     */
    public function sample(): array {
        [$free, $total] = $this->disk_space();
        [$nfs, $nfsapplies] = $this->probe_nfs();
        return [
            'load_per_core' => $this->load_per_core(),
            'memory_available_bytes' => $this->memory_available_bytes(),
            'db_ms' => $this->measure_db(),
            'disk_free_bytes' => $free,
            'disk_total_bytes' => $total,
            'nfs_mbps' => $nfs,
            'not_applicable' => $nfsapplies ? [] : ['nfs_mbps'],
        ];
    }

    /**
     * Why the run should stop for now.
     *
     * The order is the order of the importance: the server, the disk, the database, the file storage.
     * A value that is null is not judged. The free space may be at the floor, which is the larger of 10% of the disk
     * and 5 GB, and not below it.
     *
     * @param array $sample Result of sample().
     * @param limits $limits The limits.
     * @return string|null server_busy, disk_low, db_slow or nfs_slow; null when nothing is wrong.
     */
    public static function verdict(array $sample, limits $limits): ?string {
        $load = $sample['load_per_core'] ?? null;
        $memory = $sample['memory_available_bytes'] ?? null;
        $free = $sample['disk_free_bytes'] ?? null;
        $total = $sample['disk_total_bytes'] ?? null;
        $db = $sample['db_ms'] ?? null;
        $nfs = $sample['nfs_mbps'] ?? null;
        if (($load !== null && $load > $limits->loadpercore) || ($memory !== null && $memory < $limits->minmemorymb * self::MIB)) {
            return 'server_busy';
        }
        if ($free !== null && $free < max(intdiv($total ?? 0, 10), 5 * self::GIB)) {
            return 'disk_low';
        }
        if ($db !== null && $db > $limits->maxdbms) {
            return 'db_slow';
        }
        return $nfs !== null && $nfs < $limits->minnfsmbps ? 'nfs_slow' : null;
    }

    /**
     * How long a run waits between its steps: 30 seconds when a value cannot be read (the server is then spared), else 0.
     *
     * A key in not_applicable is not a value that cannot be read: it is left out. The function only answers; how often the
     * pause is taken is up to the caller.
     *
     * @param array $sample Result of sample().
     * @return int Seconds.
     */
    public static function pause_seconds(array $sample): int {
        $ignored = $sample['not_applicable'] ?? [];
        foreach (['load_per_core', 'memory_available_bytes', 'db_ms', 'disk_free_bytes', 'nfs_mbps'] as $key) {
            if (!isset($sample[$key]) && !in_array($key, $ignored, true)) {
                return self::UNKNOWN_PAUSE;
            }
        }
        return 0;
    }

    /**
     * The time that the database needs for one trivial read.
     *
     * @return float Milliseconds.
     */
    public function measure_db(): float {
        global $DB;
        $start = ($this->clock)();
        $DB->get_field_sql('SELECT 1');
        return (($this->clock)() - $start) * 1000.0;
    }

    /**
     * The speed at which a file of the file storage is read: up to 4 MiB of one file of 1 MiB or more.
     *
     * Only the local file system of Moodle is measured: a file system of another kind (an object store, for example) is not
     * the disk that the site reads its files from.
     *
     * @return float|null MiB per second; null when there is no such file, it cannot be read or the file system is not local.
     */
    public function measure_nfs(): ?float {
        return $this->probe_nfs()[0];
    }

    /**
     * Measures the speed of the file storage and says whether it can be measured on this site at all.
     *
     * The file is chosen by one query that is not indexed, so it is done once for the life of the monitor, also when there is
     * no such file. A chosen file that has been removed since is forgotten and a new one is chosen the next time.
     *
     * @return array{0: ?float, 1: bool} The speed in MiB per second, and false when the speed does not apply to this site
     *     (the file system is not the local one, or no file of 1 MiB or more is stored).
     */
    private function probe_nfs(): array {
        global $DB;
        $filesystem = $this->filesystem ?? get_file_storage()->get_file_system();
        if (get_class($filesystem) !== \file_system_filedir::class) {
            return [null, false];
        }
        if ($this->fileid === null) {
            $rows = $DB->get_records_sql(
                'SELECT id FROM {files} WHERE filesize >= ? ORDER BY id',
                [self::NFS_MIN_FILE_BYTES],
                0,
                1
            );
            $this->fileid = $rows ? (int) key($rows) : 0;
        }
        if ($this->fileid === 0) {
            return [null, false];
        }
        $file = get_file_storage()->get_file_by_id($this->fileid);
        if (!$file) {
            $this->fileid = null;
            return [null, true];
        }
        set_error_handler(static fn(int $errno, string $errstr): bool => true);
        try {
            // The clock starts before the file is opened: the time to open it, on a network disk, is part of the speed.
            $start = ($this->clock)();
            $handle = $filesystem->get_content_file_handle($file);
            $content = $handle ? stream_get_contents($handle, self::NFS_READ_BYTES) : '';
            $seconds = ($this->clock)() - $start;
            if ($handle) {
                fclose($handle);
            }
        } finally {
            restore_error_handler();
        }
        $speed = $content === '' || $content === false ? null : strlen($content) / self::MIB / max($seconds, 1.0E-6);
        return [$speed, true];
    }

    /**
     * The one-minute load average divided by the number of CPUs that are online.
     *
     * @return float|null Null when either cannot be read.
     */
    public function load_per_core(): ?float {
        $loadavg = $this->files->read('/proc/loadavg');
        $cores = $this->cores();
        if ($loadavg === null || $cores === null || preg_match('/^(\d+(?:\.\d+)?)\s/', $loadavg, $m) !== 1) {
            return null;
        }
        return (float) $m[1] / $cores;
    }

    /**
     * The number of CPUs that are online.
     *
     * @return int|null Null when /sys/devices/system/cpu/online cannot be read as a list such as 0-3,8.
     */
    private function cores(): ?int {
        $online = $this->files->read('/sys/devices/system/cpu/online');
        if ($online === null || preg_match('/^\d+(-\d+)?(,\d+(-\d+)?)*\s*\z/', $online) !== 1) {
            return null;
        }
        $cores = 0;
        foreach (explode(',', trim($online)) as $part) {
            [$from, $to] = array_map('intval', explode('-', $part)) + [1 => (int) $part];
            if ($to < $from) {
                return null;
            }
            $cores += $to - $from + 1;
        }
        return $cores > self::MAX_CORES ? null : $cores;
    }

    /**
     * The memory that a program can still get, from MemAvailable of /proc/meminfo.
     *
     * @return int|null Bytes; null when it cannot be read.
     */
    public function memory_available_bytes(): ?int {
        $meminfo = $this->files->read('/proc/meminfo');
        if ($meminfo === null || preg_match('/^MemAvailable:\s+(\d{1,15}) kB$/m', $meminfo, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 1024;
    }
}
