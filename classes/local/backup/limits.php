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

/**
 * The limits that the run engine keeps to: server load, memory, database response, transfer speed and temporary files.
 *
 * They are set on the settings page of the plugin (stored in the plugin's settings). A stored value that is not a number
 * in its range, which the settings page does not allow, counts as not set and gives the default.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class limits {
    /** @var float Highest load per CPU core (the one-minute load average divided by the number of cores). */
    public readonly float $loadpercore;

    /** @var int Least available memory, in MB (1 MB = 1,048,576 bytes). */
    public readonly int $minmemorymb;

    /** @var int Slowest response of the database, in milliseconds. */
    public readonly int $maxdbms;

    /** @var float Slowest read speed of the file storage, in MB per second. */
    public readonly float $minnfsmbps;

    /** @var string Folder of the temporary files; the folder of one run is inside it. */
    public readonly string $tempdir;

    /** @var int Budget of the temporary files in MB; 0 means the smaller of 10% of the free space and 2,048 MB. */
    public readonly int $tempbudgetmb;

    /** @var int Upper limit of the upload speed in megabits per second; 0 means no limit. */
    public readonly int $bandwidthmbps;

    /**
     * Constructor.
     *
     * @param float $loadpercore Highest load per core.
     * @param int $minmemorymb Least available memory in MB.
     * @param int $maxdbms Slowest database response in ms.
     * @param float $minnfsmbps Slowest storage read speed in MB/s.
     * @param string $tempdir Folder of the temporary files.
     * @param int $tempbudgetmb Budget of the temporary files in MB, or 0.
     * @param int $bandwidthmbps Upload speed limit in Mbps, or 0.
     */
    public function __construct(
        float $loadpercore,
        int $minmemorymb,
        int $maxdbms,
        float $minnfsmbps,
        string $tempdir,
        int $tempbudgetmb,
        int $bandwidthmbps
    ) {
        $this->loadpercore = $loadpercore;
        $this->minmemorymb = $minmemorymb;
        $this->maxdbms = $maxdbms;
        $this->minnfsmbps = $minnfsmbps;
        $this->tempdir = $tempdir;
        $this->tempbudgetmb = $tempbudgetmb;
        $this->bandwidthmbps = $bandwidthmbps;
    }

    /**
     * The limits that are set on this site.
     *
     * @return self
     */
    public static function current(): self {
        global $CFG;
        $tempdir = get_config('tool_secbackup', 'tempdir');
        if (!is_string($tempdir) || !self::usable_directory($tempdir)) {
            $tempdir = $CFG->tempdir . '/tool_secbackup';
        }
        return new self(
            self::number('loadpercore', 2.0, 0.1, false),
            (int) self::number('minmemorymb', 256.0, 0.0, true),
            (int) self::number('maxdbms', 500.0, 1.0, true),
            self::number('minnfsmbps', 5.0, 0.0, false),
            rtrim($tempdir, '/\\'),
            (int) self::number('tempbudgetmb', 0.0, 0.0, true),
            (int) self::number('bandwidthmbps', 0.0, 0.0, true)
        );
    }

    /**
     * Whether a folder can be used as the folder of the temporary files: absolute, with no ".." and no control characters.
     *
     * @param string $path The setting.
     * @return bool
     */
    private static function usable_directory(string $path): bool {
        return preg_match('~^(/|[A-Za-z]:[\\\\/])~', $path) === 1
            && preg_match('~[\x00-\x1f\x7f]|(^|[\\\\/])\.\.($|[\\\\/])~', $path) === 0
            && trim($path, '/\\') !== '';
    }

    /**
     * A number setting, or its default.
     *
     * @param string $name Name of the setting.
     * @param float $default Default.
     * @param float $min Least allowed value.
     * @param bool $integer Whether only whole numbers are allowed.
     * @return float
     */
    private static function number(string $name, float $default, float $min, bool $integer): float {
        $value = get_config('tool_secbackup', $name);
        $pattern = $integer ? '/^\d{1,12}\z/' : '/^\d{1,12}(\.\d{1,6})?\z/';
        if (is_string($value) && preg_match($pattern, $value) === 1 && (float) $value >= $min) {
            return (float) $value;
        }
        return $default;
    }
}
