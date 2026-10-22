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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\run_record::class)]
/**
 * Tests for the record of a backup run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\run_record
 */
final class run_record_test extends \advanced_testcase {
    public function test_a_new_run_is_active_at_the_first_stage(): void {
        global $DB;
        $this->resetAfterTest();

        $run = run_record::create('manual', 1000);

        $this->assertGreaterThan(0, $run->id());
        $this->assertSame('active', $run->status());
        $this->assertSame('prepare', $run->stage());
        $this->assertSame('manual', $run->trigger());
        $this->assertSame([], $run->progress());
        $this->assertNull($run->run_uuid());
        $this->assertNull($run->abort_code());
        $this->assertSame(0, $run->entries_committed());
        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertSame('full', $row->kind);
        $this->assertEquals(1000, $row->timecreated);
        $this->assertSame(1000, $run->started_at());
        $this->assertSame(1000, run_record::active()->started_at());
        $this->assertEquals(1000, $row->timemodified);
        $this->assertNull($row->timefinished);
        $this->assertNull($row->nextattempt);
        $this->assertEquals(0, $row->windowmisses);
        $this->assertEquals(0, $row->bytesuploaded);
        $this->assertEquals(0, $row->chunksuploaded);
        $this->assertNull($row->estimatedbytes);
        // Any server may go on with a run until the run says otherwise (a temporary folder of one server).
        $this->assertNull($row->hostname);
        $this->assertNull($run->hostname());
        $this->assertNull($run->next_attempt());
        $this->assertSame(0, $run->window_misses());
        $this->assertSame(0, $run->bytes_uploaded());
        $this->assertSame(0, $run->chunks_uploaded());
        $this->assertNull($run->estimated_bytes());
    }

    public function test_an_unknown_trigger_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        run_record::create('weekly', 1000);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trigger_provider')]
    /**
     * Every trigger is accepted.
     *
     * @dataProvider trigger_provider
     * @param string $trigger The trigger.
     */
    public function test_the_known_triggers_are_accepted(string $trigger): void {
        $this->resetAfterTest();
        $this->assertSame($trigger, run_record::create($trigger, 1000)->trigger());
    }

    /**
     * The triggers of a run.
     *
     * @return array
     */
    public static function trigger_provider(): array {
        return [['schedule'], ['manual'], ['cli']];
    }

    public function test_the_stages_are_in_order(): void {
        $this->assertSame(
            ['prepare', 'start', 'present', 'capacity', 'db', 'files', 'dataroot', 'code', 'manifest', 'index',
                'complete', 'receipt', 'done'],
            run_record::STAGES
        );
    }

    public function test_setting_a_stage_stores_the_progress_as_json(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);

        $run->set_stage('db', ['table' => 'user', 'row' => 5000, 'done' => false, 'ratio' => 0.5], 1100);

        $this->assertSame('db', $run->stage());
        $this->assertSame(['table' => 'user', 'row' => 5000, 'done' => false, 'ratio' => 0.5], $run->progress());
        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertSame('db', $row->stage);
        $this->assertEquals(1100, $row->timemodified);
        $this->assertSame(['table' => 'user', 'row' => 5000, 'done' => false, 'ratio' => 0.5], json_decode($row->progress, true));
    }

    public function test_the_state_is_read_back_from_the_database(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->set_stage('files', ['n' => 3], 1100);

        $again = run_record::active();

        $this->assertNotNull($again);
        $this->assertSame($run->id(), $again->id());
        $this->assertSame('files', $again->stage());
        $this->assertSame(['n' => 3], $again->progress());
    }

    public function test_an_unknown_stage_is_refused_and_nothing_is_stored(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        try {
            $run->set_stage('upload', [], 1100);
            $this->fail('A stage that is not listed must be refused.');
        } catch (\coding_exception $e) {
            $this->assertSame('prepare', $run->stage());
            $this->assertSame('prepare', run_record::active()->stage());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('url_progress_provider')]
    /**
     * A URL anywhere in the progress is refused.
     *
     * @dataProvider url_progress_provider
     * @param array $progress A progress that holds a URL.
     */
    public function test_a_progress_that_holds_a_url_is_refused(array $progress): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        try {
            $run->set_stage('db', $progress, 1100);
            $this->fail('A URL must not be written to the table.');
        } catch (\coding_exception $e) {
            $this->assertSame([], $run->progress());
            $this->assertSame('prepare', $run->stage());
        }
    }

    /**
     * Progress values that hold a URL, at the top and nested.
     *
     * @return array
     */
    public static function url_progress_provider(): array {
        return [
            'top level' => [['target' => 'https://storage.example.com/bucket/key?sig=abc']],
            'nested' => [['chunks' => [['name' => 'a', 'put' => ['url' => 'https://storage.example.com/x']]]]],
            'other scheme' => [['target' => 'ftp://storage.example.com/x']],
            'embedded' => [['note' => 'retry later: http://storage.example.com/x failed']],
        ];
    }

    public function test_a_progress_without_urls_is_accepted_when_a_value_has_only_a_colon(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->set_stage('db', ['path' => 'a:b', 'n' => 1, 'x' => null, 'list' => ['f/g']], 1100);
        $this->assertSame(['path' => 'a:b', 'n' => 1, 'x' => null, 'list' => ['f/g']], $run->progress());
    }

    public function test_active_returns_the_latest_active_or_paused_run_only(): void {
        $this->resetAfterTest();
        $this->assertNull(run_record::active());

        $done = run_record::create('cli', 1000);
        $done->finish(1010);
        $aborted = run_record::create('cli', 1020);
        $aborted->abort('upload_failed', 1030);
        $this->assertNull(run_record::active());

        $paused = run_record::create('schedule', 1040);
        $paused->pause(1050, 2000);
        $this->assertSame($paused->id(), run_record::active()->id());
        $this->assertSame('paused', run_record::active()->status());

        $latest = run_record::create('manual', 1060);
        $this->assertSame($latest->id(), run_record::active()->id());
    }

    public function test_pausing_stores_the_next_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);

        $run->pause(1100, 2500);

        $this->assertSame('paused', $run->status());
        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertSame('paused', $row->status);
        $this->assertEquals(2500, $row->nextattempt);
        $this->assertEquals(1100, $row->timemodified);
        $this->assertNull($row->timefinished);
    }

    public function test_finishing_completes_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->pause(1050, 1100);

        $run->finish(1200);

        $this->assertSame('completed', $run->status());
        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertEquals(1200, $row->timefinished);
        $this->assertNull($row->nextattempt);
        $this->assertSame($run->id(), run_record::last_completed()->id());
    }

    public function test_the_end_of_a_run_is_known_only_when_it_has_ended(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $this->assertNull($run->finished_at());
        $run->finish(1200);
        $this->assertSame(1200, $run->finished_at());
        $this->assertSame(1200, run_record::last_completed()->finished_at());
    }

    public function test_aborting_stores_the_code(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);

        $run->abort('window_ended', 1300);

        $this->assertSame('aborted', $run->status());
        $this->assertSame('window_ended', $run->abort_code());
        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertSame('window_ended', $row->abortcode);
        $this->assertEquals(1300, $row->timefinished);
        $this->assertSame('window_ended', run_record::recent(1)[0]->abort_code());
    }

    public function test_last_completed_is_null_without_a_completed_run(): void {
        $this->resetAfterTest();
        $this->assertNull(run_record::last_completed());
        run_record::create('cli', 1000)->abort('x', 1001);
        $this->assertNull(run_record::last_completed());
    }

    public function test_last_completed_is_the_latest_one(): void {
        $this->resetAfterTest();
        $first = run_record::create('cli', 1000);
        $first->finish(1010);
        $second = run_record::create('cli', 1020);
        $second->finish(1030);
        $this->assertSame($second->id(), run_record::last_completed()->id());
    }

    public function test_recent_returns_the_newest_first_up_to_the_limit(): void {
        $this->resetAfterTest();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = run_record::create('cli', 1000 + $i)->id();
        }

        $recent = run_record::recent(3);

        $this->assertCount(3, $recent);
        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_map(static fn($r) => $r->id(), $recent));
        $this->assertInstanceOf(run_record::class, $recent[0]);
        $this->assertSame([], run_record::recent(0));
    }

    public function test_consecutive_aborts_counts_those_after_the_last_completed_run(): void {
        $this->resetAfterTest();
        $this->assertSame(0, run_record::consecutive_aborts());

        run_record::create('cli', 1000)->abort('a', 1001);
        $this->assertSame(1, run_record::consecutive_aborts());

        run_record::create('cli', 1010)->finish(1011);
        $this->assertSame(0, run_record::consecutive_aborts());

        run_record::create('cli', 1020)->abort('a', 1021);
        run_record::create('cli', 1030)->abort('b', 1031);
        $paused = run_record::create('cli', 1040);
        $paused->pause(1041, 1100);
        $this->assertSame(2, run_record::consecutive_aborts());
    }

    public function test_the_counters_are_added_up(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);

        $run->add_uploaded(10, 1);
        $run->add_uploaded(32 * 1024 * 1024 * 1024, 2);
        $run->set_entries_committed(7);
        $run->set_estimate(99);
        $run->set_run_uuid('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e11');

        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertEquals(32 * 1024 * 1024 * 1024 + 10, $row->bytesuploaded);
        $this->assertEquals(3, $row->chunksuploaded);
        $this->assertEquals(7, $row->entriescommitted);
        $this->assertEquals(99, $row->estimatedbytes);
        $this->assertSame('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e11', $row->runuuid);
        $this->assertSame(7, $run->entries_committed());
        $this->assertSame('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e11', run_record::active()->run_uuid());
    }

    public function test_a_commit_writes_the_stage_the_progress_and_the_entries_at_once(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);

        $run->commit('files', ['cursor' => ['offset' => 120]], 42, 1100);

        $row = $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
        $this->assertSame('files', $row->stage);
        $this->assertSame(['cursor' => ['offset' => 120]], json_decode($row->progress, true));
        $this->assertEquals(42, $row->entriescommitted);
        $this->assertEquals(1100, $row->timemodified);
        $this->assertSame(42, $run->entries_committed());
    }

    public function test_a_commit_refuses_an_unknown_stage_and_a_url(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        try {
            $run->commit('nowhere', [], 1, 1100);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertSame('prepare', run_record::active()->stage());
        }
        $this->expectException(\coding_exception::class);
        $run->commit('files', ['tosend' => [['path' => 'https://example.com/a']]], 1, 1100);
    }

    public function test_the_server_of_a_run_is_stored_and_cleared(): void {
        $this->resetAfterTest();
        $run = run_record::create('schedule', 1000);
        $run->set_hostname(str_repeat('h', 300));
        $this->assertSame(str_repeat('h', 255), run_record::active()->hostname());
        $run->set_hostname(null);
        $this->assertNull(run_record::active()->hostname());
    }

    public function test_the_window_misses_are_stored(): void {
        $this->resetAfterTest();
        $run = run_record::create('schedule', 1000);
        $run->set_window_misses(1);
        $this->assertSame(1, run_record::active()->window_misses());
    }

    public function test_a_paused_run_becomes_active_again(): void {
        $this->resetAfterTest();
        $run = run_record::create('schedule', 1000);
        $run->pause(1010, 2000);
        $this->assertSame(2000, run_record::active()->next_attempt());
        $run->activate(2001);
        $stored = run_record::active();
        $this->assertSame('active', $stored->status());
        $this->assertNull($stored->next_attempt());
    }

    public function test_a_refused_run_is_neither_active_nor_a_stop(): void {
        $this->resetAfterTest();
        run_record::create('schedule', 900)->abort('server_busy', 901);
        $run = run_record::create('manual', 1000);
        $run->refuse('run_limit_reached', 1001);
        $this->assertSame('refused', $run->status());
        $this->assertSame('run_limit_reached', $run->abort_code());
        $this->assertNull(run_record::active());
        $this->assertSame(1, run_record::consecutive_aborts());
        $this->assertSame($run->id(), run_record::recent(1)[0]->id());
    }

    public function test_the_time_when_a_refused_run_can_start_again_is_kept(): void {
        $this->resetAfterTest();
        $run = run_record::create('manual', 1000);
        $this->assertNull($run->retry_at());
        $run->refuse('run_limit_reached', 1001, 3600);
        $this->assertSame(4601, run_record::recent(1)[0]->retry_at());
        $other = run_record::create('manual', 2000);
        $other->refuse('contract_inactive', 2001);
        $this->assertNull(run_record::recent(1)[0]->retry_at());
    }

    public function test_the_note_of_a_stop_is_kept_with_the_progress(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->set_stage('files', ['cursor' => ['offset' => 5]], 1001);
        $run->abort('disk_low', 1002, ['cleared' => true, 'leftovers' => 0]);
        $this->assertSame(
            ['abort' => ['cleared' => true, 'leftovers' => 0], 'cursor' => ['offset' => 5]],
            run_record::recent(1)[0]->progress()
        );
    }

    public function test_a_run_is_found_by_its_id_at_the_service(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->set_run_uuid('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e11');
        $run->abort('disk_low', 1001);
        $this->assertSame($run->id(), run_record::find_by_uuid('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e11')->id());
        $this->assertNull(run_record::find_by_uuid('0b6f1b0a-6d0e-4f3e-9b0e-6a3d3a0c5e12'));
    }

    public function test_a_run_is_read_again_from_the_table(): void {
        global $DB;
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $other = run_record::active();
        $other->commit('files', ['cursor' => ['offset' => 9]], 4, 1001);
        $other->pause(1002, 2000);
        $run->reload();
        $this->assertSame('files', $run->stage());
        $this->assertSame('paused', $run->status());
        $this->assertSame(4, $run->entries_committed());
        $this->assertSame(['cursor' => ['offset' => 9]], $run->progress());
        $DB->delete_records('tool_secbackup_runs', ['id' => $run->id()]);
        $this->expectException(\dml_missing_record_exception::class);
        $run->reload();
    }

    public function test_a_stale_record_never_changes_a_run_that_ended(): void {
        $this->resetAfterTest();
        $stale = run_record::create('cli', 1000);
        $fresh = run_record::active();
        $fresh->finish(1100);
        $this->assertFalse($stale->is_live());
        $this->assertFalse($stale->abort('disk_low', 1200));
        $this->assertFalse($stale->pause(1200, 1300));
        $this->assertFalse($stale->refuse('contract_inactive', 1200));
        $this->assertFalse($stale->finish(1200));
        $this->assertFalse($stale->activate(1200));
        $stored = run_record::recent(1)[0];
        $this->assertSame('completed', $stored->status());
        $this->assertNull($stored->abort_code());
        $this->assertSame('completed', $stale->status(), 'The stale copy learns the stored status.');
    }

    public function test_a_live_run_changes_from_the_states_that_allow_it(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $this->assertTrue($run->is_live());
        $this->assertFalse($run->activate(1001), 'An active run is not activated.');
        $this->assertTrue($run->pause(1002, 1100));
        $this->assertTrue($run->pause(1003, 1200));
        $this->assertTrue($run->activate(1004));
        $this->assertTrue($run->abort('disk_low', 1005));
        $this->assertFalse($run->is_live());
    }

    public function test_values_are_kept_without_moving_the_commit(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        $run->commit('files', ['run' => ['a' => 1, 'b' => 2], 'cursor' => ['offset' => 9]], 4, 1001);
        $run->keep(['b' => 3, 'c' => true]);
        $stored = run_record::active();
        $this->assertSame(['run' => ['b' => 3, 'c' => true, 'a' => 1], 'cursor' => ['offset' => 9]], $stored->progress());
        $this->assertSame('files', $stored->stage());
        $this->assertSame(4, $stored->entries_committed());
        $this->expectException(\coding_exception::class);
        $run->keep(['address' => 'https://example.com/']);
    }

    public function test_what_a_backup_does_not_hold_is_read_from_its_progress(): void {
        $this->resetAfterTest();
        $run = run_record::create('cli', 1000);
        // A run that did not get to its list.
        $this->assertNull($run->not_backed_up());
        $run->keep(['notbackedup' => 'none']);
        $this->assertNull(run_record::active()->not_backed_up());
        $counts = ['missing' => 2, 'unreadable' => 1, 'skippedlinks' => 0, 'secret' => 3];
        $run->keep(['notbackedup' => $counts]);
        $this->assertSame($counts, run_record::active()->not_backed_up());
        // A count that is missing or not a whole number reads as none.
        $run->keep(['notbackedup' => ['missing' => '2', 'unreadable' => 1.5, 'secret' => 4]]);
        $expected = ['missing' => 0, 'unreadable' => 0, 'skippedlinks' => 0, 'secret' => 4];
        $this->assertSame($expected, run_record::active()->not_backed_up());
    }
}
