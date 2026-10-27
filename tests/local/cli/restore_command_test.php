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

namespace tool_secbackup\local\cli;

use tool_secbackup\local\crypto\key_exchange;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\fail_after_filter;
use tool_secbackup\tests\restore_fixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\cli\restore_command::class)]
/**
 * Tests for the extraction on the command line: the talk with the restore host, the progress lines and the errors.
 *
 * The tests play the restore host: they write its lines to a stream before the command runs, sealing the keys of the site to
 * the public key of an exchange that they give the command, and read the lines that the command wrote.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\cli\restore_command
 */
final class restore_command_test extends \advanced_testcase {
    /** @var restore_fixture The generation. */
    private restore_fixture $fixture;

    /** @var key_exchange The exchange that the command uses. */
    private key_exchange $exchange;

    /** @var int The time that the command sees. */
    private int $now = 1000;

    /** @var array|null The input and the number of keys that a fake extractor was given. */
    private ?array $given = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->fixture = new restore_fixture();
        $this->exchange = new key_exchange();
    }

    /**
     * The line of the sealed keys of the site.
     *
     * @param string[]|null $secrets The secret keys, or null for the keys of the fixture.
     * @return string
     */
    private function keys_line(?array $secrets = null): string {
        $sealed = array_map(
            fn(string $key): string => base64_encode(sodium_crypto_box_seal($key, $this->exchange->public_key())),
            $secrets ?? $this->fixture->secrets()
        );
        return json_encode(['type' => 'site_keys', 'sealed' => $sealed]);
    }

    /**
     * The line that starts the extraction, with the input in a file.
     *
     * @param string $content The content of the file.
     * @return string
     */
    private function go_line(string $content): string {
        $path = make_request_directory() . '/input.json';
        file_put_contents($path, $content);
        return json_encode(['type' => 'go', 'input' => $path], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Runs the command.
     *
     * @param string[] $lines The lines of the restore host.
     * @param \Closure|null $extractors The maker of the extractors; null for the real one.
     * @param resource|null $out The stream for the lines of the command; null for a new one.
     * @return array The exit code, the lines of the command, what it wrote to its error stream, and its raw output.
     */
    private function run_command(array $lines, ?\Closure $extractors = null, $out = null): array {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, implode('', array_map(static fn(string $line): string => $line . "\n", $lines)));
        rewind($in);
        $out ??= fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $command = new restore_command($in, $out, $err, $this->exchange, fn(): int => $this->now, $extractors);
        $code = $command->extract();
        rewind($out);
        $raw = (string) stream_get_contents($out);
        rewind($err);
        $lines = array_map(
            static fn(string $line): array => json_decode($line, true),
            array_filter(explode("\n", $raw), static fn(string $line): bool => $line !== '')
        );
        return [$code, $lines, (string) stream_get_contents($err), $raw];
    }

    /**
     * A maker of extractors whose run is the given function.
     *
     * @param \Closure $run Takes the progress function, gives the summary.
     * @return \Closure
     */
    private function fake(\Closure $run): \Closure {
        return function (array $input, site_keys $keys, \Closure $progress) use ($run): object {
            $this->given = ['input' => $input, 'keys' => $keys->count()];
            return new class (fn(): array => $run($progress)) {
                /** @var \Closure What run() does. */
                private \Closure $run;

                /**
                 * Constructor.
                 *
                 * @param \Closure $run What run() does.
                 */
                public function __construct(\Closure $run) {
                    $this->run = $run;
                }

                /**
                 * Runs.
                 *
                 * @return array The summary.
                 */
                public function run(): array {
                    return ($this->run)();
                }
            };
        };
    }

    /**
     * The input of a fake extractor, as a JSON text.
     *
     * @return string
     */
    private static function some_input(): string {
        return json_encode(['v' => 1, 'site_id' => 'a']);
    }

    public function test_a_generation_is_extracted_over_the_lines_in_the_order_of_the_agreement(): void {
        $fixture = $this->fixture;
        $input = $fixture->build();
        [$code, $lines, $err, $raw] = $this->run_command([
            $this->keys_line(),
            $this->go_line(json_encode($input, JSON_UNESCAPED_SLASHES)),
        ]);

        $this->assertSame(0, $code);
        $this->assertSame('', $err);
        $this->assertSame(
            ['type' => 'ephemeral_public_key', 'key' => base64_encode($this->exchange->public_key())],
            $lines[0]
        );
        $this->assertSame(['type' => 'keys_ready', 'count' => 2], $lines[1]);
        $this->assertSame(
            '{"type":"progress","stage":"extracting","done":0,"total":17,"unit":"entries"}',
            explode("\n", $raw)[2]
        );
        $this->assertSame(
            ['type' => 'progress', 'stage' => 'extracting', 'done' => 17, 'total' => 17, 'unit' => 'entries'],
            $lines[3]
        );
        $this->assertCount(5, $lines);
        $this->assertSame(['type' => 'done', 'summary' => [
            'moodle' => $fixture->header['moodle'],
            'db_family' => 'postgres',
            'tables' => 3,
            'rows' => 5,
            'files' => 4,
            'plugins' => ['mod_attendance'],
            'missing_files' => 1,
        ]], $lines[4]);
        $this->assertStringEndsWith("}}\n", $raw);
        $this->assertSame($fixture->codefiles['core']['index.php'], file_get_contents($fixture->targetroot . '/index.php'));
        $this->assertSame($fixture->header, json_decode(file_get_contents($fixture->stagingdir . '/header.json'), true));
    }

    public function test_the_extractor_is_given_the_input_of_the_file_and_the_keys_that_were_opened(): void {
        $input = ['v' => 1, 'site_id' => 'a', 'skip_components' => ['tool_secbackup']];
        [$code, $lines] = $this->run_command(
            [$this->keys_line(), $this->go_line(json_encode($input))],
            $this->fake(fn(\Closure $progress): array => ['rows' => 0])
        );
        $this->assertSame(0, $code);
        $this->assertSame(['input' => $input, 'keys' => 2], $this->given);
        $this->assertSame(['type' => 'done', 'summary' => ['rows' => 0]], end($lines));
    }

    public function test_a_command_with_no_exchange_given_makes_its_own(): void {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $this->assertSame(1, (new restore_command($in, $out, $err))->extract());
        rewind($out);
        $first = json_decode((string) fgets($out), true);
        $this->assertSame('ephemeral_public_key', $first['type']);
        $this->assertSame(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, strlen(base64_decode($first['key'], true)));
    }

    public function test_the_memory_limit_is_raised_before_the_extractor_runs(): void {
        $before = ini_get('memory_limit');
        $inside = null;
        try {
            ini_set('memory_limit', '192M');
            $this->run_command(
                [$this->keys_line(), $this->go_line(self::some_input())],
                $this->fake(function () use (&$inside): array {
                    $inside = ini_get('memory_limit');
                    return [];
                })
            );
        } finally {
            ini_set('memory_limit', $before);
        }
        // MEMORY_HUGE is a sentinel for raise_memory_limit(), which makes it 2G.
        $this->assertGreaterThanOrEqual(get_real_size('1G'), get_real_size($inside));
    }

    /**
     * The calls of the extractor and the progress lines that they make due.
     *
     * @return array
     */
    public static function progress_provider(): array {
        $each = static function (int $total, int $time = 0): array {
            return array_map(static fn(int $done): array => [$done, $time], range(0, $total));
        };
        return [
            'a line for each thousand entries, and the last' => [5000, $each(5000), [0, 1000, 2000, 3000, 4000, 5000]],
            'the last when it is not a thousand' => [2500, $each(2500), [0, 1000, 2000, 2500]],
            'a line for each five seconds' => [12, array_map(static fn(int $i): array => [$i, $i], range(0, 12)), [0, 5, 10, 12]],
            'four seconds are not enough, five are' => [100, [[0, 0], [1, 4], [2, 5], [100, 5]], [0, 2, 100]],
            'the five seconds count from the last line' => [5, [[0, 0], [1, 5], [2, 9], [3, 10], [4, 14], [5, 14]], [0, 1, 3, 5]],
            'the thousand count from the last line' => [
                3000,
                [[0, 0], [10, 5], [1000, 5], [1009, 5], [1010, 5], [3000, 5]],
                [0, 10, 1010, 3000],
            ],
            'no entries' => [0, [[0, 0]], [0]],
            'the first line is there at once' => [7, [[0, 0], [7, 0]], [0, 7]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('progress_provider')]
    /**
     * A progress line at the start, then at most one for each thousand entries or five seconds, and one when all are done.
     *
     * @dataProvider progress_provider
     * @param int $total Entries in the list.
     * @param int[][] $calls The calls of the extractor: [entries done, time].
     * @param int[] $expected The entries done that the lines tell.
     */
    public function test_the_progress_lines_are_few_and_the_last_has_all_done(int $total, array $calls, array $expected): void {
        [$code, $lines] = $this->run_command(
            [$this->keys_line(), $this->go_line(self::some_input())],
            $this->fake(function (\Closure $progress) use ($total, $calls): array {
                foreach ($calls as [$done, $time]) {
                    $this->now = $time;
                    $progress($done, $total);
                }
                return [];
            })
        );
        $this->assertSame(0, $code);
        $progress = array_values(array_filter($lines, static fn(array $line): bool => $line['type'] === 'progress'));
        $this->assertSame($expected, array_column($progress, 'done'));
        $this->assertSame(array_fill(0, count($expected), $total), array_column($progress, 'total'));
        $this->assertSame('done', end($lines)['type']);
        $this->assertSame('progress', $lines[count($lines) - 2]['type']);
    }

    public function test_the_keys_that_do_not_open_are_refused_and_no_key_is_written(): void {
        $secrets = $this->fixture->secrets();
        $sealed = [base64_encode(sodium_crypto_box_seal($secrets[0], $this->exchange->public_key())), 'bm90IHNlYWxlZA=='];
        [$code, $lines, $err, $raw] = $this->run_command([json_encode(['type' => 'site_keys', 'sealed' => $sealed])]);

        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_keys_invalid'], end($lines));
        $this->assertCount(2, $lines);
        $this->assertSame(get_string('restore_keys_invalid', 'tool_secbackup') . "\n", $err);
        $this->assert_no_secret($raw . $err, [...$secrets, ...$sealed]);
    }

    public function test_more_keys_than_a_site_has_are_refused_as_keys_and_not_as_a_line(): void {
        $keys = array_map(static fn(int $i): string => random_bytes(32), range(1, 11));
        [$code, $lines] = $this->run_command([$this->keys_line($keys)]);
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_keys_invalid'], end($lines));
    }

    public function test_the_most_keys_a_site_has_are_opened(): void {
        $keys = array_map(static fn(int $i): string => random_bytes(32), range(1, 10));
        [$code, $lines] = $this->run_command(
            [$this->keys_line($keys), $this->go_line(self::some_input())],
            $this->fake(fn(): array => [])
        );
        $this->assertSame(0, $code);
        $this->assertSame(['type' => 'keys_ready', 'count' => 10], $lines[1]);
    }

    /**
     * Asserts that none of the secrets is in the text, as bytes or in standard or URL-safe base64.
     *
     * @param string $text The text.
     * @param string[] $secrets The secrets.
     */
    private function assert_no_secret(string $text, array $secrets): void {
        foreach ($secrets as $secret) {
            foreach ([$secret, base64_encode($secret), strtr(base64_encode($secret), '+/', '-_'), bin2hex($secret)] as $form) {
                $this->assertStringNotContainsString($form, $text);
            }
        }
    }

    /**
     * Lines of the restore host that are not the message with the sealed keys.
     *
     * @return array
     */
    public static function line_provider(): array {
        $keys = '{"type":"site_keys","sealed":["AAAA"]}';
        return [
            'no keys line' => [[]],
            'the go line first' => [['{"type":"go","input":"/x"}']],
            'keys with a member that is not known' => [['{"type":"site_keys","sealed":["AAAA"],"extra":1}']],
            'keys that are not a list' => [['{"type":"site_keys","sealed":"AAAA"}']],
            'keys that are not JSON' => [['site_keys']],
            'keys in a line over 64 KiB' => [[str_repeat(' ', 66000) . $keys]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('line_provider')]
    /**
     * A line of the restore host that is not the keys message ends the command at once.
     *
     * @dataProvider line_provider
     * @param string[] $lines The lines of the restore host.
     */
    public function test_a_keys_line_that_is_not_one_is_refused_after_the_public_key(array $lines): void {
        [$code, $out, $err] = $this->run_command($lines);
        $this->assertSame(1, $code);
        $this->assertSame('ephemeral_public_key', $out[0]['type']);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], $out[1]);
        $this->assertCount(2, $out);
        $this->assertSame(get_string('restore_input_invalid', 'tool_secbackup') . "\n", $err);
    }

    /**
     * Lines of the restore host that are not the message that starts the extraction.
     *
     * @return array
     */
    public static function go_provider(): array {
        return [
            'no line' => [null],
            'the keys line again' => ['{"type":"site_keys","sealed":["AAAA"]}'],
            'a member that is not known' => ['{"type":"go","input":"/x","extra":1}'],
            'no member' => ['{"type":"go"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('go_provider')]
    /**
     * A line after the keys that is not the go message ends the command, with the keys opened and nothing written.
     *
     * @dataProvider go_provider
     * @param string|null $line The line, or null for none.
     */
    public function test_a_go_line_that_is_not_one_is_refused_after_the_keys_are_ready(?string $line): void {
        [$code, $out] = $this->run_command($line === null ? [$this->keys_line()] : [$this->keys_line(), $line]);
        $this->assertSame(1, $code);
        $this->assertSame('keys_ready', $out[1]['type']);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], $out[2]);
        $this->assertCount(3, $out);
    }

    /**
     * Contents of the input file that cannot be used.
     *
     * @return array
     */
    public static function input_file_provider(): array {
        return [
            'a file that is not JSON' => ['{"v":'],
            'an empty file' => [''],
            'JSON that is a number' => ['5'],
            'JSON that is a list' => ['[1, 2]'],
            'JSON that is null' => ['null'],
            'a file over a mebibyte' => ['{"v":1,"pad":"' . str_repeat('a', 1048576) . '"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('input_file_provider')]
    /**
     * An input file that is not a JSON object of a size to be read is refused as an input.
     *
     * @dataProvider input_file_provider
     * @param string $content The content of the file.
     */
    public function test_an_input_file_that_is_not_a_json_object_is_refused(string $content): void {
        [$code, $out, $err, $raw] = $this->run_command([$this->keys_line(), $this->go_line($content)]);
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], end($out));
        $this->assertSame(get_string('restore_input_invalid', 'tool_secbackup') . "\n", $err);
        $this->assertCount(3, $out);
        $this->assertStringNotContainsString('aaaaaaaa', $raw . $err);
    }

    public function test_an_input_file_over_a_mebibyte_is_refused_also_when_only_blanks_are_over(): void {
        $content = '{"v":1}' . str_repeat(' ', 1048576 - strlen('{"v":1}') + 1);
        $this->assertSame(1048577, strlen($content));
        [$code, $out] = $this->run_command(
            [$this->keys_line(), $this->go_line($content)],
            $this->fake(fn(): array => [])
        );
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], end($out));
    }

    public function test_an_input_file_of_a_mebibyte_is_read(): void {
        $content = '{"v":1,"pad":"' . str_repeat('a', 1048576 - strlen('{"v":1,"pad":""}')) . '"}';
        $this->assertSame(1048576, strlen($content));
        [$code] = $this->run_command(
            [$this->keys_line(), $this->go_line($content)],
            $this->fake(fn(): array => [])
        );
        $this->assertSame(0, $code);
    }

    /**
     * Paths of the input file that cannot be read.
     *
     * @return array
     */
    public static function input_path_provider(): array {
        return [
            'a file that is not there' => ['/no/such/folder/input.json'],
            'a folder' => ['/'],
            'a relative path' => ['input.json'],
            'an empty path' => [''],
            'a path with a null byte' => ["/tmp/a\0b"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('input_path_provider')]
    /**
     * An input path that names no file that can be read is refused as an input.
     *
     * @dataProvider input_path_provider
     * @param string $path The path.
     */
    public function test_an_input_path_that_cannot_be_read_is_refused(string $path): void {
        $go = json_encode(['type' => 'go', 'input' => $path], JSON_UNESCAPED_SLASHES);
        [$code, $out, $err] = $this->run_command([$this->keys_line(), $go]);
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], end($out));
        $this->assertStringNotContainsString($path === '' ? "\0" : $path, $err);
    }

    public function test_an_input_that_the_extractor_refuses_is_an_input_error(): void {
        [$code, $out] = $this->run_command([$this->keys_line(), $this->go_line('{}')]);
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_input_invalid'], end($out));
    }

    public function test_an_error_of_the_extractor_is_an_error_line_and_leaks_neither_a_key_nor_the_input(): void {
        $fixture = $this->fixture;
        $input = $fixture->build();
        $input['manifest']['signature'] = base64_encode(str_repeat("\x01", 64));
        [$code, $out, $err, $raw] = $this->run_command([
            $this->keys_line(),
            $this->go_line(json_encode($input, JSON_UNESCAPED_SLASHES)),
        ]);

        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => 'restore_manifest_invalid'], end($out));
        $this->assertSame(get_string('restore_manifest_invalid', 'tool_secbackup') . "\n", $err);
        $secrets = $fixture->secrets();
        $sealed = array_map(
            fn(string $key): string => base64_encode(sodium_crypto_box_seal($key, $this->exchange->public_key())),
            $secrets
        );
        $this->assert_no_secret($raw . $err, [...$secrets, ...$sealed]);
        foreach (
            [$input['manifest']['signature'], $input['manifest']['signing_public_key'], $input['manifest']['path'],
                $input['chunks_dir'], $input['target_root'], $input['site_id']] as $text
        ) {
            $this->assertStringNotContainsString($text, $err);
            // The error line is the only line that may repeat nothing of the input; the other lines come before the error.
            $this->assertStringNotContainsString($text, $raw);
        }
    }

    public function test_an_error_that_is_not_the_plugin_s_is_one_line_with_its_class_and_no_error_line(): void {
        $thrown = new \coding_exception('The file /var/secret/path/chunk could not be read');
        [$code, $out, $err, $raw] = $this->run_command(
            [$this->keys_line(), $this->go_line(self::some_input())],
            $this->fake(function () use ($thrown): array {
                throw $thrown;
            })
        );
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'keys_ready', 'count' => 2], end($out));
        $this->assertStringNotContainsString('"error"', $raw);
        $this->assertSame(get_string('cli_restore_failed', 'tool_secbackup', get_class($thrown)) . "\n", $err);
        $this->assertStringNotContainsString('secret', $err);
        $this->assertStringNotContainsString('chunk', $err);
    }

    public function test_a_failure_to_write_a_line_is_one_line_with_its_class(): void {
        $path = make_request_directory() . '/out';
        touch($path);
        [$code, $out, $err] = $this->run_command([$this->keys_line()], null, fopen($path, 'rb'));
        $this->assertSame(1, $code);
        $this->assertSame([], $out);
        $this->assertSame(get_string('cli_restore_failed', 'tool_secbackup', 'RuntimeException') . "\n", $err);
    }

    public function test_an_error_line_that_cannot_be_written_does_not_hide_the_error(): void {
        $out = fopen('php://memory', 'w+b');
        fail_after_filter::attach($out, 1);
        [$code, $lines, $err] = $this->run_command(
            [json_encode(['type' => 'site_keys', 'sealed' => ['AAAA']])],
            null,
            $out
        );
        $this->assertSame(1, $code);
        $this->assertSame('ephemeral_public_key', $lines[0]['type']);
        $this->assertCount(1, $lines);
        $this->assertSame(
            get_string('cli_restore_failed', 'tool_secbackup', \RuntimeException::class) . "\n"
                . get_string('restore_keys_invalid', 'tool_secbackup') . "\n",
            $err
        );
    }

    public function test_the_error_codes_of_the_agreement_are_all_strings_of_the_plugin(): void {
        foreach (
            ['restore_input_invalid', 'restore_keys_invalid', 'restore_manifest_invalid', 'restore_core_official_unsupported',
                'restore_chunk_missing', 'restore_chunk_corrupt', 'restore_content_mismatch', 'restore_path_unsafe',
                'restore_write_failed'] as $code
        ) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
            [$exitcode, $out, $err] = $this->run_command(
                [$this->keys_line(), $this->go_line(self::some_input())],
                $this->fake(function () use ($code): array {
                    throw new plugin_exception($code);
                })
            );
            $this->assertSame(1, $exitcode);
            $this->assertSame(['type' => 'error', 'code' => $code], end($out));
            $this->assertSame(get_string($code, 'tool_secbackup') . "\n", $err);
            $this->exchange = new key_exchange();
        }
    }

    public function test_the_help_is_written_line_by_line_under_the_trademark_notice(): void {
        $lines = [];
        restore_command::help(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame([get_string('trademark', 'tool_secbackup'), get_string('cli_restore_help', 'tool_secbackup')], $lines);
        $this->assertStringContainsString('--extract', $lines[1]);
        $this->assertStringContainsString('--import', $lines[1]);
    }
}
