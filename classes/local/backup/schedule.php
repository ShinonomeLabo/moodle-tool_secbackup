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
 * The time slots in which the backup service wants this site to make a backup.
 *
 * The window is given in a time zone; with several runs a day it repeats every 1440 / runs minutes from its start. A site
 * starts a little after the beginning of a slot, by a delay that depends on its ID, so that the sites of one service do not
 * all start at the same minute.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule {
    /** @var int Minutes in a day. */
    private const DAY = 1440;

    /** @var int The longest delay of the start, in minutes. */
    private const MAX_JITTER = 30;

    /**
     * The slot that contains a time.
     *
     * @param settings $s The settings.
     * @param string $siteid The ID of this site.
     * @param int $now The current time.
     * @return array{start: int, end: int}|null The UNIX times of the start (with the delay) and the end; null outside a slot.
     */
    public static function slot_at(settings $s, string $siteid, int $now): ?array {
        $length = ($s->window_end() - $s->window_start() + self::DAY) % self::DAY;
        $runs = $s->runs_per_day();
        $step = intdiv(self::DAY, $runs);
        $jitter = self::jitter($siteid, $length);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($s->timezone())->setTime(0, 0);
        // A slot that began yesterday may still be going on after midnight.
        foreach ([$today->modify('-1 day'), $today] as $midnight) {
            for ($k = 0; $k < $runs; $k++) {
                $from = $s->window_start() + $k * $step;
                $start = self::at($midnight, $from + $jitter);
                // The settings keep length * runs within a day, so a slot ends with its window before the next one begins.
                $end = self::at($midnight, $from + $length);
                if ($start <= $now && $now < $end) {
                    return ['start' => $start, 'end' => $end];
                }
            }
        }
        return null;
    }

    /**
     * The first slot that starts at or after a time.
     *
     * @param settings $s The settings.
     * @param string $siteid The ID of this site.
     * @param int $from The time.
     * @return array{start: int, end: int} The UNIX times of the start (with the delay) and the end.
     */
    public static function next_slot(settings $s, string $siteid, int $from): array {
        $length = ($s->window_end() - $s->window_start() + self::DAY) % self::DAY;
        $runs = $s->runs_per_day();
        $step = intdiv(self::DAY, $runs);
        $jitter = self::jitter($siteid, $length);
        $today = (new \DateTimeImmutable('@' . $from))->setTimezone($s->timezone())->setTime(0, 0);
        // The starts go up from the first slot of yesterday to the last of today (the slots of a day fit in the day).
        foreach ([$today->modify('-1 day'), $today] as $midnight) {
            for ($k = 0; $k < $runs; $k++) {
                $start = self::at($midnight, $s->window_start() + $k * $step + $jitter);
                if ($start >= $from) {
                    return ['start' => $start, 'end' => self::at($midnight, $s->window_start() + $k * $step + $length)];
                }
            }
        }
        $tomorrow = $today->modify('+1 day');
        return [
            'start' => self::at($tomorrow, $s->window_start() + $jitter),
            'end' => self::at($tomorrow, $s->window_start() + $length),
        ];
    }

    /**
     * Whether a run is due: a slot is going on and no run has been started since it began.
     *
     * @param settings $s The settings.
     * @param string $siteid The ID of this site.
     * @param int $now The current time.
     * @param int|null $laststart When the latest run of any kind was started, or null when there is none.
     * @return bool
     */
    public static function due(settings $s, string $siteid, int $now, ?int $laststart): bool {
        $slot = self::slot_at($s, $siteid, $now);
        return $slot !== null && ($laststart === null || $laststart < $slot['start']);
    }

    /**
     * The window of the settings as the administrator reads it: its start, its end, its time zone and how many times a day it
     * comes.
     *
     * @param settings $s The settings.
     * @return string Plain text; "not received yet" before the settings arrive.
     */
    public static function window_text(settings $s): string {
        if (!$s->is_received()) {
            return get_string('schedule_window_none', 'tool_secbackup');
        }
        $a = (object) [
            'from' => sprintf('%02d:%02d', intdiv($s->window_start(), 60), $s->window_start() % 60),
            'to' => sprintf('%02d:%02d', intdiv($s->window_end(), 60), $s->window_end() % 60),
            'timezone' => $s->timezone()->getName(),
            'runs' => $s->runs_per_day(),
        ];
        return get_string($a->runs > 1 ? 'schedule_window_runs' : 'schedule_window', 'tool_secbackup', $a);
    }

    /**
     * The delay of the start of a slot.
     *
     * The first 4 bytes of the SHA-256 of the site ID, as an unsigned big-endian number, modulo the smaller of a quarter of
     * the window and 30 minutes. The same site gets the same delay every day.
     *
     * @param string $siteid The ID of this site.
     * @param int $windowminutes The length of the window in minutes.
     * @return int Minutes.
     */
    public static function jitter(string $siteid, int $windowminutes): int {
        $divisor = min(intdiv($windowminutes, 4), self::MAX_JITTER);
        if ($divisor === 0) {
            return 0;
        }
        $number = unpack('N', substr(hash('sha256', $siteid, true), 0, 4))[1];
        return $number % $divisor;
    }

    /**
     * A time of a day in the time zone of the day.
     *
     * @param \DateTimeImmutable $midnight The start of the day.
     * @param int $minutes Minutes after it; more than a day goes over to the next day.
     * @return int UNIX time.
     */
    private static function at(\DateTimeImmutable $midnight, int $minutes): int {
        return $midnight->setTime(intdiv($minutes, 60), $minutes % 60)->getTimestamp();
    }
}
