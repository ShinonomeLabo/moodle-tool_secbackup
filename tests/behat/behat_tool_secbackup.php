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
 * Behat steps of tool_secbackup.
 *
 * @package    tool_secbackup
 * @category   test
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by Behat before including config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Behat steps of tool_secbackup.
 *
 * @package    tool_secbackup
 * @category   test
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_secbackup extends behat_base {
    /**
     * Installs a minimal Japanese language, so that real pages load the strings of lang/ja of the plugin.
     *
     * The language has no strings of its own: the strings of Moodle fall back to English and only the plugin is in
     * Japanese. A language pack cannot be put on the test site beforehand, because Moodle empties the language folder of
     * the test site before every scenario, so each scenario that needs Japanese installs it.
     *
     * @Given /^the Japanese language pack is installed$/
     */
    public function the_japanese_language_pack_is_installed(): void {
        global $CFG;

        $strings = [
            'locale' => 'ja_JP.UTF-8',
            'parentlanguage' => '',
            'thisdirection' => 'ltr',
            'thislanguage' => '日本語',
            'thislanguageint' => 'Japanese',
        ];
        $code = "<?php\n";
        foreach ($strings as $id => $text) {
            $code .= '$string[' . var_export($id, true) . '] = ' . var_export($text, true) . ";\n";
        }

        // The folder in which Moodle looks for language packs: the language folder of the dataroot, unless it is moved.
        $dir = $CFG->langotherroot . '/ja';
        make_writable_directory($dir);
        $file = $dir . '/langconfig.php';
        if (file_put_contents($file, $code) === false) {
            throw new \Exception("The Japanese language pack cannot be written to $file.");
        }
        get_string_manager()->reset_caches();
    }
}
