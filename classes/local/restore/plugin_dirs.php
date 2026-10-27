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
 * - lib/components.json at the root, whose "plugintypes" give the folder of each plugin type relative to the root. On Moodle
 *   5.1 and later the file stays at the root, outside public/, and its folders start with "public/".
 * - else the db/subplugins.json of the plugins of the types that may have subplugins (mod, editor, tool, then local, each in
 *   the byte order of the names; the first that has the type counts). Its "subplugintypes" are relative to the folder of the
 *   plugin; when it has none, its "plugintypes" are relative to the web root, which is public/ when the root has public/.
 *
 * Those files come from the backup too, so a folder that is not a plain relative path is refused, as tree_writer refuses it.
 * The subplugins are looked for again on each call, since the code of an added plugin may be written after the first call.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_dirs {
    /** @var string[] The plugin types whose plugins may have subplugins, in the order in which core_component reads them. */
    private const PARENTS = ['mod', 'editor', 'tool', 'local'];

    /** @var int The most bytes of a JSON file that are read. */
    private const MAX_JSON = 1048576;

    /** @var string Pattern of a plugin type. */
    private const TYPE = '/\A[a-z][a-z0-9]*\z/';

    /** @var string Pattern of the name of a plugin. */
    private const NAME = '/\A[a-z][a-z0-9_]*\z/';

    /** @var string The root of the restored code. */
    private string $root;

    /** @var bool Whether the root has public/ (Moodle 5.1 and later). */
    private bool $public;

    /** @var array|null The "plugintypes" of lib/components.json, once they have been read. */
    private ?array $types = null;

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
     *     to the restored code, or the folder is not a plain relative path.
     */
    public function folder(string $component): string {
        $parts = explode('_', $component, 2);
        if (count($parts) !== 2 || preg_match(self::TYPE, $parts[0]) !== 1 || preg_match(self::NAME, $parts[1]) !== 1) {
            throw new plugin_exception('restore_path_unsafe');
        }
        [$type, $name] = $parts;
        $this->types ??= $this->plugintypes();
        $folder = $this->types[$type] ?? $this->subplugintypes()[$type] ?? null;
        if (!is_string($folder)) {
            throw new plugin_exception('restore_path_unsafe');
        }
        tree_writer::check_path($folder . '/' . $name);
        return $folder . '/' . $name;
    }

    /**
     * The "plugintypes" of lib/components.json.
     *
     * @return array Folder (or whatever the file has) by plugin type.
     * @throws plugin_exception With restore_path_unsafe when the file is missing or has no such list.
     */
    private function plugintypes(): array {
        $types = $this->json('lib/components.json')['plugintypes'] ?? null;
        if (!is_array($types)) {
            throw new plugin_exception('restore_path_unsafe');
        }
        return $types;
    }

    /**
     * The subplugin types that the plugins of the parent types have, as they are on the disk now.
     *
     * @return array Folder relative to the root (or null when the file has no text for it) by subplugin type.
     */
    private function subplugintypes(): array {
        $found = [];
        foreach (self::PARENTS as $parent) {
            $folder = $this->types[$parent] ?? null;
            $names = is_string($folder)
                ? error_silencer::run(fn() => scandir($this->root . '/' . $folder), $warning)
                : false;
            foreach ($names === false ? [] : $names as $plugin) {
                // Names that are not of the form are not plugins; "." and ".." are such names.
                if (preg_match(self::NAME, $plugin) !== 1) {
                    continue;
                }
                foreach ($this->declared($folder . '/' . $plugin) as $subtype => $subfolder) {
                    $found[$subtype] ??= $subfolder;
                }
            }
        }
        return $found;
    }

    /**
     * The subplugin types that one plugin declares in its db/subplugins.json.
     *
     * @param string $plugin The folder of the plugin, relative to the root.
     * @return array Folder relative to the root, or null for a value that is not text, by subplugin type.
     */
    private function declared(string $plugin): array {
        $json = $this->json($plugin . '/db/subplugins.json');
        $relative = $json['subplugintypes'] ?? null;
        if (is_array($relative) && $relative !== []) {
            return array_map(static fn($folder) => is_string($folder) ? $plugin . '/' . $folder : null, $relative);
        }
        $fromweb = $json['plugintypes'] ?? null;
        if (!is_array($fromweb)) {
            return [];
        }
        return array_map(fn($folder) => is_string($folder) ? $this->from_web_root($folder) : null, $fromweb);
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
