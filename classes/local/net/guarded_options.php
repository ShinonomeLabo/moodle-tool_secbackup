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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use tool_secbackup\local\trust\developer_settings;

/**
 * The checks that every connection of the plugin goes through, whatever it carries: the name check, a connection pinned to the
 * checked address, only the bundled Amazon root certificates, Moodle's HTTP security list, and the mapping of a failure of the
 * HTTP client to an error code without URLs or bodies. transport (the service) and object_transport (the storage) both use it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class guarded_options {
    /** @var int[] curl error numbers of failed TLS handshakes and certificate checks. */
    private const TLS_ERRORS = [35, 51, 58, 60, 77, 83, 90, 91];

    /**
     * @var int[] curl error numbers of failed or timed-out connections. 97 is a failed handshake with a SOCKS proxy, which
     *     libcurl before 7.73.0 reports as 7.
     */
    private const CONNECT_ERRORS = [5, 6, 7, 28, 52, 55, 56, 97];

    /**
     * Adds the name check, the pinned address and the pinned roots to the options of a request.
     *
     * The types of $url and $options are written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * transport::send()). The attributes keep the URL, which can be a pre-signed one, and the options, which hold the headers
     * and the body of the request, out of the trace of the exception that a failed name check throws.
     *
     * @param developer_settings $settings Developer settings.
     * @param dns_guard $guard Name check.
     * @param #[|\SensitiveParameter|]|string $url Request URL (decides whether a proxy looks the name up).
     * @param string $host Host name of the URL.
     * @param int $port Port of the URL.
     * @param string $service AWS service the host must belong to.
     * @param #[|\SensitiveParameter|]|array $options Options of the request, with the "curl" option set.
     * @return array The options.
     * @throws transport_exception When the name check fails.
     */
    public static function apply(
        developer_settings $settings,
        dns_guard $guard,
        #[\SensitiveParameter] string $url,
        string $host,
        int $port,
        string $service,
        #[\SensitiveParameter] array $options
    ): array {
        if ($settings->skip_network_checks()) {
            return $options;
        }
        // Only an HTTP proxy looks the name up. With a SOCKS5 proxy this server does it, so its answer is checked and pinned.
        $pinned = $guard->check($host, proxy_settings::resolves_names($url), $service);
        $options['verify'] = transport::roots_file();
        // A folder without "<hash>.0" files: curl must not add the system's CA directory to the bundled roots.
        $options['curl'][CURLOPT_CAPATH] = dirname(transport::roots_file());
        if ($pinned !== null) {
            $options['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $pinned];
        }
        return $options;
    }

    /**
     * The settings of the HTTP client.
     *
     * @param developer_settings $settings Developer settings.
     * @param callable|null $handler Guzzle handler; null for the real network.
     * @return array
     */
    public static function client_config(developer_settings $settings, ?callable $handler): array {
        $config = $handler === null ? [] : ['mock' => $handler];
        if ($settings->allow_private_hosts()) {
            $config['ignoresecurity'] = true;
        }
        return $config;
    }

    /**
     * Turns a Guzzle failure into a transport exception without URLs or bodies.
     *
     * The type of $e is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * transport::send()). The attribute keeps the failure, which holds the request with its signature and body, out of the
     * trace of the exception made here.
     *
     * @param #[|\SensitiveParameter|]|GuzzleException $e The failure.
     * @return transport_exception
     */
    public static function failure(#[\SensitiveParameter] GuzzleException $e): transport_exception {
        if ($e->getMessage() === get_string('curlsecurityurlblocked', 'admin')) {
            return new transport_exception('transport_blocked');
        }
        $errno = 0;
        if ($e instanceof RequestException || $e instanceof ConnectException) {
            $errno = (int) ($e->getHandlerContext()['errno'] ?? 0);
        }
        $detail = $errno > 0 ? 'cURL error ' . $errno : get_class($e);
        if (in_array($errno, self::TLS_ERRORS, true)) {
            return new transport_exception('transport_tls', $detail);
        }
        if ($e instanceof ConnectException || in_array($errno, self::CONNECT_ERRORS, true)) {
            return new transport_exception('transport_connect', $detail);
        }
        return new transport_exception('transport_failed', $detail);
    }
}
