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

namespace tool_secbackup\local\trust;

use tool_secbackup\tests\envelope_builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\trust\developer_settings::class)]
/**
 * Tests for the developer settings read from config.php.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\trust\developer_settings
 */
final class developer_settings_test extends \advanced_testcase {
    public function test_nothing_set_means_production_behaviour(): void {
        $settings = developer_settings::from_value(null);
        $this->assertFalse($settings->is_active());
        $this->assertSame([], $settings->trusted_keys());
        $this->assertFalse($settings->allow_http());
        $this->assertFalse($settings->allow_private_hosts());
        $this->assertFalse($settings->skip_network_checks());
    }

    public function test_reads_config_php(): void {
        global $CFG;
        $this->resetAfterTest();
        // Moodle keeps a copy of what config.php set in $CFG->config_php_settings, before it loads the database settings.
        $CFG->config_php_settings['tool_secbackup_developer'] = [
            'trustedkeys' => [envelope_builder::PUBLIC_KEY],
            'allowhttp' => true,
            'allowprivatehosts' => true,
            'skipnetworkchecks' => true,
        ];
        $settings = developer_settings::current();
        $this->assertTrue($settings->is_active());
        $this->assertSame([base64_decode(envelope_builder::PUBLIC_KEY)], $settings->trusted_keys());
        $this->assertTrue($settings->allow_http());
        $this->assertTrue($settings->allow_private_hosts());
        $this->assertTrue($settings->skip_network_checks());
    }

    public function test_absent_config_is_not_active(): void {
        global $CFG;
        $this->resetAfterTest();
        unset($CFG->config_php_settings['tool_secbackup_developer']);
        $this->assertFalse(developer_settings::current()->is_active());
    }

    public function test_a_value_that_only_cfg_holds_is_ignored(): void {
        global $CFG;
        $this->resetAfterTest();
        unset($CFG->config_php_settings['tool_secbackup_developer']);
        // The way Moodle puts a setting of its database, or of the cache in front of it, into $CFG.
        $CFG->tool_secbackup_developer = [
            'trustedkeys' => [envelope_builder::PUBLIC_KEY],
            'allowhttp' => true,
            'allowprivatehosts' => true,
            'skipnetworkchecks' => true,
        ];
        $settings = developer_settings::current();
        $this->assertFalse($settings->is_active());
        $this->assertSame([], $settings->trusted_keys());
        $this->assertFalse($settings->allow_http());
        $this->assertFalse($settings->allow_private_hosts());
        $this->assertFalse($settings->skip_network_checks());
    }

    public function test_the_copy_of_config_php_wins_over_a_value_in_cfg(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->config_php_settings['tool_secbackup_developer'] = ['allowhttp' => true];
        $CFG->tool_secbackup_developer = ['allowprivatehosts' => true];
        $settings = developer_settings::current();
        $this->assertTrue($settings->allow_http());
        $this->assertFalse($settings->allow_private_hosts());
    }

    public function test_a_row_of_the_config_table_does_not_break_the_settings(): void {
        global $CFG;
        $this->resetAfterTest();
        unset($CFG->config_php_settings['tool_secbackup_developer']);
        // A row of that name in the config table reaches $CFG as a text. It used to fail with a message about config.php.
        set_config('tool_secbackup_developer', 'yes');
        $this->assertSame('yes', $CFG->tool_secbackup_developer);
        $this->assertFalse(developer_settings::current()->is_active());
    }

    public function test_an_array_in_the_config_cache_does_not_add_a_trusted_key(): void {
        global $CFG;
        $this->resetAfterTest();
        unset($CFG->config_php_settings['tool_secbackup_developer']);
        // Whoever can write to the cache store can put an array there; initialise_cfg() copies the cache into $CFG.
        $planted = ['trustedkeys' => [envelope_builder::PUBLIC_KEY]];
        get_config('core');
        $cache = \cache::make('core', 'config');
        $cached = $cache->get('core');
        $this->assertIsArray($cached);
        $cached['tool_secbackup_developer'] = $planted;
        $cache->set('core', $cached);
        initialise_cfg();
        $this->assertSame($planted, $CFG->tool_secbackup_developer);
        $this->assertSame([], developer_settings::current()->trusted_keys());
        $cache->delete('core');
    }

    /**
     * Each switch on its own.
     *
     * @return array
     */
    public static function switch_provider(): array {
        return [
            'allowhttp' => ['allowhttp', 'allow_http'],
            'allowprivatehosts' => ['allowprivatehosts', 'allow_private_hosts'],
            'skipnetworkchecks' => ['skipnetworkchecks', 'skip_network_checks'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('switch_provider')]
    /**
     * Turning on one switch makes the settings active and changes no other switch.
     *
     * @dataProvider switch_provider
     * @param string $name Setting name.
     * @param string $method Getter.
     */
    public function test_one_switch_is_enough_to_be_active(string $name, string $method): void {
        $settings = developer_settings::from_value([$name => true]);
        $this->assertTrue($settings->is_active());
        foreach (self::switch_provider() as [$othername, $othermethod]) {
            $this->assertSame($othername === $name, $settings->$othermethod(), $othermethod);
        }
    }

    public function test_trusted_keys_alone_are_active(): void {
        $this->assertTrue(developer_settings::from_value(['trustedkeys' => [envelope_builder::PUBLIC_KEY]])->is_active());
    }

    public function test_explicit_false_values_are_not_active(): void {
        $settings = developer_settings::from_value(['trustedkeys' => [], 'allowhttp' => false]);
        $this->assertFalse($settings->is_active());
    }

    public function test_an_empty_array_is_not_active(): void {
        $settings = developer_settings::from_value([]);
        $this->assertFalse($settings->is_active());
        $this->assertSame([], $settings->trusted_keys());
    }

    public function test_every_trusted_key_is_kept_in_order(): void {
        $second = new envelope_builder(str_repeat('07', 32));
        $settings = developer_settings::from_value(
            ['trustedkeys' => [envelope_builder::PUBLIC_KEY, $second->public_key_base64()]]
        );
        $this->assertSame(
            [base64_decode(envelope_builder::PUBLIC_KEY), base64_decode($second->public_key_base64())],
            $settings->trusted_keys()
        );
    }

    /**
     * Values that must be refused, with a part of the reason.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'not an array' => ['yes', 'must be an array'],
            'misspelt name' => [['trustedkey' => []], 'Unknown setting: trustedkey'],
            'keys not an array' => [['trustedkeys' => 'abc'], 'trustedkeys must be an array'],
            'key not base64' => [['trustedkeys' => ['***']], 'standard base64'],
            'key too short' => [['trustedkeys' => [base64_encode('short')]], 'standard base64'],
            'key not text' => [['trustedkeys' => [123]], 'standard base64'],
            'switch as text' => [['allowhttp' => 'yes'], 'allowhttp must be true or false'],
            'false instead of an array' => [false, 'must be an array'],
            'empty text instead of an array' => ['', 'must be an array'],
            'several misspelt names' => [['allowhttp' => true, 'bogus' => 1, 'other' => 2], 'Unknown setting: bogus, other.'],
            'key with a stray character' => [
                ['trustedkeys' => [substr_replace(envelope_builder::PUBLIC_KEY, '!', 10, 0)]],
                'standard base64',
            ],
            'key of 31 bytes' => [['trustedkeys' => [base64_encode(str_repeat('a', 31))]], 'standard base64'],
            'key of 33 bytes' => [['trustedkeys' => [base64_encode(str_repeat('a', 33))]], 'standard base64'],
            'second key not valid' => [['trustedkeys' => [envelope_builder::PUBLIC_KEY, 'x']], 'standard base64'],
            'key is a list' => [['trustedkeys' => [['a']]], 'standard base64'],
            'key is nothing' => [['trustedkeys' => [null]], 'standard base64'],
            'switch as number' => [['skipnetworkchecks' => 1], 'skipnetworkchecks must be true or false'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * A value that is not valid is refused with the error developersettings_invalid and a reason.
     *
     * @dataProvider invalid_provider
     * @param mixed $value Value of $CFG->tool_secbackup_developer.
     * @param string $reason Part of the reason in debuginfo.
     */
    public function test_invalid_values_are_refused(mixed $value, string $reason): void {
        try {
            developer_settings::from_value($value);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('developersettings_invalid', $e->errorcode);
            $this->assertStringContainsString($reason, $e->debuginfo);
        }
    }

    public function test_the_reason_does_not_repeat_a_key(): void {
        // A public key is not secret, but the reason (shown with debugging on) still has no key value in it.
        try {
            developer_settings::from_value(['trustedkeys' => [envelope_builder::PUBLIC_KEY . '!']]);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('developersettings_invalid', $e->errorcode);
            $this->assertNull($e->a);
            $this->assertStringNotContainsString(envelope_builder::PUBLIC_KEY, $e->debuginfo);
            $this->assertStringNotContainsString(envelope_builder::PUBLIC_KEY, $e->getMessage());
        }
    }

    public function test_the_error_code_is_a_language_string(): void {
        $this->assertTrue(get_string_manager()->string_exists('developersettings_invalid', 'tool_secbackup'));
    }
}
