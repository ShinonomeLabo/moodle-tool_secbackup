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
 * A status answer.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_result {
    /** @var string Status of the site at the service, such as active. */
    public readonly string $status;

    /** @var int The service's clock (UNIX time). */
    public readonly int $servertime;

    /** @var int The service's clock minus this server's clock, in seconds. */
    public readonly int $clockskew;

    /** @var array The instruction envelopes of the answer, as they came. Nothing here is verified yet. */
    public readonly array $instructions;

    /**
     * Constructor.
     *
     * @param string $status Status.
     * @param int $servertime The service's clock.
     * @param int $clockskew Clock difference.
     * @param array $instructions The instruction envelopes.
     */
    public function __construct(string $status, int $servertime, int $clockskew, array $instructions) {
        $this->status = $status;
        $this->servertime = $servertime;
        $this->clockskew = $clockskew;
        $this->instructions = $instructions;
    }
}
