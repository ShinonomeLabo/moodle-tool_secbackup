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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\temp_admin::class)]
/**
 * Tests for the administrator of a temporary site, secbackup_admin, whom the import makes or makes again.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\temp_admin
 */
final class temp_admin_test extends \advanced_testcase {
    /** @var string The user name. */
    private const USERNAME = 'secbackup_admin';

    /** @var string The password of the tests. */
    private const PASSWORD = 'Kx7#mP2-qR9=vT4@wZ6!';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Whether a user name and a password log in. Only for a login that is expected to work: a login that fails writes to the
     * log of PHP with the browser of a request, which the tests do not have.
     *
     * @param string $password The password.
     * @return bool
     */
    private static function logs_in(string $password): bool {
        global $CFG;
        require_once($CFG->libdir . '/authlib.php');
        $reason = null;
        return authenticate_user_login(self::USERNAME, $password, false, $reason) !== false;
    }

    /**
     * The ids of the site administrators, as the database holds them.
     *
     * @return string[]
     */
    private static function siteadmins(): array {
        global $DB;
        return explode(',', (string) $DB->get_field('config', 'value', ['name' => 'siteadmins']));
    }

    /**
     * Adds a host of the network of Moodle sites.
     *
     * @param string $wwwroot Its address.
     * @return int Its id.
     */
    private static function mnet_host(string $wwwroot): int {
        global $DB;
        return $DB->insert_record('mnet_host', ['wwwroot' => $wwwroot, 'ip_address' => '', 'name' => 'Host', 'public_key' => '']);
    }

    public function test_the_administrator_is_made_when_the_site_does_not_have_one(): void {
        global $DB, $CFG;
        $this->assertFalse($DB->record_exists('user', ['username' => self::USERNAME]));
        $before = time();
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $user = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(self::USERNAME, $user->username);
        $this->assertSame('manual', $user->auth);
        $this->assertEquals(1, $user->confirmed);
        $this->assertEquals(0, $user->suspended);
        $this->assertEquals(0, $user->deleted);
        $this->assertEquals(1, $user->policyagreed);
        $this->assertEquals($CFG->mnet_localhost_id, $user->mnethostid);
        $this->assertSame('Temporary site administrator', $user->firstname);
        $this->assertSame('', $user->lastname);
        $this->assertSame('secbackup_admin@example.invalid', $user->email);
        $this->assertSame('en', $user->lang);
        $empty = ['idnumber', 'phone1', 'phone2', 'institution', 'department', 'address', 'city', 'country', 'theme', 'lastip',
            'secret'];
        foreach ($empty as $column) {
            $this->assertSame('', $user->$column, $column);
        }
        $this->assertGreaterThanOrEqual($before, (int) $user->timecreated);
        $this->assertGreaterThanOrEqual($before, (int) $user->timemodified);
        $this->assertTrue(validate_internal_user_password($user, self::PASSWORD));
        $this->assertStringNotContainsString(self::PASSWORD, $user->password);
        // Read from the table: context_user::instance() would make the context that it looks for.
        $this->assertTrue($DB->record_exists('context', ['contextlevel' => CONTEXT_USER, 'instanceid' => $id]));
        $this->assertTrue(self::logs_in(self::PASSWORD));
    }

    public function test_the_administrator_is_a_site_administrator_once(): void {
        global $DB;
        $admins = self::siteadmins();
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame([...$admins, (string) $id], self::siteadmins());
        $this->assertSame($id, temp_admin::ensure(self::USERNAME, 'another-Password-1'));
        $this->assertSame([...$admins, (string) $id], self::siteadmins());
        $this->assertSame(1, $DB->count_records('user', ['username' => self::USERNAME]));
    }

    public function test_an_administrator_that_the_site_has_is_made_again_with_the_new_password(): void {
        global $DB, $CFG;
        $old = $this->getDataGenerator()->create_user([
            'username' => self::USERNAME, 'password' => 'Old-password-1', 'auth' => 'nologin', 'confirmed' => 0,
            'suspended' => 1, 'policyagreed' => 0, 'firstname' => 'Old', 'lastname' => 'Name', 'email' => 'old@example.com',
            'city' => 'Kyoto',
        ]);
        // A row of the backed-up site, as the import wrote it.
        $DB->update_record('user', ['id' => $old->id, 'deleted' => 1, 'lang' => 'fr', 'timecreated' => 1000,
            'timemodified' => 1000]);
        $before = time();
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame((int) $old->id, $id);
        $user = $DB->get_record('user', ['id' => $id]);
        $this->assertSame('manual', $user->auth);
        $this->assertEquals(1, $user->confirmed);
        $this->assertEquals(0, $user->suspended);
        $this->assertEquals(0, $user->deleted);
        $this->assertEquals(1, $user->policyagreed);
        $this->assertEquals(1000, $user->timecreated);
        $this->assertGreaterThanOrEqual($before, (int) $user->timemodified);
        $this->assertEquals($CFG->mnet_localhost_id, $user->mnethostid);
        $this->assertSame('Temporary site administrator', $user->firstname);
        $this->assertSame('', $user->lastname);
        $this->assertSame('secbackup_admin@example.invalid', $user->email);
        $this->assertSame('Kyoto', $user->city, 'What the administrator does not need stays as it was.');
        $this->assertSame('fr', $user->lang);
        $this->assertTrue(self::logs_in(self::PASSWORD));
        // Not by logging in: a login that fails writes to the log of PHP with the browser of a request.
        $this->assertFalse(validate_internal_user_password($user, 'Old-password-1'));
        $this->assertContains((string) $id, self::siteadmins());
    }

    public function test_an_administrator_that_was_locked_out_can_log_in(): void {
        $user = $this->getDataGenerator()->create_user(['username' => self::USERNAME]);
        set_user_preference('login_lockout', time(), $user);
        set_user_preference('login_failed_count', 20, $user);
        temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertNull(get_user_preferences('login_lockout', null, $user->id));
        $this->assertNull(get_user_preferences('login_failed_count', null, $user->id));
        $this->assertTrue(self::logs_in(self::PASSWORD));
    }

    public function test_a_user_of_another_host_with_the_same_name_is_left_as_it_is(): void {
        global $DB;
        $remote = self::mnet_host('https://remote.example.com');
        $other = $this->getDataGenerator()->create_user(['username' => self::USERNAME, 'mnethostid' => $remote,
            'auth' => 'mnet', 'firstname' => 'Remote']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertNotSame((int) $other->id, $id);
        $this->assertSame('Remote', $DB->get_field('user', 'firstname', ['id' => $other->id]));
        $this->assertSame('mnet', $DB->get_field('user', 'auth', ['id' => $other->id]));
    }

    public function test_the_local_host_is_the_one_of_the_imported_settings(): void {
        global $DB;
        $remote = self::mnet_host('https://local.example.com');
        // The import wrote the id of the local host of the backed-up site under the one that this process loaded.
        $DB->set_field('config', 'value', (string) $remote, ['name' => 'mnet_localhost_id']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertEquals($remote, $DB->get_field('user', 'mnethostid', ['id' => $id]));
    }

    public function test_the_imported_site_administrators_are_kept(): void {
        global $DB;
        // The import wrote the administrators of the backed-up site under the ones that this process loaded.
        $DB->set_field('config', 'value', '2,57', ['name' => 'siteadmins']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame(['2', '57', (string) $id], self::siteadmins());
    }

    public function test_the_site_administrators_are_read_as_moodle_reads_them(): void {
        global $DB;
        $DB->set_field('config', 'value', '2, 57,,0', ['name' => 'siteadmins']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame(['2', '57', (string) $id], self::siteadmins());
        $DB->set_field('config', 'value', '2, ' . $id, ['name' => 'siteadmins']);
        temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame(['2', ' ' . $id], self::siteadmins(), 'A list that has the administrator is left as it is.');
    }

    public function test_a_site_without_administrators_gets_this_one(): void {
        global $DB;
        $DB->delete_records('config', ['name' => 'siteadmins']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame([(string) $id], self::siteadmins());
    }

    public function test_the_name_is_in_the_language_of_the_restored_site(): void {
        global $CFG, $DB;
        // A language pack of Japanese in the language folder of the site, as the data folder of a restored site may have.
        $root = make_request_directory();
        mkdir($root . '/ja');
        file_put_contents($root . '/ja/langconfig.php', "<?php\n\$string['parentlanguage'] = '';\n");
        $old = $CFG->langotherroot;
        $CFG->langotherroot = $root;
        get_string_manager(true);
        try {
            $DB->set_field('config', 'value', 'ja', ['name' => 'lang']);
            $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
            $this->assertSame('一時サイトの管理者', $DB->get_field('user', 'firstname', ['id' => $id]));
            $this->assertSame('ja', $DB->get_field('user', 'lang', ['id' => $id]));
        } finally {
            $CFG->langotherroot = $old;
            get_string_manager(true);
        }
    }

    public function test_a_language_that_is_a_path_is_not_followed(): void {
        global $CFG, $DB;
        // A folder next to the language folder that has what a language pack has: the language "../ja" would find it.
        $root = make_request_directory();
        mkdir($root . '/lang');
        mkdir($root . '/ja');
        file_put_contents($root . '/ja/langconfig.php', "<?php\n\$string['parentlanguage'] = '';\n");
        file_put_contents($root . '/ja/tool_secbackup.php', "<?php\n\$string['restore_admin_fullname'] = 'Outside';\n");
        $old = $CFG->langotherroot;
        $CFG->langotherroot = $root . '/lang';
        get_string_manager(true);
        try {
            $DB->set_field('config', 'value', '../ja', ['name' => 'lang']);
            $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
            $this->assertSame('Temporary site administrator', $DB->get_field('user', 'firstname', ['id' => $id]));
        } finally {
            $CFG->langotherroot = $old;
            get_string_manager(true);
        }
    }

    /**
     * Languages of the restored site that the name is not in.
     *
     * @return array
     */
    public static function language_provider(): array {
        return [
            'a language that the site does not have' => ['xx', 'xx'],
            'a value that is not a language' => ['../ja', 'en'],
            'a code longer than the column of the user holds' => [str_repeat('a', 31), 'en'],
            'no value' => ['', 'en'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('language_provider')]
    /**
     * When the language of the restored site has no strings, the name is in English; when its setting is not the code of a
     * language, the user is in English too.
     *
     * @dataProvider language_provider
     * @param string $lang The language of the restored site.
     * @param string $userlang The language that the user gets.
     */
    public function test_the_name_is_in_english_when_the_language_cannot_be_used(string $lang, string $userlang): void {
        global $DB;
        $DB->set_field('config', 'value', $lang, ['name' => 'lang']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame('Temporary site administrator', $DB->get_field('user', 'firstname', ['id' => $id]));
        $this->assertSame($userlang, $DB->get_field('user', 'lang', ['id' => $id]));
    }

    public function test_a_code_of_a_language_of_30_characters_is_the_language_of_the_user(): void {
        global $DB;
        $DB->set_field('config', 'value', str_repeat('a', 30), ['name' => 'lang']);
        $id = temp_admin::ensure(self::USERNAME, self::PASSWORD);
        $this->assertSame(str_repeat('a', 30), $DB->get_field('user', 'lang', ['id' => $id]));
    }

    /**
     * Names and passwords that are refused.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'another user name' => ['admin', self::PASSWORD],
            'the user name in capitals' => ['SECBACKUP_ADMIN', self::PASSWORD],
            'an empty user name' => ['', self::PASSWORD],
            'an empty password' => [self::USERNAME, ''],
            'a password longer than Moodle takes' => [self::USERNAME, str_repeat('a', MAX_PASSWORD_CHARACTERS + 1)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    /**
     * Another user name than secbackup_admin, which could be a user of the restored site, and a password that Moodle does not
     * take, are refused before anything is written, without the password in the exception.
     *
     * @dataProvider refused_provider
     * @param string $username The user name.
     * @param string $password The password.
     */
    public function test_another_user_or_a_password_that_cannot_be_used_is_refused(string $username, string $password): void {
        global $DB;
        $admin = get_admin();
        $users = $DB->count_records('user');
        $admins = self::siteadmins();
        try {
            temp_admin::ensure($username, $password);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('restore_input_invalid', $e->errorcode);
            if ($password !== '') {
                $this->assertStringNotContainsString($password, (string) $e);
            }
        }
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertSame($admins, self::siteadmins());
        $this->assertSame($admin->password, $DB->get_field('user', 'password', ['id' => $admin->id]));
    }

    public function test_a_password_of_the_most_characters_that_moodle_takes_is_used(): void {
        $password = str_repeat('a', MAX_PASSWORD_CHARACTERS);
        temp_admin::ensure(self::USERNAME, $password);
        $this->assertTrue(self::logs_in($password));
    }

    public function test_check_accepts_the_administrator_and_writes_nothing(): void {
        global $DB;
        temp_admin::check(self::USERNAME, self::PASSWORD);
        $this->assertFalse($DB->record_exists('user', ['username' => self::USERNAME]));
        $this->expectException(plugin_exception::class);
        temp_admin::check('admin', self::PASSWORD);
    }

    public function test_the_password_is_hidden_from_the_traces(): void {
        $parameter = (new \ReflectionMethod(temp_admin::class, 'ensure'))->getParameters()[1];
        $this->assertSame('password', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }
}
