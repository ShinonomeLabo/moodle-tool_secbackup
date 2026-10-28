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
use tool_secbackup\local\restore\db_importer;
use tool_secbackup\local\restore\extractor;
use tool_secbackup\local\restore\forced_settings;
use tool_secbackup\local\restore\protocol;
use tool_secbackup\local\restore\temp_admin;
use tool_secbackup\local\restore\temp_site_safety;

/**
 * The restore of a generation on the command line, for the restore host that drives it: the extraction of the downloaded
 * generation, and the import of its database into the Moodle that the restore host installed from it.
 *
 * The restore host talks to the command over the standard input and output, one JSON line at a time. For the extraction the
 * command gives a one-time public key, receives the sealed keys of the site and the path of the input file, and tells how far
 * it is. For the import it receives one line with the administrator of the temporary site, and tells how far it is. A failure
 * of the plugin ends in one line {"type":"error","code":...} and the exit code 1. A failure of another kind ends in the exit
 * code 1 without that line, and one line on the error stream that names the kind of the failure and nothing else. What the
 * command writes never holds a key, a password, a signature, a path, the input or a value of a row: it is a code, or a
 * language string of the plugin, never the exception that a failure of the plugin was caused by.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_command {
    /** @var int The extraction or the import is complete. */
    public const EXIT_DONE = 0;

    /** @var int The extraction or the import failed. */
    public const EXIT_FAILED = 1;

    /** @var int Entries done that make the next progress line of the extraction due. */
    public const PROGRESS_ENTRIES = 1000;

    /** @var int Rows done that make the next progress line of the import due. */
    public const PROGRESS_ROWS = 10000;

    /** @var int The most things stopped that the summary of the import lists: the backup service takes no more. */
    public const MAX_STOPPED = 500;

    /** @var int Seconds that make the next progress line due. */
    public const PROGRESS_SECONDS = 5;

    /** @var int The most bytes of the input file. */
    public const MAX_INPUT = 1048576;

    /** @var string Pattern of a host name: lower-case labels of 1 to 63 letters, digits and inner hyphens, two or more. */
    private const HOST = '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/';

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

    /** @var \Closure Makes the importer: takes the staging folder and the progress function. */
    private \Closure $importers;

    /** @var self|null The command that is running, for the text that is not one of its lines. */
    private static ?self $active = null;

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
     * @param \Closure|null $importers Makes the object whose import() imports, as function (string $stagingdir,
     *     \Closure $progress): object; null for the importer of the plugin, on the database of the site.
     */
    public function __construct(
        $in,
        $out,
        $err,
        ?key_exchange $exchange = null,
        ?callable $clock = null,
        ?\Closure $extractors = null,
        ?\Closure $importers = null
    ) {
        $this->protocol = new protocol($in, $out);
        $this->err = $err;
        $this->exchange = $exchange;
        $this->clock = $clock ?? static fn(): int => time();
        $this->extractors = $extractors
            ?? static fn(array $input, site_keys $keys, \Closure $progress): object => new extractor($input, $keys, $progress);
        $this->importers = $importers ?? static function (string $stagingdir, \Closure $progress): object {
            global $DB;
            return new db_importer($stagingdir, $DB, $progress);
        };
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
     * Gives text that something else wrote while the extraction or the import runs to the error stream.
     *
     * @param string $text The text.
     */
    public static function stray(string $text): void {
        if (self::$active !== null && $text !== '') {
            self::$active->say(rtrim($text, "\n"));
        }
    }

    /**
     * Extracts the generation that the restore host names, over the lines of the agreement.
     *
     * @return int EXIT_DONE, or EXIT_FAILED after one line of the error (a failure of the plugin) or none.
     */
    public function extract(): int {
        return $this->guarded(function (): void {
            // The compressed list of a million entries is about 72 MB and its peak is two or three times that.
            raise_memory_limit(MEMORY_HUGE);
            $this->run();
        });
    }

    /**
     * Imports the database of the generation that the extraction left in the staging folder, then makes the site a temporary
     * site: the settings that config.php of the backed-up site forced, what a temporary site stops, and its administrator.
     *
     * The restore host gives one line, {"admin": {"username", "password"}, "expires_at", "temp_host"}. The line, the header of
     * the generation (header.json) and the forced settings and the plugins in it are checked before anything is written. Then
     * db_importer imports the database, forced_settings, temp_site_safety and temp_admin follow, in that order, and the summary
     * {"tables", "rows", "stopped"} ends the lines, with the first MAX_STOPPED things stopped that fit in the line. The time and
     * the host are checked for their form only: the restore host writes them into config.php of the site itself.
     *
     * The restore host stops the cron and the web server of the restored site first, and the import runs outside a transaction
     * of the database (db_importer says why). It can run again on the same staging folder. It does not purge the caches: the
     * restore host does that after it.
     *
     * @param string $stagingdir The staging folder that the extraction wrote, an absolute path.
     * @return int EXIT_DONE, or EXIT_FAILED after one line of the error (a failure of the plugin) or none.
     */
    public function import(string $stagingdir): int {
        return $this->guarded(fn() => $this->run_import($stagingdir));
    }

    /**
     * Runs the extraction or the import with the standard output for the lines of the agreement only.
     *
     * @param \Closure $run The extraction or the import.
     * @return int EXIT_DONE, or EXIT_FAILED after one line of the error (a failure of the plugin) or none.
     */
    private function guarded(\Closure $run): int {
        // The lines of the agreement are the only thing on the standard output: the restore host fails the restore at a line
        // that is not JSON. They are written to the stream itself, not echoed. What is echoed meanwhile (debugging() of Moodle, a
        // library) is caught by an output buffer and goes to the error stream, and so does what mtrace() of Moodle writes, which
        // does not pass the buffer, and the errors and warnings of PHP. cli_writeln() and a write to STDOUT are not caught: the
        // extraction and the import do not use them, nor do the functions of Moodle that they call.
        global $CFG;
        require_once(__DIR__ . '/../../../cli/restore_mtrace.php');
        $display = ini_set('display_errors', 'stderr');
        $wrapper = $CFG->mtrace_wrapper ?? null;
        $CFG->mtrace_wrapper = 'tool_secbackup_restore_mtrace';
        self::$active = $this;
        $level = ob_get_level();
        ob_start(static function (string $text): string {
            self::stray($text);
            return '';
        }, 1);
        try {
            $run();
            return self::EXIT_DONE;
        } catch (plugin_exception $e) {
            try {
                $this->protocol->send(['type' => 'error', 'code' => $e->errorcode]);
            } catch (\Throwable $unsent) {
                // The restore host reads the exit code 1 without an error line as a failure, which it is.
                $this->say(get_string('cli_restore_failed', 'tool_secbackup', get_class($unsent)));
            }
            // The message of this exception only: the one that caused it (a write that the database refused) has the values of
            // the row in its message and debug information.
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
            self::$active = null;
            if ($wrapper === null) {
                unset($CFG->mtrace_wrapper);
            } else {
                $CFG->mtrace_wrapper = $wrapper;
            }
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
        $extractor = ($this->extractors)($input, $keys, $this->progress('extracting', 'entries', self::PROGRESS_ENTRIES));
        $summary = $extractor->run();
        $this->protocol->send(['type' => 'done', 'summary' => $summary]);
    }

    /**
     * The lines of the import, from the line of the restore host to the summary.
     *
     * @param string $stagingdir The staging folder.
     * @throws plugin_exception With restore_input_invalid, or what the importer and the steps after it throw.
     */
    private function run_import(string $stagingdir): void {
        $line = $this->protocol->receive('import');
        if (!self::is_time($line['expires_at']) || preg_match(self::HOST, $line['temp_host']) !== 1) {
            throw new plugin_exception('restore_input_invalid');
        }
        $admin = $line['admin'];
        temp_admin::check($admin['username'], $admin['password']);
        $header = self::read_header($stagingdir);
        forced_settings::check($header['config']['forced']);
        temp_site_safety::check($header);
        $importer = ($this->importers)($stagingdir, $this->progress('importing', 'rows', self::PROGRESS_ROWS));
        $result = $importer->import();
        forced_settings::apply($header['config']['forced']);
        $stopped = temp_site_safety::apply($header);
        temp_admin::ensure($admin['username'], $admin['password']);
        // The first of the things stopped, as many as the backup service takes and the line holds: the table of the plugin
        // keeps all of them.
        $done = ['type' => 'done', 'summary' => [
            'tables' => $result['tables'],
            'rows' => $result['rows'],
            'stopped' => array_slice($stopped, 0, self::MAX_STOPPED),
        ]];
        while (!protocol::fits($done)) {
            array_pop($done['summary']['stopped']);
        }
        $this->protocol->send($done);
    }

    /**
     * The function that tells how far a stage is: at the start, then once for each $step units or PROGRESS_SECONDS seconds,
     * and when all the units are done.
     *
     * @param string $stage The stage of the progress lines.
     * @param string $unit What the stage counts.
     * @param int $step Units done that make the next line due.
     * @return \Closure Takes the units done and the units in all.
     */
    private function progress(string $stage, string $unit, int $step): \Closure {
        return function (int $done, int $total) use ($stage, $unit, $step): void {
            $now = ($this->clock)();
            if (
                $this->lastdone !== null && $done !== $total
                && $done - $this->lastdone < $step && $now - $this->lasttime < self::PROGRESS_SECONDS
            ) {
                return;
            }
            $this->lastdone = $done;
            $this->lasttime = $now;
            $this->protocol->send(['type' => 'progress', 'stage' => $stage, 'done' => $done, 'total' => $total,
                'unit' => $unit]);
        };
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
     * Whether a time of the restore host is one in UTC to the second, YYYY-MM-DDTHH:MM:SSZ, of a day of its month and an hour
     * of its day.
     *
     * @param string $time The time.
     * @return bool
     */
    private static function is_time(string $time): bool {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $time, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d\\TH:i:s\\Z') === $time;
    }

    /**
     * Reads the header of the generation that the extraction wrote to the staging folder.
     *
     * @param string $stagingdir The staging folder.
     * @return array The header, with config.forced an array.
     * @throws plugin_exception With restore_input_invalid when the staging folder is not an absolute path, or header.json cannot
     *     be read, is not a JSON object or has no config.forced that is an object. The path is not in the exception.
     */
    private static function read_header(string $stagingdir): array {
        if (!str_starts_with($stagingdir, '/') || str_contains($stagingdir, "\0")) {
            throw new plugin_exception('restore_input_invalid');
        }
        $path = $stagingdir . '/header.json';
        $text = error_silencer::run(static fn() => file_get_contents($path), $warning);
        try {
            $header = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $header = null;
        }
        if (!is_array($header['config']['forced'] ?? null)) {
            throw new plugin_exception('restore_input_invalid', null, 'header');
        }
        return $header;
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
