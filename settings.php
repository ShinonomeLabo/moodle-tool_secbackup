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
 * Admin pages of tool_secbackup (Site administration > Plugins > Admin tools).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('tools', new admin_category('tool_secbackup', new lang_string('pluginname', 'tool_secbackup')));
    $ADMIN->add('tool_secbackup', new admin_externalpage(
        'tool_secbackup_status',
        new lang_string('page_status', 'tool_secbackup'),
        new moodle_url('/admin/tool/secbackup/index.php'),
        'tool/secbackup:manage'
    ));
    $ADMIN->add('tool_secbackup', new admin_externalpage(
        'tool_secbackup_connect',
        new lang_string('page_connect', 'tool_secbackup'),
        new moodle_url('/admin/tool/secbackup/connect.php'),
        'tool/secbackup:manage'
    ));
    $ADMIN->add('tool_secbackup', new admin_externalpage(
        'tool_secbackup_diagnostics',
        new lang_string('page_diagnostics', 'tool_secbackup'),
        new moodle_url('/admin/tool/secbackup/diagnostics.php'),
        'tool/secbackup:manage'
    ));
    $limits = new admin_settingpage(
        'tool_secbackup_settings',
        new lang_string('page_settings', 'tool_secbackup'),
        'tool/secbackup:manage'
    );
    // Number settings are checked by their parameter type; limits::current() checks them again when it reads them.
    foreach (
        [
            ['loadpercore', '2', PARAM_FLOAT],
            ['minmemorymb', '256', PARAM_INT],
            ['maxdbms', '500', PARAM_INT],
            ['minnfsmbps', '5', PARAM_FLOAT],
            ['tempbudgetmb', '0', PARAM_INT],
            ['bandwidthmbps', '0', PARAM_INT],
        ] as [$name, $default, $type]
    ) {
        $limits->add(new admin_setting_configtext(
            'tool_secbackup/' . $name,
            new lang_string('setting_' . $name, 'tool_secbackup'),
            new lang_string('setting_' . $name . '_desc', 'tool_secbackup'),
            $default,
            $type
        ));
    }
    $limits->add(new admin_setting_configtext(
        'tool_secbackup/tempdir',
        new lang_string('setting_tempdir', 'tool_secbackup'),
        new lang_string('setting_tempdir_desc', 'tool_secbackup'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $ADMIN->add('tool_secbackup', $limits);
}
