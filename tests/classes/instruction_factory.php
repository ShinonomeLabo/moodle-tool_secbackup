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

use tool_secbackup\local\api\instruction;
use tool_secbackup\local\trust\sec_trust;

/**
 * Builds instructions signed by the test key, for tests of the code that applies them.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instruction_factory {
    /**
     * A valid settings body with some fields replaced.
     *
     * @param array $changes Fields to replace.
     * @return array
     */
    public static function settings_body(array $changes = []): array {
        return array_merge([
            'timezone' => 'Asia/Tokyo',
            'window_start' => '02:00',
            'window_end' => '05:00',
            'runs_per_day' => 1,
            'full_interval' => 'weekly',
            'bandwidth_mbps' => 0,
            'include_logs' => false,
        ], $changes);
    }

    /**
     * An instruction signed by the test key and read as the plugin reads it.
     *
     * @param string $kind settings or aws_ranges.
     * @param int $seq Sequence number.
     * @param array $body Body.
     * @return instruction
     */
    public static function make(string $kind, int $seq, array $body): instruction {
        $payload = [
            'type' => 'instruction',
            'kind' => $kind,
            'site_id' => $kind === 'settings' ? fake_service::SITE_ID : null,
            'seq' => $seq,
            'issued_at' => '2026-10-18T09:00:00Z',
            'body' => $body,
        ];
        $trust = new sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]);
        return instruction::from_envelope((new envelope_builder())->parts($payload), $trust, fake_service::SITE_ID);
    }
}
