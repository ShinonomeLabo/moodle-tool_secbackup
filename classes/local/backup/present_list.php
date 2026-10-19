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

use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Reads the list of the chunks that the storage holds (the "present list"), which the backup service keeps for the site.
 *
 * The list tells a run which chunks it need not send again. A list that is not this site's, or not in its form, is not
 * trusted: the run stops with signature_invalid, as it does for an answer whose signature is wrong.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class present_list {
    /** @var int Most chunks that are read from a list. */
    private const MAX_COUNT = 10000000;

    /** @var int Longest line of the list: a name, a space and a size of at most 16 digits. */
    private const MAX_LINE = 100;

    /** @var int Longest header. */
    private const MAX_HEADER = 1000;

    /** @var int Bytes of the file read and decompressed at a time. */
    private const PIECE = 8192;

    /** @var int Largest whole number that a JSON number holds exactly (2^53 - 1). */
    private const MAX_SIZE = 9007199254740991;

    /**
     * Reads a list.
     *
     * The file is decompressed in pieces, and a list whose compressed data does not end where it should (a transfer that was
     * cut) is not accepted.
     *
     * @param string $gzippath The file that was fetched. A file that is not there is the list of a first run (the storage had
     *     no list, 404), and gives an empty list.
     * @param string $siteid Site ID at the backup service.
     * @return array Size in bytes of each chunk, by its name, in the order of the list.
     * @throws \coding_exception When $siteid is not a UUID.
     * @throws plugin_exception With signature_invalid when the list is not this site's or not in its form.
     */
    public static function parse(string $gzippath, string $siteid): array {
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $siteid) !== 1) {
            throw new \coding_exception('A site is a lower-case UUID.');
        }
        if (!is_file($gzippath)) {
            return [];
        }
        $in = error_silencer::run(static fn() => fopen($gzippath, 'rb'), $warning);
        if ($in === false) {
            throw self::invalid('open');
        }
        try {
            return self::read($in, $siteid);
        } finally {
            fclose($in);
        }
    }

    /**
     * Reads an open list.
     *
     * @param resource $in The compressed list.
     * @param string $siteid Site ID.
     * @return array
     * @throws plugin_exception With signature_invalid.
     */
    private static function read($in, string $siteid): array {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $buffer = '';
        $count = null;
        $previous = '';
        $list = [];
        while (inflate_get_status($context) !== ZLIB_STREAM_END) {
            $raw = fread($in, self::PIECE);
            if ($raw === false || $raw === '') {
                // The file ends before the compressed data does.
                throw self::invalid('cut');
            }
            $text = error_silencer::run(static fn() => inflate_add($context, $raw), $warning);
            if ($text === false) {
                throw self::invalid('inflate');
            }
            $buffer .= $text;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                if ($count === null) {
                    $count = self::header($line, $siteid);
                    continue;
                }
                self::entry($line, $previous, $list, $count);
            }
            if (strlen($buffer) > ($count === null ? self::MAX_HEADER : self::MAX_LINE)) {
                throw self::invalid('line');
            }
        }
        // Nothing may follow the last line feed or the end of the compressed data.
        if ($buffer !== '' || fread($in, 1) !== '' || $count === null || count($list) !== $count) {
            throw self::invalid('end');
        }
        return $list;
    }

    /**
     * Checks the header line.
     *
     * @param string $line The line, without its line feed.
     * @param string $siteid Site ID.
     * @return int The number of chunks that follow.
     * @throws plugin_exception With signature_invalid.
     */
    private static function header(string $line, string $siteid): int {
        $data = strlen($line) > self::MAX_HEADER ? null : json_decode($line, true, 4);
        if (
            !is_array($data) || ($data['v'] ?? null) !== 1 || ($data['site_id'] ?? null) !== $siteid
            || !is_int($data['count'] ?? null) || $data['count'] < 0 || $data['count'] > self::MAX_COUNT
        ) {
            throw self::invalid('header');
        }
        return $data['count'];
    }

    /**
     * Checks one line of a chunk and adds it.
     *
     * @param string $line The line, without its line feed.
     * @param string $previous The name of the line before, which is replaced.
     * @param array $list The list so far.
     * @param int $count The number of lines that the header promised.
     * @throws plugin_exception With signature_invalid.
     */
    private static function entry(string $line, string &$previous, array &$list, int $count): void {
        if (preg_match('/^([0-9a-f]{64}) ([1-9][0-9]{0,15})\z/', $line, $m) !== 1 || (int) $m[2] > self::MAX_SIZE) {
            throw self::invalid('line');
        }
        if ($m[1] <= $previous || count($list) >= $count) {
            throw self::invalid('order');
        }
        $previous = $m[1];
        $list[$m[1]] = (int) $m[2];
    }

    /**
     * The failure.
     *
     * @param string $where Short detail without content.
     * @return plugin_exception
     */
    private static function invalid(string $where): plugin_exception {
        return new plugin_exception('signature_invalid', null, 'present_' . $where);
    }
}
