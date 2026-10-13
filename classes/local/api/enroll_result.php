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

/**
 * A verified enrolment answer.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enroll_result {
    /** @var string Site ID (UUID). */
    public readonly string $siteid;

    /** @var string Site name on the platform. */
    public readonly string $sitename;

    /** @var string Platform name. */
    public readonly string $platformname;

    /** @var string Connection URL in its normal form. */
    public readonly string $apibase;

    /** @var int The service's time of the enrolment (UNIX time). */
    public readonly int $enrolledat;

    /** @var int The service's clock minus this server's clock, in seconds. */
    public readonly int $clockskew;

    /**
     * Constructor.
     *
     * @param string $siteid Site ID.
     * @param string $sitename Site name.
     * @param string $platformname Platform name.
     * @param string $apibase Connection URL.
     * @param int $enrolledat Time of the enrolment.
     * @param int $clockskew Clock difference.
     */
    public function __construct(
        string $siteid,
        string $sitename,
        string $platformname,
        string $apibase,
        int $enrolledat,
        int $clockskew
    ) {
        $this->siteid = $siteid;
        $this->sitename = $sitename;
        $this->platformname = $platformname;
        $this->apibase = $apibase;
        $this->enrolledat = $enrolledat;
        $this->clockskew = $clockskew;
    }
}
