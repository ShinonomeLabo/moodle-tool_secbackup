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
 * A text setting of the limits that accepts only what limits::current() will use.
 *
 * Without this, the page would save a value that is then replaced by the default without a word. It holds either a number
 * (with its least value, whole numbers only or with decimals) or the folder of the temporary files.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class limit_setting extends \admin_setting_configtext {
    /** @var float|null Least value of a number; null when the setting is a folder. */
    private ?float $min;

    /** @var bool Whether a number must be a whole number. */
    private bool $integer;

    /**
     * Constructor.
     *
     * The name and the description on the page are the strings setting_<key> and setting_<key>_desc.
     *
     * @param string $key Name of the setting in the plugin's settings, such as maxdbms.
     * @param string $default Default.
     * @param float|null $min Least value of a number, or null for the folder of the temporary files.
     * @param bool $integer Whether a number must be a whole number.
     */
    public function __construct(string $key, string $default, ?float $min, bool $integer) {
        parent::__construct(
            'tool_secbackup/' . $key,
            new \lang_string('setting_' . $key, 'tool_secbackup'),
            new \lang_string('setting_' . $key . '_desc', 'tool_secbackup'),
            $default,
            PARAM_RAW_TRIMMED
        );
        $this->min = $min;
        $this->integer = $integer;
    }

    /**
     * Checks a value like limits::current() does when it reads it.
     *
     * @param string $data The value.
     * @return true|string True, or the error to show.
     */
    public function validate($data) {
        $general = parent::validate($data);
        if ($general !== true) {
            return $general;
        }
        $data = (string) $data;
        if ($this->min !== null) {
            if (limits::valid_number($data, $this->min, $this->integer)) {
                return true;
            }
            $error = $this->integer ? 'setting_error_whole' : 'setting_error_decimal';
            return get_string($error, 'tool_secbackup', (string) $this->min);
        }
        $place = null;
        if ($data !== '') {
            if (!limits::usable_directory($data)) {
                return get_string('setting_error_directory', 'tool_secbackup');
            }
            if (limits::inside_dirroot($data)) {
                return get_string('setting_error_dirroot', 'tool_secbackup');
            }
            $place = dataroot_source::place_of($data);
            if ($place === 'dataroot') {
                return get_string('setting_error_dataroot', 'tool_secbackup');
            }
        }
        // The page writes every setting when it is saved: the saved folder holds the files of the plugin, and is kept.
        if (rtrim($data, '/\\') === rtrim((string) $this->get_setting(), '/\\')) {
            return true;
        }
        // A running backup keeps its temporary files in the folder that it started with, and would miss them.
        if (run_record::active() !== null) {
            return get_string('setting_error_running', 'tool_secbackup');
        }
        return $place === 'backedup' && !self::is_empty($data)
            ? get_string('setting_error_datafolder', 'tool_secbackup') : true;
    }

    /**
     * Whether a path is a folder that holds nothing; a folder that cannot be read is not taken as empty.
     *
     * @param string $path The path.
     * @return bool
     */
    private static function is_empty(string $path): bool {
        return is_dir($path) && is_readable($path) && !(new \FilesystemIterator($path))->valid();
    }
}
