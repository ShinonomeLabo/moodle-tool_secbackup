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
use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\network_fakes;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\api\receipt::class)]
/**
 * Tests for the receipt check.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\api\receipt
 */
final class receipt_test extends \basic_testcase {
    /** @var string Site ID. */
    private const SITE = '11111111-2222-4333-8444-555555555555';

    /** @var string Run ID. */
    private const RUN = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';

    /** @var string SHA-256 of the manifest, standard base64. */
    private const MANIFEST = 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs=';

    /** @var string SHA-256 of the index, standard base64. */
    private const INDEX = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /**
     * The payload of a good receipt.
     *
     * @param array $override Fields that replace those of the good payload.
     * @return array
     */
    private static function payload(array $override = []): array {
        return array_merge([
            'type' => 'receipt',
            'site_id' => self::SITE,
            'run_id' => self::RUN,
            'kind' => 'full',
            'manifest_sha256' => self::MANIFEST,
            'index_sha256' => self::INDEX,
            'chunk_count' => 12,
            'stored_bytes' => 123456,
            'verified_at' => '2026-10-18T03:04:05Z',
        ], $override);
    }

    /**
     * Verifies a receipt with these fields against the values of the good run.
     *
     * @param array $override Fields that replace those of the good payload.
     * @param string|null $key Seed of the signing key; null for the trusted one.
     * @return array The payload.
     */
    private static function verify(array $override = [], ?string $key = null): array {
        $builder = $key === null ? new envelope_builder() : new envelope_builder($key);
        return receipt::verify(
            $builder->parts(self::payload($override)),
            network_fakes::trust(),
            self::SITE,
            self::RUN,
            self::MANIFEST,
            self::INDEX,
            12
        );
    }

    /**
     * Expects receipt::verify() to refuse an envelope.
     *
     * @param string $code Expected error code.
     * @param array $envelope The envelope.
     * @param array $sent What this site sent: site, run, manifest digest, index digest and chunk count.
     */
    private function assert_refused(string $code, array $envelope, array $sent): void {
        try {
            receipt::verify($envelope, network_fakes::trust(), ...$sent);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_a_receipt_for_this_run_is_taken_and_its_payload_returned(): void {
        $payload = self::verify();
        $this->assertSame(self::payload(), $payload);
    }

    public function test_a_receipt_of_no_bytes_is_taken(): void {
        $this->assertSame(0, self::verify(['stored_bytes' => 0])['stored_bytes']);
    }

    /**
     * Receipts that are not for this run, or are malformed, and the field that is wrong.
     *
     * @return array
     */
    public static function mismatch_provider(): array {
        return [
            'type' => [['type' => 'enrollment'], 'type'],
            'other site' => [['site_id' => '99999999-2222-4333-8444-555555555555'], 'site_id'],
            'site as a number' => [['site_id' => 5], 'site_id'],
            'site missing' => [['site_id' => null], 'site_id'],
            'other run' => [['run_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002'], 'run_id'],
            'run with a line feed' => [['run_id' => self::RUN . "\n"], 'run_id'],
            'kind' => [['kind' => 'incremental'], 'kind'],
            'other manifest digest' => [['manifest_sha256' => self::INDEX], 'manifest_sha256'],
            'manifest digest as a number' => [['manifest_sha256' => 5], 'manifest_sha256'],
            'other index digest' => [['index_sha256' => self::MANIFEST], 'index_sha256'],
            'index digest with a line feed' => [['index_sha256' => self::INDEX . "\n"], 'index_sha256'],
            'one chunk less' => [['chunk_count' => 11], 'chunk_count'],
            'one chunk more' => [['chunk_count' => 13], 'chunk_count'],
            'chunk count as text' => [['chunk_count' => '12'], 'chunk_count'],
            'stored bytes below zero' => [['stored_bytes' => -1], 'stored_bytes'],
            'stored bytes as text' => [['stored_bytes' => '5'], 'stored_bytes'],
            'stored bytes missing' => [['stored_bytes' => null], 'stored_bytes'],
            'verified with a space' => [['verified_at' => '2026-10-18 03:04:05'], 'verified_at'],
            'verified on a day that is not' => [['verified_at' => '2026-02-30T03:04:05Z'], 'verified_at'],
            'verified as a number' => [['verified_at' => 1760000000], 'verified_at'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatch_provider')]
    /**
     * A receipt that does not match what this site sent is refused, and the field is named in the debug information.
     *
     * @dataProvider mismatch_provider
     * @param array $override Changes to the good payload.
     * @param string $field The field that is wrong.
     */
    public function test_a_receipt_that_does_not_match_is_refused(array $override, string $field): void {
        try {
            self::verify($override);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('receipt_mismatch', $e->errorcode);
            $this->assertSame($field, $e->debuginfo);
        }
    }

    /**
     * The values this site sent, one of which differs from the receipt.
     *
     * @return array
     */
    public static function sent_provider(): array {
        return [
            'site' => [['99999999-2222-4333-8444-555555555555', self::RUN, self::MANIFEST, self::INDEX, 12]],
            'run' => [[self::SITE, 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002', self::MANIFEST, self::INDEX, 12]],
            'manifest' => [[self::SITE, self::RUN, self::INDEX, self::INDEX, 12]],
            'index' => [[self::SITE, self::RUN, self::MANIFEST, self::MANIFEST, 12]],
            'count' => [[self::SITE, self::RUN, self::MANIFEST, self::INDEX, 13]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sent_provider')]
    /**
     * A good receipt does not fit a run whose site, run, digests or chunk count are others.
     *
     * @dataProvider sent_provider
     * @param array $sent What this site sent: site, run, manifest digest, index digest and chunk count.
     */
    public function test_a_receipt_does_not_fit_another_run(array $sent): void {
        $this->assert_refused('receipt_mismatch', (new envelope_builder())->parts(self::payload()), $sent);
    }

    public function test_a_receipt_signed_by_another_key_is_refused_before_its_content_is_read(): void {
        try {
            self::verify([], str_repeat('07', 32));
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('envelope_untrusted', $e->errorcode);
        }
    }

    public function test_a_receipt_with_a_broken_signature_is_refused(): void {
        $parts = (new envelope_builder())->parts(self::payload());
        $parts['sig'] = envelope_builder::b64url(str_repeat("\0", 64));
        $this->assert_refused('envelope_signature', $parts, [self::SITE, self::RUN, self::MANIFEST, self::INDEX, 12]);
    }

    public function test_a_receipt_that_is_not_an_envelope_is_refused(): void {
        $this->assert_refused('envelope_malformed', ['v' => 1], [self::SITE, self::RUN, self::MANIFEST, self::INDEX, 12]);
    }

    public function test_the_message_of_a_mismatch_is_a_language_string_in_both_languages(): void {
        $this->assertTrue(get_string_manager()->string_exists('receipt_mismatch', 'tool_secbackup'));
        $e = new envelope_exception('receipt_mismatch');
        $this->assertNotSame('', $e->message());
    }
}
