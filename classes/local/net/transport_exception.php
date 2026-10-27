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

use tool_secbackup\local\plugin_exception;

/**
 * A connection to the service was refused or failed. The error code is a language string of tool_secbackup.
 *
 * The debug information never contains URLs, headers or bodies: later stages send pre-signed URLs,
 * which carry credentials.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transport_exception extends plugin_exception {
    /**
     * @var string The debug information of a connection, or of every lookup over HTTPS, that failed at once (cURL error 7:
     *     refused, unreachable or denied by the system), and so did not time out.
     */
    public const CONNECT_FAILED = 'cURL error 7';

    /** @var string[] The errors whose debug information can say that the connection failed at once. */
    private const CONNECTIONS = ['transport_connect', 'dns_doh_unavailable'];

    /**
     * Whether an error of the plugin is a connection to the service or its storage, or the lookups over HTTPS, that failed at
     * once: the only failure of the network that a denial of the system (SELinux) can be. A timeout and a failed lookup are
     * not.
     *
     * @param plugin_exception $e The error; also one that was made again from its parts.
     * @return bool
     */
    public static function failed_at_connect(plugin_exception $e): bool {
        return in_array($e->errorcode, self::CONNECTIONS, true) && $e->debuginfo === self::CONNECT_FAILED;
    }

    /**
     * Constructor.
     *
     * @param string $errorcode One of the dns_*, transport_* or object_* language strings.
     * @param string|null $debuginfo Short technical detail without secrets, such as "cURL error 60".
     */
    public function __construct(string $errorcode, ?string $debuginfo = null) {
        parent::__construct($errorcode, null, $debuginfo);
    }
}
