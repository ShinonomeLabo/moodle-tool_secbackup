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

/**
 * English strings for tool_secbackup.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['developersettings_invalid'] = 'The developer settings in config.php (TOOL_SECBACKUP_DEVELOPER) are not valid. Turn on debugging to see the reason.';
$string['endpoint_empty'] = 'Enter the connection URL shown on the platform screen.';
$string['endpoint_host'] = 'The host name of the connection URL is not allowed. Use the URL shown on the platform screen, with a host name and not an IP address.';
$string['endpoint_invalid'] = 'The connection URL is not a URL. Copy the whole URL, starting with https://, from the platform screen.';
$string['endpoint_path'] = 'The path of the connection URL contains characters that are not allowed. Copy the URL again from the platform screen.';
$string['endpoint_port'] = 'The connection URL must not contain a port number other than the standard one (443).';
$string['endpoint_query'] = 'The connection URL must not contain "?" or "#". Copy the URL again from the platform screen.';
$string['endpoint_scheme'] = 'The connection URL must start with https://.';
$string['endpoint_userinfo'] = 'The connection URL must not contain a user name or a password.';
$string['envelope_malformed'] = 'The response from the service could not be read. Check that the connection URL is correct.';
$string['envelope_signature'] = 'The signature of the response from the service could not be verified. Check that the connection URL is correct.';
$string['envelope_untrusted'] = 'The response was signed with a key that this plugin does not trust. Check that the connection URL is correct.';
$string['pluginname'] = 'Shinonome LMS Backup';
$string['privacy:metadata:service'] = 'The plugin sends encrypted backups of this site to the Shinonome LMS Backup service.';
$string['privacy:metadata:service:sitedata'] = 'The whole site, including the personal data of all users (everything in the backup).';
$string['publickey_base64'] = 'The public key contains characters that are not allowed. Copy it again from the platform screen.';
$string['publickey_length'] = 'The public key is not complete. Copy the whole key again from the platform screen.';
$string['publickey_prefix'] = 'The public key must start with "secbk1:". Copy it again from the platform screen.';
$string['secbackup:manage'] = 'Manage the connection to Shinonome LMS Backup';
$string['signingkey_corrupt'] = 'The stored signing key cannot be read. Remove the registration and register the site again.';
$string['signingkey_savefailed'] = 'The signing key could not be encrypted, so it was not saved. Check the encryption key file in the data directory and try again.';
