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
use Psr\Http\Message\ResponseInterface;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;

/**
 * Sends requests to the service with Moodle's HTTP client, behind the checks of the connection design:
 * the name check (dns_guard), a connection pinned to the checked address, only the bundled Amazon
 * root certificates, no redirects and a size limit.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transport {
    /** @var int Largest response accepted, in bytes. */
    public const MAX_RESPONSE = 1048576;

    /** @var int[] curl error numbers of failed TLS handshakes and certificate checks. */
    private const TLS_ERRORS = [35, 51, 58, 60, 77, 83, 90, 91];

    /** @var int[] curl error numbers of failed or timed-out connections. */
    private const CONNECT_ERRORS = [5, 6, 7, 28, 52, 55, 56];

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
     * The bundled Amazon root certificates.
     *
     * @return string
     */
    public static function roots_file(): string {
        return dirname(__DIR__, 3) . '/data/amazon-roots.pem';
    }

    /**
     * Sends one request.
     *
     * @param string $method GET or POST.
     * @param endpoint $endpoint Connection URL.
     * @param string $relative Name of the API call, such as "status".
     * @param string[] $headers Headers by name.
     * @param string $body Body.
     * @return response
     * @throws transport_exception When the checks fail or the connection fails.
     */
    public function send(string $method, endpoint $endpoint, string $relative, array $headers, string $body): response {
        $url = $endpoint->join($relative);
        $proxy = proxy_settings::for_url($url);
        $options = [
            'headers' => $headers,
            'body' => $body,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 30,
            'connect_timeout' => 10,
            'proxy' => $proxy,
            // The mocked transport also calls this with the exception that it is about to throw: the argument has no type.
            'on_headers' => static function ($received): void {
                if (
                    $received instanceof ResponseInterface
                    && (int) $received->getHeaderLine('Content-Length') > self::MAX_RESPONSE
                ) {
                    throw new transport_exception('transport_too_large');
                }
            },
            'curl' => [],
        ];
        if (!$this->settings->skip_network_checks()) {
            // Only an HTTP proxy looks the name up. With a SOCKS5 proxy this server does it, so its answer is checked and pinned.
            $pinned = $this->guard->check($endpoint->host(), proxy_settings::resolves_names($url), aws_ranges::SERVICE_CLOUDFRONT);
            $options['verify'] = self::roots_file();
            // A folder without "<hash>.0" files: curl must not add the system's CA directory to the bundled roots.
            $options['curl'][CURLOPT_CAPATH] = dirname(self::roots_file());
            if ($pinned !== null) {
                $options['curl'][CURLOPT_RESOLVE] = [$endpoint->host() . ':' . $endpoint->port() . ':' . $pinned];
            }
        }
        $config = $this->handler === null ? [] : ['mock' => $this->handler];
        if ($this->settings->allow_private_hosts()) {
            $config['ignoresecurity'] = true;
        }
        try {
            $response = (new \core\http_client($config))->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw self::failure($e);
        }
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new transport_exception('transport_redirect', 'HTTP ' . $response->getStatusCode());
        }
        $responsebody = (string) $response->getBody();
        if (strlen($responsebody) > self::MAX_RESPONSE) {
            throw new transport_exception('transport_too_large');
        }
        return new response($response->getStatusCode(), $responsebody, self::retry_after($response->getHeaderLine('Retry-After')));
    }

    /**
     * Turns a Guzzle failure into a transport exception without URLs or bodies.
     *
     * @param GuzzleException $e The failure.
     * @return transport_exception
     */
    private static function failure(GuzzleException $e): transport_exception {
        if ($e->getPrevious() instanceof transport_exception) {
            return $e->getPrevious();
        }
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

    /**
     * Reads a Retry-After header given in seconds.
     *
     * @param string $value Header value.
     * @return int|null Seconds, at most one day; null for other forms.
     */
    private static function retry_after(string $value): ?int {
        return preg_match('/^\d{1,6}$/', trim($value)) === 1 ? min((int) trim($value), 86400) : null;
    }
}
