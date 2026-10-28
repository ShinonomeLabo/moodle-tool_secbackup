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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\forced_settings::class)]
/**
 * Tests for the settings that config.php of the backed-up site forced, which the import writes to the settings in the
 * database of the restored site, all but the ones that describe the server.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\forced_settings
 */
final class forced_settings_test extends \advanced_testcase {
    /** @var string[] The names that are never written. */
    private const EXCLUDED = ['wwwroot', 'dataroot', 'dirroot', 'root', 'libdir', 'admin', 'prefix', 'directorypermissions',
        'filepermissions', 'umaskpermissions', 'sslproxy', 'reverseproxy', 'tempdir', 'cachedir', 'localcachedir',
        'backuptempdir', 'localrequestdir', 'sharedcachedir', 'alternative_file_system_class', 'noemailever',
        'divertallemailsto', 'routerconfigured', 'preventexecpath', 'xsendfile', 'xsendfilealiases', 'alternateloginurl',
        'secretdataroot', 'themedir', 'customscripts', 'langotherroot', 'langlocalroot', 'alternative_component_cache',
        'altcacheconfigpath', 'aspellpath', 'behat_dataroot', 'behat_faildump_path', 'blockmanagerclassfile',
        'moodlepageclassfile', 'file_lock_root', 'filedir', 'phpunit_dataroot'];

    /** @var string[] The starts of the names that are never written. */
    private const PREFIXES = ['db', 'session', 'pathto', 'smtp', 'proxy', 'mail', 'behat_', 'phpunit_'];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The value of a core setting in the database, or false for none.
     *
     * @param string $name The name.
     * @return string|false
     */
    private static function core(string $name): string|false {
        global $DB;
        return $DB->get_field('config', 'value', ['name' => $name]);
    }

    /**
     * The value of a setting of a plugin in the database, or false for none.
     *
     * @param string $plugin The plugin.
     * @param string $name The name.
     * @return string|false
     */
    private static function plugin(string $plugin, string $name): string|false {
        global $DB;
        return $DB->get_field('config_plugins', 'value', ['plugin' => $plugin, 'name' => $name]);
    }

    public function test_a_forced_setting_goes_in_and_the_ones_of_the_server_do_not(): void {
        $config = [
            'theme' => 'classic',
            'wwwroot' => 'https://lms.example.com',
            'dbhost' => 'db.example.com',
            'session_handler_class' => '\core\session\redis',
            'smtphosts' => 'mail.example.com',
            'proxyhost' => 'proxy.example.com',
            'alternateloginurl' => 'https://sso.example.com/',
        ];
        $before = [];
        foreach (array_keys($config) as $name) {
            $before[$name] = self::core($name);
        }
        $written = forced_settings::apply(['config' => $config, 'plugins' => []]);
        $this->assertSame(['theme'], $written);
        $this->assertSame('classic', self::core('theme'));
        foreach ($config as $name => $value) {
            if ($name !== 'theme') {
                $this->assertSame($before[$name], self::core($name), $name);
                $this->assertNotSame($value, self::core($name), $name);
            }
        }
    }

    public function test_the_settings_of_a_plugin_go_to_the_settings_of_the_plugins_one_by_one(): void {
        global $DB;
        $before = $DB->count_records('config');
        $written = forced_settings::apply(['config' => [], 'plugins' => [
            'mod_attendance' => ['enabled' => '1', 'maxsessions' => 30],
            'auth_secbkextra' => ['field_map' => 'idnumber'],
        ]]);
        $this->assertSame(['mod_attendance/enabled', 'mod_attendance/maxsessions', 'auth_secbkextra/field_map'], $written);
        $this->assertSame('1', self::plugin('mod_attendance', 'enabled'));
        $this->assertSame('30', self::plugin('mod_attendance', 'maxsessions'));
        $this->assertSame('idnumber', self::plugin('auth_secbkextra', 'field_map'));
        $this->assertSame($before, $DB->count_records('config'));
    }

    public function test_a_setting_that_the_site_has_takes_the_forced_value(): void {
        set_config('theme', 'boost');
        set_config('enabled', '0', 'mod_attendance');
        forced_settings::apply(['config' => ['theme' => 'classic'], 'plugins' => ['mod_attendance' => ['enabled' => '1']]]);
        $this->assertSame('classic', self::core('theme'));
        $this->assertSame('1', self::plugin('mod_attendance', 'enabled'));
    }

    /**
     * Names that are not written, in the settings of core and of a plugin.
     *
     * @return array
     */
    public static function excluded_provider(): array {
        $names = [];
        foreach (self::EXCLUDED as $name) {
            $names[$name] = [$name];
        }
        foreach (self::PREFIXES as $prefix) {
            $names[$prefix] = [$prefix];
            $names[$prefix . ' and more'] = [$prefix . 'something'];
        }
        foreach (['behat_prefix', 'behat_wwwroot', 'phpunit_prefix', 'phpunit_dataroot'] as $name) {
            $names['a setting of the tests: ' . $name] = [$name];
        }
        $names['a name in capitals'] = ['WWWROOT'];
        $names['a start in capitals'] = ['DBHost'];
        $names['a setting of the tests in capitals'] = ['BEHAT_PREFIX'];
        $names['a name in mixed letters'] = ['AlternateLoginUrl'];
        return $names;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excluded_provider')]
    /**
     * A name of the list, or one that starts with a start of the list, in any letters, is not written, in the settings of
     * core nor in the ones of a plugin.
     *
     * @dataProvider excluded_provider
     * @param string $name The name.
     */
    public function test_a_name_of_the_server_is_not_written(string $name): void {
        global $DB;
        $config = $DB->get_records_menu('config', null, 'name', 'name, value');
        $plugins = $DB->count_records('config_plugins');
        $written = forced_settings::apply(['config' => [$name => 'x'], 'plugins' => ['mod_attendance' => [$name => 'x']]]);
        $this->assertSame([], $written);
        $this->assertSame($config, $DB->get_records_menu('config', null, 'name', 'name, value'));
        $this->assertSame($plugins, $DB->count_records('config_plugins'));
    }

    /**
     * Names that are not an ASCII identifier: MySQL and MariaDB can take some of them for another name.
     *
     * @return array
     */
    public static function not_an_identifier_provider(): array {
        return [
            'a blank at the end' => ['smtphosts '],
            'a blank at the start' => [' theme'],
            'an accent' => ['proxyhóst'],
            'full-width letters' => ['ｗｗｗｒｏｏｔ'],
            'a hyphen' => ['the-name'],
            'a dot' => ['a.b'],
            'a slash' => ['a/b'],
            'a line break' => ["theme\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('not_an_identifier_provider')]
    /**
     * A name that is not an ASCII identifier is not written, in the settings of core nor in the ones of a plugin.
     *
     * @dataProvider not_an_identifier_provider
     * @param string $name The name.
     */
    public function test_a_name_that_is_not_an_ascii_identifier_is_not_written(string $name): void {
        global $DB;
        $config = $DB->get_records_menu('config', null, 'name', 'name, value');
        $plugins = $DB->get_records_menu('config_plugins', null, 'id', 'id, value');
        $written = forced_settings::apply(['config' => [$name => 'x'], 'plugins' => ['mod_attendance' => [$name => 'x']]]);
        $this->assertSame([], $written);
        $this->assertSame($config, $DB->get_records_menu('config', null, 'name', 'name, value'));
        $this->assertSame($plugins, $DB->get_records_menu('config_plugins', null, 'id', 'id, value'));
    }

    public function test_a_name_of_letters_digits_and_underscores_in_any_case_is_written(): void {
        $this->assertSame(['Secbk_Name_9', 'mod_attendance/Secbk_Name_9'], forced_settings::apply([
            'config' => ['Secbk_Name_9' => 'v'],
            'plugins' => ['mod_attendance' => ['Secbk_Name_9' => 'w']],
        ]));
    }

    /**
     * Names that only look like the names of the server.
     *
     * @return array
     */
    public static function kept_name_provider(): array {
        return [
            'the start of the list later in the name' => ['enabledb'],
            'a name of the list later in the name' => ['mywwwroot'],
            'a name of the list with more after it' => ['wwwrootold'],
            'a start that is one letter short' => ['d'],
            'a name that the site uses' => ['forcelogin'],
            'the start of a setting of the tests without its underscore' => ['behatx'],
            'the name of the tests alone' => ['behat'],
            'the start of a setting of the tests later in the name' => ['myphpunit_x'],
            'the other name of the tests alone' => ['phpunit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kept_name_provider')]
    /**
     * Only the names of the list and the names that start with a start of the list are left out, in the settings of core and
     * of a plugin.
     *
     * @dataProvider kept_name_provider
     * @param string $name The name.
     */
    public function test_a_name_that_only_looks_like_one_of_the_server_is_written(string $name): void {
        $this->assertSame(
            [$name, 'mod_attendance/' . $name],
            forced_settings::apply(['config' => [$name => 'v'], 'plugins' => ['mod_attendance' => [$name => 'w']]])
        );
        $this->assertSame('v', self::core($name));
        $this->assertSame('w', self::plugin('mod_attendance', $name));
    }

    public function test_the_values_are_written_as_the_database_keeps_them(): void {
        forced_settings::apply(['config' => ['secbka' => true, 'secbkb' => false, 'secbkc' => 5, 'secbkd' => 1.5,
            'secbke' => ''], 'plugins' => []]);
        $this->assertSame('1', self::core('secbka'));
        $this->assertSame('0', self::core('secbkb'));
        $this->assertSame('5', self::core('secbkc'));
        $this->assertSame('1.5', self::core('secbkd'));
        $this->assertSame('', self::core('secbke'));
    }

    public function test_a_value_that_a_setting_cannot_hold_is_left_out(): void {
        set_config('secbknull', 'kept');
        $written = forced_settings::apply([
            'config' => ['secbklist' => ['a', 'b'], 'secbkmap' => ['a' => 1], 'secbknull' => null, 'secbkok' => 'v'],
            'plugins' => ['mod_attendance' => ['nested' => ['a' => 1], 'none' => null, 'ok' => 'w']],
        ]);
        $this->assertSame(['secbkok', 'mod_attendance/ok'], $written);
        $this->assertFalse(self::core('secbklist'));
        $this->assertFalse(self::core('secbkmap'));
        $this->assertSame('kept', self::core('secbknull'));
        $this->assertFalse(self::plugin('mod_attendance', 'nested'));
        $this->assertFalse(self::plugin('mod_attendance', 'none'));
    }

    public function test_a_name_that_the_table_cannot_hold_is_left_out(): void {
        $core = str_repeat('a', 255);
        $plugin = str_repeat('b', 100);
        $written = forced_settings::apply([
            'config' => [$core => '1', $core . 'a' => '2', '' => '3'],
            'plugins' => ['mod_attendance' => [$plugin => '4', $plugin . 'b' => '5', '' => '6']],
        ]);
        $this->assertSame([$core, 'mod_attendance/' . $plugin], $written);
        $this->assertSame('1', self::core($core));
        $this->assertSame('4', self::plugin('mod_attendance', $plugin));
    }

    public function test_a_name_that_json_made_a_number_is_written_as_its_text(): void {
        $forced = json_decode('{"config":{"7":"seven"},"plugins":{"mod_attendance":{"8":"eight"}}}', true);
        $this->assertSame(['7', 'mod_attendance/8'], forced_settings::apply($forced));
        $this->assertSame('seven', self::core('7'));
        $this->assertSame('eight', self::plugin('mod_attendance', '8'));
    }

    /**
     * Names of plugins whose settings are not written.
     *
     * @return array
     */
    public static function component_provider(): array {
        return [
            'this plugin, whose settings are not restored' => ['tool_secbackup'],
            'an empty name, which set_config() takes for core' => [''],
            'zero, which set_config() takes for core' => ['0'],
            'a name in capitals' => ['Mod_attendance'],
            'a name with a slash' => ['mod/attendance'],
            'a name that starts with a digit' => ['1mod'],
            'a name that is too long' => ['local_' . str_repeat('a', 95)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('component_provider')]
    /**
     * The settings of this plugin, and of a name that is not one of a plugin, are not written anywhere.
     *
     * @dataProvider component_provider
     * @param string $component The name of the plugin.
     */
    public function test_the_settings_of_a_name_that_is_not_one_of_a_plugin_to_restore_are_left_out(string $component): void {
        global $DB;
        $config = $DB->get_records_menu('config', null, 'name', 'name, value');
        $plugins = $DB->get_records_menu('config_plugins', null, 'id', 'id, value');
        $forced = json_decode(json_encode(['config' => [], 'plugins' => [$component => ['theme' => 'classic']]]), true);
        $this->assertSame([], forced_settings::apply($forced));
        $this->assertSame($config, $DB->get_records_menu('config', null, 'name', 'name, value'));
        $this->assertSame($plugins, $DB->get_records_menu('config_plugins', null, 'id', 'id, value'));
    }

    public function test_a_plugin_name_of_100_characters_is_written(): void {
        $component = 'local_' . str_repeat('a', 94);
        $this->assertSame([$component . '/x'], forced_settings::apply(['config' => [], 'plugins' => [$component => ['x' => 'y']]]));
        $this->assertSame('y', self::plugin($component, 'x'));
    }

    /**
     * Forced settings that are not in the form of the header.
     *
     * @return array
     */
    public static function form_provider(): array {
        $core = ['theme' => 'classic'];
        $plugin = ['mod_attendance' => ['enabled' => '1']];
        return [
            'nothing' => [[]],
            'no settings of the plugins' => [['config' => $core]],
            'no settings of core' => [['plugins' => $plugin]],
            'settings of core that are text' => [['config' => 'theme', 'plugins' => $plugin]],
            'settings of the plugins that are text' => [['config' => $core, 'plugins' => 'x']],
            'settings of a plugin that are text' => [['config' => $core, 'plugins' => $plugin + ['mod_secbkb' => 'enabled']]],
            'settings of a plugin that are null' => [['config' => $core, 'plugins' => $plugin + ['mod_secbkb' => null]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('form_provider')]
    /**
     * Forced settings that are not in their form are refused before anything is written.
     *
     * @dataProvider form_provider
     * @param array $forced The forced settings.
     */
    public function test_forced_settings_not_in_their_form_are_refused_before_anything_is_written(array $forced): void {
        global $DB;
        $theme = self::core('theme');
        try {
            forced_settings::apply($forced);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_input_invalid', $e->errorcode);
        }
        $this->assertSame($theme, self::core('theme'));
        $this->assertFalse($DB->record_exists('config_plugins', ['plugin' => 'mod_attendance']));
    }

    public function test_check_accepts_forced_settings_in_their_form_and_writes_nothing(): void {
        $theme = self::core('theme');
        forced_settings::check(['config' => ['theme' => 'classic'], 'plugins' => ['mod_attendance' => []]]);
        $this->assertSame($theme, self::core('theme'));
        $this->expectException(plugin_exception::class);
        forced_settings::check(['config' => []]);
    }
}
