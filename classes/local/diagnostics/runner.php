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

namespace tool_secbackup\local\diagnostics;

use tool_secbackup\local\api\client;
use tool_secbackup\local\backup\autobackup_watch;
use tool_secbackup\local\backup\limits;
use tool_secbackup\local\backup\monitor;
use tool_secbackup\local\backup\schedule;
use tool_secbackup\local\backup\settings;
use tool_secbackup\local\backup\temp_area;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\aws_ranges;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\proxy_settings;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\net\transport_exception;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

/**
 * Runs the checks of the connection diagnostics, for the page and for the command line.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runner {
    /** @var string[] PHP extensions the plugin needs. */
    private const REQUIRED_EXTENSIONS = ['curl', 'intl', 'mbstring', 'openssl', 'sodium'];

    /** @var string[] File system types that live on the network. */
    private const NETWORK_FILE_SYSTEMS = ['nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'ceph', 'glusterfs', '9p', 'fuse.s3fs',
        'fuse.sshfs', 'fuse.glusterfs', 'fuse.rclone', 'fuse.goofys', 'fuse.mountpoint-s3'];

    /** @var string[] Language strings of the names of the days of the week, Sunday first as in the settings of Moodle. */
    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** @var string[] Modes of an AppArmor profile in which an access that the profile does not allow is stopped. */
    private const APPARMOR_DENYING_MODES = ['enforce', 'kill', 'prompt', 'user', 'mixed'];

    /** @var int Characters of an AppArmor profile name that are shown. */
    private const APPARMOR_NAME_LENGTH = 100;

    /** @var string[] Problems of the name check and of the connection that an AppArmor profile can cause. */
    private const APPARMOR_PROBLEMS = ['dns_doh_unavailable', 'dns_os_failed'];

    /** @var developer_settings Developer settings. */
    private developer_settings $settings;

    /** @var dns_guard Name check. */
    private dns_guard $guard;

    /** @var transport Transport. */
    private transport $transport;

    /** @var system_files Reader of /proc and /sys. */
    private system_files $files;

    /** @var string[] Extensions to check. */
    private array $extensions;

    /** @var monitor|null Watch of the server; null for the one of this site. */
    private ?monitor $monitor;

    /** @var \Closure|null Gives what is known of the automated backup of Moodle; null for autobackup_watch::describe(). */
    private ?\Closure $autobackup;

    /** @var \Closure|null Tries a connection on the loopback address for SELinux; null for selinux::connect_loopback(). */
    private ?\Closure $probe;

    /**
     * Constructor.
     *
     * @param developer_settings $settings Developer settings.
     * @param dns_guard $guard Name check.
     * @param transport $transport Transport.
     * @param system_files $files Reader of /proc and /sys.
     * @param string[]|null $extensions Extensions to check; null for the ones the plugin needs.
     * @param monitor|null $monitor Watch of the server; null for one that reads $files with the limits that are set.
     * @param callable|null $autobackup Gives what is known of the automated backup of Moodle, as
     *     autobackup_watch::describe() does; null for that function.
     * @param callable|null $probe As for selinux::connection_denied(); null for a connection to the loopback address.
     */
    public function __construct(
        developer_settings $settings,
        dns_guard $guard,
        transport $transport,
        system_files $files,
        ?array $extensions = null,
        ?monitor $monitor = null,
        ?callable $autobackup = null,
        ?callable $probe = null
    ) {
        $this->settings = $settings;
        $this->guard = $guard;
        $this->transport = $transport;
        $this->files = $files;
        $this->extensions = $extensions ?? self::REQUIRED_EXTENSIONS;
        $this->monitor = $monitor;
        $this->autobackup = $autobackup === null ? null : \Closure::fromCallable($autobackup);
        $this->probe = $probe === null ? null : \Closure::fromCallable($probe);
    }

    /**
     * The runner of this site.
     *
     * @return self
     */
    public static function create(): self {
        return new self(developer_settings::current(), dns_guard::create(), transport::create(), new system_files());
    }

    /**
     * Runs every check.
     *
     * @param endpoint|null $endpoint Connection URL, or null when none is known.
     * @return check_result[]
     */
    public function run(?endpoint $endpoint): array {
        $enforce = $this->files->read('/sys/fs/selinux/enforce');
        // SELinux can deny this process only when it is enforcing and the process is the confined web server (design 7.3).
        $selinux = selinux::enforcing($this->files) && selinux::confined($this->files);
        $profile = $this->apparmor_profile();
        $confining = $profile !== null && in_array($profile['mode'], self::APPARMOR_DENYING_MODES, true);
        return [
            $this->developer(),
            $this->extensions(),
            $this->proxy($endpoint),
            $this->dns($endpoint, $selinux, $confining),
            $this->connection($endpoint, $selinux, $confining),
            $this->clock(),
            $this->selinux($enforce),
            $this->apparmor($profile),
            $this->moodledata(),
            ...$this->server($this->monitor ?? new monitor($this->files, limits::current())),
        ];
    }

    /**
     * Developer settings.
     *
     * @return check_result
     */
    private function developer(): check_result {
        if (!$this->settings->is_active()) {
            return new check_result('developer', check_result::PASS, get_string('diag_developer_off', 'tool_secbackup'));
        }
        $active = array_keys(array_filter([
            'trustedkeys' => $this->settings->trusted_keys() !== [],
            'allowhttp' => $this->settings->allow_http(),
            'allowprivatehosts' => $this->settings->allow_private_hosts(),
            'skipnetworkchecks' => $this->settings->skip_network_checks(),
        ]));
        return new check_result(
            'developer',
            check_result::WARN,
            get_string('diag_developer_on', 'tool_secbackup', implode(', ', $active)),
            get_string('diag_developer_advice', 'tool_secbackup')
        );
    }

    /**
     * PHP extensions.
     *
     * @return check_result
     */
    private function extensions(): check_result {
        $missing = array_values(array_filter($this->extensions, static fn(string $name): bool => !extension_loaded($name)));
        if ($missing === []) {
            return new check_result('extensions', check_result::PASS, get_string('diag_extensions_ok', 'tool_secbackup'));
        }
        return new check_result(
            'extensions',
            check_result::FAIL,
            get_string('diag_extensions_missing', 'tool_secbackup', implode(', ', $missing)),
            get_string('diag_extensions_advice', 'tool_secbackup')
        );
    }

    /**
     * Proxy.
     *
     * @param endpoint|null $endpoint Connection URL.
     * @return check_result
     */
    private function proxy(?endpoint $endpoint): check_result {
        $description = proxy_settings::describe($endpoint === null ? 'https://www.example.com/' : $endpoint->join('status'));
        return new check_result('proxy', check_result::INFO, $description === null
            ? get_string('diag_proxy_direct', 'tool_secbackup')
            : get_string('diag_proxy_used', 'tool_secbackup', $description));
    }

    /**
     * Name resolution.
     *
     * @param endpoint|null $endpoint Connection URL.
     * @param bool $selinux Whether SELinux is enforcing and confines this process.
     * @param bool $confining Whether an AppArmor profile that denies is on this process.
     * @return check_result
     */
    private function dns(?endpoint $endpoint, bool $selinux, bool $confining): check_result {
        if ($endpoint === null) {
            return new check_result('dns', check_result::INFO, get_string('diag_no_endpoint', 'tool_secbackup'));
        }
        if ($this->settings->skip_network_checks()) {
            return new check_result('dns', check_result::INFO, get_string('diag_skipped', 'tool_secbackup'));
        }
        $report = $this->guard->inspect($endpoint->host(), proxy_settings::resolves_names($endpoint->join('status')));
        $lines = [];
        foreach ($report->doh as $name => $addresses) {
            $lines[] = get_string('diag_dns_source_' . $name, 'tool_secbackup') . ': ' . self::addresses($addresses);
        }
        $lines[] = get_string('diag_dns_source_os', 'tool_secbackup') . ': '
            . ($report->os === null ? get_string('diag_dns_viaproxy', 'tool_secbackup') : self::addresses($report->os));
        $problem = $this->guard->problem($report, aws_ranges::SERVICE_CLOUDFRONT);
        if ($problem === null) {
            return new check_result('dns', check_result::PASS, implode('; ', $lines));
        }
        return new check_result(
            'dns',
            check_result::FAIL,
            implode('; ', $lines),
            $this->with_access_control_advice(
                get_string($problem, 'tool_secbackup'),
                $selinux && $problem === 'dns_doh_unavailable' && $report->connectfailed
                    && selinux::connection_denied($this->probe),
                $confining && in_array($problem, self::APPARMOR_PROBLEMS, true)
            ),
            $problem
        );
    }

    /**
     * Adds the advice about SELinux and about AppArmor to an advice.
     *
     * @param string $advice The advice; may be empty.
     * @param bool $selinux Whether SELinux denied it.
     * @param bool $apparmor Whether AppArmor may be the cause.
     * @return string
     */
    private function with_access_control_advice(string $advice, bool $selinux, bool $apparmor): string {
        if ($selinux) {
            $advice .= ' ' . $this->selinux_advice();
        }
        if ($apparmor) {
            $advice .= ' ' . get_string('diag_apparmor_advice', 'tool_secbackup');
        }
        return trim($advice);
    }

    /**
     * Addresses of one source.
     *
     * @param string[]|null $addresses Addresses, or null when the source did not answer.
     * @return string
     */
    private static function addresses(?array $addresses): string {
        if ($addresses === null) {
            return get_string('diag_dns_noanswer', 'tool_secbackup');
        }
        return $addresses === [] ? get_string('diag_dns_noaddress', 'tool_secbackup') : implode(', ', $addresses);
    }

    /**
     * Connection to the service, with one request without a signature.
     *
     * @param endpoint|null $endpoint Connection URL.
     * @param bool $selinux Whether SELinux is enforcing and confines this process.
     * @param bool $confining Whether an AppArmor profile that denies is on this process.
     * @return check_result
     */
    private function connection(?endpoint $endpoint, bool $selinux, bool $confining): check_result {
        if ($endpoint === null) {
            return new check_result('connection', check_result::INFO, get_string('diag_no_endpoint', 'tool_secbackup'));
        }
        try {
            $response = $this->transport->send('GET', $endpoint, 'status', ['Accept' => 'application/json'], '');
        } catch (plugin_exception $e) {
            $denied = $selinux && transport_exception::failed_at_connect($e) && selinux::connection_denied($this->probe);
            $apparmor = $confining && in_array($e->errorcode, array_merge(['transport_connect'], self::APPARMOR_PROBLEMS), true);
            return new check_result(
                'connection',
                check_result::FAIL,
                $e->message(),
                $this->with_access_control_advice('', $denied, $apparmor),
                $e->errorcode
            );
        }
        $data = json_decode($response->body, true);
        $code = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
        if ($response->status === 401 && $code === 'signature_required') {
            return new check_result(
                'connection',
                check_result::PASS,
                get_string('diag_connection_ok', 'tool_secbackup', $response->status),
                '',
                'http_' . $response->status
            );
        }
        return new check_result(
            'connection',
            check_result::WARN,
            get_string('diag_connection_other', 'tool_secbackup', $response->status),
            '',
            'http_' . $response->status
        );
    }

    /**
     * Clock difference measured at the last registration or status request.
     *
     * @return check_result
     */
    private function clock(): check_result {
        $skew = state::load()->clock_skew();
        if ($skew === null) {
            return new check_result('clock', check_result::UNKNOWN, get_string('diag_clock_unknown', 'tool_secbackup'));
        }
        $detail = get_string('diag_clock_seconds', 'tool_secbackup', abs($skew));
        if (abs($skew) < client::CLOCK_SKEW_WARNING) {
            return new check_result('clock', check_result::PASS, $detail);
        }
        return new check_result(
            'clock',
            check_result::WARN,
            $detail,
            get_string('clock_skew_warning', 'tool_secbackup', (int) round(abs($skew) / 60))
        );
    }

    /**
     * The advice about SELinux, with the temporary folder of the plugin in it.
     *
     * @return string
     */
    private function selinux_advice(): string {
        $tempdir = ($this->monitor ?? new monitor($this->files, limits::current()))->limits()->tempdir;
        return get_string('diag_selinux_advice', 'tool_secbackup', $tempdir);
    }

    /**
     * SELinux.
     *
     * @param string|null $enforce Content of /sys/fs/selinux/enforce.
     * @return check_result
     */
    private function selinux(?string $enforce): check_result {
        $context = $this->files->read('/proc/self/attr/current');
        $parts = $context === null ? [] : explode(':', trim($context, "\0\n "));
        $type = isset($parts[2]) ? self::plain($parts[2]) : get_string('result_unknown', 'tool_secbackup');
        $enforce = $enforce === null ? null : trim($enforce);
        if ($enforce === '1') {
            return new check_result(
                'selinux',
                check_result::INFO,
                get_string('diag_selinux_enforcing', 'tool_secbackup', $type),
                $this->selinux_advice()
            );
        }
        if ($enforce === '0') {
            return new check_result('selinux', check_result::INFO, get_string('diag_selinux_permissive', 'tool_secbackup', $type));
        }
        return new check_result('selinux', check_result::UNKNOWN, get_string('diag_selinux_unknown', 'tool_secbackup'));
    }

    /**
     * The AppArmor profile of this process, from the label the kernel gives it.
     *
     * The label is "unconfined", or "<profile> (<mode>)". The file of AppArmor is read first; the older file is also
     * the one of SELinux, so its content counts only when it has the form of an AppArmor label. Nothing is returned when
     * AppArmor is off, or when no label can be read (the profile of php-fpm hides the switch of AppArmor from the process).
     * The name comes from the kernel and is shown to an administrator, so it is made plain (see plain()) and its length is cut.
     *
     * @return array|null ['profile' => name, empty when not confined, 'mode' => mode word], or null when not known
     */
    private function apparmor_profile(): ?array {
        $enabled = $this->files->read('/sys/module/apparmor/parameters/enabled');
        if ($enabled !== null && trim($enabled) === 'N') {
            return null;
        }
        foreach (['/proc/self/attr/apparmor/current', '/proc/self/attr/current'] as $file) {
            $label = trim($this->files->read($file) ?? '', "\0\r\n\t ");
            if ($label === 'unconfined') {
                return ['profile' => '', 'mode' => 'unconfined'];
            }
            if (preg_match('/^(.+) \(([a-z]{1,16})\)$/s', $label, $matches) !== 1) {
                continue;
            }
            $name = self::plain($matches[1]);
            if ($name !== '') {
                if (mb_strlen($name) > self::APPARMOR_NAME_LENGTH) {
                    $name = mb_substr($name, 0, self::APPARMOR_NAME_LENGTH) . "\u{2026}";
                }
                return ['profile' => $name, 'mode' => $matches[2]];
            }
        }
        return null;
    }

    /**
     * AppArmor.
     *
     * @param array|null $profile The profile of this process; see apparmor_profile().
     * @return check_result
     */
    private function apparmor(?array $profile): check_result {
        if ($profile === null) {
            return new check_result('apparmor', check_result::UNKNOWN, get_string('diag_apparmor_unknown', 'tool_secbackup'));
        }
        ['profile' => $name, 'mode' => $mode] = $profile;
        if ($name === '') {
            return new check_result('apparmor', check_result::INFO, get_string('diag_apparmor_unconfined', 'tool_secbackup'));
        }
        $advice = in_array($mode, self::APPARMOR_DENYING_MODES, true) ? get_string('diag_apparmor_advice', 'tool_secbackup') : '';
        $string = ['enforce' => 'diag_apparmor_enforcing', 'complain' => 'diag_apparmor_complain'][$mode] ?? null;
        if ($string === null) {
            return new check_result(
                'apparmor',
                check_result::INFO,
                get_string('diag_apparmor_other', 'tool_secbackup', $name . ' (' . $mode . ')'),
                $advice
            );
        }
        return new check_result('apparmor', check_result::INFO, get_string($string, 'tool_secbackup', $name), $advice);
    }

    /**
     * File system of the data directory.
     *
     * @return check_result
     */
    private function moodledata(): check_result {
        global $CFG;
        $mountinfo = $this->files->read('/proc/self/mountinfo');
        $mount = $mountinfo === null ? null : self::mount_of(realpath($CFG->dataroot) ?: $CFG->dataroot, $mountinfo);
        if ($mount === null) {
            return new check_result('moodledata', check_result::UNKNOWN, get_string('diag_moodledata_unknown', 'tool_secbackup'));
        }
        $detail = get_string('diag_moodledata', 'tool_secbackup', (object) $mount);
        if (in_array($mount['type'], self::NETWORK_FILE_SYSTEMS, true)) {
            return new check_result(
                'moodledata',
                check_result::WARN,
                $detail,
                get_string('diag_moodledata_network', 'tool_secbackup')
            );
        }
        return new check_result('moodledata', check_result::INFO, $detail);
    }

    /**
     * The checks of the server that a backup depends on: the temporary folder, the load, the memory, the database and the
     * automated backup of Moodle.
     *
     * @param monitor $monitor The watch of the server.
     * @return check_result[]
     */
    private function server(monitor $monitor): array {
        return [
            $this->tempdir($monitor),
            $this->load($monitor),
            $this->memory($monitor),
            $this->database($monitor),
            $this->autobackup(),
        ];
    }

    /**
     * The result of a value that cannot be read.
     *
     * @param string $check Name of the check.
     * @return check_result
     */
    private static function unreadable(string $check): check_result {
        return new check_result($check, check_result::UNKNOWN, get_string('diag_unreadable', 'tool_secbackup'));
    }

    /**
     * A measured value against its limit: a pass, or a warning with the advice.
     *
     * @param string $check Name of the check.
     * @param bool $beyond Whether the value is beyond the limit.
     * @param string $detail What was found.
     * @return check_result
     */
    private static function against_limit(string $check, bool $beyond, string $detail): check_result {
        if (!$beyond) {
            return new check_result($check, check_result::PASS, $detail);
        }
        return new check_result($check, check_result::WARN, $detail, get_string('diag_' . $check . '_advice', 'tool_secbackup'));
    }

    /**
     * Free space of the temporary folder and the budget of a backup in it.
     *
     * @param monitor $monitor The watch of the server.
     * @return check_result
     */
    private function tempdir(monitor $monitor): check_result {
        [$free, $total] = $monitor->disk_space();
        if ($free === null) {
            return self::unreadable('tempdir');
        }
        $budget = (new temp_area($monitor->limits(), static fn(): array => [$free, $total]))->budget_bytes();
        $detail = get_string('diag_tempdir', 'tool_secbackup', (object) [
            'free' => display_size($free),
            'budget' => display_size($budget),
        ]);
        $low = monitor::verdict(['disk_free_bytes' => $free, 'disk_total_bytes' => $total], $monitor->limits()) !== null;
        return self::against_limit('tempdir', $low, $detail);
    }

    /**
     * Load of the server.
     *
     * @param monitor $monitor The watch of the server.
     * @return check_result
     */
    private function load(monitor $monitor): check_result {
        $load = $monitor->load_per_core();
        if ($load === null) {
            return self::unreadable('load');
        }
        $limit = $monitor->limits()->loadpercore;
        $detail = get_string('diag_load', 'tool_secbackup', (object) [
            'load' => format_float($load, 2),
            'limit' => format_float($limit, 1, true, true),
        ]);
        return self::against_limit('load', $load > $limit, $detail);
    }

    /**
     * Memory that the server can still give.
     *
     * @param monitor $monitor The watch of the server.
     * @return check_result
     */
    private function memory(monitor $monitor): check_result {
        $memory = $monitor->memory_available_bytes();
        if ($memory === null) {
            return self::unreadable('memory');
        }
        $least = $monitor->limits()->minmemorymb * 1048576;
        $detail = get_string('diag_memory', 'tool_secbackup', (object) [
            'memory' => display_size($memory),
            'limit' => display_size($least),
        ]);
        return self::against_limit('memory', $memory < $least, $detail);
    }

    /**
     * Response of the database.
     *
     * @param monitor $monitor The watch of the server.
     * @return check_result
     */
    private function database(monitor $monitor): check_result {
        $milliseconds = $monitor->measure_db();
        $limit = $monitor->limits()->maxdbms;
        $detail = get_string('diag_database', 'tool_secbackup', (object) [
            'ms' => format_float($milliseconds, 1),
            'limit' => $limit,
        ]);
        return self::against_limit('database', $milliseconds > $limit, $detail);
    }

    /**
     * The schedule of the automated backup of Moodle and the time its last round took.
     *
     * The plugin does not start a backup while that backup works, so the administrator is told when it runs.
     *
     * @return check_result
     */
    private function autobackup(): check_result {
        $known = $this->autobackup === null ? autobackup_watch::describe() : ($this->autobackup)();
        $valid = preg_match('/^[01]{7}$/', (string) $known['weekdays']) === 1
            && $known['hour'] >= 0 && $known['hour'] <= 23 && $known['minute'] >= 0 && $known['minute'] <= 59;
        if (!$valid) {
            return self::unreadable('autobackup');
        }
        $days = [];
        foreach (str_split($known['weekdays']) as $number => $on) {
            if ($on === '1') {
                $days[] = get_string(self::DAYS[$number], 'calendar');
            }
        }
        if ($known['backup_auto_active'] !== 1 || $days === []) {
            return new check_result('autobackup', check_result::INFO, get_string('diag_autobackup_off', 'tool_secbackup'));
        }
        return new check_result(
            'autobackup',
            check_result::INFO,
            get_string('diag_autobackup_on', 'tool_secbackup', (object) [
                'days' => implode(', ', $days),
                'time' => sprintf('%02d:%02d', $known['hour'], $known['minute']),
                'zone' => $known['timezone'],
                'duration' => $known['lastduration'] === null
                    ? get_string('diag_autobackup_noround', 'tool_secbackup')
                    : format_time($known['lastduration']),
            ]),
            get_string('diag_autobackup_advice', 'tool_secbackup', schedule::window_text(settings::current()))
        );
    }

    /**
     * The mount that holds a path, from the text of /proc/self/mountinfo.
     *
     * The longest mount point wins. Of two mounts on the same mount point the later one wins: it covers the earlier
     * one, as a network file system does that is mounted on top of its automounter.
     *
     * @param string $path Absolute path.
     * @param string $mountinfo Content of /proc/self/mountinfo.
     * @return array|null ['type' => file system type, 'source' => mount source, 'mount' => mount point]
     */
    public static function mount_of(string $path, string $mountinfo): ?array {
        $best = null;
        foreach (explode("\n", $mountinfo) as $line) {
            $sides = explode(' - ', $line, 2);
            $left = explode(' ', $sides[0]);
            $right = explode(' ', $sides[1] ?? '');
            if (count($sides) !== 2 || count($left) < 5 || count($right) < 2) {
                continue;
            }
            $mountpoint = self::unescape($left[4]);
            $inside = $mountpoint === '/' || $path === $mountpoint || str_starts_with($path, $mountpoint . '/');
            if ($inside && ($best === null || strlen($mountpoint) >= strlen($best['mount']))) {
                $best = ['type' => $right[0], 'source' => self::unescape($right[1]), 'mount' => $mountpoint];
            }
        }
        return $best === null ? null : array_map(self::plain(...), $best);
    }

    /**
     * A text that the kernel gives, made safe to print on a terminal and on a page.
     *
     * Bytes that are not UTF-8 are replaced first, then every control character is removed: C0, DEL and C1 (U+0080 to
     * U+009F, which a terminal can take as an escape sequence). The order matters: removing a control character first can
     * join an orphan lead byte to a continuation byte and make a C1 control out of them.
     *
     * @param string $text The text as read.
     * @return string
     */
    private static function plain(string $text): string {
        return (string) preg_replace('/\p{Cc}/u', '', mb_scrub($text));
    }

    /**
     * Undoes the octal escapes of /proc/self/mountinfo (\040 is a space).
     *
     * @param string $field A field.
     * @return string
     */
    private static function unescape(string $field): string {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $m): string => chr(octdec($m[1])), $field);
    }
}
