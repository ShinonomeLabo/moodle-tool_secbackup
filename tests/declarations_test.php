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
        $this->assertSame(2026102800, $plugin->version);
        $this->assertSame('0.5.0', $plugin->release);
    }

    public function test_the_restore_stops_table_is_declared(): void {
        $file = new \xmldb_file(self::dir() . '/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $table = $file->getStructure()->getTable('tool_secbackup_restore_stops');
        $this->assertInstanceOf(\xmldb_table::class, $table);
        $fields = [];
        foreach ($table->getFields() as $field) {
            $fields[$field->getName()] = [$field->getType(), (string) $field->getLength(), $field->getNotNull(),
                $field->getSequence()];
        }
        $this->assertSame([
            'id' => [XMLDB_TYPE_INTEGER, '10', true, true],
            'kind' => [XMLDB_TYPE_CHAR, '16', true, false],
            'name' => [XMLDB_TYPE_CHAR, '255', true, false],
            'previous' => [XMLDB_TYPE_TEXT, '', false, false],
            'timecreated' => [XMLDB_TYPE_INTEGER, '10', true, false],
        ], $fields);
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[$index->getName()] = [$index->getUnique(), $index->getFields()];
        }
        $this->assertSame(['kind' => [false, ['kind']]], $indexes);
    }

    /**
     * The ids of the strings the restore adds.
     *
     * @return string[]
     */
    private static function restore_ids(): array {
        return ['restore_admin_fullname', 'restore_chunk_corrupt', 'restore_chunk_missing', 'restore_content_mismatch',
            'restore_core_official_unsupported', 'restore_import_count_mismatch', 'restore_import_failed',
            'restore_import_schema_mismatch', 'restore_input_invalid', 'restore_keys_invalid', 'restore_manifest_invalid',
            'restore_path_unsafe', 'restore_write_failed'];
    }

    public function test_the_restore_strings_exist_in_both_languages(): void {
        foreach (['en', 'ja'] as $lang) {
            $strings = self::strings($lang);
            foreach (self::restore_ids() as $id) {
                $this->assertArrayHasKey($id, $strings, "$lang $id");
                $this->assertNotSame('', trim($strings[$id]), "$lang $id");
            }
        }
        $this->assertCount(13, array_filter(array_keys(self::strings('en')), fn($id) => strpos($id, 'restore_') === 0));
    }

    public function test_the_restore_strings_speak_of_restoring_a_backup_and_never_name_the_platform_brand(): void {
        foreach (['en', 'ja'] as $lang) {
            $strings = self::strings($lang);
            foreach (self::restore_ids() as $id) {
                $this->assertStringNotContainsString('SEC', $strings[$id], "$lang $id");
            }
        }
        $japanese = "\u{4E00}\u{6642}\u{30B5}\u{30A4}\u{30C8}\u{306E}\u{7BA1}\u{7406}\u{8005}";
        $this->assertSame($japanese, self::strings('ja')['restore_admin_fullname']);
        $this->assertSame('Temporary site administrator', self::strings('en')['restore_admin_fullname']);
    }

    public function test_message_providers(): void {
        $messageproviders = [];
        include(self::dir() . '/db/messages.php');
        $this->assertSame(['connection', 'backupfailure'], array_keys($messageproviders));
        foreach ($messageproviders as $name => $provider) {
            $this->assertSame('tool/secbackup:manage', $provider['capability'], $name);
            $this->assertSame(['popup', 'email'], array_keys($provider['defaults']), $name);
            $this->assertSame(MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED, $provider['defaults']['popup'], $name);
            $this->assertSame(MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED, $provider['defaults']['email'], $name);
        }
    }

    public function test_scheduled_tasks(): void {
        $tasks = [];
        include(self::dir() . '/db/tasks.php');
        $this->assertSame([['classname' => 'tool_secbackup\task\poll_status', 'blocking' => 0, 'minute' => '*/5',
            'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*']], $tasks);
        $this->assertTrue(class_exists($tasks[0]['classname']), 'The scheduled task class must exist.');
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

    /**
     * The placeholders of a language string, such as {$a} and {$a->name}, in the order of the text.
     *
     * @param string $text The string.
     * @return string[]
     */
    private static function placeholders(string $text): array {
        preg_match_all('/\{\$a(?:->\w+)?\}/', $text, $matches);
        return $matches[0];
    }

    public function test_japanese_strings_have_the_placeholders_of_the_english_ones(): void {
        // A string that lacks the value in one language shows a message without it, and nothing fails.
        $en = self::strings('en');
        $withvalue = 0;
        foreach (self::strings('ja') as $id => $text) {
            $this->assertSame(self::placeholders($en[$id]), self::placeholders($text), $id);
            $withvalue += self::placeholders($text) === [] ? 0 : 1;
        }
        $this->assertGreaterThanOrEqual(3, $withvalue, 'The strings with a value are the ones that are compared.');
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
            // The warning about files in an external cloud service is the sentence of the design, which speaks of the plugin
            // of the site that keeps the files there, not of this one.
            if ($id !== 'modal_external') {
                $this->assertStringNotContainsString("\u{30D7}\u{30E9}\u{30B0}\u{30A4}\u{30F3}", $text, $id);
            }
        }
        $this->assertStringContainsString("\u{9023}\u{643A}\u{30C4}\u{30FC}\u{30EB}", $strings['privacy:metadata:service']);
        $this->assertStringContainsString("\u{9023}\u{643A}\u{30C4}\u{30FC}\u{30EB}", $strings['envelope_untrusted']);
    }

    public function test_japanese_strings_have_no_emoji(): void {
        foreach (self::strings('ja') as $id => $text) {
            $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text), $id);
        }
    }

    public function test_settings_register_the_pages(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');
        $tree = \admin_get_root(true, false);
        $this->assertInstanceOf(\admin_category::class, $tree->locate('tool_secbackup'));
        $this->assertSame(
            (new \moodle_url('/admin/tool/secbackup/index.php'))->out(false),
            $tree->locate('tool_secbackup_status')->url->out(false)
        );
        $this->assertSame(
            (new \moodle_url('/admin/tool/secbackup/connect.php'))->out(false),
            $tree->locate('tool_secbackup_connect')->url->out(false)
        );
        $this->assertSame(['tool/secbackup:manage'], $tree->locate('tool_secbackup_status')->req_capability);
        $this->assertSame(
            (new \moodle_url('/admin/tool/secbackup/diagnostics.php'))->out(false),
            $tree->locate('tool_secbackup_diagnostics')->url->out(false)
        );
        $this->assertSame(['tool/secbackup:manage'], $tree->locate('tool_secbackup_connect')->req_capability);
        $limits = $tree->locate('tool_secbackup_settings');
        $this->assertInstanceOf(\admin_settingpage::class, $limits);
        $this->assertSame(['tool/secbackup:manage'], $limits->req_capability);
        $names = [];
        foreach ($limits->settings as $setting) {
            $names[$setting->name] = $setting->get_defaultsetting();
        }
        $this->assertSame(
            ['loadpercore' => '2', 'minmemorymb' => '256', 'maxdbms' => '500', 'minnfsmbps' => '5', 'tempbudgetmb' => '0',
                'bandwidthmbps' => '0', 'tempdir' => ''],
            $names
        );
        $this->assertSame(['tool/secbackup:manage'], $tree->locate('tool_secbackup_diagnostics')->req_capability);
    }

    public function test_the_pages_are_in_the_tree_only_for_users_who_may_configure_the_site(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Backup manager', 'backupmanager', '');
        assign_capability('tool/secbackup:manage', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        try {
            // The tree is built again for this user: settings.php decides what is in it from the user's capabilities.
            $tree = \admin_get_root(true, false);
            $this->assertNull($tree->locate('tool_secbackup'));
            $this->assertNull($tree->locate('tool_secbackup_status'));
            $this->assertNull($tree->locate('tool_secbackup_connect'));
            $this->assertNull($tree->locate('tool_secbackup_diagnostics'));
            $this->assertNull($tree->locate('tool_secbackup_settings'));
        } finally {
            $this->setAdminUser();
            \admin_get_root(true, false);
        }
    }
}
