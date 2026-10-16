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
    /** @var string[] What "the platform" is, said at its first mention on a screen, by language. */
    private const PLATFORM = [
        'en' => 'the platform (the service where the backups were ordered, such as Shinonome Education Cloud)',
        'ja' => 'プラットフォーム (バックアップを申し込んだサービス。Shinonome Education Cloud など)',
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
}
