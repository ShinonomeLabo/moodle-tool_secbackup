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

/**
 * Tests for the settings of the site that a restore needs.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\site_config::class)]
/**
 * Tests for the settings of the site that a restore needs.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\site_config
 */
final class site_config_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_the_database_identity_is_copied(): void {
        global $CFG;
        $snapshot = site_config::snapshot();
        $this->assertSame($CFG->prefix, $snapshot['prefix']);
        $this->assertSame($CFG->dbtype, $snapshot['dbtype']);
        $this->assertSame($CFG->dblibrary, $snapshot['dblibrary']);
        $this->assertSame(['config', 'plugins'], array_keys($snapshot['forced']));
    }

    public function test_secret_settings_are_left_out_and_others_are_kept(): void {
        global $CFG;
        $CFG->config_php_settings = [
            'smtppass' => 'x', 'apikey' => 'y', 'theme' => 'boost', 'dbpass' => 'p', 'dbuser' => 'u', 'dbhost' => 'h',
            'passwordsaltmain' => 's', 'smtpuser' => 'su', 'proxyuser' => 'pu', 'session_redis_auth' => 'a',
            'bind_pw' => 'b', 'private_note' => 'n', 'tlscert' => 'c', 'sentry_dsn' => 'd', 'Mytoken' => 't', 'credx' => 'c',
            'cachejs' => true, 'maxbytes' => 1048576, 'ratio' => 1.5, 'nothing' => null, 'list' => ['a', 2, false],
        ];
        $forced = site_config::snapshot()['forced']['config'];
        $this->assertSame(['cachejs', 'list', 'maxbytes', 'nothing', 'ratio', 'theme'], array_keys($forced));
        $this->assertSame('boost', $forced['theme']);
        $this->assertSame(['a', 2, false], $forced['list']);
        $this->assertNull($forced['nothing']);
    }

    public function test_nested_replica_credentials_are_left_out_at_every_depth(): void {
        global $CFG;
        $CFG->config_php_settings = ['dboptions' => [
            'dbport' => 3306,
            'readonly' => ['instance' => [
                ['dbhost' => 'replica1', 'dbuser' => 'ro', 'dbpass' => 'pw', 'dbport' => 3307],
                ['dbhost' => 'replica2', 'dbuser' => 'ro', 'dbpass' => 'pw'],
            ], 'latency' => 0.5],
        ]];
        $this->assertSame(
            ['dboptions' => ['dbport' => 3306, 'readonly' => ['instance' => [['dbport' => 3307], []], 'latency' => 0.5]]],
            site_config::snapshot()['forced']['config']
        );
    }

    public function test_plugin_settings_are_filtered_by_setting_name(): void {
        global $CFG;
        $CFG->forced_plugin_settings = [
            'auth_ldap' => ['bind_pw' => 'x', 'host_url' => 'ldap.example.test', 'bind_dn' => 'cn=a'],
            'tool_x_secret' => ['s3_secret' => 'y', 'region' => 'ap-northeast-1'],
            'broken' => 'text',
        ];
        $this->assertSame(
            [
                'auth_ldap' => ['bind_dn' => 'cn=a', 'host_url' => 'ldap.example.test'],
                'tool_x_secret' => ['region' => 'ap-northeast-1'],
            ],
            site_config::snapshot()['forced']['plugins']
        );
    }

    public function test_values_that_hold_credentials_or_are_objects_are_left_out(): void {
        global $CFG;
        $handle = fopen('php://memory', 'r');
        $CFG->config_php_settings = [
            'sessionurl' => 'redis://:pw@host:6379', 'cacheurl' => 'https://user:pw@example.test/path',
            'plainurl' => 'https://example.test/path', 'object' => new \stdClass(), 'handle' => $handle,
            'nested' => ['u' => 'redis://:pw@host', 'ok' => 'redis://host', 'o' => new \stdClass()],
        ];
        $forced = site_config::snapshot()['forced']['config'];
        fclose($handle);
        $this->assertSame(['nested', 'plainurl'], array_keys($forced));
        $this->assertSame(['ok' => 'redis://host'], $forced['nested']);
        $this->assertSame('https://example.test/path', $forced['plainurl']);
    }

    public function test_the_snapshot_is_the_same_whatever_the_order_of_the_settings(): void {
        global $CFG;
        $CFG->config_php_settings = ['b' => ['y' => 1, 'x' => 2], 'a' => 1];
        $first = site_config::snapshot();
        $CFG->config_php_settings = ['a' => 1, 'b' => ['x' => 2, 'y' => 1]];
        $second = site_config::snapshot();
        $this->assertSame(json_encode($first), json_encode($second));
        $this->assertSame(['a', 'b'], array_keys($first['forced']['config']));
        $this->assertSame(['x', 'y'], array_keys($first['forced']['config']['b']));
    }

    public function test_missing_settings_give_empty_lists(): void {
        global $CFG;
        unset($CFG->config_php_settings, $CFG->forced_plugin_settings);
        $this->assertSame(['config' => [], 'plugins' => []], site_config::snapshot()['forced']);
    }

    public function test_userinfo_without_a_password_and_a_password_with_a_slash_are_left_out(): void {
        global $CFG;
        $CFG->config_php_settings = [
            'a' => 'redis://secrettoken@host:6379', 'b' => 'redis://user:pa/ss@host', 'c' => 'redis://host:6379/0',
            'd' => 'https://example.test/a@b', 'e' => 'name@example.test',
        ];
        $this->assertSame(['c', 'e'], array_keys(site_config::snapshot()['forced']['config']));
    }

    public function test_a_list_stays_a_list_when_entries_are_left_out(): void {
        global $CFG;
        $CFG->config_php_settings = [
            'hosts' => ['a', 'redis://:u:p@h', 'b', new \stdClass()],
            'nested' => [['x' => 1], ['dbpass' => 'p'], ['y' => 2]],
            'map' => [3 => 'c', 1 => 'redis://:u:p@h', 2 => 'd'],
        ];
        $forced = site_config::snapshot()['forced']['config'];
        $this->assertSame(['a', 'b'], $forced['hosts']);
        $this->assertSame('{"hosts":["a","b"],"map":{"2":"d","3":"c"},"nested":[{"x":1},[],{"y":2}]}', json_encode($forced));
    }

    public function test_names_that_only_contain_auth_are_kept_and_a_secret_name_drops_an_array_whole(): void {
        global $CFG;
        $CFG->config_php_settings = [
            'authtype' => 'ldap', 'authenticate' => true, 'authmethod' => 'x', 'session_redis_auth' => 'p',
            'smtppass' => ['a' => 1, 'b' => ['c' => 2]], 'apikeys' => [1, 2],
        ];
        $this->assertSame(['authenticate', 'authmethod', 'authtype'], array_keys(site_config::snapshot()['forced']['config']));
    }
}
