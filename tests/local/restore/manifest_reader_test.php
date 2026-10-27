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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\backup\manifest_builder;
use tool_secbackup\local\crypto\artifact_signature;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\manifest_reader::class)]
/**
 * Tests for the reader of the list of generations that a restore trusts only after it has checked it.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\manifest_reader
 */
final class manifest_reader_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '0b1c2d3e-4f50-4617-8899-aabbccddeeff';

    /** @var string Run ID of the tests. */
    private const RUN = '22222222-2222-4222-8222-222222222222';

    /** @var string Another run. */
    private const OTHER_RUN = '33333333-3333-4333-8333-333333333333';

    /** @var signing_keypair The signing key pair of the site's plugin. */
    private signing_keypair $pair;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->pair = signing_keypair::generate();
    }

    /**
     * The X25519 key pair that the chunks of the tests are sealed to.
     *
     * @return string
     */
    private static function boxpair(): string {
        return sodium_crypto_box_seed_keypair(str_repeat("\x09", 32));
    }

    /**
     * The keys of the site that a restore holds.
     *
     * @return site_keys
     */
    private static function keys(): site_keys {
        return new site_keys([sodium_crypto_box_secretkey(self::boxpair())]);
    }

    /**
     * The header of a list.
     *
     * @param array $changes Values that replace those of the default.
     * @return array
     */
    private static function header(array $changes = []): array {
        return $changes + [
            'v' => 1, 'site_id' => self::SITE, 'run_id' => self::RUN, 'kind' => 'full',
            'moodle' => ['release' => '4.5.1', 'version' => 2024100701, 'build' => '20241104'],
            'db' => ['family' => 'pgsql', 'prefix' => 'mdl_'],
            'plugins' => ['mod_attendance' => ['version' => 2025010100, 'standard' => false]],
            'config' => ['prefix' => 'mdl_', 'forced' => ['config' => [], 'plugins' => []]],
            'core_diff' => ['official' => false, 'modified' => [], 'added' => [], 'deleted' => []],
            'missing_files' => [],
        ];
    }

    /**
     * One entry of each type.
     *
     * @return array[]
     */
    private static function entries(): array {
        $chunk = str_repeat('a', 64);
        return [
            [
                't' => 'db', 'table' => 'course', 'from' => 1, 'to' => 40, 'rows' => 40, 'plainsha256' => str_repeat('b', 64),
                'plainlength' => 1234, 'chunk' => $chunk, 'offset' => 86, 'length' => 500,
            ],
            [
                't' => 'file', 'contenthash' => sha1('x'), 'size' => 10, 'chunk' => $chunk, 'offset' => 86, 'length' => 30,
                'piece' => 0, 'pieces' => 1,
            ],
            [
                't' => 'dataroot', 'root' => 'dataroot', 'path' => 'lang/ja/local.php', 'size' => 5,
                'sha256' => str_repeat('c', 64),
                'chunk' => $chunk, 'offset' => 86, 'length' => 30, 'piece' => 0, 'pieces' => 1,
            ],
            [
                't' => 'code', 'root' => 'core', 'path' => 'lib/setup.php', 'size' => 5, 'sha256' => str_repeat('d', 64),
                'chunk' => $chunk, 'offset' => 86, 'length' => 30, 'piece' => 0, 'pieces' => 1,
            ],
        ];
    }

    /**
     * Makes the list with the builder of a run, signs it, and gives the arguments of the reader.
     *
     * @param array $header The first line.
     * @param array[] $entries The entries.
     * @return array The values of the constructor, in order, after the keys (path, size, sha256, signature, public key).
     */
    private function built(array $header, array $entries): array {
        $builder = new manifest_builder(make_request_directory());
        foreach ($entries as $entry) {
            $builder->append($entry);
        }
        $chunk = $builder->build($header, self::RUN, sodium_crypto_box_publickey(self::boxpair()), self::SITE);
        return $this->described($chunk->path, $chunk->size, $chunk->sha256base64);
    }

    /**
     * Makes a list of the given text lines, compressed as one gzip stream, in a chunk of type 4.
     *
     * @param string $text The lines of the list, each ended by a line feed.
     * @param int $cut Bytes cut from the end of the compressed list.
     * @return array As built().
     */
    private function chunk_of(string $text, int $cut = 0): array {
        $gzip = gzencode($text, 6);
        return $this->chunk_of_gzip($cut > 0 ? substr($gzip, 0, -$cut) : $gzip);
    }

    /**
     * Makes a list of the given compressed bytes in a chunk of type 4.
     *
     * @param string $gzip The compressed list.
     * @return array As built().
     */
    private function chunk_of_gzip(string $gzip): array {
        $path = make_request_directory() . '/manifest.chunk';
        $writer = new chunk_writer(
            $path,
            chunk_writer::TYPE_MANIFEST,
            chunk_name::manifest(self::RUN),
            sodium_crypto_box_publickey(self::boxpair()),
            self::SITE
        );
        $writer->add_item($gzip, strlen($gzip));
        $info = $writer->finish();
        return $this->described($info->path, $info->size, $info->sha256base64);
    }

    /**
     * The values for a chunk that are signed.
     *
     * @param string $path Path of the chunk.
     * @param int $size Its size.
     * @param string $sha256 Its SHA-256 in base64.
     * @return array
     */
    private function described(string $path, int $size, string $sha256): array {
        return [
            $path, $size, $sha256, artifact_signature::manifest($this->pair, self::SITE, self::RUN, $sha256),
            base64_encode($this->pair->public_key()),
        ];
    }

    /**
     * The lines of a list as text.
     *
     * @param array $header The first line.
     * @param array[] $entries Entries.
     * @param int|null $count The number the last line says, or null for the true one.
     * @param bool $end Whether the last line is there.
     * @return string
     */
    private static function text(array $header, array $entries, ?int $count = null, bool $end = true): string {
        $lines = [];
        foreach ([$header, ...$entries] as $line) {
            $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES) . "\n";
        }
        if ($end) {
            $lines[] = json_encode(['end' => true, 'entries' => $count ?? count($entries)]) . "\n";
        }
        return implode('', $lines);
    }

    /**
     * Makes a reader from the values of built() or chunk_of(), with changes.
     *
     * @param array $args The values.
     * @param array $changes Values by position that replace those of $args.
     * @param string $siteid Site ID.
     * @param string $runid Run ID.
     * @return manifest_reader
     */
    private function reader(
        array $args,
        array $changes = [],
        string $siteid = self::SITE,
        string $runid = self::RUN
    ): manifest_reader {
        $args = $changes + $args;
        return new manifest_reader($args[0], self::keys(), $siteid, $runid, $args[1], $args[2], $args[3], $args[4]);
    }

    /**
     * Asserts that a list of the given text is signed and well compressed but cannot be read.
     *
     * @param string $text The lines of the list.
     */
    private function assert_unreadable(string $text): void {
        $args = $this->chunk_of($text);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    /**
     * Runs a call that must fail and gives the error code.
     *
     * @param callable $call The call.
     * @return string
     */
    private function code_of(callable $call): string {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e->errorcode;
        }
        $this->fail('No exception');
    }

    public function test_the_header_and_the_entries_are_read_as_they_were_written(): void {
        $header = self::header();
        $reader = $this->reader($this->built($header, self::entries()));
        $this->assertSame($header, $reader->header());
        $this->assertSame(self::entries(), iterator_to_array($reader->entries(), false));
        // The header can be read again, and the entries too.
        $this->assertSame($header, $reader->header());
        $this->assertSame(self::entries(), iterator_to_array($reader->entries(), false));
    }

    public function test_a_list_without_entries_is_read(): void {
        $reader = $this->reader($this->built(self::header(), []));
        $this->assertSame(self::SITE, $reader->header()['site_id']);
        $this->assertSame([], iterator_to_array($reader->entries(), false));
    }

    public function test_a_wrong_size_fails(): void {
        $args = $this->built(self::header(), self::entries());
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [1 => $args[1] + 1])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [1 => $args[1] - 1])));
    }

    public function test_a_wrong_digest_fails(): void {
        $args = $this->built(self::header(), self::entries());
        $other = base64_encode(hash('sha256', 'other', true));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [2 => $other])));
        // A digest that is not in its canonical form is another text, so it does not match even if it decodes to the same bytes.
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [2 => rtrim($args[2], '=')])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [2 => ''])));
    }

    public function test_the_content_of_the_file_must_match_its_digest(): void {
        $args = $this->built(self::header(), self::entries());
        $bytes = file_get_contents($args[0]);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        file_put_contents($args[0], $bytes);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_wrong_signature_fails(): void {
        $args = $this->built(self::header(), self::entries());
        $other = artifact_signature::manifest(signing_keypair::generate(), self::SITE, self::RUN, $args[2]);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [3 => $other])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [3 => ''])));
        // A signature of another run does not do for this run.
        $other = artifact_signature::manifest($this->pair, self::SITE, self::OTHER_RUN, $args[2]);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [3 => $other])));
    }

    public function test_a_public_key_that_is_not_the_signers_fails(): void {
        $args = $this->built(self::header(), self::entries());
        $other = base64_encode(signing_keypair::generate()->public_key());
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [4 => $other])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [4 => ''])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [4 => 'not base64!'])));
        // A key that is not 32 bytes, and a key in a spelling that is not the canonical one.
        $short = base64_encode(substr($this->pair->public_key(), 1));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [4 => $short])));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [4 => rtrim($args[4], '=')])));
    }

    public function test_the_site_and_the_run_must_be_the_signed_ones(): void {
        $args = $this->built(self::header(), self::entries());
        $other = '0b1c2d3e-4f50-4617-8899-aabbccddeef0';
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [], $other)));
        $wrongrun = fn() => $this->reader($args, [], self::SITE, self::OTHER_RUN);
        $this->assertSame('restore_manifest_invalid', $this->code_of($wrongrun));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args, [], 'not an id')));
    }

    public function test_a_header_of_another_run_fails(): void {
        // The chunk is the one of this run and the signature is right, but the header says another run.
        $args = $this->chunk_of(self::text(self::header(['run_id' => self::OTHER_RUN]), self::entries()));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_header_of_another_site_fails(): void {
        $args = $this->chunk_of(self::text(self::header(['site_id' => '0b1c2d3e-4f50-4617-8899-aabbccddeef0']), self::entries()));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_header_that_lacks_a_part_fails(): void {
        foreach (['moodle', 'db', 'plugins', 'config', 'core_diff', 'missing_files', 'run_id', 'site_id', 'v'] as $key) {
            $header = self::header();
            unset($header[$key]);
            $args = $this->chunk_of(self::text($header, self::entries()));
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)), $key);
        }
    }

    public function test_a_header_of_another_version_fails(): void {
        foreach ([2, 0, '1', true, null] as $version) {
            $args = $this->chunk_of(self::text(self::header(['v' => $version]), self::entries()));
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
        }
    }

    public function test_a_first_line_that_is_not_an_object_fails(): void {
        foreach (["[1,2]\n", "\"text\"\n", "not json\n", "\n", ''] as $first) {
            $args = $this->chunk_of($first . json_encode(['end' => true, 'entries' => 0]) . "\n");
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
        }
    }

    public function test_an_entry_that_lacks_a_field_fails(): void {
        foreach (self::entries() as $number => $entry) {
            foreach (array_keys($entry) as $field) {
                $broken = self::entries();
                unset($broken[$number][$field]);
                $args = $this->chunk_of(self::text(self::header(), $broken));
                $this->assertSame(
                    'restore_manifest_invalid',
                    $this->code_of(fn() => $this->reader($args)),
                    $entry['t'] . ' ' . $field
                );
            }
        }
    }

    public function test_an_entry_with_a_field_of_another_type_fails(): void {
        $broken = self::entries();
        $broken[0]['rows'] = '40';
        $args = $this->chunk_of(self::text(self::header(), $broken));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
        $broken = self::entries();
        $broken[1]['contenthash'] = 5;
        $args = $this->chunk_of(self::text(self::header(), $broken));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
        $broken = self::entries();
        $broken[3]['pieces'] = null;
        $args = $this->chunk_of(self::text(self::header(), $broken));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_an_entry_of_an_unknown_kind_fails(): void {
        foreach (['index', 'DB', '', null, 5] as $kind) {
            $entry = ['t' => $kind, 'size' => 1];
            $args = $this->chunk_of(self::text(self::header(), [$entry]));
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
        }
    }

    public function test_a_line_that_is_not_an_object_fails(): void {
        $text = json_encode(self::header()) . "\n[1]\n" . json_encode(['end' => true, 'entries' => 1]) . "\n";
        $this->assert_unreadable($text);
        $text = json_encode(self::header()) . "\nnot json\n" . json_encode(['end' => true, 'entries' => 1]) . "\n";
        $this->assert_unreadable($text);
    }

    public function test_a_list_without_its_last_line_fails(): void {
        $args = $this->chunk_of(self::text(self::header(), self::entries(), null, false));
        // The entries cannot be trusted without the line that counts them: the reader is not made.
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_list_whose_last_line_counts_wrongly_fails(): void {
        foreach ([3, 5, 0] as $count) {
            $args = $this->chunk_of(self::text(self::header(), self::entries(), $count));
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)), "$count");
        }
        $text = self::text(self::header(), self::entries());
        $text = str_replace('"entries":4', '"entries":"4"', $text);
        $this->assert_unreadable($text);
    }

    public function test_a_last_line_that_has_something_after_it_fails(): void {
        $text = self::text(self::header(), self::entries()) . json_encode(self::entries()[0]) . "\n";
        $this->assert_unreadable($text);
        $text = self::text(self::header(), self::entries()) . "\n";
        $this->assert_unreadable($text);
    }

    public function test_a_list_that_is_cut_in_the_middle_of_the_gzip_fails(): void {
        $entries = [];
        for ($n = 0; $n < 2000; $n++) {
            $entries[] = ['t' => 'file', 'contenthash' => sha1((string) $n), 'size' => $n, 'chunk' => str_repeat('a', 64),
                'offset' => 86, 'length' => $n + 1, 'piece' => 0, 'pieces' => 1];
        }
        foreach ([1, 8, 200] as $cut) {
            $args = $this->chunk_of(self::text(self::header(), $entries), $cut);
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)), "$cut");
        }
    }

    public function test_a_payload_that_is_not_gzip_fails(): void {
        $path = make_request_directory() . '/manifest.chunk';
        $writer = new chunk_writer(
            $path,
            chunk_writer::TYPE_MANIFEST,
            chunk_name::manifest(self::RUN),
            sodium_crypto_box_publickey(self::boxpair()),
            self::SITE
        );
        $writer->add_item('this is not compressed', 22);
        $info = $writer->finish();
        $args = $this->described($info->path, $info->size, $info->sha256base64);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_bytes_after_the_gzip_fail(): void {
        $gzip = gzencode(self::text(self::header(), self::entries()), 6) . 'extra';
        $path = make_request_directory() . '/manifest.chunk';
        $writer = new chunk_writer(
            $path,
            chunk_writer::TYPE_MANIFEST,
            chunk_name::manifest(self::RUN),
            sodium_crypto_box_publickey(self::boxpair()),
            self::SITE
        );
        $writer->add_item($gzip, strlen($gzip));
        $info = $writer->finish();
        $args = $this->described($info->path, $info->size, $info->sha256base64);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_chunk_of_another_type_fails(): void {
        $path = make_request_directory() . '/other.chunk';
        $writer = new chunk_writer(
            $path,
            chunk_writer::TYPE_DB,
            chunk_name::manifest(self::RUN),
            sodium_crypto_box_publickey(self::boxpair()),
            self::SITE
        );
        $gzip = gzencode(self::text(self::header(), self::entries()));
        $writer->add_item($gzip, strlen($gzip));
        $info = $writer->finish();
        $args = $this->described($info->path, $info->size, $info->sha256base64);
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($args)));
    }

    public function test_a_missing_file_and_a_chunk_that_the_keys_do_not_open_fail_with_their_own_codes(): void {
        $args = $this->built(self::header(), self::entries());
        $this->assertSame('restore_chunk_missing', $this->code_of(fn() => $this->reader($args, [0 => $args[0] . '.none'])));
        $other = new site_keys([sodium_crypto_box_secretkey(sodium_crypto_box_seed_keypair(str_repeat("\x0a", 32)))]);
        $this->assertSame('restore_chunk_corrupt', $this->code_of(
            fn() => new manifest_reader($args[0], $other, self::SITE, self::RUN, $args[1], $args[2], $args[3], $args[4])
        ));
    }

    public function test_a_hundred_thousand_entries_are_read_in_little_memory(): void {
        $builder = new manifest_builder(make_request_directory());
        for ($n = 0; $n < 100000; $n++) {
            $builder->append([
                't' => 'file', 'contenthash' => sha1((string) $n), 'size' => $n, 'chunk' => hash('sha256', (string) ($n % 500)),
                'offset' => 86, 'length' => $n + 30, 'piece' => 0, 'pieces' => 1,
            ]);
        }
        $chunk = $builder->build(self::header(), self::RUN, sodium_crypto_box_publickey(self::boxpair()), self::SITE);
        $args = $this->described($chunk->path, $chunk->size, $chunk->sha256base64);
        unset($builder);
        gc_collect_cycles();

        // PHP 8.2 can reset the peak, so that it covers the reader being made, which reads the whole list once. PHP 8.1 cannot:
        // its peak is that of every test that ran before, so there the use of memory is sampled while the entries are read.
        $canreset = function_exists('memory_reset_peak_usage');
        $before = memory_get_usage();
        if ($canreset) {
            memory_reset_peak_usage();
        }
        $peak = 0;
        $reader = $this->reader($args);
        $peak = max($peak, memory_get_usage() - $before);
        $count = 0;
        foreach ($reader->entries() as $entry) {
            if ($count++ % 1000 === 0) {
                $peak = max($peak, memory_get_usage() - $before);
            }
            $last = $entry;
        }
        $peak = max($peak, memory_get_usage() - $before);
        if ($canreset) {
            $peak = max($peak, memory_get_peak_usage() - $before);
        }
        $this->assertSame(100000, $count);
        $this->assertSame(99999, $last['size']);
        // The compressed list (about 7 MiB) is held once while it is written to the working file; the plain list is 20 MiB or
        // more, which a reader that expands it whole could not stay under.
        $this->assertLessThan(24 * 1048576, $peak);
    }

    /**
     * Applies a change to the entry of a kind and says whether a reader can be made of the list.
     *
     * @param int $number Position in entries() of the kind: 0 db, 1 file, 2 dataroot, 3 code.
     * @param array $changes The fields that are replaced.
     * @return string The error code, or an empty string when the reader was made.
     */
    private function code_with_entry(int $number, array $changes): string {
        $entries = self::entries();
        $entries[$number] = $changes + $entries[$number];
        $args = $this->chunk_of(self::text(self::header(), $entries));
        try {
            $this->reader($args);
        } catch (plugin_exception $e) {
            return $e->errorcode;
        }
        return '';
    }

    public function test_entries_with_a_value_out_of_range_fail_and_the_edges_pass(): void {
        $bigint = 9999999999999999999;
        // Each: [kind, field, values that fail, values that pass].
        $cases = [
            [0, 'from', [-1, $bigint, 41], [0, 40]],
            [0, 'to', [-1, 0, $bigint], [1, 40]],
            [0, 'rows', [-1, $bigint], [0, 1]],
            [0, 'plainlength', [-1], [0, 1]],
            [0, 'offset', [-1, 0, 85], [86, 87]],
            [0, 'length', [-1], [0, 1]],
            [1, 'size', [-1, $bigint], [0, 1]],
            [1, 'offset', [-1, 85], [86]],
            [1, 'length', [-1], [0]],
            [1, 'piece', [-1, 1, 2], [0]],
            [1, 'pieces', [-1, 0], [1]],
            [2, 'size', [-1], [0]],
            [2, 'piece', [-1, 1], [0]],
            [2, 'pieces', [0, -1], [1]],
            [3, 'offset', [85, -1], [86]],
            [3, 'length', [-1], [0]],
            [3, 'size', [-1], [0]],
            [3, 'piece', [-1], [0]],
        ];
        foreach ($cases as [$number, $field, $failing, $passing]) {
            foreach ($failing as $value) {
                $this->assertSame('restore_manifest_invalid', $this->code_with_entry($number, [$field => $value]), "$field=$value");
            }
            foreach ($passing as $value) {
                // From and to move together so that from <= to holds around the edge.
                $changes = [$field => $value];
                $this->assertSame('', $this->code_with_entry($number, $changes), "$field=$value");
            }
        }
        // A piece is below the number of pieces: the last piece is the one before it.
        $this->assertSame('', $this->code_with_entry(1, ['piece' => 2, 'pieces' => 3]));
        $this->assertSame('restore_manifest_invalid', $this->code_with_entry(1, ['piece' => 3, 'pieces' => 3]));
        // The first id is not above the last.
        $this->assertSame('', $this->code_with_entry(0, ['from' => 7, 'to' => 7]));
        $this->assertSame('restore_manifest_invalid', $this->code_with_entry(0, ['from' => 8, 'to' => 7]));
    }

    public function test_entries_with_a_field_of_the_wrong_form_fail(): void {
        $hex64 = str_repeat('e', 64);
        $cases = [
            [0, 'chunk', ['../etc/passwd', str_repeat('A', 64), substr($hex64, 1), $hex64 . '0', $hex64 . "\n", '']],
            [0, 'plainsha256', ['', substr($hex64, 1), str_repeat('G', 64), strtoupper(str_repeat('e', 64))]],
            [0, 'table', ['', 'Course', 'mdl course', "course\n", 'course;drop', '../x']],
            [1, 'contenthash', ['', str_repeat('a', 39), str_repeat('a', 41), str_repeat('A', 40), str_repeat('a', 40) . "\n"]],
            [1, 'chunk', ['a/b', str_repeat('a', 63)]],
            [2, 'sha256', ['', substr($hex64, 1), $hex64 . "\n", str_repeat('E', 64)]],
            [2, 'chunk', ['..', str_repeat('g', 64)]],
            [3, 'sha256', ['', str_repeat('z', 64)]],
            [3, 'chunk', ['../../x', "\0" . substr($hex64, 1)]],
        ];
        foreach ($cases as [$number, $field, $values]) {
            foreach ($values as $value) {
                $this->assertSame('restore_manifest_invalid', $this->code_with_entry($number, [$field => $value]), "$field=$value");
            }
        }
        $this->assertSame('', $this->code_with_entry(0, ['table' => 'tool_secbackup_runs_2']));
    }

    public function test_an_integer_too_large_for_php_and_an_extra_field_are_told_apart(): void {
        // A number that does not fit in an integer is a float to PHP, which is not a count.
        $text = str_replace('"rows":40', '"rows":99999999999999999999', self::text(self::header(), self::entries()));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($this->chunk_of($text))));
        // A field that the restore does not know is left alone: a newer plugin may write more.
        $this->assertSame('', $this->code_with_entry(1, ['future' => 'x']));
    }

    public function test_a_line_that_is_not_valid_utf8_fails(): void {
        $text = self::text(self::header(), self::entries());
        $text = str_replace('lang/ja/local.php', "lang/\xff\xfe.php", $text);
        $this->assert_unreadable($text);
    }

    public function test_the_list_is_checked_when_the_reader_is_made_and_the_entries_are_counted(): void {
        $reader = $this->reader($this->built(self::header(), self::entries()));
        $this->assertSame(4, $reader->count());
        $this->assertSame(0, $this->reader($this->built(self::header(), []))->count());
        // An entry that is wrong far down the list stops the reader before any entry is given.
        $entries = [];
        for ($n = 0; $n < 300; $n++) {
            $entries[] = self::entries()[1];
        }
        $entries[299]['piece'] = 5;
        $this->assertSame('restore_manifest_invalid', $this->code_of(
            fn() => $this->reader($this->chunk_of(self::text(self::header(), $entries)))
        ));
    }

    public function test_a_list_whose_compressed_size_is_a_multiple_of_the_piece_is_read(): void {
        // The end of the stream is met by a read of exactly one piece, so feof() is still false at that point.
        foreach ([65536, 131072] as $target) {
            $gzip = $this->gzip_of_size($target);
            $this->assertSame($target, strlen($gzip));
            $args = $this->chunk_of_gzip($gzip);
            $reader = $this->reader($args);
            $this->assertSame(self::entries(), iterator_to_array($reader->entries(), false));
            $this->assertSame(4, $reader->count());
            // One byte after the end of the stream is not part of the list.
            $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $this->reader($this->chunk_of_gzip($gzip . 'x'))));
        }
    }

    /**
     * A compressed list of the entries of the tests whose size is exactly the given one.
     *
     * The header holds a filler that compresses to about half its length; the length that gives the size is found by
     * stepping towards it.
     *
     * @param int $target Size in bytes.
     * @return string
     */
    private function gzip_of_size(int $target): string {
        $filler = '';
        for ($block = 0; strlen($filler) < $target * 4; $block++) {
            $filler .= hash('sha256', 'filler' . $block);
        }
        $length = $target * 2;
        for ($try = 0; $try < 400; $try++) {
            $header = self::header(['filler' => substr($filler, 0, $length)]);
            $gzip = gzencode(self::text($header, self::entries()), 9);
            $difference = $target - strlen($gzip);
            if ($difference === 0) {
                return $gzip;
            }
            $step = max(1, (int) (abs($difference) * 1.9));
            $length += $difference > 0 ? $step : -$step;
        }
        $this->fail('No filler gives the size');
    }

    public function test_close_removes_the_working_file(): void {
        $before = $this->working_files();
        $reader = $this->reader($this->built(self::header(), self::entries()));
        $during = array_diff($this->working_files(), $before);
        $this->assertCount(1, $during);
        $reader->close();
        $this->assertSame([], array_diff($this->working_files(), $before));
        // Closing again does nothing, and a reader that is closed does not read.
        $reader->close();
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => $reader->header()));
        $this->assertSame('restore_manifest_invalid', $this->code_of(fn() => iterator_to_array($reader->entries())));
    }

    public function test_the_working_file_goes_when_the_reader_is_dropped(): void {
        $before = $this->working_files();
        $reader = $this->reader($this->built(self::header(), self::entries()));
        $this->assertNotSame([], array_diff($this->working_files(), $before));
        unset($reader);
        gc_collect_cycles();
        $this->assertSame([], array_diff($this->working_files(), $before));
    }

    public function test_a_reader_that_failed_to_open_leaves_no_working_file(): void {
        $before = $this->working_files();
        $args = $this->built(self::header(), self::entries());
        $this->code_of(fn() => $this->reader($args, [3 => '']));
        $this->assertSame([], array_diff($this->working_files(), $before));
    }

    /**
     * The files that the readers keep in the temporary folder.
     *
     * @return string[]
     */
    private function working_files(): array {
        global $CFG;
        return glob($CFG->tempdir . '/tool_secbackup_restore/*') ?: [];
    }
}
