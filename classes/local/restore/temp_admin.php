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

/**
 * The administrator of a temporary site, secbackup_admin, with the password that the restore host gives.
 *
 * The user is made when the restored site does not have one of the name on its own host, and made again when it has: a
 * manual account that is confirmed, not suspended nor deleted, has agreed to the policy, has the name of the language string
 * restore_admin_fullname in the language of the restored site, and an address in the domain example.invalid. The password
 * is hashed with hash_internal_user_password(), and a lockout of the user is lifted. A new user has the language of the site
 * and every text column that cannot be null empty, and gets its context. The user is added to the site administrators once.
 *
 * Only secbackup_admin: another name could be a user of the backed-up site, whom this would take over. The user is written
 * to the table directly, so that the observers of the events of the additional plugins of the site do not run. The values are
 * read from the database, not from $CFG: the import replaced the settings under the ones that this process loaded. The
 * password goes nowhere but into the hash: not into an exception, an event or a log.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class temp_admin {
    /** @var string The user name of the administrator. */
    public const USERNAME = 'secbackup_admin';

    /** @var string The address of the administrator, which no mail reaches. */
    private const EMAIL = 'secbackup_admin@example.invalid';

    /** @var string Pattern of the code of a language, which the column user.lang holds. */
    private const LANG = '/\A[a-z][a-z0-9_]{0,29}\z/';

    /**
     * @var string[] The text columns of the user that cannot be null and have no default in install.xml: empty for a new user,
     *     not left to the default that the database gives them.
     */
    private const EMPTY_COLUMNS = ['idnumber', 'phone1', 'phone2', 'institution', 'department', 'address', 'city', 'country',
        'theme', 'lastip', 'secret'];

    /**
     * Checks the user name and the password, and writes nothing.
     *
     * The type of $password is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()); it is plain "string" for PHP. The same holds for ensure().
     *
     * @param string $username The user name.
     * @param #[|\SensitiveParameter|]|string $password The password.
     * @throws plugin_exception With restore_input_invalid when the user name is not secbackup_admin, or the password is empty
     *     or longer than Moodle takes (MAX_PASSWORD_CHARACTERS bytes). The exception holds neither.
     */
    public static function check(string $username, #[\SensitiveParameter] string $password): void {
        if ($username !== self::USERNAME || $password === '' || exceeds_password_length($password)) {
            throw new plugin_exception('restore_input_invalid', null, 'admin');
        }
    }

    /**
     * Makes the administrator, or makes it again with the password.
     *
     * @param string $username The user name: secbackup_admin.
     * @param #[|\SensitiveParameter|]|string $password The password.
     * @return int The id of the user.
     * @throws plugin_exception With restore_input_invalid when check() refuses the user name or the password, before anything
     *     is written.
     */
    public static function ensure(string $username, #[\SensitiveParameter] string $password): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/authlib.php');
        self::check($username, $password);
        $host = (int) self::database_setting('mnet_localhost_id', (string) $CFG->mnet_localhost_id);
        $lang = self::lang();
        $now = time();
        $fields = [
            'auth' => 'manual',
            'confirmed' => 1,
            'suspended' => 0,
            'deleted' => 0,
            'policyagreed' => 1,
            'mnethostid' => $host,
            'password' => hash_internal_user_password($password),
            'firstname' => get_string_manager()->get_string('restore_admin_fullname', 'tool_secbackup', null, $lang),
            'lastname' => '',
            'email' => self::EMAIL,
            'timemodified' => $now,
        ];
        $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $host], 'id');
        if ($user === false) {
            $new = ['username' => $username, 'lang' => $lang, 'timecreated' => $now] + array_fill_keys(self::EMPTY_COLUMNS, '');
            $id = (int) $DB->insert_record('user', $new + $fields);
        } else {
            $id = (int) $user->id;
            $DB->update_record('user', ['id' => $id] + $fields);
            login_unlock_account($user);
        }
        \context_user::instance($id);
        // As Moodle reads the list: ids, each a number, none 0.
        $admins = array_filter(array_map('intval', explode(',', self::database_setting('siteadmins', ''))));
        if (!in_array($id, $admins, true)) {
            set_config('siteadmins', implode(',', [...$admins, $id]));
        }
        return $id;
    }

    /**
     * The language of the restored site, or English when its setting is not the code of a language.
     *
     * @return string
     */
    private static function lang(): string {
        $lang = self::database_setting('lang', 'en');
        return preg_match(self::LANG, $lang) === 1 ? $lang : 'en';
    }

    /**
     * A setting of core as the database holds it.
     *
     * @param string $name The setting.
     * @param string $default The value when the database does not have it.
     * @return string
     */
    private static function database_setting(string $name, string $default): string {
        global $DB;
        $value = $DB->get_field('config', 'value', ['name' => $name]);
        return $value === false ? $default : $value;
    }
}
