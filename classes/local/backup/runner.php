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
use tool_secbackup\local\backup\db\size_estimate;
use tool_secbackup\local\backup\db\table_list;
use tool_secbackup\local\backup\db\connection_factory;
use tool_secbackup\local\backup\env\external_storage;
use tool_secbackup\local\backup\files\file_source;
use tool_secbackup\local\backup\files\packer;
use tool_secbackup\local\backup\stage\artifact_stage;
use tool_secbackup\local\backup\stage\db_stage;
use tool_secbackup\local\backup\stage\files_stage;
use tool_secbackup\local\backup\stage\tree_stage;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\sec_trust;

/**
 * Moves a backup run forward, one step at a time, through the stages of run_record::STAGES.
 *
 * A step goes from stage to stage until its budget is used; every stage does at least one unit of work, so that a short
 * budget still ends the run. The stage of the database is the exception: it reads the whole database in one step, inside one
 * snapshot. What survives between the steps, and how a step goes on from the last commit, is described in run_context.
 *
 * step() answers continue (more to do; the run may wait until its next attempt), paused (the run waits for its next window, or
 * its files are on another server), done or aborted (stopped, or refused by the service before it started).
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runner {
    /** @var string[] The seams that a test may give. */
    private const SEAMS = ['limits', 'diskspace', 'sample', 'sleep', 'timer', 'hostname', 'link', 'trust', 'files', 'windows',
        'log', 'tables', 'datarootroots', 'coderoots', 'corediff', 'external', 'opendb', 'filesource'];

    /** @var int The most bytes of a present list that are fetched (10 million chunks). */
    private const PRESENT_MAX = 1073741824;

    /** @var int Seconds to wait for a busy service that did not say how long. */
    private const DEFAULT_WAIT = 60;

    /** @var client The client of the service. */
    private client $client;

    /** @var object_transport The transport of the storage. */
    private object_transport $objects;

    /** @var callable Gives the current UNIX time. */
    private $clock;

    /** @var array The seams. */
    private array $seams;

    /** @var temp_area The temporary area. */
    private temp_area $temp;

    /** @var \stdClass When the watch last paused a run of this runner, as the property "at". */
    private \stdClass $pause;

    /** @var string|null The class (and code) of the unexpected error of the last step. */
    private ?string $lasterror = null;

    /**
     * Constructor.
     *
     * @param client $client The client of the service.
     * @param object_transport $objects The transport of the storage.
     * @param callable|null $clock Gives the current UNIX time; null for time().
     * @param array $seams Seams for tests, by name: limits (limits), diskspace (callable for temp_area), sample (callable that
     *     gives a sample of the watch), sleep (callable(int)), timer (callable that gives seconds as a float, for the budget),
     *     hostname (callable), link (callable that gives [endpoint, signing_keypair, site ID]), trust (sec_trust of the
     *     receipts), files (system_files for SELinux), windows (bool: false for the command line), log (callable(string)),
     *     tables (callable(bool $logs) as table_list::build), datarootroots (callable as dataroot_source::roots), coderoots
     *     (callable as code_source::roots), corediff (callable that gives a core_diff), external (callable that gives a bool),
     *     opendb (callable that gives the export connection) and filesource (callable(\moodle_database) that gives a
     *     file_source).
     */
    public function __construct(client $client, object_transport $objects, ?callable $clock = null, array $seams = []) {
        $unknown = array_diff(array_keys($seams), self::SEAMS);
        if ($unknown !== []) {
            throw new \coding_exception('Unknown seam of the runner: ' . implode(', ', $unknown));
        }
        $this->client = $client;
        $this->objects = $objects;
        $this->clock = $clock ?? static fn(): int => time();
        $limits = $seams['limits'] ?? limits::current();
        $monitor = isset($seams['sample']) ? null : new monitor(new system_files(), $limits);
        $this->seams = $seams + [
            'limits' => $limits,
            'diskspace' => null,
            'sample' => static fn(): array => $monitor->sample(),
            'sleep' => static fn(int $seconds) => sleep($seconds),
            'timer' => static fn(): float => microtime(true),
            'hostname' => static fn(): string => (string) gethostname(),
            'link' => [uploader::class, 'site_link'],
            'trust' => null,
            'files' => new system_files(),
            'windows' => true,
            'log' => static fn(string $line) => mtrace($line),
            'tables' => [table_list::class, 'build'],
            'datarootroots' => [dataroot_source::class, 'roots'],
            'coderoots' => [code_source::class, 'roots'],
            'corediff' => static fn(): core_diff => new core_diff(),
            'external' => static fn(): bool => (new external_storage())->detected(),
            'opendb' => static fn(): \moodle_database => connection_factory::open(),
            'filesource' => static fn(\moodle_database $db): file_source => new file_source($db),
        ];
        $this->temp = new temp_area($this->seams['limits'], $this->seams['diskspace']);
        $this->pause = (object) ['at' => null];
    }

    /**
     * The runner of this site.
     *
     * @param bool $windows Whether the run stops at the end of its window: true for the task, false for the command line.
     * @return self
     */
    public static function create(bool $windows = true): self {
        return new self(client::create(), object_transport::create(), null, ['windows' => $windows]);
    }

    /**
     * The upload speed limit of a run: the smaller of the two settings that is not zero.
     *
     * @return int Bytes a second; 0 for no limit.
     */
    public function bandwidth(): int {
        return uploader::bandwidth_bytes(settings::current()->bandwidth_mbps(), $this->seams['limits']->bandwidthmbps);
    }

    /**
     * The class of the error that ended the last step without stopping the run, and its code for an error of the plugin.
     *
     * @return string|null Null when the last step had no such error.
     */
    public function last_error(): ?string {
        return $this->lasterror;
    }

    /**
     * Moves a run forward.
     *
     * @param run_record $run The run.
     * @param int $budgetseconds Seconds that the step may take (the stage of the database takes what it needs).
     * @return string continue, paused, done or aborted.
     */
    public function step(run_record $run, int $budgetseconds): string {
        $this->lasterror = null;
        $status = $run->status();
        if ($status === 'completed') {
            return 'done';
        }
        if ($status !== 'active' && $status !== 'paused') {
            return 'aborted';
        }
        if ($run->hostname() !== null && $run->hostname() !== ($this->seams['hostname'])()) {
            return 'paused';
        }
        $now = $this->now();
        if ($status === 'paused' && $run->next_attempt() !== null && $run->next_attempt() > $now) {
            // The command line does not wait for a window; it waits for anything else as the task does.
            $windowpause = (bool) ($run->progress()['run']['windowpause'] ?? false);
            if (!$windowpause || $this->seams['windows']) {
                return $windowpause ? 'paused' : 'continue';
            }
        }
        $context = null;
        try {
            $context = new run_context(
                $run,
                $this->client,
                $this->objects,
                $this->temp,
                $this->seams['limits'],
                $this->seams['link'],
                [
                    'clock' => $this->clock,
                    'timer' => $this->seams['timer'],
                    'sample' => $this->seams['sample'],
                    'sleep' => $this->seams['sleep'],
                    'diskspace' => $this->seams['diskspace'],
                    'pause' => $this->pause,
                    'windows' => (bool) $this->seams['windows'],
                ],
                ($this->seams['timer'])() + $budgetseconds
            );
            if ($status === 'paused') {
                $run->activate($now);
                $context->set('windowpause', false);
            }
            return $this->stages($context);
        } catch (\Throwable $e) {
            $code = abort::classify($e, $this->seams['files']);
            $error = $e::class . ($e instanceof plugin_exception ? ' ' . $e->errorcode : '');
            // The error is not kept: its trace holds the arguments of the calls, and those can be secrets.
            unset($e);
            return $this->failed($run, $context, $code, $error);
        } finally {
            $context?->close();
        }
    }

    /**
     * Goes from stage to stage until the budget is used.
     *
     * @param run_context $context The step.
     * @return string What step() answers.
     */
    private function stages(run_context $context): string {
        while (true) {
            $stage = $context->run->stage();
            if ($stage === 'done') {
                // The step that verified the receipt stopped before it marked the run completed.
                $context->run->finish($context->now());
                return 'done';
            }
            if ($context->window_passed()) {
                return $this->end_of_window($context);
            }
            $outcome = match ($stage) {
                'prepare' => $this->prepare($context),
                'start' => $this->start($context),
                'present' => $this->present($context),
                'capacity' => $this->capacity($context),
                'db' => db_stage::run($context, $this->seams),
                'files' => files_stage::run($context, $this->seams),
                'dataroot' => tree_stage::dataroot($context, $this->seams),
                'code' => tree_stage::code($context, $this->seams),
                'manifest' => artifact_stage::manifest($context, $this->seams),
                'index' => artifact_stage::index($context),
                'complete' => artifact_stage::complete($context, $this->seams),
                'receipt' => artifact_stage::receipt($context, $this->seams['trust'] ?? sec_trust::current()),
            };
            if ($outcome === 'window') {
                return $this->end_of_window($context);
            }
            if ($outcome === 'failed') {
                return $this->failed($context->run, $context, (string) $context->failure(), '');
            }
            if ($outcome !== 'next') {
                return ['more' => 'continue', 'refused' => 'aborted', 'done' => 'done'][$outcome];
            }
            if ($context->out_of_time()) {
                return 'continue';
            }
        }
    }

    /**
     * Ends a step that failed: a reason stops the run, any other error leaves it for the next step.
     *
     * @param run_record $run The run.
     * @param run_context|null $context The step, or null when it could not begin.
     * @param string $code The reason, or an empty string.
     * @param string $error The class and code of the error, for the log.
     * @return string continue or aborted.
     */
    private function failed(run_record $run, ?run_context $context, string $code, string $error): string {
        if ($code === '') {
            $context?->end_export();
            $this->lasterror = $error;
            ($this->seams['log'])('The backup step failed and is tried again: ' . $error);
            return 'continue';
        }
        abort::run(
            $run,
            $code,
            $this->client,
            $context === null ? null : static fn() => $context->end_export(),
            $this->temp,
            $this->seams['link'],
            $this->clock,
            $context === null ? [] : $context->uploader->leftovers()
        );
        return 'aborted';
    }

    /**
     * The window of the run ended: what waits is sent, and the run waits for its next window. A run that has missed two windows
     * is stopped with window_ended.
     *
     * @param run_context $context The step.
     * @return string paused, or continue when the next window has begun already.
     * @throws plugin_exception With window_ended.
     */
    private function end_of_window(run_context $context): string {
        $context->uploader->drain($context->run, true);
        $now = $context->now();
        $next = schedule::next_slot($context->settings, $context->siteid, (int) $context->get('window_end'));
        $misses = $context->run->window_misses() + ($now >= $next['end'] ? 2 : 1);
        $context->run->set_window_misses($misses);
        if ($misses >= 2) {
            throw new plugin_exception('window_ended');
        }
        $context->set('window_end', $next['end']);
        if ($now >= $next['start']) {
            $context->commit($context->cursor());
            return 'continue';
        }
        $context->set('windowpause', true);
        $context->commit($context->cursor());
        $context->wait($next['start']);
        return 'paused';
    }

    /**
     * The stage "prepare": the server of the run, the end of its window and the key for names.
     *
     * The server is recorded only when the temporary folder is not the one of Moodle, which every server shares.
     *
     * @param run_context $context The step.
     * @return string next.
     */
    private function prepare(run_context $context): string {
        global $CFG;
        $shared = rtrim($CFG->tempdir, '/') . '/tool_secbackup';
        $context->run->set_hostname(
            rtrim($context->limits->tempdir, '/') === $shared ? null : (string) ($this->seams['hostname'])()
        );
        $started = $context->run->started_at();
        $slot = schedule::slot_at($context->settings, $context->siteid, $started)
            ?? schedule::next_slot($context->settings, $context->siteid, $started);
        $context->set('window_end', $slot['end']);
        $context->dedup_key();
        $context->next_stage('start');
        return 'next';
    }

    /**
     * The stage "start": the service starts the run, and the present list is fetched while its address is known.
     *
     * @param run_context $context The step.
     * @return string next, more (the service is busy) or refused.
     */
    private function start(run_context $context): string {
        $run = $context->run;
        if ($run->run_uuid() === null) {
            for ($attempt = 0;; $attempt++) {
                try {
                    $started = $this->client->start_run($context->endpoint, $context->pair(), $context->siteid);
                    break;
                } catch (api_exception $e) {
                    $refused = in_array($e->errorcode, ['api_run_limit_reached', 'api_contract_inactive'], true);
                    if ($refused) {
                        $run->refuse($e->servercode, $context->now());
                        return 'refused';
                    }
                    if (in_array($e->errorcode, ['api_unavailable', 'api_rate_limited'], true)) {
                        $context->wait($context->now() + ($e->retryafter ?? self::DEFAULT_WAIT));
                        return 'more';
                    }
                    if ($e->errorcode !== 'api_run_in_progress' || $e->runid === null || $attempt > 0) {
                        throw $e;
                    }
                    $this->fail_lost($context, $e->runid);
                }
            }
            $run->set_run_uuid($started->run_id());
            $part = $context->folder . '/present.part';
            if ($this->objects->get_to_file($started->present_url(), $part, self::PRESENT_MAX)) {
                $whole = $context->folder . '/present.gz';
                if (!error_silencer::run(static fn() => rename($part, $whole), $warning)) {
                    throw new plugin_exception('temp_write_failed', null, 'present_rename');
                }
            }
        }
        // A run whose ID is known but whose present list is not (its address was lost) goes on with an empty list.
        $context->next_stage('present');
        return 'next';
    }

    /**
     * Tells the service about a run that it still has open and that this site no longer goes on with: the reason of its
     * stop when this site recorded one, else restarted.
     *
     * @param run_context $context The step.
     * @param string $runid The ID of that run.
     */
    private function fail_lost(run_context $context, string $runid): void {
        $lost = run_record::find_by_uuid($runid);
        $code = $lost !== null && in_array($lost->abort_code(), client::FAIL_CODES, true) ? $lost->abort_code() : 'restarted';
        $this->client->fail($context->endpoint, $context->pair(), $context->siteid, $runid, (string) $code);
    }

    /**
     * The stage "present": the present list is read and checked once.
     *
     * @param run_context $context The step.
     * @return string next.
     * @throws plugin_exception With signature_invalid when the list is not this site's or not in its form.
     */
    private function present(run_context $context): string {
        $context->present();
        $context->next_stage('capacity');
        return 'next';
    }

    /**
     * The stage "capacity": the service decides whether a run of the expected size may go on.
     *
     * @param run_context $context The step.
     * @return string next, or more when the service is busy.
     * @throws plugin_exception With capacity_refused.
     */
    private function capacity(run_context $context): string {
        global $DB;
        $tables = ($this->seams['tables'])($context->settings->include_logs());
        $estimate = size_estimate::bytes($DB, $tables['export']) + $this->files_estimate($context);
        try {
            $decision = $this->client->capacity(
                $context->endpoint,
                $context->pair(),
                $context->siteid,
                $context->run_uuid(),
                $estimate
            );
        } catch (api_exception $e) {
            if (!in_array($e->errorcode, ['api_unavailable', 'api_rate_limited'], true)) {
                throw $e;
            }
            $context->wait($context->now() + ($e->retryafter ?? self::DEFAULT_WAIT));
            return 'more';
        }
        if (!$decision->continue()) {
            throw new plugin_exception('capacity_refused');
        }
        $context->run->set_estimate($estimate);
        $context->set('estimate', $estimate);
        $context->next_stage('db');
        return 'next';
    }

    /**
     * The bytes of the file store that the run expects to send: every small file, and the big files whose pieces are not all
     * in the present list.
     *
     * @param run_context $context The step.
     * @return int
     */
    private function files_estimate(run_context $context): int {
        global $DB;
        $grouped = '(SELECT contenthash, MAX(filesize) AS size FROM {files} WHERE filesize > 0 GROUP BY contenthash) sizes';
        $small = (int) $DB->get_field_sql('SELECT SUM(size) FROM ' . $grouped . ' WHERE size < ?', [packer::FILE_LIMIT]);
        $big = 0;
        $rows = $DB->get_recordset_sql('SELECT contenthash, size FROM ' . $grouped . ' WHERE size >= ?', [packer::FILE_LIMIT]);
        $present = $context->present();
        foreach ($rows as $row) {
            $pieces = intdiv((int) $row->size + packer::PIECE - 1, packer::PIECE);
            for ($piece = 0; $piece < $pieces; $piece++) {
                if ($present->size_of(chunk_name::file($context->dedup_key(), $row->contenthash, $piece)) === null) {
                    $big += (int) $row->size;
                    break;
                }
            }
        }
        $rows->close();
        return $small + $big;
    }

    /**
     * The current UNIX time.
     *
     * @return int
     */
    private function now(): int {
        return (int) ($this->clock)();
    }
}
