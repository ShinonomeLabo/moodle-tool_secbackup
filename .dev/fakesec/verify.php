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
 * Development only: opens the last completed backup that the fake service holds and checks its files against this site.
 *
 * The run must have been made against the fake service after .dev/fakesec/prepare.php with --publickey, from a key pair whose
 * secret key is given here. The list of the generation (the manifest) is read from the storage of the fake service and
 * decrypted; every file of it is decrypted from its chunks, put together from its pieces and hashed. The files of this site are
 * the contents that the files table names with a size of one byte or more (the same set as the backup takes).
 *
 * Usage: php <plugin>/.dev/fakesec/verify.php --secretkey=<file> [--state=<file>]
 *   --secretkey  a file with the X25519 secret key of the site in base64 (the pair of the key that was registered)
 *   --state      the file of the state of the fake service (the default is the one that router.php writes for this user)
 * Prints counts and the result, never a key; ends with 0 only when everything matches.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\enrollment\state;

[$options, $unrecognised] = cli_get_params(
    ['secretkey' => '', 'state' => sys_get_temp_dir() . '/tool_secbackup_fakesec.json', 'help' => false],
    ['h' => 'help']
);
if ($unrecognised || $options['help'] || $options['secretkey'] === '') {
    cli_writeln('Usage: php .dev/fakesec/verify.php --secretkey=<file> [--state=<file>]');
    exit($options['help'] ? 0 : 1);
}
raise_memory_limit(MEMORY_UNLIMITED);

$fake = json_decode((string) file_get_contents($options['state']), true);
if (!is_array($fake)) {
    cli_error('The state of the fake service cannot be read.');
}
$runid = null;
foreach ($fake['runs'] as $id => $run) {
    if ($run['status'] === 'completed' && isset($run['receipt'])) {
        $runid = (string) $id;
    }
}
if ($runid === null) {
    cli_error('The fake service holds no completed run.');
}
cli_writeln("run $runid: completed at the fake service, with a signed receipt");

$site = state::load();
$siteid = $site->site_id();
$publickey = public_key_format::parse($site->encryption_key());
$secretkey = base64_decode(trim((string) file_get_contents($options['secretkey'])), true);
if (!is_string($secretkey) || strlen($secretkey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES
        || sodium_crypto_box_publickey_from_secretkey($secretkey) !== $publickey) {
    cli_error('The secret key is not the pair of the encryption key that the site registered.');
}
$folder = make_request_directory();

/**
 * Opens a chunk of the storage of the fake service; each chunk is written to a file once.
 *
 * @param array $fake The state of the fake service.
 * @param string $key Key of the object.
 * @param string $name Name of the chunk.
 * @param array $context [folder, secret key, public key, site ID].
 * @return chunk_reader
 */
function secbk_dev_open(array $fake, string $key, string $name, array $context): chunk_reader {
    static $readers = [];
    if (!isset($readers[$key])) {
        if (!isset($fake['objects'][$key])) {
            cli_error("The storage has no object for the chunk $name.");
        }
        $path = $context[0] . '/' . sha1($key);
        file_put_contents($path, base64_decode($fake['objects'][$key], true));
        $readers[$key] = new chunk_reader($path, $context[1], $context[2], $context[3], $name);
    }
    return $readers[$key];
}

$context = [$folder, $secretkey, $publickey, $siteid];
$prefix = 'sites/' . $siteid . '/';
$manifestname = chunk_name::manifest($runid);
$reader = secbk_dev_open($fake, $prefix . 'manifests/' . $runid, $manifestname, $context);
$lines = explode("\n", rtrim((string) gzdecode($reader->read_item(chunk_writer::HEADER)), "\n"));
$header = json_decode((string) array_shift($lines), true);
$end = json_decode((string) array_pop($lines), true);
$entries = array_map(static fn(string $line): array => json_decode($line, true), $lines);
if (($header['run_id'] ?? null) !== $runid || ($end['end'] ?? false) !== true || ($end['entries'] ?? null) !== count($entries)) {
    cli_error('The list of the generation is not the one of the run.');
}
$kinds = array_count_values(array_column($entries, 't'));
ksort($kinds);
cli_writeln('list of the generation: ' . count($entries) . ' entries (' . implode(', ', array_map(
    static fn(string $kind, int $n): string => "$kind $n",
    array_keys($kinds),
    $kinds
)) . '), missing files ' . count($header['missing_files']) . ', unreadable ' . count($header['unreadable'])
    . ', official core list ' . ($header['core_diff']['official'] ? 'used' : 'not used'));

$sitefiles = $DB->get_records_sql_menu(
    'SELECT contenthash, MAX(filesize) AS size FROM {files} WHERE filesize > 0 GROUP BY contenthash'
);

// The pieces of every file, in the order of their numbers.
$pieces = [];
foreach ($entries as $entry) {
    if ($entry['t'] === 'file') {
        $pieces[$entry['contenthash']][$entry['piece']] = $entry;
    }
}
$checked = 0;
$bad = [];
foreach ($pieces as $hash => $parts) {
    ksort($parts);
    $sha1 = hash_init('sha1');
    $length = 0;
    $first = reset($parts);
    $complete = count($parts) === $first['pieces'] && array_keys($parts) === range(0, $first['pieces'] - 1);
    foreach ($parts as $part) {
        $chunk = secbk_dev_open($fake, $prefix . 'objects/' . $part['chunk'], $part['chunk'], $context);
        $content = $chunk->read_item($part['offset']);
        hash_update($sha1, $content);
        $length += strlen($content);
    }
    $size = isset($sitefiles[$hash]) ? (int) $sitefiles[$hash] : $first['size'];
    if (!$complete || hash_final($sha1) !== $hash || $length !== $first['size'] || $length !== $size) {
        $bad[] = $hash;
    } else {
        $checked++;
    }
}

$inbackup = array_keys($pieces);
$missing = $header['missing_files'];
$notbacked = array_diff(array_keys($sitefiles), $inbackup, $missing);
$extra = array_diff($inbackup, array_keys($sitefiles));
cli_writeln('files of the site (contents of 1 byte or more): ' . count($sitefiles));
cli_writeln('files in the backup: ' . count($inbackup) . ', decrypted with SHA-1 = content hash and the size of the site: '
    . $checked . ', not matching: ' . count($bad));
cli_writeln('files listed as missing: ' . count($missing) . ', files of the site in neither: ' . count($notbacked)
    . ', files in the backup that the site does not have: ' . count($extra));
$ok = $bad === [] && $notbacked === [] && $extra === [] && $missing === [] && $checked === count($sitefiles);
cli_writeln('result: ' . ($ok ? 'ok' : 'mismatch'));
exit($ok ? 0 : 1);
