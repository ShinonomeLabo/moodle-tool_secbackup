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

namespace tool_secbackup\tests;

use tool_secbackup\local\backup\files\file_source;

/**
 * A file_source whose handles can fail to open, and whose handles it keeps, for the tests.
 *
 * A handle is a failing_stream handle while failing_stream::fail() has counted streams, and open() fails (false) for the
 * first $openfailures handles.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flaky_file_source extends file_source {
    /** @var int How many of the next opens fail. */
    public int $openfailures = 0;

    /** @var resource[] The handles that were given. */
    public array $handles = [];

    /**
     * The handle of a file, through a failing stream.
     *
     * @param \stored_file $file The file.
     * @return resource|false
     */
    protected function handle_of(\stored_file $file) {
        global $CFG;
        if ($this->openfailures > 0) {
            $this->openfailures--;
            return false;
        }
        $hash = $file->get_contenthash();
        $path = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $handle = fopen(failing_stream::PROTOCOL . '://' . $path, 'rb');
        if ($handle !== false) {
            $this->handles[] = $handle;
        }
        return $handle;
    }

    /**
     * Opens a file of the trash; fails while there are opens to fail.
     *
     * @param string $path The path.
     * @return resource|false
     */
    protected function open_path(string $path) {
        if ($this->openfailures > 0) {
            $this->openfailures--;
            return false;
        }
        $handle = parent::open_path($path);
        if ($handle !== false) {
            $this->handles[] = $handle;
        }
        return $handle;
    }
}
