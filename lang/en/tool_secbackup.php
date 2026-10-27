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

$string['abort_capacity_refused'] = 'The backup is more than the backup service can hold in one generation (the size that the plan allows, or the number of chunks), so the backup was stopped.';
$string['abort_capacity_refused_advice'] = 'Check the size that the plan allows on the platform screen, and change the plan if the site has grown. Removing files that are no longer needed from the site also makes the backup smaller.';
$string['abort_contract_inactive'] = 'The contract for the backup service ended while the backup was running, so the backup was stopped.';
$string['abort_contract_inactive_advice'] = 'Check the contract for the backup service on the platform screen. The backup starts again when the contract is active.';
$string['abort_db_slow'] = 'The database answered more slowly than the limit of the plugin allows, so the backup was stopped. The next scheduled backup starts again.';
$string['abort_db_slow_advice'] = 'Check the load of the database server at the time of the backup. If the database is often this slow, raise the slowest database response in "Limits of the backup".';
$string['abort_disk_low'] = 'There is not enough free space in the temporary folder of the plugin, so the backup was stopped.';
$string['abort_disk_low_advice'] = 'Free up space on the disk that holds the temporary folder, or choose a folder on a disk with more space in "Limits of the backup". If there is space, check that the user that runs the cron and the web server can write to the folder, and that nothing empties it while a backup runs (for example a folder under /tmp at a restart of the server).';
$string['abort_expired'] = 'The backup service did not receive the end of the backup within 24 hours, so it stopped the backup.';
$string['abort_expired_advice'] = 'Check that the cron of Moodle runs every minute, because the backup goes on only while it runs. If the backup paused at the end of its window, the site needs a longer window or a run from cli/backup.php (see the README). The next scheduled backup starts again.';
$string['abort_file'] = 'The file: {$a->path} in the data directory, the content of a file of the site in the component {$a->component}, file area {$a->filearea}.';
$string['abort_file_content'] = 'The file: {$a->path} in the data directory (no file of the site refers to it any more).';
$string['abort_network_check_failed'] = 'The connection to the backup service or to its storage did not pass the checks before sending (the address, the name lookup or the network), so the backup was stopped.';
$string['abort_network_check_failed_advice'] = 'Open the connection diagnostics, fix the checks that failed (the address, the name lookup or the network), and start the backup again.';
$string['abort_nfs_slow'] = 'The files of the site were read more slowly than the limit of the plugin, or a site file could not be read after several attempts. The shared storage that holds the files may be slow or unstable. The next run continues from where this one stopped.';
$string['abort_nfs_slow_advice'] = 'Check the storage that holds the files of the site (the data directory). If it is slow only at times, lower the slowest file storage read speed in "Limits of the backup".';
$string['abort_restarted'] = 'This site lost the record of the backup while it was running, so the backup was stopped and a new one was started.';
$string['abort_restarted_advice'] = 'Nothing needs to be done. If this happens often, check that the database of the site is not restored or reset while a backup runs.';
$string['abort_selinux_denied'] = 'SELinux on this server denied the web server a connection or the temporary files of the plugin, so the backup was stopped.';
$string['abort_selinux_denied_advice'] = 'Open the connection diagnostics, follow the advice for SELinux (for the connection, the temporary folder or a data directory on NFS), and start the backup again.';
$string['abort_server_busy'] = 'The load of the server or its free memory went beyond the limits of the plugin, so the backup was stopped. The next scheduled backup starts again.';
$string['abort_server_busy_advice'] = 'Check what else runs on the server while the backup is made. If the server is often this busy, raise the highest load or lower the least available memory in "Limits of the backup".';
$string['abort_signature_invalid'] = 'The signature or the contents of data from the backup service could not be verified, so the backup was stopped.';
$string['abort_signature_invalid_advice'] = 'If this site was moved to another server or registered again, register it again in the connection settings with a new registration code from the platform screen. If it was not, ask the support of the platform.';
$string['abort_tamper_detected'] = 'The content of a site file does not match the hash it is stored under, so the file may have been changed or damaged. The backup was stopped.';
$string['abort_tamper_detected_advice'] = 'Check the data directory of the site for files that were changed or damaged (a fault of the disk, or a program that changes files). Restore the damaged files from an earlier backup, then start the backup again.';
$string['abort_unknown'] = 'The backup was stopped ({$a}).';
$string['abort_unknown_advice'] = 'Open the connection diagnostics, look for errors of this plugin in the logs of the server, and start the backup again. If it goes on, ask the support of the platform.';
$string['abort_upload_failed'] = 'The data could not be sent to the storage after several tries, so the backup was stopped.';
$string['abort_upload_failed_advice'] = 'Open the connection diagnostics and check that this server can reach the storage of the backup service. If the connection is fine, the next scheduled backup tries again.';
$string['abort_verify_artifact_mismatch'] = 'The backup service found that the manifest or the index in the storage is not the one that this site reported, so the backup is not stored.';
$string['abort_verify_artifact_mismatch_advice'] = 'Start the backup again. If it happens again, check that no proxy or security appliance between this server and the storage changes the data.';
$string['abort_verify_error'] = 'The backup service could not check the backup, so the backup is not stored.';
$string['abort_verify_error_advice'] = 'Start the backup again later. If it happens again, ask the support of the platform.';
$string['abort_verify_index_invalid'] = 'The backup service could not read the index that this site sent, so the backup is not stored.';
$string['abort_verify_index_invalid_advice'] = 'Start the backup again. If it happens again, update the plugin to the latest version and ask the support of the platform.';
$string['abort_verify_missing_chunks'] = 'The backup service did not find in the storage all the data that this site sent, so the backup is not stored.';
$string['abort_verify_missing_chunks_advice'] = 'Start the backup again. If it happens again, open the connection diagnostics and check that nothing between this server and the storage changes or blocks the data.';
$string['abort_verify_size_mismatch'] = 'The backup service found data in the storage whose size is not the one that this site reported, so the backup is not stored.';
$string['abort_verify_size_mismatch_advice'] = 'Start the backup again. If it happens again, check that no proxy or security appliance between this server and the storage changes the data.';
$string['abort_version_too_old'] = 'This version of the plugin is older than the backup service accepts, so the backup was stopped.';
$string['abort_version_too_old_advice'] = 'Update the plugin to the latest version, then start the backup again.';
$string['abort_window_ended'] = 'The backup did not finish within its backup window and was stopped: the next window started too late for the backup to finish within the 24 hours that the backup service allows a backup, or, with several windows a day, the backup did not finish within two windows in a row. The next scheduled backup starts again.';
$string['abort_window_ended_advice'] = 'Check the limits in "Limits of the backup" and the load of the server. If the site is large, its backup needs more time than its windows give it (the next window starts too late to finish within the 24 hours, or two windows are not enough): ask on the platform screen for a longer backup window, or run the backup with cli/backup.php from the cron of the system, which does not stop at the end of the window (see the README).';
$string['action_deregister'] = 'Remove the registration';
$string['action_next'] = 'Next';
$string['action_register'] = 'Register';
$string['api_artifact_signature_invalid'] = 'The backup service could not verify the signature of the manifest or the index that this site sent. If the keys of this site were replaced, register this site again, then run the backup again.';
$string['api_body_too_large'] = 'The request was larger than the service accepts.';
$string['api_capacity_recheck_required'] = 'The backup service asked for the expected size of this backup to be checked again before it accepts more data.';
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
$string['capacity_refused'] = 'The backup is more than the backup service can hold in one generation (the size that the plan allows, or the number of chunks), so the backup was stopped.';
$string['check_apparmor'] = 'AppArmor';
$string['check_autobackup'] = 'Automated backup of Moodle';
$string['check_clock'] = 'Clock';
$string['check_connection'] = 'Connection to the service';
$string['check_database'] = 'Database response';
$string['check_developer'] = 'Developer settings';
$string['check_dns'] = 'Name resolution';
$string['check_extensions'] = 'PHP extensions';
$string['check_load'] = 'Server load';
$string['check_memory'] = 'Available memory';
$string['check_moodledata'] = 'Data directory';
$string['check_proxy'] = 'Proxy';
$string['check_selinux'] = 'SELinux';
$string['check_tempdir'] = 'Temporary folder';
$string['cli_backup_aborted'] = 'The backup was stopped: {$a}';
$string['cli_backup_continued'] = 'The backup that is running goes on.';
$string['cli_backup_done'] = 'The backup is complete: the backup service verified it and its receipt was checked.';
$string['cli_backup_error'] = 'The command stopped with an error ({$a}). The backup was not stopped: the cron of Moodle goes on with it, or run the command again.';
$string['cli_backup_failed'] = 'A step of the backup failed ({$a}). It is tried again in 5 minutes.';
$string['cli_backup_heading'] = 'Backup of Shinonome LMS Backup';
$string['cli_backup_help'] = 'Runs the backup of this site to the backup service until it ends.

A backup that is running goes on. Without a running backup, nothing is done unless --now is given. A step that fails is tried again after 5 minutes; a backup that has no receipt 24 hours after it started is stopped.

Options:
--now        Start a backup at once when none is running, in or out of the backup window.
--quiet      Print only the outcome.
-h, --help   Print this help.

Exit codes: 0 the backup is complete, 1 the backup was stopped or the backup service did not start it, 2 no backup was run here (none is running, or its files are on another server), 3 the command failed and the backup goes on (with the cron of Moodle, or when the command is run again).

Run it as the user that runs cron, for example:
$ sudo -u www-data /usr/bin/php admin/tool/secbackup/cli/backup.php --now
(Moodle 5.1 and later: public/admin/tool/secbackup/cli/backup.php)';
$string['cli_backup_lockwait'] = 'Another process is running a step of the backup. The command goes on after it.';
$string['cli_backup_none'] = 'No backup is running. Give --now to start one.';
$string['cli_backup_notstarted'] = 'The backup was not started: {$a}';
$string['cli_backup_otherserver'] = 'The temporary files of the running backup are on the server {$a}. Run this command on that server.';
$string['cli_backup_refused'] = 'The backup service did not start the backup: {$a}';
$string['cli_backup_stage'] = 'Stage: {$a}';
$string['cli_backup_started'] = 'A backup was started.';
$string['cli_backup_waiting'] = 'Waiting until {$a}.';
$string['cli_diagnose_help'] = 'Checks the connection from this server to the backup service.

Options:
--endpoint=URL  Connection URL to check when the site is not registered.
-h, --help      Print this help.

Run it as the user that runs cron, for example:
$ sudo -u www-data /usr/bin/php admin/tool/secbackup/cli/diagnose.php
(Moodle 5.1 and later: public/admin/tool/secbackup/cli/diagnose.php)';
$string['cli_restore_failed'] = 'The restore stopped with an error of the kind {$a}.';
$string['cli_restore_help'] = 'Extracts a downloaded backup for the restore host, or imports it into the database. The restore host runs it as root and talks to it over the standard input and output, one JSON line at a time.

Options:
--extract    Extract the backup: the restore host sends the sealed keys of the site and the path of the input file.
--import     Import the extracted database into this site.
-h, --help   Print this help.

Give exactly one of --extract and --import. Exit codes: 0 done, 1 failed (an error line {"type":"error","code":...} on the standard output tells why, when the plugin knows it).

Run it on the restore host, for example:
$ sudo php admin/tool/secbackup/cli/restore.php --extract
(Moodle 5.1 and later: public/admin/tool/secbackup/cli/restore.php)';
$string['clock_skew_warning'] = 'The clock of this server differs from the clock of the service by about {$a} minutes. When it differs by more than 5 minutes, the service refuses the requests of this site. Set the time of this server (for example with NTP).';
$string['connect_again_explain'] = 'Register again when the platform issued a new registration code, or when the public key of this site was created again on the platform.';
$string['connect_again_heading'] = 'Register again';
$string['contract_inactive'] = 'The contract for the backup service ended while the backup was running, so the backup was stopped.';
$string['core_manifest_invalid'] = 'The list of the official Moodle files from the backup service, or the version file of this site, could not be read as expected. The backup sends all the Moodle program files instead, which takes longer and uses more storage.';
$string['core_manifest_mismatch'] = 'The list of the official Moodle files from the backup service is for another version of Moodle than the one on this site. The backup sends all the Moodle program files instead, which takes longer and uses more storage.';
$string['crypto_chunk_corrupt'] = 'A backup chunk is damaged, was changed or does not belong to this site, so it could not be decrypted.';
$string['crypto_chunk_sourceshort'] = 'The data for a backup chunk ended before its expected length. It may have changed while it was read.';
$string['crypto_chunk_truncated'] = 'A backup chunk ends before its last part, so it is incomplete.';
$string['db_slow'] = 'The database answered more slowly than the limit of the plugin allows, so the backup was stopped. The next scheduled backup starts again.';
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
$string['diag_autobackup_advice'] = 'The plugin does not start a backup while the automated backup of Moodle works. Choose a time for the automated backup that does not overlap the backup window of this plugin ({$a}).';
$string['diag_autobackup_noround'] = 'not recorded';
$string['diag_autobackup_off'] = 'Off. The automated course backup of Moodle is not used.';
$string['diag_autobackup_on'] = 'On: {$a->days} at {$a->time} ({$a->zone}). Last round: {$a->duration}.';
$string['diag_cli_advice'] = 'What to do: {$a}';
$string['diag_cli_endpoint'] = 'Connection URL: {$a}';
$string['diag_cli_heading'] = 'Connection diagnostics of Shinonome LMS Backup';
$string['diag_cli_noendpoint'] = 'Connection URL: not registered (give one with --endpoint)';
$string['diag_clock_seconds'] = 'The clocks differ by {$a} seconds.';
$string['diag_clock_unknown'] = 'Not known yet. It is measured when the site registers or asks for its status.';
$string['diag_connection_ok'] = 'The service was reached. This check asks without a signature, so HTTP {$a} (authentication required) is the expected answer.';
$string['diag_connection_other'] = 'Something answered, but not the service\'s API (HTTP {$a}). Check the connection URL.';
$string['diag_database'] = 'One simple read took {$a->ms} ms. The backup waits above {$a->limit} ms.';
$string['diag_database_advice'] = 'The database is slower than the limit. Check the load of the database server, or raise the slowest database response in "Limits of the backup".';
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
$string['diag_load'] = 'Load per CPU core: {$a->load}. The backup waits above {$a->limit}.';
$string['diag_load_advice'] = 'The server is busier than the limit. A backup waits until the load falls. If the server is often this busy, raise the highest load in "Limits of the backup".';
$string['diag_memory'] = 'Available memory: {$a->memory}. The backup waits below {$a->limit}.';
$string['diag_memory_advice'] = 'The server has less memory left than the limit. A backup waits until memory is free. If it is often this low, lower the least available memory in "Limits of the backup".';
$string['diag_moodledata'] = 'File system: {$a->type} (mounted at {$a->mount}, from {$a->source})';
$string['diag_moodledata_network'] = 'The data directory is on a network file system. Backups read every file in it, so they take longer and load that file system.';
$string['diag_moodledata_unknown'] = 'Cannot be read.';
$string['diag_no_endpoint'] = 'Register the site, or enter a connection URL, to check this.';
$string['diag_proxy_direct'] = 'Direct connection, without a proxy.';
$string['diag_proxy_used'] = 'Through the proxy {$a}.';
$string['diag_run'] = 'Check the connection';
$string['diag_selinux_advice'] = 'If SELinux denies the web server, the server administrator can see it with: ausearch -m avc -ts recent. For the connection to the backup service and its storage, to let the web server connect only to web ports such as 443: setsebool -P httpd_can_network_relay on. To let it make any network connection: setsebool -P httpd_can_network_connect on. For a temporary folder of the plugin on another disk ({$a}): semanage fcontext -a -t httpd_sys_rw_content_t "{$a}(/.*)?" and then restorecon -Rv {$a}. For a data directory of Moodle on NFS: setsebool -P httpd_use_nfs on';
$string['diag_selinux_enforcing'] = 'Enforcing. Process type: {$a}.';
$string['diag_selinux_permissive'] = 'Permissive. Process type: {$a}.';
$string['diag_selinux_unknown'] = 'Not used, or cannot be read.';
$string['diag_skipped'] = 'Skipped because of the developer settings.';
$string['diag_tempdir'] = 'Free space: {$a->free}. Budget for the temporary files of a backup: {$a->budget}.';
$string['diag_tempdir_advice'] = 'The free space is below what a backup needs (the larger of 10% of the disk and 5 GB). Free up space, or choose a folder on a disk with more space in "Limits of the backup".';
$string['diag_unreadable'] = 'Cannot be read.';
$string['disk_low'] = 'There is not enough free space in the temporary folder of the plugin, so the backup was stopped.';
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
$string['message_backupfailure_body'] = 'The last {$a->count} backups of this site to the backup service were stopped before they were stored.

Last reason: {$a->reason}

What to do: {$a->advice}

The status page of the plugin shows more: {$a->url}';
$string['message_backupfailure_subject'] = 'The backups of this site were stopped {$a->count} times in a row';
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
$string['messageprovider:backupfailure'] = 'Backups that were stopped several times in a row';
$string['messageprovider:connection'] = 'Changes to the connection with the backup service';
$string['modal_core'] = 'The core source code of the LMS being backed up (Moodle™) may have been modified. Backups can still be made, but restoring to a temporary site may fail. After each backup, always use the Preview feature to confirm that the backup can be restored.';
$string['modal_core_title'] = 'The core code may have been modified';
$string['modal_external'] = 'A plugin that keeps moodledata files in an external cloud service such as AWS S3 was detected. Running a backup may incur charges on that cloud service. (We cannot provide support for charges incurred outside {$a}.)';
$string['modal_external_title'] = 'External cloud storage detected';
$string['network_check_failed'] = 'The connection to the backup service or to its storage did not pass the checks before sending (the address, the name lookup or the network), so the backup was stopped. Open the connection diagnostics of the plugin.';
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
$string['privacy:metadata:core_message'] = 'The plugin tells the site administrators about changes to the connection with the backup service through the messaging system. The message names the administrator who made the change. The plugin also tells them when backups were stopped several times in a row. That message holds the number of stops and the reason, and no personal data.';
$string['privacy:metadata:service'] = 'The plugin sends encrypted backups of this site to the Shinonome LMS Backup service.';
$string['privacy:metadata:service:sitedata'] = 'The whole site, including the personal data of all users (everything in the backup).';
$string['publickey_base64'] = 'The public key contains characters that are not allowed. Copy it again from the platform screen.';
$string['publickey_invalid'] = 'The public key of this site cannot be used for encryption. Copy it again from the platform screen and register the site again.';
$string['publickey_length'] = 'The public key is not complete. Copy the whole key again from the platform screen.';
$string['publickey_prefix'] = 'The public key must start with "secbk1:". Copy it again from the platform screen.';
$string['receipt_mismatch'] = 'The receipt of the backup service does not match the backup that this site sent. The backup is not counted as stored.';
$string['refused_contract_inactive'] = 'The contract for the backup service on {$a} is not active, so the backup service did not start the backup.';
$string['refused_contract_inactive_advice'] = 'Check the contract on {$a}. The next scheduled backup starts once the contract is active.';
$string['refused_run_limit_reached'] = 'The number of backups that the plan allows in 24 hours has been reached, so the backup service did not start another one.';
$string['refused_run_limit_reached_advice'] = 'Nothing needs to be done. The next scheduled backup tries again, and it starts once the plan allows another one.';
$string['refused_run_limit_reached_at'] = 'The number of backups that the plan allows in 24 hours has been reached, so the backup service did not start another one. A backup can start again after {$a}.';
$string['refused_unknown'] = 'The backup service did not start the backup ({$a}).';
$string['refused_unknown_advice'] = 'Open the connection diagnostics and try again later. If it goes on, ask the support of the platform.';
$string['restarted'] = 'This site lost the record of the backup while it was running, so the backup was stopped and a new one was started.';
$string['restore_admin_fullname'] = 'Temporary site administrator';
$string['restore_chunk_corrupt'] = 'A piece of the backup could not be opened with the keys given. The backup may be damaged, or a key of the site is missing.';
$string['restore_chunk_missing'] = 'A piece of the backup that the list of files names was not found. Restore the backup again from the start.';
$string['restore_content_mismatch'] = 'The content of a file or a table in the backup does not match its record, so it was not restored. The backup may be damaged.';
$string['restore_core_official_unsupported'] = 'This backup holds Moodle core code that comes from the official download, which this restore cannot fetch yet. Restore it with the Moodle core code of the same version placed by hand.';
$string['restore_import_count_mismatch'] = 'The number of rows restored into a table does not match the backup. Restore the backup again from the start.';
$string['restore_import_failed'] = 'A table could not be written while the database was being restored. Check the database of the restore host and restore again.';
$string['restore_import_schema_mismatch'] = 'A table or a column of the backup does not match the Moodle installed on the restore host: {$a}. Install the same version of Moodle and the same plugins as the backup.';
$string['restore_input_invalid'] = 'The request to restore the backup is incomplete or has a value that is not allowed.';
$string['restore_keys_invalid'] = 'The keys given for the restore cannot be used. Between one and ten sealed keys are needed.';
$string['restore_manifest_invalid'] = 'The list of files of the backup cannot be trusted: its size, its checksum, its signature or its entries do not match.';
$string['restore_path_unsafe'] = 'The backup holds a file name that would be written outside the folder of the restore, so the restore was stopped.';
$string['restore_write_failed'] = 'A file could not be written on the restore host. Check the free space and the permissions of the folders.';
$string['result_fail'] = 'Problem';
$string['result_info'] = 'Information';
$string['result_pass'] = 'OK';
$string['result_unknown'] = 'Unknown';
$string['result_warn'] = 'Warning';
$string['schedule_window'] = '{$a->from}–{$a->to}, {$a->timezone}';
$string['schedule_window_none'] = 'not received yet';
$string['schedule_window_runs'] = '{$a->from}–{$a->to}, {$a->timezone}, {$a->runs} times a day';
$string['secbackup:manage'] = 'Manage the connection to Shinonome LMS Backup';
$string['selinux_denial_network'] = 'SELinux denied the web server a connection to port 443, which the backup service and its storage use. The server administrator can see the denial with: ausearch -m avc -ts recent. To let the web server connect to web ports such as 443 only: setsebool -P httpd_can_network_relay on (for any network connection: setsebool -P httpd_can_network_connect on). Then start the backup again.';
$string['selinux_denial_tempfolder'] = 'SELinux denied the web server writing to the temporary folder {$a}. The server administrator can see the denial with: ausearch -m avc -ts recent, and let the web server write there with: semanage fcontext -a -t httpd_sys_rw_content_t "{$a}(/.*)?" and then restorecon -Rv {$a}. If the data directory of Moodle, which holds the temporary folder by default, is on NFS: setsebool -P httpd_use_nfs on. Then start the backup again.';
$string['selinux_denied'] = 'SELinux on this server probably blocked the connection or the temporary files of the plugin, so the backup was stopped. Open the connection diagnostics of the plugin for the settings to change.';
$string['server_busy'] = 'The load of the server or its free memory went beyond the limits of the plugin, so the backup was stopped. The next scheduled backup starts again.';
$string['service_status_active'] = 'Active';
$string['service_status_awaiting_enrollment'] = 'Waiting for registration';
$string['service_status_other'] = 'Other ({$a})';
$string['setting_bandwidthmbps'] = 'Upload speed limit (Mbps)';
$string['setting_bandwidthmbps_desc'] = 'The most that the plugin sends per second, in megabits. 0 means no limit.';
$string['setting_error_datafolder'] = 'This folder of the data directory (moodledata) already holds files. The backup leaves the temporary folder out, and these files with it. Choose a new or empty folder.';
$string['setting_error_dataroot'] = 'The data directory (moodledata) itself cannot be the temporary folder: the backup would hold its own temporary files. Choose a new folder inside it or a folder elsewhere.';
$string['setting_error_decimal'] = 'Enter a number of {$a} or more, with at most six decimals.';
$string['setting_error_directory'] = 'Enter an absolute path without ".." and without control characters, or leave it empty.';
$string['setting_error_dirroot'] = 'The folder must not be inside the code folder of Moodle, which the web server may serve.';
$string['setting_error_running'] = 'A backup is running. Change the temporary folder after it has ended: the running backup keeps its temporary files in the folder that it started with.';
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
$string['setting_tempdir_desc'] = 'An absolute path. When empty, the folder tool_secbackup in the temporary folder of Moodle is used. The backup leaves this folder out, so a folder inside the data directory (moodledata) must be new or empty. With several web servers, a folder other than the default holds the temporary files of a backup on the server that started it, and only that server goes on with that backup. The folder cannot be changed while a backup runs.';
$string['signature_invalid'] = 'The signature or the contents of data from the backup service could not be verified, so the backup was stopped. If this site was moved to another server or registered again, register it again here.';
$string['signingkey_corrupt'] = 'The stored signing key cannot be read. Remove the registration and register the site again.';
$string['signingkey_missing'] = 'The signing key of this site is missing. Register the site again.';
$string['signingkey_savefailed'] = 'The signing key could not be encrypted, so it was not saved. Check the encryption key file in the data directory and try again.';
$string['stage_capacity'] = 'Checking the size of the backup';
$string['stage_code'] = 'Sending the program and the plugins';
$string['stage_complete'] = 'Finishing at the backup service';
$string['stage_dataroot'] = 'Sending the other files of the data directory';
$string['stage_db'] = 'Sending the database';
$string['stage_done'] = 'Done';
$string['stage_files'] = 'Sending the files of the site';
$string['stage_index'] = 'Writing the index of the backup';
$string['stage_manifest'] = 'Writing the list of the backup';
$string['stage_prepare'] = 'Preparing';
$string['stage_present'] = 'Checking what the backup service already has';
$string['stage_receipt'] = 'Waiting for the receipt of the backup service';
$string['stage_start'] = 'Starting at the backup service';
$string['start_autobackup'] = 'The automated course backup of Moodle is working, so the backup was not started. Try again when it has finished.';
$string['start_busy'] = 'The server is too busy for the limits of the plugin (load, memory, disk or database), so the backup was not started. Try again later, or change the limits in "Limits of the backup".';
$string['start_cause_db_slow'] = 'The database answered more slowly than the limit of the plugin. Check the load of the database server at that time, or raise the slowest database response in "Limits of the backup".';
$string['start_cause_disk_low'] = 'The disk that holds the temporary folder of the plugin has less free space than 10% of the disk or 5 GB, whichever is larger. Free up space, or choose a folder on another disk in "Limits of the backup".';
$string['start_cause_load'] = 'The load of the server per CPU core was above the limit of the plugin. Check what else runs at that time, or raise the highest load in "Limits of the backup".';
$string['start_cause_memory'] = 'The available memory of the server was below the limit of the plugin. Check what else runs at that time, or lower the least available memory in "Limits of the backup".';
$string['start_cause_nfs_slow'] = 'The file storage of the site was read more slowly than the limit of the plugin. Check the storage that holds the files of the site, or lower the slowest file storage read speed in "Limits of the backup".';
$string['start_cause_server_busy'] = 'The load or the available memory of the server was beyond the limits of the plugin.';
$string['start_clock'] = 'The clock of this server differs from the clock of the backup service by 5 minutes or more, so the backup was not started. Set the time of this server (for example with NTP), then try again.';
$string['start_memorylimit'] = 'The memory limit of PHP and the extra memory limit of Moodle are both below 256 MB, so the backup was not started. Raise one of them to 256 MB or more, then try again.';
$string['start_notreceived'] = 'The backup service has not sent the settings of the backup of this site yet, so the backup was not started. Wait for the next status request, then try again.';
$string['start_notregistered'] = 'This site is not registered with the backup service yet, so the backup was not started.';
$string['start_reason_autobackup'] = 'The automated course backups of Moodle are still running. The backup starts when they are done.';
$string['start_reason_busy'] = 'The server is too busy (load, memory, free disk space, database or file storage) for a backup now.';
$string['start_reason_clock'] = 'The clock of this server differs from the clock of the backup service by 5 minutes or more. Correct the time of the server.';
$string['start_reason_memorylimit'] = 'PHP may use less than 256 MB of memory. Raise memory_limit or the extra memory limit of Moodle.';
$string['start_reason_notreceived'] = 'The settings of the backup service have not been received yet. Wait for the next status request.';
$string['start_reason_notregistered'] = 'This site is not registered with the backup service.';
$string['start_reason_outsidewindow'] = 'It is not the time of a scheduled backup.';
$string['start_reason_running'] = 'A backup is running already.';
$string['start_reason_started'] = 'The backup was started.';
$string['start_running'] = 'A backup is already in progress.';
$string['start_started'] = 'The backup was queued. It starts the next time the cron of Moodle runs.';
$string['status_aborted'] = 'The latest backup, started on {$a}, was stopped.';
$string['status_advice'] = 'What to do: {$a}';
$string['status_backup_now'] = 'Back up now';
$string['status_backup_running'] = 'A backup is in progress, so another one cannot be started now.';
$string['status_backups_heading'] = 'Backups';
$string['status_enrolled_at'] = 'Registered on';
$string['status_failed_value'] = 'Stage: {$a->stage}. Started on {$a->started}. Sent so far: {$a->size} ({$a->chunks} chunks). The last step failed ({$a->error}); it is tried again on {$a->next}. A backup that fails until 24 hours after its start is stopped.';
$string['status_last_completed'] = 'Last completed backup';
$string['status_last_duration'] = 'Time it took';
$string['status_last_error'] = 'The last status request failed.';
$string['status_last_error_code'] = 'The last status request failed ({$a}).';
$string['status_last_poll'] = 'Last status request';
$string['status_last_schedule'] = 'Last scheduled start';
$string['status_last_schedule_value'] = '{$a->time}: {$a->text}';
$string['status_last_size'] = 'Data sent in that backup';
$string['status_last_stored'] = 'Size of the backup';
$string['status_never'] = 'Not yet';
$string['status_next_backup'] = 'Next scheduled backup';
$string['status_next_backup_none'] = 'The settings of the backup service have not been received yet.';
$string['status_next_backup_value'] = '{$a->time} (window {$a->window})';
$string['status_none_completed'] = 'No backup has completed yet.';
$string['status_not_registered'] = 'This site is not registered with the backup service yet. Open the connection settings and register it with the registration code issued on the platform (the service where the backups were ordered).';
$string['status_notbackedup'] = 'Files not in the backup';
$string['status_notbackedup_value'] = '{$a->missing} missing from the file store, {$a->unreadable} that could not be read, {$a->links} symbolic links that are not followed';
$string['status_paused_value'] = 'Stage: {$a->stage}. Started on {$a->started}. Sent so far: {$a->size} ({$a->chunks} chunks). Waiting; the next try is on {$a->next}.';
$string['status_platform'] = 'Platform';
$string['status_refused'] = 'The backup service did not start the latest backup, requested on {$a}.';
$string['status_response_invalid'] = 'The status answer of the service could not be read.';
$string['status_running'] = 'Backup in progress';
$string['status_running_value'] = 'Stage: {$a->stage}. Started on {$a->started}. Sent so far: {$a->size} ({$a->chunks} chunks).';
$string['status_secret_unreadable'] = 'The last backup could not read {$a} files or folders of the secret folder of the site, which holds the keys that Moodle encrypts some settings with: a restore of it cannot read those settings. Run the backup as the user that owns the folder (the user of the web server, for example with sudo -u), and start the backup again.';
$string['status_service_status'] = 'Status at the service';
$string['status_site'] = 'Site name on the platform';
$string['status_siteid'] = 'Site ID';
$string['status_size_value'] = '{$a->size} ({$a->chunks} chunks)';
$string['status_unknown'] = 'Not known yet';
$string['status_unreadable'] = 'Cannot be read.';
$string['tamper_detected'] = 'The content of a site file does not match the hash it is stored under, so the file may have been changed or damaged. The backup was stopped.';
$string['task_poll_failed'] = 'The status request failed. See the status page of the plugin.';
$string['task_poll_notregistered'] = 'The site is not registered, so nothing was asked.';
$string['task_poll_ok'] = 'The status was received.';
$string['task_poll_start'] = 'Scheduled backup: {$a}';
$string['task_poll_status'] = 'Ask the backup service for the status of this site';
$string['task_run_backup'] = 'Run the backup of this site';
$string['task_run_backup_aborted'] = 'The backup was stopped. See the status page of the plugin.';
$string['task_run_backup_continue'] = 'The backup goes on in the next step.';
$string['task_run_backup_done'] = 'The backup is complete.';
$string['task_run_backup_leased'] = 'The command line (cli/backup.php) drives the backup: the task leaves it to the command until the command ends.';
$string['task_run_backup_locked'] = 'Another process is running a step of the backup.';
$string['task_run_backup_none'] = 'No backup is running.';
$string['task_run_backup_paused'] = 'The backup waits for its next backup window, or for the server that holds its temporary files.';
$string['temp_write_failed'] = 'A file could not be written in the temporary folder of the plugin. Check the free space and the permissions of the folder.';
$string['trademark'] = 'The word Moodle and associated Moodle logos are trademarks or registered trademarks of Moodle Pty Ltd or its related affiliates. Shinonome LMS Backup is not affiliated with, endorsed by or sponsored by Moodle Pty Ltd or its related affiliates.';
$string['transport_blocked'] = 'Moodle\'s HTTP security settings block the connection to the service. In Site administration > General > Security > HTTP security, check that "cURL blocked hosts list" holds neither the host name of the connection URL nor its IP addresses, and that "cURL allowed ports list" is empty or holds 443.';
$string['transport_connect'] = 'Could not connect to the service, or the connection timed out. Check the network and the proxy settings of this server.';
$string['transport_failed'] = 'The connection to the service failed.';
$string['transport_redirect'] = 'The service answered with a redirect, which this plugin does not follow. Check the connection URL.';
$string['transport_tls'] = 'The certificate of the service could not be verified. If the network decrypts HTTPS traffic (for example with a security appliance or a proxy), ask its administrator to exclude the host name of the connection URL.';
$string['transport_too_large'] = 'The response of the service was larger than expected and was discarded.';
$string['upload_failed'] = 'The data could not be sent to the storage after several tries, so the backup was stopped. The next scheduled backup starts again.';
$string['version_too_old'] = 'This version of the plugin is older than the backup service accepts, so the backup was stopped. Update the plugin.';
$string['window_ended'] = 'The backup did not finish within two backup windows in a row, so it was stopped. The next scheduled backup starts again.';
