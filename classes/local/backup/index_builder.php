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

use tool_secbackup\local\plugin_exception;

/**
 * The index of a run: the name and the size of every chunk that the run's list of generations refers to.
 *
 * The first line is a header and each following line is a name and a size, in the order of the names, so that equal input
 * gives equal bytes. The backup service reads the index with limits of its own; a run that would exceed them stops with
 * capacity_refused before anything is built.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class index_builder {
    /** @var int Most chunks in an index. */
    public const MAX_COUNT = 818399;

    /** @var int Most bytes of the text of an index (64 MiB). */
    public const MAX_BYTES = 67108864;

    /** @var int Largest whole number that a JSON number holds exactly (2^53 - 1). */
    private const MAX_SIZE = 9007199254740991;

    /** @var int Lines compressed at a time. */
    private const PIECE_LINES = 2000;

    /**
     * Builds the index.
     *
     * @param array $sizes Size in bytes of each chunk by its name (64 lower-case hexadecimal digits). The manifest is not in it.
     * @param string $siteid Site ID at the backup service.
     * @param string $runuuid UUID of the run.
     * @param int $maxbytes Most bytes of the text; the contract's, but for a test.
     * @return array ['gzip' => the compressed index, 'count' => the number of chunks, 'sha256base64' => SHA-256 of the gzip]
     * @throws \coding_exception For a name, a size, a site or a run that is not in its form.
     * @throws plugin_exception With capacity_refused when the index would be larger than the service reads.
     */
    public static function build(array $sizes, string $siteid, string $runuuid, int $maxbytes = self::MAX_BYTES): array {
        foreach ([$siteid, $runuuid] as $id) {
            if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id) !== 1) {
                throw new \coding_exception('A site and a run are lower-case UUIDs.');
            }
        }
        $count = count($sizes);
        if ($count > self::MAX_COUNT) {
            throw new plugin_exception('capacity_refused', null, 'index_count');
        }
        ksort($sizes, SORT_STRING);
        $header = '{"v":1,"site_id":"' . $siteid . '","run_id":"' . $runuuid . '","count":' . $count . "}\n";
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $gzip = deflate_add($context, $header, ZLIB_NO_FLUSH);
        $bytes = strlen($header);
        $lines = '';
        $pending = 0;
        foreach ($sizes as $name => $size) {
            $name = (string) $name;
            if (preg_match('/^[0-9a-f]{64}\z/', $name) !== 1 || !is_int($size) || $size < 1 || $size > self::MAX_SIZE) {
                throw new \coding_exception('A chunk has a name of 64 lower-case hexadecimal digits and a size of 1 or more.');
            }
            $line = $name . ' ' . $size . "\n";
            $bytes += strlen($line);
            if ($bytes > $maxbytes) {
                throw new plugin_exception('capacity_refused', null, 'index_bytes');
            }
            $lines .= $line;
            if (++$pending === self::PIECE_LINES) {
                $gzip .= deflate_add($context, $lines, ZLIB_NO_FLUSH);
                $lines = '';
                $pending = 0;
            }
        }
        $gzip .= deflate_add($context, $lines, ZLIB_FINISH);
        return ['gzip' => $gzip, 'count' => $count, 'sha256base64' => base64_encode(hash('sha256', $gzip, true))];
    }
}
