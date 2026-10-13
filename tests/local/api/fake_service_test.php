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

use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the fake service itself: it has to refuse the headers that the real service refuses, and it has to stand on its
 * own, so that it checks the plugin independently and can be loaded outside Moodle.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class fake_service_test extends \basic_testcase {
    /** @var string Connection URL of the fake service. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int Clock of the fake service. */
    private const NOW = 1760000000;

    /**
     * A fake service with a fixed clock, and nobody enrolled.
     *
     * @return fake_service
     */
    private static function service(): fake_service {
        return new fake_service(self::API, array_merge(fake_service::initial_state(), ['now' => self::NOW]));
    }

    /**
     * Headers that have the shape of the signature headers.
     *
     * @return string[]
     */
    private static function headers(): array {
        return [
            'x-secbackup-timestamp' => (string) self::NOW,
            'x-secbackup-nonce' => 'AAAAAAAAAAAAAAAAAAAAAA',
            'x-secbackup-signature' => envelope_builder::b64url(str_repeat("\x01", 64)),
        ];
    }

    /**
     * Sends a status request with these headers.
     *
     * @param string[] $headers Headers.
     * @return array [HTTP status, error code]
     */
    private static function status_with(array $headers): array {
        $answer = self::service()->handle('GET', 'app.example.test', '/api/lms-backup/v1/status', $headers, '');
        return [$answer[0], json_decode($answer[2], true)['error']['code']];
    }

    public function test_headers_of_the_right_shape_go_on_to_the_next_check(): void {
        // The shape is taken, so the service goes on and finds that nobody is enrolled.
        $this->assertSame([401, 'site_not_enrolled'], self::status_with(self::headers()));
    }

    /**
     * Headers in a wrong shape: the name of the header and its value.
     *
     * @return array
     */
    public static function malformed_header_provider(): array {
        $signature = self::headers()['x-secbackup-signature'];
        return [
            'timestamp with a trailing line feed' => ['x-secbackup-timestamp', self::NOW . "\n"],
            'timestamp with a leading line feed' => ['x-secbackup-timestamp', "\n" . self::NOW],
            'timestamp of 13 digits' => ['x-secbackup-timestamp', '1000000000000'],
            'empty timestamp' => ['x-secbackup-timestamp', ''],
            'nonce with a trailing line feed' => ['x-secbackup-nonce', "AAAAAAAAAAAAAAAAAAAAAA\n"],
            'nonce with a leading line feed' => ['x-secbackup-nonce', "\nAAAAAAAAAAAAAAAAAAAAAA"],
            'nonce one character short' => ['x-secbackup-nonce', 'AAAAAAAAAAAAAAAAAAAAA'],
            'nonce one character long' => ['x-secbackup-nonce', 'AAAAAAAAAAAAAAAAAAAAAAA'],
            'signature with a trailing line feed' => ['x-secbackup-signature', $signature . "\n"],
            'signature with a leading line feed' => ['x-secbackup-signature', "\n" . $signature],
            'signature with a padding character' => ['x-secbackup-signature', $signature . '=='],
            'signature of 63 bytes' => ['x-secbackup-signature', envelope_builder::b64url(str_repeat("\x01", 63))],
            'empty signature' => ['x-secbackup-signature', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_header_provider')]
    /**
     * A header in a wrong shape gives signature_required, even when only a line feed is wrong.
     *
     * @dataProvider malformed_header_provider
     * @param string $name Header name.
     * @param string $value Header value.
     */
    public function test_malformed_headers_are_refused(string $name, string $value): void {
        $this->assertSame([401, 'signature_required'], self::status_with([$name => $value] + self::headers()));
    }

    public function test_it_uses_nothing_of_the_plugin_or_of_moodle(): void {
        // The development server loads this file without Moodle, and a test that uses the plugin's own classes to check the
        // plugin would pass even when both are wrong in the same way.
        $tokens = token_get_all((string) file_get_contents((new \ReflectionClass(fake_service::class))->getFileName()));
        $moodle = array_flip(get_defined_functions()['user']);
        $found = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            [$type, $text] = $token;
            $name = ltrim($text, '\\');
            $isclass = in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && (str_starts_with($name, 'tool_secbackup\\local') || str_starts_with($name, 'core'));
            $isfunction = $type === T_STRING && isset($moodle[strtolower($text)]) && self::is_function_call($tokens, $index);
            if ($isclass || $isfunction || $type === T_GLOBAL) {
                $found[] = $text;
            }
        }
        $this->assertSame([], $found);
    }

    /**
     * Tells whether the name at a position of the tokens is the call of a function (and not of a method, or a name of a class).
     *
     * @param array $tokens Result of token_get_all().
     * @param int $index Position of the name.
     * @return bool
     */
    private static function is_function_call(array $tokens, int $index): bool {
        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        $before = $index - 1;
        while (isset($tokens[$before]) && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
            $before--;
        }
        $previous = $tokens[$before] ?? null;
        $isaccess = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION], true);
        return ($tokens[$next] ?? null) === '(' && !$isaccess;
    }
}
