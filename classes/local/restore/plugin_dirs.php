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

use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * The folder of a plugin in the Moodle code that a restore has written, as that code would find it.
 *
 * The list of a generation has the files of an added plugin under its component, not under its folder, and the Moodle that is
 * restored may not be the Moodle that runs the restore, so the folder is read from the code that was restored, the way
 * core_component reads it:
 * - lib/components.json at the root, whose "plugintypes" (then "deprecatedplugintypes") give the folder of each plugin type
 *   relative to the root. On Moodle 5.1 and later the file stays at the root, outside public/, and its folders start with
 *   "public/".
 * - else the db/subplugins.json of the plugins of the types that may have subplugins: mod, editor, tool, then local, and in
 *   each type the folders that core_component takes as plugins (of the form of the names of the type, not one of the folders
 *   it passes over, not an activity module with the name of a subsystem that has a folder), in byte order. The first plugin
 *   that has the type counts. Its "subplugintypes" are relative to the folder of the plugin; when it has none, its
 *   "plugintypes" are relative to the web root, which is public/ when the root has public/. Its "deprecatedsubplugintypes" are
 *   relative to the folder of the plugin and come after the others. A subplugin type with the name of a subsystem that has a
 *   folder is passed over.
 * Unlike core_component, a subplugin type whose folder is not there yet still counts: the code of the subplugins is written
 * after this is asked.
 *
 * Those files come from the backup too, so a folder that is not a plain relative path is refused, as tree_writer refuses it:
 * the folder of a parent type before it is listed, and the folder that is given. The subplugins are looked for again on each
 * call, since the code of an added plugin may be written after the first call.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_dirs {
    /** @var string[] The plugin types whose plugins may have subplugins, in the order in which core_component reads them. */
    private const PARENTS = ['mod', 'editor', 'tool', 'local'];

    /**
     * @var string[] The folders that core_component never takes as plugins. The others of its list (CVS and _vti_cnf) are not
     *     of the form of a name anyway.
     */
    private const IGNORED = ['amd', 'classes', 'db', 'fonts', 'lang', 'pix', 'simpletest', 'templates', 'tests', 'yui'];

    /** @var int The most bytes of a JSON file that are read. */
    private const MAX_JSON = 1048576;

    /** @var string Pattern of a plugin type. */
    private const TYPE = '/\A[a-z][a-z0-9]*\z/';

    /** @var string Pattern of the name of a plugin. */
    private const NAME = '/\A[a-z][a-z0-9_]*\z/';

    /** @var string Pattern of the folder of an activity module, as core_component has it. */
    private const MODULE = '/\A[a-z][a-z0-9]*\z/';

    /** @var string Pattern of the folder of any other plugin, as core_component has it. */
    private const PLUGIN = '/\A[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+\z/';

    /** @var string The root of the restored code. */
    private string $root;

    /** @var bool Whether the root has public/ (Moodle 5.1 and later). */
    private bool $public;

    /** @var array|null The folder (or whatever the file has) by plugin type, active types first, once they have been read. */
    private ?array $types = null;

    /** @var array The subsystems that have a folder, by name. */
    private array $subsystems = [];

    /**
     * Constructor.
     *
     * @param string $root The root of the restored code: the folder that holds lib/components.json.
     */
    public function __construct(string $root) {
        $this->root = $root;
        $this->public = is_dir($root . '/public');
    }

    /**
     * The folder of a plugin.
     *
     * @param string $component The component, "<type>_<name>".
     * @return string The folder, relative to the root, such as "mod/attendance" or "public/mod/attendance".
     * @throws plugin_exception With restore_path_unsafe when the component is not of the form of Moodle, its type is not known
     *     to the restored code, or a folder that lib/components.json gives is not a plain relative path.
     */
    public function folder(string $component): string {
        $parts = explode('_', $component, 2);
        if (count($parts) !== 2 || preg_match(self::TYPE, $parts[0]) !== 1 || preg_match(self::NAME, $parts[1]) !== 1) {
            throw new plugin_exception('restore_path_unsafe');
        }
        [$type, $name] = $parts;
        if ($this->types === null) {
            $this->read_components();
        }
        $folder = $this->types[$type] ?? $this->subplugintypes()[$type] ?? null;
        if (!is_string($folder)) {
            throw new plugin_exception('restore_path_unsafe');
        }
        tree_writer::check_path($folder . '/' . $name);
        return $folder . '/' . $name;
    }

    /**
     * Reads the plugin types and the subsystems of lib/components.json.
     *
     * @throws plugin_exception With restore_path_unsafe when the file is missing or has no list of plugin types.
     */
    private function read_components(): void {
        $components = $this->json('lib/components.json');
        if (!is_array($components['plugintypes'] ?? null)) {
            throw new plugin_exception('restore_path_unsafe');
        }
        // A subsystem without a folder (empty() in core_component) does not take its name from plugins.
        $subsystems = self::list($components['subsystems'] ?? null);
        $this->subsystems = array_filter($subsystems, static fn($folder): bool => !empty($folder));
        $this->types = $components['plugintypes'] + self::list($components['deprecatedplugintypes'] ?? null);
    }

    /**
     * The subplugin types that the plugins of the parent types have, as they are on the disk now.
     *
     * @return array Folder relative to the root (or null when the file has no text for it) by subplugin type.
     * @throws plugin_exception With restore_path_unsafe when the folder of a parent type is not a plain relative path.
     */
    private function subplugintypes(): array {
        $found = [];
        foreach (self::PARENTS as $parent) {
            $folder = $this->types[$parent] ?? null;
            if ($folder === null) {
                continue;
            }
            // The folder comes from the backup: it is checked before anything in it is listed or read.
            tree_writer::check_path(is_string($folder) ? $folder : '');
            foreach ($this->plugins($parent, $folder) as $plugin) {
                foreach ($this->declared($folder . '/' . $plugin) as $subtype => $subfolder) {
                    if (!isset($this->subsystems[$subtype])) {
                        $found[$subtype] ??= $subfolder;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * The folders in the folder of a type that core_component takes as plugins of the type.
     *
     * @param string $type The plugin type.
     * @param string $folder Its folder, relative to the root.
     * @return string[] The names, in byte order.
     */
    private function plugins(string $type, string $folder): array {
        $names = error_silencer::run(fn() => scandir($this->root . '/' . $folder), $warning);
        $plugins = array_filter($names === false ? [] : $names, fn(string $name): bool => $this->is_plugin($type, $name));
        // The order of scandir() is the order of the locale, which puts "ab" before "a_c"; core_component sorts the bytes.
        sort($plugins, SORT_STRING);
        return $plugins;
    }

    /**
     * Whether core_component takes a folder in the folder of a type as a plugin of the type.
     *
     * @param string $type The plugin type.
     * @param string $name The name of the folder; "." and ".." are not of the form.
     * @return bool
     */
    private function is_plugin(string $type, string $name): bool {
        $module = $type === 'mod';
        return preg_match($module ? self::MODULE : self::PLUGIN, $name) === 1 && !in_array($name, self::IGNORED, true)
            && !($module && isset($this->subsystems[$name]));
    }

    /**
     * The subplugin types that one plugin declares in its db/subplugins.json.
     *
     * @param string $plugin The folder of the plugin, relative to the root.
     * @return array Folder relative to the root, or null for a value that is not text, by subplugin type.
     */
    private function declared(string $plugin): array {
        $json = $this->json($plugin . '/db/subplugins.json');
        $relative = self::list($json['subplugintypes'] ?? null);
        $fromweb = self::list($json['plugintypes'] ?? null);
        $active = $relative !== []
            ? self::under($plugin, $relative)
            : array_map(fn($folder) => is_string($folder) ? $this->from_web_root($folder) : null, $fromweb);
        return $active + self::under($plugin, self::list($json['deprecatedsubplugintypes'] ?? null));
    }

    /**
     * Folders relative to the folder of a plugin, made relative to the root.
     *
     * @param string $plugin The folder of the plugin, relative to the root.
     * @param array $folders The folders by subplugin type.
     * @return array Folder, or null for a value that is not text, by subplugin type.
     */
    private static function under(string $plugin, array $folders): array {
        return array_map(static fn($folder) => is_string($folder) ? $plugin . '/' . $folder : null, $folders);
    }

    /**
     * A folder relative to the web root, made relative to the root.
     *
     * @param string $folder The folder.
     * @return string
     */
    private function from_web_root(string $folder): string {
        return $this->public && !str_starts_with($folder, 'public/') ? 'public/' . $folder : $folder;
    }

    /**
     * A value of a JSON file that should be a list or an object.
     *
     * @param mixed $value The value.
     * @return array The value, or an empty array when it is not one.
     */
    private static function list(mixed $value): array {
        return is_array($value) ? $value : [];
    }

    /**
     * Reads a JSON file of the restored code.
     *
     * @param string $path The path, relative to the root.
     * @return array|null What it holds, or null when it is missing, cannot be read or is not a JSON object or list.
     */
    private function json(string $path): ?array {
        $file = $this->root . '/' . $path;
        $text = error_silencer::run(static fn() => file_get_contents($file, false, null, 0, self::MAX_JSON), $warning);
        $data = is_string($text) ? json_decode($text, true) : null;
        return is_array($data) ? $data : null;
    }
}
