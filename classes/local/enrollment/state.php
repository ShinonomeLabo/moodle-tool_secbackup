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
    /** @var string[] Settings that hold the registration (the signing key is kept by signing_keys). */
    private const NAMES = ['siteid', 'sitename', 'platformname', 'endpoint', 'encryptionkey', 'enrolledat', 'clockskew',
        'lastpoll', 'lastpollstatus', 'lastpollerror', 'lastpollerrora'];

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
     * @throws \Throwable Whatever fails while saving. Nothing is left saved, and the cache of the settings is not
     *     left holding values that were rolled back.
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
            // The rollback does not touch the cache of the settings, which holds the values set above.
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
     * Forgets the registration and the signing key.
     */
    public static function clear(): void {
        self::forget_values();
        signing_keys::forget();
    }

    /**
     * Removes the registration settings.
     */
    private static function forget_values(): void {
        foreach (self::NAMES as $name) {
            unset_config($name, 'tool_secbackup');
        }
    }
}
