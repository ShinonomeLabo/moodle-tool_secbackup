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

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use tool_secbackup\local\api\client;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;
use tool_secbackup\tests\trace_strings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\uploader::class)]
/**
 * Tests for the sending of the chunks of a run, against the fake service and its storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\uploader
 */
final class uploader_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The clock of the tests. */
    private const NOW = 1760000000;

    /** @var string The secret part of every URL of the fake storage. */
    private const URL_SECRET = 'X-Amz-Signature=0123456789abcdef';

    /** @var fake_service The service. */
    private fake_service $service;

    /** @var signing_keypair The key pair of the site. */
    private signing_keypair $pair;

    /** @var int The clock that the uploader and the client read. */
    private int $time = self::NOW;

    /** @var callable|null Replaces the answer of the network: function (RequestInterface): ?Response. */
    private $intercept = null;

    /** @var string[] Bodies of the requests to the service, by "METHOD path". */
    private array $seen = [];

    /** @var string The folder of the temporary files. */
    private string $tempdir;

    /** @var string|false The value of zend.exception_ignore_args before the test. */
    private $ignoreargs;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The traces of the exceptions keep their arguments, so that a test can look for a secret in them.
        $this->ignoreargs = ini_set('zend.exception_ignore_args', '0');
        network_fakes::no_security_list();
        $this->pair = signing_keypair::generate();
        $this->service = new fake_service(self::API, array_merge(
            fake_service::initial_state(),
            ['now' => self::NOW, 'signingkey' => base64_encode($this->pair->public_key())]
        ));
        $this->tempdir = make_request_directory();
    }

    protected function tearDown(): void {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreargs);
        parent::tearDown();
    }

    /**
     * The network: the fake service, with a hook for a test to answer a request itself.
     *
     * @return callable
     */
    private function network(): callable {
        $inner = $this->service->handler();
        return function (RequestInterface $request, array $options) use ($inner) {
            $this->seen[] = $request->getMethod() . ' ' . $request->getUri()->getPath() . "\n" . (string) $request->getBody();
            $request->getBody()->rewind();
            $answer = $this->intercept === null ? null : ($this->intercept)($request);
            return $answer === null ? $inner($request, $options) : Create::promiseFor($answer);
        };
    }

    /**
     * The uploader, a record of a run that has started at the fake service, and its temporary folder.
     *
     * @param int $estimate Size that the run expects to store.
     * @param int $budgetmb Budget of the temporary files in MB, or 0.
     * @param callable|null $diskspace Measure of the disk.
     * @param int $bandwidth Bandwidth setting of this site in Mbps.
     * @param callable|null $unmade Bytes of the run that are not made yet.
     * @param callable|null $remove Removes a file.
     * @return array [uploader, run_record, folder]
     */
    private function start(
        int $estimate = 1000000,
        int $budgetmb = 100,
        ?callable $diskspace = null,
        int $bandwidth = 0,
        ?callable $unmade = null,
        ?callable $remove = null
    ): array {
        $settings = developer_settings::from_value(['allowprivatehosts' => true, 'skipnetworkchecks' => true]);
        $clock = fn(): int => $this->time;
        $client = new client(
            new transport($settings, network_fakes::passing_guard(), $this->network()),
            network_fakes::trust(),
            $settings,
            $clock
        );
        $objects = new object_transport($settings, network_fakes::passing_s3_guard(), $this->network());
        $endpoint = endpoint::parse(self::API, developer_settings::from_value(null));
        $started = $client->start_run($endpoint, $this->pair, fake_service::SITE_ID);
        $client->capacity($endpoint, $this->pair, fake_service::SITE_ID, $started->run_id(), $estimate);
        $limits = new limits(2.0, 256, 500, 5.0, $this->tempdir, $budgetmb, $bandwidth);
        $temp = new temp_area($limits, $diskspace);
        $run = run_record::create('manual', self::NOW);
        $run->set_run_uuid($started->run_id());
        $uploader = new uploader(
            $client,
            $objects,
            $temp,
            $limits,
            fn(): array => [$endpoint, $this->pair, fake_service::SITE_ID],
            $clock,
            0,
            $unmade,
            $remove
        );
        return [$uploader, $run, $temp->for_run($run->id()), $client, $endpoint];
    }

    /**
     * Writes a chunk file in the folder of the run.
     *
     * @param string $folder Folder.
     * @param int $n Number of the chunk, which makes its name and its content.
     * @param int $size Bytes.
     * @return chunk_info
     */
    private static function chunk(string $folder, int $n, int $size = 100): chunk_info {
        $content = substr(str_repeat(hash('sha256', 'chunk' . $n, true), (int) ceil($size / 32)), 0, $size);
        $path = $folder . '/c' . $n;
        file_put_contents($path, $content);
        return new chunk_info($path, hash('sha256', 'name' . $n), $size, base64_encode(hash('sha256', $content, true)), []);
    }

    /**
     * How many requests of a kind the service got.
     *
     * @param string $suffix The end of the path.
     * @return int
     */
    private function posts(string $suffix): int {
        $posts = array_filter($this->seen, fn(string $s) => str_starts_with($s, 'POST '));
        return count(array_filter($posts, fn(string $s) => str_contains(explode("\n", $s)[0], $suffix)));
    }

    /**
     * The code of the exception that a call throws.
     *
     * @param callable $call The call.
     * @return plugin_exception
     */
    private function failure(callable $call): plugin_exception {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }

    /**
     * The answer of a PUT to the storage while the intercept says so.
     *
     * @param int $status Status to answer.
     * @param int $times How many PUTs get it.
     * @param array $headers Headers of the answer.
     */
    private function refuse_puts(int $status, int $times, array $headers = []): void {
        $this->intercept = function (RequestInterface $request) use ($status, &$times, $headers): ?Response {
            if ($request->getMethod() === 'PUT' && $times > 0) {
                $times--;
                return new Response($status, $headers);
            }
            return null;
        };
    }

    public function test_257_chunks_are_sent_with_two_requests_for_urls_and_their_files_are_removed(): void {
        [$uploader, $run, $folder] = $this->start();
        $names = [];
        for ($i = 0; $i < 257; $i++) {
            $chunk = self::chunk($folder, $i);
            $names[] = $chunk->name;
            $uploader->queue($chunk, 'object');
        }
        $this->assertSame(257, $uploader->pending());
        $uploader->drain($run, true);
        $this->assertSame(2, $this->posts('/upload-urls'));
        $this->assertSame(0, $uploader->pending());
        $this->assertCount(257, $this->service->state['objects']);
        foreach ($names as $name) {
            $this->assertArrayHasKey('sites/' . fake_service::SITE_ID . '/objects/' . $name, $this->service->state['objects']);
        }
        $this->assertSame([], glob($folder . '/c*'), 'The files are removed after the storage took them.');
        $stats = $this->run_row($run);
        $this->assertSame(257, (int) $stats->chunksuploaded);
        $this->assertSame(257 * 100, (int) $stats->bytesuploaded);
    }

    /**
     * The row of the run.
     *
     * @param run_record $run The run.
     * @return \stdClass
     */
    private function run_row(run_record $run): \stdClass {
        global $DB;
        return $DB->get_record('tool_secbackup_runs', ['id' => $run->id()], '*', MUST_EXIST);
    }

    public function test_without_force_nothing_is_sent_until_a_batch_is_full(): void {
        [$uploader, $run, $folder] = $this->start();
        for ($i = 0; $i < 255; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        $uploader->drain($run, false);
        $this->assertSame(0, $this->posts('/upload-urls'));
        $uploader->queue(self::chunk($folder, 255), 'object');
        $uploader->drain($run, false);
        $this->assertSame(1, $this->posts('/upload-urls'));
        $this->assertSame(0, $uploader->pending());
        $uploader->drain($run, true);
        $this->assertSame(1, $this->posts('/upload-urls'), 'An empty queue asks for nothing.');
    }

    public function test_the_manifest_and_the_index_are_sent_to_their_keys_and_may_be_sent_again(): void {
        [$uploader, $run, $folder] = $this->start();
        $manifest = self::chunk($folder, 1, 300);
        $index = self::chunk($folder, 2, 200);
        $uploader->queue($manifest, 'manifest');
        $uploader->queue($index, 'index');
        $uploader->drain($run, true);
        $keys = array_keys($this->service->state['objects']);
        sort($keys);
        $uuid = $run->run_uuid();
        $this->assertSame([
            'sites/' . fake_service::SITE_ID . '/indexes/' . $uuid,
            'sites/' . fake_service::SITE_ID . '/manifests/' . $uuid,
        ], $keys);
        $again = self::chunk($folder, 3, 300);
        $uploader->queue($again, 'manifest');
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        // Neither is counted as a chunk of the run.
        $this->assertSame(0, (int) $this->run_row($run)->chunksuploaded);
    }

    public function test_a_chunk_that_is_stored_already_counts_as_sent(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $key = 'sites/' . fake_service::SITE_ID . '/objects/' . $chunk->name;
        $this->service->state['objects'][$key] = base64_encode('the first version');
        $uploader->queue($chunk, 'object');
        $uploader->drain($run, true);
        $this->assertSame('the first version', base64_decode($this->service->state['objects'][$key]));
        $this->assertFileDoesNotExist($chunk->path);
        $this->assertSame(0, $uploader->pending());
    }

    public function test_the_same_chunk_is_queued_once(): void {
        [$uploader, , $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        $uploader->queue($chunk, 'object');
        $this->assertSame(1, $uploader->pending());
        $this->assertSame(100, $uploader->pending_bytes());
        $this->assertSame([$chunk->name], $uploader->queued_names());
    }

    public function test_a_chunk_that_is_not_there_or_a_kind_that_is_unknown_is_a_coding_error(): void {
        [$uploader, , $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        try {
            $uploader->queue($chunk, 'present');
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertSame(0, $uploader->pending());
        }
        unlink($chunk->path);
        $this->expectException(\coding_exception::class);
        $uploader->queue($chunk, 'object');
    }

    public function test_the_size_is_asked_again_when_the_service_asks_for_it(): void {
        // The service gives 110% of 25,600 bytes: the second batch of 44 chunks does not fit.
        [$uploader, $run, $folder] = $this->start(25600);
        for ($i = 0; $i < 300; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        $capacitybefore = $this->posts('/capacity');
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $this->assertSame($capacitybefore + 1, $this->posts('/capacity'));
        // Granted so far (256 x 100) plus what was not given a URL yet: the refused batch of 44 x 100 is in it.
        $bodies = array_values(array_filter($this->seen, fn(string $s) => str_contains($s, '/capacity')));
        $this->assertSame('{"estimated_bytes":30000}', explode("\n", end($bodies))[1]);
        $this->assertSame(30000, (int) $this->run_row($run)->estimatedbytes);
    }

    public function test_the_new_size_holds_the_part_of_the_run_that_is_not_made_yet(): void {
        $unmade = 5000;
        [$uploader, $run, $folder] = $this->start(25600, 100, null, 0, function () use (&$unmade): int {
            return $unmade;
        });
        for ($i = 0; $i < 300; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        $uploader->drain($run, true);
        $bodies = array_values(array_filter($this->seen, fn(string $s) => str_contains($s, '/capacity')));
        // Granted so far (25,600), what waits (4,400) and what the run has not made yet (5,000).
        $this->assertSame('{"estimated_bytes":35000}', explode("\n", end($bodies))[1]);
        $this->assertSame(35000, (int) $this->run_row($run)->estimatedbytes);
    }

    public function test_a_refusal_of_the_new_size_stops_the_run_and_asks_for_no_more_urls(): void {
        [$uploader, $run, $folder] = $this->start(100);
        $this->intercept = function (RequestInterface $request): ?Response {
            if (str_ends_with($request->getUri()->getPath(), '/capacity')) {
                return new Response(200, [], '{"decision":"refuse","limit_bytes":100,"used_bytes":0}');
            }
            return null;
        };
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('capacity_refused', $e->errorcode);
        $this->assertSame(1, $this->posts('/upload-urls'));
        $this->assertSame([], $this->service->state['objects']);
        // The chunks stay for the run to clear; the queue is still there.
        $this->assertSame(3, $uploader->pending());
    }

    public function test_a_contract_that_ended_stops_the_run(): void {
        [$uploader, $run, $folder] = $this->start();
        $this->service->state['contractinactive'] = true;
        $uploader->queue(self::chunk($folder, 1), 'object');
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('contract_inactive', $e->errorcode);
    }

    /**
     * Answers that make the uploader wait for the time of Retry-After.
     *
     * @return array
     */
    public static function waiting_provider(): array {
        return [
            'the service is busy' => [503, '120', 120],
            'the run limit' => [429, '3600', 3600],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('waiting_provider')]
    /**
     * A busy service and a run limit make the run wait for the time that the service said, and nothing more.
     *
     * @dataProvider waiting_provider
     * @param int $status Status of the answer.
     * @param string $retry Retry-After.
     * @param int $seconds The wait.
     */
    public function test_the_wait_is_the_retry_after_of_the_service(int $status, string $retry, int $seconds): void {
        global $DB;
        [$uploader, $run, $folder] = $this->start();
        $this->intercept = function (RequestInterface $request) use ($status, $retry): ?Response {
            if (str_ends_with($request->getUri()->getPath(), '/upload-urls')) {
                $body = $status === 429 ? '{"error":{"code":"run_limit_reached","message":"m","retry_after":3600}}' : '';
                return new Response($status, ['Retry-After' => $retry], $body);
            }
            return null;
        };
        $uploader->queue(self::chunk($folder, 1), 'object');
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + $seconds, $uploader->waiting_until());
        $row = $this->run_row($run);
        $this->assertSame(['paused', self::NOW + $seconds], [$row->status, (int) $row->nextattempt]);
        $this->assertSame(1, $uploader->pending());
        // Until then nothing is asked.
        $asked = $this->posts('/upload-urls');
        $this->time = self::NOW + $seconds - 1;
        $uploader->drain($run, true);
        $this->assertSame($asked, $this->posts('/upload-urls'));
        // After it the work goes on.
        $this->intercept = null;
        $this->time = self::NOW + $seconds;
        $this->service->state['now'] = $this->time;
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $this->assertNull($uploader->waiting_until());
    }

    public function test_a_busy_service_without_retry_after_is_waited_for_a_minute(): void {
        [$uploader, $run, $folder] = $this->start();
        $this->intercept = fn(RequestInterface $r): ?Response => str_ends_with($r->getUri()->getPath(), '/upload-urls')
            ? new Response(503) : null;
        $uploader->queue(self::chunk($folder, 1), 'object');
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 60, $uploader->waiting_until());
    }

    public function test_a_failed_send_is_tried_again_after_15_minutes_and_the_third_failure_stops_the_run(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        $this->refuse_puts(500, 99);
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 900, $uploader->waiting_until());
        $this->assertSame(['paused', self::NOW + 900], [$this->run_row($run)->status, (int) $this->run_row($run)->nextattempt]);
        $this->assertFileExists($chunk->path);
        $puts = count(array_filter($this->seen, fn($s) => str_starts_with($s, 'PUT ')));
        $this->assertSame(1, $puts);
        // 15 minutes less a second later: nothing is tried.
        $this->time = self::NOW + 899;
        $uploader->drain($run, true);
        $this->assertCount($puts, array_filter($this->seen, fn($s) => str_starts_with($s, 'PUT ')));
        // The second failure.
        $this->time = self::NOW + 900;
        $this->service->state['now'] = $this->time;
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 1800, $uploader->waiting_until());
        // The third one stops the run.
        $this->time = self::NOW + 1800;
        $this->service->state['now'] = $this->time;
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('upload_failed', $e->errorcode);
        $this->assertStringNotContainsString('Signature', $e->getMessage() . $e->debuginfo);
        $this->assertFileExists($chunk->path);
    }

    public function test_a_send_that_succeeds_after_a_failure_removes_the_file(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunks = [self::chunk($folder, 1), self::chunk($folder, 2)];
        foreach ($chunks as $chunk) {
            $uploader->queue($chunk, 'object');
        }
        $this->refuse_puts(500, 1);
        $uploader->drain($run, true);
        $this->assertSame(2, $uploader->pending(), 'The first fails, and the second waits behind it.');
        $this->time = self::NOW + 900;
        $this->service->state['now'] = $this->time;
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        foreach ($chunks as $chunk) {
            $this->assertFileDoesNotExist($chunk->path);
        }
    }

    /**
     * Answers of the storage that are tried again.
     *
     * @return array
     */
    public static function storage_answer_provider(): array {
        return [
            'a server error' => [500],
            'a refusal of the signature' => [403],
            'a conflict of a conditional request' => [409],
            'a slow down' => [503],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storage_answer_provider')]
    /**
     * Whatever the storage refuses with is a send failure that goes to the retry.
     *
     * @dataProvider storage_answer_provider
     * @param int $status Status of the answer.
     */
    public function test_a_refusal_of_the_storage_goes_to_the_retry(int $status): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->refuse_puts($status, 1);
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 900, $uploader->waiting_until());
    }

    public function test_the_queue_is_kept_for_the_next_step_without_a_url_in_it(): void {
        [$uploader, $run, $folder, $client, $endpoint] = $this->start();
        $chunks = [self::chunk($folder, 1), self::chunk($folder, 2), self::chunk($folder, 3)];
        foreach ($chunks as $chunk) {
            $uploader->queue($chunk, 'object');
        }
        $this->refuse_puts(500, 1);
        $uploader->drain($run, true);
        $saved = file_get_contents($folder . '/uploads.json');
        $this->assertStringNotContainsString('://', $saved);
        $this->assertStringNotContainsString('Signature', $saved);
        // A new uploader, as in the next step, takes the queue from the folder.
        $limits = new limits(2.0, 256, 500, 5.0, $this->tempdir, 100, 0);
        $settings = developer_settings::from_value(['allowprivatehosts' => true, 'skipnetworkchecks' => true]);
        $second = new uploader(
            $client,
            new object_transport($settings, network_fakes::passing_s3_guard(), $this->network()),
            new temp_area($limits),
            $limits,
            fn(): array => [$endpoint, $this->pair, fake_service::SITE_ID],
            fn(): int => $this->time
        );
        $second->resume($run);
        $this->assertSame(3, $second->pending());
        $this->assertEqualsCanonicalizing(array_map(fn($c) => $c->name, $chunks), $second->queued_names());
        $this->assertSame(self::NOW + 900, $second->waiting_until());
        $this->time = self::NOW + 900;
        $this->service->state['now'] = $this->time;
        $this->intercept = null;
        $second->drain($run, true);
        $this->assertSame(0, $second->pending());
        $this->assertCount(3, $this->service->state['objects']);
        $this->assertSame([], $this->saved($folder)['items']);
    }

    public function test_a_queue_whose_file_is_gone_is_dropped_on_resume(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        $this->refuse_puts(500, 1);
        $uploader->drain($run, true);
        unlink($chunk->path);
        $uploader->resume($run);
        $this->assertSame(0, $uploader->pending());
    }

    public function test_the_temporary_files_stay_inside_the_budget(): void {
        // One MB of budget, chunks of 300 KB: a chunk waits for others only while there is room for the next one.
        [$uploader, $run, $folder] = $this->start(100000000, 1);
        $budget = 1048576;
        for ($i = 0; $i < 12; $i++) {
            $uploader->queue(self::chunk($folder, $i, 300000), 'object');
            $uploader->drain($run, false);
            $used = (new temp_area(new limits(2.0, 256, 500, 5.0, $this->tempdir, 1, 0)))->used_bytes($run->id());
            $this->assertLessThanOrEqual($budget - 300000, $used, 'There is room for the next chunk after each step.');
        }
        $uploader->drain($run, true);
        $this->assertCount(12, $this->service->state['objects']);
    }

    public function test_with_no_budget_nothing_is_kept_beyond_the_chunk_in_hand(): void {
        $nospace = static fn(string $folder): array => [0, 0];
        [$uploader, $run, $folder] = $this->start(1000000, 0, $nospace);
        for ($i = 0; $i < 3; $i++) {
            $chunk = self::chunk($folder, $i);
            $uploader->queue($chunk, 'object');
            $uploader->drain($run, false);
            $this->assertFileDoesNotExist($chunk->path, 'A budget of zero is no room: the chunk goes at once.');
            $this->assertSame(0, $uploader->pending());
        }
        $this->assertCount(3, $this->service->state['objects']);
    }

    public function test_the_urls_are_asked_for_again_when_they_expire_during_a_long_batch(): void {
        [$uploader, $run, $folder] = $this->start();
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        // The first send takes 20 minutes; the URLs that were given for the others have expired by then.
        $this->intercept = function (RequestInterface $request): ?Response {
            if ($request->getMethod() === 'PUT' && count(array_filter($this->seen, fn($s) => str_starts_with($s, 'PUT '))) === 1) {
                $this->time += 1200;
                $this->service->state['now'] = $this->time;
            }
            return null;
        };
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $this->assertSame(2, $this->posts('/upload-urls'));
        $this->assertCount(3, $this->service->state['objects']);
    }

    public function test_the_bandwidth_is_the_smaller_of_the_two_settings_that_is_not_zero(): void {
        $this->assertSame(0, uploader::bandwidth_bytes(0, 0));
        $this->assertSame(10 * 125000, uploader::bandwidth_bytes(10, 0));
        $this->assertSame(20 * 125000, uploader::bandwidth_bytes(0, 20));
        $this->assertSame(10 * 125000, uploader::bandwidth_bytes(10, 20));
        $this->assertSame(10 * 125000, uploader::bandwidth_bytes(20, 10));
    }

    public function test_the_limit_of_the_speed_is_given_to_the_transport(): void {
        [$uploader, $run, $folder] = $this->start(1000000, 100, null, 8);
        $uploader->queue(self::chunk($folder, 1), 'object');
        $uploader->drain($run, true);
        $this->assertSame(8 * 125000, $uploader->bandwidth());
    }

    public function test_a_run_without_an_id_at_the_service_is_a_coding_error(): void {
        [$uploader, , $folder] = $this->start();
        $run = run_record::create('manual', self::NOW);
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->expectException(\coding_exception::class);
        $uploader->drain($run, true);
    }

    public function test_the_link_is_the_registration_of_the_site(): void {
        $this->assertSame('api_site_not_enrolled', $this->failure(fn() => uploader::site_link())->errorcode);
        set_config('endpoint', self::API, 'tool_secbackup');
        set_config('siteid', fake_service::SITE_ID, 'tool_secbackup');
        // Registered, but without a key pair: nothing can be signed.
        $this->assertSame('api_site_not_enrolled', $this->failure(fn() => uploader::site_link())->errorcode);
        signing_keys::save($this->pair);
        [$endpoint, $pair, $siteid] = uploader::site_link();
        $this->assertSame(self::API, $endpoint->url());
        $this->assertSame($this->pair->public_key(), $pair->public_key());
        $this->assertSame(fake_service::SITE_ID, $siteid);
    }

    public function test_a_refusal_that_is_not_about_waiting_is_thrown_as_it_is(): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->intercept = fn(RequestInterface $r): ?Response => str_ends_with($r->getUri()->getPath(), '/upload-urls')
            ? new Response(409, [], '{"error":{"code":"run_state_invalid","message":"m","status":"verifying"}}') : null;
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('api_run_state_invalid', $e->errorcode);
        $this->assertSame(1, $uploader->pending(), 'The queue is kept.');
    }

    public function test_the_size_is_not_asked_again_without_end(): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->intercept = function (RequestInterface $request): ?Response {
            if (str_ends_with($request->getUri()->getPath(), '/upload-urls')) {
                $body = '{"error":{"code":"capacity_recheck_required","message":"m","requested_bytes":5,"estimated_bytes":5}}';
                return new Response(409, [], $body);
            }
            return null;
        };
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('api_capacity_recheck_required', $e->errorcode);
        $this->assertSame(3, $this->posts('/capacity') - 1, 'The size is checked three times, then the answer is thrown.');
    }

    public function test_an_answer_without_the_bytes_that_were_given_is_thrown_as_it_is(): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->intercept = fn(RequestInterface $r): ?Response => str_ends_with($r->getUri()->getPath(), '/upload-urls')
            ? new Response(409, [], '{"error":{"code":"capacity_recheck_required","message":"m"}}') : null;
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('api_capacity_recheck_required', $e->errorcode);
        $this->assertSame(0, $this->posts('/capacity') - 1);
    }

    public function test_the_manifest_that_is_queued_again_replaces_the_first(): void {
        [$uploader, , $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1, 300), 'manifest');
        $uploader->queue(self::chunk($folder, 2, 500), 'manifest');
        $this->assertSame(1, $uploader->pending());
        $this->assertSame(500, $uploader->pending_bytes());
        $this->assertSame([], $uploader->queued_names(), 'The manifest has no chunk name.');
    }

    public function test_a_saved_queue_that_is_damaged_is_read_as_far_as_it_is_good(): void {
        [$uploader, $run, $folder] = $this->start();
        $good = self::chunk($folder, 1);
        $other = make_request_directory() . '/outside';
        file_put_contents($other, 'x');
        $sha = $good->sha256base64;
        $items = [
            ['kind' => 'object', 'name' => $good->name, 'path' => $good->path, 'size' => 100, 'sha256' => $sha, 'attempts' => 1],
            'text',
            ['kind' => 'object', 'name' => 'short', 'path' => $good->path, 'size' => 100, 'sha256' => $sha, 'attempts' => 0],
            ['kind' => 'present', 'name' => null, 'path' => $good->path, 'size' => 100, 'sha256' => $sha, 'attempts' => 0],
            ['kind' => 'index', 'name' => 'x', 'path' => $good->path, 'size' => 100, 'sha256' => $sha, 'attempts' => 0],
            ['kind' => 'object', 'name' => $good->name, 'path' => $other, 'size' => 1, 'sha256' => $sha, 'attempts' => 0],
            ['kind' => 'object', 'name' => $good->name, 'path' => $good->path, 'size' => 0, 'sha256' => $sha, 'attempts' => 0],
            ['kind' => 'object', 'name' => $good->name, 'path' => $good->path, 'size' => 100, 'sha256' => 'x', 'attempts' => 0],
            ['kind' => 'object', 'name' => $good->name, 'path' => $good->path, 'size' => 100, 'sha256' => $sha, 'attempts' => -1],
        ];
        file_put_contents($folder . '/uploads.json', json_encode(['v' => 1, 'retryat' => 'soon', 'items' => $items]));
        $uploader->resume($run);
        $this->assertSame([$good->name], $uploader->queued_names());
        $this->assertNull($uploader->waiting_until());
        file_put_contents($folder . '/uploads.json', 'not json');
        $uploader->resume($run);
        $this->assertSame(0, $uploader->pending());
    }

    public function test_a_queue_that_cannot_be_saved_is_a_failure_to_write(): void {
        [$uploader, , $folder] = $this->start();
        mkdir($folder . '/uploads.json.part');
        $e = $this->failure(fn() => $uploader->queue(self::chunk($folder, 1), 'object'));
        $this->assertSame('temp_write_failed', $e->errorcode);
    }

    public function test_a_file_that_is_shorter_than_it_was_made_goes_to_the_retry(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        file_put_contents($chunk->path, 'short');
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 900, $uploader->waiting_until());
    }

    public function test_a_second_object_with_a_queued_name_is_dropped_with_its_file(): void {
        [$uploader, , $folder] = $this->start();
        $first = self::chunk($folder, 1);
        $uploader->queue($first, 'object');
        $second = self::chunk($folder, 2);
        $copy = new chunk_info($second->path, $first->name, $second->size, $second->sha256base64, []);
        $uploader->queue($copy, 'object');
        $this->assertSame(1, $uploader->pending());
        $this->assertFileDoesNotExist($second->path);
        $this->assertFileExists($first->path);
        // The same file again is the same chunk: it stays.
        $uploader->queue($first, 'object');
        $this->assertFileExists($first->path);
    }

    public function test_a_file_that_could_not_be_removed_is_remembered_and_removed_at_the_next_drain(): void {
        $refuse = true;
        $removed = [];
        $remove = function (string $path) use (&$refuse, &$removed): bool {
            if ($refuse) {
                return false;
            }
            $removed[] = $path;
            return unlink($path);
        };
        [$uploader, $run, $folder] = $this->start(1000000, 100, null, 0, null, $remove);
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $this->assertSame([$chunk->path], $uploader->leftovers());
        $this->assertStringContainsString('"leftovers"', file_get_contents($folder . '/uploads.json'));
        // A new step reads it from the folder, and the removal is tried again.
        $uploader->resume($run);
        $this->assertSame([$chunk->path], $uploader->leftovers());
        $uploader->drain($run, false);
        $this->assertSame([$chunk->path], $uploader->leftovers(), 'Still refused.');
        $refuse = false;
        $uploader->drain($run, false);
        $this->assertSame([], $uploader->leftovers());
        $this->assertSame([$chunk->path], $removed);
        $this->assertSame([], $this->saved($folder)['leftovers']);
    }

    public function test_a_busy_service_while_the_size_is_checked_again_is_waited_for(): void {
        [$uploader, $run, $folder] = $this->start(100);
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::chunk($folder, $i), 'object');
        }
        $this->intercept = function (RequestInterface $request): ?Response {
            return str_ends_with($request->getUri()->getPath(), '/capacity')
                ? new Response(503, ['Retry-After' => '90']) : null;
        };
        $uploader->drain($run, true);
        $this->assertSame(self::NOW + 90, $uploader->waiting_until());
        $this->assertSame(3, $uploader->pending());
    }

    public function test_a_failure_of_the_connection_to_the_storage_is_a_failed_send_that_is_tried_again(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunk = self::chunk($folder, 1);
        $uploader->queue($chunk, 'object');
        $this->intercept = function (RequestInterface $request): ?Response {
            if ($request->getMethod() === 'PUT') {
                throw new \GuzzleHttp\Exception\ConnectException('down', $request);
            }
            return null;
        };
        foreach ([0, 900] as $later) {
            $this->time = self::NOW + $later;
            $this->service->state['now'] = $this->time;
            $uploader->drain($run, true);
            $this->assertSame($this->time + 900, $uploader->waiting_until());
        }
        $this->time = self::NOW + 1800;
        $this->service->state['now'] = $this->time;
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('upload_failed', $e->errorcode);
        $this->assertSame('transport_connect', $e->debuginfo);
        $this->assertFileExists($chunk->path);
    }

    public function test_no_signed_url_is_in_the_trace_of_a_failed_send(): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1), 'object');
        $this->refuse_puts(500, 99);
        foreach ([0, 900] as $later) {
            $this->time = self::NOW + $later;
            $this->service->state['now'] = $this->time;
            $uploader->drain($run, true);
        }
        $this->time = self::NOW + 1800;
        $this->service->state['now'] = $this->time;
        $e = $this->failure(fn() => $uploader->drain($run, true));
        $this->assertSame('upload_failed', $e->errorcode);
        foreach (trace_strings::of($e) as $string) {
            $this->assertStringNotContainsString('X-Amz', $string);
            $this->assertStringNotContainsString('/_s3/', $string);
            $this->assertStringNotContainsString('0123456789abcdef', $string);
        }
        // The closure is the call of this test (failure()), whose class is in the namespace of the plugin too.
        $this->assertSame([], trace_strings::objects_in_plugin_frames($e, [run_record::class, \Closure::class]));
    }
    /**
     * What the uploader saved in the folder of the run.
     *
     * @param string $folder The folder.
     * @return array
     */
    private function saved(string $folder): array {
        return json_decode(file_get_contents($folder . '/uploads.json'), true);
    }

    /**
     * A chunk of a size that is not written: a file with a hole, which reads as zeros.
     *
     * @param string $folder Folder.
     * @param int $n Number of the chunk.
     * @param int $size Bytes.
     * @return chunk_info
     */
    private static function sparse_chunk(string $folder, int $n, int $size): chunk_info {
        $path = $folder . '/s' . $n;
        $handle = fopen($path, 'wb');
        fseek($handle, $size - 1);
        fwrite($handle, "\0");
        fclose($handle);
        return new chunk_info($path, hash('sha256', 'sparse' . $n), $size, base64_encode(hash('sha256', 'x', true)), []);
    }

    /**
     * The bytes of every request for upload URLs that the service got.
     *
     * @return int[]
     */
    private function requested_bytes(): array {
        $bytes = [];
        foreach ($this->seen as $entry) {
            [$line, $body] = explode("\n", $entry, 2);
            if (str_starts_with($line, 'POST ') && str_ends_with($line, '/upload-urls')) {
                $bytes[] = array_sum(array_column(json_decode($body, true)['items'], 'size'));
            }
        }
        return $bytes;
    }

    public function test_the_urls_asked_for_are_no_more_than_can_be_sent_before_they_expire(): void {
        // 1 Mbps is 125,000 bytes a second. The URLs live 150 seconds; with the 120 of margin and the 300 for a clock that is
        // off, 60 seconds are counted: 7.5 MB, so seven chunks of 1 MB go in a request.
        $this->service->state['urllife'] = 150;
        [$uploader, $run, $folder] = $this->start(100000000, 100, null, 1);
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::chunk($folder, $i, 1000000), 'object');
        }
        // The first request does not know the life of the URLs yet: it takes the 900 seconds of the contract.
        $uploader->drain($run, true);
        $this->assertSame([3000000], $this->requested_bytes());
        for ($i = 3; $i < 18; $i++) {
            $uploader->queue(self::chunk($folder, $i, 1000000), 'object');
        }
        $uploader->drain($run, true);
        $this->assertSame([3000000, 7000000, 7000000, 1000000], $this->requested_bytes());
        $this->assertSame(18000000, $this->service->state['runs'][$run->run_uuid()]['reserved'], 'Nothing was asked for twice.');
        $this->assertCount(18, $this->service->state['objects']);
    }

    public function test_the_speed_of_the_earlier_sends_sets_the_size_of_the_next_request(): void {
        // A chunk of 1 MB takes 100 seconds: 10,000 bytes a second. The URLs live 900 seconds, less 420 for the margins: 480
        // seconds hold 4.8 MB, so four chunks.
        $this->intercept = function (RequestInterface $request): ?Response {
            if ($request->getMethod() === 'PUT') {
                $this->time += 100;
                $this->service->state['now'] = $this->time;
            }
            return null;
        };
        [$uploader, $run, $folder] = $this->start(100000000);
        $this->assertNull($uploader->measured_rate());
        for ($i = 0; $i < 2; $i++) {
            $uploader->queue(self::chunk($folder, $i, 1000000), 'object');
        }
        $uploader->drain($run, true);
        $this->assertSame(10000, $uploader->measured_rate());
        for ($i = 2; $i < 17; $i++) {
            $uploader->queue(self::chunk($folder, $i, 1000000), 'object');
        }
        $uploader->drain($run, true);
        $this->assertSame([2000000, 4000000, 4000000, 4000000, 3000000], $this->requested_bytes());
        $this->assertSame(17000000, $this->service->state['runs'][$run->run_uuid()]['reserved']);
    }

    public function test_what_was_measured_and_learned_is_kept_for_the_next_step(): void {
        $this->service->state['urllife'] = 600;
        $this->intercept = function (RequestInterface $request): ?Response {
            if ($request->getMethod() === 'PUT') {
                $this->time += 100;
                $this->service->state['now'] = $this->time;
            }
            return null;
        };
        [$uploader, $run, $folder, $client, $endpoint] = $this->start(100000000);
        $uploader->queue(self::chunk($folder, 0, 1000000), 'object');
        $uploader->drain($run, true);
        $saved = $this->saved($folder);
        $this->assertSame([], $saved['items']);
        $this->assertSame([1000000, 100, 600], [$saved['sentbytes'], $saved['senttime'], $saved['life']]);
        $this->assertStringNotContainsString('://', json_encode($saved));
        // The uploader of the next step starts from them: 10,000 bytes a second for 600 - 420 = 180 seconds, 1.8 MB.
        $limits = new limits(2.0, 256, 500, 5.0, $this->tempdir, 100, 0);
        $settings = developer_settings::from_value(['allowprivatehosts' => true, 'skipnetworkchecks' => true]);
        $second = new uploader(
            $client,
            new object_transport($settings, network_fakes::passing_s3_guard(), $this->network()),
            new temp_area($limits),
            $limits,
            fn(): array => [$endpoint, $this->pair, fake_service::SITE_ID],
            fn(): int => $this->time
        );
        $second->resume($run);
        $this->assertSame(10000, $second->measured_rate());
        for ($i = 1; $i < 6; $i++) {
            $second->queue(self::chunk($folder, $i, 1000000), 'object');
        }
        $second->drain($run, true);
        $this->assertSame(array_fill(0, 6, 1000000), $this->requested_bytes());
    }

    public function test_while_no_speed_is_known_a_request_is_a_probe_of_64_mib(): void {
        [$uploader, $run, $folder] = $this->start(10000000000);
        // The service is busy, so that nothing is sent: only the size of the first request is looked at.
        $this->intercept = fn(RequestInterface $r): ?Response => str_ends_with($r->getUri()->getPath(), '/upload-urls')
            ? new Response(503) : null;
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::sparse_chunk($folder, $i, 30 * 1048576), 'object');
        }
        $uploader->drain($run, true);
        $this->assertSame([60 * 1048576], $this->requested_bytes());
    }

    public function test_a_chunk_larger_than_the_probe_goes_alone(): void {
        [$uploader, $run, $folder] = $this->start(10000000000);
        $this->intercept = fn(RequestInterface $r): ?Response => str_ends_with($r->getUri()->getPath(), '/upload-urls')
            ? new Response(503) : null;
        $uploader->queue(self::sparse_chunk($folder, 0, 70 * 1048576), 'object');
        $uploader->queue(self::sparse_chunk($folder, 1, 1048576), 'object');
        $uploader->drain($run, true);
        $this->assertSame([70 * 1048576], $this->requested_bytes());
    }

    public function test_a_send_that_the_storage_answered_with_412_is_not_a_measure_of_the_speed(): void {
        [$uploader, $run, $folder] = $this->start();
        $chunks = [self::chunk($folder, 1), self::chunk($folder, 2)];
        foreach ($chunks as $chunk) {
            $this->service->state['objects']['sites/' . fake_service::SITE_ID . '/objects/' . $chunk->name] = base64_encode('x');
            $uploader->queue($chunk, 'object');
        }
        $this->time = self::NOW;
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $this->assertNull($uploader->measured_rate());
        $this->assertFalse(is_file($folder . '/uploads.json'));
    }

    public function test_the_batch_never_has_less_than_one_chunk(): void {
        // 1 Mbps for 60 seconds is 7.5 MB, and a chunk is 10 MB: a chunk is asked for at a time.
        $this->service->state['urllife'] = 150;
        $this->intercept = function (RequestInterface $request): ?Response {
            if ($request->getMethod() === 'PUT') {
                $request->getBody()->getContents();
                return new Response(200);
            }
            return null;
        };
        [$uploader, $run, $folder] = $this->start(10000000000, 100, null, 1);
        // A small chunk first, so that the life of the URLs is known.
        $uploader->queue(self::chunk($folder, 9, 1000), 'object');
        $uploader->drain($run, true);
        for ($i = 0; $i < 3; $i++) {
            $uploader->queue(self::sparse_chunk($folder, $i, 10000000), 'object');
        }
        $uploader->drain($run, true);
        $this->assertSame([1000, 10000000, 10000000, 10000000], $this->requested_bytes());
        $this->assertSame(0, $uploader->pending());
    }

    public function test_queued_files_stay_inside_the_budget_whatever_their_sizes(): void {
        // Five chunks of 100 KB wait (500 KB of 1 MB; the rule of half of the budget would hold them), and then comes a
        // chunk of 600 KB: what waits is sent first, so that the folder never holds more than the budget.
        [$uploader, $run, $folder] = $this->start(100000000, 1);
        $uploader->resume($run);
        $temp = new temp_area(new limits(2.0, 256, 500, 5.0, $this->tempdir, 1, 0));
        $budget = 1048576;
        foreach ([100000, 100000, 100000, 100000, 100000, 600000, 100000, 700000, 50000, 900000] as $n => $size) {
            $uploader->queue(self::chunk($folder, $n, $size), 'object');
            $this->assertLessThanOrEqual($budget, $temp->used_bytes($run->id()), 'After the chunk number ' . $n);
            $uploader->drain($run, false);
        }
        $uploader->drain($run, true);
        $this->assertCount(10, $this->service->state['objects']);
    }

    public function test_the_manifest_that_is_built_again_at_the_same_path_is_the_one_that_is_sent(): void {
        [$uploader, $run, $folder] = $this->start();
        $uploader->queue(self::chunk($folder, 1, 300), 'manifest');
        // Built again, with other content, at the same path.
        $again = self::chunk($folder, 1, 500);
        $this->assertSame(self::chunk($folder, 1, 500)->path, $again->path);
        $uploader->queue($again, 'manifest');
        $this->assertSame(1, $uploader->pending());
        $this->assertSame(500, $uploader->pending_bytes());
        $uploader->drain($run, true);
        $this->assertSame(0, $uploader->pending());
        $key = 'sites/' . fake_service::SITE_ID . '/manifests/' . $run->run_uuid();
        $this->assertSame(500, strlen(base64_decode($this->service->state['objects'][$key])));
        // The same file for an object is the same chunk, and is not queued twice.
        $chunk = self::chunk($folder, 2);
        $uploader->queue($chunk, 'object');
        $uploader->queue($chunk, 'object');
        $this->assertSame(1, $uploader->pending());
        $this->assertFileExists($chunk->path);
    }
}
