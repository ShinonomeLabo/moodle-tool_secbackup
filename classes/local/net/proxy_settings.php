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
 * Moodle's proxy settings, as the "proxy" option of one request.
 *
 * \core\http_client joins the proxy user name and password without the ":" and "@" separators,
 * so the plugin builds the proxy URI itself and sets it on every request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class proxy_settings {
    /**
     * The proxy URI for a URL, or an empty string for a direct connection.
     *
     * @param string $url Request URL.
     * @return string
     */
    public static function for_url(string $url): string {
        global $CFG;
        if (empty($CFG->proxyhost) || is_proxybypass($url)) {
            return '';
        }
        $auth = '';
        if (!empty($CFG->proxyuser) && !empty($CFG->proxypassword)) {
            $auth = rawurlencode($CFG->proxyuser) . ':' . rawurlencode($CFG->proxypassword) . '@';
        }
        return self::scheme() . '://' . $auth . self::host_and_port();
    }

    /**
     * Whether the proxy for a URL looks the host name up itself.
     *
     * An HTTP proxy gets the host name in a CONNECT request and resolves it. With a SOCKS5 proxy this server resolves
     * the name before it talks to the proxy (the scheme is socks5, not socks5h), so the answer of this server counts.
     *
     * @param string $url Request URL.
     * @return bool False for a direct connection and for a SOCKS5 proxy.
     */
    public static function resolves_names(string $url): bool {
        return self::for_url($url) !== '' && self::scheme() === 'http';
    }

    /**
     * A description of the proxy for the diagnostics page, without the credentials.
     *
     * @param string $url Request URL.
     * @return string|null Null for a direct connection.
     */
    public static function describe(string $url): ?string {
        if (self::for_url($url) === '') {
            return null;
        }
        return strtoupper(self::scheme()) . ' ' . self::host_and_port();
    }

    /**
     * Proxy scheme.
     *
     * @return string http or socks5.
     */
    private static function scheme(): string {
        global $CFG;
        return (!empty($CFG->proxytype) && $CFG->proxytype === 'SOCKS5') ? 'socks5' : 'http';
    }

    /**
     * Proxy host and port.
     *
     * @return string
     */
    private static function host_and_port(): string {
        global $CFG;
        return $CFG->proxyhost . (empty($CFG->proxyport) ? '' : ':' . (int) $CFG->proxyport);
    }
}
