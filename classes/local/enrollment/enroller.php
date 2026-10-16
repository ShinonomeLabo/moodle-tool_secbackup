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

use tool_secbackup\event\site_enrolled;
use tool_secbackup\local\api\client;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\api\enrollment_request;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\sec_trust;

/**
 * Registers this site with the service, or registers it again with a new registration code.
 *
 * Nothing is sent unless a key to verify the service's answer is trusted, and nothing is saved unless the signed answer is
 * verified.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enroller {
    /** @var client API client. */
    private client $client;

    /** @var callable Returns the current UNIX time. */
    private $clock;

    /** @var sec_trust The keys that the client verifies the answer with. */
    private sec_trust $trust;

    /**
     * Constructor.
     *
     * @param client $client API client.
     * @param callable|null $clock Returns the current UNIX time; null for time().
     * @param sec_trust|null $trust The keys that the client verifies the answer with; null for those of this site.
     */
    public function __construct(client $client, ?callable $clock = null, ?sec_trust $trust = null) {
        $this->client = $client;
        $this->clock = $clock ?? static fn(): int => time();
        $this->trust = $trust ?? sec_trust::current();
    }

    /**
     * The enroller of this site.
     *
     * @return self
     */
    public static function create(): self {
        return new self(client::create());
    }

    /**
     * Registers this site.
     *
     * @param endpoint $endpoint Connection URL. While the site is registered, it must be the registered one.
     * @param string $encryptionkey The site's encryption public key (32 bytes), pasted from the platform screen.
     * @param #[|\SensitiveParameter|]|string $code Registration code, normalised.
     * @return enroll_result The verified answer. Once the registration is saved, a failure to log it or to tell the
     *     administrators is reported with debugging() and does not leave this method.
     * @throws plugin_exception enroll_no_trusted_key, before anything is sent, when no key can verify the answer.
     */
    public function enroll(endpoint $endpoint, string $encryptionkey, #[\SensitiveParameter] string $code): enroll_result {
        $previous = state::load();
        if ($previous->is_enrolled() && $previous->endpoint_url() !== $endpoint->url()) {
            throw new \coding_exception('The connection URL cannot change while the site is registered.');
        }
        // Without a trusted key the answer cannot be verified, and the service would use the code up for nothing.
        if ($this->trust->key_ids() === []) {
            throw new plugin_exception('enroll_no_trusted_key');
        }
        $pair = signing_keypair::generate();
        $result = $this->client->enroll($endpoint, $pair, enrollment_request::for_this_site($code, $encryptionkey));
        state::save_enrollment($result, public_key_format::encode($encryptionkey), $pair, ($this->clock)());
        // The registration is saved and the code is used up: nothing below may undo that by throwing.
        try {
            site_enrolled::create([
                'context' => \context_system::instance(),
                'other' => ['endpoint' => $result->apibase, 'siteid' => $result->siteid, 'again' => $previous->is_enrolled()],
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('The registration could not be logged: ' . $e::class);
        }
        notifier::send(
            $previous->is_enrolled() ? 'reenrolled' : 'enrolled',
            $result->platformname,
            $result->sitename,
            $result->apibase
        );
        return $result;
    }
}
