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

namespace tool_secbackup\local\backup;

use tool_secbackup\local\api\client;
use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\abort_reason::class)]
/**
 * Tests for the texts of the reasons to stop a run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\abort_reason
 */
final class abort_reason_test extends \advanced_testcase {
    /**
     * The strings of the plugin in a language, read from its file.
     *
     * @param string $lang The language.
     * @return string[]
     */
    private static function strings(string $lang): array {
        $string = [];
        include(\core_component::get_component_directory('tool_secbackup') . '/lang/' . $lang . '/tool_secbackup.php');
        return $string;
    }

    public function test_the_codes_are_the_ones_a_run_can_record(): void {
        $this->assertSame(array_merge(client::FAIL_CODES, abort::SERVICE_CODES), abort_reason::codes());
        $this->assertCount(20, abort_reason::codes());
    }

    public function test_every_code_has_a_text_and_an_advice_in_both_languages(): void {
        foreach (['en', 'ja'] as $lang) {
            $strings = self::strings($lang);
            foreach (abort_reason::codes() as $code) {
                $this->assertNotEmpty($strings['abort_' . $code] ?? '', $lang . ' abort_' . $code);
                $this->assertNotEmpty($strings['abort_' . $code . '_advice'] ?? '', $lang . ' abort_' . $code . '_advice');
            }
            $this->assertNotEmpty($strings['abort_unknown_advice'] ?? '', $lang . ' abort_unknown_advice');
        }
    }

    public function test_the_text_and_the_advice_are_the_strings_of_the_code(): void {
        foreach (abort_reason::codes() as $code) {
            $this->assertSame(get_string('abort_' . $code, 'tool_secbackup'), abort_reason::text($code), $code);
            $this->assertSame(get_string('abort_' . $code . '_advice', 'tool_secbackup'), abort_reason::advice($code), $code);
        }
    }

    public function test_a_denial_of_selinux_has_the_advice_of_what_was_denied(): void {
        $this->assertSame(
            get_string('selinux_denial_network', 'tool_secbackup', '/data/tool'),
            abort_reason::advice('selinux_denied', null, ['denial' => 'network', 'folder' => '/data/tool'])
        );
        $this->assertSame(
            get_string('selinux_denial_tempfolder', 'tool_secbackup', '/data/tool'),
            abort_reason::advice('selinux_denied', null, ['denial' => 'tempfolder', 'folder' => '/data/tool'])
        );
        $this->assertStringContainsString(
            'semanage fcontext -a -t httpd_sys_rw_content_t "/data/tool(/.*)?"',
            abort_reason::advice('selinux_denied', 'en', ['denial' => 'tempfolder', 'folder' => '/data/tool'])
        );
        $this->assertStringContainsString(
            'restorecon -Rv /data/tool',
            abort_reason::advice('selinux_denied', 'ja', ['denial' => 'tempfolder', 'folder' => '/data/tool'])
        );
        // A stop that kept no folder names the folder that is set now.
        $this->assertSame(
            get_string('selinux_denial_tempfolder', 'tool_secbackup', limits::current()->tempdir),
            abort_reason::advice('selinux_denied', null, ['denial' => 'tempfolder'])
        );
        // A stop that kept nothing, or something unknown, has the advice of the reason.
        $this->assertSame(get_string('abort_selinux_denied_advice', 'tool_secbackup'), abort_reason::advice('selinux_denied'));
        $this->assertSame(
            get_string('abort_selinux_denied_advice', 'tool_secbackup'),
            abort_reason::advice('selinux_denied', null, ['denial' => 'other'])
        );
        $this->assertSame(
            get_string('abort_disk_low_advice', 'tool_secbackup'),
            abort_reason::advice('disk_low', null, ['denial' => 'network'])
        );
    }

    public function test_a_stop_for_a_content_names_the_file(): void {
        $hash = str_repeat('ab', 20);
        $file = ['contenthash' => $hash, 'path' => 'filedir/ab/ab/' . $hash, 'component' => 'mod_resource',
            'filearea' => 'content'];
        $this->assertSame(
            get_string('abort_tamper_detected', 'tool_secbackup') . ' '
            . get_string('abort_file', 'tool_secbackup', (object) ['path' => 'filedir/ab/ab/' . $hash,
                'component' => 'mod_resource', 'filearea' => 'content']),
            abort_reason::text('tamper_detected', null, ['file' => $file])
        );
        $this->assertStringContainsString('mod_resource', abort_reason::text('nfs_slow', 'ja', ['file' => $file]));
        // The name and the item of a file, which a stop of an earlier version kept, are not shown: they can be personal data.
        $older = abort_reason::text('tamper_detected', null, ['file' => $file + ['itemid' => 81, 'filename' => 'notes.pdf']]);
        $this->assertSame(abort_reason::text('tamper_detected', null, ['file' => $file]), $older);
        // A content that no file of the site refers to any more is named by where it is.
        $content = ['contenthash' => $hash, 'path' => 'filedir/ab/ab/' . $hash];
        $this->assertSame(
            get_string('abort_tamper_detected', 'tool_secbackup') . ' '
            . get_string('abort_file_content', 'tool_secbackup', (object) ['path' => 'filedir/ab/ab/' . $hash]),
            abort_reason::text('tamper_detected', null, ['file' => $content])
        );
        // Without a file, or with one that is not what a stop keeps, the text is the one of the reason.
        $this->assertSame(get_string('abort_tamper_detected', 'tool_secbackup'), abort_reason::text('tamper_detected'));
        $this->assertSame(get_string('abort_nfs_slow', 'tool_secbackup'), abort_reason::text('nfs_slow', null, ['file' => 'x']));
        $this->assertSame(get_string('abort_nfs_slow', 'tool_secbackup'), abort_reason::text('nfs_slow', null, ['file' => []]));
    }

    public function test_a_code_without_a_text_of_its_own_is_named(): void {
        $this->assertSame(get_string('abort_unknown', 'tool_secbackup', 'something_new'), abort_reason::text('something_new'));
        $this->assertStringContainsString('something_new', abort_reason::text('something_new'));
        $this->assertSame(get_string('abort_unknown_advice', 'tool_secbackup'), abort_reason::advice('something_new'));
    }

    public function test_a_string_of_another_kind_is_not_taken_for_a_reason(): void {
        // The string 'pluginname' is a string of the plugin, but it is not a reason.
        $this->assertSame(get_string('abort_unknown', 'tool_secbackup', 'pluginname'), abort_reason::text('pluginname'));
    }

    public function test_the_language_can_be_chosen(): void {
        global $CFG;
        $this->resetAfterTest();
        // A language that is not installed falls back to English, so a minimal Japanese pack is put in place.
        $dir = $CFG->langotherroot . '/ja';
        make_writable_directory($dir);
        $file = $dir . '/langconfig.php';
        file_put_contents($file, "<?php\n\$string['thislanguage'] = 'Japanese';\n\$string['thislanguageint'] = 'Japanese';\n");
        get_string_manager()->reset_caches();
        try {
            $japanese = self::strings('ja');
            $this->assertSame($japanese['abort_disk_low'], abort_reason::text('disk_low', 'ja'));
            $this->assertSame($japanese['abort_disk_low_advice'], abort_reason::advice('disk_low', 'ja'));
            $this->assertSame(self::strings('en')['abort_disk_low'], abort_reason::text('disk_low', 'en'));
        } finally {
            unlink($file);
            rmdir($dir);
            get_string_manager()->reset_caches();
        }
    }

    public function test_every_reason_that_can_be_thrown_is_a_message_of_an_exception(): void {
        // The monitor and the run engine throw their reasons as errors of the plugin, whose message is the string of the code.
        foreach (array_merge(monitor::REASONS, client::FAIL_CODES) as $code) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'tool_secbackup'), $code);
            $this->assertSame(get_string($code, 'tool_secbackup'), (new plugin_exception($code))->message(), $code);
        }
        $this->assertCount(4, monitor::REASONS);
    }

    public function test_what_happened_does_not_repeat_the_advice(): void {
        $en = self::strings('en');
        $this->assertStringNotContainsString('diagnostics', $en['abort_selinux_denied']);
        $this->assertStringNotContainsString('diagnostics', $en['abort_network_check_failed']);
        $this->assertStringNotContainsString('register it again', $en['abort_signature_invalid']);
        $this->assertStringNotContainsString('Update the plugin', $en['abort_version_too_old']);
        $ja = self::strings('ja');
        $this->assertStringNotContainsString('接続の診断', $ja['abort_selinux_denied']);
        $this->assertStringNotContainsString('接続の診断', $ja['abort_network_check_failed']);
        $this->assertStringNotContainsString('もう一度登録', $ja['abort_signature_invalid']);
        $this->assertStringNotContainsString('新しくしてください', $ja['abort_version_too_old']);
        // That the next backup tries again is advice.
        $this->assertStringNotContainsString('next scheduled backup', $en['abort_upload_failed']);
        $this->assertStringContainsString('next scheduled backup', $en['abort_upload_failed_advice']);
        $this->assertStringNotContainsString('次の予定のバックアップ', $ja['abort_upload_failed']);
        $this->assertStringContainsString('次の予定のバックアップ', $ja['abort_upload_failed_advice']);
    }

    public function test_a_refusal_has_its_own_text_and_advice(): void {
        $this->assertSame(
            get_string('refused_contract_inactive', 'tool_secbackup', 'P'),
            abort_reason::refusal_text('contract_inactive', null, 'P')
        );
        $this->assertSame(
            get_string('refused_contract_inactive_advice', 'tool_secbackup', 'P'),
            abort_reason::refusal_advice('contract_inactive', 'P')
        );
        $this->assertSame(
            get_string('refused_run_limit_reached', 'tool_secbackup'),
            abort_reason::refusal_text('run_limit_reached', null, 'P')
        );
        $this->assertSame(
            get_string('refused_run_limit_reached_at', 'tool_secbackup', userdate(1760003600)),
            abort_reason::refusal_text('run_limit_reached', 1760003600, 'P', 1760003599)
        );
        $this->assertSame(get_string('refused_unknown', 'tool_secbackup', 'x'), abort_reason::refusal_text('x', null, 'P'));
        $this->assertSame(get_string('refused_unknown_advice', 'tool_secbackup'), abort_reason::refusal_advice('x', 'P'));
    }

    public function test_a_time_to_start_again_that_has_passed_is_not_shown(): void {
        $now = 1760003600;
        $this->assertSame(
            get_string('refused_run_limit_reached_at', 'tool_secbackup', userdate($now + 1)),
            abort_reason::refusal_text('run_limit_reached', $now + 1, 'P', $now)
        );
        foreach ([$now, $now - 3600] as $retryat) {
            $this->assertSame(
                get_string('refused_run_limit_reached', 'tool_secbackup'),
                abort_reason::refusal_text('run_limit_reached', $retryat, 'P', $now),
                (string) $retryat
            );
        }
        // Without a time given, it is now.
        $this->assertSame(
            get_string('refused_run_limit_reached', 'tool_secbackup'),
            abort_reason::refusal_text('run_limit_reached', time() - 60, 'P')
        );
        $this->assertSame(
            get_string('refused_run_limit_reached_at', 'tool_secbackup', userdate(time() + 3600)),
            abort_reason::refusal_text('run_limit_reached', time() + 3600, 'P')
        );
    }
}
