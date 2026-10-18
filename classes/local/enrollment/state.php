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

use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\api\status_result;
use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\crypto\signing_keys;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\trust\developer_settings;

/**
 * The registration of this site, kept in the plugin's settings.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state {
    /**
     * @var string[] Settings that hold the registration (the signing key is kept by signing_keys), in the order in which
     *     they are removed. They are removed one by one, outside a transaction, so a failure can stop the removal half-way.
     *     The list starts with the key for the names of the chunks (dedup_key), which belongs to the registration: a new
     *     registration gets a new key. It ends with the connection URL, the site ID and the site name, in that order. The
     *     site ID is what makes the site read as registered, and the removal form asks for the site name: while the site
     *     ID is there, the name is there too, so that the removal can be confirmed and run again. A registered site keeps
     *     its connection URL as long as possible. Once the site ID is gone, the site reads as not registered, and a later
     *     registration removes every stored value first.
     */
    private const NAMES = ['dedupsecret', 'platformname', 'encryptionkey', 'enrolledat', 'clockskew',
        'backupsettings', 'backupsettingsseq', 'backupsettingssite', 'awsranges', 'awsrangesseq', 'awsrangesendpoint',
        'lastpoll', 'lastpollstatus', 'lastpollerror', 'lastpollerrora', 'endpoint', 'siteid', 'sitename'];

    /** @var \stdClass The plugin's settings. */
    private \stdClass $config;

    /**
     * Constructor.
     *
     * @param \stdClass $config The plugin's settings.
     */
    private function __construct(\stdClass $config) {
        $this->config = $config;
    }

    /**
     * Reads the registration.
     *
     * @return self
     */
    public static function load(): self {
        return new self((object) get_config('tool_secbackup'));
    }

    /**
     * A text setting.
     *
     * @param string $name Setting name.
     * @return string Empty when not set.
     */
    private function text(string $name): string {
        return (string) ($this->config->$name ?? '');
    }

    /**
     * A whole number setting.
     *
     * @param string $name Setting name.
     * @return int|null Null when not set or not a number.
     */
    private function number(string $name): ?int {
        $value = $this->text($name);
        return preg_match('/^-?\d+\z/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Whether the site is registered.
     *
     * @return bool
     */
    public function is_enrolled(): bool {
        return $this->site_id() !== '';
    }

    /**
     * Whether the database holds a site ID for the plugin, whatever the cached settings say.
     *
     * The settings are cached, and a request that read them just before a registration was committed can cache the
     * values from before it. This asks the table itself.
     *
     * @return bool
     */
    public static function site_id_is_stored(): bool {
        global $DB;
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => 'tool_secbackup', 'name' => 'siteid']);
        return (string) $value !== '';
    }

    /**
     * Site ID at the service.
     *
     * @return string
     */
    public function site_id(): string {
        return $this->text('siteid');
    }

    /**
     * Site name on the platform.
     *
     * @return string
     */
    public function site_name(): string {
        return $this->text('sitename');
    }

    /**
     * Platform name.
     *
     * @return string
     */
    public function platform_name(): string {
        return $this->text('platformname');
    }

    /**
     * Connection URL in its normal form.
     *
     * @return string
     */
    public function endpoint_url(): string {
        return $this->text('endpoint');
    }

    /**
     * Connection URL.
     *
     * @return endpoint
     */
    public function endpoint(): endpoint {
        return endpoint::parse($this->endpoint_url(), developer_settings::current());
    }

    /**
     * The site's encryption public key, as the platform shows it.
     *
     * @return string
     */
    public function encryption_key(): string {
        return $this->text('encryptionkey');
    }

    /**
     * Fingerprint of the encryption public key.
     *
     * @return string Empty when not registered.
     */
    public function encryption_fingerprint(): string {
        return $this->encryption_key() === '' ? '' : fingerprint::of(public_key_format::parse($this->encryption_key()));
    }

    /**
     * When the site was registered (this server's clock).
     *
     * @return int|null
     */
    public function enrolled_at(): ?int {
        return $this->number('enrolledat');
    }

    /**
     * Last known difference between the service's clock and this server's clock, in seconds.
     *
     * @return int|null
     */
    public function clock_skew(): ?int {
        return $this->number('clockskew');
    }

    /**
     * When the status was last asked.
     *
     * @return int|null
     */
    public function last_poll(): ?int {
        return $this->number('lastpoll');
    }

    /**
     * Last status given by the service.
     *
     * @return string
     */
    public function last_poll_status(): string {
        return $this->text('lastpollstatus');
    }

    /**
     * Error of the last status request (a language string of tool_secbackup), or empty.
     *
     * @return string
     */
    public function last_poll_error(): string {
        return $this->text('lastpollerror');
    }

    /**
     * Value for the language string of the last error.
     *
     * @return string
     */
    public function last_poll_error_a(): string {
        return $this->text('lastpollerrora');
    }

    /**
     * Saves a verified registration, replacing any previous one.
     *
     * @param enroll_result $result The verified answer.
     * @param string $encryptionkey The site's encryption public key, as the platform shows it.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The enrolled key pair.
     * @param int $now This server's clock.
     * @throws \Throwable Whatever fails while saving. When this is not inside another open transaction, nothing is left
     *     saved. Inside one, the outer transaction decides: it is marked for rollback, but the rows stay visible to
     *     this request until it ends.
     */
    public static function save_enrollment(
        enroll_result $result,
        string $encryptionkey,
        #[\SensitiveParameter] signing_keypair $pair,
        int $now
    ): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            self::forget_values();
            $values = [
                'siteid' => $result->siteid,
                'sitename' => $result->sitename,
                'platformname' => $result->platformname,
                'endpoint' => $result->apibase,
                'encryptionkey' => $encryptionkey,
                'enrolledat' => $now,
                'clockskew' => $result->clockskew,
            ];
            foreach ($values as $name => $value) {
                set_config($name, $value, 'tool_secbackup');
            }
            signing_keys::save($pair);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The function set_config() removed the cached settings. They are cached again with the values of the open
            // transaction only if something read the settings before the failure, and the rollback does not touch the
            // cache, so it is removed again here.
            \cache_helper::invalidate_by_definition('core', 'config', [], 'tool_secbackup');
            // Rethrows the exception.
            $transaction->rollback($e);
        }
    }

    /**
     * Records the outcome of a status request.
     *
     * @param int $now This server's clock.
     * @param status_result|null $result The answer, or null when the request failed.
     * @param string $error Language string of the failure, or empty.
     * @param string $errora Value for that language string.
     * @param int|null $clockskew Clock difference learnt from a failure.
     */
    public static function record_poll(
        int $now,
        ?status_result $result,
        string $error = '',
        string $errora = '',
        ?int $clockskew = null
    ): void {
        set_config('lastpoll', $now, 'tool_secbackup');
        set_config('lastpollerror', $error, 'tool_secbackup');
        set_config('lastpollerrora', $errora, 'tool_secbackup');
        if ($result !== null) {
            set_config('lastpollstatus', $result->status, 'tool_secbackup');
            $clockskew = $result->clockskew;
        }
        if ($clockskew !== null) {
            set_config('clockskew', $clockskew, 'tool_secbackup');
        }
    }

    /**
     * Forgets the signing key and the registration.
     *
     * The key goes first, then the settings in the order of the list of names, so that a failure in between leaves a
     * site that still reads as registered and still has its name, and the removal can be run again; the key is never
     * what is left behind.
     */
    public static function clear(): void {
        signing_keys::forget();
        self::forget_values();
    }

    /**
     * Removes the registration settings, in the order of the list: the site name goes after the site ID.
     */
    private static function forget_values(): void {
        foreach (self::NAMES as $name) {
            unset_config($name, 'tool_secbackup');
        }
    }
}
