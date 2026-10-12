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
     * @param sec_trust|null $trust The trust to ask; the one that knows only the test key when null.
     */
    private function expect_failure(string $code, string $body, ?sec_trust $trust = null): void {
        try {
            ($trust ?? self::trust())->open($body);
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
        global $CFG;
        // The release plan embeds the production keys. When it does, this test fails on purpose:
        // replace it with a test of the embedded key IDs.
        // The developer keys that this site's config.php may hold must not change the result.
        $this->resetAfterTest();
        unset($CFG->tool_secbackup_developer);
        $this->assertSame([], sec_trust::current()->key_ids());
    }

    public function test_a_key_id_made_of_digits_is_still_text(): void {
        // PHP turns an array key such as "8074968321729597" into an integer. The key IDs have to come back as text.
        $builder = new envelope_builder(str_repeat('00', 30) . '0d07');
        $this->assertSame('8074968321729597', $builder->kid());
        $trust = new sec_trust([base64_decode($builder->public_key_base64())]);
        $this->assertSame(['8074968321729597'], $trust->key_ids());
        $this->assertSame('enrollment', $trust->open($builder->body(['type' => 'enrollment']))['type']);
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
     * A correctly signed response, padded with blanks to a length.
     *
     * @param int $length Length of the body in bytes.
     * @return string
     */
    private static function padded_body(int $length): string {
        $body = (new envelope_builder())->body(['type' => 'enrollment']);
        return $body . str_repeat(' ', $length - strlen($body));
    }

    /**
     * A JSON object nested in itself, with a number inside the innermost one.
     *
     * @param int $levels Number of objects.
     * @return string
     */
    private static function nested_object(int $levels): string {
        return str_repeat('{"a":', $levels) . '1' . str_repeat('}', $levels);
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
            'too large but signed' => [self::padded_body(sec_trust::MAX_BODY + 1)],
            'short key id' => [json_encode(['kid' => substr($good['kid'], 0, 15)] + $good)],
            'long key id' => [json_encode(['kid' => $good['kid'] . '0'] + $good)],
            'key id with a line break' => [json_encode(['kid' => $good['kid'] . "\n"] + $good)],
            'key id is a list' => [json_encode(['kid' => [1]] + $good)],
            // The number 1234 reads as valid base64url text (3 bytes), so only the type check refuses it.
            'payload is a number' => [json_encode(['payload' => 1234] + $good)],
            'payload field is a list' => [json_encode(['payload' => ['a']] + $good)],
            'payload is empty' => [json_encode(['payload' => ''] + $good)],
            'signature is a number' => [json_encode(['sig' => 123] + $good)],
            'signature field is a list' => [json_encode(['sig' => ['a']] + $good)],
            'misspelt field' => [json_encode(array_diff_key($good, ['sig' => true]) + ['sign' => $good['sig']])],
            'signature not base64url' => [json_encode(['sig' => 'a+b/'] + $good)],
            'long signature' => [json_encode(['sig' => envelope_builder::b64url(str_repeat('a', 65))] + $good)],
            'payload nested too deep' => [self::signed_raw_payload(self::nested_object(16))],
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

    /**
     * Values that are not a 32-byte public key.
     *
     * @return array
     */
    public static function invalid_key_provider(): array {
        return [
            'empty' => [''],
            '31 bytes' => [str_repeat('a', 31)],
            '33 bytes' => [str_repeat('a', 33)],
            'a number' => [123],
            'a list' => [['a']],
            'nothing' => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_key_provider')]
    /**
     * Only 32 bytes of text are accepted as a key.
     *
     * @dataProvider invalid_key_provider
     * @param mixed $key The value given as a key.
     */
    public function test_every_key_must_be_32_bytes_of_text(mixed $key): void {
        $this->expectException(\coding_exception::class);
        new sec_trust([$key]);
    }

    public function test_a_key_given_twice_is_one_key(): void {
        $raw = base64_decode(envelope_builder::PUBLIC_KEY);
        $this->assertSame([envelope_builder::KID], (new sec_trust([$raw, $raw]))->key_ids());
    }

    public function test_each_trusted_key_verifies_its_own_responses(): void {
        $first = new envelope_builder();
        $second = new envelope_builder(str_repeat('07', 32));
        $trust = new sec_trust([base64_decode($second->public_key_base64()), base64_decode($first->public_key_base64())]);
        $this->assertSame([$second->kid(), $first->kid()], $trust->key_ids());
        $this->assertSame('first', $trust->open($first->body(['type' => 'first']))['type']);
        $this->assertSame('second', $trust->open($second->body(['type' => 'second']))['type']);
    }

    public function test_a_response_signed_by_another_trusted_key_fails_the_signature(): void {
        // The key ID names the first key, but the second key signed: both are trusted, the signature still fails.
        $first = new envelope_builder();
        $second = new envelope_builder(str_repeat('07', 32));
        $trust = new sec_trust([base64_decode($first->public_key_base64()), base64_decode($second->public_key_base64())]);
        $parts = $first->parts(['type' => 'enrollment']);
        $parts['sig'] = $second->signature($first->kid(), $parts['payload']);
        $this->expect_failure('envelope_signature', json_encode($parts), $trust);
    }

    public function test_the_body_limit_is_64_kib(): void {
        $this->assertSame(65536, sec_trust::MAX_BODY);
    }

    public function test_a_body_of_exactly_the_largest_size_is_read(): void {
        $body = self::padded_body(sec_trust::MAX_BODY);
        $this->assertSame(sec_trust::MAX_BODY, strlen($body));
        $this->assertSame('enrollment', self::trust()->open($body)['type']);
    }

    public function test_a_payload_nested_up_to_the_limit_is_read(): void {
        $this->assertIsArray(self::trust()->open(self::signed_raw_payload(self::nested_object(15)))['a']);
    }

    public function test_the_whole_payload_comes_back(): void {
        $payload = [
            'type' => 'enrollment',
            'site_name' => '東雲 / テスト',
            'nested' => ['list' => [1, 2], 'none' => null, 'flag' => true],
        ];
        $this->assertSame($payload, self::trust()->open((new envelope_builder())->body($payload)));
    }

    public function test_a_refused_response_is_a_plugin_exception_that_shows_nothing_of_the_body(): void {
        try {
            self::trust()->open('{"secret":"do not show"}');
            $this->fail('No exception');
        } catch (\tool_secbackup\local\plugin_exception $e) {
            $this->assertInstanceOf(envelope_exception::class, $e);
            $this->assertSame(get_string('envelope_malformed', 'tool_secbackup'), $e->message());
            $this->assertNull($e->a);
            $this->assertNull($e->debuginfo);
        }
    }

    public function test_current_does_not_ignore_invalid_developer_settings(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->tool_secbackup_developer = ['trustedkey' => []];
        try {
            sec_trust::current();
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('developersettings_invalid', $e->errorcode);
        }
    }

    public function test_the_error_codes_are_language_strings(): void {
        foreach (['envelope_malformed', 'envelope_signature', 'envelope_untrusted'] as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }
}
