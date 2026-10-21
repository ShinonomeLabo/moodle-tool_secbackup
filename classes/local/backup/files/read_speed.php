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

namespace tool_secbackup\local\backup\files;

/**
 * The speed at which a run reads the file store, from its own reads of files of one MiB or more.
 *
 * A run reads each such file once, so these reads come from the storage and not from the page cache of the server, which
 * answers a file that was read a moment ago (the probe of the monitor reads the same file again and again). A smaller file is
 * mostly the time of opening it. The speed is the bytes over the seconds of the reads of the last 64 MiB at least.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class read_speed {
    /** @var int The smallest file whose read is measured (1 MiB). */
    public const MIN_FILE = 1048576;

    /** @var int Bytes of the newest reads that the speed is taken over (64 MiB). */
    private const WINDOW = 67108864;

    /** @var callable Gives the time in seconds as a float. */
    private $clock;

    /** @var array[] The reads that count, oldest first: [bytes, seconds]. */
    private array $reads = [];

    /** @var int Bytes of the reads that count. */
    private int $bytes = 0;

    /** @var float Seconds of the reads that count. */
    private float $seconds = 0.0;

    /**
     * Constructor.
     *
     * @param callable|null $clock Gives the time in seconds as a float; the monotonic clock of the system when null.
     */
    public function __construct(?callable $clock = null) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1.0E9;
    }

    /**
     * The time in seconds, to measure a read with.
     *
     * @return float
     */
    public function clock(): float {
        return ($this->clock)();
    }

    /**
     * Adds a read.
     *
     * @param int $bytes Bytes that were read.
     * @param float $seconds Seconds that the read took.
     */
    public function add(int $bytes, float $seconds): void {
        $this->reads[] = [$bytes, $seconds];
        $this->bytes += $bytes;
        $this->seconds += $seconds;
        // The oldest read is dropped while the newer ones hold the whole window without it.
        while (count($this->reads) > 1 && $this->bytes - $this->reads[0][0] >= self::WINDOW) {
            [$oldbytes, $oldseconds] = array_shift($this->reads);
            $this->bytes -= $oldbytes;
            $this->seconds -= $oldseconds;
        }
    }

    /**
     * The speed of the reads that count.
     *
     * @return float|null MiB per second; null before anything was read.
     */
    public function mbps(): ?float {
        if ($this->reads === []) {
            return null;
        }
        return $this->bytes / self::MIN_FILE / max($this->seconds, 1.0E-6);
    }
}
