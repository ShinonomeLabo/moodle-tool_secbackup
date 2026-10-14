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
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\plugin_exception;

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

    /** @var callable Returns the current UNIX time. */
    private $clock;

    /**
     * Constructor.
     *
     * @param client|null $client API client; null for the one of this site.
     * @param callable|null $clock Returns the current UNIX time; null for time().
     */
    public function __construct(?client $client = null, ?callable $clock = null) {
        $this->client = $client;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Asks for the status once.
     *
     * @return string ok, notregistered or failed.
     */
    public function run(): string {
        $state = state::load();
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
            $result = ($this->client ?? client::create())->status($state->endpoint(), $pair, $state->site_id());
        } catch (api_exception $e) {
            $skew = $e->servertime === null ? null : $e->servertime - $now;
            state::record_poll($now, null, $e->errorcode, self::text($e->a), $skew);
            return 'failed';
        } catch (plugin_exception $e) {
            state::record_poll($now, null, $e->errorcode, self::text($e->a));
            return 'failed';
        }
        state::record_poll($now, $result);
        return 'ok';
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
