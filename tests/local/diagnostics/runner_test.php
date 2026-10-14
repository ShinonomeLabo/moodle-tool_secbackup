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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\api\status_result;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\dns_guard;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\network_fakes;
use tool_secbackup\tests\system_roots;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\diagnostics\runner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\diagnostics\check_result::class)]
/**
 * Tests for the diagnostics.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\diagnostics\runner
 * @covers     \tool_secbackup\local\diagnostics\check_result
 */
final class runner_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * The test connection URL.
     *
     * @return endpoint
     */
    private static function endpoint(): endpoint {
        return endpoint::parse('https://' . network_fakes::HOST . '/api/lms-backup/v1', developer_settings::from_value(null));
    }

    /**
     * An answer of the service to a request without a signature.
     *
     * @return Response
     */
    private static function unsigned_answer(): Response {
        return new Response(401, [], '{"error":{"code":"signature_required","message":"x"}}');
    }

    /**
     * A failed connection.
     *
     * @param int $errno The cURL error number.
     * @return ConnectException
     */
    private static function failure(int $errno): ConnectException {
        return new ConnectException(
            'cURL error ' . $errno,
            new Request('GET', 'https://app.example.test/'),
            null,
            ['errno' => $errno]
        );
    }

    /**
     * A runner.
     *
     * @param array $answers Answers of the mocked network.
     * @param string $root Root of the system files.
     * @param array $settings Developer settings.
     * @param dns_guard|null $guard Name check; null for one that passes.
     * @param array|null $extensions Required extensions.
     * @return runner
     */
    private static function runner(
        array $answers,
        string $root,
        array $settings = [],
        ?dns_guard $guard = null,
        ?array $extensions = null
    ): runner {
        $developer = developer_settings::from_value($settings === [] ? null : $settings);
        $guard = $guard ?? network_fakes::passing_guard();
        return new runner(
            $developer,
            $guard,
            new transport($developer, $guard, new MockHandler($answers)),
            new system_files($root),
            $extensions
        );
    }

    /**
     * Results by check name.
     *
     * @param check_result[] $results Results.
     * @return check_result[]
     */
    private static function by_name(array $results): array {
        $named = [];
        foreach ($results as $result) {
            $named[$result->check] = $result;
        }
        return $named;
    }

    public function test_all_checks_in_order(): void {
        $results = self::runner([self::unsigned_answer()], make_request_directory())->run(self::endpoint());
        $this->assertSame(
            ['developer', 'extensions', 'proxy', 'dns', 'connection', 'clock', 'selinux', 'moodledata'],
            array_map(static fn(check_result $result): string => $result->check, $results)
        );
        $named = self::by_name($results);
        $this->assertSame(check_result::PASS, $named['developer']->result);
        $this->assertSame(check_result::PASS, $named['extensions']->result);
        $this->assertSame(get_string('diag_proxy_direct', 'tool_secbackup'), $named['proxy']->detail);
        $this->assertSame(check_result::PASS, $named['dns']->result);
        $this->assertStringContainsString('13.225.1.1', $named['dns']->detail);
        $this->assertStringContainsString('13.225.9.9', $named['dns']->detail);
        $this->assertSame(check_result::PASS, $named['connection']->result);
        $this->assertSame(get_string('diag_connection_ok', 'tool_secbackup', 401), $named['connection']->detail);
        $this->assertSame(check_result::UNKNOWN, $named['clock']->result);
        $this->assertSame(check_result::UNKNOWN, $named['selinux']->result, 'Unreadable files read as unknown.');
        $this->assertSame(check_result::UNKNOWN, $named['moodledata']->result);
    }

    public function test_the_name_check_lists_every_source_with_its_label(): void {
        $named = self::by_name(self::runner([self::unsigned_answer()], make_request_directory())->run(self::endpoint()));
        $this->assertSame(
            get_string('diag_dns_source_cloudflare', 'tool_secbackup') . ': 13.225.1.1; ' .
            get_string('diag_dns_source_google', 'tool_secbackup') . ': 13.225.1.2; ' .
            get_string('diag_dns_source_os', 'tool_secbackup') . ': 13.225.9.9, 13.225.9.8',
            $named['dns']->detail
        );
        $this->assertSame('', $named['dns']->advice);
    }

    public function test_without_a_connection_url(): void {
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame(check_result::INFO, $named['dns']->result);
        $this->assertSame(get_string('diag_no_endpoint', 'tool_secbackup'), $named['dns']->detail);
        $this->assertSame(get_string('diag_no_endpoint', 'tool_secbackup'), $named['connection']->detail);
        $this->assertSame(check_result::INFO, $named['connection']->result);
        $this->assertSame(check_result::INFO, $named['proxy']->result);
    }

    public function test_developer_settings_off(): void {
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame(check_result::PASS, $named['developer']->result);
        $this->assertSame(get_string('diag_developer_off', 'tool_secbackup'), $named['developer']->detail);
        $this->assertSame('', $named['developer']->advice);
    }

    public function test_developer_settings_are_reported(): void {
        $settings = [
            'trustedkeys' => ['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw='],
            'allowhttp' => true,
            'allowprivatehosts' => true,
            'skipnetworkchecks' => true,
        ];
        $runner = self::runner([self::unsigned_answer()], make_request_directory(), $settings);
        $named = self::by_name($runner->run(self::endpoint()));
        $this->assertSame(check_result::WARN, $named['developer']->result);
        $active = 'trustedkeys, allowhttp, allowprivatehosts, skipnetworkchecks';
        $this->assertSame(get_string('diag_developer_on', 'tool_secbackup', $active), $named['developer']->detail);
        $this->assertSame(get_string('diag_developer_advice', 'tool_secbackup'), $named['developer']->advice);
        $this->assertSame(check_result::INFO, $named['dns']->result);
        $this->assertSame(get_string('diag_skipped', 'tool_secbackup'), $named['dns']->detail);
        $this->assertSame(check_result::PASS, $named['connection']->result);
    }

    /**
     * Each developer setting on its own, and the words that name it.
     *
     * @return array
     */
    public static function one_developer_setting_provider(): array {
        return [
            'a trusted key' => [['trustedkeys' => ['PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw=']], 'trustedkeys'],
            'http' => [['allowhttp' => true], 'allowhttp'],
            'private hosts' => [['allowprivatehosts' => true], 'allowprivatehosts'],
            'no network checks' => [['skipnetworkchecks' => true], 'skipnetworkchecks'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('one_developer_setting_provider')]
    /**
     * Only the active setting is named.
     *
     * @dataProvider one_developer_setting_provider
     * @param array $settings The developer settings.
     * @param string $name The name that must be the only one reported.
     */
    public function test_only_the_active_setting_is_named(array $settings, string $name): void {
        $named = self::by_name(self::runner([], make_request_directory(), $settings)->run(null));
        $this->assertSame(get_string('diag_developer_on', 'tool_secbackup', $name), $named['developer']->detail);
    }

    public function test_missing_extension(): void {
        $runner = self::runner([], make_request_directory(), [], null, ['sodium', 'no_such_extension']);
        $named = self::by_name($runner->run(null));
        $this->assertSame(check_result::FAIL, $named['extensions']->result);
        $this->assertSame(
            get_string('diag_extensions_missing', 'tool_secbackup', 'no_such_extension'),
            $named['extensions']->detail
        );
        $this->assertSame(get_string('diag_extensions_advice', 'tool_secbackup'), $named['extensions']->advice);
    }

    public function test_extensions_all_present(): void {
        $named = self::by_name(self::runner([], make_request_directory(), [], null, ['sodium'])->run(null));
        $this->assertSame(check_result::PASS, $named['extensions']->result);
        $this->assertSame(get_string('diag_extensions_ok', 'tool_secbackup'), $named['extensions']->detail);
        $this->assertSame('', $named['extensions']->advice);
    }

    public function test_the_extensions_the_plugin_needs_are_the_default(): void {
        $constant = (new \ReflectionClass(runner::class))->getReflectionConstant('REQUIRED_EXTENSIONS');
        $this->assertSame(['curl', 'intl', 'mbstring', 'openssl', 'sodium'], $constant->getValue());
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame(check_result::PASS, $named['extensions']->result, 'This environment has what the plugin needs.');
    }

    public function test_proxy(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $guard = new dns_guard(
            network_fakes::ranges(),
            [network_fakes::doh('cloudflare', ['13.225.1.1'])],
            static fn(string $host): array => []
        );
        $runner = self::runner([self::unsigned_answer()], make_request_directory(), [], $guard);
        $named = self::by_name($runner->run(self::endpoint()));
        $this->assertSame(check_result::INFO, $named['proxy']->result);
        $this->assertSame(
            get_string('diag_proxy_used', 'tool_secbackup', 'HTTP proxy.example.ac.jp:3128'),
            $named['proxy']->detail
        );
        $this->assertStringContainsString(get_string('diag_dns_viaproxy', 'tool_secbackup'), $named['dns']->detail);
        $this->assertSame(check_result::PASS, $named['dns']->result);
    }

    public function test_a_socks5_proxy_leaves_the_name_to_this_server(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '1080';
        $CFG->proxytype = 'SOCKS5';
        $named = self::by_name(self::runner([self::unsigned_answer()], make_request_directory())->run(self::endpoint()));
        $this->assertSame(
            get_string('diag_proxy_used', 'tool_secbackup', 'SOCKS5 proxy.example.ac.jp:1080'),
            $named['proxy']->detail
        );
        $this->assertStringNotContainsString(get_string('diag_dns_viaproxy', 'tool_secbackup'), $named['dns']->detail);
        $this->assertStringContainsString(
            get_string('diag_dns_source_os', 'tool_secbackup') . ': 13.225.9.9, 13.225.9.8',
            $named['dns']->detail
        );
    }

    public function test_the_credentials_of_a_proxy_are_in_no_result(): void {
        global $CFG;
        $CFG->proxyhost = 'proxy.example.ac.jp';
        $CFG->proxyport = '3128';
        $CFG->proxyuser = 'proxyuser-7f3a';
        $CFG->proxypassword = 'proxypass-91bc';
        $results = self::runner([self::unsigned_answer()], make_request_directory())->run(self::endpoint());
        $this->assertCount(8, $results);
        foreach ($results as $result) {
            foreach ([$result->detail, $result->advice] as $text) {
                $this->assertStringNotContainsString('proxyuser-7f3a', $text, $result->check);
                $this->assertStringNotContainsString('proxypass-91bc', $text, $result->check);
            }
        }
        $named = self::by_name($results);
        $this->assertStringContainsString('proxy.example.ac.jp:3128', $named['proxy']->detail);
    }

    public function test_name_problem(): void {
        $guard = network_fakes::guard(null, [], ['13.225.9.9']);
        $named = self::by_name(self::runner([], make_request_directory(), [], $guard)->run(self::endpoint()));
        $this->assertSame(check_result::FAIL, $named['dns']->result);
        $this->assertStringContainsString(get_string('diag_dns_noanswer', 'tool_secbackup'), $named['dns']->detail);
        $this->assertStringContainsString(get_string('diag_dns_noaddress', 'tool_secbackup'), $named['dns']->detail);
        $this->assertSame(get_string('dns_not_found', 'tool_secbackup'), $named['dns']->advice);
        $this->assertSame(check_result::FAIL, $named['connection']->result);
        $this->assertSame(get_string('dns_not_found', 'tool_secbackup'), $named['connection']->detail);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_something_else_answers(): void {
        $runner = self::runner([new Response(404, [], '<html></html>')], make_request_directory());
        $named = self::by_name($runner->run(self::endpoint()));
        $this->assertSame(check_result::WARN, $named['connection']->result);
        $this->assertSame(get_string('diag_connection_other', 'tool_secbackup', 404), $named['connection']->detail);
    }

    /**
     * Answers to the request without a signature that are not the service's.
     *
     * @return array
     */
    public static function other_answer_provider(): array {
        return [
            'the right code with another status' => [200, '{"error":{"code":"signature_required"}}'],
            'the right status with another code' => [401, '{"error":{"code":"signature_invalid"}}'],
            'the right status without a code' => [401, '{"error":{}}'],
            'the right status with an error that is not a list' => [401, '{"error":"signature_required"}'],
            'the right status without an error' => [401, '{"code":"signature_required"}'],
            'the right status with a body that is not JSON' => [401, 'signature_required'],
            'the right status with an empty body' => [401, ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('other_answer_provider')]
    /**
     * Only a 401 with the code signature_required shows the service.
     *
     * @dataProvider other_answer_provider
     * @param int $status HTTP status.
     * @param string $body Body.
     */
    public function test_only_the_services_own_answer_passes(int $status, string $body): void {
        $runner = self::runner([new Response($status, [], $body)], make_request_directory());
        $named = self::by_name($runner->run(self::endpoint()));
        $this->assertSame(check_result::WARN, $named['connection']->result);
        $this->assertSame(get_string('diag_connection_other', 'tool_secbackup', $status), $named['connection']->detail);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_selinux_enforcing_and_a_failed_connection(): void {
        $named = self::by_name(self::runner([self::failure(7)], system_roots::nfs('1'))->run(self::endpoint()));
        $this->assertSame(check_result::INFO, $named['selinux']->result);
        $this->assertSame(get_string('diag_selinux_enforcing', 'tool_secbackup', 'httpd_t'), $named['selinux']->detail);
        $this->assertSame(get_string('diag_selinux_advice', 'tool_secbackup'), $named['selinux']->advice);
        $this->assertSame(check_result::FAIL, $named['connection']->result);
        $this->assertSame(get_string('transport_connect', 'tool_secbackup'), $named['connection']->detail);
        $this->assertSame(get_string('diag_selinux_advice', 'tool_secbackup'), $named['connection']->advice);
    }

    public function test_selinux_enforcing_does_not_explain_another_failure(): void {
        // Error 60 is a certificate problem: SELinux would not cause it.
        $named = self::by_name(self::runner([self::failure(60)], system_roots::nfs('1'))->run(self::endpoint()));
        $this->assertSame(check_result::FAIL, $named['connection']->result);
        $this->assertSame(get_string('transport_tls', 'tool_secbackup'), $named['connection']->detail);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_selinux_permissive_does_not_explain_a_failed_connection(): void {
        $named = self::by_name(self::runner([self::failure(7)], system_roots::nfs('0'))->run(self::endpoint()));
        $this->assertSame(check_result::FAIL, $named['connection']->result);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_selinux_enforcing_and_no_dns_over_https(): void {
        $guard = network_fakes::guard(null, null, ['13.225.9.9']);
        $named = self::by_name(self::runner([], system_roots::nfs('1'), [], $guard)->run(self::endpoint()));
        $this->assertSame(check_result::FAIL, $named['dns']->result);
        $this->assertSame(
            get_string('dns_doh_unavailable', 'tool_secbackup') . ' ' . get_string('diag_selinux_advice', 'tool_secbackup'),
            $named['dns']->advice
        );
        $this->assertSame(get_string('dns_doh_unavailable', 'tool_secbackup'), $named['connection']->detail);
        $this->assertSame(get_string('diag_selinux_advice', 'tool_secbackup'), $named['connection']->advice);
    }

    public function test_selinux_enforcing_does_not_explain_another_name_problem(): void {
        $guard = network_fakes::guard(null, [], ['13.225.9.9']);
        $named = self::by_name(self::runner([], system_roots::nfs('1'), [], $guard)->run(self::endpoint()));
        $this->assertSame(get_string('dns_not_found', 'tool_secbackup'), $named['dns']->advice);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_selinux_permissive_does_not_explain_no_dns_over_https(): void {
        $guard = network_fakes::guard(null, null, ['13.225.9.9']);
        $named = self::by_name(self::runner([], system_roots::nfs('0'), [], $guard)->run(self::endpoint()));
        $this->assertSame(get_string('dns_doh_unavailable', 'tool_secbackup'), $named['dns']->advice);
        $this->assertSame('', $named['connection']->advice);
    }

    public function test_selinux_permissive_and_nfs(): void {
        global $CFG;
        $named = self::by_name(self::runner([self::unsigned_answer()], system_roots::nfs('0'))->run(self::endpoint()));
        $this->assertSame(check_result::INFO, $named['selinux']->result);
        $this->assertSame(get_string('diag_selinux_permissive', 'tool_secbackup', 'httpd_t'), $named['selinux']->detail);
        $this->assertSame('', $named['selinux']->advice);
        $this->assertSame('', $named['connection']->advice);
        $this->assertSame(check_result::WARN, $named['moodledata']->result);
        $this->assertSame(
            get_string('diag_moodledata', 'tool_secbackup', (object) [
                'type' => 'nfs4',
                'source' => 'files.example.ac.jp:/export/moodle',
                'mount' => dirname(realpath($CFG->dataroot)),
            ]),
            $named['moodledata']->detail
        );
        $this->assertSame(get_string('diag_moodledata_network', 'tool_secbackup'), $named['moodledata']->advice);
    }

    /**
     * The content of /sys/fs/selinux/enforce that means SELinux is not known to be in use.
     *
     * @return array
     */
    public static function unknown_selinux_provider(): array {
        return [
            'no file' => [null],
            'disabled' => ['2'],
            'empty' => [''],
            'text' => ['yes'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknown_selinux_provider')]
    /**
     * Anything but 1 and 0 reads as unknown, and the process type is not reported.
     *
     * @dataProvider unknown_selinux_provider
     * @param string|null $enforce The content of the file, or null for none.
     */
    public function test_selinux_unknown(?string $enforce): void {
        $root = system_roots::nfs('0');
        if ($enforce === null) {
            unlink($root . '/sys/fs/selinux/enforce');
        } else {
            file_put_contents($root . '/sys/fs/selinux/enforce', $enforce);
        }
        $named = self::by_name(self::runner([self::unsigned_answer()], $root)->run(self::endpoint()));
        $this->assertSame(check_result::UNKNOWN, $named['selinux']->result);
        $this->assertSame(get_string('diag_selinux_unknown', 'tool_secbackup'), $named['selinux']->detail);
        $this->assertSame('', $named['selinux']->advice);
    }

    /**
     * What the process context can be.
     *
     * @return array
     */
    public static function context_provider(): array {
        return [
            'a context' => ["system_u:system_r:httpd_t:s0\0", 'httpd_t'],
            'a context with a line feed' => ["system_u:system_r:php_fpm_t:s0\n", 'php_fpm_t'],
            'a context without a type' => ["unconfined\0", null],
            'an empty context' => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('context_provider')]
    /**
     * The type is the third part of the context; without one the type reads as unknown.
     *
     * @dataProvider context_provider
     * @param string $context The content of /proc/self/attr/current.
     * @param string|null $type The type that is reported, or null for unknown.
     */
    public function test_selinux_process_type(string $context, ?string $type): void {
        $root = system_roots::nfs('1');
        file_put_contents($root . '/proc/self/attr/current', $context);
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(
            get_string('diag_selinux_enforcing', 'tool_secbackup', $type ?? get_string('result_unknown', 'tool_secbackup')),
            $named['selinux']->detail
        );
    }

    public function test_the_process_type_is_unknown_when_the_file_cannot_be_read(): void {
        $root = system_roots::nfs('0');
        unlink($root . '/proc/self/attr/current');
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(
            get_string('diag_selinux_permissive', 'tool_secbackup', get_string('result_unknown', 'tool_secbackup')),
            $named['selinux']->detail
        );
    }

    public function test_clock(): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            'https://app.example.test/api/lms-backup/v1',
            1760000000,
            30
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame(check_result::PASS, $named['clock']->result);
        $this->assertSame(get_string('diag_clock_seconds', 'tool_secbackup', 30), $named['clock']->detail);
        $this->assertSame('', $named['clock']->advice);

        state::record_poll(1760000300, new status_result('active', 1760000000, -300, 0));
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame(check_result::WARN, $named['clock']->result);
        $this->assertSame(get_string('diag_clock_seconds', 'tool_secbackup', 300), $named['clock']->detail);
        $this->assertSame(get_string('clock_skew_warning', 'tool_secbackup', 5), $named['clock']->advice);
    }

    /**
     * The clock difference and the result it gives: the warning starts at two minutes, in either direction.
     *
     * @return array
     */
    public static function clock_difference_provider(): array {
        return [
            'just under two minutes' => [119, check_result::PASS],
            'two minutes' => [120, check_result::WARN],
            'just under two minutes, this server ahead' => [-119, check_result::PASS],
            'two minutes, this server ahead' => [-120, check_result::WARN],
            'no difference' => [0, check_result::PASS],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clock_difference_provider')]
    /**
     * The result of the clock check from the difference measured last.
     *
     * @dataProvider clock_difference_provider
     * @param int $difference The service's clock minus this server's clock, in seconds.
     * @param string $expected The result.
     */
    public function test_clock_difference(int $difference, string $expected): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            'https://app.example.test/api/lms-backup/v1',
            1760000000,
            $difference
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
        $named = self::by_name(self::runner([], make_request_directory())->run(null));
        $this->assertSame($expected, $named['clock']->result);
        $this->assertSame(get_string('diag_clock_seconds', 'tool_secbackup', abs($difference)), $named['clock']->detail);
    }

    public function test_moodledata_on_a_local_file_system_is_information(): void {
        global $CFG;
        $root = make_request_directory();
        mkdir($root . '/proc/self', 0777, true);
        file_put_contents($root . '/proc/self/mountinfo', "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n");
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(check_result::INFO, $named['moodledata']->result);
        $this->assertSame(
            get_string('diag_moodledata', 'tool_secbackup', (object) ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/']),
            $named['moodledata']->detail
        );
        $this->assertSame('', $named['moodledata']->advice);
    }

    public function test_moodledata_is_found_through_a_symbolic_link(): void {
        global $CFG;
        $real = make_request_directory() . '/real data';
        mkdir($real);
        $link = make_request_directory() . '/data-link';
        symlink($real, $link);
        $CFG->dataroot = $link;
        $root = make_request_directory();
        mkdir($root . '/proc/self', 0777, true);
        file_put_contents(
            $root . '/proc/self/mountinfo',
            "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n35 22 0:40 / " . str_replace(' ', '\\040', realpath($real)) .
            " rw - nfs4 server:/x rw\n"
        );
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(check_result::WARN, $named['moodledata']->result);
        $this->assertStringContainsString(realpath($real), $named['moodledata']->detail);
    }

    public function test_moodledata_is_unknown_when_no_mount_holds_it(): void {
        $root = make_request_directory();
        mkdir($root . '/proc/self', 0777, true);
        file_put_contents($root . '/proc/self/mountinfo', "garbage\n");
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(check_result::UNKNOWN, $named['moodledata']->result);
        $this->assertSame(get_string('diag_moodledata_unknown', 'tool_secbackup'), $named['moodledata']->detail);
        $this->assertSame('', $named['moodledata']->advice);
    }

    /**
     * Every file system type that is reported as a network file system.
     *
     * @return array
     */
    public static function network_type_provider(): array {
        $types = [
            'nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'ceph', 'glusterfs', '9p', 'fuse.s3fs', 'fuse.sshfs', 'fuse.glusterfs',
            'fuse.rclone', 'fuse.goofys', 'fuse.mountpoint-s3',
        ];
        return array_map(static fn(string $type): array => [$type], array_combine($types, $types));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('network_type_provider')]
    /**
     * A network file system is a warning.
     *
     * @dataProvider network_type_provider
     * @param string $type The file system type.
     */
    public function test_network_file_systems_are_a_warning(string $type): void {
        $root = make_request_directory();
        mkdir($root . '/proc/self', 0777, true);
        file_put_contents($root . '/proc/self/mountinfo', "22 1 8:1 / / rw - $type server:/x rw\n");
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(check_result::WARN, $named['moodledata']->result, $type);
    }

    /**
     * Local file system types that must not be a warning.
     *
     * @return array
     */
    public static function local_type_provider(): array {
        return [
            'ext4' => ['ext4'], 'xfs' => ['xfs'], 'btrfs' => ['btrfs'], 'overlay' => ['overlay'], 'tmpfs' => ['tmpfs'],
            'a name that starts like a network type' => ['nfsd'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('local_type_provider')]
    /**
     * A local file system is information only.
     *
     * @dataProvider local_type_provider
     * @param string $type The file system type.
     */
    public function test_local_file_systems_are_information(string $type): void {
        $root = make_request_directory();
        mkdir($root . '/proc/self', 0777, true);
        file_put_contents($root . '/proc/self/mountinfo', "22 1 8:1 / / rw - $type server:/x rw\n");
        $named = self::by_name(self::runner([], $root)->run(null));
        $this->assertSame(check_result::INFO, $named['moodledata']->result, $type);
    }

    /**
     * Mount tables and the mount expected for /srv/moodledata/files.
     *
     * @return array
     */
    public static function mount_provider(): array {
        $root = "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n";
        return [
            'root only' => [$root, ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/']],
            'longest prefix' => [
                $root . "30 22 0:40 / /srv rw - xfs /dev/sdb1 rw\n" .
                "31 30 0:41 / /srv/moodledata rw - fuse.s3fs s3fs rw\n",
                ['type' => 'fuse.s3fs', 'source' => 's3fs', 'mount' => '/srv/moodledata'],
            ],
            'longest prefix listed first' => [
                "31 30 0:41 / /srv/moodledata rw - fuse.s3fs s3fs rw\n" . $root . "30 22 0:40 / /srv rw - xfs /dev/sdb1 rw\n",
                ['type' => 'fuse.s3fs', 'source' => 's3fs', 'mount' => '/srv/moodledata'],
            ],
            'the path itself is a mount point' => [
                $root . "30 22 0:40 / /srv/moodledata/files rw - nfs server:/x rw\n",
                ['type' => 'nfs', 'source' => 'server:/x', 'mount' => '/srv/moodledata/files'],
            ],
            'a mount below the path does not hold it' => [
                $root . "30 22 0:40 / /srv/moodledata/files/cache rw - nfs server:/x rw\n",
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'similar name is not a prefix' => [
                $root . "30 22 0:40 / /srv/moodle rw - nfs server:/x rw\n",
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'a longer name is not a prefix' => [
                $root . "30 22 0:40 / /srv/moodledata/files2 rw - nfs server:/x rw\n",
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'no root mount' => [
                "30 22 0:40 / /srv/moodledata rw - nfs server:/x rw\n",
                ['type' => 'nfs', 'source' => 'server:/x', 'mount' => '/srv/moodledata'],
            ],
            'nothing holds the path' => ["30 22 0:40 / /mnt rw - nfs server:/x rw\n", null],
            'escaped space' => [
                $root . "30 22 0:40 / /srv/moodledata rw shared:1 - cifs //server/a\\040share rw\n",
                ['type' => 'cifs', 'source' => '//server/a share', 'mount' => '/srv/moodledata'],
            ],
            'a mount inside a mount' => [
                $root . "30 22 0:40 / /srv/moodledata rw - nfs server:/x rw\n" .
                "31 30 0:41 / /srv/moodledata/files rw - ext4 /dev/sdc1 rw\n",
                ['type' => 'ext4', 'source' => '/dev/sdc1', 'mount' => '/srv/moodledata/files'],
            ],
            'an escaped backslash' => [
                $root . "30 22 0:40 / /srv/moodledata rw - nfs a\\134b rw\n",
                ['type' => 'nfs', 'source' => 'a\\b', 'mount' => '/srv/moodledata'],
            ],
            'an escape that is not three octal digits stays' => [
                $root . "30 22 0:40 / /srv/moodledata rw - nfs a\\04b rw\n",
                ['type' => 'nfs', 'source' => 'a\\04b', 'mount' => '/srv/moodledata'],
            ],
            'a line without the separator is skipped' => [
                "30 22 0:40 / /srv/moodledata rw nfs server:/x rw\n" . $root,
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'a line with too few fields before the separator is skipped' => [
                "30 22 0:40 / - nfs server:/x rw\n" . $root,
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'a line with too few fields after the separator is skipped' => [
                "30 22 0:40 / /srv/moodledata rw - nfs\n" . $root,
                ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            ],
            'exactly the fields a line needs' => [
                "30 22 0:40 / /srv/moodledata - nfs server:/x\n" . $root,
                ['type' => 'nfs', 'source' => 'server:/x', 'mount' => '/srv/moodledata'],
            ],
            'broken lines are skipped' => ["garbage\n1 2 3\n", null],
            'an empty table' => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mount_provider')]
    /**
     * The mount that holds the path.
     *
     * @dataProvider mount_provider
     * @param string $mountinfo Content of /proc/self/mountinfo.
     * @param array|null $expected Expected mount.
     */
    public function test_mount_of(string $mountinfo, ?array $expected): void {
        $this->assertSame($expected, runner::mount_of('/srv/moodledata/files', $mountinfo));
    }

    public function test_mount_of_the_root_directory_itself(): void {
        $this->assertSame(
            ['type' => 'ext4', 'source' => '/dev/sda1', 'mount' => '/'],
            runner::mount_of('/', "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n30 22 0:40 / /srv rw - nfs server:/x rw\n")
        );
    }

    public function test_create_uses_the_site_settings(): void {
        $this->assertInstanceOf(runner::class, runner::create());
    }

    public function test_the_texts_are_plain(): void {
        // A text of the system is kept as it is read; the page and the command line decide how to print it.
        $named = self::by_name(self::runner([], system_roots::with_markup())->run(null));
        $this->assertSame(
            get_string('diag_selinux_enforcing', 'tool_secbackup', '<i>x</i>'),
            $named['selinux']->detail
        );
        $this->assertStringContainsString('<u>C&D</u>', $named['moodledata']->detail);
        $this->assertStringContainsString('<b>A&B</b>', $named['moodledata']->detail);
    }
}
