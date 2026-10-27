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

use tool_secbackup\local\backup\limit_setting;
use tool_secbackup\local\backup\limits;

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
    // Each setting accepts only what limits::current() will use, so a saved value is never replaced by a default.
    $limits->add(new limit_setting('loadpercore', (string) limits::DEFAULT_LOADPERCORE, 0.1, false));
    $limits->add(new limit_setting('minmemorymb', (string) limits::DEFAULT_MINMEMORYMB, 0.0, true));
    $limits->add(new limit_setting('maxdbms', (string) limits::DEFAULT_MAXDBMS, 1.0, true));
    $limits->add(new limit_setting('minnfsmbps', (string) limits::DEFAULT_MINNFSMBPS, 0.0, false));
    $limits->add(new limit_setting('tempbudgetmb', (string) limits::DEFAULT_TEMPBUDGETMB, 0.0, true));
    $limits->add(new limit_setting('bandwidthmbps', (string) limits::DEFAULT_BANDWIDTHMBPS, 0.0, true));
    $limits->add(new limit_setting('tempdir', '', null, false));
    $ADMIN->add('tool_secbackup', $limits);
}
