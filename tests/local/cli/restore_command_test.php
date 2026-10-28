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
use tool_secbackup\local\restore\import_failed_exception;
use tool_secbackup\tests\fail_after_filter;
use tool_secbackup\tests\restore_fixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\cli\restore_command::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('tool_secbackup_restore_mtrace')]
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
 * @covers     ::tool_secbackup_restore_mtrace
 */
final class restore_command_test extends \advanced_testcase {
    /** @var string The password of the administrator in the import line of the tests. */
    private const PASSWORD = 'Kx7#mP2-qR9=vT4@wZ6!';

    /** @var restore_fixture The generation. */
    private restore_fixture $fixture;

    /** @var key_exchange The exchange that the command uses. */
    private key_exchange $exchange;

    /** @var int The time that the command sees. */
    private int $now = 1000;

    /** @var array|null What a fake extractor (the input and the number of keys) or importer (the staging folder) was given. */
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
        // Lower than what is raised to, and not lower than what is in use, which PHP refuses.
        $low = max(get_real_size('192M'), memory_get_usage(true) + get_real_size('32M'));
        if ($low >= get_real_size('1G')) {
            $this->markTestSkipped('The process uses too much memory to lower the limit below the one that is raised to.');
        }
        try {
            ini_set('memory_limit', (string) $low);
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

    public function test_what_is_echoed_or_traced_during_the_extraction_goes_to_the_error_stream_and_not_to_the_lines(): void {
        $display = ini_get('display_errors');
        $level = ob_get_level();
        $seen = null;
        [$code, $lines, $err, $raw] = $this->run_command(
            [$this->keys_line(), $this->go_line(self::some_input())],
            $this->fake(function (\Closure $progress) use (&$seen): array {
                $seen = ini_get('display_errors');
                $progress(0, 3);
                echo 'stray text of a library';
                echo "++ stray debugging in the form of the command line ++\n";
                mtrace('stray trace of Moodle');
                debugging('stray debugging of Moodle', DEBUG_DEVELOPER);
                $progress(3, 3);
                print "more\n";
                return ['rows' => 1];
            })
        );
        $this->assertDebuggingCalled();
        $this->assertSame(0, $code);
        $this->assertSame('stderr', $seen, 'The errors and warnings of PHP are shown on the error stream.');
        $this->assertSame($display, ini_get('display_errors'));
        $this->assertSame($level, ob_get_level());
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            $this->assertIsArray(json_decode($line, true), $line);
        }
        $this->assertCount(5, $lines);
        $this->assertStringContainsString('stray text of a library', $err);
        $this->assertStringContainsString('more', $err);
        $this->assertStringContainsString("++ stray debugging in the form of the command line ++\n", $err);
        $this->assertStringContainsString('stray trace of Moodle', $err);
        $this->assertStringNotContainsString('stray', $raw);
    }

    public function test_mtrace_is_given_to_the_error_stream_while_the_extraction_runs_and_the_old_setting_comes_back(): void {
        global $CFG;
        foreach ([null, 'some_function'] as $old) {
            if ($old === null) {
                unset($CFG->mtrace_wrapper);
            } else {
                $CFG->mtrace_wrapper = $old;
            }
            $inside = null;
            $this->exchange = new key_exchange();
            $this->run_command(
                [$this->keys_line(), $this->go_line(self::some_input())],
                $this->fake(function () use (&$inside): array {
                    global $CFG;
                    $inside = $CFG->mtrace_wrapper;
                    return [];
                })
            );
            $this->assertSame('tool_secbackup_restore_mtrace', $inside);
            $this->assertTrue(function_exists($inside));
            $this->assertSame($old, $CFG->mtrace_wrapper ?? null);
        }
        // Outside the extraction the function gives nothing to anyone.
        tool_secbackup_restore_mtrace('nobody listens', "\n");
    }

    public function test_what_is_echoed_before_a_failure_goes_to_the_error_stream_and_the_buffer_is_closed(): void {
        $level = ob_get_level();
        [$code, $lines, $err, $raw] = $this->run_command(
            [$this->keys_line(), $this->go_line(self::some_input())],
            $this->fake(function (): array {
                echo 'stray before the failure';
                throw new plugin_exception('restore_write_failed');
            })
        );
        $this->assertSame(1, $code);
        $this->assertSame($level, ob_get_level());
        $this->assertSame(['type' => 'error', 'code' => 'restore_write_failed'], end($lines));
        $this->assertStringContainsString('stray before the failure', $err);
        $this->assertStringNotContainsString('stray', $raw);
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
        $this->assertStringContainsString('--staging=', $lines[1]);
    }

    /**
     * The import line of the restore host.
     *
     * @param array $edit Members to change: a value each, or null to leave the member out.
     * @return string
     */
    private static function import_line(array $edit = []): string {
        $message = [
            'admin' => ['username' => 'secbackup_admin', 'password' => self::PASSWORD],
            'expires_at' => '2026-11-10T03:00:00Z',
            'temp_host' => 'a1b2c3.restore.test',
        ];
        foreach ($edit as $name => $value) {
            if ($value === null) {
                unset($message[$name]);
            } else {
                $message[$name] = $value;
            }
        }
        return json_encode($message, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The header of the generation that the extraction wrote to the staging folder, as the tests use it.
     *
     * @return array
     */
    private static function import_header(): array {
        return [
            'v' => 1,
            'plugins' => [
                ['component' => 'mod_forum', 'version' => '2024100700', 'standard' => true],
                ['component' => 'local_secbkextra', 'version' => '2025010100', 'standard' => false],
            ],
            'config' => ['prefix' => 'mdl_', 'dbtype' => 'pgsql', 'dblibrary' => 'native', 'forced' => [
                'config' => ['theme' => 'classic', 'wwwroot' => 'https://lms.example.com'],
                'plugins' => ['mod_attendance' => ['enabled' => '1']],
            ]],
        ];
    }

    /**
     * A staging folder as the extraction leaves it: header.json and db/manifest.json without tables.
     *
     * @param array|string|null $header The header, or the text of header.json, or null for no header.json.
     * @return string The folder.
     */
    private static function staging(array|string|null $header = []): string {
        $dir = make_request_directory();
        mkdir($dir . '/db');
        file_put_contents($dir . '/db/manifest.json', '{"v":1,"family":"postgres","tables":{},"blocks":[]}');
        if ($header !== null) {
            $header = $header === [] ? self::import_header() : $header;
            file_put_contents($dir . '/header.json', is_string($header) ? $header : json_encode($header, JSON_UNESCAPED_SLASHES));
        }
        return $dir;
    }

    /**
     * Runs the import.
     *
     * @param string[] $lines The lines of the restore host.
     * @param string $staging The staging folder.
     * @param \Closure|null $importers The maker of the importers; null for the real one.
     * @return array The exit code, the lines of the command, what it wrote to its error stream, and its raw output.
     */
    private function run_import(array $lines, string $staging, ?\Closure $importers = null): array {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, implode('', array_map(static fn(string $line): string => $line . "\n", $lines)));
        rewind($in);
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $command = new restore_command($in, $out, $err, null, fn(): int => $this->now, null, $importers);
        $code = $command->import($staging);
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
     * A maker of importers whose import is the given function.
     *
     * @param \Closure $import Takes the progress function, gives the result.
     * @return \Closure
     */
    private function fake_importer(\Closure $import): \Closure {
        return function (string $staging, \Closure $progress) use ($import): object {
            $this->given = ['staging' => $staging];
            return new class (fn(): array => $import($progress)) {
                /** @var \Closure What import() does. */
                private \Closure $import;

                /**
                 * Constructor.
                 *
                 * @param \Closure $import What import() does.
                 */
                public function __construct(\Closure $import) {
                    $this->import = $import;
                }

                /**
                 * Imports.
                 *
                 * @return array The tables and the rows.
                 */
                public function import(): array {
                    return ($this->import)();
                }
            };
        };
    }

    /**
     * Whether secbackup_admin logs in with a password. Only for a login that is expected to work: a login that fails writes to
     * the log of PHP with the browser of a request, which the tests do not have.
     *
     * @param string $password The password.
     * @return bool
     */
    private static function admin_logs_in(string $password): bool {
        global $CFG;
        require_once($CFG->libdir . '/authlib.php');
        $reason = null;
        return authenticate_user_login('secbackup_admin', $password, false, $reason) !== false;
    }

    public function test_a_staging_folder_is_imported_and_the_administrator_logs_in(): void {
        global $DB;
        $staging = self::staging();
        [$code, $lines, $err, $raw] = $this->run_import([self::import_line()], $staging);

        $this->assertSame(0, $code, $err);
        $this->assertSame('', $err);
        $this->assertSame(
            '{"type":"progress","stage":"importing","done":0,"total":0,"unit":"rows"}',
            explode("\n", $raw)[0]
        );
        $this->assertCount(2, $lines);
        $done = $lines[1];
        $this->assertSame('done', $done['type']);
        $this->assertSame(['tables', 'rows', 'stopped'], array_keys($done['summary']));
        $this->assertSame(0, $done['summary']['tables']);
        $this->assertSame(0, $done['summary']['rows']);
        $this->assertContains(['kind' => 'setting', 'name' => 'noemailever'], $done['summary']['stopped']);
        $this->assertSame(['kind' => 'registration', 'name' => 'tool_secbackup'], end($done['summary']['stopped']));
        $this->assertStringEndsWith("]}}\n", $raw);
        $this->assertTrue(self::admin_logs_in(self::PASSWORD));
        $this->assertSame('classic', $DB->get_field('config', 'value', ['name' => 'theme']));
        $this->assertSame('1', $DB->get_field('config_plugins', 'value', ['plugin' => 'mod_attendance', 'name' => 'enabled']));
        $this->assertSame('1', $DB->get_field('config', 'value', ['name' => 'noemailever']));
        $this->assert_no_secret($raw . $err, [self::PASSWORD]);
    }

    public function test_the_summary_has_the_tables_and_rows_of_the_import_and_what_was_stopped(): void {
        [$code, $lines] = $this->run_import(
            [self::import_line()],
            $staging = self::staging(),
            $this->fake_importer(function (\Closure $progress): array {
                $progress(0, 12);
                $progress(12, 12);
                return ['tables' => 3, 'rows' => 11];
            })
        );
        $this->assertSame(0, $code);
        $this->assertSame(['staging' => $staging], $this->given);
        $summary = end($lines)['summary'];
        $this->assertSame(3, $summary['tables']);
        $this->assertSame(11, $summary['rows']);
        global $DB;
        $this->assertSame(
            array_map(
                static fn(\stdClass $row): array => ['kind' => $row->kind, 'name' => $row->name],
                array_values($DB->get_records('tool_secbackup_restore_stops', null, 'id', 'id, kind, name'))
            ),
            $summary['stopped']
        );
    }

    /**
     * Records of things that an earlier run stopped.
     *
     * @param int $count How many.
     * @param int $length The length of the name of each.
     */
    private static function earlier_stops(int $count, int $length): void {
        global $DB;
        for ($i = 1; $i <= $count; $i++) {
            $name = '\\local_secbkextra\\task\\t' . $i . '_';
            $DB->insert_record('tool_secbackup_restore_stops', ['kind' => 'task', 'name' => str_pad($name, $length, 'x'),
                'previous' => '0', 'timecreated' => 1]);
        }
    }

    public function test_the_summary_lists_the_first_500_things_stopped_and_the_table_keeps_all(): void {
        global $DB;
        self::earlier_stops(501, 30);
        [$code, $lines] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(fn(): array => ['tables' => 0, 'rows' => 0])
        );
        $this->assertSame(0, $code);
        $stopped = end($lines)['summary']['stopped'];
        $this->assertCount(500, $stopped);
        $this->assertSame(['kind' => 'task', 'name' => str_pad('\\local_secbkextra\\task\\t1_', 30, 'x')], $stopped[0]);
        $this->assertSame(['kind' => 'task', 'name' => str_pad('\\local_secbkextra\\task\\t500_', 30, 'x')], $stopped[499]);
        $this->assertGreaterThan(501, $DB->count_records('tool_secbackup_restore_stops'));
    }

    public function test_the_summary_lists_as_many_things_stopped_as_fit_in_one_line(): void {
        global $DB;
        self::earlier_stops(300, 255);
        [$code, $lines, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(fn(): array => ['tables' => 0, 'rows' => 0])
        );
        $this->assertSame(0, $code, $err);
        $line = substr($raw, strrpos(rtrim($raw, "\n"), "\n") + 1);
        $this->assertLessThanOrEqual(65536, strlen($line));
        $stopped = end($lines)['summary']['stopped'];
        $this->assertLessThan(300, count($stopped));
        $all = array_values($DB->get_records('tool_secbackup_restore_stops', null, 'id', 'id, kind, name'));
        $this->assertSame($all[count($stopped) - 1]->name, end($stopped)['name']);
        // The next one would not fit, with the comma before it.
        $next = ['kind' => $all[count($stopped)]->kind, 'name' => $all[count($stopped)]->name];
        $this->assertGreaterThan(65536, strlen($line) + 1 + strlen(json_encode($next, JSON_UNESCAPED_SLASHES)));
    }

    public function test_the_import_comes_first_and_the_settings_and_the_administrator_after_it(): void {
        global $DB;
        set_config('noemailever', 0);
        set_config('theme', 'boost');
        $seen = null;
        [$code] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function () use (&$seen, $DB): array {
                $seen = [
                    'admin' => $DB->record_exists('user', ['username' => 'secbackup_admin']),
                    'noemailever' => $DB->get_field('config', 'value', ['name' => 'noemailever']),
                    'theme' => $DB->get_field('config', 'value', ['name' => 'theme']),
                    'stops' => $DB->count_records('tool_secbackup_restore_stops'),
                ];
                // The import brings the settings of the backed-up site back.
                $DB->set_field('config', 'value', 'boost', ['name' => 'theme']);
                return ['tables' => 0, 'rows' => 0];
            })
        );
        $this->assertSame(0, $code);
        $this->assertSame(['admin' => false, 'noemailever' => '0', 'theme' => 'boost', 'stops' => 0], $seen);
        $this->assertSame('classic', $DB->get_field('config', 'value', ['name' => 'theme']));
        $this->assertTrue(self::admin_logs_in(self::PASSWORD));
    }

    /**
     * The calls of the importer and the progress lines that they make due.
     *
     * @return array
     */
    public static function import_progress_provider(): array {
        $every = static function (int $total, int $step): array {
            return array_map(static fn(int $done): array => [$done, 0], range(0, $total, $step));
        };
        return [
            'a line for each ten thousand rows, and the last' => [
                30000, $every(30000, 100), [0, 10000, 20000, 30000],
            ],
            'the last when it is not ten thousand' => [25000, $every(25000, 1000), [0, 10000, 20000, 25000]],
            'nine thousand nine hundred and ninety-nine rows are not enough' => [
                20000, [[0, 0], [9999, 0], [10000, 0], [19999, 0], [20000, 0]], [0, 10000, 20000],
            ],
            'a line for each five seconds' => [12, array_map(static fn(int $i): array => [$i, $i], range(0, 12)), [0, 5, 10, 12]],
            'four seconds are not enough' => [100, [[0, 0], [1, 4], [2, 5], [100, 5]], [0, 2, 100]],
            'the ten thousand count from the last line' => [
                30000, [[0, 0], [10, 5], [10009, 5], [10010, 5], [30000, 5]], [0, 10, 10010, 30000],
            ],
            'no rows' => [0, [[0, 0]], [0]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('import_progress_provider')]
    /**
     * A progress line of the import at the start, then at most one for each ten thousand rows or five seconds, and one when
     * all are done.
     *
     * @dataProvider import_progress_provider
     * @param int $total Rows in all.
     * @param int[][] $calls The calls of the importer: [rows done, time].
     * @param int[] $expected The rows done that the lines tell.
     */
    public function test_the_progress_lines_of_the_import_are_few_and_the_last_has_all_done(
        int $total,
        array $calls,
        array $expected
    ): void {
        [$code, $lines] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function (\Closure $progress) use ($total, $calls): array {
                foreach ($calls as [$done, $time]) {
                    $this->now = $time;
                    $progress($done, $total);
                }
                return ['tables' => 1, 'rows' => $total];
            })
        );
        $this->assertSame(0, $code);
        $progress = array_values(array_filter($lines, static fn(array $line): bool => $line['type'] === 'progress'));
        $this->assertSame($expected, array_column($progress, 'done'));
        $this->assertSame(array_fill(0, count($expected), $total), array_column($progress, 'total'));
        $this->assertSame(array_fill(0, count($expected), 'importing'), array_column($progress, 'stage'));
        $this->assertSame(array_fill(0, count($expected), 'rows'), array_column($progress, 'unit'));
        $this->assertSame('done', end($lines)['type']);
        $this->assertSame('progress', $lines[count($lines) - 2]['type']);
    }

    public function test_what_is_echoed_or_traced_during_the_import_goes_to_the_error_stream_and_not_to_the_lines(): void {
        global $CFG;
        $display = ini_get('display_errors');
        $level = ob_get_level();
        $wrapper = $CFG->mtrace_wrapper ?? null;
        $seen = null;
        [$code, $lines, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function (\Closure $progress) use (&$seen): array {
                global $CFG;
                $seen = [ini_get('display_errors'), $CFG->mtrace_wrapper];
                $progress(0, 2);
                echo 'stray text of the import';
                mtrace('stray trace of the import');
                debugging('stray debugging of the import', DEBUG_DEVELOPER);
                $progress(2, 2);
                return ['tables' => 1, 'rows' => 2];
            })
        );
        $this->assertDebuggingCalled();
        $this->assertSame(0, $code);
        $this->assertSame(['stderr', 'tool_secbackup_restore_mtrace'], $seen);
        $this->assertSame($display, ini_get('display_errors'));
        $this->assertSame($level, ob_get_level());
        $this->assertSame($wrapper, $CFG->mtrace_wrapper ?? null);
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            $this->assertIsArray(json_decode($line, true), $line);
        }
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('stray text of the import', $err);
        $this->assertStringContainsString('stray trace of the import', $err);
        $this->assertStringNotContainsString('stray', $raw);
    }

    /**
     * Import lines that are refused.
     *
     * @return array
     */
    public static function import_line_provider(): array {
        $admin = static fn(string $username, string $password): array => ['username' => $username, 'password' => $password];
        $lines = [
            'no line' => [null],
            'a line that is not JSON' => ['secbackup_admin'],
            'the line of the extraction' => ['{"type":"go","input":"/x"}'],
            'no administrator' => [self::import_line(['admin' => null])],
            'a member that is not known' => [self::import_line(['extra' => 'x'])],
            'another administrator' => [self::import_line(['admin' => $admin('admin', self::PASSWORD)])],
            'an empty password' => [self::import_line(['admin' => $admin('secbackup_admin', '')])],
            'a password longer than Moodle takes' => [
                self::import_line(['admin' => $admin('secbackup_admin', self::PASSWORD . str_repeat('a', 120))]),
            ],
        ];
        $times = [
            'a time without its zone' => '2026-11-10T03:00:00',
            'a time in another zone' => '2026-11-10T03:00:00+09:00',
            'a time with a space' => '2026-11-10 03:00:00Z',
            'a time with a fraction' => '2026-11-10T03:00:00.5Z',
            'a day that is not one' => '2026-02-30T03:00:00Z',
            'an hour that is not one' => '2026-11-10T24:00:00Z',
            'a date only' => '2026-11-10',
            'an empty time' => '',
            'a time with a line break' => "2026-11-10T03:00:00Z\n",
        ];
        foreach ($times as $name => $time) {
            $lines[$name] = [self::import_line(['expires_at' => $time])];
        }
        $hosts = [
            'an empty host' => '',
            'a host of one label' => 'restore',
            'a host in capitals' => 'A1b2c3.restore.test',
            'a host with an underscore' => 'a_1.restore.test',
            'a label that starts with a hyphen' => '-a.restore.test',
            'a label that ends with a hyphen' => 'a-.restore.test',
            'an empty label' => 'a..restore.test',
            'a dot at the end' => 'a.restore.test.',
            'a dot at the start' => '.restore.test',
            'a label of 64 characters' => str_repeat('a', 64) . '.test',
            'a host of 254 characters' => implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63),
                str_repeat('d', 62)]),
            'a host with a port' => 'a.restore.test:443',
            'a host with a path' => 'a.restore.test/x',
            'an address' => 'https://a.restore.test',
            'a host with a line break' => "a.restore.test\n",
        ];
        foreach ($hosts as $name => $host) {
            $lines[$name] = [self::import_line(['temp_host' => $host])];
        }
        return $lines;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('import_line_provider')]
    /**
     * An import line that is not in its form is refused before anything is imported or written, without the password.
     *
     * @dataProvider import_line_provider
     * @param string|null $line The line, or null for none.
     */
    public function test_an_import_line_not_in_its_form_is_refused_before_anything_is_written(?string $line): void {
        global $DB;
        $theme = $DB->get_field('config', 'value', ['name' => 'theme']);
        [$code, $out, $err, $raw] = $this->run_import(
            $line === null ? [] : [$line],
            self::staging(),
            $this->fake_importer(fn(): array => ['tables' => 0, 'rows' => 0])
        );
        $this->assertSame(1, $code);
        $this->assertSame([['type' => 'error', 'code' => 'restore_input_invalid']], $out);
        $this->assertSame(get_string('restore_input_invalid', 'tool_secbackup') . "\n", $err);
        $this->assertNull($this->given, 'The importer was not made.');
        $this->assertFalse($DB->record_exists('user', ['username' => 'secbackup_admin']));
        $this->assertSame(0, $DB->count_records('tool_secbackup_restore_stops'));
        $this->assertSame($theme, $DB->get_field('config', 'value', ['name' => 'theme']));
        $this->assert_no_secret($raw . $err, [self::PASSWORD]);
    }

    /**
     * Forms of the import line that are taken.
     *
     * @return array
     */
    public static function import_line_taken_provider(): array {
        return [
            'a host of 253 characters' => [['temp_host' => implode('.', [str_repeat('a', 63), str_repeat('b', 63),
                str_repeat('c', 63), str_repeat('d', 61)])]],
            'a label of 63 characters' => [['temp_host' => str_repeat('a', 63) . '.test']],
            'labels of one character and digits' => [['temp_host' => 'a.0.test']],
            'a hyphen inside a label' => [['temp_host' => 'a-b.restore.test']],
            'the last second of a leap day' => [['expires_at' => '2028-02-29T23:59:59Z']],
            'a time in the past' => [['expires_at' => '2020-01-01T00:00:00Z']],
            'a password of the most characters that Moodle takes' => [['admin' => ['username' => 'secbackup_admin',
                'password' => str_repeat('p', 128)]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('import_line_taken_provider')]
    /**
     * An import line at the edges of its form is taken.
     *
     * @dataProvider import_line_taken_provider
     * @param array $edit The members that differ from the line of the tests.
     */
    public function test_an_import_line_at_the_edges_of_its_form_is_taken(array $edit): void {
        [$code, $out, $err] = $this->run_import(
            [self::import_line($edit)],
            self::staging(),
            $this->fake_importer(fn(): array => ['tables' => 0, 'rows' => 0])
        );
        $this->assertSame(0, $code, $err);
        $this->assertSame('done', end($out)['type']);
    }

    /**
     * Staging folders whose header cannot be used.
     *
     * @return array
     */
    public static function header_file_provider(): array {
        $header = self::import_header();
        $without = static function (array $header, string ...$path): array {
            $last = array_pop($path);
            $node = &$header;
            foreach ($path as $key) {
                $node = &$node[$key];
            }
            unset($node[$last]);
            return $header;
        };
        return [
            'no header' => [null],
            'a header that is not JSON' => ['{"v":1,'],
            'a header that is a list' => ['[1]'],
            'a header without the settings' => [$without($header, 'config')],
            'a header without the forced settings' => [$without($header, 'config', 'forced')],
            'forced settings without the ones of the plugins' => [$without($header, 'config', 'forced', 'plugins')],
            'a header without the plugins' => [$without($header, 'plugins')],
            'a plugin without standard' => [array_replace($header, ['plugins' => [['component' => 'local_x']]])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('header_file_provider')]
    /**
     * A header that cannot be read or is not in its form is refused before anything is imported or written.
     *
     * @dataProvider header_file_provider
     * @param array|string|null $header The header, its text, or null for none.
     */
    public function test_a_header_that_cannot_be_used_is_refused_before_the_import(array|string|null $header): void {
        global $DB;
        [$code, $out, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging($header),
            $this->fake_importer(fn(): array => ['tables' => 0, 'rows' => 0])
        );
        $this->assertSame(1, $code);
        $this->assertSame([['type' => 'error', 'code' => 'restore_input_invalid']], $out);
        $this->assertNull($this->given);
        $this->assertFalse($DB->record_exists('user', ['username' => 'secbackup_admin']));
        $this->assert_no_secret($raw . $err, [self::PASSWORD]);
    }

    /**
     * Staging folders that are not absolute paths.
     *
     * @return array
     */
    public static function staging_provider(): array {
        return [
            'an empty path' => [''],
            'a relative path' => ['staging'],
            'a path with a null byte' => ["/tmp/a\0b"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staging_provider')]
    /**
     * A staging folder that is not an absolute path is refused, with the real importer too.
     *
     * @dataProvider staging_provider
     * @param string $staging The staging folder.
     */
    public function test_a_staging_folder_that_is_not_an_absolute_path_is_refused(string $staging): void {
        [$code, $out] = $this->run_import([self::import_line()], $staging);
        $this->assertSame(1, $code);
        $this->assertSame([['type' => 'error', 'code' => 'restore_input_invalid']], $out);
    }

    /**
     * Errors of the plugin that the import can end with.
     *
     * @return array
     */
    public static function import_error_provider(): array {
        return [
            'a schema that does not match' => ['restore_import_schema_mismatch', 'course.secbkcolumn', null],
            'rows that do not add up' => ['restore_import_count_mismatch', null, 'course'],
            'a block whose content changed' => ['restore_content_mismatch', null, '1.jsonl.gz'],
            'an input that is not in its form' => ['restore_input_invalid', null, 'manifest'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('import_error_provider')]
    /**
     * An error of the importer is an error line with its code, and its message on the error stream, with nothing after it.
     *
     * @dataProvider import_error_provider
     * @param string $errorcode The code of the error.
     * @param string|null $a The value of its language string.
     * @param string|null $debuginfo Its debug information.
     */
    public function test_an_error_of_the_import_is_an_error_line_and_nothing_is_done_after_it(
        string $errorcode,
        ?string $a,
        ?string $debuginfo
    ): void {
        global $DB;
        $thrown = new plugin_exception($errorcode, $a, $debuginfo);
        [$code, $out, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function (\Closure $progress) use ($thrown): array {
                $progress(0, 5);
                throw $thrown;
            })
        );
        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'error', 'code' => $thrown->errorcode], end($out));
        $this->assertCount(2, $out);
        $this->assertSame($thrown->message() . "\n", $err);
        $this->assertFalse($DB->record_exists('user', ['username' => 'secbackup_admin']));
        $this->assertSame(0, $DB->count_records('tool_secbackup_restore_stops'));
        $this->assert_no_secret($raw . $err, [self::PASSWORD]);
    }

    public function test_the_failure_of_the_database_is_told_without_the_values_of_the_row(): void {
        $previous = new \dml_write_exception('Duplicate entry SECRET-ROW-VALUE', 'INSERT INTO t VALUES (?)', ['SECRET-PARAM']);
        [$code, $out, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function () use ($previous): array {
                throw new import_failed_exception($previous, 'course');
            })
        );
        $this->assertSame(1, $code);
        $this->assertSame([['type' => 'error', 'code' => 'restore_import_failed']], $out);
        $this->assertSame(get_string('restore_import_failed', 'tool_secbackup') . "\n", $err);
        foreach (['SECRET-ROW-VALUE', 'SECRET-PARAM', 'INSERT INTO'] as $text) {
            $this->assertStringNotContainsString($text, $raw . $err);
        }
    }

    public function test_an_error_that_is_not_the_plugin_s_ends_the_import_with_its_class_and_no_error_line(): void {
        $thrown = new \coding_exception('The value SECRET-VALUE of the row of /var/secret/path could not be read');
        [$code, $out, $err, $raw] = $this->run_import(
            [self::import_line()],
            self::staging(),
            $this->fake_importer(function () use ($thrown): array {
                throw $thrown;
            })
        );
        $this->assertSame(1, $code);
        $this->assertSame([], $out);
        $this->assertSame(get_string('cli_restore_failed', 'tool_secbackup', get_class($thrown)) . "\n", $err);
        $this->assertStringNotContainsString('SECRET', $raw . $err);
        $this->assert_no_secret($raw . $err, [self::PASSWORD]);
    }

    public function test_the_error_codes_of_the_import_are_all_strings_of_the_plugin(): void {
        foreach (
            ['restore_input_invalid', 'restore_import_schema_mismatch', 'restore_import_count_mismatch',
                'restore_import_failed'] as $code
        ) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }

    public function test_the_import_runs_again_on_the_same_staging_folder(): void {
        global $DB;
        $staging = self::staging();
        [$first, $lines] = $this->run_import([self::import_line()], $staging);
        $stops = $DB->count_records('tool_secbackup_restore_stops');
        [$second, $again] = $this->run_import([self::import_line(['admin' => ['username' => 'secbackup_admin',
            'password' => 'Another-Pw-2']])], $staging);
        $this->assertSame([0, 0], [$first, $second]);
        $this->assertSame(end($lines), end($again));
        $this->assertSame($stops, $DB->count_records('tool_secbackup_restore_stops'));
        $this->assertSame(1, $DB->count_records('user', ['username' => 'secbackup_admin']));
        $this->assertTrue(self::admin_logs_in('Another-Pw-2'));
        $admin = $DB->get_record('user', ['username' => 'secbackup_admin']);
        $this->assertFalse(validate_internal_user_password($admin, self::PASSWORD));
    }
}
