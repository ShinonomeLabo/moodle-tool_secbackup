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

use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\protocol::class)]
/**
 * Tests for the lines that the restore command and the restore host send each other.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\protocol
 */
final class protocol_test extends \advanced_testcase {
    /**
     * A protocol that reads the given text and writes to a stream of its own.
     *
     * @param string $text What the restore host sends.
     * @return array The protocol and the stream that it writes to.
     */
    private static function talk(string $text): array {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, $text);
        rewind($in);
        $out = fopen('php://memory', 'w+b');
        return [new protocol($in, $out), $out];
    }

    /**
     * Everything that was written to a stream.
     *
     * @param resource $stream The stream.
     * @return string
     */
    private static function written($stream): string {
        rewind($stream);
        return stream_get_contents($stream);
    }

    /**
     * Gives the error code of the exception that a call throws.
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

    public function test_a_message_is_one_line_with_the_slashes_as_they_are(): void {
        [$protocol, $out] = self::talk('');
        $protocol->send(['type' => 'ephemeral_public_key', 'key' => 'a/b+c==']);
        $protocol->send(['type' => 'keys_ready', 'count' => 2]);
        $this->assertSame(
            "{\"type\":\"ephemeral_public_key\",\"key\":\"a/b+c==\"}\n{\"type\":\"keys_ready\",\"count\":2}\n",
            self::written($out)
        );
    }

    public function test_a_line_is_flushed_when_it_is_sent(): void {
        $path = make_request_directory() . '/out';
        $file = fopen($path, 'wb');
        (new protocol(fopen('php://memory', 'rb'), $file))->send(['type' => 'x']);
        // The file is read while its handle is still open: only what was flushed is there.
        $this->assertSame("{\"type\":\"x\"}\n", file_get_contents($path));
    }

    public function test_a_line_that_cannot_be_written_is_a_failure(): void {
        $path = make_request_directory() . '/out';
        touch($path);
        $protocol = new protocol(fopen('php://memory', 'rb'), fopen($path, 'rb'));
        $this->expectException(\RuntimeException::class);
        $protocol->send(['type' => 'x']);
    }

    public function test_a_line_of_65536_bytes_with_its_line_break_is_sent_and_a_longer_one_is_not(): void {
        $base = strlen(json_encode(['type' => 'x', 'pad' => ''])) + 1;
        [$protocol, $out] = self::talk('');
        $protocol->send(['type' => 'x', 'pad' => str_repeat('a', 65536 - $base)]);
        $this->assertSame(65536, strlen(self::written($out)));
        [$protocol, $out] = self::talk('');
        try {
            $protocol->send(['type' => 'x', 'pad' => str_repeat('a', 65537 - $base)]);
            $this->fail('No exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('', self::written($out), 'Nothing of the line is written.');
        }
    }

    public function test_a_message_fits_when_its_line_has_65536_bytes_with_its_line_break(): void {
        $base = strlen(json_encode(['type' => 'x', 'pad' => '']));
        $this->assertTrue(protocol::fits(['type' => 'x', 'pad' => str_repeat('a', 65535 - $base)]));
        $this->assertFalse(protocol::fits(['type' => 'x', 'pad' => str_repeat('a', 65536 - $base)]));
        $this->assertTrue(protocol::fits(['type' => 'x', 'pad' => str_repeat('/', 65535 - $base)]), 'Slashes are not escaped.');
    }

    public function test_a_message_that_is_not_json_is_a_failure(): void {
        [$protocol] = self::talk('');
        $this->expectException(\JsonException::class);
        $protocol->send(['type' => "\xff"]);
    }

    public function test_the_messages_of_the_restore_host_are_given_with_their_members(): void {
        [$protocol] = self::talk(
            "{\"type\":\"site_keys\",\"sealed\":[\"AAAA\",\"BBBB\"]}\n{\"input\":\"/var/x.json\",\"type\":\"go\"}\n"
        );
        $this->assertSame(['type' => 'site_keys', 'sealed' => ['AAAA', 'BBBB']], $protocol->receive('site_keys'));
        $this->assertSame(['input' => '/var/x.json', 'type' => 'go'], $protocol->receive('go'));
    }

    public function test_a_line_without_a_line_break_at_the_end_of_the_input_is_read(): void {
        [$protocol] = self::talk('{"type":"go","input":"/x"}');
        $this->assertSame(['type' => 'go', 'input' => '/x'], $protocol->receive('go'));
    }

    public function test_the_lines_are_read_one_at_a_time(): void {
        [$protocol] = self::talk("{\"type\":\"go\",\"input\":\"/1\"}\n{\"type\":\"go\",\"input\":\"/2\"}\n");
        $this->assertSame('/1', $protocol->receive('go')['input']);
        $this->assertSame('/2', $protocol->receive('go')['input']);
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('go')));
    }

    public function test_a_line_at_the_limit_leaves_the_next_line_to_be_read(): void {
        $line = '{"type":"go","input":"/x"}';
        [$protocol] = self::talk($line . "\n" . $line . "\n");
        $this->assertSame('/x', $protocol->receive('go', strlen($line) + 1)['input']);
        $this->assertSame('/x', $protocol->receive('go', strlen($line) + 1)['input']);
    }

    /**
     * A line and a limit, and whether the line is within it.
     *
     * @return array
     */
    public static function limit_provider(): array {
        $line = '{"type":"go","input":"/x"}';
        $size = strlen($line);
        return [
            'at the limit, with a line break' => [$line . "\n", $size + 1, true],
            'at the limit, at the end of the input' => [$line, $size + 1, true],
            'one byte over, with a line break' => [$line . "\n", $size, false],
            'one byte over, at the end of the input' => [$line, $size, false],
            'far over' => [str_repeat(' ', 5000) . $line . "\n", $size + 1, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('limit_provider')]
    /**
     * A line may be as long as the limit, with its line break, and not longer.
     *
     * @dataProvider limit_provider
     * @param string $text What the restore host sends.
     * @param int $limit The limit.
     * @param bool $accepted Whether the line is read.
     */
    public function test_a_line_longer_than_the_limit_is_refused(string $text, int $limit, bool $accepted): void {
        [$protocol] = self::talk($text);
        if ($accepted) {
            $this->assertSame('/x', $protocol->receive('go', $limit)['input']);
        } else {
            $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('go', $limit)));
        }
    }

    public function test_the_limit_is_64_kib_unless_another_is_given(): void {
        $sealed = array_fill(0, 600, str_repeat('A', 100));
        $line = json_encode(['type' => 'site_keys', 'sealed' => $sealed]);
        $this->assertLessThan(65535, strlen($line));
        [$protocol] = self::talk($line . "\n");
        $this->assertSame($sealed, $protocol->receive('site_keys')['sealed']);
        $line = json_encode(['type' => 'site_keys', 'sealed' => array_fill(0, 700, str_repeat('A', 100))]);
        $this->assertGreaterThan(65536, strlen($line));
        [$protocol] = self::talk($line . "\n");
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('site_keys')));
        $this->assertSame(65536, protocol::MAX_LINE);
    }

    /**
     * Lines that are not the message that is expected.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'nothing' => [''],
            'an empty line' => ["\n"],
            'text' => ["go\n"],
            'a broken object' => ["{\"type\":\"go\",\"input\":\n"],
            'a number' => ["5\n"],
            'a string' => ["\"go\"\n"],
            'null' => ["null\n"],
            'a list' => ["[\"go\",\"/x\"]\n"],
            'an empty object' => ["{}\n"],
            'no type' => ["{\"input\":\"/x\"}\n"],
            'a type that is not text' => ["{\"type\":1,\"input\":\"/x\"}\n"],
            'the type of another message' => ["{\"type\":\"site_keys\",\"sealed\":[\"A\"]}\n"],
            'a type in other letters' => ["{\"type\":\"Go\",\"input\":\"/x\"}\n"],
            'no member' => ["{\"type\":\"go\"}\n"],
            'a member that is not known' => ["{\"type\":\"go\",\"input\":\"/x\",\"extra\":1}\n"],
            'a member that is not known instead of the one' => ["{\"type\":\"go\",\"path\":\"/x\"}\n"],
            'a member of another shape' => ["{\"type\":\"go\",\"input\":[\"/x\"]}\n"],
            'a member that is null' => ["{\"type\":\"go\",\"input\":null}\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    /**
     * A line that is not the message that is expected is refused, in any detail.
     *
     * @dataProvider refused_provider
     * @param string $text What the restore host sends.
     */
    public function test_a_line_that_is_not_the_expected_message_is_refused(string $text): void {
        [$protocol] = self::talk($text);
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('go')));
    }

    /**
     * Values of the member with the sealed keys that are not a list.
     *
     * @return array
     */
    public static function sealed_provider(): array {
        return [
            'no list' => ['"A"'],
            'an object' => ['{"a":"A"}'],
            'null' => ['null'],
            'a member that is not known' => ['["A"],"extra":true'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sealed_provider')]
    /**
     * The sealed keys are a list; what is in the list is for the key exchange to judge.
     *
     * @dataProvider sealed_provider
     * @param string $sealed The JSON of the member, and what follows it.
     */
    public function test_the_sealed_keys_are_a_list(string $sealed): void {
        [$protocol] = self::talk('{"type":"site_keys","sealed":' . $sealed . "}\n");
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('site_keys')));
    }

    public function test_the_items_of_the_sealed_keys_are_not_judged_here(): void {
        [$protocol] = self::talk("{\"type\":\"site_keys\",\"sealed\":[1,null,\"A\"]}\n");
        $this->assertSame([1, null, 'A'], $protocol->receive('site_keys')['sealed']);
    }

    /**
     * The line of the import, as the restore host writes it.
     *
     * @param array $edit Members to change: a value each, or null to leave the member out.
     * @return string
     */
    private static function import_line(array $edit = []): string {
        $message = [
            'admin' => ['username' => 'secbackup_admin', 'password' => 'Pa55-word!'],
            'expires_at' => '2026-11-10T03:00:00Z',
            'temp_host' => 'abc.restore.test',
        ];
        foreach ($edit as $name => $value) {
            if ($value === null) {
                unset($message[$name]);
            } else {
                $message[$name] = $value;
            }
        }
        return json_encode($message, JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function test_the_line_of_the_import_has_no_type_and_is_given_with_its_members(): void {
        [$protocol] = self::talk(self::import_line());
        $this->assertSame(
            [
                'admin' => ['username' => 'secbackup_admin', 'password' => 'Pa55-word!'],
                'expires_at' => '2026-11-10T03:00:00Z',
                'temp_host' => 'abc.restore.test',
            ],
            $protocol->receive('import')
        );
    }

    public function test_the_members_of_the_import_line_may_come_in_any_order(): void {
        [$protocol] = self::talk(
            "{\"temp_host\":\"h.test\",\"admin\":{\"password\":\"p\",\"username\":\"u\"},\"expires_at\":\"e\"}\n"
        );
        $this->assertSame(['password' => 'p', 'username' => 'u'], $protocol->receive('import')['admin']);
    }

    /**
     * Import lines that are not of the shape of the import.
     *
     * @return array
     */
    public static function import_refused_provider(): array {
        $admin = ['username' => 'secbackup_admin', 'password' => 'p'];
        return [
            'a type' => [self::import_line(['type' => 'import'])],
            'a member that is not known' => [self::import_line(['extra' => 1])],
            'no admin' => [self::import_line(['admin' => null])],
            'no time' => [self::import_line(['expires_at' => null])],
            'no host' => [self::import_line(['temp_host' => null])],
            'an admin that is text' => [self::import_line(['admin' => 'secbackup_admin'])],
            'an admin that is a list' => [self::import_line(['admin' => ['secbackup_admin', 'p']])],
            'an admin that is an empty object' => [str_replace('"admin":[]', '"admin":{}', self::import_line(['admin' => []]))],
            'an admin without a password' => [self::import_line(['admin' => ['username' => 'secbackup_admin']])],
            'an admin without a user name' => [self::import_line(['admin' => ['password' => 'p']])],
            'an admin with a member that is not known' => [self::import_line(['admin' => $admin + ['email' => 'a@b.test']])],
            'an admin with a member that is not known instead of one' => [
                self::import_line(['admin' => ['username' => 'secbackup_admin', 'pass' => 'p']]),
            ],
            'a password that is a number' => [self::import_line(['admin' => ['username' => 'secbackup_admin', 'password' => 5]])],
            'a password that is null' => [str_replace('"p"', 'null', self::import_line(['admin' => $admin]))],
            'a user name that is a list' => [self::import_line(['admin' => ['username' => ['a'], 'password' => 'p']])],
            'a time that is a number' => [self::import_line(['expires_at' => 1793674800])],
            'a host that is a list' => [self::import_line(['temp_host' => ['abc.restore.test']])],
            'a list of the members' => ["[\"secbackup_admin\",\"p\",\"2026-11-10T03:00:00Z\",\"h.test\"]\n"],
            'the line of another message' => ["{\"type\":\"go\",\"input\":\"/x\"}\n"],
            'nothing' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('import_refused_provider')]
    /**
     * A line that is not the import line is refused, in any detail, also inside the admin.
     *
     * @dataProvider import_refused_provider
     * @param string $text What the restore host sends.
     */
    public function test_an_import_line_not_of_its_shape_is_refused(string $text): void {
        [$protocol] = self::talk($text);
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('import')));
    }

    public function test_a_typed_message_still_needs_its_type(): void {
        [$protocol] = self::talk("{\"input\":\"/x\"}\n");
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('go')));
        [$protocol] = self::talk("{\"type\":\"go\",\"type2\":\"go\"}\n");
        $this->assertSame('restore_input_invalid', $this->code_of(fn() => $protocol->receive('go')));
    }

    public function test_a_refused_import_line_leaves_nothing_of_the_password_in_the_exception(): void {
        [$protocol] = self::talk(self::import_line(['admin' => ['username' => 'u', 'password' => 'Secret-PW-9', 'x' => 1]]));
        try {
            $protocol->receive('import');
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('Secret-PW-9', (string) $e);
        }
    }

    public function test_a_message_that_the_restore_host_does_not_send_is_a_coding_error(): void {
        [$protocol] = self::talk("{\"type\":\"keys_ready\",\"count\":1}\n");
        $this->expectException(\coding_exception::class);
        $protocol->receive('keys_ready');
    }

    public function test_a_refused_line_leaves_nothing_of_the_line_in_the_exception(): void {
        [$protocol] = self::talk("{\"type\":\"go\",\"input\":\"/secret-path-AAAA\",\"extra\":1}\n");
        try {
            $protocol->receive('go');
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('secret-path-AAAA', (string) $e);
        }
    }
}
