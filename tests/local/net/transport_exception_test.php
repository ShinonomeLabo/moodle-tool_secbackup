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

namespace tool_secbackup\local\net;

use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\remade_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\transport_exception::class)]
/**
 * Tests for the errors of the connections.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\transport_exception
 */
final class transport_exception_test extends \advanced_testcase {
    public function test_a_connection_or_the_lookups_that_failed_at_once_failed_at_connect(): void {
        $this->assertTrue(transport_exception::failed_at_connect(new transport_exception('transport_connect', 'cURL error 7')));
        $this->assertTrue(transport_exception::failed_at_connect(new transport_exception('dns_doh_unavailable', 'cURL error 7')));
        // The error of the plugin that the step keeps when it makes the error again from its parts.
        $remade = remade_exception::build(remade_exception::parts(new plugin_exception('transport_connect', null, 'cURL error 7')));
        $this->assertTrue(transport_exception::failed_at_connect($remade));
    }

    public function test_a_timeout_another_failure_or_another_error_did_not_fail_at_connect(): void {
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('transport_connect', 'cURL error 28')));
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('transport_connect', 'cURL error 70')));
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('transport_connect')));
        $this->assertFalse(transport_exception::failed_at_connect(new transport_exception('dns_not_found', 'cURL error 7')));
        $this->assertFalse(transport_exception::failed_at_connect(new plugin_exception('temp_write_failed', null, 'cURL error 7')));
    }

    public function test_the_code_and_the_debug_information_are_kept(): void {
        $e = new transport_exception('transport_tls', 'cURL error 60');
        $this->assertSame('transport_tls', $e->errorcode);
        $this->assertSame('cURL error 60', $e->debuginfo);
        $this->assertNull($e->a);
    }
}
