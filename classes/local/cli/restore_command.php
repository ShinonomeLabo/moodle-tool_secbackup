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

namespace tool_secbackup\local\cli;

use tool_secbackup\local\crypto\key_exchange;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\restore\extractor;
use tool_secbackup\local\restore\protocol;

/**
 * The extraction of a generation on the command line, for the restore host that drives it.
 *
 * The restore host talks to the command over the standard input and output, one JSON line at a time: the command gives a
 * one-time public key, receives the sealed keys of the site and the path of the input file, and tells how far it is. A failure
 * of the plugin ends in one line {"type":"error","code":...} and the exit code 1. A failure of another kind ends in the exit
 * code 1 without that line, and one line on the error stream that names the kind of the failure and nothing else. What the
 * command writes never holds a key, a signature, a path or the input: it is a code, or a language string of the plugin.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_command {
    /** @var int The extraction is complete. */
    public const EXIT_DONE = 0;

    /** @var int The extraction failed. */
    public const EXIT_FAILED = 1;

    /** @var int Entries done that make the next progress line due. */
    public const PROGRESS_ENTRIES = 1000;

    /** @var int Seconds that make the next progress line due. */
    public const PROGRESS_SECONDS = 5;

    /** @var int The most bytes of the input file. */
    public const MAX_INPUT = 1048576;

    /** @var protocol The lines of the restore host and of the command. */
    private protocol $protocol;

    /** @var resource The stream for the message of a failure. */
    private $err;

    /** @var key_exchange|null The one-time key pair; null for one that is made when the extraction starts. */
    private ?key_exchange $exchange;

    /** @var callable Gives the current UNIX time. */
    private $clock;

    /** @var \Closure Makes the extractor: takes the input, the keys and the progress function. */
    private \Closure $extractors;

    /** @var int|null Entries done at the last progress line; null before the first. */
    private ?int $lastdone = null;

    /** @var int The time of the last progress line. */
    private int $lasttime = 0;

    /**
     * Constructor.
     *
     * @param resource $in The stream to read the lines of the restore host from.
     * @param resource $out The stream to write the lines of the command to.
     * @param resource $err The stream to write the message of a failure to.
     * @param key_exchange|null $exchange The one-time key pair; null for a new one.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param \Closure|null $extractors Makes the object whose run() extracts, as function (array $input, site_keys $keys,
     *     \Closure $progress): object; null for the extractor of the plugin.
     */
    public function __construct(
        $in,
        $out,
        $err,
        ?key_exchange $exchange = null,
        ?callable $clock = null,
        ?\Closure $extractors = null
    ) {
        $this->protocol = new protocol($in, $out);
        $this->err = $err;
        $this->exchange = $exchange;
        $this->clock = $clock ?? static fn(): int => time();
        $this->extractors = $extractors
            ?? static fn(array $input, site_keys $keys, \Closure $progress): object => new extractor($input, $keys, $progress);
    }

    /**
     * Writes the help of the command, under the trademark notice.
     *
     * @param callable $write Writes one line.
     */
    public static function help(callable $write): void {
        $write(get_string('trademark', 'tool_secbackup'));
        $write(get_string('cli_restore_help', 'tool_secbackup'));
    }

    /**
     * Extracts the generation that the restore host names, over the lines of the agreement.
     *
     * @return int EXIT_DONE, or EXIT_FAILED after one line of the error (a failure of the plugin) or none.
     */
    public function extract(): int {
        // The lines of the agreement are the only thing on the standard output: the restore host fails the extraction at a line
        // that is not JSON. They are written to the stream itself, not echoed. What anything else echoes (debugging() of
        // Moodle, a library) goes to the error stream, and the errors and warnings of PHP are shown there too.
        $display = ini_set('display_errors', 'stderr');
        $level = ob_get_level();
        ob_start(function (string $text): string {
            if ($text !== '') {
                $this->say(rtrim($text, "\n"));
            }
            return '';
        }, 1);
        try {
            // The compressed list of a million entries is about 72 MB and its peak is two or three times that.
            raise_memory_limit(MEMORY_HUGE);
            $this->run();
            return self::EXIT_DONE;
        } catch (plugin_exception $e) {
            try {
                $this->protocol->send(['type' => 'error', 'code' => $e->errorcode]);
            } catch (\Throwable $unsent) {
                // The restore host reads the exit code 1 without an error line as a failure, which it is.
                $this->say(get_string('cli_restore_failed', 'tool_secbackup', get_class($unsent)));
            }
            $this->say($e->message());
            return self::EXIT_FAILED;
        } catch (\Throwable $e) {
            // Only the class: the message of an exception can hold a path, and the restore host takes the exit code 1 without
            // an error line for a failure that the plugin did not expect.
            $this->say(get_string('cli_restore_failed', 'tool_secbackup', get_class($e)));
            return self::EXIT_FAILED;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
            ini_set('display_errors', (string) $display);
        }
    }

    /**
     * The lines of the agreement, from the public key to the summary.
     *
     * @throws plugin_exception With restore_input_invalid, restore_keys_invalid or what the extractor throws.
     */
    private function run(): void {
        $exchange = $this->exchange ?? new key_exchange();
        $this->protocol->send(['type' => 'ephemeral_public_key', 'key' => base64_encode($exchange->public_key())]);
        $keys = $exchange->open($this->protocol->receive('site_keys')['sealed']);
        $this->protocol->send(['type' => 'keys_ready', 'count' => $keys->count()]);
        $input = self::read_input($this->protocol->receive('go')['input']);
        $extractor = ($this->extractors)($input, $keys, $this->progress(...));
        $summary = $extractor->run();
        $this->protocol->send(['type' => 'done', 'summary' => $summary]);
    }

    /**
     * Tells how far the extraction is: at the start, then once for each PROGRESS_ENTRIES entries or PROGRESS_SECONDS seconds,
     * and when all the entries are done.
     *
     * @param int $done Entries done.
     * @param int $total Entries in the list.
     */
    private function progress(int $done, int $total): void {
        $now = ($this->clock)();
        if (
            $this->lastdone !== null && $done !== $total
            && $done - $this->lastdone < self::PROGRESS_ENTRIES && $now - $this->lasttime < self::PROGRESS_SECONDS
        ) {
            return;
        }
        $this->lastdone = $done;
        $this->lasttime = $now;
        $this->protocol->send(['type' => 'progress', 'stage' => 'extracting', 'done' => $done, 'total' => $total,
            'unit' => 'entries']);
    }

    /**
     * Reads the input of the extraction from its file.
     *
     * @param string $path The path of the file, absolute.
     * @return array The input, as the extractor checks it.
     * @throws plugin_exception With restore_input_invalid when the file cannot be read, is longer than MAX_INPUT, or is not
     *     JSON of an object or a list (the extractor refuses a list). The path and the content are not in the exception.
     */
    private static function read_input(string $path): array {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new plugin_exception('restore_input_invalid');
        }
        $text = error_silencer::run(fn() => file_get_contents($path, false, null, 0, self::MAX_INPUT + 1), $warning);
        if (!is_string($text) || strlen($text) > self::MAX_INPUT) {
            throw new plugin_exception('restore_input_invalid');
        }
        try {
            $input = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new plugin_exception('restore_input_invalid');
        }
        if (!is_array($input)) {
            throw new plugin_exception('restore_input_invalid');
        }
        return $input;
    }

    /**
     * Writes a line to the error stream.
     *
     * @param string $line The line, without its line break.
     */
    private function say(string $line): void {
        error_silencer::run(fn() => fwrite($this->err, $line . "\n"), $warning);
    }
}
