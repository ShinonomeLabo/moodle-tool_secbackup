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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\network_fakes;
use tool_secbackup\tests\system_roots;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\cli\diagnose_command::class)]
/**
 * Tests for the diagnostics command.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\cli\diagnose_command
 */
final class diagnose_command_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * Runs the command and collects its lines.
     *
     * @param string $endpoint The --endpoint option.
     * @param array $answers Answers of the mocked network.
     * @param array $extensions Required extensions.
     * @param string|null $root Root of the system files; null for an empty one.
     * @return array [exit code, lines]
     */
    private static function run_command(
        string $endpoint,
        array $answers,
        array $extensions = ['sodium'],
        ?string $root = null
    ): array {
        $settings = developer_settings::from_value(null);
        $guard = network_fakes::passing_guard();
        $runner = new runner(
            $settings,
            $guard,
            new transport($settings, $guard, new MockHandler($answers)),
            new system_files($root ?? make_request_directory()),
            $extensions
        );
        $lines = [];
        $code = (new diagnose_command($runner))->run($endpoint, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        return [$code, $lines];
    }

    /**
     * Registers the site.
     */
    private static function register(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            self::API,
            1760000000,
            0
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
    }

    public function test_typed_url_without_problems(): void {
        [$code, $lines] = self::run_command(self::API, [new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $this->assertSame(0, $code);
        $this->assertSame(get_string('diag_cli_heading', 'tool_secbackup'), $lines[0]);
        $this->assertSame(get_string('trademark', 'tool_secbackup'), $lines[1]);
        $this->assertSame(get_string('diag_cli_endpoint', 'tool_secbackup', self::API), $lines[2]);
        $expected = '[' . get_string('result_pass', 'tool_secbackup') . '] ' . get_string('check_connection', 'tool_secbackup')
            . ': ' . get_string('diag_connection_ok', 'tool_secbackup', 401);
        $this->assertContains($expected, $lines);
    }

    public function test_one_line_per_check_in_order(): void {
        [, $lines] = self::run_command(self::API, [new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $checks = array_map(
            static fn(string $name): string => get_string('check_' . $name, 'tool_secbackup'),
            ['developer', 'extensions', 'proxy', 'dns', 'connection', 'clock', 'selinux', 'apparmor', 'moodledata']
        );
        $found = [];
        foreach (array_slice($lines, 3) as $line) {
            foreach ($checks as $check) {
                if (str_contains($line, '] ' . $check . ': ')) {
                    $found[] = $check;
                }
            }
        }
        $this->assertSame($checks, $found);
    }

    public function test_problem_gives_exit_code_1_and_advice(): void {
        [$code, $lines] = self::run_command(
            self::API,
            [new Response(401, [], '{}')],
            ['no_such_extension']
        );
        $this->assertSame(1, $code);
        $this->assertContains(
            '    ' . get_string('diag_cli_advice', 'tool_secbackup', get_string('diag_extensions_advice', 'tool_secbackup')),
            $lines
        );
        $this->assertContains(
            '[' . get_string('result_fail', 'tool_secbackup') . '] ' . get_string('check_extensions', 'tool_secbackup')
                . ': ' . get_string('diag_extensions_missing', 'tool_secbackup', 'no_such_extension'),
            $lines
        );
    }

    public function test_a_check_without_advice_has_no_advice_line(): void {
        [, $lines] = self::run_command(self::API, [new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $this->assertCount(3 + 9, $lines, 'The heading, the trademark notice, the connection URL and one line for each check.');
        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('    ', $line);
        }
    }

    public function test_a_warning_is_not_a_problem(): void {
        // Another service answers: a warning with no way to tell if the plugin is at fault, so the exit code stays 0.
        [$code, $lines] = self::run_command(self::API, [new Response(404, [], '')]);
        $this->assertSame(0, $code);
        $this->assertContains(
            '[' . get_string('result_warn', 'tool_secbackup') . '] ' . get_string('check_connection', 'tool_secbackup')
                . ': ' . get_string('diag_connection_other', 'tool_secbackup', 404),
            $lines
        );
    }

    public function test_a_failed_check_after_a_passed_one_still_gives_exit_code_1(): void {
        $guard = network_fakes::guard(null, [], ['13.225.9.9']);
        $settings = developer_settings::from_value(null);
        $runner = new runner(
            $settings,
            $guard,
            new transport($settings, $guard, new MockHandler([])),
            new system_files(make_request_directory())
        );
        $lines = [];
        $code = (new diagnose_command($runner))->run(self::API, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame(1, $code);
        $this->assertContains(
            '    ' . get_string('diag_cli_advice', 'tool_secbackup', get_string('dns_not_found', 'tool_secbackup')),
            $lines
        );
    }

    public function test_wrong_url(): void {
        [$code, $lines] = self::run_command('ftp://app.example.test/api', []);
        $this->assertSame(1, $code);
        $this->assertSame([get_string('endpoint_scheme', 'tool_secbackup')], $lines);
    }

    public function test_registered_site(): void {
        self::register();
        [$code, $lines] = self::run_command('', [new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $this->assertSame(0, $code);
        $this->assertSame(get_string('diag_cli_endpoint', 'tool_secbackup', self::API), $lines[2]);
    }

    public function test_a_typed_url_wins_over_the_registered_one(): void {
        self::register();
        $other = 'https://other.example.test/api/lms-backup/v1';
        [$code, $lines] = self::run_command($other, [new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $this->assertSame(get_string('diag_cli_endpoint', 'tool_secbackup', $other), $lines[2]);
    }

    public function test_unregistered_site_without_a_url(): void {
        [$code, $lines] = self::run_command('', []);
        $this->assertSame(0, $code);
        $this->assertSame(get_string('diag_cli_noendpoint', 'tool_secbackup'), $lines[2]);
    }

    public function test_a_registered_url_that_no_longer_reads_is_a_problem(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            'http://localhost:8099/api/lms-backup/v1',
            1760000000,
            0
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
        [$code, $lines] = self::run_command('', []);
        $this->assertSame(1, $code);
        $this->assertSame([get_string('endpoint_scheme', 'tool_secbackup')], $lines);
    }

    public function test_the_texts_of_the_system_are_printed_as_they_are(): void {
        [, $lines] = self::run_command('', [], ['sodium'], system_roots::with_markup());
        $text = implode("\n", $lines);
        $this->assertStringContainsString('<i>x</i>', $text);
        $this->assertStringContainsString('<u>C&D</u>', $text);
        $this->assertStringContainsString('<b>A&B</b>', $text);
        $this->assertStringNotContainsString('&lt;', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_the_apparmor_profile_name_is_printed_as_it_is(): void {
        $root = system_roots::with_files(['/proc/self/attr/apparmor/current' => "<u>C&D</u> (enforce)\n"]);
        [, $lines] = self::run_command('', [], ['sodium'], $root);
        $text = implode("\n", $lines);
        $this->assertStringContainsString(get_string('diag_apparmor_enforcing', 'tool_secbackup', '<u>C&D</u>'), $text);
        $this->assertStringNotContainsString('&lt;', $text);
    }

    /**
     * Outputs of the command: the help, a run whose advice names Moodle, and a run without a problem.
     *
     * @return array
     */
    public static function output_provider(): array {
        return [
            'the help' => ['help'],
            'a run where DNS over HTTPS does not answer (the advice names Moodle)' => ['nodoh'],
            'a run without a problem' => ['plain'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('output_provider')]
    /**
     * The command prints its heading and the trademark notice first: the first line that mentions Moodle is the notice, as
     * the pages show it with their introduction.
     *
     * @dataProvider output_provider
     * @param string $output Which output.
     */
    public function test_the_first_mention_of_moodle_is_the_trademark_notice(string $output): void {
        $lines = [];
        $write = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        if ($output === 'help') {
            (new diagnose_command())->help($write);
            $this->assertSame([
                get_string('diag_cli_heading', 'tool_secbackup'),
                get_string('trademark', 'tool_secbackup'),
                get_string('cli_diagnose_help', 'tool_secbackup'),
            ], $lines);
        } else {
            $guard = $output === 'nodoh' ? network_fakes::guard(null, null, ['13.225.9.9']) : network_fakes::passing_guard();
            $settings = developer_settings::from_value(null);
            $answers = [new Response(401, [], '{"error":{"code":"signature_required"}}')];
            $runner = new runner(
                $settings,
                $guard,
                new transport($settings, $guard, new MockHandler($answers)),
                new system_files(make_request_directory())
            );
            (new diagnose_command($runner))->run(self::API, $write);
            $advice = get_string('diag_cli_advice', 'tool_secbackup', get_string('dns_doh_unavailable', 'tool_secbackup'));
            $this->assertSame($output === 'nodoh', in_array('    ' . $advice, $lines, true));
        }
        $this->assertSame(get_string('diag_cli_heading', 'tool_secbackup'), $lines[0]);
        $mentions = array_keys(array_filter($lines, static fn(string $line): bool => str_contains($line, 'Moodle')));
        $this->assertSame(1, $mentions[0] ?? null, 'The first line that mentions Moodle is the second one.');
        $this->assertSame(get_string('trademark', 'tool_secbackup'), $lines[1]);
    }

    public function test_default_runner(): void {
        $lines = [];
        (new diagnose_command())->run('', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame(get_string('diag_cli_heading', 'tool_secbackup'), $lines[0]);
    }
}
