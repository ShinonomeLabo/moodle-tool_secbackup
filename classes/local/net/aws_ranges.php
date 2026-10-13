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
 * IPv4 address ranges of Amazon CloudFront and Amazon S3 (ap-northeast-1), bundled in data/aws-ranges.json.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class aws_ranges {
    /** @var string Amazon CloudFront, which serves the service's API. */
    public const SERVICE_CLOUDFRONT = 'CLOUDFRONT';

    /** @var string Amazon S3 in ap-northeast-1, which stores the backups. */
    public const SERVICE_S3 = 'S3';

    /** @var array Networks by service: lists of [network, mask] as integers. */
    private array $networks;

    /** @var string AWS sync token of the list. */
    private string $synctoken;

    /** @var string AWS creation date of the list. */
    private string $createdate;

    /**
     * Constructor.
     *
     * @param array $networks Networks by service.
     * @param string $synctoken Sync token.
     * @param string $createdate Creation date.
     */
    private function __construct(array $networks, string $synctoken, string $createdate) {
        $this->networks = $networks;
        $this->synctoken = $synctoken;
        $this->createdate = $createdate;
    }

    /**
     * The list bundled with the plugin.
     *
     * @return self
     */
    public static function bundled(): self {
        return self::from_json((string) file_get_contents(dirname(__DIR__, 3) . '/data/aws-ranges.json'));
    }

    /**
     * Reads a list in the format of data/aws-ranges.json.
     *
     * @param string $json The list.
     * @return self
     */
    public static function from_json(string $json): self {
        $data = json_decode($json, true);
        if (
            !is_array($data)
            || !isset(
                $data['syncToken'],
                $data['createDate'],
                $data['services'][self::SERVICE_CLOUDFRONT],
                $data['services'][self::SERVICE_S3]
            )
        ) {
            throw new \coding_exception('The AWS range list is not valid.');
        }
        $networks = [];
        foreach ([self::SERVICE_CLOUDFRONT, self::SERVICE_S3] as $service) {
            $networks[$service] = array_map([self::class, 'network'], $data['services'][$service]);
        }
        return new self($networks, (string) $data['syncToken'], (string) $data['createDate']);
    }

    /**
     * Turns "a.b.c.d/n" into [network, mask].
     *
     * @param mixed $prefix The prefix.
     * @return int[]
     */
    private static function network(mixed $prefix): array {
        // The pattern ends with \z: $ also matches before a final line feed.
        if (
            !is_string($prefix)
            || preg_match('~^([0-9.]+)/([0-9]|[12][0-9]|3[0-2])\z~', $prefix, $m) !== 1
            || ($network = ip2long($m[1])) === false
        ) {
            throw new \coding_exception('The AWS range list contains an invalid prefix.');
        }
        $mask = (-1 << (32 - (int) $m[2])) & 0xFFFFFFFF;
        return [$network & $mask, $mask];
    }

    /**
     * Whether an IPv4 address belongs to a service.
     *
     * @param string $ip The address.
     * @param string $service SERVICE_CLOUDFRONT or SERVICE_S3.
     * @return bool
     */
    public function contains(string $ip, string $service): bool {
        if (!isset($this->networks[$service])) {
            throw new \coding_exception('Unknown AWS service: ' . $service);
        }
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->networks[$service] as [$network, $mask]) {
            if (($address & $mask) === $network) {
                return true;
            }
        }
        return false;
    }

    /**
     * Number of prefixes of a service.
     *
     * @param string $service SERVICE_CLOUDFRONT or SERVICE_S3.
     * @return int
     */
    public function count(string $service): int {
        return count($this->networks[$service]);
    }

    /**
     * AWS sync token of the list.
     *
     * @return string
     */
    public function sync_token(): string {
        return $this->synctoken;
    }

    /**
     * AWS creation date of the list.
     *
     * @return string
     */
    public function create_date(): string {
        return $this->createdate;
    }
}
