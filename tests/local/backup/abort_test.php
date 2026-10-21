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

use tool_secbackup\local\api\api_exception;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\run_fixture;
use tool_secbackup\tests\system_roots;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\abort::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\notifier::class)]
/**
 * Tests for stopping a run: what is closed, removed, told and recorded, and which errors are reasons to stop.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\abort
 * @covers     \tool_secbackup\local\backup\notifier
 */
final class abort_test extends \advanced_testcase {
    /** @var int The clock of the tests. */
    private const NOW = 1760000000;

    /** @var run_fixture The site and the service. */
    private run_fixture $fixture;

    /** @var string The folder of the temporary files. */
    private string $tempdir;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->fixture = new run_fixture(self::NOW);
        $this->tempdir = make_request_directory();
    }

    /**
     * The temporary area of the tests.
     *
     * @return temp_area
     */
    private function temp(): temp_area {
        return new temp_area(new limits(2.0, 256, 500, 5.0, $this->tempdir, 100, 0));
    }

    /**
     * A run that the service has started, with a file in its folder.
     *
     * @return run_record
     */
    private function started_run(): run_record {
        $run = run_record::create('manual', self::NOW);
        $run->set_run_uuid($this->fixture->start_at_service());
        file_put_contents($this->temp()->for_run($run->id()) . '/chunk', 'data');
        return $run;
    }

    /**
     * Stops a run with the seams of the tests.
     *
     * @param run_record $run The run.
     * @param string $code The reason.
     * @param callable|null $closedb Closes the export connection.
     * @param string[] $leftovers Files that the uploader could not remove.
     */
    private function abort(run_record $run, string $code, ?callable $closedb = null, array $leftovers = []): void {
        abort::run(
            $run,
            $code,
            $this->fixture->client(),
            $closedb,
            $this->temp(),
            $this->fixture->link(),
            $this->fixture->clock(),
            $leftovers
        );
    }

    public function test_a_stopped_run_is_cleared_told_and_recorded(): void {
        $run = $this->started_run();
        $folder = $this->tempdir . '/run-' . $run->id();
        $this->fixture->advance(60);
        $this->abort($run, 'server_busy');
        $this->assertDirectoryDoesNotExist($folder);
        $service = $this->fixture->service->state['runs'][$run->run_uuid()];
        $this->assertSame('failed', $service['status']);
        $this->assertSame('server_busy', $service['failure_code']);
        $stored = run_record::recent(1)[0];
        $this->assertSame('aborted', $stored->status());
        $this->assertSame('server_busy', $stored->abort_code());
        $this->assertSame(self::NOW + 60, (int) $this->stored_row($run)->timefinished);
        $this->assertSame(['cleared' => true, 'leftovers' => 0], $stored->progress()['abort']);
    }

    public function test_a_stale_copy_of_a_run_that_ended_is_not_stopped_or_told(): void {
        $stale = $this->started_run();
        $folder = $this->tempdir . '/run-' . $stale->id();
        run_record::active()->finish(self::NOW + 10);
        $closed = false;
        $this->abort($stale, 'disk_low', function () use (&$closed): void {
            $closed = true;
        });
        $this->assertTrue($closed, 'The export connection of the caller is closed all the same.');
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
        $stored = run_record::recent(1)[0];
        $this->assertSame('completed', $stored->status());
        $this->assertNull($stored->abort_code());
        $this->assertFileExists($folder . '/chunk', 'The files of a run that another process owns are left alone.');
        $this->assertSame(0, run_record::consecutive_aborts());
    }

    public function test_the_export_connection_is_closed_first_even_when_closing_fails(): void {
        $run = $this->started_run();
        $order = [];
        $this->fixture->intercept = function ($request) use (&$order) {
            $order[] = 'request';
            return null;
        };
        $this->abort($run, 'db_slow', function () use (&$order): void {
            $order[] = 'closed';
            throw new \dml_read_exception('connection lost');
        });
        $this->assertSame(['closed', 'request'], $order);
        $this->assertSame('aborted', run_record::recent(1)[0]->status());
    }

    public function test_a_run_that_cannot_be_told_to_the_service_is_still_recorded(): void {
        $run = $this->started_run();
        $this->fixture->service->state['mode'] = 'unavailable';
        $this->abort($run, 'disk_low');
        $this->assertSame(1, $this->fixture->count('POST', '/fail'));
        $this->assertSame('started', $this->fixture->service->state['runs'][$run->run_uuid()]['status']);
        $stored = run_record::recent(1)[0];
        $this->assertSame('aborted', $stored->status());
        $this->assertSame('disk_low', $stored->abort_code());
    }

    public function test_a_run_without_an_id_at_the_service_is_not_told(): void {
        $run = run_record::create('manual', self::NOW);
        $this->abort($run, 'network_check_failed');
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
        $this->assertSame('network_check_failed', run_record::recent(1)[0]->abort_code());
    }

    public function test_a_failure_that_the_service_found_is_not_told_back(): void {
        $run = $this->started_run();
        $this->abort($run, 'verify_missing_chunks');
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
        $this->assertSame('verify_missing_chunks', run_record::recent(1)[0]->abort_code());
    }

    public function test_a_folder_that_stays_and_the_files_left_by_the_uploader_are_recorded(): void {
        $run = run_record::create('manual', self::NOW);
        // A file in place of the folder of the run is not removed by the area.
        file_put_contents($this->tempdir . '/run-' . $run->id(), 'x');
        $this->abort($run, 'upload_failed', null, ['/a', '/b']);
        $this->assertSame(['cleared' => false, 'leftovers' => 2], run_record::recent(1)[0]->progress()['abort']);
    }

    public function test_the_progress_of_the_stage_is_kept_with_the_record_of_the_stop(): void {
        $run = run_record::create('manual', self::NOW);
        $run->set_stage('files', ['cursor' => ['offset' => 10]], self::NOW);
        $this->abort($run, 'nfs_slow');
        $progress = run_record::recent(1)[0]->progress();
        $this->assertSame(['offset' => 10], $progress['cursor']);
        $this->assertSame('files', run_record::recent(1)[0]->stage());
    }

    public function test_the_third_stop_in_a_row_tells_the_administrators_once(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $DB->set_field('user', 'lang', 'en', ['id' => get_admin()->id]);
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->assertSame(0, $sink->count());
        $this->abort(run_record::create('schedule', self::NOW), 'disk_low');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame('tool_secbackup', $message->component);
        $this->assertSame('backupfailure', $message->eventtype);
        $this->assertSame((int) get_admin()->id, (int) $message->useridto);
        $this->assertStringContainsString('3', $message->fullmessage);
        $this->assertStringContainsString(get_string('abort_disk_low', 'tool_secbackup'), $message->fullmessage);
        $this->assertStringContainsString(get_string('abort_disk_low_advice', 'tool_secbackup'), $message->fullmessage);
        $this->assertStringContainsString('/admin/tool/secbackup/index.php', $message->fullmessage);
        $sink->clear();
        // The fourth stop of the same series does not tell them again.
        $this->abort(run_record::create('schedule', self::NOW), 'disk_low');
        $this->assertSame(0, $sink->count());
    }

    public function test_what_the_site_keeps_about_a_stop_is_recorded_and_told_to_the_administrators(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $DB->set_field('user', 'lang', 'en', ['id' => get_admin()->id]);
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $run = run_record::create('schedule', self::NOW);
        $details = ['denial' => 'tempfolder', 'folder' => '/data/tool'];
        abort::run(
            $run,
            'selinux_denied',
            $this->fixture->client(),
            null,
            $this->temp(),
            $this->fixture->link(),
            $this->fixture->clock(),
            [],
            true,
            $details
        );
        $this->assertSame($details, array_intersect_key($run->abort_note(), $details));
        $this->assertSame(['cleared' => true, 'leftovers' => 0] + $details, run_record::recent(1)[0]->abort_note());
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            get_string('selinux_denial_tempfolder', 'tool_secbackup', '/data/tool'),
            reset($messages)->fullmessage
        );
        $this->assertSame([], run_record::create('manual', self::NOW)->abort_note());
    }

    public function test_a_completed_run_starts_the_count_again(): void {
        $sink = $this->redirectMessages();
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        run_record::create('schedule', self::NOW)->finish(self::NOW);
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->assertSame(0, $sink->count());
    }

    public function test_the_message_names_a_reason_that_has_no_text_of_its_own(): void {
        $sink = $this->redirectMessages();
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        $this->abort(run_record::create('schedule', self::NOW), 'something_new');
        $messages = $sink->get_messages();
        $this->assertStringContainsString('something_new', reset($messages)->fullmessage);
        $this->assertStringContainsString(get_string('abort_unknown_advice', 'tool_secbackup'), reset($messages)->fullmessage);
    }

    public function test_a_message_that_cannot_be_sent_does_not_stop_the_record(): void {
        // Moodle holds events and messages back while a transaction is open, and PHPUnit wraps each test in one.
        $this->preventResetByRollback();
        $adminid = (int) get_admin()->id;
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\notification_sent',
            'callback' => static function (\core\event\notification_sent $event) use ($adminid): void {
                if ((int) $event->relateduserid === $adminid) {
                    throw new \Error('Provoked by the test');
                }
            },
            'internal' => false,
            'priority' => 0,
            'includefile' => null,
        ]]);
        try {
            $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
            $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
            $this->abort(run_record::create('schedule', self::NOW), 'server_busy');
        } finally {
            \core\event\manager::phpunit_reset();
        }
        $this->assertDebuggingCalled(
            'The message about the stopped backups could not be sent to the user ' . $adminid . ': Error',
            DEBUG_NORMAL
        );
        $this->assertSame(3, run_record::consecutive_aborts());
        $this->assertSame('aborted', run_record::recent(1)[0]->status());
    }

    /**
     * The row of a run in the table.
     *
     * @param run_record $run The run.
     * @return \stdClass
     */
    private function stored_row(run_record $run): \stdClass {
        global $DB;
        return $DB->get_record('tool_secbackup_runs', ['id' => $run->id()]);
    }

    /**
     * The errors and the reasons they stand for, with SELinux enforcing and not.
     *
     * @return array
     */
    public static function classify_provider(): array {
        $cases = [
            'a reason of the run' => [new plugin_exception('server_busy'), 'server_busy', 'server_busy'],
            'a refused capacity' => [new plugin_exception('capacity_refused'), 'capacity_refused', 'capacity_refused'],
            'an ended contract' => [new plugin_exception('contract_inactive'), 'contract_inactive', 'contract_inactive'],
            'sends that failed' => [new plugin_exception('upload_failed'), 'upload_failed', 'upload_failed'],
            'a changed file' => [new plugin_exception('tamper_detected'), 'tamper_detected', 'tamper_detected'],
            // SELinux alone is not to blame: the process is not the confined web server here (see the test of denials).
            'no connection' => [new transport_exception('transport_connect'), 'network_check_failed', 'network_check_failed'],
            'no DNS over HTTPS' => [
                new transport_exception('dns_doh_unavailable'),
                'network_check_failed',
                'network_check_failed',
            ],
            'a temporary file' => [new plugin_exception('temp_write_failed'), 'disk_low', 'disk_low'],
            'a bad envelope' => [new envelope_exception('envelope_signature'), 'signature_invalid', 'signature_invalid'],
            'an untrusted key' => [new envelope_exception('envelope_untrusted'), 'signature_invalid', 'signature_invalid'],
            'a malformed envelope' => [new envelope_exception('envelope_malformed'), 'signature_invalid', 'signature_invalid'],
            'a receipt of another run' => [new envelope_exception('receipt_mismatch'), 'signature_invalid', 'signature_invalid'],
            'a refused signature of the artifacts' => [
                new api_exception('api_artifact_signature_invalid', 'artifact_signature_invalid', 422),
                'signature_invalid',
                'signature_invalid',
            ],
            'an address outside the ranges' => [
                new transport_exception('dns_outside_aws'),
                'network_check_failed',
                'network_check_failed',
            ],
            'names that differ' => [new transport_exception('dns_os_mismatch'), 'network_check_failed', 'network_check_failed'],
            'an unknown name' => [new transport_exception('dns_not_found'), 'network_check_failed', 'network_check_failed'],
            'a storage address' => [
                new transport_exception('object_url_invalid'),
                'network_check_failed',
                'network_check_failed',
            ],
            'a refused object' => [new transport_exception('object_rejected'), '', ''],
            'a cut object' => [new transport_exception('object_incomplete'), '', ''],
            'a busy service' => [new api_exception('api_unavailable', '', 503, 60), '', ''],
            'a key that cannot be read' => [new key_exception('dedupkey_corrupt'), '', ''],
            'a coding error' => [new \coding_exception('broken'), '', ''],
            'an error of PHP' => [new \TypeError('wrong'), '', ''],
            'a lost database' => [new \dml_read_exception('gone'), '', ''],
        ];
        $rows = [];
        foreach ($cases as $name => [$exception, $permissive, $enforcing]) {
            $rows[$name . ', SELinux permissive'] = [$exception, '0', $permissive];
            $rows[$name . ', SELinux enforcing'] = [$exception, '1', $enforcing];
        }
        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classify_provider')]
    /**
     * The reason that an error stands for.
     *
     * @dataProvider classify_provider
     * @param \Throwable $exception The error.
     * @param string $enforce Content of /sys/fs/selinux/enforce.
     * @param string $expected The reason, or empty when the error is not a reason to stop.
     */
    public function test_classify(\Throwable $exception, string $enforce, string $expected): void {
        $files = new system_files(system_roots::with_files(['/sys/fs/selinux/enforce' => $enforce . "\n"]));
        $this->assertSame($expected, abort::classify($exception, $files));
    }

    /**
     * Errors that SELinux may have caused, and what they stand for when the confined web server got a denial.
     *
     * @return array
     */
    public static function denial_provider(): array {
        return [
            'a connection that failed at once' => [
                new transport_exception('transport_connect', 'cURL error 7'), 13, true,
                ['selinux_denied', ['denial' => 'network']],
            ],
            'lookups that all failed at once' => [
                new transport_exception('dns_doh_unavailable', 'cURL error 7'), 13, true,
                ['selinux_denied', ['denial' => 'network']],
            ],
            'a connection that the loopback address refuses too' => [
                new transport_exception('transport_connect', 'cURL error 7'), 111, true, ['network_check_failed', []],
            ],
            'a timeout' => [new transport_exception('transport_connect', 'cURL error 28'), 13, true, ['network_check_failed', []]],
            'lookups of which one timed out' => [
                new transport_exception('dns_doh_unavailable'), 13, true, ['network_check_failed', []],
            ],
            'a temporary folder that cannot be written' => [
                new plugin_exception('temp_write_failed', null, 'folder: /data/tool'), 13, false,
                ['selinux_denied', ['denial' => 'tempfolder', 'folder' => '/data/tool']],
            ],
            'a temporary folder that can be written' => [
                new plugin_exception('temp_write_failed', null, 'journal_write'), 13, true, ['disk_low', []],
            ],
            'a journal shorter than committed' => [
                new plugin_exception('temp_write_failed', null, 'journal_short'), 13, false, ['disk_low', []],
            ],
            'entries that are missing' => [
                new plugin_exception('temp_write_failed', null, 'entries_missing'), 13, false, ['disk_low', []],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('denial_provider')]
    /**
     * SELinux is blamed for a denial of the confined web server only.
     *
     * @dataProvider denial_provider
     * @param plugin_exception $exception The error.
     * @param int $probe The error number of a connection on the loopback address.
     * @param bool $writable Whether the temporary folder can be written to.
     * @param array $expected The reason and the details.
     */
    public function test_selinux_is_blamed_for_a_denial_of_the_web_server_only(
        plugin_exception $exception,
        int $probe,
        bool $writable,
        array $expected
    ): void {
        $root = system_roots::with_files([
            '/sys/fs/selinux/enforce' => "1\n",
            '/proc/self/attr/current' => "system_u:system_r:httpd_t:s0\0",
        ]);
        $files = new system_files($root);
        $probes = [];
        $probefn = function (int $port) use ($probe, &$probes): int {
            $probes[] = $port;
            return $probe;
        };
        $writablefn = static fn(string $folder): bool => $writable;
        $this->assertSame($expected, abort::reason($exception, $files, '/data/tool', $probefn, $writablefn));
        $this->assertSame($expected[0], abort::classify($exception, $files, '/data/tool', $probefn, $writablefn));
        $this->assertSame(in_array(($exception->debuginfo ?? ''), ['cURL error 7'], true) ? [443, 443] : [], $probes);
        // The same denial outside the web server (the command line), or while SELinux is permissive, is not blamed.
        foreach (
            ["system_u:system_r:unconfined_t:s0\0", "system_u:system_r:system_cronjob_t:s0\0",
                "system_u:system_r:httpd_unconfined_script_t:s0\0", ''] as $context
        ) {
            file_put_contents($root . '/proc/self/attr/current', $context);
            $this->assertNotSame('selinux_denied', abort::classify($exception, $files, '/data/tool', $probefn, $writablefn));
        }
        file_put_contents($root . '/proc/self/attr/current', "system_u:system_r:httpd_t:s0\0");
        file_put_contents($root . '/sys/fs/selinux/enforce', "0\n");
        $this->assertNotSame('selinux_denied', abort::classify($exception, $files, '/data/tool', $probefn, $writablefn));
    }

    public function test_the_default_checks_ask_the_system(): void {
        // A connection on the loopback address of a test server is refused or made, never denied; the temporary folder of
        // the limits that are set can be written to.
        $files = new system_files(system_roots::with_files([
            '/sys/fs/selinux/enforce' => "1\n",
            '/proc/self/attr/current' => "system_u:system_r:httpd_t:s0\0",
        ]));
        $this->assertNotSame(13, \tool_secbackup\local\diagnostics\selinux::connect_loopback(1));
        $this->assertSame(
            'network_check_failed',
            abort::classify(new transport_exception('transport_connect', 'cURL error 7'), $files)
        );
        $this->assertSame('disk_low', abort::classify(new plugin_exception('temp_write_failed', null, 'journal_write'), $files));
    }

    public function test_without_selinux_a_refused_connection_is_a_failed_network_check(): void {
        $files = new system_files(make_request_directory());
        $this->assertSame('network_check_failed', abort::classify(new transport_exception('transport_connect'), $files));
        $this->assertSame('disk_low', abort::classify(new plugin_exception('temp_write_failed'), $files));
    }
}
