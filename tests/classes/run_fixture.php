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

use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\local\api\client;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;

/**
 * A registered site, the fake service and its storage, and a clock that the tests move, for the tests of a backup run.
 *
 * The client and the transport of the storage send through the fake service. Every request is recorded, and a test can
 * answer one itself (intercept). Call it from a test that resets the site after the test.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_fixture {
    /** @var string Connection URL. */
    public const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var fake_service The service. */
    public fake_service $service;

    /** @var signing_keypair The signing key pair of the site. */
    public signing_keypair $pair;

    /** @var string The X25519 key pair of the site (secret and public key), which the chunks are encrypted for. */
    public string $boxpair;

    /** @var int The clock of the site and of the service. */
    public int $time;

    /** @var string[] "METHOD path" of every request, in order. */
    public array $requests = [];

    /** @var callable|null Answers a request instead of the service: function (RequestInterface): ?Response. */
    public $intercept = null;

    /**
     * Registers the site and stores the settings of the service (02:00 to 05:00 in Tokyo, once a day).
     *
     * @param int $now The clock at the start.
     * @param array $settings Fields of the settings instruction to replace.
     */
    public function __construct(int $now, array $settings = []) {
        network_fakes::no_security_list();
        $this->time = $now;
        $this->pair = signing_keypair::generate();
        $this->boxpair = sodium_crypto_box_seed_keypair(str_repeat("\x05", 32));
        $this->service = new fake_service(self::API, array_merge(
            fake_service::initial_state(),
            ['now' => $now, 'signingkey' => base64_encode($this->pair->public_key())]
        ));
        state::save_enrollment(
            new enroll_result(fake_service::SITE_ID, fake_service::SITE_NAME, fake_service::PLATFORM_NAME, self::API, $now, 0),
            public_key_format::encode(sodium_crypto_box_publickey($this->boxpair)),
            $this->pair,
            $now
        );
        settings::apply(instruction_factory::make('settings', 1, instruction_factory::settings_body($settings)));
    }

    /**
     * A time of a day in Tokyo.
     *
     * @param string $datetime Such as 2026-10-19 03:00.
     * @return int
     */
    public static function tokyo(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('Asia/Tokyo')))->getTimestamp();
    }

    /**
     * Moves the clock of the site and of the service.
     *
     * @param int $seconds Seconds.
     */
    public function advance(int $seconds): void {
        $this->set_time($this->time + $seconds);
    }

    /**
     * Sets the clock of the site and of the service.
     *
     * @param int $time UNIX time.
     */
    public function set_time(int $time): void {
        $this->time = $time;
        $this->service->state['now'] = $time;
    }

    /**
     * The clock as a callable.
     *
     * @return callable
     */
    public function clock(): callable {
        return fn(): int => $this->time;
    }

    /**
     * The network: the fake service, through the hook of a test.
     *
     * @return callable A Guzzle handler.
     */
    public function handler(): callable {
        $inner = $this->service->handler();
        return function (RequestInterface $request, array $options) use ($inner) {
            $this->requests[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
            $answer = $this->intercept === null ? null : ($this->intercept)($request);
            $request->getBody()->rewind();
            return $answer === null ? $inner($request, $options) : Create::promiseFor($answer);
        };
    }

    /**
     * The developer settings of the tests: the fake service is on a private address and is not looked up.
     *
     * @return developer_settings
     */
    public static function settings(): developer_settings {
        return developer_settings::from_value(['allowprivatehosts' => true, 'skipnetworkchecks' => true]);
    }

    /**
     * The client of the site, sending to the fake service.
     *
     * @return client
     */
    public function client(): client {
        return new client(
            new transport(self::settings(), network_fakes::passing_guard(), $this->handler()),
            network_fakes::trust(),
            self::settings(),
            $this->clock()
        );
    }

    /**
     * The transport of the storage, sending to the fake storage.
     *
     * @return object_transport
     */
    public function objects(): object_transport {
        return new object_transport(self::settings(), network_fakes::passing_s3_guard(), $this->handler());
    }

    /**
     * The link of the site: [endpoint, signing key pair, site ID].
     *
     * @return callable
     */
    public function link(): callable {
        $endpoint = endpoint::parse(self::API, developer_settings::from_value(null));
        return fn(): array => [$endpoint, $this->pair, fake_service::SITE_ID];
    }

    /**
     * Starts a run at the fake service, as the stage "start" does.
     *
     * @return string The run ID.
     */
    public function start_at_service(): string {
        [$endpoint, $pair, $siteid] = ($this->link())();
        return $this->client()->start_run($endpoint, $pair, $siteid)->run_id();
    }

    /**
     * How many requests matched.
     *
     * @param string $method Method.
     * @param string $suffix End of the path.
     * @return int
     */
    public function count(string $method, string $suffix): int {
        return count(array_filter(
            $this->requests,
            static fn(string $r): bool => str_starts_with($r, $method . ' ') && str_ends_with($r, $suffix)
        ));
    }

    /**
     * The keys of the objects that a PUT stored, with how often each was sent.
     *
     * @return int[] Count by key.
     */
    public function puts(): array {
        $counts = [];
        foreach ($this->requests as $request) {
            if (str_starts_with($request, 'PUT /_s3/')) {
                $key = substr($request, strlen('PUT /_s3/'));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
