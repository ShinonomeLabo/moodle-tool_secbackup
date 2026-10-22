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
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

/**
 * Looks up IPv4 addresses with a public DNS over HTTPS service, reached by its IP address.
 *
 * The requests go through Moodle's HTTP client, so Moodle's proxy and HTTP security settings apply. Each request sets
 * its own user agent: Moodle's default one carries the URL of the site, and the service would receive it with every
 * check. The answer is not trusted: it has to be small, and it is read as it came, without being decompressed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class doh_resolver {
    /** @var string[] Query URLs by resolver name; the host name is appended. */
    private const PROVIDERS = [
        'cloudflare' => 'https://1.1.1.1/dns-query?type=A&name=',
        'google' => 'https://8.8.8.8/resolve?type=A&name=',
    ];

    /** @var string User agent of the requests: the name of the plugin and nothing of the site. */
    private const USER_AGENT = 'tool_secbackup';

    /** @var int Largest answer accepted, in bytes. A real answer has a few hundred. */
    private const MAX_ANSWER = 65536;

    /** @var string Resolver name. */
    private string $name;

    /** @var callable|null Guzzle handler; null for the real network. */
    private $handler;

    /**
     * Constructor.
     *
     * @param string $name cloudflare or google.
     * @param callable|null $handler Guzzle handler; null for the real network.
     */
    public function __construct(string $name, ?callable $handler = null) {
        if (!array_key_exists($name, self::PROVIDERS)) {
            throw new \coding_exception('Unknown DNS over HTTPS resolver: ' . $name);
        }
        $this->name = $name;
        $this->handler = $handler;
    }

    /**
     * All resolvers.
     *
     * @param callable|null $handler Guzzle handler; null for the real network.
     * @return doh_resolver[]
     */
    public static function all(?callable $handler = null): array {
        return array_map(static fn(string $name): self => new self($name, $handler), array_keys(self::PROVIDERS));
    }

    /**
     * Resolver name.
     *
     * @return string
     */
    public function name(): string {
        return $this->name;
    }

    /**
     * Looks up the IPv4 addresses of a host.
     *
     * @param string $host Host name.
     * @return string[] Addresses; empty when the name does not exist.
     * @throws transport_exception When the resolver does not answer or its answer cannot be used (dns_doh_failed).
     */
    public function lookup(string $host): array {
        $url = self::PROVIDERS[$this->name] . rawurlencode($host);
        $client = new \core\http_client($this->handler === null ? [] : ['mock' => $this->handler]);
        try {
            $response = $client->request('GET', $url, [
                'headers' => ['Accept' => 'application/dns-json', 'User-Agent' => self::USER_AGENT],
                'http_errors' => false,
                'allow_redirects' => false,
                // The limit on the size counts the bytes of the wire, so the answer must not be decompressed.
                'decode_content' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
                'proxy' => proxy_settings::for_url($url),
                // The mocked transport also calls this with the exception that it is about to throw: the argument has no type.
                'on_headers' => static function ($received): void {
                    if (
                        $received instanceof ResponseInterface
                        && (int) $received->getHeaderLine('Content-Length') > self::MAX_ANSWER
                    ) {
                        throw new TransferException('The answer is larger than the limit.');
                    }
                },
                // Cuts the transfer as soon as more than the limit has arrived: a callback that returns 1 aborts it. (An
                // exception thrown from the progress option of Guzzle comes out only when the whole transfer is over.)
                'curl' => [
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => static function ($handle, int $downloadtotal, int $downloaded): int {
                        return $downloaded > self::MAX_ANSWER ? 1 : 0;
                    },
                ],
            ]);
        } catch (GuzzleException $e) {
            // A connection that failed at once is told apart from a timeout or a lookup, which a denial of the system is not.
            $errno = $e instanceof ConnectException ? (int) ($e->getHandlerContext()['errno'] ?? 0) : 0;
            unset($e);
            throw new transport_exception(
                'dns_doh_failed',
                $this->name . ($errno === 7 ? ', ' . transport_exception::CONNECT_FAILED : '')
            );
        }
        $text = $response->getBody()->read(self::MAX_ANSWER + 1);
        $data = strlen($text) > self::MAX_ANSWER ? null : json_decode($text, true, 8);
        $records = is_array($data) && array_key_exists('Answer', $data) ? $data['Answer'] : [];
        if (
            $response->getStatusCode() !== 200
            || !is_array($data)
            || !in_array($data['Status'] ?? null, [0, 3], true)
            || !is_array($records)
        ) {
            throw new transport_exception('dns_doh_failed', $this->name);
        }
        $addresses = [];
        foreach ($records as $record) {
            if (
                is_array($record)
                && ($record['type'] ?? null) === 1
                && is_string($record['data'] ?? null)
                && filter_var($record['data'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ) {
                $addresses[] = $record['data'];
            }
        }
        return array_values(array_unique($addresses));
    }
}
