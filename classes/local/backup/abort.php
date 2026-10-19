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

use tool_secbackup\local\api\client;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\local\plugin_exception;

/**
 * Stops a run and puts things back (section 6.4 of the design of the plugin), and tells which errors are reasons to stop.
 *
 * Chunks that were sent are not taken back: the storage locks them, and the next run uses them instead of sending them again.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class abort {
    /** @var string[] The reasons with which the backup service fails a run itself. */
    public const SERVICE_CODES = ['expired', 'verify_missing_chunks', 'verify_size_mismatch', 'verify_artifact_mismatch',
        'verify_index_invalid', 'verify_error'];

    /** @var int Stops in a row after which the site administrators are told. */
    public const NOTIFY_AFTER = 3;

    /** @var string[] Errors of the connection that SELinux causes when it denies a connection. */
    private const CONNECTION_ERRORS = ['transport_connect', 'dns_doh_unavailable'];

    /**
     * Stops a run.
     *
     * In order: the transaction of the export is closed, the temporary files of the run are removed, the backup service is told
     * the reason (a failure to tell it does not keep the run going: the service fails a run that is not completed within 24
     * hours), the stop is recorded, and the third stop in a row is told to the site administrators. A reason that the service
     * gave itself (SERVICE_CODES) is not told back. A run that the table says has ended already (the caller's copy is stale)
     * is left as it is: only the export connection of the caller is closed.
     *
     * @param run_record $run The run.
     * @param string $code The reason: one of client::FAIL_CODES, or a reason that the service gave.
     * @param client $client The client of the service.
     * @param callable|null $closedb Closes the export connection and its transaction, if one is open; null when none is.
     * @param temp_area|null $temp The temporary area; null for the one of the limits that are set.
     * @param callable|null $link Gives [endpoint, signing_keypair, site ID]; null for the registration of this site.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param string[] $leftovers Files that the uploader could not remove, for the record.
     */
    public static function run(
        run_record $run,
        string $code,
        client $client,
        ?callable $closedb = null,
        ?temp_area $temp = null,
        ?callable $link = null,
        ?callable $clock = null,
        array $leftovers = []
    ): void {
        if ($closedb !== null) {
            try {
                $closedb();
            } catch (\Throwable $e) {
                // The connection ends with the process anyway: the stop goes on, and the error is not kept.
                unset($e);
            }
        }
        if (!$run->is_live()) {
            // A stale copy: another process ended the run, which keeps its record, its files and its state at the service.
            return;
        }
        $cleared = ($temp ?? temp_area::create())->clear($run->id());
        if ($run->run_uuid() !== null && in_array($code, client::FAIL_CODES, true)) {
            try {
                [$endpoint, $pair, $siteid] = ($link ?? [uploader::class, 'site_link'])();
                $client->fail($endpoint, $pair, $siteid, (string) $run->run_uuid(), $code);
            } catch (\Throwable $e) {
                // The service fails a run that is not completed within 24 hours, so the stop is recorded all the same. The
                // error is not kept: the trace of the call holds the key pair of the site as an argument.
                unset($e);
            }
        }
        $now = $clock === null ? time() : (int) $clock();
        $run->abort($code, $now, ['cleared' => $cleared, 'leftovers' => count($leftovers)]);
        if (run_record::consecutive_aborts() === self::NOTIFY_AFTER) {
            notifier::backup_failed($code, self::NOTIFY_AFTER);
        }
    }

    /**
     * The reason to stop a run that an error stands for.
     *
     * An error of the plugin whose code is a reason is that reason. A connection that is refused, a lookup over HTTPS that is
     * not possible and a temporary file that cannot be written are selinux_denied while SELinux is enforcing; otherwise the
     * first two are network_check_failed and the third is disk_low. An envelope or a receipt that cannot be verified, and a
     * signature of the artifacts that the service refuses, are signature_invalid. The other refusals of the name lookup and of
     * the storage address are network_check_failed. Anything else is not a reason to stop: the caller tries again.
     *
     * @param \Throwable $e The error.
     * @param system_files $files Reader of /sys, for the state of SELinux.
     * @return string The reason, or an empty string when the error is not a reason to stop.
     */
    public static function classify(\Throwable $e, system_files $files): string {
        if (!$e instanceof plugin_exception) {
            return '';
        }
        $code = $e->errorcode;
        if (in_array($code, client::FAIL_CODES, true)) {
            return $code;
        }
        $selinux = in_array($code, self::CONNECTION_ERRORS, true) || $code === 'temp_write_failed';
        if ($selinux && trim((string) $files->read('/sys/fs/selinux/enforce')) === '1') {
            return 'selinux_denied';
        }
        if ($code === 'temp_write_failed') {
            return 'disk_low';
        }
        if (in_array($code, self::CONNECTION_ERRORS, true) || str_starts_with($code, 'dns_') || $code === 'object_url_invalid') {
            return 'network_check_failed';
        }
        if (str_starts_with($code, 'envelope_') || $code === 'receipt_mismatch' || $code === 'api_artifact_signature_invalid') {
            return 'signature_invalid';
        }
        return '';
    }
}
