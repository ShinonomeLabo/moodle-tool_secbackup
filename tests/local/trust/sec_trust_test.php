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

namespace tool_secbackup\local\trust;

use tool_secbackup\tests\envelope_builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\trust\sec_trust::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\trust\envelope_exception::class)]
/**
 * Tests for the verification of the service's signed responses.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\trust\sec_trust
 * @covers     \tool_secbackup\local\trust\envelope_exception
 */
final class sec_trust_test extends \advanced_testcase {
    /** @var string A response the service signed with the RFC 8032 TEST 2 key. */
    private const FIXED = '{"v":1,"kid":"39f713d0a644253f",'
        . '"payload":"eyJ0eXBlIjoiZW5yb2xsbWVudCIsInNpdGVfaWQiOiIxMTExMTExMS0yMjIyLTQzMzMtODQ0NC01NTU1NTU1NTU1NTUifQ",'
        . '"sig":"1qXTc4FPfq58qxt8DQXKaM8sQMyF-G_tJwTLKrdckxLLPFyI2EOn5jKGnmvUKIZBLiyy9adUUM3KQbpPmRwVAg"}';

    /**
     * A trust that knows only the test key.
     *
     * @return sec_trust
     */
    private static function trust(): sec_trust {
        return new sec_trust([base64_decode(envelope_builder::PUBLIC_KEY)]);
    }

    /**
     * Expects open() to fail with one error code.
     *
     * @param string $code Error code.
     * @param string $body Response body.
     */
    private function expect_failure(string $code, string $body): void {
        try {
            self::trust()->open($body);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_opens_the_fixed_vector(): void {
        $this->assertSame(
            ['type' => 'enrollment', 'site_id' => '11111111-2222-4333-8444-555555555555'],
            self::trust()->open(self::FIXED)
        );
    }

    public function test_key_id_matches_the_service(): void {
        $this->assertSame(envelope_builder::KID, sec_trust::kid(base64_decode(envelope_builder::PUBLIC_KEY)));
        $this->assertSame([envelope_builder::KID], self::trust()->key_ids());
    }

    public function test_no_production_key_is_embedded_yet(): void {
        // The release plan embeds the production keys. When it does, this test fails on purpose:
        // replace it with a test of the embedded key IDs.
        $this->assertSame([], sec_trust::current()->key_ids());
    }

    public function test_current_adds_the_developer_keys(): void {
        $this->resetAfterTest();
        (new envelope_builder())->trust();
        $this->assertSame([envelope_builder::KID], sec_trust::current()->key_ids());
        $this->assertSame('enrollment', sec_trust::current()->open(self::FIXED)['type']);
    }

    public function test_without_keys_everything_is_untrusted(): void {
        $this->expectException(envelope_exception::class);
        (new sec_trust([]))->open(self::FIXED);
    }

    public function test_another_key_is_untrusted(): void {
        $other = new envelope_builder(str_repeat('07', 32));
        $this->expect_failure('envelope_untrusted', $other->body(['type' => 'enrollment']));
    }

    public function test_a_changed_payload_fails_the_signature(): void {
        $parts = (new envelope_builder())->parts(['type' => 'enrollment', 'site_id' => 'a']);
        $parts['payload'] = envelope_builder::b64url('{"type":"enrollment","site_id":"b"}');
        $this->expect_failure('envelope_signature', json_encode($parts));
    }

    public function test_a_signature_for_another_key_id_fails(): void {
        $builder = new envelope_builder();
        $parts = $builder->parts(['type' => 'enrollment']);
        $parts['sig'] = $builder->signature('0000000000000000', $parts['payload']);
        $this->expect_failure('envelope_signature', json_encode($parts));
    }

    /**
     * Builds a correctly signed response whose payload is not a JSON object.
     *
     * @param string $rawpayload Raw payload text.
     * @return string
     */
    private static function signed_raw_payload(string $rawpayload): string {
        $builder = new envelope_builder();
        $encoded = envelope_builder::b64url($rawpayload);
        return json_encode(['v' => 1, 'kid' => $builder->kid(), 'payload' => $encoded,
            'sig' => $builder->signature($builder->kid(), $encoded)]);
    }

    /**
     * Bodies that are not signed responses.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        $good = (new envelope_builder())->parts(['type' => 'enrollment']);
        return [
            'not json' => ['not json'],
            'json list' => ['[1,2,3,4]'],
            'missing signature' => [json_encode(array_diff_key($good, ['sig' => true]))],
            'extra field' => [json_encode($good + ['extra' => 1])],
            'version 2' => [json_encode(['v' => 2] + $good)],
            'version as text' => [json_encode(['v' => '1'] + $good)],
            'upper case key id' => [json_encode(['kid' => strtoupper($good['kid'])] + $good)],
            'payload not base64url' => [json_encode(['payload' => 'a+b/'] + $good)],
            'short signature' => [json_encode(['sig' => envelope_builder::b64url('short')] + $good)],
            'payload is a list' => [self::signed_raw_payload('[1]')],
            'payload is not json' => [self::signed_raw_payload('nope')],
            'too large' => [str_repeat(' ', sec_trust::MAX_BODY + 1)],
            'too deep' => ['{"v":1,"kid":{"a":{"b":{"c":{"d":{}}}}},"payload":"","sig":""}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    /**
     * A body that is not a signed response is refused with the error envelope_malformed.
     *
     * @dataProvider malformed_provider
     * @param string $body Response body.
     */
    public function test_malformed_bodies_are_refused(string $body): void {
        $this->expect_failure('envelope_malformed', $body);
    }

    public function test_keys_must_have_32_bytes(): void {
        $this->expectException(\coding_exception::class);
        new sec_trust(['short']);
    }

    public function test_the_error_codes_are_language_strings(): void {
        foreach (['envelope_malformed', 'envelope_signature', 'envelope_untrusted'] as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }
}
