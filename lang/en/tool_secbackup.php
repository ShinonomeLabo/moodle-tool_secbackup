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

$string['action_deregister'] = 'Remove the registration';
$string['action_next'] = 'Next';
$string['action_register'] = 'Register';
$string['api_artifact_signature_invalid'] = 'The backup service could not verify the signature of the manifest or the index that this site sent. If the keys of this site were replaced, register this site again, then run the backup again.';
$string['api_body_too_large'] = 'The request was larger than the service accepts.';
$string['api_capacity_recheck_required'] = 'The backup service asks for the expected size of this backup to be checked again before it accepts more data. The backup checks it again and goes on.';
$string['api_contract_inactive'] = 'The contract for the backup service is not active. Check the contract on the platform screen.';
$string['api_core_manifest_not_found'] = 'The backup service has no list of the official files for this version of Moodle.';
$string['api_encryption_key_mismatch'] = 'The public key pasted here is not the current public key of this site on the platform. Copy the public key again from the platform screen.';
$string['api_enrollment_code_invalid'] = 'The registration code is wrong, has expired (codes are valid for 30 minutes) or has already been used. Issue a new registration code on the platform screen.';
$string['api_invalid_request'] = 'The service did not accept the request. Update this plugin to the latest version.';
$string['api_ip_not_allowed'] = 'The service does not accept connections from the IP address of this server. Add the IP address of this server in the connection settings on the platform screen.';
$string['api_ip_not_allowed_address'] = 'The service does not accept connections from {$a}, the address it saw for this server. Check that {$a} is the address this server uses to reach the internet, then add it in the connection settings on the platform screen.';
$string['api_nonce_reused'] = 'The service received the same request twice. Try again.';
$string['api_rate_limited'] = 'Too many requests were sent to the service. Wait a while and try again.';
$string['api_response_invalid'] = 'The answer of the backup service could not be used.';
$string['api_run_in_progress'] = 'The backup service says that a backup of this site is still in progress.';
$string['api_run_limit_reached'] = 'The number of backups that the plan allows in 24 hours has been reached. Try again later.';
$string['api_run_not_found'] = 'The backup service does not know this backup.';
$string['api_run_state_invalid'] = 'The backup service does not accept this operation in the present state of the backup.';
$string['api_signature_invalid'] = 'The service could not verify the signature of this site. If this site was registered again from another server, register it again here.';
$string['api_signature_required'] = 'The signature of the request did not reach the service. A proxy or a security appliance may remove headers; ask its administrator to pass the headers that start with X-SecBackup.';
$string['api_site_not_enrolled'] = 'The service does not know this site, or its registration was disabled. Issue a registration code on the platform screen and register this site again.';
$string['api_timestamp_out_of_range'] = 'The clock of this server differs too much from the clock of the service. Set the time of this server (for example with NTP) and try again.';
$string['api_timestamp_out_of_range_minutes'] = 'The clock of this server differs from the clock of the service by about {$a} minutes. Set the time of this server (for example with NTP) and try again.';
$string['api_unavailable'] = 'The service is busy or under maintenance. Try again later.';
$string['api_unexpected'] = 'The service gave an unexpected answer (HTTP {$a}).';
$string['api_wwwroot_mismatch'] = 'The URL of this site ($CFG->wwwroot) is not the site URL registered on the platform. Check the site URL on the platform screen.';
$string['capacity_refused'] = 'The backup service refused the expected size of this backup, because it is more than the plan allows. The backup was stopped.';
$string['check_apparmor'] = 'AppArmor';
$string['check_clock'] = 'Clock';
$string['check_connection'] = 'Connection to the service';
$string['check_developer'] = 'Developer settings';
$string['check_dns'] = 'Name resolution';
$string['check_extensions'] = 'PHP extensions';
$string['check_moodledata'] = 'Data directory';
$string['check_proxy'] = 'Proxy';
$string['check_selinux'] = 'SELinux';
$string['cli_diagnose_help'] = 'Checks the connection from this server to the backup service.

Options:
--endpoint=URL  Connection URL to check when the site is not registered.
-h, --help      Print this help.

Run it as the user that runs cron, for example:
$ sudo -u www-data /usr/bin/php admin/tool/secbackup/cli/diagnose.php
(Moodle 5.1 and later: public/admin/tool/secbackup/cli/diagnose.php)';
$string['clock_skew_warning'] = 'The clock of this server differs from the clock of the service by about {$a} minutes. When it differs by more than 5 minutes, the service refuses the requests of this site. Set the time of this server (for example with NTP).';
$string['connect_again_explain'] = 'Register again when the platform issued a new registration code, or when the public key of this site was created again on the platform.';
$string['connect_again_heading'] = 'Register again';
$string['contract_inactive'] = 'The contract for the backup service ended while the backup was running, so the backup was stopped.';
$string['core_manifest_invalid'] = 'The list of the official Moodle files from the backup service, or the version file of this site, could not be read as expected. The backup sends all the Moodle program files instead, which takes longer and uses more storage.';
$string['core_manifest_mismatch'] = 'The list of the official Moodle files from the backup service is for another version of Moodle than the one on this site. The backup sends all the Moodle program files instead, which takes longer and uses more storage.';
$string['crypto_chunk_corrupt'] = 'A backup chunk is damaged, was changed or does not belong to this site, so it could not be decrypted.';
$string['crypto_chunk_sourceshort'] = 'The data for a backup chunk ended before its expected length. It may have changed while it was read.';
$string['crypto_chunk_truncated'] = 'A backup chunk ends before its last part, so it is incomplete.';
$string['db_unsupported'] = 'This database is not supported. The plugin supports MySQL, MariaDB and PostgreSQL.';
$string['dedupkey_corrupt'] = 'The stored key for the names of the backup chunks cannot be read. Remove the registration and register the site again.';
$string['dedupkey_savefailed'] = 'The key for the names of the backup chunks could not be encrypted, so it was not saved. Check the encryption key file in the data directory and try again.';
$string['deregister_done'] = 'The registration of this site was removed. Backups are no longer sent. To be safe, also remove the site or issue a new registration code on {$a}.';
$string['deregister_explain'] = 'Removing the registration deletes the key and the registration of this site on this server. {$a} is not told; remove the site or issue a new registration code there as well.';
$string['deregister_heading'] = 'Remove the registration';
$string['deregister_typename'] = 'To confirm, type the site name on the platform: {$a}';
$string['developer_banner'] = 'Developer settings are active in config.php (TOOL_SECBACKUP_DEVELOPER). Some protections of the connection are off. Remove them on production sites.';
$string['developersettings_invalid'] = 'The developer settings in config.php (TOOL_SECBACKUP_DEVELOPER) are not valid. Turn on debugging to see the reason.';
$string['diag_apparmor_advice'] = 'If AppArmor denies the connection, the server administrator can look into it with these commands, run as root. To find the denials: journalctl --no-pager | grep DENIED (this reads the whole journal; journalctl -k can miss them). Where auditd runs, they are also in /var/log/audit/audit.log. A rule written as deny logs nothing; write it as audit deny to log the denials. To let PHP connect, add these four rules, one on each line, to the local file of the profile (for the profile php-fpm: /etc/apparmor.d/local/php-fpm): "network inet stream," "network inet dgram," "network inet6 stream," "network inet6 dgram,". Then load the profile again, naming the file of the profile (for php-fpm: apparmor_parser -r /etc/apparmor.d/php-fpm).';
$string['diag_apparmor_complain'] = 'Complain mode. Profile: {$a}.';
$string['diag_apparmor_enforcing'] = 'Enforcing. Profile: {$a}.';
$string['diag_apparmor_other'] = 'Profile and mode: {$a}.';
$string['diag_apparmor_unconfined'] = 'Enabled. This process is not confined.';
$string['diag_apparmor_unknown'] = 'Not used, or cannot be read.';
$string['diag_cli_advice'] = 'What to do: {$a}';
$string['diag_cli_endpoint'] = 'Connection URL: {$a}';
$string['diag_cli_heading'] = 'Connection diagnostics of Shinonome LMS Backup';
$string['diag_cli_noendpoint'] = 'Connection URL: not registered (give one with --endpoint)';
$string['diag_clock_seconds'] = 'The clocks differ by {$a} seconds.';
$string['diag_clock_unknown'] = 'Not known yet. It is measured when the site registers or asks for its status.';
$string['diag_connection_ok'] = 'The service was reached. This check asks without a signature, so HTTP {$a} (authentication required) is the expected answer.';
$string['diag_connection_other'] = 'Something answered, but not the service\'s API (HTTP {$a}). Check the connection URL.';
$string['diag_developer_advice'] = 'Remove the TOOL_SECBACKUP_DEVELOPER definition from config.php on production sites.';
$string['diag_developer_off'] = 'Not used.';
$string['diag_developer_on'] = 'Active: {$a}';
$string['diag_dns_noaddress'] = 'no address';
$string['diag_dns_noanswer'] = 'no answer';
$string['diag_dns_source_cloudflare'] = 'Cloudflare (1.1.1.1)';
$string['diag_dns_source_google'] = 'Google (8.8.8.8)';
$string['diag_dns_source_os'] = 'This server';
$string['diag_dns_viaproxy'] = 'not used, the proxy looks the name up';
$string['diag_extensions_advice'] = 'Install and enable the missing PHP extensions.';
$string['diag_extensions_missing'] = 'Missing: {$a}';
$string['diag_extensions_ok'] = 'All the extensions the plugin needs are loaded.';
$string['diag_heading_advice'] = 'What to do';
$string['diag_heading_check'] = 'Check';
$string['diag_heading_detail'] = 'Details';
$string['diag_heading_result'] = 'Result';
$string['diag_hint'] = 'To check the network of this server, open {$a}.';
$string['diag_moodledata'] = 'File system: {$a->type} (mounted at {$a->mount}, from {$a->source})';
$string['diag_moodledata_network'] = 'The data directory is on a network file system. Backups read every file in it, so they take longer and load that file system.';
$string['diag_moodledata_unknown'] = 'Cannot be read.';
$string['diag_no_endpoint'] = 'Register the site, or enter a connection URL, to check this.';
$string['diag_proxy_direct'] = 'Direct connection, without a proxy.';
$string['diag_proxy_used'] = 'Through the proxy {$a}.';
$string['diag_run'] = 'Check the connection';
$string['diag_selinux_advice'] = 'If SELinux denies the connection, the server administrator can check with: ausearch -m avc -ts recent. To let the web server connect only to web ports such as 443: setsebool -P httpd_can_network_relay on. To let it make any network connection: setsebool -P httpd_can_network_connect on';
$string['diag_selinux_enforcing'] = 'Enforcing. Process type: {$a}.';
$string['diag_selinux_permissive'] = 'Permissive. Process type: {$a}.';
$string['diag_selinux_unknown'] = 'Not used, or cannot be read.';
$string['diag_skipped'] = 'Skipped because of the developer settings.';
$string['dns_doh_failed'] = 'The DNS over HTTPS service did not answer.';
$string['dns_doh_unavailable'] = 'Neither DNS over HTTPS service (1.1.1.1 and 8.8.8.8) answered, so the address of the service could not be confirmed. Allow HTTPS connections to 1.1.1.1 or 8.8.8.8, or set up a proxy in Moodle.';
$string['dns_not_found'] = 'The host name of the connection URL was not found. Check the connection URL.';
$string['dns_os_failed'] = 'This server could not look up the host name of the connection URL. Check the DNS settings of the server.';
$string['dns_os_mismatch'] = 'This server\'s DNS answers with addresses that do not belong to the service. Nothing was sent. Ask the network administrator to check the DNS of this server.';
$string['dns_outside_aws'] = 'The host name of the connection URL does not point to the service. Nothing was sent. Check the connection URL. If it is right, the list of Amazon address ranges that comes with this plugin may be older than the addresses of the service: update the plugin to the latest version.';
$string['endpoint_empty'] = 'Enter the connection URL shown on the platform screen.';
$string['endpoint_host'] = 'The host name of the connection URL is not allowed. Use the URL shown on the platform screen, with a host name and not an IP address.';
$string['endpoint_invalid'] = 'The connection URL is not a URL. Copy the whole URL, starting with https://, from the platform screen.';
$string['endpoint_path'] = 'The path of the connection URL contains characters that are not allowed. Copy the URL again from the platform screen.';
$string['endpoint_port'] = 'The connection URL must not contain a port number other than the standard one (443).';
$string['endpoint_query'] = 'The connection URL must not contain "?" or "#". Copy the URL again from the platform screen.';
$string['endpoint_scheme'] = 'The connection URL must start with https://.';
$string['endpoint_userinfo'] = 'The connection URL must not contain a user name or a password.';
$string['enroll_code_used_advice'] = 'The service accepted the registration code before this failure, so the code cannot be used again. Fix the problem, then issue a new registration code on the platform screen and register again. Until then, this site cannot use the service.';
$string['enroll_done'] = 'This site was registered with {$a}.';
$string['enroll_no_trusted_key'] = 'This version of the plugin contains no key to check the answers of the service, so this site cannot be registered. Nothing was sent, and the registration code was not used. Update the plugin to a version that contains the key of the service.';
$string['enroll_reissue_advice'] = 'The registration code may have been sent to a wrong connection URL. To be safe, issue a new registration code on the platform screen.';
$string['enroll_response_invalid'] = 'The registration answer of the service could not be read. Nothing was saved.';
$string['enroll_response_mismatch'] = 'The signed answer of the service does not belong to this registration request. Nothing was saved. Check the connection URL, and to be safe, issue a new registration code on the platform screen.';
$string['envelope_malformed'] = 'The response from the service could not be read. Check that the connection URL is correct.';
$string['envelope_signature'] = 'The signature of the response from the service could not be verified. Check that the connection URL is correct.';
$string['envelope_untrusted'] = 'The response was signed with a key that this plugin does not trust. Check that the connection URL is correct.';
$string['event_deregistered'] = 'Registration with the backup service removed';
$string['event_enrolled'] = 'Site registered with the backup service';
$string['field_code'] = 'Registration code';
$string['field_code_help'] = 'The one-time registration code issued on the platform screen. It is valid for 30 minutes.';
$string['field_code_invalid'] = 'A registration code has the form SEC-BK-XXXX-XXXX-XXXX-XXXX-XXXX. Copy it again from the platform screen.';
$string['field_confirmfingerprint'] = 'I checked that this fingerprint matches the one on the screen of the platform (the service where the backups were ordered).';
$string['field_confirmfingerprint_label'] = 'Fingerprint check';
$string['field_confirmfingerprint_required'] = 'Compare the fingerprint with the one on the platform screen, and tick the box if they match.';
$string['field_confirmname'] = 'Site name';
$string['field_confirmname_mismatch'] = 'The typed name is not the site name on the platform.';
$string['field_endpoint'] = 'Connection URL';
$string['field_endpoint_fixed'] = 'The connection URL cannot be changed while the site is registered. To change it, remove the registration first.';
$string['field_endpoint_help'] = 'Copy the connection URL shown for this site on the screen of the platform (the service where the backups were ordered). It starts with https://.';
$string['field_fingerprint'] = 'Fingerprint of the public key';
$string['field_publickey'] = 'Public key of this site';
$string['field_publickey_help'] = 'Copy the public key of this site, which starts with secbk1:, from the platform screen. The next step shows its fingerprint, so that you can check that you copied the right key.';
$string['instruction_invalid'] = 'The backup service sent an instruction that could not be verified, so it was not used.';
$string['intro'] = 'This plugin connects this site (Moodle™) to the backup service, which keeps encrypted backups of the site.';
$string['message_deregistered_body'] = '{$a->user} removed the registration of this site with {$a->platform}.

Connection URL: {$a->endpoint}
Site name on the platform: {$a->site}

Backups are no longer sent. To be safe, also remove the site or issue a new registration code on the platform screen.

{$a->url}';
$string['message_deregistered_subject'] = 'The registration with {$a->platform} was removed';
$string['message_enrolled_body'] = '{$a->user} registered this site with {$a->platform}.

Connection URL: {$a->endpoint}
Site name on the platform: {$a->site}

If you did not expect this, check the connection settings of the plugin.

{$a->url}';
$string['message_enrolled_subject'] = 'This site was registered with {$a->platform}';
$string['message_reenrolled_body'] = '{$a->user} registered this site again with {$a->platform}, with a new registration code. The previous key of this site no longer works.

Connection URL: {$a->endpoint}
Site name on the platform: {$a->site}

If you did not expect this, check the connection settings of the plugin.

{$a->url}';
$string['message_reenrolled_subject'] = 'This site was registered again with {$a->platform}';
$string['messageprovider:connection'] = 'Changes to the connection with the backup service';
$string['nfs_slow'] = 'A site file could not be read after several attempts. The shared storage that holds the files may be slow or unstable. The next run continues from where this one stopped.';
$string['notifier_system'] = 'The system';
$string['object_incomplete'] = 'The data that was sent to or read from the storage was not as long as expected, so it was discarded.';
$string['object_rejected'] = 'The storage did not accept the request. The address for the transfer may have expired.';
$string['object_url_invalid'] = 'The backup service gave an address for the storage that this plugin does not accept. Nothing was sent.';
$string['page_connect'] = 'Connection settings';
$string['page_diagnostics'] = 'Connection diagnostics';
$string['page_settings'] = 'Limits of the backup';
$string['page_status'] = 'Status';
$string['pluginname'] = 'Shinonome LMS Backup';
$string['privacy:metadata:core_message'] = 'The plugin tells the site administrators about changes to the connection with the backup service through the messaging system. The message names the administrator who made the change.';
$string['privacy:metadata:service'] = 'The plugin sends encrypted backups of this site to the Shinonome LMS Backup service.';
$string['privacy:metadata:service:sitedata'] = 'The whole site, including the personal data of all users (everything in the backup).';
$string['publickey_base64'] = 'The public key contains characters that are not allowed. Copy it again from the platform screen.';
$string['publickey_invalid'] = 'The public key of this site cannot be used for encryption. Copy it again from the platform screen and register the site again.';
$string['publickey_length'] = 'The public key is not complete. Copy the whole key again from the platform screen.';
$string['publickey_prefix'] = 'The public key must start with "secbk1:". Copy it again from the platform screen.';
$string['receipt_mismatch'] = 'The receipt of the backup service does not match the backup that this site sent. The backup is not counted as stored.';
$string['result_fail'] = 'Problem';
$string['result_info'] = 'Information';
$string['result_pass'] = 'OK';
$string['result_unknown'] = 'Unknown';
$string['result_warn'] = 'Warning';
$string['secbackup:manage'] = 'Manage the connection to Shinonome LMS Backup';
$string['service_status_active'] = 'Active';
$string['service_status_awaiting_enrollment'] = 'Waiting for registration';
$string['service_status_other'] = 'Other ({$a})';
$string['setting_bandwidthmbps'] = 'Upload speed limit (Mbps)';
$string['setting_bandwidthmbps_desc'] = 'The most that the plugin sends per second, in megabits. 0 means no limit.';
$string['setting_error_decimal'] = 'Enter a number of {$a} or more, with at most six decimals.';
$string['setting_error_directory'] = 'Enter an absolute path without ".." and without control characters, or leave it empty.';
$string['setting_error_dirroot'] = 'The folder must not be inside the code folder of Moodle, which the web server may serve.';
$string['setting_error_whole'] = 'Enter a whole number of {$a} or more.';
$string['setting_loadpercore'] = 'Highest server load per CPU core';
$string['setting_loadpercore_desc'] = 'The backup waits while the one-minute load average divided by the number of CPU cores is above this value. The default is 2.';
$string['setting_maxdbms'] = 'Slowest database response (milliseconds)';
$string['setting_maxdbms_desc'] = 'The backup waits while one simple database read takes longer than this. The default is 500.';
$string['setting_minmemorymb'] = 'Least available memory (MB)';
$string['setting_minmemorymb_desc'] = 'The backup waits while the memory that the server can still give is below this value. The default is 256.';
$string['setting_minnfsmbps'] = 'Slowest file storage read speed (MB per second)';
$string['setting_minnfsmbps_desc'] = 'The backup waits while reading a file of the site is slower than this. The default is 5.';
$string['setting_tempbudgetmb'] = 'Budget of the temporary files (MB)';
$string['setting_tempbudgetmb_desc'] = 'The most that a backup keeps in the temporary folder. 0 means the smaller of 10% of the free space and 2,048 MB.';
$string['setting_tempdir'] = 'Temporary folder';
$string['setting_tempdir_desc'] = 'An absolute path. When empty, the folder tool_secbackup in the temporary folder of Moodle is used.';
$string['signature_invalid'] = 'The list of stored chunks that the backup service gave could not be trusted, so the backup was stopped. Register this site again if it was moved to another server.';
$string['signingkey_corrupt'] = 'The stored signing key cannot be read. Remove the registration and register the site again.';
$string['signingkey_missing'] = 'The signing key of this site is missing. Register the site again.';
$string['signingkey_savefailed'] = 'The signing key could not be encrypted, so it was not saved. Check the encryption key file in the data directory and try again.';
$string['status_enrolled_at'] = 'Registered on';
$string['status_last_error'] = 'The last status request failed.';
$string['status_last_error_code'] = 'The last status request failed ({$a}).';
$string['status_last_poll'] = 'Last status request';
$string['status_never'] = 'Not yet';
$string['status_not_registered'] = 'This site is not registered with the backup service yet. Open the connection settings and register it with the registration code issued on the platform (the service where the backups were ordered).';
$string['status_platform'] = 'Platform';
$string['status_response_invalid'] = 'The status answer of the service could not be read.';
$string['status_service_status'] = 'Status at the service';
$string['status_site'] = 'Site name on the platform';
$string['status_siteid'] = 'Site ID';
$string['status_unknown'] = 'Not known yet';
$string['status_unreadable'] = 'Cannot be read.';
$string['tamper_detected'] = 'The content of a site file does not match the hash it is stored under, so the file may have been changed or damaged. The backup was stopped.';
$string['task_poll_failed'] = 'The status request failed. See the status page of the plugin.';
$string['task_poll_notregistered'] = 'The site is not registered, so nothing was asked.';
$string['task_poll_ok'] = 'The status was received.';
$string['task_poll_status'] = 'Ask the backup service for the status of this site';
$string['temp_write_failed'] = 'A file could not be written in the temporary folder of the plugin. Check the free space and the permissions of the folder.';
$string['trademark'] = 'The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with, endorsed by or sponsored by Moodle Pty Ltd or its related affiliates.';
$string['transport_blocked'] = 'Moodle\'s HTTP security settings block the connection to the service. In Site administration > General > Security > HTTP security, check that "cURL blocked hosts list" holds neither the host name of the connection URL nor its IP addresses, and that "cURL allowed ports list" is empty or holds 443.';
$string['transport_connect'] = 'Could not connect to the service, or the connection timed out. Check the network and the proxy settings of this server.';
$string['transport_failed'] = 'The connection to the service failed.';
$string['transport_redirect'] = 'The service answered with a redirect, which this plugin does not follow. Check the connection URL.';
$string['transport_tls'] = 'The certificate of the service could not be verified. If the network decrypts HTTPS traffic (for example with a security appliance or a proxy), ask its administrator to exclude the host name of the connection URL.';
$string['transport_too_large'] = 'The response of the service was larger than expected and was discarded.';
$string['upload_failed'] = 'The data could not be sent to the storage after several tries, so the backup was stopped. The next backup tries again.';
