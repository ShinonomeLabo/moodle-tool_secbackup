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

namespace tool_secbackup\local\diagnostics;

use tool_secbackup\local\error_silencer;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\plugin_exception;

/**
 * Whether SELinux denied something to this process (design of the plugin, 7.3): SELinux is enforcing, the process runs in a
 * confined domain of the web server, and the error is a denial of permission.
 *
 * SELinux confines the web server, not the command line or the cron of the system (unconfined_t, system_cronjob_t), so it is
 * never blamed there, nor when the domain cannot be read. A denial is told by what the system answers, not by a message,
 * which can be in the language of the server: a connection that failed at once (cURL error 7; a timeout or a failed lookup is
 * never a denial) is tried again on the loopback address and the same port, which SELinux denies as it denies any other
 * address (EACCES), while it is refused or accepted otherwise; a temporary file that could not be written is a denial when the
 * folder cannot be written to (the access check of the system), unless the error says what the state of the run is (a journal
 * or the entries shorter than committed).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selinux {
    /** @var int The port that a connection is tried on again: the one of every connection of the plugin to the service. */
    public const PORT = 443;

    /** @var int The error number of a denial of permission. */
    private const EACCES = 13;

    /** @var string[] Debug information of a temporary file that says what the state of the run is, not what was denied. */
    private const STATE_ERRORS = ['journal_short', 'journal_missing', 'journal_line'];

    /**
     * Whether SELinux is enforcing.
     *
     * @param system_files $files Reader of /sys.
     * @return bool
     */
    public static function enforcing(system_files $files): bool {
        return trim((string) $files->read('/sys/fs/selinux/enforce')) === '1';
    }

    /**
     * The SELinux type of this process, such as httpd_t.
     *
     * @param system_files $files Reader of /proc.
     * @return string|null Null when it cannot be read.
     */
    public static function process_type(system_files $files): ?string {
        $context = $files->read('/proc/self/attr/current');
        $parts = $context === null ? [] : explode(':', trim($context, "\0\n "));
        return isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
    }

    /**
     * Whether this process runs in a confined domain of the web server (httpd_t and the other httpd_* types that are not
     * unconfined), which SELinux restricts.
     *
     * @param system_files $files Reader of /proc.
     * @return bool False also when the domain cannot be read.
     */
    public static function confined(system_files $files): bool {
        $type = self::process_type($files);
        return $type !== null && str_starts_with($type, 'httpd_') && !str_contains($type, 'unconfined');
    }

    /**
     * Whether SELinux denies this process a connection to the port of the service: a connection to the loopback address is
     * denied (EACCES). A probe that cannot run proves nothing: with stream_socket_client() in disable_functions, PHP throws
     * an Error when it is called, and the failure stays a failed network check.
     *
     * @param callable|null $probe Function (int $port) that tries the connection and gives its error number (0 when it was
     *     made); a connection to 127.0.0.1 when null.
     * @return bool
     */
    public static function connection_denied(?callable $probe = null): bool {
        try {
            return ($probe ?? [self::class, 'connect_loopback'])(self::PORT) === self::EACCES;
        } catch (\Error $e) {
            return false;
        }
    }

    /**
     * Tries a connection to the loopback address.
     *
     * @param int $port The port.
     * @return int The error number; 0 when the connection was made.
     */
    public static function connect_loopback(int $port): int {
        $errno = 0;
        $errstr = '';
        $socket = error_silencer::run(static function () use ($port, &$errno, &$errstr) {
            return stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
        }, $warning);
        if (is_resource($socket)) {
            fclose($socket);
        }
        return $errno;
    }

    /**
     * Whether a folder cannot be written to by this process (the nearest one that exists, when it does not exist yet).
     *
     * @param string $folder The folder.
     * @param callable|null $writable Function (string $folder): bool; is_writable() when null.
     * @return bool
     */
    public static function folder_denied(string $folder, ?callable $writable = null): bool {
        while (!is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }
        return !($writable ?? 'is_writable')($folder);
    }

    /**
     * What SELinux denied when an error is a denial of SELinux.
     *
     * @param plugin_exception $e The error.
     * @param system_files $files Reader of /proc and /sys.
     * @param string $tempdir The temporary folder of the plugin.
     * @param callable|null $probe As for connection_denied().
     * @param callable|null $writable As for folder_denied().
     * @return string|null network (a connection), tempfolder (the temporary folder), or null when SELinux is not to blame.
     */
    public static function denial(
        plugin_exception $e,
        system_files $files,
        string $tempdir,
        ?callable $probe = null,
        ?callable $writable = null
    ): ?string {
        $network = transport_exception::failed_at_connect($e);
        $temp = $e->errorcode === 'temp_write_failed' && !self::state_error($e->debuginfo);
        if ((!$network && !$temp) || !self::enforcing($files) || !self::confined($files)) {
            return null;
        }
        if ($network) {
            return self::connection_denied($probe) ? 'network' : null;
        }
        return self::folder_denied($tempdir, $writable) ? 'tempfolder' : null;
    }

    /**
     * Whether the debug information of a temporary file says what the state of the run is.
     *
     * @param string|null $debuginfo The debug information.
     * @return bool
     */
    private static function state_error(?string $debuginfo): bool {
        return in_array($debuginfo, self::STATE_ERRORS, true) || str_starts_with((string) $debuginfo, 'entries_');
    }
}
