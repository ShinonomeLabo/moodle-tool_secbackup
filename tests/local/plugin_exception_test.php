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

namespace tool_secbackup\local;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\plugin_exception::class)]
/**
 * Tests for the plugin's base exception.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\plugin_exception
 */
final class plugin_exception_test extends \advanced_testcase {
    public function test_message_leaves_out_the_debug_information(): void {
        $e = new plugin_exception('publickey_length', 'value', 'detail');
        $this->assertSame(get_string('publickey_length', 'tool_secbackup'), $e->message());
        $this->assertSame('publickey_length', $e->errorcode);
        $this->assertSame('tool_secbackup', $e->module);
        $this->assertSame('value', $e->a);
        $this->assertSame('detail', $e->debuginfo);
        $this->assertInstanceOf(\moodle_exception::class, $e);
    }

    public function test_the_key_error_codes_are_language_strings(): void {
        foreach (['publickey_base64', 'publickey_length', 'publickey_prefix', 'signingkey_corrupt'] as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
        }
    }
}
