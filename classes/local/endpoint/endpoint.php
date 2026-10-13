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

namespace tool_secbackup\local\endpoint;

use tool_secbackup\local\trust\developer_settings;

/**
 * The connection URL of the backup service, in a normal form.
 *
 * Only one strict shape is accepted, without parse_url(), so that the URL means the same thing
 * here, in Moodle's HTTP security checks and in curl. A pattern that has to match the whole text
 * ends with \z and not with $, which also matches before a final line feed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class endpoint {
    /** @var int Longest accepted input, in bytes. */
    private const MAX_LENGTH = 2048;

    /** @var string One DNS label. */
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @var string Scheme. */
    private string $scheme;

    /** @var string Host name, lower case. */
    private string $host;

    /** @var int Port. */
    private int $port;

    /** @var string Path without the trailing slash; empty for the root. */
    private string $path;

    /**
     * Constructor.
     *
     * @param string $scheme Scheme.
     * @param string $host Host.
     * @param int $port Port.
     * @param string $path Path.
     */
    private function __construct(string $scheme, string $host, int $port, string $path) {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
    }

    /**
     * Reads a typed or stored connection URL.
     *
     * @param string $input The URL.
     * @param developer_settings $settings Developer settings from config.php.
     * @return self
     * @throws endpoint_exception When the URL is refused.
     */
    public static function parse(string $input, developer_settings $settings): self {
        $text = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $input);
        if ($text === null) {
            // The pattern gives null for text that is not UTF-8: that is not a URL, and it is not an empty input either.
            throw new endpoint_exception('endpoint_invalid');
        }
        if ($text === '') {
            throw new endpoint_exception('endpoint_empty');
        }
        if (
            strlen($text) > self::MAX_LENGTH
            || preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)([^?#]*)(\?[^#]*)?(#.*)?\z~s', $text, $m) !== 1
        ) {
            throw new endpoint_exception('endpoint_invalid');
        }
        $scheme = strtolower($m[1]);
        if ($scheme !== 'https' && !($scheme === 'http' && $settings->allow_http())) {
            throw new endpoint_exception('endpoint_scheme');
        }
        if (str_contains($m[2], '@')) {
            throw new endpoint_exception('endpoint_userinfo');
        }
        if ((isset($m[4]) && $m[4] !== '') || isset($m[5])) {
            throw new endpoint_exception('endpoint_query');
        }
        [$host, $port] = self::split_authority($m[2], $scheme === 'https' ? 443 : 80, $settings);
        if (!self::host_allowed($host, $settings)) {
            throw new endpoint_exception('endpoint_host');
        }
        $path = rtrim($m[3], '/');
        if (
            ($path !== '' && preg_match('~^(/[A-Za-z0-9._\~-]+)+\z~', $path) !== 1)
            || preg_match('~/\.\.?(/|$)~', $path) === 1
        ) {
            throw new endpoint_exception('endpoint_path');
        }
        return new self($scheme, $host, $port, $path);
    }

    /**
     * Splits "host" or "host:port". Only the default port is accepted, unless the developer settings allow private hosts.
     *
     * @param string $authority The part between "//" and the path.
     * @param int $defaultport 443 or 80.
     * @param developer_settings $settings Developer settings.
     * @return array [host in lower case, port]
     * @throws endpoint_exception When the port is refused.
     */
    private static function split_authority(string $authority, int $defaultport, developer_settings $settings): array {
        if (str_starts_with($authority, '[')) {
            throw new endpoint_exception('endpoint_host');
        }
        $colon = strrpos($authority, ':');
        if ($colon === false) {
            return [strtolower($authority), $defaultport];
        }
        $port = substr($authority, $colon + 1);
        $number = (int) $port;
        if (
            preg_match('/^[0-9]{1,5}\z/', $port) !== 1 || $number < 1 || $number > 65535
            || ($number !== $defaultport && !$settings->allow_private_hosts())
        ) {
            throw new endpoint_exception('endpoint_port');
        }
        return [strtolower(substr($authority, 0, $colon)), $number];
    }

    /**
     * Whether a host is a DNS name of the internet (or, with the developer settings, a local host).
     *
     * A name with one label and a name under localhost are local hosts.
     *
     * @param string $host Host in lower case.
     * @param developer_settings $settings Developer settings.
     * @return bool
     */
    private static function host_allowed(string $host, developer_settings $settings): bool {
        $private = $settings->allow_private_hosts();
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $private;
        }
        if (strlen($host) > 253 || preg_match('/^' . self::LABEL . '(\.' . self::LABEL . ')*\z/', $host) !== 1) {
            return false;
        }
        $labels = explode('.', $host);
        // The last label must start with a letter: curl reads names such as 127.1 as addresses.
        if (preg_match('/^[a-z]/', end($labels)) !== 1) {
            return false;
        }
        // The name localhost and every name under it mean this machine (RFC 6761), and curl resolves them to loopback itself.
        if (end($labels) === 'localhost') {
            return $private;
        }
        return count($labels) > 1 || $private;
    }

    /**
     * The URL in its normal form.
     *
     * @return string
     */
    public function url(): string {
        return $this->origin() . $this->path;
    }

    /**
     * Scheme, host and, when it is not the default one, port.
     *
     * @return string
     */
    private function origin(): string {
        $default = $this->scheme === 'https' ? 443 : 80;
        return $this->scheme . '://' . $this->host . ($this->port === $default ? '' : ':' . $this->port);
    }

    /**
     * Scheme (https, or http with the developer settings).
     *
     * @return string
     */
    public function scheme(): string {
        return $this->scheme;
    }

    /**
     * Host name in lower case.
     *
     * @return string
     */
    public function host(): string {
        return $this->host;
    }

    /**
     * Port.
     *
     * @return int
     */
    public function port(): int {
        return $this->port;
    }

    /**
     * Path without the trailing slash; empty for the root.
     *
     * @return string
     */
    public function path(): string {
        return $this->path;
    }

    /**
     * Path of an API call under this URL, as signed in requests.
     *
     * @param string $relative Name of the call, such as "enroll".
     * @return string
     */
    public function path_of(string $relative): string {
        if (preg_match('~^[a-z][a-z0-9-]*(/[a-z0-9-]+)*\z~', $relative) !== 1) {
            throw new \coding_exception('Unexpected API path: ' . $relative);
        }
        return $this->path . '/' . $relative;
    }

    /**
     * Full URL of an API call under this URL.
     *
     * @param string $relative Name of the call, such as "enroll".
     * @return string
     */
    public function join(string $relative): string {
        return $this->origin() . $this->path_of($relative);
    }

    /**
     * Whether two connection URLs are the same.
     *
     * @param self $other The other URL.
     * @return bool
     */
    public function equals(self $other): bool {
        return $this->url() === $other->url();
    }
}
