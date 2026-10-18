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

namespace tool_secbackup\local\api;

use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\local\trust\sec_trust;

/**
 * A signed instruction from the backup service: the answer to a status request carries the latest one of each kind.
 *
 * The signature, the site and the shape of the instruction are checked here. The rules for the body of each kind belong to
 * the class that applies it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instruction {
    /** @var string Kind: the backup settings of this site. */
    public const KIND_SETTINGS = 'settings';

    /** @var string Kind: the list of AWS address ranges, which is the same for all sites. */
    public const KIND_AWS_RANGES = 'aws_ranges';

    /** @var string[] The fields of an instruction, sorted. */
    private const FIELDS = ['body', 'issued_at', 'kind', 'seq', 'site_id', 'type'];

    /** @var string Kind. */
    private string $kind;

    /** @var int Sequence number. */
    private int $seq;

    /** @var array Body. */
    private array $body;

    /**
     * Constructor.
     *
     * @param string $kind Kind.
     * @param int $seq Sequence number.
     * @param array $body Body.
     */
    private function __construct(string $kind, int $seq, array $body) {
        $this->kind = $kind;
        $this->seq = $seq;
        $this->body = $body;
    }

    /**
     * Verifies an instruction envelope and reads it.
     *
     * @param array $envelope The envelope as it came in the status answer.
     * @param sec_trust $trust The trusted keys.
     * @param string $siteid ID of this site.
     * @return self
     * @throws envelope_exception With the code instruction_invalid, whatever is wrong with the envelope.
     */
    public static function from_envelope(array $envelope, sec_trust $trust, string $siteid): self {
        try {
            $payload = $trust->open_envelope($envelope);
        } catch (envelope_exception $e) {
            throw new envelope_exception('instruction_invalid');
        }
        $names = array_keys($payload);
        sort($names);
        $kind = $payload['kind'] ?? null;
        $seq = $payload['seq'] ?? null;
        $issuedat = $payload['issued_at'] ?? null;
        $body = $payload['body'] ?? null;
        $valid = $names === self::FIELDS
            && $payload['type'] === 'instruction'
            && ($kind === self::KIND_SETTINGS ? $payload['site_id'] === $siteid : $kind === self::KIND_AWS_RANGES
                && $payload['site_id'] === null)
            && is_int($seq) && $seq > 0
            && is_string($issuedat) && self::is_zulu_time($issuedat)
            && is_array($body) && !array_is_list($body);
        if (!$valid) {
            throw new envelope_exception('instruction_invalid');
        }
        return new self($kind, $seq, $body);
    }

    /**
     * Whether a text is a time such as 2026-10-18T09:00:00Z.
     *
     * @param string $value The text.
     * @return bool
     */
    private static function is_zulu_time(string $value): bool {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }

    /**
     * Kind of the instruction.
     *
     * @return string settings or aws_ranges.
     */
    public function kind(): string {
        return $this->kind;
    }

    /**
     * Sequence number, which grows for each kind.
     *
     * @return int
     */
    public function seq(): int {
        return $this->seq;
    }

    /**
     * Body of the instruction.
     *
     * @return array
     */
    public function body(): array {
        return $this->body;
    }
}
