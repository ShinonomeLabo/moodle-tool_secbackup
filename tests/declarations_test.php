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

namespace tool_secbackup;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the declaration files (version, capabilities, language strings).
 *
 * Moodle reads these files during installation, before any test runs, so the tests read them
 * again here and check what they declare.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class declarations_test extends \advanced_testcase {
    /**
     * Returns the plugin directory on any Moodle version.
     *
     * @return string
     */
    private static function dir(): string {
        return \core_component::get_component_directory('tool_secbackup');
    }

    public function test_version(): void {
        $plugin = new \stdClass();
        include(self::dir() . '/version.php');
        $this->assertSame('tool_secbackup', $plugin->component);
        $this->assertSame(2024100700, $plugin->requires);
        $this->assertSame([405, 503], $plugin->supported);
        $this->assertMatchesRegularExpression('/^\d{10}$/', (string) $plugin->version);
    }

    public function test_capabilities(): void {
        $capabilities = [];
        include(self::dir() . '/db/access.php');
        $this->assertSame(['tool/secbackup:manage'], array_keys($capabilities));
        $this->assertSame(CONTEXT_SYSTEM, $capabilities['tool/secbackup:manage']['contextlevel']);
        $this->assertSame([], $capabilities['tool/secbackup:manage']['archetypes']);
    }

    /**
     * Reads one language file.
     *
     * @param string $lang Language code.
     * @return array The strings.
     */
    private static function strings(string $lang): array {
        $string = [];
        include(self::dir() . "/lang/$lang/tool_secbackup.php");
        return $string;
    }

    public function test_languages_have_the_same_strings_in_order(): void {
        $en = self::strings('en');
        $ja = self::strings('ja');
        $this->assertSame(array_keys($en), array_keys($ja));
        $keys = array_keys($en);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'String ids must be in ASCII order.');
    }

    public function test_names_do_not_contain_the_moodle_trademark(): void {
        $this->assertStringNotContainsStringIgnoringCase('moodle', self::strings('en')['pluginname']);
        $this->assertStringNotContainsStringIgnoringCase('moodle', self::strings('ja')['pluginname']);
    }

    public function test_the_invalid_settings_string_names_the_constant_in_both_languages(): void {
        foreach (['en', 'ja'] as $lang) {
            $text = self::strings($lang)['developersettings_invalid'];
            $this->assertStringContainsString('TOOL_SECBACKUP_DEVELOPER', $text, $lang);
            $this->assertStringNotContainsString('$CFG', $text, $lang);
        }
    }

    public function test_japanese_strings_call_the_plugin_a_linking_tool(): void {
        // The plugin has one name in the Japanese strings. Written as escapes to keep this file ASCII.
        $strings = self::strings('ja');
        foreach ($strings as $id => $text) {
            $this->assertStringNotContainsString("\u{30D7}\u{30E9}\u{30B0}\u{30A4}\u{30F3}", $text, $id);
        }
        $this->assertStringContainsString("\u{9023}\u{643A}\u{30C4}\u{30FC}\u{30EB}", $strings['privacy:metadata:service']);
        $this->assertStringContainsString("\u{9023}\u{643A}\u{30C4}\u{30FC}\u{30EB}", $strings['envelope_untrusted']);
    }

    public function test_japanese_strings_have_no_emoji(): void {
        foreach (self::strings('ja') as $id => $text) {
            $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text), $id);
        }
    }
}
