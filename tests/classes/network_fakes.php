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

namespace tool_secbackup\tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\local\api\client;
use tool_secbackup\local\net\aws_ranges;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\doh_resolver;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\sec_trust;

/**
 * Fake name sources and AWS ranges for tests, so that no test needs the network.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class network_fakes {
    /** @var string The service host used in tests. */
    public const HOST = 'app.example.test';

    /**
     * A small range list: CloudFront 13.225.0.0/16, S3 3.5.152.0/21.
     *
     * A list needs five prefixes for each service, so four reserved ranges that no test uses are added to each.
     *
     * @return aws_ranges
     */
    public static function ranges(): aws_ranges {
        return aws_ranges::from_json(json_encode([
            'syncToken' => '1',
            'createDate' => '2026-10-12-00-00-00',
            'services' => [
                'CLOUDFRONT' => ['13.225.0.0/16', '192.0.2.0/24', '198.51.100.0/24', '198.18.0.0/15', '100.64.0.0/10'],
                'S3' => ['3.5.152.0/21', '192.0.0.0/24', '192.88.99.0/24', '192.175.48.0/24', '233.252.0.0/24'],
            ],
        ]));
    }

    /**
     * A DNS over HTTPS resolver that gives the same answer up to ten times.
     *
     * @param string $name cloudflare or google.
     * @param string[]|null $addresses Addresses, or null for a connection error.
     * @param int $status DNS status.
     * @return doh_resolver
     */
    public static function doh(string $name, ?array $addresses, int $status = 0): doh_resolver {
        $answers = [];
        for ($i = 0; $i < 10; $i++) {
            if ($addresses === null) {
                $answers[] = new ConnectException('cURL error 7', new Request('GET', 'https://1.1.1.1/'), null, ['errno' => 7]);
            } else {
                $records = array_map(
                    static fn(string $ip): array => ['name' => self::HOST, 'type' => 1, 'TTL' => 60, 'data' => $ip],
                    $addresses
                );
                $answers[] = new Response(200, [], json_encode(['Status' => $status, 'Answer' => $records]));
            }
        }
        return new doh_resolver($name, new MockHandler($answers));
    }

    /**
     * A guard with these answers.
     *
     * @param string[]|null $cloudflare Cloudflare's answer, or null when it fails.
     * @param string[]|null $google Google's answer, or null when it fails.
     * @param string[] $os The operating system's answer.
     * @return dns_guard
     */
    public static function guard(?array $cloudflare, ?array $google, array $os): dns_guard {
        return new dns_guard(
            self::ranges(),
            [self::doh('cloudflare', $cloudflare), self::doh('google', $google)],
            static fn(string $host): array => $os
        );
    }

    /**
     * A guard whose answers all pass; it pins 13.225.9.9.
     *
     * @return dns_guard
     */
    public static function passing_guard(): dns_guard {
        return self::guard(['13.225.1.1'], ['13.225.1.2'], ['13.225.9.9', '13.225.9.8']);
    }

    /**
     * Turns Moodle's HTTP security list off. With rules, its checks look host names up on the network.
     * Call it from a test that resets the site after the test.
     */
    public static function no_security_list(): void {
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
    }

    /**
     * A client that sends through a Guzzle handler, trusts the test key of envelope_builder and reads a fixed clock.
     *
     * @param callable $handler Guzzle handler, such as fake_service::handler().
     * @param int $now This server's clock (UNIX time).
     * @return client
     */
    public static function client(callable $handler, int $now): client {
        $settings = developer_settings::from_value(null);
        $transport = new transport($settings, self::passing_guard(), $handler);
        return new client(
            $transport,
            new sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]),
            $settings,
            static fn(): int => $now
        );
    }
}
