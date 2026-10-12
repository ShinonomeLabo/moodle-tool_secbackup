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

namespace tool_secbackup\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for tool_secbackup.
 *
 * The plugin sends encrypted backups of the whole site, which include the personal data of all
 * users, to the backup service. It keeps no personal data in its own tables.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describes the data sent to the backup service.
     *
     * @param collection $collection The collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'tool_secbackup_service',
            ['sitedata' => 'privacy:metadata:service:sitedata'],
            'privacy:metadata:service'
        );
        return $collection;
    }
}
