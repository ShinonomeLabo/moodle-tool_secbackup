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
        $this->assertNotEmpty($row->hostname);
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
}
