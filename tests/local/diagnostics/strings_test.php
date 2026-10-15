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

namespace tool_secbackup\local\diagnostics;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests that every language string the diagnostics use exists.
 *
 * The other tests compare a text with get_string() of the same id, which gives the same "[[id]]" on both sides when
 * the string is missing, so they cannot find a missing string.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class strings_test extends \advanced_testcase {
    /**
     * The files of the diagnostics.
     *
     * @return string[]
     */
    private static function files(): array {
        $dir = \core_component::get_component_directory('tool_secbackup');
        return [
            $dir . '/classes/local/diagnostics/runner.php',
            $dir . '/classes/local/cli/diagnose_command.php',
            $dir . '/classes/local/page/diagnostics_page.php',
            $dir . '/classes/form/diagnostics_form.php',
            $dir . '/classes/output/renderer.php',
            $dir . '/classes/local/page/connect_page.php',
        ];
    }

    public function test_every_string_the_diagnostics_name_exists(): void {
        $ids = [];
        foreach (self::files() as $file) {
            preg_match_all("/get_string\\(\\s*'([a-z0-9_]+)'\\s*,\\s*'tool_secbackup'/", file_get_contents($file), $matches);
            $ids = array_merge($ids, $matches[1]);
        }
        $ids = array_unique($ids);
        $this->assertGreaterThan(40, count($ids), 'The scan found the strings.');
        foreach ($ids as $id) {
            $this->assertTrue(get_string_manager()->string_exists($id, 'tool_secbackup'), $id);
        }
    }

    public function test_every_string_the_diagnostics_build_exists(): void {
        $ids = [];
        $checks = ['developer', 'extensions', 'proxy', 'dns', 'connection', 'clock', 'selinux', 'apparmor', 'moodledata'];
        foreach ($checks as $check) {
            $ids[] = 'check_' . $check;
        }
        foreach (['pass', 'warn', 'fail', 'info', 'unknown'] as $result) {
            $ids[] = 'result_' . $result;
        }
        foreach (['cloudflare', 'google', 'os'] as $source) {
            $ids[] = 'diag_dns_source_' . $source;
        }
        // The problems of the name check, which the page shows as the advice.
        foreach (['dns_doh_unavailable', 'dns_not_found', 'dns_outside_aws', 'dns_os_failed', 'dns_os_mismatch'] as $problem) {
            $ids[] = $problem;
        }
        foreach ($ids as $id) {
            $this->assertTrue(get_string_manager()->string_exists($id, 'tool_secbackup'), $id);
        }
    }

    public function test_the_names_of_the_name_sources_are_the_ones_the_guard_has(): void {
        $this->assertSame(['cloudflare', 'google'], \tool_secbackup\local\net\dns_guard::create()->resolver_names());
    }
}
