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
 * The receipt that the service signs when it has verified a run: the proof that the backup is stored.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receipt {
    /**
     * Verifies a receipt against what this site sent.
     *
     * @param array $envelope The envelope from the run state.
     * @param sec_trust $trust Trusted response keys.
     * @param string $siteid The site's ID.
     * @param string $runid The run's ID.
     * @param string $manifestsha256 SHA-256 of the manifest that was sent, standard base64.
     * @param string $indexsha256 SHA-256 of the index that was sent, standard base64.
     * @param int $chunkcount Number of chunks the index lists.
     * @return array The payload.
     * @throws envelope_exception With the code of sec_trust when the envelope is not signed by a trusted key, and with
     *         receipt_mismatch (the field in the debug information) when the receipt is not for this run or is malformed.
     */
    public static function verify(
        array $envelope,
        sec_trust $trust,
        string $siteid,
        string $runid,
        string $manifestsha256,
        string $indexsha256,
        int $chunkcount
    ): array {
        $payload = $trust->open_envelope($envelope);
        $checks = [
            'type' => ($payload['type'] ?? null) === 'receipt',
            'site_id' => self::same($payload['site_id'] ?? null, $siteid),
            'run_id' => self::same($payload['run_id'] ?? null, $runid),
            'kind' => ($payload['kind'] ?? null) === 'full',
            'manifest_sha256' => self::same($payload['manifest_sha256'] ?? null, $manifestsha256),
            'index_sha256' => self::same($payload['index_sha256'] ?? null, $indexsha256),
            'chunk_count' => ($payload['chunk_count'] ?? null) === $chunkcount,
            'stored_bytes' => is_int($payload['stored_bytes'] ?? null) && $payload['stored_bytes'] >= 0,
            'verified_at' => is_string($payload['verified_at'] ?? null) && self::zulu($payload['verified_at']),
        ];
        foreach ($checks as $field => $ok) {
            if (!$ok) {
                throw new envelope_exception('receipt_mismatch', null, $field);
            }
        }
        return $payload;
    }

    /**
     * Whether a value of the receipt is the string this site expects.
     *
     * @param mixed $value The value.
     * @param string $expected What was sent.
     * @return bool
     */
    private static function same(mixed $value, string $expected): bool {
        return is_string($value) && hash_equals($expected, $value);
    }

    /**
     * Whether a text is a time such as 2026-10-12T03:04:05Z.
     *
     * @param string $value The text.
     * @return bool
     */
    private static function zulu(string $value): bool {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
