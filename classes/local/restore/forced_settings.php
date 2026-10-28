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
 * Writes the settings that config.php of the backed-up site forced to the settings in the database of the restored site.
 *
 * The header of the generation holds them as config.forced: config, the settings of core by name, and plugins, the settings of
 * each plugin by name (site_config of the backup took them, without the secrets). The config.php of the restored site is the
 * one of the restore host, so without this a forced setting would fall back to the value in the database.
 *
 * A setting that describes the server of the backed-up site is not written: a name of NAMES, or a name that starts with one of
 * PREFIXES, in any letters (MySQL compares the names of settings without the case), also among the settings of a plugin.
 * Neither is a value that a setting cannot hold (an array, or null, which set_config() takes for a deletion), a name that is
 * empty or too long for its table, the settings of this plugin, which the restore does not bring back, and the settings of a
 * name that is not one of a plugin (set_config() writes the settings of an empty name, or of "0", to core).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class forced_settings {
    /** @var string[] The names of the settings that describe the server, in lower case. */
    private const NAMES = ['wwwroot', 'dataroot', 'dirroot', 'root', 'libdir', 'admin', 'prefix', 'directorypermissions',
        'filepermissions', 'umaskpermissions', 'sslproxy', 'reverseproxy', 'tempdir', 'cachedir', 'localcachedir',
        'backuptempdir', 'localrequestdir', 'sharedcachedir', 'alternative_file_system_class', 'noemailever',
        'divertallemailsto', 'routerconfigured', 'preventexecpath', 'xsendfile', 'xsendfilealiases', 'alternateloginurl',
        'secretdataroot'];

    /** @var string[] The starts of the names of the settings that describe the server, in lower case. */
    private const PREFIXES = ['db', 'session', 'pathto', 'smtp', 'proxy', 'mail'];

    /** @var string The plugin whose settings are not restored. */
    private const COMPONENT = 'tool_secbackup';

    /** @var string Pattern of the name of a plugin. */
    private const PLUGIN = '/\A[a-z][a-z0-9_]{0,99}\z/';

    /** @var int The most bytes of the name of a setting of core (the column config.name). */
    private const CORE_NAME = 255;

    /** @var int The most bytes of the name of a setting of a plugin (the column config_plugins.name). */
    private const PLUGIN_NAME = 100;

    /**
     * Checks that the forced settings are in their form, and writes nothing.
     *
     * @param array $forced The member config.forced of the header.
     * @throws plugin_exception With restore_input_invalid when config or plugins is not an object, or the settings of a plugin
     *     are not one.
     */
    public static function check(array $forced): void {
        $plugins = $forced['plugins'] ?? null;
        if (!is_array($forced['config'] ?? null) || !is_array($plugins) || array_filter($plugins, 'is_array') !== $plugins) {
            throw new plugin_exception('restore_input_invalid', null, 'forced');
        }
    }

    /**
     * Writes the forced settings, one by one, those of a plugin with set_config($name, $value, $plugin).
     *
     * @param array $forced The member config.forced of the header.
     * @return string[] The settings written, in order: the name of each setting of core, and plugin/name for each setting of a
     *     plugin.
     * @throws plugin_exception With restore_input_invalid when the forced settings are not in their form, before anything is
     *     written.
     */
    public static function apply(array $forced): array {
        self::check($forced);
        $written = [];
        foreach ($forced['config'] as $name => $value) {
            if (self::writable((string) $name, $value, self::CORE_NAME)) {
                set_config((string) $name, self::text($value));
                $written[] = (string) $name;
            }
        }
        foreach ($forced['plugins'] as $plugin => $settings) {
            if ($plugin === self::COMPONENT || preg_match(self::PLUGIN, (string) $plugin) !== 1) {
                continue;
            }
            foreach ($settings as $name => $value) {
                if (self::writable((string) $name, $value, self::PLUGIN_NAME)) {
                    set_config((string) $name, self::text($value), $plugin);
                    $written[] = $plugin . '/' . $name;
                }
            }
        }
        return $written;
    }

    /**
     * Whether a setting is written.
     *
     * @param string $name The name.
     * @param mixed $value The value.
     * @param int $longest The most bytes of the name.
     * @return bool
     */
    private static function writable(string $name, mixed $value, int $longest): bool {
        if ($name === '' || strlen($name) > $longest || !is_scalar($value)) {
            return false;
        }
        $lower = strtolower($name);
        if (in_array($lower, self::NAMES, true)) {
            return false;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The text of a value, as the database keeps it: a true value as 1 and a false one as 0.
     *
     * @param string|int|float|bool $value The value.
     * @return string
     */
    private static function text(string|int|float|bool $value): string {
        return is_bool($value) ? (string) (int) $value : (string) $value;
    }
}
