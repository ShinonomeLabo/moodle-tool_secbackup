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

use tool_secbackup\local\plugin_exception;

/**
 * The service refused a request, or its answer could not be used.
 *
 * The error code is a language string of tool_secbackup. The service's own message is never kept.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends plugin_exception {
    /** @var string Error code given by the service, or an empty string. */
    public readonly string $servercode;

    /** @var int HTTP status, or 0. */
    public readonly int $httpstatus;

    /** @var int|null Seconds to wait before trying again, from Retry-After. */
    public readonly ?int $retryafter;

    /** @var int|null The service's clock (UNIX time), when it gave it. */
    public readonly ?int $servertime;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string.
     * @param string $servercode Error code given by the service.
     * @param int $httpstatus HTTP status.
     * @param int|null $retryafter Seconds from Retry-After.
     * @param int|null $servertime The service's clock.
     * @param mixed $a Value for the language string.
     * @param string|null $debuginfo Detail without secrets.
     */
    public function __construct(
        string $errorcode,
        string $servercode = '',
        int $httpstatus = 0,
        ?int $retryafter = null,
        ?int $servertime = null,
        mixed $a = null,
        ?string $debuginfo = null
    ) {
        parent::__construct($errorcode, $a, $debuginfo);
        $this->servercode = $servercode;
        $this->httpstatus = $httpstatus;
        $this->retryafter = $retryafter;
        $this->servertime = $servertime;
    }
}
