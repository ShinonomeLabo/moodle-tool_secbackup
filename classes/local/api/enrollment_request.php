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

namespace tool_secbackup\local\api;

use tool_secbackup\local\crypto\fingerprint;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\plugin_exception;

/**
 * The body of an enrolment request.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrollment_request {
    /** @var string Registration code (secret). */
    private string $code;

    /** @var string The site's encryption public key (32 bytes). */
    private string $encryptionkey;

    /** @var array Site information: wwwroot, moodle_release, moodle_version, db_family. */
    private array $site;

    /** @var string Version of this plugin. */
    private string $pluginversion;

    /**
     * Constructor.
     *
     * The type of $code is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see
     * signing_keypair::__construct()). The attribute keeps the registration code out of the trace of an exception.
     *
     * @param #[|\SensitiveParameter|]|string $code Registration code, normalised.
     * @param string $encryptionkey The site's encryption public key (32 bytes).
     * @param array $site Site information.
     * @param string $pluginversion Version of this plugin.
     */
    public function __construct(#[\SensitiveParameter] string $code, string $encryptionkey, array $site, string $pluginversion) {
        $this->code = $code;
        $this->encryptionkey = $encryptionkey;
        $this->site = $site;
        $this->pluginversion = $pluginversion;
    }

    /**
     * The request for this site.
     *
     * The type of $code is written the way the PHPDoc checker reads the signature (see __construct()).
     *
     * @param #[|\SensitiveParameter|]|string $code Registration code, normalised.
     * @param string $encryptionkey The site's encryption public key (32 bytes).
     * @return self
     */
    public static function for_this_site(#[\SensitiveParameter] string $code, string $encryptionkey): self {
        global $CFG, $DB;
        return new self($code, $encryptionkey, [
            'wwwroot' => $CFG->wwwroot,
            'moodle_release' => (string) $CFG->release,
            // Moodle keeps the version as text, so 2024100703.10 may read 2024100703.1. The service wants two decimals.
            'moodle_version' => number_format((float) $CFG->version, 2, '.', ''),
            'db_family' => self::db_family($DB),
        ], (string) get_config('tool_secbackup', 'version'));
    }

    /**
     * The database family as the service names it.
     *
     * @param \moodle_database $db The database.
     * @return string mysql, mariadb or postgres.
     * @throws plugin_exception When the database is not supported (db_unsupported).
     */
    public static function db_family(\moodle_database $db): string {
        if ($db->get_dbvendor() === 'mariadb') {
            return 'mariadb';
        }
        if (in_array($db->get_dbfamily(), ['mysql', 'postgres'], true)) {
            return $db->get_dbfamily();
        }
        throw new plugin_exception('db_unsupported');
    }

    /**
     * The JSON body.
     *
     * The type of $pair is written the way the PHPDoc checker reads the signature (see __construct()). The attribute keeps
     * the key pair out of the trace of an exception.
     *
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The key pair being enrolled.
     * @return string
     */
    public function body(#[\SensitiveParameter] signing_keypair $pair): string {
        return json_encode([
            'enrollment_code' => $this->code,
            'signing_public_key' => base64_encode($pair->public_key()),
            'encryption_key_fingerprint' => fingerprint::format($this->encryption_fingerprint()),
            'site' => $this->site,
            'plugin_version' => $this->pluginversion,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Fingerprint of the encryption public key.
     *
     * @return string
     */
    public function encryption_fingerprint(): string {
        return fingerprint::of($this->encryptionkey);
    }

    /**
     * Keeps the registration code out of print_r(), var_dump() and debugging output.
     *
     * PHP matches magic method names without regard to case. The lower-case spelling is the one the Moodle code
     * checker accepts for this method.
     *
     * @return array
     */
    public function __debuginfo(): array {
        return ['code' => '[hidden]', 'site' => $this->site, 'pluginversion' => $this->pluginversion];
    }
}
