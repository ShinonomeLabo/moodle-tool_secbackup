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
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\instruction::class)]
/**
 * Tests for the signed instructions.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\instruction
 * @covers     \tool_secbackup\local\trust\sec_trust
 */
final class instruction_test extends \basic_testcase {
    /**
     * The payload of a valid settings instruction.
     *
     * @param array $changes Fields to replace.
     * @return array
     */
    public static function payload(array $changes = []): array {
        return array_merge([
            'type' => 'instruction',
            'kind' => 'settings',
            'site_id' => fake_service::SITE_ID,
            'seq' => 12,
            'issued_at' => '2026-10-18T09:00:00Z',
            'body' => ['timezone' => 'Asia/Tokyo'],
        ], $changes);
    }

    /** @var sec_trust Trust with the test key. */
    private sec_trust $trust;

    protected function setUp(): void {
        parent::setUp();
        $this->trust = new sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]);
    }

    public function test_a_valid_settings_instruction_is_read(): void {
        $i = instruction::from_envelope((new envelope_builder())->parts(self::payload()), $this->trust, fake_service::SITE_ID);
        $this->assertSame('settings', $i->kind());
        $this->assertSame(12, $i->seq());
        $this->assertSame(['timezone' => 'Asia/Tokyo'], $i->body());
    }

    public function test_a_valid_aws_ranges_instruction_has_no_site(): void {
        $payload = self::payload(['kind' => 'aws_ranges', 'site_id' => null, 'body' => ['sha256' => 'x']]);
        $i = instruction::from_envelope((new envelope_builder())->parts($payload), $this->trust, fake_service::SITE_ID);
        $this->assertSame('aws_ranges', $i->kind());
    }

    /**
     * Payloads that are not instructions for this site.
     *
     * @return array[]
     */
    public static function invalid_payloads(): array {
        $other = '99999999-2222-4333-8444-555555555555';
        return [
            'another site' => [self::payload(['site_id' => $other])],
            'no site on settings' => [self::payload(['site_id' => null])],
            'a site on aws_ranges' => [self::payload(['kind' => 'aws_ranges'])],
            'an unknown kind' => [self::payload(['kind' => 'wipe'])],
            'a kind that is not text' => [self::payload(['kind' => ['settings']])],
            'another type' => [self::payload(['type' => 'receipt'])],
            'seq 0' => [self::payload(['seq' => 0])],
            'negative seq' => [self::payload(['seq' => -1])],
            'seq as text' => [self::payload(['seq' => '12'])],
            'a time without the Z' => [self::payload(['issued_at' => '2026-10-18T09:00:00'])],
            'a time that does not exist' => [self::payload(['issued_at' => '2026-02-30T09:00:00Z'])],
            'a time with a final line feed' => [self::payload(['issued_at' => "2026-10-18T09:00:00Z\n"])],
            'a time that is not text' => [self::payload(['issued_at' => 1760000000])],
            'a body that is a list' => [self::payload(['body' => ['a']])],
            'a body that is text' => [self::payload(['body' => 'x'])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_payloads')]
    /**
     * An instruction that is not for this site, or not in the form, is refused.
     *
     * @dataProvider invalid_payloads
     * @param array $payload Payload.
     */
    public function test_invalid_payloads_are_refused(array $payload): void {
        try {
            instruction::from_envelope((new envelope_builder())->parts($payload), $this->trust, fake_service::SITE_ID);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('instruction_invalid', $e->errorcode);
        }
    }

    public function test_a_payload_without_a_field_is_refused(): void {
        foreach (['type', 'kind', 'site_id', 'seq', 'issued_at', 'body'] as $field) {
            $payload = self::payload();
            unset($payload[$field]);
            try {
                instruction::from_envelope((new envelope_builder())->parts($payload), $this->trust, fake_service::SITE_ID);
                $this->fail('No exception without ' . $field);
            } catch (envelope_exception $e) {
                $this->assertSame('instruction_invalid', $e->errorcode, $field);
            }
        }
    }

    public function test_an_extra_field_is_refused(): void {
        $this->expectException(envelope_exception::class);
        $envelope = (new envelope_builder())->parts(self::payload(['extra' => 1]));
        instruction::from_envelope($envelope, $this->trust, fake_service::SITE_ID);
    }

    public function test_signature_problems_are_instruction_invalid(): void {
        $good = (new envelope_builder())->parts(self::payload());
        $badsig = array_merge($good, ['sig' => envelope_builder::b64url(str_repeat("\0", 64))]);
        $untrusted = (new envelope_builder(str_repeat('07', 32)))->parts(self::payload());
        $malformed = ['v' => 1, 'kid' => $good['kid']];
        $notobject = array_merge($good, ['payload' => envelope_builder::b64url('[1]')]);
        $notobject['sig'] = (new envelope_builder())->signature($good['kid'], $notobject['payload']);
        $cases = ['bad signature' => $badsig, 'untrusted key' => $untrusted, 'malformed' => $malformed, 'list' => $notobject];
        foreach ($cases as $name => $envelope) {
            try {
                instruction::from_envelope($envelope, $this->trust, fake_service::SITE_ID);
                $this->fail('No exception for ' . $name);
            } catch (envelope_exception $e) {
                $this->assertSame('instruction_invalid', $e->errorcode, $name);
            }
        }
    }

    public function test_the_trust_opens_a_decoded_envelope_like_a_body(): void {
        $parts = (new envelope_builder())->parts(['a' => 1]);
        $this->assertSame(['a' => 1], $this->trust->open_envelope($parts));
        $this->assertSame(['a' => 1], $this->trust->open(json_encode($parts)));
    }

    public function test_an_envelope_larger_than_a_response_is_refused(): void {
        $parts = (new envelope_builder())->parts(['a' => str_repeat('x', sec_trust::MAX_BODY)]);
        try {
            $this->trust->open_envelope($parts);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_malformed', $e->errorcode);
        }
    }
}
