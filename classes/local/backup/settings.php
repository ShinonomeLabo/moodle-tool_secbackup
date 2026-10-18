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

use tool_secbackup\local\api\instruction;
use tool_secbackup\local\trust\envelope_exception;

/**
 * The backup settings of this site, which the backup service sends as signed instructions.
 *
 * They are kept in the plugin's settings as the JSON of the body that was verified, with the sequence number of the
 * instruction. Until the first instruction has been received, the site makes no backup by itself.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings {
    /** @var string[] Intervals of a full backup. */
    private const INTERVALS = ['weekly', 'biweekly', 'monthly'];

    /** @var string[] The fields of a body, sorted. */
    private const FIELDS = ['bandwidth_mbps', 'full_interval', 'include_logs', 'runs_per_day', 'timezone', 'window_end',
        'window_start'];

    /** @var int Minutes in a day. */
    private const DAY = 1440;

    /** @var array|null The verified body, or null before the first instruction. */
    private ?array $body;

    /** @var int Sequence number of the instruction that the body came from. */
    private int $seq;

    /**
     * Constructor.
     *
     * @param array|null $body The verified body, or null.
     * @param int $seq Sequence number.
     */
    private function __construct(?array $body, int $seq) {
        $this->body = $body;
        $this->seq = $seq;
    }

    /**
     * The settings that are stored.
     *
     * A stored value that cannot be read, which should not happen, counts as nothing received.
     *
     * @return self
     */
    public static function current(): self {
        $json = get_config('tool_secbackup', 'backupsettings');
        $seq = get_config('tool_secbackup', 'backupsettingsseq');
        $body = is_string($json) ? json_decode($json, true, 4) : null;
        if (is_array($body) && is_string($seq) && preg_match('/^[1-9]\d{0,17}\z/', $seq) === 1) {
            try {
                return new self(self::validated($body), (int) $seq);
            } catch (envelope_exception $e) {
                return new self(null, 0);
            }
        }
        return new self(null, 0);
    }

    /**
     * Stores the settings of an instruction when it is newer than the stored ones.
     *
     * @param instruction $instruction A settings instruction.
     * @return bool Whether it was newer, and so stored.
     * @throws envelope_exception With the code instruction_invalid, when the body breaks a rule.
     */
    public static function apply(instruction $instruction): bool {
        if ($instruction->kind() !== instruction::KIND_SETTINGS) {
            throw new \coding_exception('Not a settings instruction.');
        }
        $body = self::validated($instruction->body());
        if ($instruction->seq() <= self::current()->seq()) {
            return false;
        }
        set_config('backupsettings', json_encode($body), 'tool_secbackup');
        set_config('backupsettingsseq', $instruction->seq(), 'tool_secbackup');
        return true;
    }

    /**
     * Checks the body of an instruction.
     *
     * @param array $body The body.
     * @return array The body, unchanged.
     * @throws envelope_exception With the code instruction_invalid.
     */
    private static function validated(array $body): array {
        $names = array_keys($body);
        sort($names);
        $start = $body['window_start'] ?? null;
        $end = $body['window_end'] ?? null;
        $runs = $body['runs_per_day'] ?? null;
        $valid = $names === self::FIELDS
            && self::is_time_zone($body['timezone'])
            && self::is_time($start) && self::is_time($end)
            && is_int($runs) && $runs >= 1 && $runs <= 24
            && in_array($body['full_interval'], self::INTERVALS, true)
            && is_int($body['bandwidth_mbps']) && $body['bandwidth_mbps'] >= 0 && $body['bandwidth_mbps'] <= 1000
            && is_bool($body['include_logs']);
        if ($valid) {
            // A window that ends before it starts goes over midnight. Its length may not be 0, and the windows of one day
            // may not be longer than the day.
            $length = (self::minutes($end) - self::minutes($start) + self::DAY) % self::DAY;
            $valid = $length > 0 && $length * $runs <= self::DAY;
        }
        if (!$valid) {
            throw new envelope_exception('instruction_invalid');
        }
        return $body;
    }

    /**
     * Whether a value is a name that PHP reads as a time zone.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_time_zone(mixed $value): bool {
        // The pattern ends with \z: $ also matches before a final line feed. It also keeps null bytes out of the name.
        if (!is_string($value) || preg_match('~^[A-Za-z0-9_+:/-]{1,64}\z~', $value) !== 1) {
            return false;
        }
        try {
            new \DateTimeZone($value);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Whether a value is a time of day on the half hour, such as 02:30.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function is_time(mixed $value): bool {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):(00|30)\z/', $value) === 1;
    }

    /**
     * Minutes after midnight of a time that is_time() accepted.
     *
     * @param string $time The time.
     * @return int
     */
    private static function minutes(string $time): int {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * Whether an instruction has been received.
     *
     * @return bool
     */
    public function is_received(): bool {
        return $this->body !== null;
    }

    /**
     * Sequence number of the stored settings.
     *
     * @return int 0 before the first instruction.
     */
    public function seq(): int {
        return $this->seq;
    }

    /**
     * The time zone of the window.
     *
     * @return \DateTimeZone UTC before the first instruction.
     */
    public function timezone(): \DateTimeZone {
        return new \DateTimeZone($this->body['timezone'] ?? 'UTC');
    }

    /**
     * Start of the first window.
     *
     * @return int Minutes after midnight, from 0 to 1439.
     */
    public function window_start(): int {
        return $this->body === null ? 0 : self::minutes($this->body['window_start']);
    }

    /**
     * End of the first window, which is before the start when the window goes over midnight.
     *
     * @return int Minutes after midnight, from 0 to 1439.
     */
    public function window_end(): int {
        return $this->body === null ? 0 : self::minutes($this->body['window_end']);
    }

    /**
     * Number of backups a day.
     *
     * @return int
     */
    public function runs_per_day(): int {
        return $this->body['runs_per_day'] ?? 1;
    }

    /**
     * Interval of a full backup: weekly, biweekly or monthly.
     *
     * @return string
     */
    public function full_interval(): string {
        return $this->body['full_interval'] ?? 'weekly';
    }

    /**
     * Upper limit of the sending speed.
     *
     * @return int Megabits a second; 0 for no limit.
     */
    public function bandwidth_mbps(): int {
        return $this->body['bandwidth_mbps'] ?? 0;
    }

    /**
     * Whether the log tables are backed up.
     *
     * @return bool
     */
    public function include_logs(): bool {
        return $this->body['include_logs'] ?? false;
    }
}
