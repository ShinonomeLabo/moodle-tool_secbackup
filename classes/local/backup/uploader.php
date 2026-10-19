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
use tool_secbackup\local\api\client;
use tool_secbackup\local\api\upload_target;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\plugin_exception;

/**
 * Sends the chunks of a run to the storage and removes each file once the storage has taken it.
 *
 * Chunks are queued as they are made. drain() asks the backup service for upload URLs (up to 256 in one request), sends the
 * files, and removes a file when its upload is confirmed. The queue is kept in the folder of the run (names, sizes and
 * digests, never a URL), so the next step of the run goes on from it. A step that has to wait, for a busy service or a failed
 * send, pauses the run and says until when; a stop that the run cannot recover from is an exception whose error code is the
 * reason for stopping the run.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class uploader {
    /** @var int Most items in one request for upload URLs. */
    public const BATCH = 256;

    /** @var int Seconds to wait after a failed send. */
    public const RETRY_SECONDS = 900;

    /** @var int Failures of one chunk after which the run stops. */
    public const MAX_ATTEMPTS = 3;

    /** @var int Seconds to wait for a busy service that did not say how long. */
    public const DEFAULT_WAIT = 60;

    /** @var string Name of the file of the queue, in the folder of the run. */
    public const QUEUE_FILE = 'uploads.json';

    /** @var int Seconds before the expiry at which an URL is no longer used. */
    private const EXPIRY_MARGIN = 30;

    /** @var int Times in a row that the size is checked again for one request. */
    private const MAX_RECHECKS = 3;

    /** @var int Bytes a second in one megabit a second. */
    private const MBPS = 125000;

    /** @var client The client. */
    private client $client;

    /** @var object_transport The transport to the storage. */
    private object_transport $objects;

    /** @var temp_area The temporary area. */
    private temp_area $temp;

    /** @var limits The limits. */
    private limits $limits;

    /** @var callable Gives [endpoint, signing_keypair, site ID]. */
    private $link;

    /** @var callable Gives the current UNIX time. */
    private $clock;

    /** @var int Bandwidth limit that the backup service set, in Mbps; 0 for none. */
    private int $servicembps;

    /** @var array[] The chunks that wait: kind, name, path, size, sha256, attempts. */
    private array $queue = [];

    /** @var int|null Nothing is tried before this time. */
    private ?int $retryat = null;

    /** @var string|null The folder of the run, where the queue is kept. */
    private ?string $folder = null;

    /** @var bool Whether the queue of the folder has been read. */
    private bool $loaded = false;

    /**
     * Constructor.
     *
     * @param client $client The client.
     * @param object_transport $objects The transport to the storage.
     * @param temp_area $temp The temporary area.
     * @param limits $limits The limits.
     * @param callable|null $link Gives [endpoint, signing_keypair, site ID]; null for the registration of this site.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param int $servicembps Bandwidth limit of the backup service's settings in Mbps; 0 for none.
     */
    public function __construct(
        client $client,
        object_transport $objects,
        temp_area $temp,
        limits $limits,
        ?callable $link = null,
        ?callable $clock = null,
        int $servicembps = 0
    ) {
        $this->client = $client;
        $this->objects = $objects;
        $this->temp = $temp;
        $this->limits = $limits;
        $this->link = $link ?? [self::class, 'site_link'];
        $this->clock = $clock ?? static fn(): int => time();
        $this->servicembps = $servicembps;
    }

    /**
     * The endpoint, the key pair and the ID of the registered site.
     *
     * @return array [endpoint, signing_keypair, site ID]
     * @throws plugin_exception With api_site_not_enrolled when the site is not registered.
     */
    public static function site_link(): array {
        $state = state::load();
        $pair = $state->is_enrolled() ? signing_keys::load() : null;
        if ($pair === null) {
            throw new plugin_exception('api_site_not_enrolled');
        }
        return [$state->endpoint(), $pair, $state->site_id()];
    }

    /**
     * The upload speed limit: the smaller of the two settings that is not zero.
     *
     * @param int $servicembps The backup service's setting in Mbps; 0 for none.
     * @param int $localmbps This site's setting in Mbps; 0 for none.
     * @return int Bytes a second; 0 for no limit.
     */
    public static function bandwidth_bytes(int $servicembps, int $localmbps): int {
        $set = array_filter([$servicembps, $localmbps], static fn(int $mbps): bool => $mbps > 0);
        return $set === [] ? 0 : min($set) * self::MBPS;
    }

    /**
     * The upload speed limit of this uploader.
     *
     * @return int Bytes a second; 0 for no limit.
     */
    public function bandwidth(): int {
        return self::bandwidth_bytes($this->servicembps, $this->limits->bandwidthmbps);
    }

    /**
     * Puts a chunk in the queue. The queue is saved before this returns.
     *
     * @param chunk_info $chunk The chunk, as the writer returned it. Its file is in the folder of the run.
     * @param string $kind object, manifest or index.
     * @throws \coding_exception For another kind, or a file that is not there.
     * @throws plugin_exception With temp_write_failed when the queue cannot be saved.
     */
    public function queue(chunk_info $chunk, string $kind): void {
        if (!in_array($kind, ['object', 'manifest', 'index'], true) || !is_file($chunk->path)) {
            throw new \coding_exception('A chunk is an object, the manifest or the index, and its file is there.');
        }
        $folder = dirname($chunk->path);
        $this->load($folder);
        $item = [
            'kind' => $kind,
            'name' => $kind === 'object' ? $chunk->name : null,
            'path' => $chunk->path,
            'size' => $chunk->size,
            'sha256' => $chunk->sha256base64,
            'attempts' => 0,
        ];
        $same = array_keys(array_filter($this->queue, static fn(array $q): bool => $q['kind'] === $kind
            && $q['name'] === $item['name']));
        if ($same === []) {
            $this->queue[] = $item;
        } else if ($kind !== 'object') {
            // The manifest and the index are named by the run: the later one replaces the earlier.
            $this->queue[$same[0]] = $item;
        }
        $this->save();
    }

    /**
     * Reads the queue that an earlier step left in the folder of the run, instead of the one in memory.
     *
     * Chunks whose files are gone are dropped: the file is removed only after the storage took it.
     *
     * @param run_record $run The run.
     * @throws plugin_exception With temp_write_failed when the folder cannot be used.
     */
    public function resume(run_record $run): void {
        $this->loaded = false;
        $this->queue = [];
        $this->retryat = null;
        $this->load($this->temp->for_run($run->id()));
    }

    /**
     * The number of chunks that wait.
     *
     * @return int
     */
    public function pending(): int {
        return count($this->queue);
    }

    /**
     * The bytes of the chunks that wait.
     *
     * @return int
     */
    public function pending_bytes(): int {
        return array_sum(array_column($this->queue, 'size'));
    }

    /**
     * The names of the chunks that wait, manifest and index left out.
     *
     * @return string[]
     */
    public function queued_names(): array {
        return array_values(array_filter(array_column($this->queue, 'name')));
    }

    /**
     * Until when nothing is sent.
     *
     * @return int|null UNIX time, or null when the uploader is not waiting.
     */
    public function waiting_until(): ?int {
        return $this->retryat !== null && $this->retryat > ($this->clock)() ? $this->retryat : null;
    }

    /**
     * Sends what waits.
     *
     * Without $force the chunks are sent only when a request is full, or when the room that is left in the temporary area is
     * under half of the budget. A budget of zero is no room at all, so then each chunk is sent at once.
     *
     * @param run_record $run The run; it must have its ID at the backup service.
     * @param bool $force Whether to send everything that waits.
     * @throws \coding_exception When the run has no ID at the backup service.
     * @throws plugin_exception With the reason for stopping the run as the error code: capacity_refused, contract_inactive
     *         or upload_failed. Other errors of the service and of the connection are thrown as they are.
     */
    public function drain(run_record $run, bool $force): void {
        $this->load($this->temp->for_run($run->id()));
        $now = ($this->clock)();
        if ($this->retryat !== null && $now < $this->retryat) {
            return;
        }
        $this->retryat = null;
        if ($this->queue === [] || (!$force && !$this->full())) {
            $this->save();
            return;
        }
        if ($run->run_uuid() === null) {
            throw new \coding_exception('A run is sent for after the backup service has started it.');
        }
        try {
            while ($this->queue !== []) {
                $batch = array_slice($this->queue, 0, self::BATCH);
                $targets = $this->urls($run, $batch);
                if ($targets === null || !$this->send($run, $batch, $targets)) {
                    return;
                }
            }
        } finally {
            $this->save();
        }
    }

    /**
     * Whether it is time to send without being forced.
     *
     * @return bool
     */
    private function full(): bool {
        $budget = $this->temp->budget_bytes();
        return count($this->queue) >= self::BATCH || $budget === 0 || $this->pending_bytes() * 2 > $budget;
    }

    /**
     * Asks for the URLs of a batch.
     *
     * @param run_record $run The run.
     * @param array[] $batch The chunks.
     * @return upload_target[]|null Null when the run waits.
     * @throws plugin_exception With capacity_refused or contract_inactive.
     * @throws api_exception For another refusal.
     */
    private function urls(run_record $run, array $batch): ?array {
        [$endpoint, $pair, $siteid] = ($this->link)();
        $items = array_map(static fn(array $q): array => [
            'kind' => $q['kind'], 'name' => $q['name'], 'size' => $q['size'], 'sha256' => $q['sha256'],
        ], $batch);
        $rechecks = 0;
        while (true) {
            try {
                return $this->client->upload_urls($endpoint, $pair, $siteid, (string) $run->run_uuid(), $items);
            } catch (api_exception $e) {
                $recheck = $e->errorcode === 'api_capacity_recheck_required' && $e->requestedbytes !== null;
                if ($recheck && ++$rechecks <= self::MAX_RECHECKS) {
                    // What the service has given, with everything that has not got an URL yet, the refused batch among it.
                    $expected = $e->requestedbytes + $this->pending_bytes();
                    if (!$this->client->capacity($endpoint, $pair, $siteid, (string) $run->run_uuid(), $expected)->continue()) {
                        throw new plugin_exception('capacity_refused');
                    }
                    $run->set_estimate($expected);
                    continue;
                }
                if ($e->errorcode === 'api_contract_inactive') {
                    throw new plugin_exception('contract_inactive');
                }
                if ($e->errorcode === 'api_run_limit_reached' || $e->errorcode === 'api_unavailable') {
                    $this->wait($run, ($this->clock)() + ($e->retryafter ?? self::DEFAULT_WAIT));
                    return null;
                }
                throw $e;
            }
        }
    }

    /**
     * Sends a batch.
     *
     * @param run_record $run The run.
     * @param array[] $batch The chunks.
     * @param upload_target[] $targets Where each one goes.
     * @return bool False when the run waits.
     * @throws plugin_exception With upload_failed when a chunk failed for the last time.
     */
    private function send(run_record $run, array $batch, array $targets): bool {
        foreach (array_values($batch) as $i => $item) {
            // The URLs of a long batch expire: the rest is asked for again. The first of a batch is always sent.
            if ($i > 0 && ($this->clock)() >= $targets[$i]->expires_at() - self::EXPIRY_MARGIN) {
                return true;
            }
            try {
                $this->objects->put_file($targets[$i]->url(), $targets[$i]->headers(), $item['path'], $this->bandwidth());
            } catch (transport_exception $e) {
                if (!in_array($e->errorcode, ['object_rejected', 'object_incomplete'], true)) {
                    throw $e;
                }
                $this->queue[0]['attempts']++;
                if ($this->queue[0]['attempts'] >= self::MAX_ATTEMPTS) {
                    throw new plugin_exception('upload_failed', null, $e->errorcode);
                }
                $this->wait($run, ($this->clock)() + self::RETRY_SECONDS);
                return false;
            }
            array_shift($this->queue);
            error_silencer::run(static fn() => unlink($item['path']), $warning);
            if ($item['kind'] === 'object') {
                $run->add_uploaded($item['size'], 1);
            }
        }
        return true;
    }

    /**
     * Pauses the run until a time.
     *
     * @param run_record $run The run.
     * @param int $until UNIX time.
     */
    private function wait(run_record $run, int $until): void {
        $this->retryat = $until;
        $run->pause(($this->clock)(), $until);
    }

    /**
     * Reads the queue of a folder once, and keeps the chunks that were queued before it was read.
     *
     * @param string $folder The folder of the run.
     */
    private function load(string $folder): void {
        $folder = rtrim($folder, '/');
        if ($this->loaded && $this->folder === $folder) {
            return;
        }
        $this->folder = $folder;
        $this->loaded = true;
        $path = $folder . '/' . self::QUEUE_FILE;
        $text = is_file($path) ? error_silencer::run(static fn() => file_get_contents($path), $warning) : false;
        $data = is_string($text) ? json_decode($text, true, 8) : null;
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            return;
        }
        $kept = [];
        foreach ($data['items'] as $item) {
            if (self::valid_item($item, $folder) && is_file($item['path'])) {
                $kept[] = $item;
            }
        }
        $known = array_map(static fn(array $q): string => $q['kind'] . '|' . $q['name'], $kept);
        foreach ($this->queue as $item) {
            if (!in_array($item['kind'] . '|' . $item['name'], $known, true)) {
                $kept[] = $item;
            }
        }
        $this->queue = $kept;
        $this->retryat = is_int($data['retryat'] ?? null) ? $data['retryat'] : null;
    }

    /**
     * Whether an item of the saved queue is in its form and its file is in the folder of the run.
     *
     * @param mixed $item The item.
     * @param string $folder The folder of the run.
     * @return bool
     */
    private static function valid_item(mixed $item, string $folder): bool {
        return is_array($item)
            && in_array($item['kind'] ?? null, ['object', 'manifest', 'index'], true)
            && ($item['kind'] === 'object'
                ? is_string($item['name'] ?? null) && preg_match('/^[0-9a-f]{64}\z/', $item['name']) === 1
                : ($item['name'] ?? false) === null)
            && is_string($item['path'] ?? null) && dirname($item['path']) === $folder
            && is_int($item['size'] ?? null) && $item['size'] > 0
            && is_string($item['sha256'] ?? null) && preg_match('/^[A-Za-z0-9+\/]{43}=\z/', $item['sha256']) === 1
            && is_int($item['attempts'] ?? null) && $item['attempts'] >= 0;
    }

    /**
     * Saves the queue in the folder of the run, or removes the file when there is nothing to keep.
     *
     * @throws plugin_exception With temp_write_failed.
     */
    private function save(): void {
        $path = $this->folder . '/' . self::QUEUE_FILE;
        if ($this->queue === []) {
            error_silencer::run(static fn() => is_file($path) && unlink($path), $warning);
            return;
        }
        $json = json_encode(['v' => 1, 'retryat' => $this->retryat, 'items' => $this->queue], JSON_UNESCAPED_SLASHES);
        $part = $path . '.part';
        $written = error_silencer::run(static fn() => file_put_contents($part, $json), $warning);
        if ($written !== strlen($json) || !error_silencer::run(static fn() => rename($part, $path), $warning)) {
            throw new plugin_exception('temp_write_failed', null, 'uploads_save');
        }
    }
}
