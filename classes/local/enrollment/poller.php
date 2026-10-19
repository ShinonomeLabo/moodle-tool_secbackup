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

namespace tool_secbackup\local\enrollment;

use tool_secbackup\local\api\api_exception;
use tool_secbackup\local\api\client;
use tool_secbackup\local\api\instruction;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\backup\starter;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\net\aws_ranges;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\local\trust\sec_trust;

/**
 * Asks the service for this site's status and records the outcome for the status page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class poller {
    /** @var client|null API client; null for the one of this site, made when needed. */
    private ?client $client;

    /** @var sec_trust|null Trusted response keys; null for the ones of this site, read when needed. */
    private ?sec_trust $trust;

    /** @var callable Returns the current UNIX time. */
    private $clock;

    /** @var callable Function (int $now) that starts a backup run when one is due; starter::maybe_start() by default. */
    private $starter;

    /**
     * Constructor.
     *
     * @param client|null $client API client; null for the one of this site.
     * @param callable|null $clock Returns the current UNIX time; null for time().
     * @param sec_trust|null $trust Trusted response keys, which verify the instructions; null for the ones of this site.
     * @param callable|null $starter Function (int $now) that starts a backup run when one is due; null for starter::maybe_start().
     */
    public function __construct(
        ?client $client = null,
        ?callable $clock = null,
        ?sec_trust $trust = null,
        ?callable $starter = null
    ) {
        $this->client = $client;
        $this->trust = $trust;
        $this->clock = $clock ?? static fn(): int => time();
        $this->starter = $starter ?? [starter::class, 'maybe_start'];
    }

    /**
     * Asks for the status once.
     *
     * @return string ok, notregistered or failed.
     */
    public function run(): string {
        $state = state::load();
        if (!$state->is_enrolled() && state::site_id_is_stored()) {
            // The cached settings are from before a registration. Drop them and read the registration again.
            \cache_helper::invalidate_by_definition('core', 'config', [], 'tool_secbackup');
            $state = state::load();
        }
        if (!$state->is_enrolled()) {
            return 'notregistered';
        }
        $now = ($this->clock)();
        try {
            $pair = signing_keys::load();
            if ($pair === null) {
                state::record_poll($now, null, 'signingkey_missing');
                return 'failed';
            }
            $client = $this->client ?? client::create();
            $result = $client->status($state->endpoint(), $pair, $state->site_id());
        } catch (api_exception $e) {
            $skew = $e->servertime === null ? null : $e->servertime - $now;
            state::record_poll($now, null, $e->errorcode, self::text($e->a), $skew);
            return 'failed';
        } catch (plugin_exception $e) {
            state::record_poll($now, null, $e->errorcode, self::text($e->a));
            return 'failed';
        }
        if (!$this->apply_instructions($result->instructions, $client, $state, $pair, $now, $result)) {
            return 'failed';
        }
        // The answer and the instructions are saved, so a run that is due now starts with the newest settings.
        ($this->starter)($now);
        return 'ok';
    }

    /**
     * Applies the instructions of a status answer one by one and records the outcome of the poll.
     *
     * An instruction that cannot be verified or has a body that breaks a rule is skipped and recorded as
     * instruction_invalid; the others are applied, and the poll still counts as made. When the list of AWS ranges cannot be
     * fetched, the error of the request is recorded and the next poll tries again.
     *
     * @param array $instructions The envelopes of the answer.
     * @param client $client API client.
     * @param state $state The registration.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @param int $now This server's clock.
     * @param \tool_secbackup\local\api\status_result $result The status answer.
     * @return bool False when something that was asked for failed.
     */
    private function apply_instructions(
        array $instructions,
        client $client,
        state $state,
        #[\SensitiveParameter] signing_keypair $pair,
        int $now,
        \tool_secbackup\local\api\status_result $result
    ): bool {
        $trust = $this->trust ?? sec_trust::current();
        $error = '';
        $errora = '';
        $ok = true;
        foreach ($instructions as $envelope) {
            try {
                if (!is_array($envelope)) {
                    throw new envelope_exception('instruction_invalid');
                }
                $this->apply_instruction(instruction::from_envelope($envelope, $trust, $state->site_id()), $client, $state, $pair);
            } catch (envelope_exception $e) {
                $error = 'instruction_invalid';
                $errora = '';
            } catch (plugin_exception $e) {
                $error = $e->errorcode;
                $errora = self::text($e->a);
                $ok = false;
            }
        }
        state::record_poll($now, $result, $error, $errora);
        return $ok;
    }

    /**
     * Applies one verified instruction.
     *
     * @param instruction $instruction The instruction.
     * @param client $client API client.
     * @param state $state The registration.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The site's key pair.
     * @throws plugin_exception When the list of AWS ranges cannot be fetched, or the instruction breaks a rule.
     */
    private function apply_instruction(
        instruction $instruction,
        client $client,
        state $state,
        #[\SensitiveParameter] signing_keypair $pair
    ): void {
        if ($instruction->kind() === instruction::KIND_SETTINGS) {
            settings::apply($instruction);
        } else if (aws_ranges::is_newer($instruction)) {
            aws_ranges::apply($instruction, $client->aws_ranges($state->endpoint(), $pair, $state->site_id()));
        }
    }

    /**
     * An error's value as text for a stored setting.
     *
     * @param mixed $a The value of the error; it can be anything, so only a scalar is kept.
     * @return string
     */
    private static function text(mixed $a): string {
        return is_scalar($a) ? (string) $a : '';
    }
}
