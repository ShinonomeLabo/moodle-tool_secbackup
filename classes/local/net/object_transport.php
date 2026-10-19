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

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use tool_secbackup\local\trust\developer_settings;

/**
 * Sends a file to the storage with a pre-signed URL and reads an object back, behind the same checks as the connection to the
 * service (guarded_options): the name check and the range check of the storage (S3), a connection pinned to the checked
 * address, only the bundled Amazon root certificates, no redirects, and a limit on the size and on the time of a transfer.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class object_transport {
    /** @var string[] The only headers that a pre-signed URL of the backup service is bound to, in lower case. */
    private const BOUND_HEADERS = [
        'content-length',
        'x-amz-checksum-sha256',
        'x-amz-storage-class',
        'x-amz-object-lock-mode',
        'x-amz-object-lock-retain-until-date',
        'if-none-match',
    ];

    /** @var int Slowest speed a transfer is expected to reach, in bytes a second (128 KiB). */
    private const MIN_SPEED = 131072;

    /** @var int Time added to the time of a transfer, in seconds. */
    private const EXTRA_SECONDS = 60;

    /** @var int Longest time of a transfer, in seconds. */
    private const MAX_SECONDS = 3600;

    /** @var string User agent of the requests: the name of the plugin and nothing of the site. */
    private const USER_AGENT = 'tool_secbackup';

    /** @var developer_settings Developer settings. */
    private developer_settings $settings;

    /** @var dns_guard Name check. */
    private dns_guard $guard;

    /** @var callable|null Guzzle handler; null for the real network. */
    private $handler;

    /**
     * Constructor.
     *
     * @param developer_settings $settings Developer settings.
     * @param dns_guard $guard Name check.
     * @param callable|null $handler Guzzle handler; null for the real network.
     */
    public function __construct(developer_settings $settings, dns_guard $guard, ?callable $handler = null) {
        $this->settings = $settings;
        $this->guard = $guard;
        $this->handler = $handler;
    }

    /**
     * The transport of this site.
     *
     * @return self
     */
    public static function create(): self {
        return new self(developer_settings::current(), dns_guard::create());
    }

    /**
     * Sends a file with PUT, with the headers that the backup service returned, exactly as they are.
     *
     * The type of $url is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * transport::send()). The attribute keeps the pre-signed URL, which the object holds, out of the trace of an exception.
     *
     * @param #[|\SensitiveParameter|]|object_url $url Pre-signed URL.
     * @param string[] $headers Headers by name: only Content-Length, x-amz-checksum-sha256, x-amz-storage-class,
     *        x-amz-object-lock-mode, x-amz-object-lock-retain-until-date and If-None-Match (exactly "*"), and Content-Length
     *        is required. With If-None-Match the storage answers 412 for an object that is stored already, which is a success.
     * @param string $path File to send.
     * @param int $bandwidthbytespersecond Upper limit of the sending speed; 0 for none.
     * @throws \coding_exception When a header is not one of the six (the five and If-None-Match), is given twice, or is not
     *         a plain value, when Content-Length is missing or not a number, or when the limit is negative: those are mistakes
     *         of the caller.
     * @throws transport_exception With object_incomplete when the file is not as long as Content-Length says (before or after
     *         the sending), with object_rejected when the storage does not answer 2xx, and with the transport_* and dns_*
     *         codes of the connection.
     */
    public function put_file(
        #[\SensitiveParameter] object_url $url,
        array $headers,
        string $path,
        int $bandwidthbytespersecond
    ): void {
        if ($bandwidthbytespersecond < 0) {
            throw new \coding_exception('The limit of the speed is not negative.');
        }
        $declared = self::declared_length($headers);
        $handle = is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new transport_exception('transport_failed', 'file_unreadable');
        }
        $body = new Stream($handle);
        try {
            if ($body->getSize() !== $declared) {
                throw new transport_exception('object_incomplete', 'file_size');
            }
            $options = ['headers' => $headers, 'body' => $body];
            $curl = [];
            if ($bandwidthbytespersecond > 0) {
                $curl[CURLOPT_MAX_SEND_SPEED_LARGE] = $bandwidthbytespersecond;
            }
            $response = $this->request('PUT', $url, $options, $curl, $declared, $bandwidthbytespersecond, transport::MAX_RESPONSE);
            if ($response->getStatusCode() === 412 && self::is_conditional($headers)) {
                // Stored already: the version that is kept is the one that was sent first.
                return;
            }
            self::check_status($response->getStatusCode());
            // The position of the stream is the number of bytes that the client took from it.
            if ($body->tell() !== $declared) {
                throw new transport_exception('object_incomplete', 'sent');
            }
        } finally {
            $body->close();
        }
    }

    /**
     * Reads an object into a file. On any failure the file is removed.
     *
     * The type of $url is written as in put_file().
     *
     * @param #[|\SensitiveParameter|]|object_url $url Pre-signed URL.
     * @param string $path File to write; it is replaced.
     * @param int $maxbytes Most bytes accepted.
     * @return bool False when the storage has no such object (404).
     * @throws \coding_exception When $maxbytes is not positive.
     * @throws transport_exception With transport_too_large when the object is larger than $maxbytes, with object_incomplete
     *         when the file is not as long as Content-Length says, with object_rejected for another answer that is not 2xx, and
     *         with the transport_* and dns_* codes of the connection.
     */
    public function get_to_file(#[\SensitiveParameter] object_url $url, string $path, int $maxbytes): bool {
        if ($maxbytes < 1) {
            throw new \coding_exception('The most bytes is positive.');
        }
        $done = false;
        try {
            $response = $this->request('GET', $url, ['sink' => $path], [], $maxbytes, 0, $maxbytes);
            if ($response->getStatusCode() === 404) {
                return false;
            }
            self::check_status($response->getStatusCode());
            clearstatcache(true, $path);
            $size = (int) filesize($path);
            if ($size > $maxbytes) {
                throw new transport_exception('transport_too_large');
            }
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (string) $size !== $length) {
                throw new transport_exception('object_incomplete', 'received');
            }
            $done = true;
            return true;
        } finally {
            if (!$done && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The value of Content-Length, after the headers have been checked.
     *
     * @param string[] $headers Headers by name.
     * @return int
     * @throws \coding_exception For a mistake in the headers.
     */
    private static function declared_length(array $headers): int {
        $seen = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::BOUND_HEADERS, true) || isset($seen[$lower])) {
                throw new \coding_exception('A header is one of the five (or If-None-Match) that the URL is bound to, once.');
            }
            if (!is_string($value) || preg_match('/^[\x20-\x7e]*\z/', $value) !== 1) {
                throw new \coding_exception('The value of a header is plain text.');
            }
            if ($lower === 'if-none-match' && $value !== '*') {
                throw new \coding_exception('If-None-Match is "*" or it is not sent.');
            }
            $seen[$lower] = $value;
        }
        if (!isset($seen['content-length']) || preg_match('/^(?:0|[1-9][0-9]{0,15})\z/', $seen['content-length']) !== 1) {
            throw new \coding_exception('Content-Length is a number.');
        }
        return (int) $seen['content-length'];
    }

    /**
     * Whether the headers make the PUT conditional.
     *
     * @param string[] $headers Headers by name, checked already.
     * @return bool
     */
    private static function is_conditional(array $headers): bool {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'if-none-match') {
                return true;
            }
        }
        return false;
    }

    /**
     * Sends one request through the checks.
     *
     * @param string $method PUT or GET.
     * The types of $url and $given are written as in put_file(). The attributes keep the URL and the options (the headers, and
     * later the proxy with its password, which is built in a local variable) out of the trace of an exception.
     *
     * @param #[|\SensitiveParameter|]|object_url $url Pre-signed URL.
     * @param #[|\SensitiveParameter|]|array $given Options of the request besides the common ones.
     * @param array $curl Options of curl besides the common ones.
     * @param int $size Number of bytes that the transfer is expected to move; it sets the time limit.
     * @param int $limit Upper limit of the speed in bytes a second; 0 for none. A limit under 128 KiB/s lengthens the time.
     * @param int $maxresponse Most bytes accepted from the storage.
     * @return ResponseInterface
     * @throws transport_exception When the checks or the connection fail.
     */
    private function request(
        string $method,
        #[\SensitiveParameter] object_url $url,
        #[\SensitiveParameter] array $given,
        array $curl,
        int $size,
        int $limit,
        int $maxresponse
    ): ResponseInterface {
        $address = $url->url();
        // Set when the size limit stops the transfer: the failure that Guzzle then reports does not say why.
        $toolarge = false;
        // Cuts the transfer as soon as more than the limit has arrived: a callback that returns 1 aborts it.
        $progress = static function ($handle, int $downloadtotal, int $downloaded) use (&$toolarge, $maxresponse): int {
            if ($downloaded > $maxresponse) {
                $toolarge = true;
                return 1;
            }
            return 0;
        };
        $speed = $limit > 0 ? min(self::MIN_SPEED, $limit) : self::MIN_SPEED;
        $options = $given;
        $options['headers'] = ($given['headers'] ?? []) + ['User-Agent' => self::USER_AGENT];
        $options += [
            'http_errors' => false,
            'allow_redirects' => false,
            // The limit counts the bytes of the wire, so the response must not be decompressed.
            'decode_content' => false,
            'timeout' => min(self::MAX_SECONDS, (int) ceil($size / $speed) + self::EXTRA_SECONDS),
            'connect_timeout' => 10,
            'proxy' => proxy_settings::for_url($address),
            // The mocked transport also calls this with the exception that it is about to throw: the argument has no type.
            'on_headers' => static function ($received) use (&$toolarge, $maxresponse): void {
                if (
                    $received instanceof ResponseInterface
                    && $received->getStatusCode() < 300
                    && (int) $received->getHeaderLine('Content-Length') > $maxresponse
                ) {
                    $toolarge = true;
                    throw new TransferException('The response is larger than the limit.');
                }
            },
            'curl' => $curl + [
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => $progress,
            ],
        ];
        $checked = guarded_options::apply(
            $this->settings,
            $this->guard,
            $address,
            $url->host(),
            $url->port(),
            aws_ranges::SERVICE_S3,
            $options
        );
        $config = guarded_options::client_config($this->settings, $this->handler);
        // Every exception of the HTTP client is replaced here, where the URL is hidden from its trace: one that was made inside
        // the client holds the arguments of Guzzle's calls, the request with its URL among them.
        try {
            return (new \core\http_client($config))->request($method, $address, $checked);
        } catch (GuzzleException $e) {
            throw $toolarge ? new transport_exception('transport_too_large') : guarded_options::failure($e);
        } catch (\Throwable $e) {
            // PSR-7 refusing a header, a bundle of roots that is missing, an error of PHP: only the class is kept, and the
            // exception is not linked.
            throw new transport_exception('transport_failed', get_class($e));
        }
    }

    /**
     * Refuses a redirect and any answer that is not 2xx.
     *
     * @param int $status Status of the answer.
     * @throws transport_exception With transport_redirect or object_rejected.
     */
    private static function check_status(int $status): void {
        if ($status >= 300 && $status < 400) {
            throw new transport_exception('transport_redirect', 'HTTP ' . $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new transport_exception('object_rejected', 'http_' . $status);
        }
    }
}
