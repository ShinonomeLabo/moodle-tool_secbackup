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

namespace tool_secbackup\local\trust;

/**
 * Overrides for development and for the staging service, read only from config.php.
 *
 *     $CFG->tool_secbackup_developer = [
 *         'trustedkeys' => ['<standard base64 of a 32-byte Ed25519 public key>'],
 *         'allowhttp' => true,
 *         'allowprivatehosts' => true,
 *         'skipnetworkchecks' => true,
 *     ];
 *
 * - trustedkeys: response signing keys to trust besides the keys embedded in sec_trust.
 * - allowhttp: accept http:// connection URLs.
 * - allowprivatehosts: accept host names without a dot and hosts on private or loopback addresses,
 *   and connect without Moodle's HTTP security list.
 * - skipnetworkchecks: skip the pinned root certificates, the name resolution check and the AWS range check.
 *
 * Nothing in the web interface can set these: whoever can edit config.php already controls the server. The value is read
 * from $CFG->config_php_settings, the copy of config.php that Moodle takes before it loads the settings of its database
 * and of the cache in front of it. Moodle copies those into $CFG for every name that config.php does not set, so a value
 * that only $CFG holds could have been written by someone with much less access than config.php.
 * While any of them is active, every page of the plugin shows a warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class developer_settings {
    /** @var string[] Names of the switches. */
    private const SWITCHES = ['allowhttp', 'allowprivatehosts', 'skipnetworkchecks'];

    /** @var string[] Raw 32-byte public keys. */
    private array $trustedkeys;

    /** @var bool[] Values of the switches by name. */
    private array $switches;

    /**
     * Constructor.
     *
     * @param string[] $trustedkeys Raw 32-byte public keys.
     * @param bool[] $switches Values of the switches by name.
     */
    private function __construct(array $trustedkeys, array $switches) {
        $this->trustedkeys = $trustedkeys;
        $this->switches = $switches;
    }

    /**
     * Reads the overrides from config.php.
     *
     * @return self
     */
    public static function current(): self {
        global $CFG;
        return self::from_value($CFG->config_php_settings['tool_secbackup_developer'] ?? null);
    }

    /**
     * Reads overrides from a value of $CFG->tool_secbackup_developer.
     *
     * @param mixed $value The value, or null when it is not set.
     * @return self
     * @throws \moodle_exception When the value is not valid (error code developersettings_invalid).
     */
    public static function from_value(mixed $value): self {
        if ($value === null) {
            return new self([], array_fill_keys(self::SWITCHES, false));
        }
        if (!is_array($value)) {
            throw self::invalid('$CFG->tool_secbackup_developer must be an array.');
        }
        $unknown = array_diff(array_keys($value), array_merge(['trustedkeys'], self::SWITCHES));
        if ($unknown !== []) {
            throw self::invalid('Unknown setting: ' . implode(', ', $unknown) . '.');
        }
        $keys = $value['trustedkeys'] ?? [];
        if (!is_array($keys)) {
            throw self::invalid('trustedkeys must be an array.');
        }
        $rawkeys = [];
        foreach ($keys as $key) {
            $raw = is_string($key) ? base64_decode($key, true) : false;
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw self::invalid('Each trusted key must be the standard base64 of a 32-byte Ed25519 public key.');
            }
            $rawkeys[] = $raw;
        }
        $switches = [];
        foreach (self::SWITCHES as $name) {
            $switch = $value[$name] ?? false;
            if (!is_bool($switch)) {
                throw self::invalid($name . ' must be true or false.');
            }
            $switches[$name] = $switch;
        }
        return new self($rawkeys, $switches);
    }

    /**
     * Builds the error for an invalid value. The reason is shown only with debugging on.
     *
     * @param string $reason Reason in English.
     * @return \moodle_exception
     */
    private static function invalid(string $reason): \moodle_exception {
        return new \moodle_exception('developersettings_invalid', 'tool_secbackup', '', null, $reason);
    }

    /**
     * Whether any override is active.
     *
     * @return bool
     */
    public function is_active(): bool {
        return $this->trustedkeys !== [] || in_array(true, $this->switches, true);
    }

    /**
     * Extra response signing keys to trust.
     *
     * @return string[] Raw 32-byte public keys.
     */
    public function trusted_keys(): array {
        return $this->trustedkeys;
    }

    /**
     * Whether http:// connection URLs are accepted.
     *
     * @return bool
     */
    public function allow_http(): bool {
        return $this->switches['allowhttp'];
    }

    /**
     * Whether private, loopback and dotless hosts are accepted.
     *
     * @return bool
     */
    public function allow_private_hosts(): bool {
        return $this->switches['allowprivatehosts'];
    }

    /**
     * Whether the network checks before sending are skipped.
     *
     * @return bool
     */
    public function skip_network_checks(): bool {
        return $this->switches['skipnetworkchecks'];
    }
}
