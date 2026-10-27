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
 * The talk of the restore command with the restore host: one JSON object on each line, over a pair of streams.
 *
 * The restore host (the program that drives the restore) writes the lines of its side to the standard input of the command and
 * reads the lines of the command from its standard output. A line that does not have the shape of the message that is
 * expected next, in any detail, is refused: the restore host is trusted, but a half-written line must not be taken for a
 * message. Nothing of a refused line is kept in the exception, since a line can hold keys.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protocol {
    /** @var int The most bytes of a line that a restore host sends, without the line break. */
    public const MAX_LINE = 65536;

    /** @var string[][] The members of each message of the restore host, besides its type, and the type of each. */
    private const MESSAGES = ['site_keys' => ['sealed' => 'array'], 'go' => ['input' => 'string']];

    /** @var int Flags of the JSON that is written. */
    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

    /** @var resource The stream of the lines of the restore host. */
    private $in;

    /** @var resource The stream of the lines of the command. */
    private $out;

    /**
     * Constructor.
     *
     * @param resource $in The stream to read the lines from.
     * @param resource $out The stream to write the lines to.
     */
    public function __construct($in, $out) {
        $this->in = $in;
        $this->out = $out;
    }

    /**
     * Writes a message as one line, and flushes it, so that the restore host reads it at once.
     *
     * @param array $message The message.
     * @throws \JsonException When the message cannot be written as JSON.
     * @throws \RuntimeException When the line cannot be written.
     */
    public function send(array $message): void {
        $line = json_encode($message, self::JSON) . "\n";
        $written = error_silencer::run(fn() => fwrite($this->out, $line), $warning);
        if ($written !== strlen($line) || !error_silencer::run(fn() => fflush($this->out), $warning)) {
            throw new \RuntimeException('A line could not be written.');
        }
    }

    /**
     * Reads the next line and checks that it is the message that is expected.
     *
     * @param string $type The type of the message: site_keys or go.
     * @param int $maxbytes The most bytes of the line, without its line break.
     * @return array The message: its type and its members, of the shape that the type has.
     * @throws plugin_exception With restore_input_invalid when there is no line, the line is longer than $maxbytes, is not a
     *     JSON object, is a message of another type, lacks a member, has a member that is not known or not of its shape.
     * @throws \coding_exception When the type is not one that the restore host sends.
     */
    public function receive(string $type, int $maxbytes = self::MAX_LINE): array {
        if (!isset(self::MESSAGES[$type])) {
            throw new \coding_exception('The restore host does not send a message of the type ' . $type . '.');
        }
        // One byte more than the longest line is read: the line break of a line of $maxbytes, or the byte that is too much.
        $line = error_silencer::run(fn() => fgets($this->in, $maxbytes + 2), $warning);
        if ($line === false) {
            throw self::invalid();
        }
        if (str_ends_with($line, "\n")) {
            $line = substr($line, 0, -1);
        } else if (strlen($line) > $maxbytes) {
            throw self::invalid();
        }
        try {
            $message = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalid();
        }
        $members = self::MESSAGES[$type];
        // The type and the members, and no other: with the count right, no member that is not known is there.
        if (
            !is_array($message) || array_is_list($message) || ($message['type'] ?? null) !== $type
            || count($message) !== count($members) + 1
        ) {
            throw self::invalid();
        }
        foreach ($members as $name => $shape) {
            $value = $message[$name] ?? null;
            if (get_debug_type($value) !== $shape || ($shape === 'array' && !array_is_list($value))) {
                throw self::invalid();
            }
        }
        return $message;
    }

    /**
     * The exception of a line that is not the message that is expected.
     *
     * @return plugin_exception
     */
    private static function invalid(): plugin_exception {
        return new plugin_exception('restore_input_invalid');
    }
}
