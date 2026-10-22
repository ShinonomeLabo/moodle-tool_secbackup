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

/**
 * Takes the settings of the site that a restore needs, without any secret.
 *
 * The snapshot goes into the header of the encrypted list of the generation. It holds the table prefix, the database driver,
 * and the settings that config.php forces ($CFG->config_php_settings and $CFG->forced_plugin_settings). A setting is left out
 * by its name, at any depth of nested arrays, and by its value when the value is an object, a resource or a URL with a
 * password in it. The keys are sorted, so that the same site gives the same snapshot.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_config {
    /** @var string Names that look like a secret. */
    private const SECRET_NAME = '/pass|secret|key|token|salt|cred|pw|private|cert|dsn|auth\z/i';

    /** @var string[] Names that are left out whatever the pattern says. */
    private const NEVER = ['dbpass', 'dbuser', 'dbhost', 'passwordsaltmain', 'smtpuser', 'proxyuser'];

    /** @var string A URL with an "@" after its scheme: user information, even without a colon or with a "/" in it. */
    private const URL_CREDENTIALS = '~[a-z][a-z0-9+.-]*://.*@~is';

    /**
     * Gets the snapshot.
     *
     * @return array ['prefix', 'dbtype', 'dblibrary', 'forced' => ['config' => array, 'plugins' => array]].
     */
    public static function snapshot(): array {
        global $CFG;
        $config = self::clean(is_array($CFG->config_php_settings ?? null) ? $CFG->config_php_settings : []);
        $plugins = [];
        foreach (is_array($CFG->forced_plugin_settings ?? null) ? $CFG->forced_plugin_settings : [] as $component => $settings) {
            if (is_array($settings)) {
                $plugins[$component] = self::clean($settings);
            }
        }
        ksort($plugins);
        return [
            'prefix' => (string) $CFG->prefix,
            'dbtype' => (string) $CFG->dbtype,
            'dblibrary' => (string) $CFG->dblibrary,
            'forced' => ['config' => $config, 'plugins' => $plugins],
        ];
    }

    /**
     * Keeps the settings that are safe to store, at every depth, in key order.
     *
     * @param array $settings Names and values.
     * @return array
     */
    private static function clean(array $settings): array {
        $kept = [];
        foreach ($settings as $name => $value) {
            if (is_string($name) && self::is_secret_name($name)) {
                continue;
            }
            if (is_array($value)) {
                $kept[$name] = self::clean($value);
                if (array_is_list($value)) {
                    $kept[$name] = array_values($kept[$name]);
                }
            } else if (is_string($value) && preg_match(self::URL_CREDENTIALS, $value) === 1) {
                continue;
            } else if (is_scalar($value) || $value === null) {
                $kept[$name] = $value;
            }
        }
        ksort($kept);
        return $kept;
    }

    /**
     * Tells whether a setting must not be stored, by its name.
     *
     * @param string $name The name of the setting.
     * @return bool
     */
    private static function is_secret_name(string $name): bool {
        return in_array(strtolower($name), self::NEVER, true) || preg_match(self::SECRET_NAME, $name) === 1;
    }
}
