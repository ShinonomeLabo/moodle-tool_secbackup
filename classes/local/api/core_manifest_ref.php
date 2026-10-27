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

namespace tool_secbackup\local\api;

use tool_secbackup\local\net\object_url;

/**
 * Where the list of the official files of a Moodle build is read, and what it must be.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class core_manifest_ref {
    /** @var string Standard base64 of the SHA-256 of the list. */
    private string $sha256;

    /** @var int Size of the list, in bytes. */
    private int $size;

    /** @var int Number of files in the list. */
    private int $count;

    /** @var object_url Where the list is read. */
    private object_url $url;

    /**
     * Constructor.
     *
     * @param string $sha256 SHA-256, standard base64.
     * @param int $size Size in bytes.
     * @param int $count Number of files.
     * @param object_url $url Where the list is read.
     */
    public function __construct(string $sha256, int $size, int $count, object_url $url) {
        $this->sha256 = $sha256;
        $this->size = $size;
        $this->count = $count;
        $this->url = $url;
    }

    /**
     * SHA-256 of the list.
     *
     * @return string Standard base64.
     */
    public function sha256(): string {
        return $this->sha256;
    }

    /**
     * Size of the list.
     *
     * @return int Bytes.
     */
    public function size(): int {
        return $this->size;
    }

    /**
     * Number of files in the list.
     *
     * @return int
     */
    public function count(): int {
        return $this->count;
    }

    /**
     * Where the list is read.
     *
     * @return object_url
     */
    public function url(): object_url {
        return $this->url;
    }
}
