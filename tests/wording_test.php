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

namespace tool_secbackup;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests that a school administrator reads some lines once and knows whether all is well and what to do.
 *
 * The language files are read directly, as the site of the tests may not have the Japanese language pack.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class wording_test extends \basic_testcase {
    /**
     * @var string[] What "the platform" is, said at its first mention on a screen, by language. It names no brand: the name of
     * the platform is the one the registration receives, and a reseller's platform has a brand of its own.
     */
    private const PLATFORM = [
        'en' => 'the platform (the service where the backups were ordered)',
        'ja' => 'プラットフォーム (バックアップを申し込んだサービス)',
    ];

    /**
     * The strings of one language.
     *
     * @param string $lang en or ja.
     * @return string[]
     */
    private static function strings(string $lang): array {
        $string = [];
        include(\core_component::get_component_directory('tool_secbackup') . '/lang/' . $lang . '/tool_secbackup.php');
        return $string;
    }

    /**
     * The languages.
     *
     * @return array
     */
    public static function language_provider(): array {
        return ['english' => ['en'], 'japanese' => ['ja']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('language_provider')]
    /**
     * The connection check passes on HTTP 401, so its line says that this answer is the expected one, and why.
     *
     * @dataProvider language_provider
     * @param string $lang The language.
     */
    public function test_the_passed_connection_check_says_that_http_401_is_expected(string $lang): void {
        $text = self::strings($lang)['diag_connection_ok'];
        $words = [
            'en' => ['The service was reached.', 'without a signature', 'HTTP {$a} (authentication required)', 'expected answer'],
            'ja' => ['サービスに届きました。', '署名を付けずに', 'HTTP {$a} (認証が必要)', '正常です'],
        ];
        foreach ($words[$lang] as $word) {
            $this->assertStringContainsString($word, $text);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('language_provider')]
    /**
     * The line of the data directory names a file system and its mount point, instead of "X on Y".
     *
     * @dataProvider language_provider
     * @param string $lang The language.
     */
    public function test_the_data_directory_line_describes_the_file_system(string $lang): void {
        $expected = [
            'en' => 'File system: {$a->type} (mounted at {$a->mount}, from {$a->source})',
            'ja' => 'ファイルシステム: {$a->type} (マウント先: {$a->mount}、元: {$a->source})',
        ];
        $this->assertSame($expected[$lang], self::strings($lang)['diag_moodledata']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('language_provider')]
    /**
     * Before registration, the first mention of the platform on each screen says what it is, and only there.
     *
     * The status page says it in status_not_registered, the first step of the connection settings in the help of the
     * connection URL, and the second step in the box that confirms the fingerprint.
     *
     * @dataProvider language_provider
     * @param string $lang The language.
     */
    public function test_the_first_mention_of_the_platform_on_a_screen_says_what_it_is(string $lang): void {
        $explained = [];
        foreach (self::strings($lang) as $id => $text) {
            if (str_contains($text, self::PLATFORM[$lang])) {
                $explained[] = $id;
            }
        }
        sort($explained);
        $this->assertSame(['field_confirmfingerprint', 'field_endpoint_help', 'status_not_registered'], $explained);
    }

    public function test_the_developer_banner_says_plainly_that_some_checks_are_off(): void {
        $text = self::strings('ja')['developer_banner'];
        $this->assertStringContainsString('接続の安全確認の一部が無効になっています。', $text);
        $this->assertStringNotContainsString('守り', $text);
    }

    public function test_the_headings_of_the_diagnostics_are_nouns(): void {
        $strings = self::strings('ja');
        $this->assertSame('詳細', $strings['diag_heading_detail']);
        $this->assertSame('対処', $strings['diag_heading_advice']);
    }

    public function test_the_japanese_texts_call_one_thing_by_one_name(): void {
        $strings = self::strings('ja');
        foreach ($strings as $id => $text) {
            // The data directory is named as the row of the diagnostics names it.
            $this->assertStringNotContainsString('データディレクトリ', $text, $id);
            $this->assertSame(
                substr_count($text, 'データのフォルダ'),
                substr_count($text, 'データのフォルダ (moodledata)'),
                $id . ': the data directory is named with "(moodledata)".'
            );
            // An HTTP header is a ヘッダー: the administrator of a proxy would not know a 見出し.
            $this->assertStringNotContainsString('見出し', $text, $id);
        }
        $this->assertStringContainsString('X-SecBackup で始まるヘッダー', $strings['api_signature_required']);
        $this->assertStringContainsString('データのフォルダ (moodledata) にある暗号鍵のファイル', $strings['signingkey_savefailed']);
        $this->assertStringContainsString('誤った接続先の URL に', $strings['enroll_reissue_advice']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('language_provider')]
    /**
     * The messages to the administrators name the connection URL as the screens do.
     *
     * @dataProvider language_provider
     * @param string $lang The language.
     */
    public function test_the_messages_name_the_connection_url_as_the_screens_do(string $lang): void {
        $strings = self::strings($lang);
        foreach (['message_deregistered_body', 'message_enrolled_body', 'message_reenrolled_body'] as $id) {
            $this->assertStringContainsString("\n" . $strings['field_endpoint'] . ': {$a->endpoint}' . "\n", $strings[$id], $id);
        }
    }

    public function test_the_trademark_notice_of_the_readme_is_the_one_of_the_plugin(): void {
        // The notice is a fixed text (Trademark Guidelines of Moodle Pty Ltd): the README says it word for word, as the pages do.
        $readme = file_get_contents(\core_component::get_component_directory('tool_secbackup') . '/README.md');
        $found = preg_match('/^## Trademark\n\n(.+?)\n*(?=^## |\z)/ms', $readme, $match);
        $this->assertSame(1, $found, 'README.md has no Trademark section.');
        $this->assertSame(self::strings('en')['trademark'], $match[1]);
    }
}
