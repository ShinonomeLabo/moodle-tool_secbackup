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
use GuzzleHttp\Psr7\Utils;
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
     * The types of $headers and $body are written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()). The attributes keep the headers, which carry the signature of the request, and the
     * body, which can hold the registration code of an enrolment, out of the trace of an exception thrown here or in a call
     * made from here.
     *
     * @param string $method GET or POST.
     * @param endpoint $endpoint Connection URL.
     * @param string $relative Name of the API call, such as "status".
     * @param #[|\SensitiveParameter|]|string[] $headers Headers by name.
     * @param #[|\SensitiveParameter|]|string $body Body.
     * @return response
     * @throws \coding_exception When the method is not GET or POST, a GET request has a body, or the name of the call is not
     *         allowed: that is a mistake of the caller.
     * @throws transport_exception When the checks or the connection fail, and when anything else fails in the HTTP client
     *         (transport_failed, with the name of the class). A body that cannot be read afterwards throws \RuntimeException.
     */
    public function send(
        string $method,
        endpoint $endpoint,
        string $relative,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] string $body
    ): response {
        // Only GET and POST in upper case, as the request signer accepts them: it signs the method as a line of its text. PSR-7
        // upper-cases a method itself, but it lets a line break through, and curl sends that as it is. A GET request has no
        // body: the caller signed the hash of a body that would not be the one that is sent.
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \coding_exception('A method is GET or POST.');
        }
        if ($method === 'GET' && $body !== '') {
            throw new \coding_exception('A GET request has no body.');
        }
        $url = $endpoint->join($relative);
        $proxy = proxy_settings::for_url($url);
        // Moodle's HTTP client would add its own user agent, "MoodleBot/<version> (+<wwwroot>)": it carries the URL of the site.
        $others = array_filter(
            $headers,
            static fn($name): bool => strtolower((string) $name) !== 'user-agent',
            ARRAY_FILTER_USE_KEY
        );
        // Set when the size limit stops the transfer: the failure that Guzzle then reports does not say why.
        $toolarge = false;
        $options = [
            'headers' => $others + ['User-Agent' => self::USER_AGENT],
            // Empty for a GET request: the check above refuses a body there. It is the string that was signed, never an array.
            'body' => $body,
            'http_errors' => false,
            'allow_redirects' => false,
            // The limit counts the bytes of the wire, so the response must not be decompressed.
            'decode_content' => false,
            'timeout' => 30,
            'connect_timeout' => 10,
            'proxy' => $proxy,
            // The mocked transport also calls this with the exception that it is about to throw: the argument has no type.
            'on_headers' => static function ($received) use (&$toolarge): void {
                if (
                    $received instanceof ResponseInterface
                    && (int) $received->getHeaderLine('Content-Length') > self::MAX_RESPONSE
                ) {
                    $toolarge = true;
                    throw new TransferException('The response is larger than the limit.');
                }
            },
            // Cuts the transfer as soon as more than the limit has arrived: a callback that returns 1 aborts it. (An
            // exception thrown from the progress option of Guzzle comes out only when the whole transfer is over.)
            'curl' => [
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function ($handle, int $downloadtotal, int $downloaded) use (&$toolarge): int {
                    if ($downloaded > self::MAX_RESPONSE) {
                        $toolarge = true;
                        return 1;
                    }
                    return 0;
                },
            ],
        ];
        $options = guarded_options::apply(
            $this->settings,
            $this->guard,
            $url,
            $endpoint->host(),
            $endpoint->port(),
            aws_ranges::SERVICE_CLOUDFRONT,
            $options
        );
        $config = guarded_options::client_config($this->settings, $this->handler);
        // Every exception of the HTTP client is replaced here, where the headers and the body are hidden from its trace: one that
        // was made inside the client holds the arguments of Guzzle's calls, the request among them.
        try {
            $response = (new \core\http_client($config))->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw $toolarge ? new transport_exception('transport_too_large') : guarded_options::failure($e);
        } catch (\Throwable $e) {
            // PSR-7 refusing a header, a bundle of roots that is missing, an error of PHP: only the class is kept, and the
            // exception is not linked. PSR-7 even puts the refused header value into its message.
            throw new transport_exception('transport_failed', get_class($e));
        }
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new transport_exception('transport_redirect', 'HTTP ' . $response->getStatusCode());
        }
        // One byte more than the limit is enough to see that the body is too large; the rest is not read.
        $responsebody = Utils::copyToString($response->getBody(), self::MAX_RESPONSE + 1);
        if (strlen($responsebody) > self::MAX_RESPONSE) {
            throw new transport_exception('transport_too_large');
        }
        return new response($response->getStatusCode(), $responsebody, self::retry_after($response->getHeaderLine('Retry-After')));
    }

    /**
     * Reads a Retry-After header given in seconds.
     *
     * @param string $value Header value.
     * @return int|null Seconds, at most one day; null for other forms.
     */
    private static function retry_after(string $value): ?int {
        // The pattern ends with \z: $ also matches before a final line feed.
        return preg_match('/^\d{1,6}\z/', trim($value)) === 1 ? min((int) trim($value), 86400) : null;
    }
}
