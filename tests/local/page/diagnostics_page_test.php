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

namespace tool_secbackup\local\page;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use tool_secbackup\form\diagnostics_form;
use tool_secbackup\local\api\enroll_result;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\diagnostics\system_files;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\net\transport;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\network_fakes;
use tool_secbackup\tests\system_roots;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\diagnostics_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\page\access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\form\diagnostics_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\output\renderer::class)]
/**
 * Tests for the diagnostics page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\page\diagnostics_page
 * @covers     \tool_secbackup\local\page\access
 * @covers     \tool_secbackup\form\diagnostics_form
 * @covers     \tool_secbackup\output\renderer
 */
final class diagnostics_page_test extends \advanced_testcase {
    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        network_fakes::no_security_list();
        $PAGE->set_url(new \moodle_url('/admin/tool/secbackup/diagnostics.php'));
        $PAGE->set_context(\context_system::instance());
    }

    /**
     * A runner whose network answers like the service.
     *
     * @param MockHandler $mock Mocked network.
     * @param string|null $root Root of the system files; null for an empty one.
     * @return runner
     */
    private static function runner(MockHandler $mock, ?string $root = null): runner {
        $settings = developer_settings::from_value(null);
        $guard = network_fakes::passing_guard();
        return new runner(
            $settings,
            $guard,
            new transport($settings, $guard, $mock),
            new system_files($root ?? make_request_directory())
        );
    }

    /**
     * Registers the site.
     *
     * @param string $api The connection URL.
     */
    private static function register(string $api = self::API): void {
        $result = new enroll_result(
            '11111111-2222-4333-8444-555555555555',
            'Example site',
            'Example Platform',
            $api,
            1760000000,
            0
        );
        state::save_enrollment(
            $result,
            'secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=',
            signing_keypair::generate(),
            1760000000
        );
    }

    /**
     * Checks that a text came out escaped once: the escaped text is there, the raw text and the twice escaped text are not.
     *
     * @param string $html The page.
     * @param string $raw The text as it was read.
     * @param string $escaped The text escaped once.
     */
    private function assert_escaped_once(string $html, string $raw, string $escaped): void {
        $this->assertStringContainsString($escaped, $html);
        $this->assertStringNotContainsString($raw, $html);
        $this->assertStringNotContainsString(str_replace('&', '&amp;', $escaped), $html);
    }

    public function test_registered_site_is_checked_at_once(): void {
        self::register();
        $mock = new MockHandler([new Response(401, [], '{"error":{"code":"signature_required"}}')]);
        $page = new diagnostics_page(self::runner($mock));
        $page->handle();
        $html = $page->render();
        // The table escapes the texts, so the apostrophe of "service's" is an entity in the HTML.
        $this->assertStringContainsString(s(get_string('diag_connection_ok', 'tool_secbackup', 401)), $html);
        $this->assertStringContainsString(get_string('result_pass', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString('name="endpoint"', $html);
        $this->assertSame(self::API . '/status', (string) $mock->getLastRequest()->getUri());
    }

    public function test_every_check_is_in_the_table_with_its_headings(): void {
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $html = $page->render();
        foreach (['developer', 'extensions', 'proxy', 'dns', 'connection', 'clock', 'selinux', 'moodledata'] as $check) {
            $this->assertStringContainsString(get_string('check_' . $check, 'tool_secbackup'), $html, $check);
        }
        foreach (['check', 'result', 'detail', 'advice'] as $heading) {
            $this->assertStringContainsString(get_string('diag_heading_' . $heading, 'tool_secbackup'), $html, $heading);
        }
    }

    public function test_the_top_of_the_page_is_there(): void {
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $html = $page->render();
        $this->assertStringContainsString(get_string('intro', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('trademark', 'tool_secbackup'), $html);
        $this->assertStringNotContainsString(get_string('developer_banner', 'tool_secbackup'), $html);
    }

    public function test_the_page_warns_while_developer_settings_are_active(): void {
        global $CFG;
        $CFG->tool_secbackup_developer = ['allowhttp' => true];
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $this->assertStringContainsString(get_string('developer_banner', 'tool_secbackup'), $page->render());
    }

    public function test_unregistered_site_asks_for_a_url(): void {
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $html = $page->render();
        $this->assertStringContainsString('name="endpoint"', $html);
        $this->assertStringContainsString(get_string('diag_no_endpoint', 'tool_secbackup'), $html);
        $this->assertStringContainsString(get_string('diag_run', 'tool_secbackup'), $html);
    }

    public function test_typed_url_is_checked(): void {
        diagnostics_form::mock_submit(['endpoint' => self::API]);
        $mock = new MockHandler([new Response(404, [], '')]);
        $page = new diagnostics_page(self::runner($mock));
        $page->handle();
        $this->assertStringContainsString(s(get_string('diag_connection_other', 'tool_secbackup', 404)), $page->render());
        $this->assertSame(self::API . '/status', (string) $mock->getLastRequest()->getUri());
    }

    public function test_wrong_typed_url_is_explained(): void {
        diagnostics_form::mock_submit(['endpoint' => 'http://app.example.test/api']);
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $this->assertStringContainsString(get_string('endpoint_scheme', 'tool_secbackup'), $page->render());
    }

    public function test_wrong_typed_url_is_explained_escaped_once(): void {
        // The message of this error holds quotation marks, and a validation error is printed as HTML.
        diagnostics_form::mock_submit(['endpoint' => 'https://app.example.test/api?x=1']);
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $message = get_string('endpoint_query', 'tool_secbackup');
        $this->assertStringContainsString('"', $message);
        $this->assert_escaped_once($page->render(), $message, s($message));
    }

    public function test_saved_url_that_no_longer_reads(): void {
        self::register('http://localhost:8099/api/lms-backup/v1');
        $page = new diagnostics_page(self::runner(new MockHandler([])));
        $page->handle();
        $this->assertStringContainsString(get_string('endpoint_scheme', 'tool_secbackup'), $page->render());
    }

    public function test_the_text_of_the_system_is_escaped_once(): void {
        $root = system_roots::with_markup();
        $page = new diagnostics_page(self::runner(new MockHandler([]), $root));
        $page->handle();
        $html = $page->render();
        $this->assert_escaped_once($html, '<b>A&B</b>', '&lt;b&gt;A&amp;B&lt;/b&gt;');
        $this->assert_escaped_once($html, '<u>C&D</u>', '&lt;u&gt;C&amp;D&lt;/u&gt;');
        $this->assert_escaped_once($html, '<i>x</i>', '&lt;i&gt;x&lt;/i&gt;');
    }

    public function test_the_advice_and_the_detail_of_a_failure_are_escaped_once(): void {
        // The message of this problem holds an apostrophe. It is the advice of the name check and the detail of the
        // connection check.
        self::register();
        $settings = developer_settings::from_value(null);
        $guard = network_fakes::guard(['13.225.1.1'], ['13.225.1.2'], ['203.0.113.55']);
        $runner = new runner(
            $settings,
            $guard,
            new transport($settings, $guard, new MockHandler([])),
            new system_files(make_request_directory())
        );
        $page = new diagnostics_page($runner);
        $page->handle();
        $message = get_string('dns_os_mismatch', 'tool_secbackup');
        $this->assertStringContainsString("'", $message);
        $html = $page->render();
        $this->assert_escaped_once($html, $message, s($message));
        $this->assertSame(2, substr_count($html, s($message)), 'Once as the advice and once as the detail.');
    }

    public function test_default_runner(): void {
        $page = new diagnostics_page();
        $page->handle();
        $this->assertStringContainsString(get_string('check_extensions', 'tool_secbackup'), $page->render());
    }

    /**
     * The users who have only one of the two capabilities.
     *
     * @return array
     */
    public static function one_capability_provider(): array {
        return [
            'only the right to manage the connection' => ['tool/secbackup:manage'],
            'only the right to configure the site' => ['moodle/site:config'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('one_capability_provider')]
    /**
     * The page needs both capabilities.
     *
     * @dataProvider one_capability_provider
     * @param string $capability The only capability the user has.
     */
    public function test_needs_both_capabilities(string $capability): void {
        $user = $this->getDataGenerator()->create_user();
        $role = create_role('Partial manager', 'partialmanager', '');
        assign_capability($capability, CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        new diagnostics_page();
    }

    public function test_the_page_links_to_the_diagnostics_from_the_status_page(): void {
        global $PAGE;
        $html = $PAGE->get_renderer('tool_secbackup')->links();
        $this->assertStringContainsString('href="https://www.example.com/moodle/admin/tool/secbackup/diagnostics.php"', $html);
        $this->assertStringContainsString(get_string('page_diagnostics', 'tool_secbackup'), $html);
    }
}
