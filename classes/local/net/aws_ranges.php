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

use tool_secbackup\local\api\instruction;
use tool_secbackup\local\trust\envelope_exception;

/**
 * IPv4 address ranges of Amazon CloudFront and Amazon S3 (of the region of the storage).
 *
 * The list bundled in data/aws-ranges.json is used until the backup service sends a newer one, which is kept in the
 * plugin's settings after its SHA-256 and size have been checked against a signed instruction.
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

    /** @var int Fewest prefixes that a list may have for a service: the tool that makes the list refuses fewer. */
    private const MIN_PREFIXES = 5;

    /** @var string Region of the S3 prefixes of the bundled list, which does not name one. */
    private const DEFAULT_REGION = 'ap-northeast-1';

    /** @var int Largest list accepted, in bytes: the largest answer the transport reads. */
    private const MAX_SIZE = 1048576;

    /** @var array Networks by service: lists of [network, mask] as integers. */
    private array $networks;

    /** @var string AWS sync token of the list. */
    private string $synctoken;

    /** @var string AWS creation date of the list. */
    private string $createdate;

    /** @var string Region of the S3 prefixes. */
    private string $region;

    /**
     * Constructor.
     *
     * @param array $networks Networks by service.
     * @param string $synctoken Sync token.
     * @param string $createdate Creation date.
     * @param string $region Region of the S3 prefixes.
     */
    private function __construct(array $networks, string $synctoken, string $createdate, string $region) {
        $this->networks = $networks;
        $this->synctoken = $synctoken;
        $this->createdate = $createdate;
        $this->region = $region;
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
     * The list in use: the one the backup service sent and this site stored, or else the bundled one.
     *
     * A stored list that cannot be read, which should not happen, is dropped, so that the check of the addresses never
     * depends on it.
     *
     * @return self
     */
    public static function current(): self {
        $stored = get_config('tool_secbackup', 'awsranges');
        // A list belongs to the connection URL it came from; the numbering of another service starts again from 1. An empty
        // value is not a list either, so it is dropped like any other that cannot be read.
        if (is_string($stored) && self::stored_endpoint() === self::endpoint()) {
            try {
                return self::from_json($stored);
            } catch (\coding_exception $e) {
                unset_config('awsranges', 'tool_secbackup');
                unset_config('awsrangesseq', 'tool_secbackup');
                unset_config('awsrangesendpoint', 'tool_secbackup');
            }
        }
        return self::bundled();
    }

    /**
     * Sequence number of the stored list.
     *
     * @return int 0 when none is stored.
     */
    public static function stored_seq(): int {
        // The list is read first: a stored list that cannot be read is dropped with its sequence number.
        self::current();
        $seq = get_config('tool_secbackup', 'awsrangesseq');
        if (self::stored_endpoint() !== self::endpoint()) {
            return 0;
        }
        return is_string($seq) && preg_match('/^[1-9]\d{0,17}\z/', $seq) === 1 ? (int) $seq : 0;
    }

    /**
     * The connection URL of this registration.
     *
     * @return string Empty when none is stored.
     */
    private static function endpoint(): string {
        return (string) get_config('tool_secbackup', 'endpoint');
    }

    /**
     * The connection URL that the stored list came from.
     *
     * @return string
     */
    private static function stored_endpoint(): string {
        return (string) get_config('tool_secbackup', 'awsrangesendpoint');
    }

    /**
     * Whether an instruction is newer than the stored list, so that the list is worth fetching.
     *
     * @param instruction $instruction An aws_ranges instruction.
     * @return bool
     * @throws envelope_exception With the code instruction_invalid, when the body breaks a rule.
     */
    public static function is_newer(instruction $instruction): bool {
        self::checked_body($instruction);
        return $instruction->seq() > self::stored_seq();
    }

    /**
     * Stores a list that an instruction names, when the content is what the instruction signed.
     *
     * @param instruction $instruction An aws_ranges instruction.
     * @param string $content The content that was fetched.
     * @return bool False when the instruction is not newer, or when the content is not the signed one (a newer list was
     *     published in between); nothing is stored then, and the next poll fetches again.
     * @throws envelope_exception With the code instruction_invalid, when the body breaks a rule or the signed content is
     *     not a list.
     */
    public static function apply(instruction $instruction, string $content): bool {
        $body = self::checked_body($instruction);
        if ($instruction->seq() <= self::stored_seq()) {
            return false;
        }
        if (strlen($content) !== $body['size'] || !hash_equals($body['sha256'], hash('sha256', $content))) {
            return false;
        }
        try {
            self::from_json($content);
        } catch (\coding_exception $e) {
            throw new envelope_exception('instruction_invalid');
        }
        set_config('awsranges', $content, 'tool_secbackup');
        set_config('awsrangesseq', $instruction->seq(), 'tool_secbackup');
        set_config('awsrangesendpoint', self::endpoint(), 'tool_secbackup');
        return true;
    }

    /**
     * Checks the body of an aws_ranges instruction.
     *
     * @param instruction $instruction The instruction.
     * @return array The body, unchanged.
     * @throws envelope_exception With the code instruction_invalid.
     */
    private static function checked_body(instruction $instruction): array {
        if ($instruction->kind() !== instruction::KIND_AWS_RANGES) {
            throw new \coding_exception('Not an aws_ranges instruction.');
        }
        $body = $instruction->body();
        $names = array_keys($body);
        sort($names);
        // The patterns end with \z: $ also matches before a final line feed.
        $valid = $names === ['create_date', 'sha256', 'size', 'sync_token']
            && is_string($body['sync_token']) && $body['sync_token'] !== '' && strlen($body['sync_token']) <= 64
            && is_string($body['create_date']) && preg_match('/^\d{4}(-\d{2}){5}\z/', $body['create_date']) === 1
            && is_string($body['sha256']) && preg_match('/^[0-9a-f]{64}\z/', $body['sha256']) === 1
            && is_int($body['size']) && $body['size'] >= 1 && $body['size'] <= self::MAX_SIZE;
        if (!$valid) {
            throw new envelope_exception('instruction_invalid');
        }
        return $body;
    }

    /**
     * Reads a list in the format of data/aws-ranges.json.
     *
     * The list has to be exactly what the tool writes: two strings, optionally the region of the S3 prefixes, and for each
     * service a list of at least five different prefixes that are strings of the form a.b.c.d/n with n from 8 to 32.
     * A list with fewer prefixes, or a prefix that covers
     * a large part of the internet, would make the check of the addresses meaningless.
     *
     * @param string $json The list.
     * @return self
     * @throws \coding_exception When the list is not valid.
     */
    public static function from_json(string $json): self {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['syncToken'] ?? null) || !is_string($data['createDate'] ?? null)) {
            throw new \coding_exception('The AWS range list is not valid.');
        }
        $region = array_key_exists('s3_region', $data) ? $data['s3_region'] : self::DEFAULT_REGION;
        // The pattern ends with \z: $ also matches before a final line feed.
        if (!is_string($region) || preg_match('/^[a-z]{2}-[a-z]+-\d\z/', $region) !== 1) {
            throw new \coding_exception('The AWS range list is not valid.');
        }
        $networks = [];
        foreach ([self::SERVICE_CLOUDFRONT, self::SERVICE_S3] as $service) {
            $prefixes = $data['services'][$service] ?? null;
            if (!is_array($prefixes) || !array_is_list($prefixes)) {
                throw new \coding_exception('The AWS range list is not valid.');
            }
            // A prefix that is repeated, or written in another way for the same network, counts once.
            $unique = [];
            foreach ($prefixes as $prefix) {
                $network = self::network($prefix);
                $unique[$network[0] . '/' . $network[1]] = $network;
            }
            if (count($unique) < self::MIN_PREFIXES) {
                throw new \coding_exception('The AWS range list has too few prefixes of ' . $service . '.');
            }
            $networks[$service] = array_values($unique);
        }
        return new self($networks, $data['syncToken'], $data['createDate'], $region);
    }

    /**
     * Turns "a.b.c.d/n" into [network, mask].
     *
     * @param mixed $prefix The prefix.
     * @return int[]
     */
    private static function network(mixed $prefix): array {
        // The pattern ends with \z: $ also matches before a final line feed.
        // The length starts at 8: a shorter prefix would cover a large part of the internet.
        if (
            !is_string($prefix)
            || preg_match('~^([0-9.]+)/([89]|[12][0-9]|3[0-2])\z~', $prefix, $m) !== 1
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
     * Region of the S3 prefixes.
     *
     * @return string Such as ap-northeast-1.
     */
    public function s3_region(): string {
        return $this->region;
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
