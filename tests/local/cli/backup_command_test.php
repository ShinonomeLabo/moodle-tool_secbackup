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

use tool_secbackup\local\backup\core_diff;
use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\backup\runner;
use tool_secbackup\local\crypto\dedup_key;
use tool_secbackup\tests\run_fixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\cli\backup_command::class)]
/**
 * Tests for the backup on the command line: its exit codes and what it prints.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\cli\backup_command
 */
final class backup_command_test extends \advanced_testcase {
    /** @var run_fixture The site and the service. */
    private run_fixture $fixture;

    /** @var int[] What the command waited, in seconds. */
    private array $slept = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->fixture = new run_fixture(run_fixture::tokyo('2026-10-19 12:00'));
    }

    /**
     * Runs the command and collects its lines.
     *
     * @param bool $now The option --now.
     * @param bool $quiet The option --quiet.
     * @param array $seams Seams of the runner that replace those of a small site.
     * @param callable|null $verdict The watch of the starter; a calm one when null.
     * @return array [exit code, lines]
     */
    private function run_command(bool $now, bool $quiet = false, array $seams = [], ?callable $verdict = null): array {
        $runner = new runner(
            $this->fixture->client(),
            $this->fixture->objects(),
            $this->fixture->clock(),
            ['windows' => false] + $this->fixture->small_seams($seams)
        );
        $command = new backup_command(
            $runner,
            $this->fixture->clock(),
            function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->fixture->advance($seconds);
            },
            $verdict ?? static fn(): ?string => null
        );
        $lines = [];
        $code = $command->run($now, $quiet, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        return [$code, $lines];
    }

    public function test_without_a_running_backup_and_without_now_nothing_is_run(): void {
        [$code, $lines] = $this->run_command(false);
        $this->assertSame(2, $code);
        $this->assertContains(get_string('cli_backup_none', 'tool_secbackup'), $lines);
        $this->assertSame([], run_record::recent(1));
        $this->assertSame([], $this->fixture->requests);
    }

    public function test_now_starts_a_backup_out_of_the_window_and_runs_it_to_its_receipt(): void {
        [$code, $lines] = $this->run_command(true);
        $this->assertSame(0, $code);
        $this->assertSame(get_string('cli_backup_heading', 'tool_secbackup'), $lines[0]);
        $this->assertSame(get_string('trademark', 'tool_secbackup'), $lines[1]);
        $this->assertContains(get_string('cli_backup_started', 'tool_secbackup'), $lines);
        $this->assertSame(get_string('cli_backup_done', 'tool_secbackup'), end($lines));
        $run = run_record::recent(1)[0];
        $this->assertSame('cli', $run->trigger());
        $this->assertSame('completed', $run->status());
        // The receipt is asked for 30 seconds after the report: the command waits for it.
        $this->assertSame([30], $this->slept);
        $this->assertNotEmpty(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Waiting until')));
    }

    public function test_a_running_backup_goes_on(): void {
        run_record::create('manual', $this->fixture->time);
        [$code, $lines] = $this->run_command(false);
        $this->assertSame(0, $code);
        $this->assertContains(get_string('cli_backup_continued', 'tool_secbackup'), $lines);
        $this->assertSame('completed', run_record::recent(1)[0]->status());
    }

    public function test_quiet_prints_the_outcome_only(): void {
        [$code, $lines] = $this->run_command(true, true);
        $this->assertSame(0, $code);
        $this->assertSame([get_string('cli_backup_done', 'tool_secbackup')], $lines);
    }

    public function test_a_stopped_backup_exits_with_1_and_names_the_reason(): void {
        [$code, $lines] = $this->run_command(true, false, ['sample' => static fn(): array => ['load_per_core' => 9.0]]);
        $this->assertSame(1, $code);
        $this->assertSame(
            get_string('cli_backup_aborted', 'tool_secbackup', get_string('server_busy', 'tool_secbackup')),
            end($lines)
        );
    }

    public function test_a_reason_without_a_text_of_its_own_is_named_by_its_code(): void {
        $this->fixture->intercept = function (\Psr\Http\Message\RequestInterface $request) {
            return preg_match('~/runs/[0-9a-f-]{36}\z~', $request->getUri()->getPath()) === 1
                ? new \GuzzleHttp\Psr7\Response(200, [], json_encode(['run_id' => explode('/runs/', $request->getUri()
                    ->getPath())[1], 'status' => 'failed', 'failure_code' => 'something_new', 'receipt' => null]))
                : null;
        };
        [$code, $lines] = $this->run_command(true);
        $this->assertSame(1, $code);
        $this->assertSame(get_string(
            'cli_backup_aborted',
            'tool_secbackup',
            get_string('abort_unknown', 'tool_secbackup', 'something_new')
        ), end($lines));
    }

    public function test_a_backup_that_the_service_refuses_exits_with_2(): void {
        $this->fixture->service->state['contractinactive'] = true;
        [$code, $lines] = $this->run_command(true);
        $this->assertSame(2, $code);
        $this->assertSame(get_string(
            'cli_backup_refused',
            'tool_secbackup',
            get_string('api_contract_inactive', 'tool_secbackup')
        ), end($lines));
    }

    public function test_a_backup_that_cannot_start_exits_with_2(): void {
        [$code, $lines] = $this->run_command(true, false, [], static fn(): ?string => 'server_busy');
        $this->assertSame(2, $code);
        $this->assertSame(get_string(
            'cli_backup_notstarted',
            'tool_secbackup',
            get_string('start_reason_busy', 'tool_secbackup')
        ), end($lines));
        $this->assertSame([], run_record::recent(1));
    }

    public function test_a_step_that_fails_unexpectedly_exits_with_1_and_names_the_class_only(): void {
        $broken = static function (): core_diff {
            throw new \RuntimeException('secret detail');
        };
        [$code, $lines] = $this->run_command(true, false, ['corediff' => $broken]);
        $this->assertSame(1, $code);
        $this->assertSame(get_string('cli_backup_failed', 'tool_secbackup', 'RuntimeException'), end($lines));
        $this->assertSame('active', run_record::active()->status());
    }

    public function test_a_backup_whose_files_are_on_another_server_is_not_run_here(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $run->set_hostname('web9');
        [$code, $lines] = $this->run_command(false);
        $this->assertSame(2, $code);
        $this->assertSame(get_string('cli_backup_otherserver', 'tool_secbackup', 'web9'), end($lines));
        $this->assertSame([], $this->fixture->requests);
    }

    public function test_while_another_process_steps_the_backup_the_command_does_nothing(): void {
        global $CFG;
        run_record::create('manual', $this->fixture->time);
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $lock = \core\lock\lock_config::get_lock_factory('tool_secbackup_run')->get_lock('step', 0);
        try {
            [$code, $lines] = $this->run_command(false);
        } finally {
            $lock->release();
        }
        $this->assertSame(2, $code);
        $this->assertSame(get_string('cli_backup_locked', 'tool_secbackup'), end($lines));
        $this->assertSame([], $this->fixture->requests);
    }

    public function test_the_output_holds_no_address_and_no_key(): void {
        [$code, $lines] = $this->run_command(true);
        $this->assertSame(0, $code);
        $output = implode("\n", $lines);
        $secrets = [
            'http',
            'X-Amz',
            'example.test',
            base64_encode($this->fixture->pair->public_key()),
            base64_encode(dedup_key::load_or_create()),
            bin2hex(dedup_key::load_or_create()),
            run_record::recent(1)[0]->run_uuid(),
        ];
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_the_help_has_the_heading_and_the_trademark_notice_first(): void {
        $lines = [];
        (new backup_command())->help(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame([
            get_string('cli_backup_heading', 'tool_secbackup'),
            get_string('trademark', 'tool_secbackup'),
            get_string('cli_backup_help', 'tool_secbackup'),
        ], $lines);
    }
}
