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

namespace tool_secbackup\local\net;

/**
 * A response of the service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class response {
    /** @var int HTTP status. */
    public readonly int $status;

    /** @var string Body. */
    public readonly string $body;

    /** @var int|null Seconds from the Retry-After header (0 to 86400), or null. */
    public readonly ?int $retryafter;

    /**
     * Constructor.
     *
     * @param int $status HTTP status.
     * @param string $body Body.
     * @param int|null $retryafter Seconds from Retry-After.
     */
    public function __construct(int $status, string $body, ?int $retryafter) {
        $this->status = $status;
        $this->body = $body;
        $this->retryafter = $retryafter;
    }
}
