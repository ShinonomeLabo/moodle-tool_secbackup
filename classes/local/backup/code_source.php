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
 * The plugins that were added to the site: their folders go into a backup as code, and the versions of all plugins go into the
 * list of the generation.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class code_source {
    /**
     * The folders of the plugins that do not come with Moodle.
     *
     * A plugin whose folder is missing from the disk (core lists it with no folder) has no root, and neither has a plugin whose
     * folder lies inside the folder of another one (a subplugin): the walk of the outer folder covers it. Of two plugins with
     * the same folder, the first component is the root.
     *
     * @param object[]|null $plugins Plugins by component instead of those of Moodle, for tests.
     * @return string[] Folder by component, such as "tool_secbackup", sorted by component.
     */
    public static function roots(?array $plugins = null): array {
        $plugins ??= self::plugins();
        ksort($plugins, SORT_STRING);
        $real = [];
        foreach ($plugins as $component => $plugin) {
            $resolved = $plugin->is_standard() || !is_string($plugin->rootdir) ? false : realpath($plugin->rootdir);
            if ($resolved !== false && is_dir($resolved)) {
                $real[$component] = rtrim($resolved, '/');
            }
        }
        $roots = [];
        foreach ($real as $component => $dir) {
            $covered = false;
            foreach ($real as $other => $outer) {
                $inside = str_starts_with($dir, $outer . '/') || ($dir === $outer && strcmp($other, $component) < 0);
                if ($other !== $component && $inside) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $roots[$component] = $plugins[$component]->rootdir;
            }
        }
        return $roots;
    }

    /**
     * The version of every plugin as it is in the database: that is what a restore installs the code for.
     *
     * @return array[] ['component', 'version' (text, or null when the plugin is not installed), 'standard'], sorted by component.
     */
    public static function versions(): array {
        $versions = [];
        foreach (self::plugins() as $component => $plugin) {
            $versions[] = [
                'component' => $component,
                'version' => $plugin->versiondb === null ? null : (string) $plugin->versiondb,
                'standard' => $plugin->is_standard(),
            ];
        }
        return $versions;
    }

    /**
     * Every plugin, by component, sorted.
     *
     * @return \core\plugininfo\base[]
     */
    private static function plugins(): array {
        $plugins = [];
        foreach (\core_plugin_manager::instance()->get_plugins() as $type => $ofthetype) {
            foreach ($ofthetype as $name => $plugin) {
                $plugins[$type . '_' . $name] = $plugin;
            }
        }
        ksort($plugins, SORT_STRING);
        return $plugins;
    }
}
