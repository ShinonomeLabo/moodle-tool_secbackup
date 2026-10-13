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

namespace tool_secbackup\local\enrollment;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\enrollment\notifier::class)]
/**
 * Tests for the messages to the site administrators.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\enrollment\notifier
 */
final class notifier_test extends \advanced_testcase {
    /** @var string A site name with markup in it. */
    private const SITE = '<b>A&B</b> <a href="https://attacker.example/">confirm</a>';

    /** @var string A platform name with markup in it. */
    private const PLATFORM = '<i>P&Q</i>';

    /** @var string Connection URL. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        // The observers a test replaced are not given back to the next test by every Moodle version.
        \core\event\manager::phpunit_reset();
        parent::tearDown();
    }

    /**
     * Installs the Japanese language pack, as a site with Japanese speaking users has it (the plugin's own Japanese
     * strings are read once Moodle knows the language; a site without the pack falls back to English).
     */
    private function install_japanese_pack(): void {
        global $CFG;
        $root = make_request_directory();
        mkdir($root . '/ja');
        file_put_contents(
            $root . '/ja/langconfig.php',
            "<?php\n\$string['thislanguage'] = 'Japanese';\n\$string['parentlanguage'] = '';\n"
        );
        $CFG->langotherroot = $root;
        $CFG->langlocalroot = $root;
        get_string_manager(true)->reset_caches(true);
    }

    /**
     * Makes another user a site administrator.
     *
     * @param array $record Fields of the user.
     * @return \stdClass The user.
     */
    private function add_admin(array $record = []): \stdClass {
        $user = $this->getDataGenerator()->create_user($record);
        set_config('siteadmins', get_config('core', 'siteadmins') . ',' . $user->id);
        return $user;
    }

    /**
     * The messages by the user they went to.
     *
     * @param \phpunit_message_sink $sink The sink.
     * @return \stdClass[]
     */
    private static function by_user(\phpunit_message_sink $sink): array {
        $byuser = [];
        foreach ($sink->get_messages() as $message) {
            $byuser[(int) $message->useridto] = $message;
        }
        return $byuser;
    }

    /**
     * The three kinds of messages.
     *
     * @return array
     */
    public static function kind_provider(): array {
        return [
            'registered' => ['enrolled', 'This site was registered with Example Platform', 'registered this site with'],
            'registered again' => ['reenrolled', 'This site was registered again with Example Platform',
                'registered this site again with'],
            'removed' => ['deregistered', 'The registration with Example Platform was removed',
                'removed the registration of this site with'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kind_provider')]
    /**
     * Every site administrator is told, with the text of the kind.
     *
     * @dataProvider kind_provider
     * @param string $kind Kind of message.
     * @param string $subject The subject in English.
     * @param string $sentence A sentence only that kind has.
     */
    public function test_every_administrator_is_told_with_the_text_of_the_kind(
        string $kind,
        string $subject,
        string $sentence
    ): void {
        $second = $this->add_admin();
        $messages = $this->redirectMessages();

        notifier::send($kind, 'Example Platform', 'Example site', self::API);

        $byuser = self::by_user($messages);
        $this->assertEqualsCanonicalizing([(int) get_admin()->id, (int) $second->id], array_keys($byuser));
        $this->assertCount(2, $messages->get_messages());
        foreach ($byuser as $message) {
            $this->assertSame($subject, $message->subject);
            $this->assertStringContainsString($sentence, $message->fullmessage);
        }
    }

    public function test_the_stored_message_keeps_the_names_and_its_html_shows_them_as_text(): void {
        $messages = $this->redirectMessages();

        notifier::send('enrolled', self::PLATFORM, self::SITE, self::API);

        $sent = $messages->get_messages();
        $this->assertNotEmpty($sent);
        foreach ($sent as $message) {
            $this->assertStringContainsString(self::PLATFORM, $message->subject);
            $this->assertStringContainsString(self::SITE, $message->fullmessage);
            $this->assertEquals(FORMAT_PLAIN, $message->fullmessageformat);
            foreach (['<b>', '<i>', '<a ', 'href="', '&amp;lt;', '&amp;amp;'] as $live) {
                $this->assertStringNotContainsString($live, $message->fullmessagehtml);
            }
            $this->assertStringContainsString(s(self::SITE), $message->fullmessagehtml);
            $this->assertStringContainsString(s(self::PLATFORM), $message->fullmessagehtml);
            $this->assertStringContainsString('<br />', $message->fullmessagehtml);
        }
    }

    /**
     * Splits an e-mail into its text part and its HTML part, decoded.
     *
     * @param string $body The MIME body.
     * @return string[] The parts by subtype.
     */
    private static function mime_parts(string $body): array {
        $parts = [];
        $body = str_replace("\r\n", "\n", $body);
        preg_match_all('~Content-Type: text/(plain|html);[^\n]*\n[^\n]*\n\n(.*?)(?=\n--b1=)~s', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $parts[$match[1]] = quoted_printable_decode($match[2]);
        }
        return $parts;
    }

    public function test_the_e_mail_shows_the_names_as_text(): void {
        // The e-mail processor runs only outside the transaction that wraps a test.
        $this->preventResetByRollback();
        $sink = $this->redirectEmails();

        notifier::send('enrolled', self::PLATFORM, self::SITE, self::API);

        $emails = $sink->get_messages();
        $this->assertNotEmpty($emails);
        foreach ($emails as $email) {
            $this->assertSame('This site was registered with ' . self::PLATFORM, $email->subject);
            $parts = self::mime_parts($email->body);
            $this->assertArrayHasKey('html', $parts);
            foreach (['<b>', '<i>', '<a href="https://attacker', 'href="https://attacker', '&amp;lt;'] as $live) {
                $this->assertStringNotContainsString($live, $parts['html']);
            }
            $this->assertStringContainsString(s(self::SITE), $parts['html']);
            $this->assertStringContainsString(s(self::PLATFORM), $parts['html']);
            // The text part is made by Moodle from the HTML (html_to_text), which reads the markup of a name as
            // markup. That is Moodle's own treatment of every message, and it is pinned here as it is.
            $this->assertArrayHasKey('plain', $parts);
            $this->assertStringContainsString('Site name on the platform: ', $parts['plain']);
            $this->assertSame(self::TEXT_PART_SITE_LINE, self::line_with($parts['plain'], 'Site name on the platform: '));
        }
    }

    /** @var string What Moodle makes of the site name in the text part of the e-mail. */
    private const TEXT_PART_SITE_LINE = 'Site name on the platform: A&B confirm [1]';

    /**
     * The first line of a text that starts with a prefix.
     *
     * @param string $text The text.
     * @param string $prefix The start of the line.
     * @return string
     */
    private static function line_with(string $text, string $prefix): string {
        foreach (preg_split('~\r?\n~', $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }
        return '';
    }

    /**
     * Who made the change.
     *
     * @return array
     */
    public static function actor_provider(): array {
        return [
            'an English speaking administrator made the change' => ['en'],
            'a Japanese speaking administrator made the change' => ['ja'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actor_provider')]
    /**
     * Each administrator reads the message in their own language, whoever made the change.
     *
     * @dataProvider actor_provider
     * @param string $actorlang Language of the administrator who made the change.
     */
    public function test_each_administrator_reads_the_message_in_their_language(string $actorlang): void {
        $this->install_japanese_pack();
        $english = $this->add_admin(['lang' => 'en']);
        $japanese = $this->add_admin(['lang' => 'ja']);
        $this->setUser($actorlang === 'ja' ? $japanese : $english);
        $messages = $this->redirectMessages();

        notifier::send('enrolled', 'Example Platform', 'Example site', self::API);

        $byuser = self::by_user($messages);
        $this->assertSame('This site was registered with Example Platform', $byuser[(int) $english->id]->subject);
        $this->assertStringContainsString(
            'registered this site with Example Platform',
            $byuser[(int) $english->id]->fullmessage
        );
        $this->assertSame('このサイトを Example Platform に登録しました', $byuser[(int) $japanese->id]->subject);
        $this->assertStringContainsString(
            'このサイトを Example Platform に登録しました。',
            $byuser[(int) $japanese->id]->fullmessage
        );
        $this->assertSame('あんしんLMSバックアップ', $byuser[(int) $japanese->id]->contexturlname);
    }

    public function test_a_change_without_a_user_is_attributed_to_the_system_in_each_language(): void {
        $this->install_japanese_pack();
        $japanese = $this->add_admin(['lang' => 'ja']);
        $this->setUser(0);
        $messages = $this->redirectMessages();

        notifier::send('deregistered', 'Example Platform', 'Example site', self::API);

        $byuser = self::by_user($messages);
        $this->assertStringContainsString('The system removed the registration', $byuser[(int) get_admin()->id]->fullmessage);
        $this->assertStringContainsString('システム が、このサイトの', $byuser[(int) $japanese->id]->fullmessage);
    }

    /**
     * Makes message_send() throw for one administrator: an observer of the event that Moodle triggers when a
     * notification is stored throws an Error for that user.
     *
     * @param int $userid The user whose notification fails.
     */
    private function make_sending_fail_for(int $userid): void {
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\notification_sent',
            'callback' => static function (\core\event\notification_sent $event) use ($userid): void {
                if ((int) $event->relateduserid === $userid) {
                    throw new \Error('Provoked by the test');
                }
            },
            'internal' => false,
            'priority' => 0,
            'includefile' => null,
        ]]);
    }

    public function test_a_message_that_cannot_be_sent_does_not_stop_the_others_or_leave_the_method(): void {
        global $DB;
        // Moodle holds events and messages back while a transaction is open, and PHPUnit wraps each test in one.
        $this->preventResetByRollback();
        $second = $this->add_admin();
        $this->make_sending_fail_for((int) get_admin()->id);

        notifier::send('enrolled', 'Example Platform', 'Example site', self::API);

        $this->assertDebuggingCalled('The message about the connection could not be sent to the user ' . get_admin()->id
            . ': Error', DEBUG_DEVELOPER);
        $this->assertTrue($DB->record_exists('notifications', ['useridto' => $second->id, 'component' => 'tool_secbackup']));
    }

    public function test_a_missing_default_of_the_provider_is_reported_for_each_administrator(): void {
        $this->add_admin();
        unset_config('popup_provider_tool_secbackup_connection_locked', 'message');

        notifier::send('enrolled', 'Example Platform', 'Example site', self::API);

        $this->assertDebuggingCalledCount(2);
    }
}
