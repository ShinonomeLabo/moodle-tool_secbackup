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
 *     define('TOOL_SECBACKUP_DEVELOPER', [
 *         'trustedkeys' => ['<standard base64 of a 32-byte Ed25519 public key>'],
 *         'allowhttp' => true,
 *         'allowprivatehosts' => true,
 *         'skipnetworkchecks' => true,
 *     ]);
 *
 * - trustedkeys: response signing keys to trust besides the keys embedded in sec_trust.
 * - allowhttp: accept http:// connection URLs.
 * - allowprivatehosts: accept host names without a dot and hosts on private or loopback addresses,
 *   and connect without Moodle's HTTP security list.
 * - skipnetworkchecks: skip the pinned root certificates, the name resolution check and the AWS range check.
 *
 * The settings are a constant because nothing but config.php can define one. Moodle copies the settings of its database and
 * of the cache in front of it into $CFG, and a cache entry can even replace $CFG->config_php_settings, the copy of config.php
 * that Moodle keeps there. So neither $CFG nor that copy is a place that only whoever can edit config.php can write to.
 * Nothing in the web interface can set these: whoever can edit config.php already controls the server.
 * While PHPUnit runs, the settings are read from $CFG->tool_secbackup_developer instead, so that a test can vary them.
 * While any of them is active, every page of the plugin shows a warning.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class developer_settings {
    /** @var string Name of the constant that config.php defines. */
    public const CONSTANT = 'TOOL_SECBACKUP_DEVELOPER';

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
        return self::from_value(self::configured_value(defined('PHPUNIT_TEST') && PHPUNIT_TEST));
    }

    /**
     * The value that config.php gave the developer settings, or null when it gave none.
     *
     * Outside PHPUnit this is the constant TOOL_SECBACKUP_DEVELOPER and nothing else. Not $CFG, not the copy of config.php
     * in $CFG->config_php_settings, and not any setting of the database or of a cache: Moodle copies those into $CFG, and
     * whoever can write to the database or to a cache must not be able to switch the developer settings on. Inside PHPUnit
     * (the constant PHPUNIT_TEST) the value is $CFG->tool_secbackup_developer, which Moodle resets after each test.
     *
     * @param bool $inphpunit Whether PHPUnit is running.
     * @param string $constant Name of the constant. Only the tests of this class pass another name.
     * @return mixed The value, or null when there is none.
     */
    public static function configured_value(bool $inphpunit, string $constant = self::CONSTANT): mixed {
        global $CFG;
        if ($inphpunit) {
            return $CFG->tool_secbackup_developer ?? null;
        }
        return defined($constant) ? constant($constant) : null;
    }

    /**
     * Reads overrides from a value of the constant TOOL_SECBACKUP_DEVELOPER.
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
            throw self::invalid(self::CONSTANT . ' must be an array.');
        }
        $unknown = array_diff(array_keys($value), array_merge(['trustedkeys'], self::SWITCHES));
        if ($unknown !== []) {
            throw self::invalid(self::unknown_reason($unknown));
        }
        // An explicit null is a value of the wrong type, not a setting that is left out.
        $keys = array_key_exists('trustedkeys', $value) ? $value['trustedkeys'] : [];
        if (!is_array($keys)) {
            throw self::invalid('trustedkeys must be an array.');
        }
        $rawkeys = [];
        foreach ($keys as $key) {
            $raw = is_string($key) ? base64_decode($key, true) : false;
            // Only the canonical text is accepted: the bytes have to encode back to the same text, which rules out blanks,
            // line breaks, a missing "=" and stray bits in the last character.
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || base64_encode($raw) !== $key) {
                throw self::invalid('Each trusted key must be the standard base64 of a 32-byte Ed25519 public key.');
            }
            $rawkeys[] = $raw;
        }
        $switches = [];
        foreach (self::SWITCHES as $name) {
            $switch = array_key_exists($name, $value) ? $value[$name] : false;
            if (!is_bool($switch)) {
                throw self::invalid($name . ' must be true or false.');
            }
            $switches[$name] = $switch;
        }
        return new self($rawkeys, $switches);
    }

    /**
     * Describes setting names that are not known. A name is printed only if it looks like the name of a setting (1 to 32
     * lower case letters). Anything else, for example a key and its value that were swapped by mistake, is not printed.
     *
     * @param array $names The keys of the value that are not settings.
     * @return string Reason in English.
     */
    private static function unknown_reason(array $names): string {
        $listed = array_filter($names, static fn($name): bool => is_string($name) && preg_match('/^[a-z]{1,32}\z/', $name) === 1);
        $reason = $listed === [] ? 'Unknown setting.' : 'Unknown setting: ' . implode(', ', $listed) . '.';
        if (count($listed) < count($names)) {
            $reason .= ' Names that do not look like the name of a setting are not shown.';
        }
        return $reason;
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
