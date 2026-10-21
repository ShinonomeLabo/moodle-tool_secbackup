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

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\local\backup\files\file_source;
use tool_secbackup\local\backup\files\packer;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\dedup_key;
use tool_secbackup\local\crypto\key_exception;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;
use tool_secbackup\tests\run_fixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\runner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\run_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\stage\db_stage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\stage\files_stage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\stage\tree_stage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\stage\artifact_stage::class)]
/**
 * Tests for a whole run against the fake service and its storage: the stages, the way a run goes on after a stop, the window,
 * the watch, the temporary files and the receipt.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\runner
 * @covers     \tool_secbackup\local\backup\run_context
 * @covers     \tool_secbackup\local\backup\stage\db_stage
 * @covers     \tool_secbackup\local\backup\stage\files_stage
 * @covers     \tool_secbackup\local\backup\stage\tree_stage
 * @covers     \tool_secbackup\local\backup\stage\artifact_stage
 */
final class runner_test extends \advanced_testcase {
    /** @var array A sample of the watch in which nothing is wrong. */
    private const CALM = [
        'load_per_core' => 0.1,
        'memory_available_bytes' => 8589934592,
        'db_ms' => 1.0,
        'disk_free_bytes' => 107374182400,
        'disk_total_bytes' => 214748364800,
        'nfs_mbps' => 100.0,
        'not_applicable' => [],
    ];

    /** @var string The site of the fake service. */
    private const SITE = fake_service::SITE_ID;

    /** @var run_fixture The site and the service. */
    private run_fixture $fixture;

    /** @var string Folder of the temporary files. */
    private string $tempdir;

    /** @var string The data root that the run walks. */
    private string $dataroot;

    /** @var string The folder of the added plugin that the run sends. */
    private string $plugin;

    /** @var string The folder of the Moodle program that the run compares and sends. */
    private string $core;

    /** @var string[] Content hash of each file of the site, by name. */
    private array $hashes = [];

    /** @var int[] What the tests waited, in seconds. */
    private array $slept = [];

    /** @var string[] The lines of the log. */
    private array $logged = [];

    /** @var float The timer that the tests move. */
    private float $tick = 0.0;

    /** @var string[] What the steps of the last finish() returned. */
    private array $results = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The export reads through a connection of its own, which sees only what is committed.
        $this->preventResetByRollback();
        $this->fixture = new run_fixture(run_fixture::tokyo('2026-10-19 03:00'));
        $this->tempdir = make_request_directory();
        $this->make_site();
    }

    protected function tearDown(): void {
        // PHPUnit 9 keeps every test case until the end of the run: what the fake storage holds goes with its test.
        unset($this->fixture);
        parent::tearDown();
    }

    /**
     * Makes the content of the site: files of the file store, a data root, an added plugin and a Moodle program.
     */
    private function make_site(): void {
        $fs = get_file_storage();
        $where = ['contextid' => \context_system::instance()->id, 'component' => 'tool_secbackup', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/'];
        $contents = [
            'small1' => str_repeat('a', 1000),
            'small2' => str_repeat('b', 2000),
            'small3' => str_repeat('c', 3000),
            'copy' => str_repeat('a', 1000),
            'big' => random_bytes(packer::FILE_LIMIT + 1048576),
            'missing' => 'content that is not in the file store any more',
        ];
        foreach ($contents as $name => $content) {
            $file = $fs->create_file_from_string($where + ['filename' => $name . '.bin'], $content);
            $this->hashes[$name] = $file->get_contenthash();
        }
        global $CFG;
        $hash = $this->hashes['missing'];
        unlink($CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash);
        $this->dataroot = make_request_directory();
        mkdir($this->dataroot . '/lang/ja', 0777, true);
        file_put_contents($this->dataroot . '/lang/ja/langconfig.php', 'lang');
        mkdir($this->dataroot . '/secret');
        file_put_contents($this->dataroot . '/secret/key', 'key');
        symlink($this->dataroot . '/lang', $this->dataroot . '/link');
        $this->plugin = make_request_directory();
        file_put_contents($this->plugin . '/version.php', 'plugin version');
        file_put_contents($this->plugin . '/lib.php', 'plugin lib');
        $this->core = make_request_directory();
        file_put_contents(
            $this->core . '/version.php',
            "<?php\n\$version  = 2024100703.10;\n\$release  = '4.5.3+ (Build: 20250113)';\n"
        );
        file_put_contents($this->core . '/index.php', 'core index');
        mkdir($this->core . '/lib');
        file_put_contents($this->core . '/lib/a.php', 'core a');
    }

    /**
     * The limits of the tests.
     *
     * @param int $budgetmb Budget of the temporary files in MB.
     * @param int $bandwidth Bandwidth setting of this site in Mbps.
     * @param string|null $tempdir Folder of the temporary files; the one of the test when null.
     * @return limits
     */
    private function limits(int $budgetmb = 100, int $bandwidth = 0, ?string $tempdir = null): limits {
        return new limits(2.0, 256, 500, 5.0, $tempdir ?? $this->tempdir, $budgetmb, $bandwidth);
    }

    /**
     * The seams of the tests.
     *
     * @return array
     */
    private function seams(): array {
        global $DB;
        $tables = $DB->get_manager()->get_install_xml_schema();
        return [
            'limits' => $this->limits(),
            'sample' => fn(): array => self::CALM,
            'sleep' => function (int $seconds): void {
                $this->slept[] = $seconds;
            },
            'hostname' => fn(): string => 'web1',
            'link' => $this->fixture->link(),
            'trust' => network_fakes::trust(),
            'files' => new system_files(make_request_directory()),
            'log' => function (string $line): void {
                $this->logged[] = $line;
            },
            'tables' => fn(bool $logs): array => [
                'export' => [$tables->getTable('course'), $tables->getTable('files'), $tables->getTable('user')],
                'unknown' => ['mystery'],
                'excluded' => ['sessions'],
            ],
            'datarootroots' => fn(): array => ['dataroot' => [$this->dataroot, []]],
            'coderoots' => fn(): array => ['local_example' => $this->plugin],
            'corediff' => fn(): core_diff => new core_diff($this->core, []),
            'external' => fn(): bool => false,
        ];
    }

    /**
     * A runner with the seams of the tests.
     *
     * @param array $seams Seams that replace those of the tests.
     * @return runner
     */
    private function runner(array $seams = []): runner {
        return new runner($this->fixture->client(), $this->fixture->objects(), $this->fixture->clock(), $seams + $this->seams());
    }

    /**
     * A timer that says that the budget is used after every unit of work.
     *
     * @return callable
     */
    private function fast_timer(): callable {
        return function (): float {
            $this->tick += 2.0;
            return $this->tick;
        };
    }

    /**
     * Steps a run until it ends, moving the clock to every time that the run waits for.
     *
     * @param runner $runner The runner.
     * @param run_record $run The run.
     * @param int $budget Seconds of each step.
     * @return string What the last step returned: done or aborted. The results of all steps are in $results.
     */
    private function finish(runner $runner, run_record $run, int $budget = 600): string {
        $this->results = [];
        for ($i = 0; $i < 500; $i++) {
            $result = $runner->step($run, $budget);
            $this->results[] = $result;
            if ($result === 'done' || $result === 'aborted') {
                return $result;
            }
            $next = $run->next_attempt();
            if ($next !== null && $next > $this->fixture->time) {
                $this->fixture->set_time($next);
            }
        }
        $this->fail('The run did not end: ' . $run->stage());
    }

    /**
     * An object of the fake storage.
     *
     * @param string $key Key under the site.
     * @return string|null
     */
    private function stored(string $key): ?string {
        $objects = $this->fixture->service->state['objects'];
        $full = 'sites/' . self::SITE . '/' . $key;
        return isset($objects[$full]) ? (string) base64_decode($objects[$full]) : null;
    }

    /**
     * The chunks in the fake storage, by name.
     *
     * @return int[] Size by name.
     */
    private function stored_chunks(): array {
        $prefix = 'sites/' . self::SITE . '/objects/';
        $chunks = [];
        foreach ($this->fixture->service->state['objects'] as $key => $body) {
            if (str_starts_with($key, $prefix)) {
                $chunks[substr($key, strlen($prefix))] = strlen((string) base64_decode($body));
            }
        }
        ksort($chunks);
        return $chunks;
    }

    /**
     * The contents of the file store that a backup holds: every content hash of a file of 1 byte or more, but the missing one.
     *
     * @return string[] Sorted.
     */
    private function stored_contents(): array {
        global $DB;
        $hashes = $DB->get_fieldset_sql('SELECT DISTINCT contenthash FROM {files} WHERE filesize > 0');
        $hashes = array_values(array_diff($hashes, [$this->hashes['missing']]));
        sort($hashes);
        return $hashes;
    }

    /**
     * The index of a run, as stored.
     *
     * @param string $runid The run.
     * @return array [header, size by name]
     */
    private function index(string $runid): array {
        $lines = explode("\n", rtrim(gzdecode($this->stored('indexes/' . $runid)), "\n"));
        $header = json_decode(array_shift($lines), true);
        $sizes = [];
        foreach ($lines as $line) {
            [$name, $size] = explode(' ', $line);
            $sizes[$name] = (int) $size;
        }
        return [$header, $sizes];
    }

    /**
     * The list of the generation of a run, decrypted.
     *
     * @param string $runid The run.
     * @return array [header, entries, last line]
     */
    private function manifest(string $runid): array {
        $path = make_request_directory() . '/manifest';
        file_put_contents($path, $this->stored('manifests/' . $runid));
        $reader = new chunk_reader(
            $path,
            sodium_crypto_box_secretkey($this->fixture->boxpair),
            sodium_crypto_box_publickey($this->fixture->boxpair),
            self::SITE,
            chunk_name::manifest($runid)
        );
        $this->assertSame(chunk_writer::TYPE_MANIFEST, $reader->type());
        $lines = explode("\n", rtrim(gzdecode($reader->read_item(chunk_writer::HEADER)), "\n"));
        $header = json_decode(array_shift($lines), true);
        $last = json_decode(array_pop($lines), true);
        return [$header, array_map(static fn(string $line): array => json_decode($line, true), $lines), $last];
    }

    public function test_a_run_goes_through_every_stage_to_a_verified_receipt(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $run));
        $this->assertSame('completed', $run->status());
        $this->assertSame('done', $run->stage());
        $runid = $run->run_uuid();
        $service = $this->fixture->service->state['runs'][$runid];
        $this->assertSame('completed', $service['status']);
        $this->assertNotNull($service['receipt']);
        // Every chunk was sent once, and the index names exactly the chunks in the storage.
        foreach ($this->fixture->puts() as $key => $count) {
            $this->assertSame(1, $count, $key);
        }
        [$header, $sizes] = $this->index($runid);
        $this->assertSame(['v' => 1, 'site_id' => self::SITE, 'run_id' => $runid, 'count' => count($sizes)], $header);
        $this->assertSame($this->stored_chunks(), $sizes);
        $this->assertSame(count($sizes), $service['index']['count']);
        // The list of the generation.
        [$header, $entries, $last] = $this->manifest($runid);
        $this->assertSame(['end' => true, 'entries' => count($entries)], $last);
        $this->assertSame(1, $header['v']);
        $this->assertSame(self::SITE, $header['site_id']);
        $this->assertSame($runid, $header['run_id']);
        $this->assertSame('full', $header['kind']);
        $this->assertSame(['course', 'files', 'user'], $header['db']['tables']);
        $this->assertSame(['mystery'], $header['db']['unknown_tables']);
        $this->assertSame(['sessions'], $header['db']['excluded_tables']);
        $this->assertFalse($header['core_diff']['official']);
        $this->assertSame([$this->hashes['missing']], $header['missing_files']);
        $this->assertSame(['dataroot:link'], $header['skipped_links']);
        $this->assertSame([], $header['unreadable']);
        $this->assertArrayHasKey('config', $header);
        $this->assertArrayHasKey('plugins', $header);
        $this->assertSame('20250113', $header['moodle']['build']);
        $byt = [];
        foreach ($entries as $entry) {
            $byt[$entry['t']][] = $entry;
            $this->assertArrayHasKey($entry['chunk'], $sizes, 'Every entry refers to a chunk of the index.');
        }
        $this->assertSame(['course', 'files', 'user'], array_values(array_unique(array_column($byt['db'], 'table'))));
        $files = array_values(array_unique(array_column($byt['file'], 'contenthash')));
        sort($files);
        $this->assertSame($this->stored_contents(), $files);
        foreach (['small1', 'small2', 'small3', 'big'] as $name) {
            $this->assertContains($this->hashes[$name], $files);
        }
        $this->assertEqualsCanonicalizing(
            ['dataroot:lang/ja/langconfig.php', 'dataroot:secret/key'],
            array_map(static fn(array $e): string => $e['root'] . ':' . $e['path'], $byt['dataroot'])
        );
        $this->assertEqualsCanonicalizing(
            ['core:index.php', 'core:lib/a.php', 'core:version.php', 'local_example:lib.php', 'local_example:version.php'],
            array_map(static fn(array $e): string => $e['root'] . ':' . $e['path'], $byt['code'])
        );
        // No entry twice, and the temporary files are gone.
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
        $this->assertDirectoryDoesNotExist($this->tempdir . '/run-' . $run->id());
        $this->assertSame([], glob($this->tempdir . '/present-*') ?: []);
    }

    public function test_a_budget_of_one_second_gives_the_same_backup(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(['timer' => $this->fast_timer()]), $run, 1));
        $this->assertGreaterThan(20, count($this->results));
        [$header, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
        [, $entries, $last] = $this->manifest($run->run_uuid());
        $this->assertSame(['end' => true, 'entries' => count($entries)], $last);
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
        $kinds = array_count_values(array_column($entries, 't'));
        $this->assertSame(3, count(array_unique(array_column(array_filter($entries, fn($e) => $e['t'] === 'db'), 'table'))));
        $files = array_values(array_unique(array_column(array_filter($entries, fn($e) => $e['t'] === 'file'), 'contenthash')));
        sort($files);
        $this->assertSame($this->stored_contents(), $files);
        $this->assertSame(2, $kinds['dataroot']);
        $this->assertSame(5, $kinds['code']);
        foreach ($this->fixture->puts() as $key => $count) {
            $this->assertSame(1, $count, $key);
        }
    }

    public function test_a_step_that_stops_after_the_database_goes_on_without_sending_twice(): void {
        global $DB;
        $run = run_record::create('manual', $this->fixture->time);
        $broken = fn(\moodle_database $db): file_source => new class ($db) extends file_source {
            /**
             * Fails as a step that is stopped does.
             *
             * @param string $contenthash Content hash.
             * @param int $size Size.
             * @param packer $packer Packer.
             * @return array|null
             */
            public function pack(string $contenthash, int $size, packer $packer): ?array {
                throw new \RuntimeException('The process was stopped.');
            }
        };
        $first = $this->runner(['filesource' => $broken]);
        $this->assertSame('continue', $first->step($run, 600));
        $this->assertSame('files', $run->stage());
        // The next step waits five minutes.
        $this->assertSame('paused', $run->status());
        $this->assertSame($this->fixture->time + runner::RETRY_AFTER, $run->next_attempt());
        $this->assertSame(['The backup step failed and is tried again: RuntimeException'], $this->logged);
        $this->assertSame('RuntimeException', $first->last_error());
        $committed = $run->entries_committed();
        $this->assertGreaterThan(0, $committed);
        // A new runner, as the next task has, goes on from the committed point.
        $this->assertSame('done', $this->finish($this->runner(), run_record::active()));
        foreach ($this->fixture->puts() as $key => $count) {
            $this->assertSame(1, $count, $key);
        }
        $runid = $DB->get_field('tool_secbackup_runs', 'runuuid', ['id' => $run->id()]);
        [, $entries] = $this->manifest($runid);
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
        [, $sizes] = $this->index($runid);
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_step_that_stops_with_files_waiting_for_a_pack_does_them_again_once(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $calls = 0;
        $flaky = function (\moodle_database $db) use (&$calls): file_source {
            return new class ($db, $calls) extends file_source {
                /** @var int Calls so far, shared with the test. */
                private int $calls;

                /**
                 * Constructor.
                 *
                 * @param \moodle_database $db Connection.
                 * @param int $calls Calls so far, by reference.
                 */
                public function __construct(\moodle_database $db, int &$calls) {
                    parent::__construct($db);
                    $this->calls = &$calls;
                }

                /**
                 * Fails at the third file, while the first two wait for their pack.
                 *
                 * @param string $contenthash Content hash.
                 * @param int $size Size.
                 * @param packer $packer Packer.
                 * @return array|null
                 */
                public function pack(string $contenthash, int $size, packer $packer): ?array {
                    if (++$this->calls === 3) {
                        throw new \RuntimeException('Stopped.');
                    }
                    return parent::pack($contenthash, $size, $packer);
                }
            };
        };
        $runner = $this->runner(['filesource' => $flaky]);
        $this->assertSame('done', $this->finish($runner, $run), (string) $run->abort_code());
        $this->assertContains('continue', $this->results);
        $this->assertSame(['The backup step failed and is tried again: RuntimeException'], $this->logged);
        [, $entries] = $this->manifest($run->run_uuid());
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
        $files = array_column(array_filter($entries, fn($e) => $e['t'] === 'file'), 'contenthash');
        $this->assertSame(count($files), count(array_unique($files)));
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_step_that_stops_after_chunks_were_queued_makes_them_again_and_sends_them(): void {
        // A second big file: a content always follows the first chunk that is made.
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'tool_secbackup', 'filearea' => 'test', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'big2.bin'], random_bytes(packer::FILE_LIMIT + 10));
        $state = (object) ['armed' => false, 'thrown' => false];
        $flaky = fn(\moodle_database $db): file_source => new class ($db, $state) extends file_source {
            /** @var \stdClass Shared with the test: armed and thrown. */
            private \stdClass $state;

            /**
             * Constructor.
             *
             * @param \moodle_database $db Connection.
             * @param \stdClass $state Shared with the test: armed and thrown.
             */
            public function __construct(\moodle_database $db, \stdClass $state) {
                parent::__construct($db);
                $this->state = $state;
            }

            /**
             * Stops the step once, at the first file after chunks were made and queued.
             *
             * @param string $contenthash Content hash.
             * @param int $size Size.
             * @param packer $packer Packer.
             * @return array|null
             */
            public function pack(string $contenthash, int $size, packer $packer): ?array {
                $this->stop_once();
                $chunks = parent::pack($contenthash, $size, $packer);
                $this->state->armed = $this->state->armed || ($chunks !== null && $chunks !== []);
                return $chunks;
            }

            /**
             * Stops the step once, at the first piece after chunks were made and queued.
             *
             * @param string $contenthash Content hash.
             * @param int $size Size.
             * @param int $piece Number of the piece.
             * @param string $digest Its digest.
             * @param packer $packer Packer.
             * @return chunk_info|null
             */
            public function big_piece(string $contenthash, int $size, int $piece, string $digest, packer $packer): ?chunk_info {
                $this->stop_once();
                $chunk = parent::big_piece($contenthash, $size, $piece, $digest, $packer);
                $this->state->armed = true;
                return $chunk;
            }

            /**
             * Throws once, when chunks were made and queued before.
             */
            private function stop_once(): void {
                if ($this->state->armed && !$this->state->thrown) {
                    $this->state->thrown = true;
                    throw new \RuntimeException('Stopped.');
                }
            }
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(['filesource' => $flaky]), $run), (string) $run->abort_code());
        $this->assertTrue($state->thrown);
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_block_that_a_stopped_step_queued_and_the_next_step_made_otherwise_is_not_sent(): void {
        global $DB;
        $run = run_record::create('manual', $this->fixture->time);
        $stopped = false;
        // The watch after the first block of the database (the table course) stops the step, as a failure of the process does.
        $sample = function () use ($run, &$stopped): array {
            if (!$stopped && $run->stage() === 'db') {
                $stopped = true;
                throw new \RuntimeException('The process was stopped.');
            }
            return self::CALM;
        };
        $this->assertSame('continue', $this->runner(['sample' => $sample])->step($run, 600));
        $this->assertTrue($stopped);
        $this->assertSame('db', $run->stage());
        $this->assertSame([], $this->stored_chunks(), 'Nothing was sent before the step stopped.');
        // The table changes before the next step: the block that the stopped step made is not the one of the next snapshot.
        $DB->set_field('course', 'fullname', 'Changed between the steps', ['id' => SITEID]);
        $this->assertSame('done', $this->finish($this->runner(), $run), (string) $run->abort_code());
        // Only the chunks of the index reach the storage: the block of the stopped step is removed, not sent.
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($sizes, $this->stored_chunks());
        $reserved = $this->fixture->service->state['runs'][$run->run_uuid()]['reserved'];
        $this->assertSame(array_sum($sizes) + strlen($this->stored('manifests/' . $run->run_uuid()))
            + strlen($this->stored('indexes/' . $run->run_uuid())), $reserved);
    }

    public function test_a_run_pauses_at_the_end_of_the_window_and_goes_on_in_the_next(): void {
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        while ($run->stage() !== 'files') {
            $this->assertSame('continue', $runner->step($run, 1));
        }
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:00'));
        $this->assertSame('paused', $runner->step($run, 1));
        $this->assertSame('paused', $run->status());
        $this->assertSame(1, $run->window_misses());
        $next = schedule::next_slot(settings::current(), self::SITE, run_fixture::tokyo('2026-10-19 05:00'));
        $this->assertSame($next['start'], $run->next_attempt());
        // Before the next window nothing happens.
        $requests = count($this->fixture->requests);
        $this->assertSame('paused', $runner->step($run, 1));
        $this->assertCount($requests, $this->fixture->requests);
        $this->fixture->set_time($next['start']);
        $this->assertSame('done', $this->finish($runner, $run, 1));
        $this->assertSame('completed', $run->status());
    }

    public function test_a_run_that_misses_two_windows_in_a_row_is_stopped(): void {
        // Started 5 minutes before the end of its window, the run sees the end of the next one within the 24 hours.
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 04:55'));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        while ($run->stage() !== 'db') {
            $runner->step($run, 1);
        }
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:00'));
        $this->assertSame('paused', $runner->step($run, 1));
        $this->fixture->set_time(run_fixture::tokyo('2026-10-20 05:01'));
        $this->assertSame('aborted', $runner->step($run, 1));
        $this->assertSame('window_ended', $run->abort_code());
        $this->assertSame('window_ended', $this->fixture->service->state['runs'][$run->run_uuid()]['failure_code']);
    }

    public function test_a_run_that_comes_back_after_a_whole_window_has_passed_is_stopped(): void {
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 04:55'));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $runner->step($run, 1);
        $this->fixture->set_time(run_fixture::tokyo('2026-10-20 05:01'));
        $this->assertSame('aborted', $runner->step($run, 1));
        $this->assertSame('window_ended', $run->abort_code());
    }

    public function test_a_run_that_comes_back_after_the_24_hours_of_the_service_is_stopped_as_expired(): void {
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $runner->step($run, 1);
        // Two windows have passed too; the service failed the run first, and its reason is kept.
        $this->fixture->set_time(run_fixture::tokyo('2026-10-20 12:00'));
        $this->assertSame('aborted', $runner->step($run, 1));
        $this->assertSame('expired', $run->abort_code());
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    public function test_the_command_line_does_not_stop_at_the_end_of_the_window(): void {
        $run = run_record::create('schedule', $this->fixture->time);
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 06:00'));
        $this->assertSame('done', $this->finish($this->runner(['windows' => false]), $run));
        $this->assertSame(0, $run->window_misses());
    }

    public function test_the_command_line_goes_on_with_a_run_that_waits_for_its_window(): void {
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $runner->step($run, 1);
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:00'));
        $this->assertSame('paused', $runner->step($run, 1));
        $this->assertSame('done', $this->finish($this->runner(['windows' => false]), $run));
    }

    public function test_a_watch_that_finds_the_server_busy_stops_the_run_and_keeps_what_was_sent(): void {
        $samples = 0;
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['sample' => function () use (&$samples): array {
            return ++$samples > 5 ? ['load_per_core' => 9.0] + self::CALM : self::CALM;
        }]);
        $this->assertSame('aborted', $this->finish($runner, $run));
        $this->assertSame('server_busy', $run->abort_code());
        $this->assertSame('server_busy', $this->fixture->service->state['runs'][$run->run_uuid()]['failure_code']);
        $this->assertSame(6, $samples);
        $this->assertDirectoryDoesNotExist($this->tempdir . '/run-' . $run->id());
    }

    public function test_the_chunks_that_were_sent_stay_when_the_run_stops(): void {
        $samples = 0;
        $run = run_record::create('manual', $this->fixture->time);
        $sent = [];
        $runner = $this->runner([
            'limits' => $this->limits(0),
            'diskspace' => fn(string $folder): array => [0, 1000],
            'sample' => function () use (&$samples, &$sent): array {
                $sent = $this->stored_chunks();
                return ++$samples > 4 ? ['memory_available_bytes' => 1024] + self::CALM : self::CALM;
            },
        ]);
        $this->assertSame('aborted', ($this->finish($runner, $run)));
        $this->assertNotEmpty($sent);
        foreach (array_keys($sent) as $name) {
            $this->assertArrayHasKey($name, $this->stored_chunks());
        }
    }

    public function test_values_that_cannot_be_read_make_the_run_wait_at_most_once_in_five_minutes(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $unknown = ['load_per_core' => null] + self::CALM;
        $runner = $this->runner([
            'sample' => fn(): array => $unknown,
            'sleep' => function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->fixture->advance($seconds);
            },
        ]);
        $this->assertSame('done', ($this->finish($runner, $run)));
        $this->assertSame([30], $this->slept);
    }

    public function test_a_value_that_does_not_apply_to_the_site_does_not_make_it_wait(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['sample' => fn(): array => ['nfs_mbps' => null, 'not_applicable' => ['nfs_mbps']] + self::CALM]);
        $this->assertSame('done', ($this->finish($runner, $run)));
        $this->assertSame([], $this->slept);
    }

    /**
     * Adds a file of three pieces to the file store and one to the data root.
     *
     * @return array [content hash of the file of the file store, its content, path of the file of the data root]
     */
    private function add_files_of_three_pieces(): array {
        $content = random_bytes(2 * packer::PIECE + 1048576);
        $file = get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'tool_secbackup', 'filearea' => 'test', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'three.bin'], $content);
        $handle = fopen($this->dataroot . '/big.bin', 'wb');
        for ($i = 0; $i < 2 * series_writer::PIECE + 1048576; $i += 1048576) {
            fwrite($handle, random_bytes(1048576));
        }
        fclose($handle);
        return [$file->get_contenthash(), $content, $this->dataroot . '/big.bin'];
    }

    /**
     * The chunk files in the folder of a run: every file but the queue, the entries and the present list.
     *
     * @param string $folder The folder.
     * @return string[]
     */
    private static function chunk_files(string $folder): array {
        return array_values(array_filter(
            glob($folder . '/*') ?: [],
            static fn(string $path): bool => is_file($path)
                && !in_array(basename($path), ['uploads.json', 'entries.jsonl', 'present.gz'], true)
        ));
    }

    /**
     * The budgets of the tests of the temporary files: none at all, and room for one piece.
     *
     * @return array
     */
    public static function piece_budget_provider(): array {
        return ['no room' => [0], 'room for one piece' => [40]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('piece_budget_provider')]
    /**
     * A file of several pieces is in the temporary folder one piece at a time.
     *
     * @dataProvider piece_budget_provider
     * @param int $budgetmb The budget in MB.
     */
    public function test_a_file_of_several_pieces_holds_one_piece_at_a_time_in_the_temporary_folder(int $budgetmb): void {
        [$hash] = $this->add_files_of_three_pieces();
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        $most = 0;
        $bytes = 0;
        $look = function () use ($folder, &$most, &$bytes): void {
            $files = self::chunk_files($folder);
            $most = max($most, count($files));
            $bytes = max($bytes, array_sum(array_map('filesize', $files)));
        };
        $this->fixture->intercept = function (RequestInterface $request) use ($look) {
            if ($request->getMethod() === 'PUT') {
                $look();
            }
            return null;
        };
        $runner = $this->runner([
            'limits' => $this->limits($budgetmb),
            'diskspace' => fn(string $folder): array => [0, 1000],
            // The watch looks right after a chunk is made: when the folder holds the most.
            'sample' => function () use ($look): array {
                $look();
                return self::CALM;
            },
        ]);
        $this->assertSame('done', $this->finish($runner, $run));
        if ($budgetmb === 0) {
            $this->assertSame(1, $most, 'Nothing but the chunk that was just made, or is being sent.');
            $this->assertLessThanOrEqual(run_context::chunk_bound(packer::PIECE), $bytes);
        } else {
            $this->assertLessThanOrEqual($budgetmb * 1048576, $bytes, 'The pieces queued stay within the budget.');
        }
        [, $entries] = $this->manifest($run->run_uuid());
        $three = array_values(array_filter($entries, fn($e) => $e['t'] === 'file' && $e['contenthash'] === $hash));
        $this->assertSame([0, 1, 2], array_column($three, 'piece'));
        $big = array_values(array_filter($entries, fn($e) => $e['t'] === 'dataroot' && $e['path'] === 'big.bin'));
        $this->assertSame([0, 1, 2], array_column($big, 'piece'));
        $this->assertCount(3, array_unique(array_column($big, 'chunk')));
    }

    /**
     * The stages of the tests of a file of several pieces.
     *
     * @return array
     */
    public static function piece_stage_provider(): array {
        return ['the file store' => ['files'], 'the data root' => ['dataroot']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('piece_stage_provider')]
    /**
     * The watch looks after every piece, and a verdict after the second stops the run before the third is made.
     *
     * @dataProvider piece_stage_provider
     * @param string $stage The stage of the file.
     */
    public function test_the_watch_looks_after_every_piece_and_stops_before_the_next(string $stage): void {
        global $DB;
        [$hash] = $this->add_files_of_three_pieces();
        if ($stage === 'files') {
            unlink($this->dataroot . '/big.bin');
            // The file of three pieces is the only content: the looks follow its pieces, whatever the order of the hashes.
            $DB->delete_records_select('files', 'contenthash <> ?', [$hash]);
        }
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        $looks = [];
        $runner = $this->runner([
            'limits' => $this->limits(0),
            'diskspace' => fn(string $folder): array => [0, 1000],
            'sample' => function () use ($run, $folder, $stage, &$looks): array {
                if ($run->stage() !== $stage) {
                    return self::CALM;
                }
                $looks[] = count(self::chunk_files($folder));
                return count($looks) === 2 ? ['load_per_core' => 9.0] + self::CALM : self::CALM;
            },
        ]);
        $this->assertSame('aborted', $this->finish($runner, $run));
        $this->assertSame('server_busy', $run->abort_code());
        $this->assertCount(2, $looks);
        $this->assertSame([1, 1], $looks, 'One piece is in the folder at each look.');
    }

    public function test_a_file_of_the_file_store_that_changes_between_the_reads_stores_no_wrong_piece(): void {
        global $CFG;
        [$hash, $content] = $this->add_files_of_three_pieces();
        unlink($this->dataroot . '/big.bin');
        $path = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        $first = chunk_name::file(dedup_key::load_or_create(), $hash, 0);
        $runner = $this->runner([
            'limits' => $this->limits(0),
            'diskspace' => fn(string $folder): array => [0, 1000],
            'sample' => function () use ($folder, $first, $path): array {
                if (is_file($folder . '/' . $first)) {
                    // The second piece changes after the first was made.
                    $handle = fopen($path, 'r+b');
                    fseek($handle, packer::PIECE + 10);
                    fwrite($handle, 'changed');
                    fclose($handle);
                }
                return self::CALM;
            },
        ]);
        $this->assertSame('aborted', $this->finish($runner, $run));
        $this->assertSame('tamper_detected', $run->abort_code());
        $key = dedup_key::load_or_create();
        $this->assertNull($this->stored('objects/' . chunk_name::file($key, $hash, 1)));
        $this->assertNull($this->stored('objects/' . chunk_name::file($key, $hash, 2)));
    }

    public function test_a_file_of_the_data_root_that_changes_between_the_reads_is_withdrawn_and_its_pieces_stay_stored(): void {
        [, , $path] = $this->add_files_of_three_pieces();
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        $changed = false;
        $runner = $this->runner([
            'sample' => function () use ($run, $folder, $path, &$changed): array {
                if ($run->stage() === 'dataroot' && !$changed && count(self::chunk_files($folder)) > 0) {
                    $changed = true;
                    $handle = fopen($path, 'r+b');
                    fseek($handle, series_writer::PIECE + 10);
                    fwrite($handle, 'changed');
                    fclose($handle);
                }
                return self::CALM;
            },
        ]);
        $this->assertSame('done', $this->finish($runner, $run));
        [$header, $entries] = $this->manifest($run->run_uuid());
        $this->assertSame([], array_values(array_filter($entries, fn($e) => $e['t'] === 'dataroot' && $e['path'] === 'big.bin')));
        $this->assertContains('dataroot:big.bin', $header['unreadable']);
        // The first piece was made and stays stored, in the index, referred to by no entry.
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
        $referred = array_unique(array_column($entries, 'chunk'));
        $this->assertCount(1, array_diff(array_keys($sizes), $referred));
    }

    public function test_a_big_file_in_progress_that_is_small_when_the_run_goes_on_is_taken_whole_once(): void {
        [, , $path] = $this->add_files_of_three_pieces();
        $run = run_record::create('manual', $this->fixture->time);
        $looks = 0;
        $cut = $this->runner(['sample' => function () use ($run, &$looks): array {
            if ($run->stage() === 'dataroot' && ++$looks === 2) {
                throw new \RuntimeException('The process was stopped.');
            }
            return self::CALM;
        }]);
        $this->assertSame('continue', $cut->step($run, 600));
        $this->assertSame(2, $run->progress()['cursor']['big']['piece']);
        file_put_contents($path, 'small now');
        $this->assertSame('done', $this->finish($this->runner(), run_record::active()));
        $done = run_record::recent(1)[0];
        [$header, $entries] = $this->manifest($done->run_uuid());
        $big = array_values(array_filter($entries, fn($e) => $e['t'] === 'dataroot' && $e['path'] === 'big.bin'));
        $this->assertCount(1, $big);
        $this->assertSame(strlen('small now'), $big[0]['size']);
        $this->assertSame([0, 1], [$big[0]['piece'], $big[0]['pieces']]);
        $this->assertNotContains('dataroot:big.bin', $header['unreadable']);
        [, $sizes] = $this->index($done->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
    }

    public function test_the_stream_of_a_big_file_does_not_outlive_its_step(): void {
        \tool_secbackup\tests\failing_stream::register();
        try {
            $this->add_files_of_three_pieces();
            unlink($this->dataroot . '/big.bin');
            $sources = [];
            $seams = [
                'timer' => $this->fast_timer(),
                'filesource' => function (\moodle_database $db) use (&$sources): file_source {
                    $sources[] = new \tool_secbackup\tests\flaky_file_source($db, static fn(int $seconds) => null);
                    return end($sources);
                },
            ];
            $run = run_record::create('manual', $this->fixture->time);
            $steps = 0;
            for ($i = 0; $i < 200 && $run->status() !== 'completed'; $i++) {
                $this->runner($seams)->step($run, 1);
                if ($run->next_attempt() !== null && $run->next_attempt() > $this->fixture->time) {
                    $this->fixture->set_time($run->next_attempt());
                }
                $steps += $run->stage() === 'files' ? 1 : 0;
                foreach ($sources as $source) {
                    foreach ($source->handles as $handle) {
                        $this->assertFalse(is_resource($handle), 'A stream of the file store is open after the step.');
                    }
                }
            }
            $this->assertSame('completed', $run->status());
            $this->assertGreaterThan(1, $steps, 'The big file took more than one step.');
        } finally {
            \tool_secbackup\tests\failing_stream::unregister();
        }
    }

    public function test_a_step_cut_after_the_second_piece_goes_on_without_sending_or_numbering_again(): void {
        $this->add_files_of_three_pieces();
        $run = run_record::create('manual', $this->fixture->time);
        $looks = 0;
        $cut = $this->runner(['sample' => function () use ($run, &$looks): array {
            if ($run->stage() === 'dataroot' && ++$looks === 2) {
                throw new \RuntimeException('The process was stopped.');
            }
            return self::CALM;
        }]);
        $this->assertSame('continue', $cut->step($run, 600));
        $this->assertSame('dataroot', $run->stage());
        $this->assertSame('done', $this->finish($this->runner(), run_record::active()));
        foreach ($this->fixture->puts() as $key => $count) {
            $this->assertSame(1, $count, $key);
        }
        $done = run_record::recent(1)[0];
        [, $entries] = $this->manifest($done->run_uuid());
        $big = array_values(array_filter($entries, fn($e) => $e['t'] === 'dataroot' && $e['path'] === 'big.bin'));
        $this->assertSame([0, 1, 2], array_column($big, 'piece'));
        $this->assertCount(3, array_unique(array_column($big, 'chunk')));
        [, $sizes] = $this->index($done->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
        $this->assertSame(count($entries), count(array_unique(array_map('json_encode', $entries))));
    }

    /**
     * The chunks that the storage had taken each time the watch looked, while the run read the database.
     *
     * @param limits $limits The limits.
     * @param callable|null $diskspace Measure of the disk.
     * @param array $more Other seams.
     * @return int[] Count of chunks at each look.
     */
    private function sent_while_reading_the_database(limits $limits, ?callable $diskspace = null, array $more = []): array {
        $run = run_record::create('manual', $this->fixture->time);
        $counts = [];
        $seams = $more + ['limits' => $limits, 'sample' => function () use ($run, &$counts): array {
            if ($run->stage() === 'db') {
                $counts[] = count($this->stored_chunks());
            }
            return self::CALM;
        }];
        if ($diskspace !== null) {
            $seams['diskspace'] = $diskspace;
        }
        $this->assertSame('done', $this->finish($this->runner($seams), $run));
        return $counts;
    }

    public function test_with_room_for_the_database_the_blocks_are_sent_after_the_snapshot(): void {
        $counts = $this->sent_while_reading_the_database($this->limits(100));
        $this->assertNotEmpty($counts);
        $this->assertSame([0], array_values(array_unique($counts)));
    }

    public function test_without_room_for_the_database_the_blocks_are_sent_while_it_is_read(): void {
        $counts = $this->sent_while_reading_the_database($this->limits(0), fn(string $folder): array => [0, 1000]);
        $this->assertGreaterThan(0, max($counts));
    }

    public function test_an_estimate_that_fits_but_blocks_that_do_not_are_sent_while_the_database_is_read(): void {
        // The budget is a tenth of 1,000 bytes: an estimate of 50 bytes fits, the blocks do not.
        $counts = $this->sent_while_reading_the_database(
            $this->limits(0),
            fn(string $folder): array => [1000, 2000],
            ['estimate' => fn(array $tables): int => 50]
        );
        $this->assertGreaterThan(0, max($counts));
    }

    public function test_another_server_does_not_go_on_with_a_run_whose_files_are_on_one_server(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $runner->step($run, 1);
        $this->assertSame('web1', $run->hostname());
        $stage = $run->stage();
        $requests = count($this->fixture->requests);
        $other = $this->runner(['hostname' => fn(): string => 'web2']);
        $this->assertSame('paused', $other->step($run, 600));
        $this->assertSame($stage, $run->stage());
        $this->assertCount($requests, $this->fixture->requests);
        $this->assertSame('done', ($this->finish($runner, $run)));
    }

    public function test_a_run_in_the_temporary_folder_of_moodle_can_go_on_on_any_server(): void {
        global $CFG;
        $run = run_record::create('manual', $this->fixture->time);
        $limits = $this->limits(100, 0, $CFG->tempdir . '/tool_secbackup');
        $this->assertSame('done', ($this->finish($this->runner(['limits' => $limits]), $run)));
        $this->assertNull($run->hostname());
    }

    public function test_a_present_list_of_another_site_stops_the_run(): void {
        $other = '99999999-2222-4333-8444-555555555555';
        $text = json_encode(['v' => 1, 'site_id' => $other, 'generated_at' => '2026-10-18T00:00:00Z', 'count' => 0]) . "\n";
        $this->fixture->service->state['objects']['sites/' . self::SITE . '/indexes/present'] = base64_encode(gzencode($text));
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', ($this->finish($this->runner(), $run)));
        $this->assertSame('signature_invalid', $run->abort_code());
        $this->assertSame('signature_invalid', $this->fixture->service->state['runs'][$run->run_uuid()]['failure_code']);
    }

    /**
     * Writes the present list from what the fake storage holds, as the service does after a verified run.
     */
    private function publish_present_list(): void {
        $chunks = $this->stored_chunks();
        $text = json_encode(['v' => 1, 'site_id' => self::SITE, 'generated_at' => '2026-10-19T00:00:00Z',
            'count' => count($chunks)]) . "\n";
        foreach ($chunks as $name => $size) {
            $text .= $name . ' ' . $size . "\n";
        }
        $this->fixture->service->state['objects']['sites/' . self::SITE . '/indexes/present'] = base64_encode(gzencode($text));
    }

    public function test_a_big_file_that_the_present_list_holds_asks_for_no_room(): void {
        $first = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $first)));
        $this->publish_present_list();
        $this->fixture->advance(86400);
        // New small files on both sides of the big file, in the order of the content hashes: the pack of the first one waits
        // in the queue when the big file comes.
        $big = $this->hashes['big'];
        $sides = [];
        for ($i = 0; count($sides) < 2; $i++) {
            $side = sha1('new content ' . $i) < $big ? 'before' : 'after';
            $sides[$side] ??= 'new content ' . $i;
        }
        foreach ($sides as $side => $content) {
            get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
                'component' => 'tool_secbackup', 'filearea' => 'test', 'itemid' => 0, 'filepath' => '/',
                'filename' => $side . '.bin'], $content);
        }
        // In the stage of the files the service is busy: whatever waits cannot be sent.
        $this->fixture->intercept = function (RequestInterface $request) {
            if (str_ends_with($request->getUri()->getPath(), '/upload-urls') && run_record::recent(1)[0]->stage() === 'files') {
                $request->getBody()->getContents();
                return new Response(503, ['Retry-After' => '60'], json_encode(['error' => ['code' => 'busy']]));
            }
            return null;
        };
        $second = run_record::create('manual', $this->fixture->time);
        // Room for small records only (a tenth of the free space): whatever waits is sent before the next chunk is made.
        $runner = $this->runner(['limits' => $this->limits(0), 'diskspace' => fn(string $folder): array => [1048576, 10485760]]);
        for ($i = 0; $i < 20 && ($second->stage() !== 'files' || $second->status() !== 'paused'); $i++) {
            $runner->step($second, 600);
        }
        $this->assertSame('files', $second->stage());
        $this->assertSame('paused', $second->status());
        // The step stopped at the file after the big one: the big file was taken from the present list without a drain.
        $entries = $this->tempdir . '/run-' . $second->id() . '/' . \tool_secbackup\local\backup\manifest_builder::FILE;
        $lines = array_slice(file($entries), 0, $second->entries_committed());
        $hashes = array_column(array_map(static fn(string $l): array => json_decode($l, true), $lines), 'contenthash');
        $this->assertContains($big, $hashes);
        $this->assertNotContains(sha1($sides['after']), $hashes);
    }

    public function test_chunks_in_the_present_list_are_not_sent_again_but_are_in_the_index(): void {
        $first = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $first)));
        [, $firstentries] = $this->manifest($first->run_uuid());
        $this->publish_present_list();
        $before = $this->fixture->puts();
        $this->fixture->advance(86400);
        $second = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $second)));
        $piece = chunk_name::file(dedup_key::load_or_create(), $this->hashes['big'], 0);
        $after = $this->fixture->puts();
        $this->assertSame(1, $after['sites/' . self::SITE . '/objects/' . $piece]);
        // The packs of the small files are the same chunks too.
        foreach (array_filter($firstentries, fn($e) => $e['t'] === 'file') as $entry) {
            $this->assertSame($before['sites/' . self::SITE . '/objects/' . $entry['chunk']], 1);
            $this->assertSame(1, $after['sites/' . self::SITE . '/objects/' . $entry['chunk']]);
        }
        [, $sizes] = $this->index($second->run_uuid());
        $this->assertArrayHasKey($piece, $sizes);
        [, $entries] = $this->manifest($second->run_uuid());
        $big = array_values(array_filter($entries, fn($e) => $e['t'] === 'file' && $e['contenthash'] === $this->hashes['big']));
        $firstbig = array_values(array_filter(
            $firstentries,
            fn($e) => $e['t'] === 'file' && $e['contenthash'] === $this->hashes['big']
        ));
        $this->assertSame($firstbig, $big);
        $this->assertSame('completed', $this->fixture->service->state['runs'][$second->run_uuid()]['status']);
    }

    /**
     * The bandwidth settings and the limit that the run sends with.
     *
     * @return array
     */
    public static function bandwidth_provider(): array {
        return [
            'both set, the service lower' => [2, 4, 250000],
            'both set, this site lower' => [8, 4, 500000],
            'the service only' => [3, 0, 375000],
            'this site only' => [0, 6, 750000],
            'neither' => [0, 0, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bandwidth_provider')]
    /**
     * The chunks of a run are sent at the smaller of the two limits that are not zero.
     *
     * @dataProvider bandwidth_provider
     * @param int $service Setting of the service in Mbps.
     * @param int $local Setting of this site in Mbps.
     * @param int $expected Bytes a second, or 0 for no limit.
     */
    public function test_the_upload_speed_is_the_smaller_of_the_two_limits(int $service, int $local, int $expected): void {
        $body = \tool_secbackup\tests\instruction_factory::settings_body(['bandwidth_mbps' => $service]);
        settings::apply(\tool_secbackup\tests\instruction_factory::make('settings', 2, $body));
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(['limits' => $this->limits(100, $local)]), $run));
        $limits = array_unique($this->fixture->putspeeds);
        $this->assertSame([$expected], array_values($limits));
        $this->assertGreaterThan(5, count($this->fixture->putspeeds));
    }

    public function test_a_contract_that_is_not_active_at_the_start_refuses_the_run(): void {
        $this->fixture->service->state['contractinactive'] = true;
        $run = run_record::create('schedule', $this->fixture->time);
        $this->assertSame('aborted', $this->runner()->step($run, 600));
        $this->assertSame('refused', $run->status());
        $this->assertSame('contract_inactive', $run->abort_code());
        $this->assertNull($run->retry_at());
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
        $this->assertSame(0, run_record::consecutive_aborts());
        $this->assertNull(run_record::active());
    }

    public function test_the_number_of_runs_of_the_plan_refuses_the_run(): void {
        $this->fixture->service->state['mode'] = 'run_limit_reached';
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', $this->runner()->step($run, 600));
        $this->assertSame('refused', $run->status());
        $this->assertSame('run_limit_reached', $run->abort_code());
        // The service answers with a wait of an hour.
        $this->assertSame($this->fixture->time + 3600, $run->retry_at());
    }

    public function test_a_busy_service_at_the_start_makes_the_run_wait(): void {
        $this->fixture->service->state['mode'] = 'unavailable';
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertSame('paused', $run->status());
        $this->assertSame($this->fixture->time + 120, $run->next_attempt());
        $this->fixture->service->state['mode'] = '';
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
    }

    public function test_a_contract_that_ends_during_the_run_stops_it(): void {
        $this->fixture->intercept = function (RequestInterface $request) {
            if (str_ends_with($request->getUri()->getPath(), '/upload-urls')) {
                $this->fixture->service->state['contractinactive'] = true;
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', ($this->finish($this->runner(), $run)));
        $this->assertSame('contract_inactive', $run->abort_code());
        $this->assertSame('contract_inactive', $this->fixture->service->state['runs'][$run->run_uuid()]['failure_code']);
    }

    public function test_a_run_of_the_service_that_this_site_lost_is_failed_as_restarted(): void {
        $lost = $this->fixture->start_at_service();
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        $this->assertSame('failed', $this->fixture->service->state['runs'][$lost]['status']);
        $this->assertSame('restarted', $this->fixture->service->state['runs'][$lost]['failure_code']);
        $this->assertNotSame($lost, $run->run_uuid());
    }

    public function test_a_run_that_this_site_stopped_without_telling_is_failed_with_its_reason(): void {
        $stopped = run_record::create('manual', $this->fixture->time);
        $stopped->set_run_uuid($this->fixture->start_at_service());
        $stopped->abort('disk_low', $this->fixture->time);
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        $this->assertSame('disk_low', $this->fixture->service->state['runs'][$stopped->run_uuid()]['failure_code']);
    }

    public function test_the_receipt_is_asked_for_after_30_60_120_300_and_then_every_600_seconds(): void {
        $this->fixture->service->state['holdverify'] = true;
        $polls = [];
        $this->fixture->intercept = function (RequestInterface $request) use (&$polls) {
            if ($request->getMethod() === 'GET' && preg_match('~/runs/[0-9a-f-]{36}\z~', $request->getUri()->getPath())) {
                $polls[] = $this->fixture->time;
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner();
        while ($run->stage() !== 'receipt') {
            $runner->step($run, 600);
        }
        $completed = $this->fixture->time;
        $this->assertSame($completed + 30, $run->next_attempt());
        // A step before the time asks nothing.
        $this->assertSame('continue', $runner->step($run, 600));
        $this->assertSame([], $polls);
        foreach ([60, 120, 300, 600, 600, 600] as $wait) {
            $this->fixture->set_time($run->next_attempt());
            $this->assertSame('continue', $runner->step($run, 600));
            $this->assertSame($this->fixture->time + $wait, $run->next_attempt());
        }
        $this->assertSame([30, 90, 210, 510, 1110, 1710], array_map(fn($t) => $t - $completed, $polls));
        $this->fixture->service->verify_run($run->run_uuid());
        $this->fixture->set_time($run->next_attempt());
        $this->assertSame('done', $runner->step($run, 600));
        $this->assertSame('completed', $run->status());
    }

    public function test_a_run_that_the_service_fails_is_stopped_with_its_reason(): void {
        $first = true;
        $this->fixture->intercept = function (RequestInterface $request) use (&$first) {
            if ($request->getMethod() === 'PUT' && str_contains($request->getUri()->getPath(), '/objects/') && $first) {
                $first = false;
                // The storage takes the whole body, says that it stored it, and keeps nothing.
                $request->getBody()->getContents();
                return new Response(200);
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', ($this->finish($this->runner(), $run)));
        $this->assertSame('verify_missing_chunks', $run->abort_code());
        $this->assertSame(1, $this->fixture->count('POST', '/complete'));
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    public function test_a_receipt_that_is_not_for_what_was_sent_stops_the_run(): void {
        $this->fixture->service->state['receiptpayload'] = ['chunk_count' => 999999];
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', ($this->finish($this->runner(), $run)));
        $this->assertSame('signature_invalid', $run->abort_code());
        // The service, which completed the run, refuses the report of the stop; the stop is recorded all the same.
        $this->assertSame('completed', $this->fixture->service->state['runs'][$run->run_uuid()]['status']);
    }

    public function test_the_size_that_the_receipt_names_is_kept_with_the_run(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertNull($run->stored_bytes());
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        $stored = $this->fixture->service->state['runs'][$run->run_uuid()]['storedbytes'];
        $this->assertSame((int) $stored, run_record::last_completed()->stored_bytes());
    }

    public function test_the_report_holds_statistics_that_the_service_takes(): void {
        $body = null;
        $this->fixture->intercept = function (RequestInterface $request) use (&$body) {
            if (str_ends_with($request->getUri()->getPath(), '/complete')) {
                $body = (string) $request->getBody();
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        $this->assertLessThanOrEqual(60000, strlen($body));
        $stats = json_decode($body, true)['stats'];
        $this->assertNotEmpty($stats['tables']);
        $this->assertArrayHasKey('course', $stats['tables']);
        $this->assertContains('manual', $stats['auth_methods']);
        $this->assertSame(['db', 'files', 'dataroot', 'code'], array_keys($stats['bytes']));
        $this->assertGreaterThan(packer::FILE_LIMIT, $stats['bytes']['files']);
        $this->assertSame(strlen('lang') + strlen('key'), $stats['bytes']['dataroot']);
        // Without an official list nothing was compared: the whole program was sent, and the service and the status page warn
        // as for a change (design 8.3).
        $this->assertTrue($stats['core_modified']);
        $this->assertFalse($stats['external_storage']);
        $this->assertSame('1', get_config('tool_secbackup', 'coremodified'));
        $this->assertSame('20250113', $stats['moodle']['build']);
    }

    public function test_with_the_official_list_only_the_changed_files_of_moodle_are_sent(): void {
        $locked = $this->core . '/locked';
        mkdir($locked);
        file_put_contents($locked . '/file.php', 'unreadable');
        $official = [
            'index.php' => hash('sha256', 'the official index'),
            'lib/a.php' => hash('sha256', 'core a'),
            'lib/gone.php' => hash('sha256', 'deleted on this site'),
            'locked/file.php' => hash('sha256', 'unreadable'),
            'version.php' => hash_file('sha256', $this->core . '/version.php'),
        ];
        $text = json_encode(['v' => 1, 'version' => '2024100703.10', 'build' => '20250113',
            'release' => '4.5.3+ (Build: 20250113)', 'count' => count($official)], JSON_UNESCAPED_SLASHES) . "\n";
        foreach ($official as $path => $hash) {
            $text .= $path . "\t" . $hash . "\n";
        }
        $this->fixture->service->publish_core_manifest('2024100703.10', '20250113', gzencode($text), ['count' => 5]);
        file_put_contents($this->core . '/lib/new.php', 'added');
        $lister = static fn(string $dir) => basename($dir) === 'locked' ? false : scandir($dir);
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['corediff' => fn(): core_diff => new core_diff($this->core, [], \Closure::fromCallable($lister))]);
        $this->assertSame('done', ($this->finish($runner, $run)));
        [$header, $entries] = $this->manifest($run->run_uuid());
        $this->assertSame(
            ['official' => true, 'modified' => ['index.php'], 'added' => ['lib/new.php'], 'deleted' => ['lib/gone.php']],
            $header['core_diff']
        );
        $this->assertSame(['core:locked'], $header['unreadable']);
        $this->assertSame('1', get_config('tool_secbackup', 'coremodified'));
        $core = array_values(array_filter($entries, fn($e) => $e['t'] === 'code' && $e['root'] === 'core'));
        $this->assertEqualsCanonicalizing(['index.php', 'lib/new.php'], array_column($core, 'path'));
    }

    public function test_a_flag_of_a_change_is_taken_back_by_a_run_that_finds_the_program_as_it_is_officially(): void {
        set_config('coremodified', 1, 'tool_secbackup');
        $official = [];
        foreach (['index.php', 'lib/a.php', 'version.php'] as $path) {
            $official[$path] = hash_file('sha256', $this->core . '/' . $path);
        }
        $text = json_encode(['v' => 1, 'version' => '2024100703.10', 'build' => '20250113',
            'release' => '4.5.3+ (Build: 20250113)', 'count' => count($official)], JSON_UNESCAPED_SLASHES) . "\n";
        foreach ($official as $path => $hash) {
            $text .= $path . "\t" . $hash . "\n";
        }
        $this->fixture->service->publish_core_manifest('2024100703.10', '20250113', gzencode($text), ['count' => 3]);
        $body = null;
        $this->fixture->intercept = function (RequestInterface $request) use (&$body) {
            if (str_ends_with($request->getUri()->getPath(), '/complete')) {
                $body = (string) $request->getBody();
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertSame(['official' => true, 'modified' => [], 'added' => [], 'deleted' => []], $header['core_diff']);
        $this->assertSame('0', get_config('tool_secbackup', 'coremodified'));
        $this->assertFalse(json_decode($body, true)['stats']['core_modified']);
    }

    public function test_an_official_list_for_another_build_sends_the_whole_program(): void {
        $text = json_encode(['v' => 1, 'version' => '2024100703.10', 'build' => '20250114',
            'release' => '4.5.3+ (Build: 20250114)', 'count' => 0], JSON_UNESCAPED_SLASHES) . "\n";
        $this->fixture->service->publish_core_manifest('2024100703.10', '20250113', gzencode($text), ['count' => 0]);
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
        [$header, $entries] = $this->manifest($run->run_uuid());
        $this->assertFalse($header['core_diff']['official']);
        $core = array_column(array_filter($entries, fn($e) => $e['t'] === 'code' && $e['root'] === 'core'), 'path');
        $this->assertEqualsCanonicalizing(['index.php', 'lib/a.php', 'version.php'], $core);
    }

    public function test_an_unexpected_error_is_logged_by_its_class_only_and_the_run_goes_on(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $broken = $this->runner(['corediff' => function (): core_diff {
            throw new key_exception('dedupkey_corrupt', null, 'secret detail');
        }]);
        $results = [];
        while ($run->stage() !== 'code' || count($results) === 0) {
            $results[] = $broken->step($run, 600);
        }
        $broken->step($run, 600);
        $this->assertSame('paused', $run->status());
        $this->assertContains(
            'The backup step failed and is tried again: tool_secbackup\local\crypto\key_exception dedupkey_corrupt',
            $this->logged
        );
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('secret detail', $line);
        }
        $this->assertSame('done', ($this->finish($this->runner(), $run)));
    }

    public function test_a_run_that_ended_is_left_alone(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $run->finish($this->fixture->time);
        $this->assertSame('done', $this->runner()->step($run, 600));
        $aborted = run_record::create('manual', $this->fixture->time);
        $aborted->abort('disk_low', $this->fixture->time);
        $this->assertSame('aborted', $this->runner()->step($aborted, 600));
        $this->assertSame([], $this->fixture->requests);
    }

    public function test_an_unknown_seam_is_refused(): void {
        $this->expectException(\coding_exception::class);
        $this->runner(['nosuchseam' => true]);
    }

    public function test_the_runner_of_the_site_is_made_with_its_own_parts(): void {
        $runner = runner::create(false);
        $run = run_record::create('manual', $this->fixture->time);
        $run->finish($this->fixture->time);
        $this->assertSame('done', $runner->step($run, 600));
    }

    public function test_a_run_whose_receipt_was_verified_but_not_marked_is_completed_and_cleared(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $run->commit('done', [], 0, $this->fixture->time);
        mkdir($this->tempdir . '/run-' . $run->id());
        file_put_contents($this->tempdir . '/run-' . $run->id() . '/left', 'x');
        $this->assertSame('done', $this->runner()->step($run, 600));
        $this->assertSame('completed', $run->status());
        $this->assertDirectoryDoesNotExist($this->tempdir . '/run-' . $run->id());
        $this->assertSame(['cleared' => true], run_record::recent(1)[0]->progress()['done']);
    }

    public function test_the_window_can_end_while_the_files_are_added(): void {
        // A second big file: a content always follows the first big one, whose chunk is the first of the stage.
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'tool_secbackup', 'filearea' => 'test', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'big2.bin'], random_bytes(packer::FILE_LIMIT + 10));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['sample' => function () use ($run): array {
            if ($run->stage() === 'files') {
                $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:00'));
            }
            return self::CALM;
        }]);
        $this->assertSame('paused', $runner->step($run, 600));
        $this->assertSame('files', $run->stage());
        $this->assertSame(1, $run->window_misses());
        $this->fixture->set_time($run->next_attempt());
        $this->assertSame('done', $this->finish($this->runner(), $run));
    }

    public function test_the_window_can_end_while_the_data_root_is_added(): void {
        file_put_contents($this->dataroot . '/big.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['sample' => function () use ($run): array {
            if ($run->stage() === 'dataroot') {
                $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:00'));
            }
            return self::CALM;
        }]);
        $this->assertSame('paused', $runner->step($run, 600));
        $this->assertSame('dataroot', $run->stage());
        $this->fixture->set_time($run->next_attempt());
        $this->assertSame('done', $this->finish($this->runner(), $run));
        [, $entries] = $this->manifest($run->run_uuid());
        $paths = array_column(array_filter($entries, fn($e) => $e['t'] === 'dataroot'), 'path');
        $this->assertSame(count($paths), count(array_unique($paths)));
        $this->assertContains('big.bin', $paths);
    }

    public function test_a_run_that_finds_its_window_over_inside_the_next_window_goes_on_at_once(): void {
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 04:55'));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $runner->step($run, 1);
        $this->fixture->set_time(run_fixture::tokyo('2026-10-20 04:00'));
        $this->assertSame('continue', $runner->step($run, 1));
        $this->assertSame(1, $run->window_misses());
        $this->assertSame('active', $run->status());
        $this->assertSame('done', $this->finish($runner, $run, 1));
    }

    public function test_a_run_started_out_of_the_window_works_until_the_end_of_the_next_one(): void {
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 12:00'));
        $run = run_record::create('manual', $this->fixture->time);
        $this->runner(['timer' => $this->fast_timer()])->step($run, 1);
        $this->assertSame(run_fixture::tokyo('2026-10-20 05:00'), $run->progress()['run']['window_end']);
    }

    public function test_an_unexpected_refusal_of_the_start_is_tried_again_by_the_next_step(): void {
        $this->fixture->service->state['mode'] = 'ip_not_allowed';
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertSame(
            ['The backup step failed and is tried again: tool_secbackup\local\api\api_exception api_ip_not_allowed_address'],
            $this->logged
        );
        $this->fixture->service->state['mode'] = '';
        $this->assertSame('done', $this->finish($this->runner(), $run));
    }

    public function test_a_present_list_that_cannot_be_kept_stops_the_run(): void {
        $text = json_encode(['v' => 1, 'site_id' => self::SITE, 'generated_at' => '2026-10-19T00:00:00Z', 'count' => 0]) . "\n";
        $this->fixture->service->state['objects']['sites/' . self::SITE . '/indexes/present'] = base64_encode(gzencode($text));
        $run = run_record::create('manual', $this->fixture->time);
        mkdir($this->tempdir . '/run-' . $run->id() . '/present.gz', 0777, true);
        $this->assertSame('aborted', $this->finish($this->runner(), $run));
        $this->assertSame('disk_low', $run->abort_code());
    }

    /**
     * Answers the request for the capacity with a status and a body.
     *
     * @param int $status HTTP status.
     * @param array $body The body.
     * @param int $times How many requests are answered this way.
     */
    private function answer_capacity(int $status, array $body, int $times = 1): void {
        $this->fixture->intercept = function (RequestInterface $request) use ($status, $body, &$times) {
            if (str_ends_with($request->getUri()->getPath(), '/capacity') && $times-- > 0) {
                return new Response($status, ['Retry-After' => '45'], json_encode($body));
            }
            return null;
        };
    }

    public function test_a_refused_capacity_stops_the_run(): void {
        $this->answer_capacity(200, ['decision' => 'refuse', 'limit_bytes' => 10, 'used_bytes' => 5]);
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', $this->finish($this->runner(), $run));
        $this->assertSame('capacity_refused', $run->abort_code());
    }

    public function test_a_busy_service_makes_the_capacity_wait(): void {
        $this->answer_capacity(503, ['error' => ['code' => 'service_unavailable']]);
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner();
        $this->assertSame('continue', $runner->step($run, 600));
        $this->assertSame('capacity', $run->stage());
        $this->assertSame($this->fixture->time + 45, $run->next_attempt());
        $this->assertSame('done', $this->finish($runner, $run));
    }

    public function test_another_refusal_of_the_capacity_is_tried_again(): void {
        $this->answer_capacity(500, ['error' => ['code' => 'server_error']]);
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertSame('capacity', $run->stage());
        $this->assertSame('done', $this->finish($this->runner(), $run));
    }

    public function test_a_send_that_fails_in_any_stage_is_tried_again_in_15_minutes(): void {
        // The data root sends while the run is in its stage whatever the order of the content hashes: the piece of a big file
        // (the first in the walk) is queued after its commit and sent before the chunk of the next file is made. Without it,
        // the stage would send only a chunk that the files stage left in the queue, and the files stage leaves none when
        // its last content in hash order is one that is in no store ('missing') and the content before it is the big one.
        file_put_contents($this->dataroot . '/big.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        $failed = [];
        $run = run_record::create('manual', $this->fixture->time);
        $this->fixture->intercept = function (RequestInterface $request) use ($run, &$failed) {
            $stage = $run->stage();
            if ($request->getMethod() === 'PUT' && !isset($failed[$stage])) {
                $failed[$stage] = $request->getUri()->getPath();
                return new Response(500);
            }
            return null;
        };
        $runner = $this->runner([
            'windows' => false,
            'limits' => $this->limits(0),
            'diskspace' => fn(string $folder): array => [0, 1000],
        ]);
        $this->assertSame('done', $this->finish($runner, $run));
        $this->assertSame(['db', 'files', 'dataroot', 'code', 'manifest', 'index', 'complete'], array_keys($failed));
        $puts = $this->fixture->puts();
        $objects = $this->fixture->service->state['objects'];
        foreach ($failed as $stage => $path) {
            $key = substr($path, strlen('/_s3/'));
            $this->assertArrayHasKey($key, $objects, $stage);
            // Sent again after 15 minutes. A block of the database waits for no commit: the next step takes it out of the queue
            // and makes it again, and the block made again is the one that is sent.
            $this->assertSame(2, $puts[$key], $stage);
        }
    }

    public function test_a_block_whose_sends_keep_failing_stops_the_run_although_each_step_makes_it_again(): void {
        $paths = [];
        $run = run_record::create('manual', $this->fixture->time);
        $this->fixture->intercept = function (RequestInterface $request) use ($run, &$paths) {
            if ($request->getMethod() === 'PUT' && $run->stage() === 'db') {
                $paths[] = $request->getUri()->getPath();
                return new Response(500);
            }
            return null;
        };
        $runner = $this->runner([
            'windows' => false,
            'limits' => $this->limits(0),
            'diskspace' => fn(string $folder): array => [0, 1000],
        ]);
        $this->assertSame('aborted', $this->finish($runner, $run));
        $this->assertSame('upload_failed', $run->abort_code());
        // The first block of the database, tried three times in three steps: each step made it again with the same name, and
        // the sends that failed before counted.
        $this->assertCount(3, $paths);
        $this->assertCount(1, array_unique($paths));
        $this->assertSame(['continue', 'continue', 'aborted'], array_slice($this->results, -3));
    }

    public function test_a_moodle_program_without_a_version_file_is_described_by_the_settings_of_the_site(): void {
        global $CFG;
        unlink($this->core . '/version.php');
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertSame(number_format((float) $CFG->version, 2, '.', ''), $header['moodle']['version']);
        $this->assertSame((string) $CFG->release, $header['moodle']['release']);
        $this->assertMatchesRegularExpression('/^\d{8}\z/', $header['moodle']['build']);
        $this->assertFalse($header['core_diff']['official']);
    }

    public function test_an_index_that_cannot_be_written_stops_the_run(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        while ($run->stage() !== 'index') {
            $runner->step($run, 1);
        }
        mkdir($this->tempdir . '/run-' . $run->id() . '/index.gz');
        $this->assertSame('aborted', $runner->step($run, 1));
        $this->assertSame('disk_low', $run->abort_code());
    }

    public function test_a_report_whose_answer_was_lost_goes_on_to_the_receipt(): void {
        $lost = false;
        $this->fixture->intercept = function (RequestInterface $request) use (&$lost) {
            if (str_ends_with($request->getUri()->getPath(), '/complete') && !$lost) {
                $lost = true;
                $headers = [];
                foreach ($request->getHeaders() as $name => $values) {
                    $headers[strtolower($name)] = implode(', ', $values);
                }
                // The service takes the report; its answer does not come back.
                $this->fixture->service->handle(
                    'POST',
                    $request->getUri()->getHost(),
                    $request->getUri()->getPath(),
                    $headers,
                    (string) $request->getBody()
                );
                return new Response(503);
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $run));
        $this->assertSame(2, $this->fixture->count('POST', '/complete'));
        $this->assertContains('continue', $this->results);
    }

    public function test_the_report_keeps_a_table_and_a_method_when_nothing_else_fits(): void {
        $stats = ['users_active_365d' => 1, 'courses' => 1, 'auth_methods' => [],
            'tables' => (object) ['big' => 50, 'small' => 1, 'middle' => 10], 'bytes' => [], 'duration_seconds' => 1,
            'moodle' => [], 'core_modified' => false, 'external_storage' => false];
        $fitted = stage\artifact_stage::fit_report($stats, 10);
        $this->assertSame(['big' => 50], (array) $fitted['tables']);
        $this->assertSame(['manual'], $fitted['auth_methods']);
        $roomy = stage\artifact_stage::fit_report(['auth_methods' => ['ldap']] + $stats, 100000);
        $this->assertSame(['big' => 50, 'small' => 1, 'middle' => 10], (array) $roomy['tables']);
        $this->assertSame(['ldap'], $roomy['auth_methods']);
    }

    public function test_a_completed_run_without_a_receipt_stops_the_run(): void {
        $this->fixture->intercept = function (RequestInterface $request) {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'GET' && preg_match('~/runs/([0-9a-f-]{36})\z~', $path, $m) === 1) {
                return new Response(200, [], json_encode(['run_id' => $m[1], 'status' => 'completed', 'failure_code' => null,
                    'receipt' => null]));
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('aborted', $this->finish($this->runner(), $run));
        $this->assertSame('signature_invalid', $run->abort_code());
    }

    public function test_names_that_a_list_cannot_hold_and_files_that_went_away_are_listed_as_unreadable(): void {
        file_put_contents($this->dataroot . "/bad\xff.txt", 'x');
        file_put_contents($this->dataroot . '/a.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        file_put_contents($this->dataroot . '/ab.txt', 'goes away');
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['sample' => function () use ($run): array {
            if ($run->stage() === 'dataroot' && is_file($this->dataroot . '/ab.txt')) {
                unlink($this->dataroot . '/ab.txt');
            }
            return self::CALM;
        }]);
        $this->assertSame('done', $this->finish($runner, $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertSame(['dataroot:hex:' . bin2hex("bad\xff.txt"), 'dataroot:ab.txt'], $header['unreadable']);
    }

    public function test_an_error_of_the_service_while_the_program_is_compared_is_tried_again(): void {
        $once = true;
        $this->fixture->intercept = function (RequestInterface $request) use (&$once) {
            if (str_ends_with($request->getUri()->getPath(), '/core-manifests') && $once) {
                $once = false;
                return new Response(500);
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertSame('code', $run->stage());
        $line = 'The backup step failed and is tried again: tool_secbackup\local\api\api_exception api_unexpected';
        $this->assertSame([$line], $this->logged);
        $this->assertSame('done', $this->finish($this->runner(), $run));
    }

    public function test_a_folder_of_journals_that_cannot_be_made_stops_the_run(): void {
        $run = run_record::create('manual', $this->fixture->time);
        mkdir($this->tempdir . '/run-' . $run->id());
        file_put_contents($this->tempdir . '/run-' . $run->id() . '/journal', 'in the way');
        $this->assertSame('aborted', $this->runner()->step($run, 600));
        $this->assertSame('disk_low', $run->abort_code());
    }

    /**
     * A step of a run made directly, as the runner makes it.
     *
     * @param run_record $run The run.
     * @return run_context
     */
    private function context(run_record $run): run_context {
        $limits = $this->limits();
        return new run_context(
            $run,
            $this->fixture->client(),
            $this->fixture->objects(),
            new temp_area($limits),
            $limits,
            $this->fixture->link(),
            ['clock' => $this->fixture->clock(), 'timer' => fn(): float => 0.0, 'sample' => fn(): array => self::CALM,
                'sleep' => fn(int $s) => null, 'diskspace' => null, 'windows' => true],
            600.0
        );
    }

    public function test_a_step_queues_what_the_last_commit_left_waiting_and_removes_what_a_stopped_step_left(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $run->set_run_uuid($this->fixture->start_at_service());
        $folder = $this->tempdir . '/run-' . $run->id();
        mkdir($folder);
        $name = str_repeat('ab', 32);
        file_put_contents($folder . '/' . $name, 'waiting chunk');
        file_put_contents($folder . '/stray.part', 'left by a stopped step');
        file_put_contents($this->tempdir . '/present-0123456789abcdef.records', 'stale');
        $run->commit('dataroot', ['tosend' => [[
            'name' => $name, 'path' => $folder . '/' . $name, 'size' => 13,
            'sha256' => base64_encode(hash('sha256', 'waiting chunk', true)),
        ], [
            'name' => str_repeat('cd', 32), 'path' => $folder . '/gone', 'size' => 1,
            'sha256' => base64_encode(str_repeat('x', 32)),
        ]]], 0, $this->fixture->time);
        $context = $this->context($run);
        $this->assertSame([$folder . '/' . $name], $context->uploader->queued_paths());
        $this->assertFileExists($folder . '/' . $name);
        $this->assertFileDoesNotExist($folder . '/stray.part');
        $this->assertFileDoesNotExist($this->tempdir . '/present-0123456789abcdef.records');
        $this->assertSame(['run' => $run->id(), 'stage' => 'dataroot'], $context->__debugInfo());
        $context->close();
    }

    public function test_a_run_with_more_chunks_than_an_index_holds_is_refused(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        mkdir($folder . '/journal', 0777, true);
        file_put_contents($folder . '/journal/chunks.jsonl', str_repeat("x\n", 10));
        $run->commit('files', ['journals' => ['chunks' => [index_builder::MAX_COUNT + 1, 20]]], 0, $this->fixture->time);
        $context = $this->context($run);
        try {
            $context->commit([]);
            $this->fail('No exception');
        } catch (\tool_secbackup\local\plugin_exception $e) {
            $this->assertSame('capacity_refused', $e->errorcode);
        } finally {
            $context->close();
        }
    }

    public function test_the_pause_of_the_watch_is_kept_with_the_run_across_runners(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $unknown = ['load_per_core' => null] + self::CALM;
        $seams = [
            'timer' => $this->fast_timer(),
            'sample' => fn(): array => $unknown,
            'sleep' => function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->fixture->advance($seconds);
            },
        ];
        // Every step gets a new runner, as every run of the task does.
        for ($i = 0; $i < 500 && $run->status() !== 'completed'; $i++) {
            $this->runner($seams)->step($run, 1);
            if ($run->next_attempt() !== null && $run->next_attempt() > $this->fixture->time) {
                $this->fixture->set_time($run->next_attempt());
            }
        }
        $this->assertSame('completed', $run->status());
        $this->assertSame([30], $this->slept);
    }

    public function test_a_run_without_a_receipt_24_hours_and_10_minutes_after_its_start_is_stopped_as_expired(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $started = $this->fixture->time;
        $runner = $this->runner(['link' => function (): array {
            throw new \tool_secbackup\local\plugin_exception('api_site_not_enrolled');
        }]);
        $this->assertSame('continue', $runner->step($run, 600));
        $this->assertSame($started + 300, $run->next_attempt());
        $this->fixture->set_time($started + 86400 + 599);
        $this->assertSame('continue', $runner->step($run, 600));
        $this->fixture->set_time($started + 86400 + 600);
        $this->assertSame('aborted', $runner->step($run, 600));
        $this->assertSame('expired', $run->abort_code());
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    /**
     * Makes the report of a run fail with an answer that is not a reason to stop.
     */
    private function refuse_the_report(): void {
        $this->fixture->intercept = function (RequestInterface $request) {
            if (str_ends_with($request->getUri()->getPath(), '/complete')) {
                $request->getBody()->getContents();
                return new Response(422, [], json_encode(['error' => ['code' => 'invalid_request']]));
            }
            return null;
        };
    }

    public function test_a_run_whose_report_keeps_failing_expires_and_is_not_told_back(): void {
        $this->refuse_the_report();
        $run = run_record::create('manual', $this->fixture->time);
        $started = $this->fixture->time;
        $runner = $this->runner();
        for ($i = 0; $i < 50 && ($run->stage() !== 'complete' || $run->next_attempt() === null); $i++) {
            $runner->step($run, 600);
        }
        $this->assertSame('paused', $run->status());
        // The second step after a failure is not run before 300 seconds.
        $requests = count($this->fixture->requests);
        $this->assertSame('continue', $runner->step($run, 600));
        $this->assertCount($requests, $this->fixture->requests);
        $this->fixture->set_time($started + 86400 + 300);
        $this->assertSame('continue', $runner->step($run, 600));
        $this->fixture->set_time($started + 86400 + 600);
        $this->assertSame('aborted', $runner->step($run, 600));
        $this->assertSame('expired', $run->abort_code());
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    public function test_a_scheduled_run_whose_report_keeps_failing_ends_after_two_missed_windows(): void {
        $this->refuse_the_report();
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 04:55'));
        $run = run_record::create('schedule', $this->fixture->time);
        $runner = $this->runner();
        for ($i = 0; $i < 50 && ($run->stage() !== 'complete' || $run->next_attempt() === null); $i++) {
            $runner->step($run, 600);
        }
        $this->fixture->set_time(run_fixture::tokyo('2026-10-19 05:01'));
        $this->assertSame('paused', $runner->step($run, 600));
        $this->assertSame(1, $run->window_misses());
        $this->fixture->set_time(run_fixture::tokyo('2026-10-20 05:01'));
        $this->assertSame('aborted', $runner->step($run, 600));
        $this->assertSame('window_ended', $run->abort_code());
    }

    /**
     * Answers the calls whose path ends with a suffix with 503 and Retry-After, once.
     *
     * @param string $suffix The end of the path, or a pattern starting with "~".
     */
    private function busy_once(string $suffix): void {
        $once = true;
        $this->fixture->intercept = function (RequestInterface $request) use ($suffix, &$once) {
            $path = $request->getUri()->getPath();
            $match = str_starts_with($suffix, '~') ? preg_match($suffix, $path) === 1 : str_ends_with($path, $suffix);
            if ($match && $once) {
                $once = false;
                $request->getBody()->getContents();
                return new Response(503, ['Retry-After' => '77'], json_encode(['error' => ['code' => 'busy']]));
            }
            return null;
        };
    }

    /**
     * The calls during which a busy service makes the run wait.
     *
     * @return array
     */
    public static function busy_provider(): array {
        return [
            'the report' => ['/complete', 'complete'],
            'the receipt' => ['~/runs/[0-9a-f-]{36}\z~', 'receipt'],
            'the official list' => ['/core-manifests', 'code'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('busy_provider')]
    /**
     * A busy service makes the run wait for Retry-After, without counting as a failure.
     *
     * @dataProvider busy_provider
     * @param string $suffix The end of the path of the call.
     * @param string $stage The stage of the call.
     */
    public function test_a_busy_service_makes_the_run_wait_for_retry_after(string $suffix, string $stage): void {
        $this->busy_once($suffix);
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner();
        $result = '';
        $before = 0;
        while ($result !== 'done') {
            $before = $this->fixture->time;
            $result = $runner->step($run, 600);
            if ($run->stage() === $stage && $run->next_attempt() === $before + 77) {
                break;
            }
            if ($run->next_attempt() !== null && $run->next_attempt() > $this->fixture->time) {
                $this->fixture->set_time($run->next_attempt());
            }
        }
        $this->assertSame($before + 77, $run->next_attempt());
        $this->assertSame([], $this->logged);
        $this->assertSame('done', $this->finish($runner, $run));
    }

    public function test_a_present_list_that_cannot_be_fetched_restarts_the_run_at_the_service(): void {
        $text = json_encode(['v' => 1, 'site_id' => self::SITE, 'generated_at' => '2026-10-19T00:00:00Z', 'count' => 0]) . "\n";
        $this->fixture->service->state['objects']['sites/' . self::SITE . '/indexes/present'] = base64_encode(gzencode($text));
        $once = true;
        $this->fixture->intercept = function (RequestInterface $request) use (&$once) {
            if ($request->getMethod() === 'GET' && str_ends_with($request->getUri()->getPath(), '/indexes/present') && $once) {
                $once = false;
                return new Response(500);
            }
            return null;
        };
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertNull($run->run_uuid());
        $this->assertSame('done', $this->finish($this->runner(), $run));
        $first = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';
        $this->assertSame('restarted', $this->fixture->service->state['runs'][$first]['failure_code']);
        $this->assertNotSame($first, $run->run_uuid());
        $this->assertSame(2, $this->fixture->count('GET', '/indexes/present'));
    }

    public function test_a_run_of_the_service_that_a_live_record_holds_is_not_failed(): void {
        $held = $this->fixture->start_at_service();
        $other = run_record::create('manual', $this->fixture->time);
        $other->set_run_uuid($held);
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('continue', $this->runner()->step($run, 600));
        $this->assertSame('started', $this->fixture->service->state['runs'][$held]['status']);
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    public function test_an_error_while_a_failed_step_is_recorded_leaves_no_secret_in_its_trace(): void {
        $ignore = ini_set('zend.exception_ignore_args', '0');
        try {
            $run = run_record::create('manual', $this->fixture->time);
            $runner = $this->runner([
                'corediff' => static function (): core_diff {
                    throw new \RuntimeException('broken');
                },
                'log' => static function (string $line): void {
                    throw new \RuntimeException('The log cannot be written.');
                },
            ]);
            try {
                $runner->step($run, 600);
                $this->fail('No exception');
            } catch (\Throwable $e) {
                $this->assertSame([], \tool_secbackup\tests\trace_strings::objects_in_plugin_frames($e, [run_record::class]));
                $strings = implode("\n", \tool_secbackup\tests\trace_strings::of($e));
                $this->assertStringNotContainsString(dedup_key::load_or_create(), $strings);
                $this->assertStringNotContainsString($this->fixture->pair->export_secret(), $strings);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignore);
        }
    }

    public function test_an_export_that_cannot_be_ended_does_not_throw_out_of_the_step(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $context = $this->context($run);
        $db = \tool_secbackup\local\backup\db\connection_factory::open();
        $snapshot = new class implements \tool_secbackup\local\backup\db\snapshot {
            /** @var bool Whether end() was called. */
            public bool $ended = false;

            /**
             * Begins nothing.
             *
             * @param \moodle_database $db The connection.
             */
            public function begin(\moodle_database $db): void {
            }

            /**
             * Fails as the end of a transaction on a broken connection does.
             *
             * @param \moodle_database $db The connection.
             */
            public function end(\moodle_database $db): void {
                $this->ended = true;
                throw new \dml_write_exception('The connection is broken.');
            }
        };
        $context->hold_export($db, $snapshot);
        $context->end_export();
        $this->assertTrue($snapshot->ended);
        // Ended once: the connection is let go.
        $context->end_export();
        $context->close();
    }

    public function test_an_export_connection_that_cannot_be_let_go_does_not_throw_out_of_the_step(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $context = $this->context($run);
        $disposed = 0;
        $db = $this->createMock(\moodle_database::class);
        $db->method('dispose')->willReturnCallback(function () use (&$disposed): void {
            $disposed++;
            if ($disposed === 1) {
                throw new \dml_exception('dbconnectionfailed');
            }
        });
        $snapshot = new class implements \tool_secbackup\local\backup\db\snapshot {
            /** @var int How many times end() was called. */
            public int $ended = 0;

            /**
             * Begins nothing.
             *
             * @param \moodle_database $db The connection.
             */
            public function begin(\moodle_database $db): void {
            }

            /**
             * Ends the transaction.
             *
             * @param \moodle_database $db The connection.
             */
            public function end(\moodle_database $db): void {
                $this->ended++;
            }
        };
        $context->hold_export($db, $snapshot);
        $context->end_export();
        $this->assertSame(1, $snapshot->ended);
        $this->assertSame(1, $disposed);
        // The connection was let go even though letting it go failed: it is not ended or let go again.
        $context->end_export();
        $context->close();
        $this->assertSame(1, $snapshot->ended);
        $this->assertSame(1, $disposed);
    }

    public function test_a_file_of_the_step_that_cannot_be_closed_does_not_keep_the_others_open(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $context = $this->context($run);
        $journals = (new \ReflectionProperty(run_context::class, 'journals'))->getValue($context);
        $handle = new \ReflectionProperty(journal::class, 'handle');
        foreach ($journals as $journal) {
            $journal->append('line');
        }
        $first = reset($journals);
        // The first file was closed under the journal, so closing it again fails.
        fclose($handle->getValue($first));
        $context->close();
        foreach (array_slice($journals, 1) as $name => $journal) {
            $this->assertNull($handle->getValue($journal), $name);
        }
        $handle->setValue($first, null);
    }

    public function test_a_run_that_waits_for_its_receipt_past_24_hours_asks_once_more_and_is_stopped_as_expired(): void {
        $this->fixture->service->state['holdverify'] = true;
        $run = run_record::create('manual', $this->fixture->time);
        $started = $this->fixture->time;
        $runner = $this->runner();
        for ($i = 0; $i < 50 && $run->stage() !== 'receipt'; $i++) {
            $runner->step($run, 600);
        }
        $this->assertSame('receipt', $run->stage());
        $this->fixture->set_time($started + runner::EXPIRES_AFTER + 60);
        $polls = $this->fixture->count('GET', '/runs/' . $run->run_uuid());
        $this->assertSame('aborted', $runner->step($run, 600));
        // The receipt was asked for once more: the service had not verified the run, so it is stopped.
        $this->assertSame($polls + 1, $this->fixture->count('GET', '/runs/' . $run->run_uuid()));
        $this->assertSame('expired', $run->abort_code());
        $this->assertSame(0, $this->fixture->count('POST', '/fail'));
    }

    /**
     * Adds a file to the file store of the test site.
     *
     * @param string $name File name.
     * @param string $content Content.
     * @return string The content hash.
     */
    private function store_file(string $name, string $content): string {
        return get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'tool_secbackup', 'filearea' => 'test', 'itemid' => 0, 'filepath' => '/',
            'filename' => $name], $content)->get_contenthash();
    }

    /**
     * The path of a content in the file directory.
     *
     * @param string $hash The content hash.
     * @return string
     */
    private static function filedir(string $hash): string {
        global $CFG;
        return $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
    }

    /**
     * The entries of the list of a run that are of one kind and match a field.
     *
     * @param string $runid The run.
     * @param string $type The kind of entry.
     * @param string $field The field.
     * @param string $value Its value.
     * @return array[]
     */
    private function entries_of(string $runid, string $type, string $field, string $value): array {
        [, $entries] = $this->manifest($runid);
        return array_values(array_filter($entries, fn(array $e): bool => $e['t'] === $type && ($e[$field] ?? null) === $value));
    }

    public function test_a_big_content_that_is_in_no_store_is_listed_as_missing(): void {
        $hash = $this->store_file('gone.bin', random_bytes(packer::FILE_LIMIT + 10));
        unlink(self::filedir($hash));
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains($hash, $header['missing_files']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'file', 'contenthash', $hash));
    }

    /**
     * The seam filesource of a file store whose content goes after its first piece, as if it was deleted meanwhile.
     *
     * @param string $hash The content that goes.
     * @param bool $failnext Whether the first file that a step packs after the content went fails once, with an error that is
     *     not a reason to stop.
     * @return callable
     */
    private static function vanishing_source(string $hash, bool $failnext = false): callable {
        return static function (\moodle_database $db) use ($hash, $failnext): file_source {
            return new class ($db, self::filedir($hash), $failnext) extends file_source {
                /** @var string The content that goes after its first piece. */
                private string $path;

                /** @var bool Whether the next file after the content went fails once. */
                private bool $failnext;

                /** @var bool Whether a piece of the content was asked for after it went. */
                private bool $gone = false;

                /**
                 * Constructor.
                 *
                 * @param \moodle_database $db Connection.
                 * @param string $path The content that goes.
                 * @param bool $failnext Whether the next file after it fails once.
                 */
                public function __construct(\moodle_database $db, string $path, bool $failnext) {
                    parent::__construct($db, static fn(int $seconds) => null);
                    $this->path = $path;
                    $this->failnext = $failnext;
                }

                /**
                 * Writes a piece; the content goes after its first piece.
                 *
                 * @param string $contenthash Content hash.
                 * @param int $size Size.
                 * @param int $piece Piece.
                 * @param string $digest Digest.
                 * @param packer $packer Packer.
                 * @return chunk_info|null
                 */
                public function big_piece(string $contenthash, int $size, int $piece, string $digest, packer $packer): ?chunk_info {
                    $chunk = parent::big_piece($contenthash, $size, $piece, $digest, $packer);
                    if ($piece === 0 && $contenthash === basename($this->path)) {
                        $this->close();
                        unlink($this->path);
                    }
                    $this->gone = $this->gone || $chunk === null;
                    return $chunk;
                }

                /**
                 * Packs a small file; the first one after the content went fails once when the test asks for it.
                 *
                 * @param string $contenthash Content hash.
                 * @param int $size Size.
                 * @param packer $packer Packer.
                 * @return chunk_info[]|null
                 */
                public function pack(string $contenthash, int $size, packer $packer): ?array {
                    if ($this->gone && $this->failnext) {
                        $this->failnext = false;
                        throw new \RuntimeException('A failure that is not a reason to stop.');
                    }
                    return parent::pack($contenthash, $size, $packer);
                }
            };
        };
    }

    public function test_a_big_content_that_goes_between_two_pieces_is_withdrawn(): void {
        $hash = $this->store_file('two.bin', random_bytes(packer::PIECE + 100));
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(['filesource' => self::vanishing_source($hash)]), $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains($hash, $header['missing_files']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'file', 'contenthash', $hash));
        // The first piece was sent: it is stored and in the index, with no entry.
        $piece = chunk_name::file(dedup_key::load_or_create(), $hash, 0);
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertArrayHasKey($piece, $sizes);
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_every_chunk_of_the_file_store_is_committed_before_the_watch(): void {
        $run = run_record::create('manual', $this->fixture->time);
        $cut = $this->runner(['sample' => function () use ($run): array {
            if ($run->stage() === 'files') {
                throw new \RuntimeException('The process was stopped.');
            }
            return self::CALM;
        }]);
        $this->assertSame('continue', $cut->step($run, 600));
        $this->assertSame('files', $run->stage());
        // The first pack or piece is committed with its entries before the watch stops the step.
        $this->assertGreaterThan(0, $run->progress()['cursor']['offset']);
        $this->assertGreaterThan(0, $run->entries_committed());
        $this->assertSame('done', $this->finish($this->runner(), $run));
        foreach ($this->hashes as $name => $hash) {
            $this->assertCount($name === 'missing' ? 0 : 1,
                $this->entries_of($run->run_uuid(), 'file', 'contenthash', $hash), $name);
        }
    }

    public function test_a_step_that_fails_after_a_big_content_was_withdrawn_is_gone_on_with(): void {
        $hash = $this->store_file('two.bin', random_bytes(packer::PIECE + 100));
        // A small file after it in the order of the content hash, which the step packs before its next commit.
        do {
            $small = random_bytes(64);
        } while (strcmp(sha1($small), $hash) <= 0);
        $after = $this->store_file('after.bin', $small);
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['filesource' => self::vanishing_source($hash, true)]);
        $this->assertSame('done', $this->finish($runner, $run), (string) $run->abort_code());
        $this->assertContains('The backup step failed and is tried again: RuntimeException', $this->logged);
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains($hash, $header['missing_files']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'file', 'contenthash', $hash));
        $this->assertCount(1, $this->entries_of($run->run_uuid(), 'file', 'contenthash', $after));
    }

    public function test_the_pieces_of_a_big_file_that_the_present_list_holds_are_not_sent_again(): void {
        $hash = $this->store_file('two.bin', random_bytes(packer::PIECE + 100));
        $first = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $first));
        $key = dedup_key::load_or_create();
        $kept = chunk_name::file($key, $hash, 0);
        $lost = chunk_name::file($key, $hash, 1);
        // The storage has lost the second piece: the list has the first only.
        unset($this->fixture->service->state['objects']['sites/' . self::SITE . '/objects/' . $lost]);
        $this->publish_present_list();
        $this->fixture->advance(86400);
        $before = $this->fixture->puts();
        $second = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $second));
        $after = $this->fixture->puts();
        $keptkey = 'sites/' . self::SITE . '/objects/' . $kept;
        $lostkey = 'sites/' . self::SITE . '/objects/' . $lost;
        $this->assertSame($before[$keptkey], $after[$keptkey]);
        $this->assertSame(1, $after[$lostkey] - ($before[$lostkey] ?? 0));
        $pieces = $this->entries_of($second->run_uuid(), 'file', 'contenthash', $hash);
        $this->assertSame([0, 1], array_column($pieces, 'piece'));
        $this->assertSame([$kept, $lost], array_column($pieces, 'chunk'));
        // The index of the second run has both pieces, and every chunk in it is stored.
        [, $sizes] = $this->index($second->run_uuid());
        $this->assertArrayHasKey($kept, $sizes);
        $this->assertArrayHasKey($lost, $sizes);
        $this->assertSame([], array_diff_key($sizes, $this->stored_chunks()));
    }

    public function test_small_files_that_fill_a_pack_are_sent_while_the_stage_goes_on(): void {
        global $DB;
        // Without a big file between them in the order of the hashes, which would send a part of the pack first.
        $DB->delete_records('files', ['contenthash' => $this->hashes['big']]);
        // A pack is written once its files reach 32 MiB. Nine files of almost 4 MiB reach it, whatever the order of the hashes,
        // so with ten the pack is written while the stage goes on and at least one file comes after it.
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $hashes[] = $this->store_file('full' . $i . '.bin', random_bytes(packer::FILE_LIMIT - 1));
        }
        $run = run_record::create('manual', $this->fixture->time);
        $sent = [];
        $this->fixture->intercept = function (RequestInterface $request) use ($run, &$sent) {
            if ($request->getMethod() === 'PUT') {
                $sent[] = [$run->stage(), (int) $request->getBody()->getSize()];
            }
            return null;
        };
        // No room: whatever waits is sent before the next chunk is made, so the full pack goes before the next file.
        $runner = $this->runner(['limits' => $this->limits(0), 'diskspace' => fn(string $folder): array => [0, 1000]]);
        $this->assertSame('done', $this->finish($runner, $run));
        $chunks = [];
        foreach ($hashes as $hash) {
            $entries = $this->entries_of($run->run_uuid(), 'file', 'contenthash', $hash);
            $this->assertCount(1, $entries, $hash);
            $chunks[$entries[0]['chunk']] = true;
        }
        $this->assertGreaterThan(1, count($chunks));
        $full = array_filter($sent, static fn(array $put): bool => $put[0] === 'files' && $put[1] >= packer::PACK_AT);
        $this->assertNotEmpty($full, 'The full pack was sent while the run was in the stage of the files.');
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_small_file_before_a_big_one_is_sent_in_a_chunk_of_its_own(): void {
        file_put_contents($this->dataroot . '/a.txt', 'small before');
        file_put_contents($this->dataroot . '/b.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        $run = run_record::create('manual', $this->fixture->time);
        $this->assertSame('done', $this->finish($this->runner(), $run));
        $small = $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'a.txt');
        $big = $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'b.bin');
        $later = $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'lang/ja/langconfig.php');
        $this->assertCount(1, $small);
        $this->assertCount(1, $big);
        // The chunk of the small file was closed before the big file: the small files after it are in another one.
        $this->assertNotSame($small[0]['chunk'], $later[0]['chunk']);
        $this->assertNotSame($small[0]['chunk'], $big[0]['chunk']);
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_chunk_of_small_files_that_cannot_be_sent_before_a_big_file_makes_the_stage_wait(): void {
        // Eight files of almost 4 MiB fill a chunk (32 MiB); the ninth starts the next, which waits when the big file (next in the
        // walk) comes.
        for ($i = 0; $i < 9; $i++) {
            file_put_contents($this->dataroot . '/f' . $i . '.bin', random_bytes(series_writer::FILE_LIMIT - 1));
        }
        file_put_contents($this->dataroot . '/g.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        $run = run_record::create('manual', $this->fixture->time);
        $busy = 0;
        $this->fixture->intercept = function (RequestInterface $request) use ($run, &$busy) {
            if (!str_ends_with($request->getUri()->getPath(), '/upload-urls') || $run->stage() !== 'dataroot' || $busy > 0) {
                return null;
            }
            $items = json_decode((string) $request->getBody(), true)['items'];
            $request->getBody()->rewind();
            if (max(array_column($items, 'size')) < 30 * 1048576) {
                return null;
            }
            // The full chunk of small files is to be sent: the service is busy.
            $busy++;
            return new Response(503, ['Retry-After' => '120'], json_encode(['error' => ['code' => 'busy']]));
        };
        $runner = $this->runner(['limits' => $this->limits(0), 'diskspace' => fn(string $folder): array => [0, 1000]]);
        $result = '';
        for ($i = 0; $i < 50 && $busy === 0; $i++) {
            $result = $runner->step($run, 600);
            if ($run->next_attempt() !== null && $run->next_attempt() > $this->fixture->time) {
                $this->fixture->set_time($run->next_attempt());
            }
        }
        $this->assertSame(1, $busy);
        $this->assertSame('continue', $result);
        $this->assertSame('dataroot', $run->stage());
        $this->assertNull($run->progress()['cursor']['big'] ?? null, 'The big file was not begun.');
        // The ninth file still waits in its chunk, which was not closed: the stage goes on from after the eighth.
        $this->assertSame('f7.bin', $run->progress()['cursor']['position']);
        $this->assertSame('done', $this->finish($runner, $run));
        foreach (['f0.bin', 'f8.bin', 'g.bin'] as $path) {
            $this->assertCount(1, $this->entries_of($run->run_uuid(), 'dataroot', 'path', $path), $path);
        }
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_big_file_of_the_data_root_that_goes_before_it_is_read_is_unreadable(): void {
        file_put_contents($this->dataroot . '/a.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        file_put_contents($this->dataroot . '/b.bin', random_bytes(series_writer::FILE_LIMIT + 10));
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['sample' => function () use ($run): array {
            // Once the first big file is made, the second goes: the walk listed it with its size.
            if ($run->stage() === 'dataroot' && is_file($this->dataroot . '/b.bin')) {
                unlink($this->dataroot . '/b.bin');
            }
            return self::CALM;
        }]);
        $this->assertSame('done', $this->finish($runner, $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains('dataroot:b.bin', $header['unreadable']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'b.bin'));
        $this->assertCount(1, $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'a.bin'));
    }

    public function test_a_step_without_time_stops_between_two_pieces_of_a_big_file_of_the_data_root(): void {
        file_put_contents($this->dataroot . '/big.bin', random_bytes(series_writer::PIECE + 100));
        $run = run_record::create('manual', $this->fixture->time);
        $runner = $this->runner(['timer' => $this->fast_timer()]);
        $between = false;
        $result = '';
        for ($i = 0; $i < 200 && $result !== 'done'; $i++) {
            $result = $runner->step($run, 1);
            $between = $between || ($run->stage() === 'dataroot' && ($run->progress()['cursor']['big']['piece'] ?? 0) === 1);
            if ($run->next_attempt() !== null && $run->next_attempt() > $this->fixture->time) {
                $this->fixture->set_time($run->next_attempt());
            }
        }
        $this->assertSame('done', $result);
        $this->assertTrue($between, 'A step ended after the first piece.');
        $this->assertSame([0, 1], array_column($this->entries_of($run->run_uuid(), 'dataroot', 'path', 'big.bin'), 'piece'));
        foreach ($this->fixture->puts() as $key => $count) {
            $this->assertSame(1, $count, $key);
        }
    }

    public function test_a_big_file_of_the_data_root_that_goes_while_it_is_in_progress_is_withdrawn(): void {
        file_put_contents($this->dataroot . '/big.bin', random_bytes(series_writer::PIECE + 100));
        $run = run_record::create('manual', $this->fixture->time);
        $cut = $this->runner(['sample' => function () use ($run): array {
            if ($run->stage() === 'dataroot') {
                throw new \RuntimeException('The process was stopped.');
            }
            return self::CALM;
        }]);
        $this->assertSame('continue', $cut->step($run, 600));
        $this->assertSame(1, $run->progress()['cursor']['big']['piece']);
        unlink($this->dataroot . '/big.bin');
        $this->assertSame('done', $this->finish($this->runner(), $run));
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains('dataroot:big.bin', $header['unreadable']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'big.bin'));
        [, $sizes] = $this->index($run->run_uuid());
        $this->assertSame($this->stored_chunks(), $sizes);
    }

    public function test_a_step_that_fails_after_a_big_file_of_the_data_root_was_withdrawn_is_gone_on_with(): void {
        file_put_contents($this->dataroot . '/big.bin', random_bytes(series_writer::PIECE + 100));
        file_put_contents($this->dataroot . '/zz.txt', 'after the big file');
        $run = run_record::create('manual', $this->fixture->time);
        $folder = $this->tempdir . '/run-' . $run->id();
        $changed = false;
        $thrown = false;
        $runner = $this->runner([
            // The big file changes after its first piece, so that its second piece is not the one of the first read.
            'sample' => function () use ($run, $folder, &$changed): array {
                if ($run->stage() === 'dataroot' && !$changed && count(self::chunk_files($folder)) > 0) {
                    $changed = true;
                    $handle = fopen($this->dataroot . '/big.bin', 'r+b');
                    fseek($handle, series_writer::PIECE + 10);
                    fwrite($handle, 'changed');
                    fclose($handle);
                }
                return self::CALM;
            },
            // Once the file is withdrawn, the next look at the time fails, before the step commits again.
            'timer' => function () use ($folder, &$thrown): float {
                $unreadable = $folder . '/journal/unreadable.jsonl';
                if (!$thrown && is_file($unreadable) && str_contains((string) file_get_contents($unreadable), 'big.bin')) {
                    $thrown = true;
                    throw new \RuntimeException('A failure that is not a reason to stop.');
                }
                return microtime(true);
            },
        ]);
        $this->assertSame('done', $this->finish($runner, $run), (string) $run->abort_code());
        $this->assertTrue($thrown);
        $this->assertContains('The backup step failed and is tried again: RuntimeException', $this->logged);
        [$header] = $this->manifest($run->run_uuid());
        $this->assertContains('dataroot:big.bin', $header['unreadable']);
        $this->assertSame([], $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'big.bin'));
        $this->assertCount(1, $this->entries_of($run->run_uuid(), 'dataroot', 'path', 'zz.txt'));
    }
}
