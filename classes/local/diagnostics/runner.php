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
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\aws_ranges;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\proxy_settings;
use tool_secbackup\local\net\transport;
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

    /**
     * Constructor.
     *
     * @param developer_settings $settings Developer settings.
     * @param dns_guard $guard Name check.
     * @param transport $transport Transport.
     * @param system_files $files Reader of /proc and /sys.
     * @param string[]|null $extensions Extensions to check; null for the ones the plugin needs.
     */
    public function __construct(
        developer_settings $settings,
        dns_guard $guard,
        transport $transport,
        system_files $files,
        ?array $extensions = null
    ) {
        $this->settings = $settings;
        $this->guard = $guard;
        $this->transport = $transport;
        $this->files = $files;
        $this->extensions = $extensions ?? self::REQUIRED_EXTENSIONS;
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
        $enforcing = $enforce !== null && trim($enforce) === '1';
        $profile = $this->apparmor_profile();
        $confining = $profile !== null && in_array($profile['mode'], self::APPARMOR_DENYING_MODES, true);
        return [
            $this->developer(),
            $this->extensions(),
            $this->proxy($endpoint),
            $this->dns($endpoint, $enforcing, $confining),
            $this->connection($endpoint, $enforcing, $confining),
            $this->clock(),
            $this->selinux($enforce),
            $this->apparmor($profile),
            $this->moodledata(),
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
     * @param bool $enforcing Whether SELinux is enforcing.
     * @param bool $confining Whether an AppArmor profile that denies is on this process.
     * @return check_result
     */
    private function dns(?endpoint $endpoint, bool $enforcing, bool $confining): check_result {
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
            self::with_access_control_advice(
                get_string($problem, 'tool_secbackup'),
                $enforcing && $problem === 'dns_doh_unavailable',
                $confining && in_array($problem, self::APPARMOR_PROBLEMS, true)
            )
        );
    }

    /**
     * Adds the advice about SELinux and about AppArmor to an advice.
     *
     * @param string $advice The advice; may be empty.
     * @param bool $selinux Whether SELinux may be the cause.
     * @param bool $apparmor Whether AppArmor may be the cause.
     * @return string
     */
    private static function with_access_control_advice(string $advice, bool $selinux, bool $apparmor): string {
        if ($selinux) {
            $advice .= ' ' . get_string('diag_selinux_advice', 'tool_secbackup');
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
     * @param bool $enforcing Whether SELinux is enforcing.
     * @param bool $confining Whether an AppArmor profile that denies is on this process.
     * @return check_result
     */
    private function connection(?endpoint $endpoint, bool $enforcing, bool $confining): check_result {
        if ($endpoint === null) {
            return new check_result('connection', check_result::INFO, get_string('diag_no_endpoint', 'tool_secbackup'));
        }
        try {
            $response = $this->transport->send('GET', $endpoint, 'status', ['Accept' => 'application/json'], '');
        } catch (plugin_exception $e) {
            $selinux = $enforcing && in_array($e->errorcode, ['transport_connect', 'dns_doh_unavailable'], true);
            $apparmor = $confining && in_array($e->errorcode, array_merge(['transport_connect'], self::APPARMOR_PROBLEMS), true);
            return new check_result(
                'connection',
                check_result::FAIL,
                $e->message(),
                self::with_access_control_advice('', $selinux, $apparmor)
            );
        }
        $data = json_decode($response->body, true);
        $code = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
        if ($response->status === 401 && $code === 'signature_required') {
            return new check_result(
                'connection',
                check_result::PASS,
                get_string('diag_connection_ok', 'tool_secbackup', $response->status)
            );
        }
        return new check_result(
            'connection',
            check_result::WARN,
            get_string('diag_connection_other', 'tool_secbackup', $response->status)
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
     * SELinux.
     *
     * @param string|null $enforce Content of /sys/fs/selinux/enforce.
     * @return check_result
     */
    private function selinux(?string $enforce): check_result {
        $context = $this->files->read('/proc/self/attr/current');
        $parts = $context === null ? [] : explode(':', trim($context, "\0\n "));
        $type = $parts[2] ?? get_string('result_unknown', 'tool_secbackup');
        $enforce = $enforce === null ? null : trim($enforce);
        if ($enforce === '1') {
            return new check_result(
                'selinux',
                check_result::INFO,
                get_string('diag_selinux_enforcing', 'tool_secbackup', $type),
                get_string('diag_selinux_advice', 'tool_secbackup')
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
     * The name comes from the kernel and is shown to an administrator, so its control characters are removed and its
     * length is cut.
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
            $name = mb_scrub((string) preg_replace('/[\x00-\x1f\x7f]/', '', $matches[1]));
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
        return $best;
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
