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

namespace tool_secbackup\local\api;

use tool_secbackup\local\net\object_url;

/**
 * A verified answer to the start of a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_start {
    /** @var string Run ID (lower-case UUID). */
    private string $runid;

    /** @var object_url Where the list of the chunks that are stored already is read. */
    private object_url $presenturl;

    /** @var int The service's time of the start (UNIX time). */
    private int $startedat;

    /** @var int When the URL stops working (UNIX time). */
    private int $presentexpiresat;

    /**
     * Constructor.
     *
     * @param string $runid Run ID.
     * @param object_url $presenturl URL of the list of stored chunks.
     * @param int $startedat Time of the start.
     * @param int $presentexpiresat Time the URL expires.
     */
    public function __construct(string $runid, object_url $presenturl, int $startedat, int $presentexpiresat) {
        $this->runid = $runid;
        $this->presenturl = $presenturl;
        $this->startedat = $startedat;
        $this->presentexpiresat = $presentexpiresat;
    }

    /**
     * Run ID.
     *
     * @return string
     */
    public function run_id(): string {
        return $this->runid;
    }

    /**
     * URL of the list of the chunks that are stored already.
     *
     * @return object_url
     */
    public function present_url(): object_url {
        return $this->presenturl;
    }

    /**
     * The service's time of the start.
     *
     * @return int UNIX time.
     */
    public function started_at(): int {
        return $this->startedat;
    }

    /**
     * When the URL of the list stops working.
     *
     * @return int UNIX time.
     */
    public function present_url_expires_at(): int {
        return $this->presentexpiresat;
    }
}
