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

namespace tool_secbackup\local\backup\stage;

use tool_secbackup\local\api\api_exception;
use tool_secbackup\local\api\receipt;
use tool_secbackup\local\backup\code_source;
use tool_secbackup\local\backup\index_builder;
use tool_secbackup\local\backup\journal;
use tool_secbackup\local\backup\manifest_builder;
use tool_secbackup\local\backup\run_context;
use tool_secbackup\local\backup\site_config;
use tool_secbackup\local\backup\stats;
use tool_secbackup\local\crypto\artifact_signature;
use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\local\trust\sec_trust;

/**
 * The stages that end a run: the list of the generation (manifest), the index, the report to the service (complete) and the
 * receipt.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class artifact_stage {
    /** @var int The most bytes of the body of the report that the service takes. */
    public const MAX_REPORT_BYTES = 60000;

    /** @var int[] Seconds to wait for the receipt after each request, by its number; then RECEIPT_EVERY. */
    private const RECEIPT_WAITS = [1 => 60, 2 => 120, 3 => 300];

    /** @var int Seconds between the requests for the receipt from the fifth on. */
    private const RECEIPT_EVERY = 600;

    /** @var int Seconds between the report and the first request for the receipt. */
    private const RECEIPT_FIRST = 30;

    /** @var int Bytes allowed for one line of the index. */
    private const INDEX_LINE = 80;

    /**
     * The stage "manifest": the list of the generation is built and queued.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: corediff.
     * @return string next, or more when there is no room for it.
     */
    public static function manifest(run_context $context, array $seams): string {
        $entriesfile = $context->folder . '/' . manifest_builder::FILE;
        $size = is_file($entriesfile) ? (int) filesize($entriesfile) : 0;
        if (!$context->make_room(run_context::chunk_bound($size))) {
            return 'more';
        }
        $context->set('bytes', self::bytes($entriesfile));
        $chunk = $context->entries->build(
            self::header($context, $seams),
            $context->run_uuid(),
            $context->site_public_key(),
            $context->siteid
        );
        $context->uploader->queue($chunk, 'manifest');
        $context->set('manifest', ['sha256' => $chunk->sha256base64, 'size' => $chunk->size]);
        $context->next_stage('index');
        return 'next';
    }

    /**
     * The first line of the list of the generation.
     *
     * The lists that a step may have written twice (the links and the names that could not be read, when a step went on from
     * the middle of a page) have each value once.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner.
     * @return array
     */
    private static function header(run_context $context, array $seams): array {
        $core = $context->get('core', ['official' => false]);
        return [
            'v' => 1,
            'site_id' => $context->siteid,
            'run_id' => $context->run_uuid(),
            'kind' => 'full',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $context->run->started_at()),
            'encryption_key_fingerprint' => fingerprint::format(state::load()->encryption_fingerprint()),
            'moodle' => self::moodle($seams),
            'db' => $context->get('db'),
            'plugins' => code_source::versions(),
            'config' => site_config::snapshot(),
            'core_diff' => [
                'official' => (bool) $core['official'],
                'modified' => self::values($context->journal('core_modified')),
                'added' => self::values($context->journal('core_added')),
                'deleted' => self::values($context->journal('core_deleted')),
            ],
            'missing_files' => self::values($context->journal('missing_files')),
            'skipped_links' => self::values($context->journal('skipped_links')),
            'unreadable' => self::values($context->journal('unreadable')),
        ];
    }

    /**
     * The values of a journal, each once, in the order they were first written.
     *
     * @param journal $journal The journal.
     * @return array
     */
    private static function values(journal $journal): array {
        return array_values(array_unique(iterator_to_array($journal->values(), false), SORT_REGULAR));
    }

    /**
     * The release, the version and the build of the Moodle program: from its version file, or from the settings of the site
     * when the file cannot be read.
     *
     * @param array $seams The seams of the runner: corediff.
     * @return string[] ['release', 'version', 'build']
     */
    private static function moodle(array $seams): array {
        global $CFG;
        try {
            return ($seams['corediff'])()->release_parts();
        } catch (plugin_exception $e) {
            $build = preg_match('/Build: (\d{8})(?!\d)/', (string) $CFG->release, $m) === 1 ? $m[1] : '00000000';
            return [
                'release' => (string) $CFG->release,
                'version' => number_format((float) $CFG->version, 2, '.', ''),
                'build' => $build,
            ];
        }
    }

    /**
     * The sizes of what the run stored, from its entries: the plain text of the blocks, and the files once each.
     *
     * @param string $path The file of the entries.
     * @return int[] db, files, dataroot and code.
     */
    private static function bytes(string $path): array {
        $bytes = ['db' => 0, 'files' => 0, 'dataroot' => 0, 'code' => 0];
        foreach ((new journal($path))->values() as $entry) {
            if ($entry['t'] === 'db') {
                $bytes['db'] += $entry['plainlength'];
            } else if ($entry['piece'] === 0) {
                $bytes[$entry['t'] === 'file' ? 'files' : $entry['t']] += $entry['size'];
            }
        }
        return $bytes;
    }

    /**
     * The stage "index": the index of the chunks is built and queued, after the list of the generation has gone when there is
     * no room for both.
     *
     * @param run_context $context The step.
     * @return string next, or more when there is no room for it.
     */
    public static function index(run_context $context): string {
        $chunks = $context->journal('chunks');
        if (!$context->make_room(run_context::chunk_bound($chunks->lines() * self::INDEX_LINE))) {
            return 'more';
        }
        $sizes = [];
        foreach ($chunks->values() as [$name, $size]) {
            $sizes[$name] = $size;
        }
        $index = index_builder::build($sizes, $context->siteid, $context->run_uuid());
        unset($sizes);
        $path = $context->folder . '/index.gz';
        $part = $path . '.part';
        $gzip = $index['gzip'];
        $written = error_silencer::run(static fn() => file_put_contents($part, $gzip), $warning);
        if ($written !== strlen($gzip) || !error_silencer::run(static fn() => rename($part, $path), $warning)) {
            throw new plugin_exception('temp_write_failed', null, 'index_write');
        }
        $context->uploader->queue(new chunk_info($path, '', strlen($gzip), $index['sha256base64'], []), 'index');
        $context->set('index', ['sha256' => $index['sha256base64'], 'size' => strlen($gzip), 'count' => $index['count']]);
        $context->next_stage('complete');
        return 'next';
    }

    /**
     * The stage "complete": once everything is sent, the run is reported with the signed list, the signed index and the
     * statistics.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner: corediff and external.
     * @return string more: the receipt is asked for later, or something still waits to be sent.
     */
    public static function complete(run_context $context, array $seams): string {
        $context->uploader->drain($context->run, true);
        if ($context->uploader->pending() > 0) {
            return 'more';
        }
        $runid = $context->run_uuid();
        $m = $context->get('manifest');
        $i = $context->get('index');
        $manifest = [
            'sha256' => $m['sha256'],
            'size' => $m['size'],
            'signature' => artifact_signature::manifest($context->pair(), $context->siteid, $runid, $m['sha256']),
        ];
        $index = [
            'sha256' => $i['sha256'],
            'size' => $i['size'],
            'count' => $i['count'],
            'signature' => artifact_signature::index($context->pair(), $context->siteid, $runid, $i['sha256'], $i['count']),
        ];
        // The status page warns about the Moodle program from this flag: it is set when the program differs from the official
        // files, and also when nothing could be compared, because then the whole program was sent (design 8.3).
        [$official, $changed] = self::core_state($context);
        set_config('coremodified', !$official || $changed > 0 ? 1 : 0, 'tool_secbackup');
        $stats = self::stats($context, $seams, $manifest, $index);
        try {
            $context->client->complete($context->endpoint, $context->pair(), $context->siteid, $runid, $manifest, $index, $stats);
        } catch (api_exception $e) {
            // A report that got through before its answer was lost: the run is verifying, or done, at the service.
            if ($e->errorcode !== 'api_run_state_invalid') {
                throw $e;
            }
        }
        $context->set('polls', 0);
        $context->next_stage('receipt');
        $context->wait($context->now() + self::RECEIPT_FIRST);
        return 'more';
    }

    /**
     * What the comparison of the Moodle program with the official list found.
     *
     * @param run_context $context The step.
     * @return array [whether an official list was compared with, how many files differ from it]
     */
    private static function core_state(run_context $context): array {
        $official = (bool) $context->get('core', ['official' => false])['official'];
        $changed = $context->journal('core_modified')->lines() + $context->journal('core_added')->lines()
            + $context->journal('core_deleted')->lines();
        return [$official, $changed];
    }

    /**
     * The statistics of the report, brought inside the size that the service takes.
     *
     * The list of the tables and the authentication methods are never empty: the service refuses an empty one.
     *
     * @param run_context $context The step.
     * @param array $seams The seams of the runner.
     * @param array $manifest The part of the report on the list of the generation.
     * @param array $index The part of the report on the index.
     * @return array
     */
    private static function stats(run_context $context, array $seams, array $manifest, array $index): array {
        [$official, $changed] = self::core_state($context);
        $stats = stats::collect(
            $context->get('tablerows', []),
            $context->get('bytes'),
            $context->now() - $context->run->started_at(),
            self::moodle($seams),
            $official && $changed > 0,
            (bool) ($seams['external'])()
        );
        $rest = strlen(stats::encode(['manifest' => $manifest, 'index' => $index, 'stats' => new \stdClass()]));
        return self::fit_report($stats, self::MAX_REPORT_BYTES - ($rest - 2));
    }

    /**
     * Brings the statistics inside a size, and keeps the table with the most rows and an authentication method when nothing
     * else would be left: the service refuses an empty list of tables or of methods.
     *
     * @param array $stats Statistics from stats::collect().
     * @param int $maxjsonbytes The most bytes that the statistics may have.
     * @return array
     */
    public static function fit_report(array $stats, int $maxjsonbytes): array {
        $fitted = stats::fit($stats, $maxjsonbytes);
        $tables = (array) $stats['tables'];
        if ((array) $fitted['tables'] === [] && $tables !== []) {
            arsort($tables);
            $fitted['tables'] = (object) array_slice($tables, 0, 1, true);
        }
        if ($fitted['auth_methods'] === []) {
            $fitted['auth_methods'] = ['manual'];
        }
        return $fitted;
    }

    /**
     * Marks the run completed and removes its temporary files: the receipt was verified (the stage is "done").
     *
     * @param run_context $context The step.
     * @return string done.
     */
    public static function finish(run_context $context): string {
        $context->run->finish($context->now());
        $context->close();
        // Whether the temporary files are gone is recorded, as it is for a run that stops.
        $progress = ['done' => ['cleared' => $context->temp->clear($context->run->id())]] + $context->run->progress();
        $context->run->commit('done', $progress, $context->run->entries_committed(), $context->now());
        return 'done';
    }

    /**
     * The stage "receipt": the state of the run is asked for, 30, 60, 120 and 300 seconds apart and then every 600 seconds,
     * until the service has verified it.
     *
     * @param run_context $context The step.
     * @param sec_trust $trust The keys that sign the receipts.
     * @return string done, failed (the service failed the run) or more.
     * @throws envelope_exception When the receipt is not one for what this site sent.
     */
    public static function receipt(run_context $context, sec_trust $trust): string {
        $runid = $context->run_uuid();
        $state = $context->client->run_state($context->endpoint, $context->pair(), $context->siteid, $runid);
        if ($state->status() === 'completed') {
            $envelope = $state->receipt();
            if ($envelope === null) {
                throw new envelope_exception('receipt_mismatch', null, 'receipt');
            }
            $m = $context->get('manifest');
            $i = $context->get('index');
            $payload = receipt::verify($envelope, $trust, $context->siteid, $runid, $m['sha256'], $i['sha256'], $i['count']);
            // The size is verified with the receipt; it is the size of the backup that the status page shows.
            $context->set('stored_bytes', $payload['stored_bytes']);
            $context->next_stage('done');
            return self::finish($context);
        }
        if ($state->status() === 'failed') {
            $context->set_failure($state->failure_code() ?? 'verify_error');
            return 'failed';
        }
        $polls = (int) $context->get('polls', 0) + 1;
        $context->set('polls', $polls);
        $context->commit([]);
        $context->wait($context->now() + (self::RECEIPT_WAITS[$polls] ?? self::RECEIPT_EVERY));
        return 'more';
    }
}
