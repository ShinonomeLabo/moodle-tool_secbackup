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

namespace tool_secbackup\local\crypto;

/**
 * A chunk that chunk_writer has written.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunk_info {
    /** @var string Path of the file. */
    public readonly string $path;

    /** @var string Name of the chunk (64 hexadecimal digits). */
    public readonly string $name;

    /** @var int Size of the file in bytes. */
    public readonly int $size;

    /** @var string SHA-256 of the file, in standard base64. */
    public readonly string $sha256base64;

    /**
     * @var array[] Items in the order they were added: ['offset' => byte offset of the item, 'length' => its bytes]. The
     *     length of the last item includes the padding of the chunk.
     */
    public readonly array $items;

    /**
     * Constructor.
     *
     * @param string $path Path of the file.
     * @param string $name Name of the chunk.
     * @param int $size Size in bytes.
     * @param string $sha256base64 SHA-256 in standard base64.
     * @param array[] $items Offsets and lengths of the items.
     */
    public function __construct(string $path, string $name, int $size, string $sha256base64, array $items) {
        $this->path = $path;
        $this->name = $name;
        $this->size = $size;
        $this->sha256base64 = $sha256base64;
        $this->items = $items;
    }
}
