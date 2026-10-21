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

use tool_secbackup\local\trust\developer_settings;

/**
 * A pre-signed URL of the storage, in the one shape that this plugin sends data to or reads data from.
 *
 * The URL carries its credential in the query, so it is never put into a message, a log or the debug information of an
 * exception. Only a strict shape is accepted, without parse_url(), so that it means the same thing here and in curl: https,
 * no user name, no port, and the host of the storage region (s3.<region>.amazonaws.com or <bucket>.s3.<region>.amazonaws.com).
 * The developer settings widen it: allowhttp admits http, and allowprivatehosts admits other host names, with a port.
 * A pattern that has to match the whole text ends with \z and not with $, which also matches before a final line feed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class object_url {
    /** @var int Longest accepted URL, in bytes. */
    private const MAX_LENGTH = 8192;

    /** @var string One DNS label of an ordinary host name. */
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @var string The URL, as it was given. */
    private string $url;

    /** @var string Scheme. */
    private string $scheme;

    /** @var string Host name. */
    private string $host;

    /** @var int Port. */
    private int $port;

    /**
     * Constructor.
     *
     * @param string $url The URL.
     * @param string $scheme Scheme.
     * @param string $host Host name.
     * @param int $port Port.
     */
    private function __construct(string $url, string $scheme, string $host, int $port) {
        $this->url = $url;
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Reads a URL that the backup service gave.
     *
     * The type of $url is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * transport::send()). The attribute keeps the URL out of the trace of an exception thrown here.
     *
     * @param #[|\SensitiveParameter|]|string $url The URL.
     * @param developer_settings $settings Developer settings.
     * @return self
     * @throws transport_exception With object_url_invalid, without the URL, when the URL is refused.
     */
    public static function parse(#[\SensitiveParameter] string $url, developer_settings $settings): self {
        // The path and the query are printable ASCII without a space: a presigned URL is percent-encoded. No fragment.
        $matched = strlen($url) <= self::MAX_LENGTH
            && preg_match('~^(https?)://([a-z0-9.:-]+)(/[\x21-\x7e]*?)\?([\x21-\x7e]+)\z~', $url, $m) === 1
            && !str_contains($m[3] . $m[4], '#') && !str_contains($m[3] . $m[4], '\\')
            && preg_match('~/(?:\.|%2e){1,2}(?:/|\z)~i', $m[3]) !== 1
            && strlen($m[3]) > 1;
        if (!$matched || ($m[1] === 'http' && !$settings->allow_http())) {
            throw new transport_exception('object_url_invalid');
        }
        $default = $m[1] === 'https' ? 443 : 80;
        $port = $default;
        $host = $m[2];
        if (preg_match('~^([a-z0-9.-]+):([0-9]{1,5})\z~', $host, $parts) === 1) {
            $host = $parts[1];
            $port = (int) $parts[2];
        }
        $private = $settings->allow_private_hosts();
        $inregion = self::in_region($host);
        if (
            !($inregion && $port === $default && $host === $m[2])
            && !($private && self::is_host($host) && $port >= 1 && $port <= 65535)
        ) {
            throw new transport_exception('object_url_invalid');
        }
        return new self($url, $m[1], $host, $port);
    }

    /**
     * Keeps the URL, whose query is a credential, out of print_r(), var_dump() and debugging output.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['scheme' => $this->scheme, 'host' => $this->host, 'port' => $this->port];
    }

    /**
     * Whether a host is s3.<region>.amazonaws.com or <bucket>.s3.<region>.amazonaws.com of the storage region.
     *
     * @param string $host Host name.
     * @return bool
     */
    private static function in_region(string $host): bool {
        $suffix = 's3.' . aws_ranges::current()->s3_region() . '.amazonaws.com';
        if ($host === $suffix) {
            return true;
        }
        if (!str_ends_with($host, '.' . $suffix)) {
            return false;
        }
        $bucket = substr($host, 0, -strlen('.' . $suffix));
        return preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', $bucket) === 1 && !str_contains($bucket, '..');
    }

    /**
     * Whether a host is an IPv4 address or a DNS name (with the developer settings, also a name without a dot).
     *
     * @param string $host Host name.
     * @return bool
     */
    private static function is_host(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (strlen($host) > 253 || preg_match('/^' . self::LABEL . '(\.' . self::LABEL . ')*\z/', $host) !== 1) {
            return false;
        }
        // The last label must start with a letter: curl reads names such as 127.1 as addresses.
        $labels = explode('.', $host);
        return preg_match('/^[a-z]/', end($labels)) === 1;
    }

    /**
     * The URL as it was given.
     *
     * @return string
     */
    public function url(): string {
        return $this->url;
    }

    /**
     * Scheme.
     *
     * @return string
     */
    public function scheme(): string {
        return $this->scheme;
    }

    /**
     * Host name.
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
}
