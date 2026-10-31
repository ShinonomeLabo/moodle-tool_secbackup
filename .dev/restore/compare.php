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
 * Development only: compares a restored site with the site it was backed up from.
 *
 * Two steps, because the two sites run in different environments. First each site writes a snapshot of itself (--snapshot):
 * every table with the hash of each row by its id, the contents of the file store and whether each is in filedir with its
 * content hash, the versions of the plugins in the database and on the disk, and, for the restored site, whether
 * secbackup_admin logs in (--login: in the database and through the login page of the site, after the tables are read).
 * A snapshot keeps the values of a row only where the comparison needs them (secbk_dev_kept_columns()): it holds no setting
 * of the site but the few that are checked, so no secret of the plugin or of the site goes into the material.
 * Then the two snapshots are compared (--source, --target): the tables and the rows, the set of the contents and their
 * presence, the plugins and the login. What the restore changes on purpose, and what the backup does not hold, is left out,
 * and the output lists it first:
 * - the tables that the backup never holds (sessions, cache_flags) and those that its header says it left out (the logs,
 *   without include_logs) or did not know, given by fixture.json of make_fixture.php;
 * - the tables tool_secbackup_* and the rows of tool_secbackup in config_plugins and task_adhoc: the restore keeps the plugin
 *   of the restored site;
 * - the settings that config.php of the backed-up site forced (the names of the header in fixture.json), which the import
 *   writes into the database;
 * - siteadmins, the user secbackup_admin and the context of that user, which the import makes;
 * - what the import stopped, as the table tool_secbackup_restore_stops of the restored site records it: the settings
 *   noemailever and alternateloginurl, the flag enabled of the message processors, the flags disabled and customised of the
 *   scheduled tasks, and the ad hoc tasks of the additional plugins, which it deleted;
 * - what admin/cli/purge_caches.php, which the restore host runs after the import, changes: the revisions of the caches in
 *   the settings (secbk_dev_purge_settings()) and the column cacherev of every course (purge_other_caches() of Moodle).
 * What is left out because the import changed it is then checked on its own (secbk_dev_checks()): the values that the import
 * gives what it stops, the list of the site administrators, that nothing is stopped but what the import stops, and that the
 * header leaves out no table but the logs. Every other difference, and every check that fails, is a mismatch. Nothing of a
 * row is printed but its id.
 *
 * Usage: php compare.php --snapshot=<file> [--login=<file with the password of secbackup_admin>]
 *        php compare.php --source=<snapshot> --target=<snapshot> [--fixture=<fixture.json>]
 * The comparison prints result: ok or result: mismatch, and ends with 0 only for ok.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

define('CLI_SCRIPT', true);

$options = getopt('', ['snapshot:', 'login:', 'source:', 'target:', 'fixture:', 'help']);
if (isset($options['snapshot']) && !isset($options['source']) && !isset($options['target']) && !isset($options['fixture'])) {
    require(__DIR__ . '/../../../../../config.php');
    require_once($CFG->libdir . '/clilib.php');
    require_once($CFG->libdir . '/adminlib.php');
    raise_memory_limit(MEMORY_HUGE);
    secbk_dev_snapshot((string) $options['snapshot'], isset($options['login']) ? (string) $options['login'] : null);
    exit(0);
}
if (isset($options['source'], $options['target']) && !isset($options['snapshot']) && !isset($options['login'])) {
    ini_set('memory_limit', '2G');
    $fixture = isset($options['fixture']) ? (string) $options['fixture'] : null;
    exit(secbk_dev_compare((string) $options['source'], (string) $options['target'], $fixture));
}
fwrite(STDERR, "Usage: php compare.php --snapshot=<file> [--login=<password file>]\n"
    . "       php compare.php --source=<snapshot> --target=<snapshot> [--fixture=<fixture.json>]\n");
exit(isset($options['help']) ? 0 : 1);

/**
 * The columns whose values the snapshot keeps for the rows of a table, so that the comparison can leave out single rows and
 * columns by what they hold, and check them: null for every column. The other tables keep the hash of each row only.
 *
 * @return array<string, string[]|null>
 */
function secbk_dev_kept_columns(): array {
    return [
        'config' => ['name'],
        'config_plugins' => ['plugin', 'name'],
        'message_processors' => null,
        'task_adhoc' => ['component', 'classname'],
        'task_scheduled' => null,
        'tool_secbackup_restore_stops' => ['kind', 'name'],
    ];
}

/**
 * The settings of core whose value the snapshot keeps, for the checks: the list of the site administrators and the settings
 * that the import stops. They hold no secret.
 *
 * @return string[]
 */
function secbk_dev_checked_settings(): array {
    return ['alternateloginurl', 'noemailever', 'siteadmins'];
}

/**
 * The columns that the hash of a row leaves out, by table: purge_caches.php gives every course a new revision of its cache.
 *
 * @return string[][]
 */
function secbk_dev_masked_columns(): array {
    return ['course' => ['cacherev']];
}

/**
 * The settings of core that purge_caches.php sets to the time it runs: theme_reset_all_caches(), js_reset_all_caches(),
 * template_reset_all_caches(), the reset of the string manager and purge_other_caches() of Moodle.
 *
 * @return string[]
 */
function secbk_dev_purge_settings(): array {
    return ['jsrev', 'langrev', 'localcachedirpurged', 'scheduledtaskreset', 'templaterev', 'themerev'];
}

/**
 * The tables that a backup leaves out: always, and the logs without include_logs (table_list of the plugin).
 *
 * @return string[][] always and logs.
 */
function secbk_dev_left_out_tables(): array {
    return ['always' => ['cache_flags', 'sessions'], 'logs' => ['log', 'logstore_standard_log']];
}

/**
 * The hash of a row: of the name and the value of each column in the order of the names, with a length before each, so that
 * null, an empty text and binary values are told apart.
 *
 * @param array $row The values by column, in the order of the names.
 * @return string
 */
function secbk_dev_row_hash(array $row): string {
    $context = hash_init('sha1');
    foreach ($row as $name => $value) {
        hash_update($context, strlen((string) $name) . ':' . $name . '=');
        hash_update($context, $value === null ? 'N;' : 'S' . strlen((string) $value) . ':' . $value . ';');
    }
    return hash_final($context);
}

/**
 * Writes the snapshot of this site, readable by its owner only.
 *
 * @param string $file The file to write.
 * @param string|null $loginfile The file with the password of secbackup_admin, or null not to check the login.
 */
function secbk_dev_snapshot(string $file, ?string $loginfile): void {
    global $CFG, $DB;
    $kept = secbk_dev_kept_columns();
    $checked = secbk_dev_checked_settings();
    $masked = secbk_dev_masked_columns();
    $tables = [];
    $rowcount = 0;
    $names = $DB->get_tables(false);
    sort($names, SORT_STRING);
    foreach ($names as $table) {
        $columns = $DB->get_columns($table, false);
        ksort($columns, SORT_STRING);
        $types = [];
        foreach ($columns as $name => $column) {
            $types[] = $name . ':' . $column->meta_type;
        }
        $hasid = isset($columns['id']);
        $hashes = [];
        $values = [];
        $rows = $DB->get_recordset($table, null, $hasid ? 'id ASC' : '');
        foreach ($rows as $record) {
            $row = (array) $record;
            ksort($row, SORT_STRING);
            $hash = secbk_dev_row_hash(array_diff_key($row, array_flip($masked[$table] ?? [])));
            // A table without the column id has its rows by their hash, with a number for rows that are the same.
            $key = $hasid ? (string) $row['id'] : $hash . '#' . count(array_keys($hashes, $hash, true));
            $hashes[$key] = $hash;
            if (array_key_exists($table, $kept)) {
                $columnsofrow = $kept[$table];
                if ($table === 'config' && in_array($row['name'], $checked, true)) {
                    $columnsofrow[] = 'value';
                }
                $values[$key] = $columnsofrow === null ? $row : array_intersect_key($row, array_flip($columnsofrow));
            }
        }
        $rows->close();
        $rowcount += count($hashes);
        $tables[$table] = ['columns' => $types, 'rows' => $hashes, 'masked' => $masked[$table] ?? []];
        if (array_key_exists($table, $kept)) {
            $tables[$table]['values'] = $values;
        }
    }

    // The contents of the file store, and whether each is in filedir with its content hash.
    $filedir = $CFG->filedir ?? $CFG->dataroot . '/filedir';
    $files = [];
    $hashes = $DB->get_fieldset_sql('SELECT DISTINCT contenthash FROM {files} WHERE filesize > 0');
    sort($hashes, SORT_STRING);
    foreach ($hashes as $hash) {
        $path = $filedir . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $files[$hash] = !is_file($path) ? 'missing' : (sha1_file($path) === $hash ? 'ok' : 'corrupt');
    }

    $plugins = [];
    foreach (core_plugin_manager::instance()->get_plugins() as $type => $oftype) {
        foreach ($oftype as $name => $plugin) {
            $plugins[$type . '_' . $name] = ['db' => $plugin->versiondb === null ? null : (string) $plugin->versiondb,
                'disk' => $plugin->versiondisk === null ? null : (string) $plugin->versiondisk];
        }
    }
    ksort($plugins, SORT_STRING);

    // The user secbackup_admin and its contexts, which the comparison leaves out.
    $admins = array_map('strval', $DB->get_fieldset_select('user', 'id', 'username = ?', ['secbackup_admin']));
    $contexts = [];
    if ($admins !== []) {
        [$in, $params] = $DB->get_in_or_equal($admins);
        $select = "contextlevel = ? AND instanceid $in";
        $contexts = array_map('strval', $DB->get_fieldset_select('context', 'id', $select, array_merge([CONTEXT_USER], $params)));
    }

    // The core tasks that the import of this plugin stops, as the plugin of this site names them.
    $coretasks = (new ReflectionClassConstant(\tool_secbackup\local\restore\temp_site_safety::class, 'CORE_TASKS'))->getValue();
    $forcedplugins = [];
    foreach ($CFG->forced_plugin_settings ?? [] as $component => $settings) {
        $forcedplugins[$component] = array_keys((array) $settings);
    }
    $snapshot = [
        'v' => 1,
        'wwwroot' => $CFG->wwwroot,
        'moodle' => ['release' => $CFG->release, 'version' => (string) $CFG->version, 'family' => $DB->get_dbfamily(),
            'dbtype' => $CFG->dbtype],
        'upgrade' => moodle_needs_upgrading(),
        'forced' => ['config' => array_keys($CFG->config_php_settings ?? []), 'plugins' => (object) $forcedplugins],
        'admin' => ['users' => $admins, 'contexts' => $contexts],
        'coretasks' => $coretasks,
        'tables' => $tables,
        'files' => (object) $files,
        'plugins' => $plugins,
        'login' => $loginfile === null ? null : secbk_dev_login(trim((string) file_get_contents($loginfile))),
    ];
    $text = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR);
    $umask = umask(0077);
    $written = file_put_contents($file, $text);
    umask($umask);
    if ($written === false) {
        cli_error('The snapshot could not be written.');
    }
    cli_writeln('snapshot: ' . count($tables) . " tables, $rowcount rows, " . count($files) . ' contents, ' . count($plugins)
        . ' plugins' . ($loginfile === null ? '' : ', login ' . json_encode($snapshot['login'])));
}

/**
 * Whether secbackup_admin logs in with the password: in the database (the account and the password), and through the login
 * page of the site, after which the administration search page must open.
 *
 * The site is asked on its own web server in this container: the request goes to localhost on port 80 with the host and the
 * port of wwwroot, as a browser outside sends them.
 *
 * @param string $password The password.
 * @return string[] database and web: ok, or what failed.
 */
function secbk_dev_login(string $password): array {
    global $CFG, $DB;
    require_once($CFG->libdir . '/authlib.php');
    $user = $DB->get_record('user', ['username' => 'secbackup_admin', 'mnethostid' => $CFG->mnet_localhost_id]);
    if ($user === false) {
        $database = 'no user';
    } else if ($user->auth !== 'manual' || !is_enabled_auth('manual')) {
        $database = 'not a manual account that is enabled';
    } else if (!$user->confirmed || $user->suspended || $user->deleted) {
        $database = 'not confirmed, or suspended, or deleted';
    } else if (!validate_internal_user_password($user, $password)) {
        $database = 'another password';
    } else if (login_is_lockedout($user)) {
        $database = 'locked out';
    } else if (!is_siteadmin($user->id)) {
        $database = 'not a site administrator';
    } else {
        $database = 'ok';
    }

    $parts = parse_url($CFG->wwwroot);
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECT_TO => [$parts['host'] . ':' . $port . ':localhost:80'],
    ]);
    curl_setopt($curl, CURLOPT_URL, $CFG->wwwroot . '/login/index.php');
    $page = (string) curl_exec($curl);
    if (preg_match('/name="logintoken" value="([^"]+)"/', $page, $m) !== 1) {
        return ['database' => $database, 'web' => 'no login form (HTTP ' . curl_getinfo($curl, CURLINFO_RESPONSE_CODE) . ')'];
    }
    curl_setopt($curl, CURLOPT_POST, true);
    // The separator is given: Moodle 4.5 sets arg_separator.output to "&amp;" for its pages, and the form would lose fields.
    $fields = ['username' => 'secbackup_admin', 'password' => $password, 'logintoken' => $m[1]];
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
    curl_exec($curl);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_URL, $CFG->wwwroot . '/admin/search.php');
    $page = (string) curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $url = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);
    if ($code !== 200 || $url !== $CFG->wwwroot . '/admin/search.php' || !str_contains($page, 'id="page-admin-search"')) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return ['database' => $database, 'web' => "the administration search page did not open (HTTP $code at $path)"];
    }
    return ['database' => $database, 'web' => 'ok'];
}

/**
 * The values of a column of the rows of a table that a snapshot keeps, by id.
 *
 * @param array $snapshot The snapshot.
 * @param string $table The table.
 * @return array[] The rows by id.
 */
function secbk_dev_values(array $snapshot, string $table): array {
    return $snapshot['tables'][$table]['values'] ?? [];
}

/**
 * The value of a setting of core in a snapshot, among the settings that it keeps the value of.
 *
 * @param array $snapshot The snapshot.
 * @param string $name The setting.
 * @return string|null The value, or null when the site does not have the setting.
 */
function secbk_dev_setting(array $snapshot, string $name): ?string {
    foreach (secbk_dev_values($snapshot, 'config') as $row) {
        if ($row['name'] === $name) {
            return $row['value'] ?? null;
        }
    }
    return null;
}

/**
 * Checks what the comparison leaves out because the import changed it, or because the backup does not hold it: each check
 * that fails is a line of the result.
 *
 * - The header leaves out no table but cache_flags, sessions and the logs, the logs only without include_logs (when
 *   fixture.json tells), and a table that it did not know is not a table of the restored site (whose install made every table
 *   that an install.xml describes).
 * - siteadmins of the restored site is the list of the backed-up site with secbackup_admin added.
 * - noemailever is 1 and alternateloginurl is empty; every message processor but popup is disabled; every scheduled task that
 *   the import recorded as stopped is disabled and customised, and is one of an additional plugin or one of the core tasks
 *   that the import stops; every scheduled task of an additional plugin is disabled; no ad hoc task of an additional plugin
 *   is left; and the registration of the plugin is not restored. A kind of record that is not known is a failure.
 *
 * A check that cannot be made is not a failure, and is named in $notes: those that need the header without fixture.json, and
 * those of the logs when fixture.json does not record include_logs (a material made before it recorded the setting).
 *
 * @param array $source The snapshot of the backed-up site.
 * @param array $target The snapshot of the restored site.
 * @param array|null $fixture fixture.json, or null (the checks that need its header are not made then).
 * @param array $stops The records of the import by kind: the names.
 * @param string[] $notes Gets the checks that were not made.
 * @return string[] The checks that failed.
 */
function secbk_dev_checks(array $source, array $target, ?array $fixture, array $stops, array &$notes): array {
    $failed = [];
    $notes = [];
    $leftout = secbk_dev_left_out_tables();
    if ($fixture === null) {
        $notes[] = 'no fixture.json: the header, and whether the tasks that are stopped or left are of the additional plugins,'
            . ' are not checked';
    } else {
        $excluded = $fixture['header']['excluded_tables'];
        $other = array_diff($excluded, $leftout['always'], $leftout['logs']);
        if ($other !== []) {
            $failed[] = 'the header leaves out tables that are not the logs: ' . implode(' ', $other);
        }
        $logs = array_values(array_intersect($leftout['logs'], array_keys($source['tables'])));
        $logsout = array_values(array_intersect($leftout['logs'], $excluded));
        $includelogs = $fixture['run']['include_logs'] ?? null;
        if ($includelogs === false && $logsout !== $logs) {
            $failed[] = 'without include_logs the header does not leave out every table of the logs';
        } else if ($includelogs === true && $logsout !== []) {
            $failed[] = 'with include_logs the header leaves out tables of the logs';
        } else if ($includelogs === null) {
            $notes[] = 'include_logs is not recorded in fixture.json: whether the header left out the logs is not checked';
        }
        $known = array_intersect($fixture['header']['unknown_tables'], array_keys($target['tables']));
        if ($known !== []) {
            $failed[] = 'tables that the header did not know are tables of the restored site: ' . implode(' ', $known);
        }
    }

    // As Moodle reads the list: ids, each a number, none 0.
    $ids = array_filter(array_map('intval', explode(',', (string) secbk_dev_setting($source, 'siteadmins'))));
    $admins = array_values(array_map('strval', $ids));
    foreach ($target['admin']['users'] as $id) {
        if (!in_array($id, $admins, true)) {
            $admins[] = $id;
        }
    }
    if (secbk_dev_setting($target, 'siteadmins') !== implode(',', $admins) || $target['admin']['users'] === []) {
        $failed[] = 'siteadmins is not the list of the backed-up site with secbackup_admin added';
    }
    if (secbk_dev_setting($target, 'noemailever') !== '1') {
        $failed[] = 'noemailever is not 1';
    }
    if ((string) secbk_dev_setting($target, 'alternateloginurl') !== '') {
        $failed[] = 'alternateloginurl is not empty';
    }

    $processors = secbk_dev_values($target, 'message_processors');
    foreach ($processors as $row) {
        if ($row['name'] !== 'popup' && (string) $row['enabled'] !== '0') {
            $failed[] = "the message processor {$row['name']} is enabled";
        }
    }
    $names = array_column($processors, 'name');
    foreach ($stops['processor'] ?? [] as $name) {
        if (!in_array($name, $names, true)) {
            $failed[] = "the stopped message processor $name is not there";
        }
    }

    $additional = $fixture['header']['additional_plugins'] ?? null;
    $tasks = [];
    foreach (secbk_dev_values($target, 'task_scheduled') as $row) {
        $tasks[$row['classname']] = $row;
        if ($additional !== null && in_array($row['component'], $additional, true) && (string) $row['disabled'] !== '1') {
            $failed[] = "the scheduled task {$row['classname']} of an additional plugin is enabled";
        }
    }
    foreach ($stops['task'] ?? [] as $name) {
        $row = $tasks[$name] ?? null;
        if ($row === null || (string) $row['disabled'] !== '1' || (string) $row['customised'] !== '1') {
            $failed[] = "the stopped scheduled task $name is not disabled and customised";
        } else if (
            $additional !== null && !in_array($name, $target['coretasks'], true)
            && !in_array($row['component'], $additional, true)
        ) {
            $failed[] = "the scheduled task $name is stopped, but it is neither of an additional plugin nor a core task to stop";
        }
    }

    foreach (secbk_dev_values($target, 'task_adhoc') as $row) {
        if (in_array($row['classname'], $stops['adhoc'] ?? [], true)) {
            $failed[] = "an ad hoc task {$row['classname']} that the import deleted is there";
        } else if ($additional !== null && in_array($row['component'], $additional, true)) {
            $failed[] = "an ad hoc task {$row['classname']} of an additional plugin is there";
        }
    }

    if (($stops['registration'] ?? []) !== [] && $stops['registration'] !== ['tool_secbackup']) {
        $failed[] = 'the record of the registration does not name tool_secbackup';
    }
    foreach (secbk_dev_values($target, 'config_plugins') as $row) {
        if ($row['plugin'] === 'tool_secbackup' && in_array($row['name'], ['endpoint', 'siteid'], true)) {
            $failed[] = 'the restored site has a registration of tool_secbackup';
            break;
        }
    }
    $unknown = array_diff(array_keys($stops), ['adhoc', 'processor', 'registration', 'setting', 'task']);
    if ($unknown !== []) {
        $failed[] = 'records of a kind that is not known: ' . implode(' ', $unknown);
    }
    return $failed;
}

/**
 * Compares the snapshot of a restored site with the snapshot of the site it was backed up from.
 *
 * @param string $sourcefile The snapshot of the backed-up site.
 * @param string $targetfile The snapshot of the restored site.
 * @param string|null $fixturefile fixture.json of the material of the restore, or null.
 * @return int 0 when everything matches, else 1.
 */
function secbk_dev_compare(string $sourcefile, string $targetfile, ?string $fixturefile): int {
    $source = json_decode((string) file_get_contents($sourcefile), true);
    $target = json_decode((string) file_get_contents($targetfile), true);
    $fixture = $fixturefile === null ? null : json_decode((string) file_get_contents($fixturefile), true);
    if (!is_array($source) || !is_array($target) || ($fixturefile !== null && !is_array($fixture))) {
        fwrite(STDERR, "A snapshot or fixture.json cannot be read.\n");
        return 1;
    }

    // What is left out, with the reason, and the rules that leave it out.
    $excluded = [];
    $leftouttables = secbk_dev_left_out_tables();
    $tables = array_fill_keys($leftouttables['always'], 'the backup never holds it');
    if ($fixture === null) {
        $tables += array_fill_keys($leftouttables['logs'], 'the backup holds it only with include_logs (without fixture.json)');
    } else {
        foreach ($fixture['header']['excluded_tables'] as $table) {
            $tables[$table] ??= 'the header of the backup says that it left it out';
        }
        foreach ($fixture['header']['unknown_tables'] as $table) {
            $tables[$table] ??= 'no install.xml describes it, and the backup does not hold it';
        }
    }
    foreach (array_keys($source['tables'] + $target['tables']) as $table) {
        if (str_starts_with($table, 'tool_secbackup_')) {
            $tables[$table] = 'a table of the plugin: the restore keeps the plugin of the restored site';
        }
    }
    ksort($tables, SORT_STRING);
    foreach ($tables as $table => $reason) {
        $excluded[] = "table $table: $reason";
    }

    $stops = [];
    foreach (secbk_dev_values($target, 'tool_secbackup_restore_stops') as $stop) {
        $stops[$stop['kind']][] = $stop['name'];
    }
    // The names that config.php of the backed-up site forced, as the header has them; the snapshot of the site without it.
    $forced = $fixture['header']['forced_config'] ?? $source['forced']['config'];
    sort($forced, SORT_STRING);
    $purged = secbk_dev_purge_settings();
    $settings = array_merge(['siteadmins'], $stops['setting'] ?? [], $purged);
    $excluded[] = 'config siteadmins: the import adds secbackup_admin to the site administrators (checked below)';
    foreach ($stops['setting'] ?? [] as $name) {
        $excluded[] = "config $name: the import stopped it (checked below)";
    }
    $excluded[] = 'config ' . implode(' ', $purged) . ': purge_caches.php after the import sets them to the time it runs';
    foreach (secbk_dev_masked_columns() as $table => $columns) {
        $excluded[] = "$table, the columns " . implode(' ', $columns) . ': purge_caches.php after the import gives every row a new'
            . ' revision';
    }
    $excluded[] = 'config, forced by config.php of the backed-up site, which the import writes into the database ('
        . count($forced) . ($fixture === null ? ', from the snapshot' : ', from the header') . '): ' . implode(' ', $forced);
    $forcedplugins = [];
    foreach ($fixture['header']['forced_plugins'] ?? $source['forced']['plugins'] as $component => $names) {
        foreach ($names as $name) {
            $forcedplugins[$component . '/' . $name] = true;
        }
    }
    $excluded[] = 'config_plugins of tool_secbackup, and those forced by config.php (' . count($forcedplugins)
        . '): the plugin of the restored site, and what the import writes into the database';
    $excluded[] = 'task_adhoc of tool_secbackup: the plugin of the restored site';
    $adhoc = $stops['adhoc'] ?? [];
    $excluded[] = 'task_adhoc of ' . count($adhoc) . ' classes of the additional plugins, which the import deleted (checked'
        . ' below): ' . implode(' ', $adhoc);
    $tasks = $stops['task'] ?? [];
    $excluded[] = 'task_scheduled, the columns disabled and customised of ' . count($tasks) . ' tasks that the import stopped'
        . ' (checked below)';
    $processors = $stops['processor'] ?? [];
    $excluded[] = 'message_processors, the column enabled of ' . count($processors) . ' processors that the import stopped'
        . ' (checked below): ' . implode(' ', $processors);
    $excluded[] = 'the registration of tool_secbackup (' . count($stops['registration'] ?? []) . ' record): not restored,'
        . ' its rows are the ones of the plugin above (checked below)';
    // The user and its context in each site: the id of one in the restored site is a row of another in the backed-up site,
    // when the restore did not keep the ids.
    $admin = ['source' => $source['admin'], 'target' => $target['admin']];
    $excluded[] = 'user secbackup_admin and its context (' . count($target['admin']['users']) . ' and '
        . count($target['admin']['contexts']) . ' rows in the restored site, ' . count($source['admin']['users']) . ' and '
        . count($source['admin']['contexts']) . ' in the backed-up site): the import makes them';

    // The rows to leave out (given the row, its id and the side), and the columns to leave out of a row, by table.
    $leftout = [
        'config' => static fn(array $row): bool => in_array($row['name'], $settings, true)
            || in_array($row['name'], $forced, true),
        'config_plugins' => static fn(array $row): bool => $row['plugin'] === 'tool_secbackup'
            || isset($forcedplugins[$row['plugin'] . '/' . $row['name']]),
        'task_adhoc' => static fn(array $row): bool => $row['component'] === 'tool_secbackup'
            || in_array($row['classname'], $adhoc, true),
        'user' => static fn(array $row, string $id, string $side): bool => in_array($id, $admin[$side]['users'], true),
        'context' => static fn(array $row, string $id, string $side): bool => in_array($id, $admin[$side]['contexts'], true),
    ];
    $masks = [
        'task_scheduled' => static fn(array $row): array => in_array($row['classname'], $tasks, true)
            ? ['disabled', 'customised'] : [],
        'message_processors' => static fn(array $row): array => in_array($row['name'], $processors, true)
            ? ['enabled'] : [],
    ];

    echo "left out of the comparison:\n";
    foreach ($excluded as $line) {
        echo "  $line\n";
    }

    $ok = true;
    $compared = 0;
    $rows = 0;
    $bad = [];
    $names = array_keys($source['tables'] + $target['tables']);
    sort($names, SORT_STRING);
    foreach ($names as $table) {
        if (isset($tables[$table])) {
            continue;
        }
        $from = $source['tables'][$table] ?? null;
        $to = $target['tables'][$table] ?? null;
        if ($from === null || $to === null) {
            $bad[] = "$table: only in the " . ($from === null ? 'restored' : 'backed-up') . ' site';
            continue;
        }
        if ($from['columns'] !== $to['columns']) {
            $bad[] = "$table: other columns";
            continue;
        }
        $compared++;
        $sides = [];
        foreach (['source' => $from, 'target' => $to] as $side => $data) {
            $kept = [];
            foreach ($data['rows'] as $id => $hash) {
                $id = (string) $id;
                $row = $data['values'][$id] ?? [];
                if (isset($leftout[$table]) && $leftout[$table]($row, $id, $side)) {
                    continue;
                }
                $mask = isset($masks[$table]) ? $masks[$table]($row) : [];
                $kept[$id] = $mask === [] ? $hash : secbk_dev_row_hash(array_diff_key($row, array_flip($mask)));
            }
            $sides[$side] = $kept;
        }
        $rows += count($sides['source']);
        $missing = array_keys(array_diff_key($sides['source'], $sides['target']));
        $added = array_keys(array_diff_key($sides['target'], $sides['source']));
        $changed = array_keys(array_diff_assoc(array_intersect_key($sides['source'], $sides['target']), $sides['target']));
        if ($missing !== [] || $added !== [] || $changed !== []) {
            $first = static fn(array $ids): string => implode(' ', array_slice($ids, 0, 5));
            $bad[] = "$table: " . count($sides['source']) . ' rows, ' . count($missing) . ' only in the backed-up site ('
                . $first($missing) . '), ' . count($added) . ' only in the restored site (' . $first($added) . '), '
                . count($changed) . ' differ (' . $first($changed) . ')';
        }
    }
    echo "tables: $compared compared with $rows rows of the backed-up site, " . count($bad) . " differ\n";
    foreach ($bad as $line) {
        echo "  $line\n";
    }
    $ok = $ok && $bad === [];

    $notes = [];
    $failed = secbk_dev_checks($source, $target, $fixture, $stops, $notes);
    echo 'checks of what is left out: ' . count($failed) . " failed\n";
    foreach ($failed as $line) {
        echo "  $line\n";
    }
    foreach ($notes as $line) {
        echo "  not checked: $line\n";
    }
    $ok = $ok && $failed === [];

    $files = $target['files'];
    $notok = array_keys(array_filter($files, static fn(string $state): bool => $state !== 'ok'));
    $missing = array_keys(array_diff_key($source['files'], $files));
    $added = array_keys(array_diff_key($files, $source['files']));
    echo 'files: ' . count($source['files']) . ' contents in the backed-up site, ' . count($files) . ' in the restored site, '
        . count($missing) . ' only in the backed-up site, ' . count($added) . ' only in the restored site, ' . count($notok)
        . " not in filedir with their content hash in the restored site\n";
    $ok = $ok && $missing === [] && $added === [] && $notok === [] && $files !== [];

    $plugins = [];
    foreach (array_keys($source['plugins'] + $target['plugins']) as $component) {
        // A plugin that is on the disk and not installed has the version null in the database: not the same as no plugin.
        $from = array_key_exists($component, $source['plugins']) ? $source['plugins'][$component]['db'] : 'no plugin';
        $to = $target['plugins'][$component] ?? null;
        if ($to === null || $from !== $to['db'] || ($to['db'] !== null && $to['db'] !== $to['disk'])) {
            $plugins[] = "$component (" . ($from ?? 'not installed') . '; ' . ($to === null ? 'no plugin'
                : ($to['db'] ?? 'not installed') . ' on the disk ' . ($to['disk'] ?? 'none')) . ')';
        }
    }
    echo 'plugins: ' . count($source['plugins']) . ' in the backed-up site, ' . count($plugins) . ' with another version in the'
        . ' database or on the disk of the restored site' . ($plugins === [] ? '' : ': ' . implode(', ', $plugins))
        . ', upgrade pending: ' . ($target['upgrade'] ? 'yes' : 'no') . ', Moodle ' . $source['moodle']['version'] . ' and '
        . $target['moodle']['version'] . "\n";
    $ok = $ok && $plugins === [] && !$target['upgrade'] && $source['moodle']['version'] === $target['moodle']['version'];

    $login = $target['login'] ?? ['database' => 'not checked', 'web' => 'not checked'];
    echo 'login as secbackup_admin: database ' . $login['database'] . ', web ' . $login['web'] . "\n";
    $ok = $ok && $login === ['database' => 'ok', 'web' => 'ok'];

    echo 'result: ' . ($ok ? 'ok' : 'mismatch') . "\n";
    return $ok ? 0 : 1;
}
