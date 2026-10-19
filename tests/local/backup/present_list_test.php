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

namespace tool_secbackup\local\backup;

use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\present_list::class)]
/**
 * Tests for the list of the chunks that the storage holds.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\present_list
 */
final class present_list_test extends \advanced_testcase {
    /** @var string Site ID of the tests. */
    private const SITE = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Writes a gzip file.
     *
     * @param string $text The text.
     * @return string Path.
     */
    private function gz(string $text): string {
        $path = make_request_directory() . '/present';
        file_put_contents($path, gzencode($text));
        return $path;
    }

    /**
     * A good header.
     *
     * @param int $count Number of lines after it.
     * @param array $override Fields that replace those of the header.
     * @return string The line, with a line feed.
     */
    private static function header(int $count, array $override = []): string {
        return json_encode($override + ['v' => 1, 'site_id' => self::SITE, 'generated_at' => '2026-10-18T09:00:00Z',
            'count' => $count]) . "\n";
    }

    public function test_the_list_is_read_into_names_and_sizes(): void {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $list = present_list::parse($this->gz(self::header(2) . $a . " 10\n" . $b . " 20\n"), self::SITE);
        $this->assertSame([$a => 10, $b => 20], $list);
    }

    public function test_a_list_without_chunks_is_empty(): void {
        $this->assertSame([], present_list::parse($this->gz(self::header(0)), self::SITE));
    }

    public function test_a_list_that_was_not_fetched_is_empty(): void {
        // A first run: the storage has no list yet (404), and the file is not there.
        $this->assertSame([], present_list::parse(make_request_directory() . '/none', self::SITE));
    }

    /**
     * Lists that are not the list of this site.
     *
     * @return array
     */
    public static function bad_list_provider(): array {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        return [
            'another site' => [self::header(1, ['site_id' => '33333333-3333-4333-8333-333333333333']) . $a . " 1\n"],
            'another version' => [self::header(1, ['v' => 2]) . $a . " 1\n"],
            'a version as text' => [self::header(1, ['v' => '1']) . $a . " 1\n"],
            'no site' => [json_encode(['v' => 1, 'count' => 0]) . "\n"],
            'a header that is not JSON' => ["not json\n"],
            'a header that is a list' => ["[1]\n"],
            'an empty text' => [''],
            'a count that is text' => [self::header(1, ['count' => '1']) . $a . " 1\n"],
            'a negative count' => [self::header(-1)],
            'a count beyond what is read' => [self::header(10000001)],
            'fewer lines than counted' => [self::header(2) . $a . " 1\n"],
            'more lines than counted' => [self::header(1) . $a . " 1\n" . $b . " 1\n"],
            'a line without a size' => [self::header(1) . $a . "\n"],
            'a line with a short name' => [self::header(1) . "abc 1\n"],
            'a line with a name in capitals' => [self::header(1) . str_repeat('A', 64) . " 1\n"],
            'a size of zero' => [self::header(1) . $a . " 0\n"],
            'a size with a sign' => [self::header(1) . $a . " +1\n"],
            'a size with a leading zero' => [self::header(1) . $a . " 01\n"],
            'a size beyond what a number holds' => [self::header(1) . $a . " 9007199254740992\n"],
            'a size with a space after it' => [self::header(1) . $a . " 1 \n"],
            'names out of order' => [self::header(2) . $b . " 1\n" . $a . " 1\n"],
            'a name twice' => [self::header(2) . $a . " 1\n" . $a . " 2\n"],
            'a last line without its line feed' => [self::header(1) . $a . ' 1'],
            'a line that never ends' => [self::header(1) . str_repeat('a', 500)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_list_provider')]
    /**
     * A list that is not the list of this site, or not in its form, stops the run with signature_invalid.
     *
     * @dataProvider bad_list_provider
     * @param string $text The text of the list.
     */
    public function test_a_list_that_is_not_the_list_of_this_site_is_a_signature_failure(string $text): void {
        try {
            present_list::parse($this->gz($text), self::SITE);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('signature_invalid', $e->errorcode);
        }
    }

    public function test_a_file_that_is_not_gzip_is_a_signature_failure(): void {
        $path = make_request_directory() . '/present';
        file_put_contents($path, "plain text\n");
        $this->expectException(plugin_exception::class);
        present_list::parse($path, self::SITE);
    }

    public function test_a_truncated_gzip_is_a_signature_failure(): void {
        $path = make_request_directory() . '/present';
        $gz = gzencode(self::header(1) . str_repeat('a', 64) . " 1\n");
        file_put_contents($path, substr($gz, 0, -6));
        try {
            present_list::parse($path, self::SITE);
            $this->fail('No exception');
        } catch (plugin_exception $e) {
            $this->assertSame('signature_invalid', $e->errorcode);
        }
    }

    public function test_the_site_of_the_argument_is_a_uuid(): void {
        $this->expectException(\coding_exception::class);
        present_list::parse($this->gz(self::header(0)), 'x');
    }
}
