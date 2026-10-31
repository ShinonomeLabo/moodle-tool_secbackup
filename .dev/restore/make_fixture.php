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
 * Development only: copies the last completed backup that the fake service holds into a folder, as the material of a restore.
 *
 * Run it on the backed-up site, after a whole run against the fake service (.dev/fakesec/prepare.php --publickey=...
 * --no-core-manifest, then cli/backup.php --now). The folder gets:
 * - chunks/<name>: every object that the index of the run names, as the storage holds it;
 * - manifest and index: the list of the generation and the index of the run, as the storage holds them;
 * - input.json: the input of the extraction (restore.php --extract) in the form of the agreement, with every path relative to
 *   the folder: the restore host gives absolute paths, which .dev/restore/drive_extract.php makes from these;
 * - signing-public-key: the public key that the plugin signs the list with, in standard base64 (also in input.json);
 * - site-secret-key: the X25519 secret key of the site in standard base64, readable by its owner only. It is the secret key
 *   of a test site, from the key pair that prepare.php registered, and never the key of a real site;
 * - fixture.json: the plan that the backup service would give the restore host for this run (the values of the run, of its
 *   list and of its index), what the extraction must report in its summary, whether the run took the logs, and the names of
 *   what the header says that the restored site has differently (for .dev/restore/compare.php).
 * The list is decrypted to check the run and to count what it holds. The run must have sent the whole Moodle program: a run
 * that compared it with a list of official files cannot be restored yet.
 *
 * Usage: php <plugin>/.dev/restore/make_fixture.php --out=<dir> --secretkey=<file> [--state=<file>]
 *   --out        a folder that does not exist yet, an absolute path
 *   --secretkey  a file with the X25519 secret key of the site in base64 (the pair of the key that prepare.php registered)
 *   --state      the file of the state of the fake service (the default is the one that router.php writes for this user)
 * Prints counts, never a key.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../../tests/classes/fake_service.php');

use tool_secbackup\local\backup\settings;
use tool_secbackup\local\crypto\chunk_name;
use tool_secbackup\local\crypto\chunk_reader;
use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\tests\fake_service;

[$options, $unrecognised] = cli_get_params(
    ['out' => '', 'secretkey' => '', 'state' => sys_get_temp_dir() . '/tool_secbackup_fakesec.json', 'help' => false],
    ['h' => 'help']
);
$usage = 'Usage: php .dev/restore/make_fixture.php --out=<dir> --secretkey=<file> [--state=<file>]';
if ($unrecognised || $options['help'] || $options['out'] === '' || $options['secretkey'] === '') {
    cli_writeln($usage);
    exit($options['help'] ? 0 : 1);
}
$out = rtrim($options['out'], '/');
if (!str_starts_with($out, '/') || file_exists($out)) {
    cli_error('--out must be an absolute path that does not exist yet.');
}
raise_memory_limit(MEMORY_HUGE);
// The folder holds the secret key of the site: only its owner reads any of it.
umask(0077);
// The components whose code a restore does not write: the restore host installs this plugin itself.
$skip = ['tool_secbackup'];

/**
 * Copies an object of the storage of the fake service to a file, and checks its size and, when one is given, its SHA-256.
 *
 * @param array $fake The state of the fake service.
 * @param string $key Key of the object.
 * @param string $to The file to write.
 * @param int $size The size that the object must have.
 * @param string|null $sha256 The SHA-256 in standard base64 that it must have, or null.
 */
function secbk_dev_copy_object(array $fake, string $key, string $to, int $size, ?string $sha256 = null): void {
    $file = is_string($fake['objectdir'] ?? null) ? fake_service::object_file($fake['objectdir'], $key) : null;
    if ($file !== null) {
        // A file of the folder of objects is copied without reading it into memory.
        $copied = is_file($file) && copy($file, $to);
    } else {
        $body = fake_service::stored_object($fake, $key);
        $copied = $body !== null && file_put_contents($to, $body) === strlen($body);
    }
    if (!$copied) {
        cli_error("The storage of the fake service has no object $key, or it could not be copied.");
    }
    clearstatcache(true, $to);
    if (filesize($to) !== $size || ($sha256 !== null && base64_encode(hash_file('sha256', $to, true)) !== $sha256)) {
        cli_error("The object $key is not the one that the run reported.");
    }
}

/**
 * Writes a file of the folder in JSON, pretty-printed.
 *
 * @param string $path The file.
 * @param array $data The data.
 */
function secbk_dev_write_json(string $path, array $data): void {
    $text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $text . "\n") === false) {
        cli_error('A file of the folder could not be written.');
    }
}

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
$run = $fake['runs'][$runid];

$site = state::load();
$siteid = $site->site_id();
$publickey = public_key_format::parse($site->encryption_key());
$secretkey = base64_decode(trim((string) file_get_contents($options['secretkey'])), true);
if (
    $siteid !== fake_service::SITE_ID || !is_string($secretkey) || strlen($secretkey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES
    || sodium_crypto_box_publickey_from_secretkey($secretkey) !== $publickey
) {
    cli_error('The site is not registered with the fake service, or the secret key is not the pair of its encryption key.');
}
if (!is_string($fake['signingkey'] ?? null)) {
    cli_error('The fake service has no signing key of the site.');
}

if (!mkdir($out . '/chunks', 0700, true)) {
    cli_error('The folder could not be made.');
}
$prefix = 'sites/' . $siteid . '/';
$manifest = $run['manifest'];
secbk_dev_copy_object($fake, $prefix . 'manifests/' . $runid, $out . '/manifest', $manifest['size'], $manifest['sha256']);
secbk_dev_copy_object($fake, $prefix . 'indexes/' . $runid, $out . '/index', $run['index']['size'], $run['index']['sha256']);

// The index: its header, then "<name> <size>" for every object of the generation.
$lines = explode("\n", rtrim((string) gzdecode((string) file_get_contents($out . '/index')), "\n"));
$indexheader = json_decode((string) array_shift($lines), true);
if (($indexheader['run_id'] ?? null) !== $runid || count($lines) !== $run['index']['count']) {
    cli_error('The index is not the one of the run.');
}
$chunks = [];
$bytes = 0;
foreach ($lines as $line) {
    if (preg_match('/^([0-9a-f]{64}) (\d+)\z/', $line, $m) !== 1) {
        cli_error('The index has a line that is not a name and a size.');
    }
    secbk_dev_copy_object($fake, $prefix . 'objects/' . $m[1], $out . '/chunks/' . $m[1], (int) $m[2]);
    $chunks[$m[1]] = true;
    $bytes += (int) $m[2];
}
cli_writeln("run $runid: list " . $run['manifest']['size'] . ' bytes, index ' . $run['index']['size'] . ' bytes, '
    . count($chunks) . " chunks of $bytes bytes copied");

// The list of the generation, decrypted to check it and to count what a restore of it writes.
$reader = new chunk_reader($out . '/manifest', $secretkey, $publickey, $siteid, chunk_name::manifest($runid));
sodium_memzero($secretkey);
$lines = explode("\n", rtrim((string) gzdecode($reader->read_item(chunk_writer::HEADER)), "\n"));
unset($reader);
$header = json_decode((string) array_shift($lines), true);
$end = json_decode((string) array_pop($lines), true);
if (($header['run_id'] ?? null) !== $runid || ($end['end'] ?? false) !== true || ($end['entries'] ?? null) !== count($lines)) {
    cli_error('The list of the generation is not the one of the run.');
}
if ($header['core_diff']['official']) {
    cli_error('The run compared the Moodle program with a list of official files, which a restore cannot use yet: prepare the'
        . ' site again with .dev/fakesec/prepare.php --no-core-manifest and make a new backup.');
}
$rows = 0;
$contents = [];
$plugins = [];
$kinds = [];
foreach ($lines as $line) {
    $entry = json_decode($line, true);
    $kinds[$entry['t']] = ($kinds[$entry['t']] ?? 0) + 1;
    if (!isset($chunks[$entry['chunk']])) {
        cli_error('The list names a chunk that the index does not have.');
    }
    if ($entry['t'] === 'db') {
        $rows += $entry['rows'];
    } else if ($entry['t'] === 'file') {
        $contents[$entry['contenthash']] = true;
    } else if ($entry['t'] === 'code' && $entry['root'] !== 'core' && !in_array($entry['root'], $skip, true)) {
        $plugins[$entry['root']] = true;
    }
}
ksort($kinds);
$plugins = array_keys($plugins);
sort($plugins, SORT_STRING);
$additional = [];
foreach ($header['plugins'] as $plugin) {
    if (!$plugin['standard']) {
        $additional[] = $plugin['component'];
    }
}
$forcedplugins = [];
foreach ($header['config']['forced']['plugins'] as $component => $settings) {
    $forcedplugins[$component] = array_keys($settings);
}

$input = [
    'v' => 1,
    'site_id' => $siteid,
    'run_id' => $runid,
    'manifest' => [
        'path' => 'manifest',
        'sha256' => $run['manifest']['sha256'],
        'size' => $run['manifest']['size'],
        'signature' => $run['manifest']['signature'],
        'signing_public_key' => $fake['signingkey'],
    ],
    'chunks_dir' => 'chunks',
    'target_root' => 'restore/root',
    'target_dataroot' => 'restore/dataroot',
    'staging_dir' => 'restore/staging',
    'skip_components' => $skip,
];
secbk_dev_write_json($out . '/input.json', $input);
file_put_contents($out . '/signing-public-key', $fake['signingkey'] . "\n");
$keyfile = $out . '/site-secret-key';
touch($keyfile);
chmod($keyfile, 0600);
file_put_contents($keyfile, trim((string) file_get_contents($options['secretkey'])) . "\n");
secbk_dev_write_json($out . '/fixture.json', [
    'v' => 1,
    'plan' => [
        'site_id' => $siteid,
        'run_id' => $runid,
        'moodle' => $header['moodle'] + ['db_family' => $header['db']['family']],
        'manifest' => $input['manifest'],
        'index' => ['sha256' => $run['index']['sha256'], 'size' => $run['index']['size'], 'count' => $run['index']['count']],
        'stored_bytes' => $run['storedbytes'],
    ],
    'expected' => [
        'moodle' => $header['moodle'],
        'db_family' => $header['db']['family'],
        'tables' => count($header['db']['tables']),
        'rows' => $rows,
        'files' => count($contents),
        'plugins' => $plugins,
        'missing_files' => count($header['missing_files']),
    ],
    // The settings of the run that the header does not have: the run of the fake service took the ones of the site now.
    'run' => ['include_logs' => settings::current()->include_logs()],
    'header' => [
        'excluded_tables' => $header['db']['excluded_tables'],
        'unknown_tables' => $header['db']['unknown_tables'],
        'forced_config' => array_keys($header['config']['forced']['config']),
        'forced_plugins' => (object) $forcedplugins,
        'additional_plugins' => $additional,
    ],
]);
cli_writeln('list of the generation: ' . count($lines) . ' entries (' . implode(', ', array_map(
    static fn(string $kind, int $n): string => "$kind $n",
    array_keys($kinds),
    $kinds
)) . '), ' . count($header['db']['tables']) . " tables with $rows rows, " . count($contents) . ' files, plugins '
    . (implode(' ', $plugins) ?: 'none') . ', missing files ' . count($header['missing_files']));
cli_writeln("written: $out");
