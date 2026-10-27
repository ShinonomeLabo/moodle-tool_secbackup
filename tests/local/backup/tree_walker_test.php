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
 * Tests for the walk over a tree of files.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\tree_walker::class)]
/**
 * Tests for the walk over a tree of files.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\tree_walker
 */
final class tree_walker_test extends \advanced_testcase {
    /**
     * A folder with these files.
     *
     * @param string[] $files Content by relative path.
     * @return string The folder.
     */
    private function tree(array $files): string {
        $root = make_request_directory();
        foreach ($files as $path => $content) {
            $dir = dirname($root . '/' . $path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($root . '/' . $path, $content);
        }
        return $root;
    }

    /**
     * All paths that a walker gives, in pages.
     *
     * @param tree_walker $walker The walker.
     * @param int $limit The size of a page.
     * @return string[]
     */
    private static function all(tree_walker $walker, int $limit = 1000): array {
        $paths = [];
        $after = null;
        do {
            $page = $walker->next($after, $limit);
            foreach ($page as $item) {
                $paths[] = $item['path'];
            }
            $after = $paths === [] ? null : end($paths);
        } while ($page !== []);
        return $paths;
    }

    public function test_the_paths_come_in_byte_order_of_the_whole_path(): void {
        $names = ['a/b', 'a-c', 'a.txt', 'a0', 'B', 'a/B', 'a b', 'z', 'a/b.d', 'a/b-e', '_x/y', 'é', 'e'];
        $root = $this->tree(array_fill_keys($names, 'x'));
        $walker = new tree_walker($root, []);
        $expected = $names;
        usort($expected, 'strcmp');
        $this->assertSame($expected, self::all($walker));
        $this->assertSame($expected, self::all($walker, 1));
    }

    public function test_directories_sort_by_their_name_and_a_slash(): void {
        $root = $this->tree(['a-c' => '1', 'a/b' => '2', 'a.d' => '3', 'a0' => '4']);
        $paths = array_column((new tree_walker($root, []))->next(null, 100), 'path');
        $this->assertSame(['a-c', 'a.d', 'a/b', 'a0'], $paths);
        $this->assertSame(['a-c', 'a.d', 'a/b', 'a0'], self::all(new tree_walker($root, []), 1));
    }

    public function test_folders_that_hold_only_smaller_paths_are_not_listed(): void {
        $root = $this->tree(['a/1' => '1', 'b/1' => '2', 'c/1' => '3', 'c/2' => '4', 'd/1' => '5', 'd/sub/2' => '6']);
        $listed = [];
        $lister = static function (string $dir) use (&$listed, $root): array|false {
            $listed[] = substr($dir, strlen($root));
            return self::list_dir($dir);
        };
        $walker = new tree_walker($root, [], $lister);
        $this->assertSame(['c/2', 'd/1'], array_column($walker->next('c/1', 2), 'path'));
        $this->assertSame(['', '/c', '/d'], $listed);
        $listed = [];
        $this->assertSame(['d/sub/2'], array_column($walker->next('d/1', 5), 'path'));
        $this->assertSame(['', '/d', '/d/sub'], $listed);
    }

    public function test_names_that_are_before_the_page_are_not_looked_at(): void {
        $root = $this->tree(['b' => '1', 'c' => '2', 'x/y' => '3']);
        // A name that is gone would be noted if it were looked at; "a" and "b" are before the page, "x" holds the page.
        $lister = static fn(string $dir): array|false => $dir === $root
            ? array_merge(self::list_dir($dir), ['a']) : self::list_dir($dir);
        $walker = new tree_walker($root, [], $lister);
        $this->assertSame(['x/y'], array_column($walker->next('x', 5), 'path'));
        $this->assertSame([], $walker->unreadable());
        $this->assertSame(['c'], array_column($walker->next('b', 1), 'path'));
        $this->assertSame([], $walker->unreadable());
        $this->assertSame(['b', 'c', 'x/y'], array_column($walker->next(null, 10), 'path'));
        $this->assertSame(['a'], $walker->unreadable());
    }

    public function test_pages_continue_after_a_path_and_prune_what_is_smaller(): void {
        $files = [];
        foreach (['a', 'b', 'c', 'd'] as $dir) {
            foreach (['1', '2', '3'] as $file) {
                $files[$dir . '/' . $file] = $dir . $file;
            }
        }
        $root = $this->tree($files);
        $walker = new tree_walker($root, []);
        $all = array_keys($files);
        $this->assertSame($all, self::all($walker, 1000));
        $this->assertSame($all, self::all($walker, 1));
        $this->assertSame($all, self::all($walker, 5));
        $this->assertSame(['b/3', 'c/1'], array_column($walker->next('b/2', 2), 'path'));
        $this->assertSame(['c/1', 'c/2'], array_column($walker->next('b/3', 2), 'path'));
        $this->assertSame(['c/1'], array_column($walker->next('b/zzz', 1), 'path'));
        $this->assertSame([], $walker->next('d/3', 10));
        $this->assertSame([], $walker->next('z', 10));
        $this->assertSame(['d/1'], array_column($walker->next('c/3', 1), 'path'));
        $this->assertSame([], $walker->next(null, 0));
    }

    public function test_a_page_gives_the_size_and_stops_at_the_limit(): void {
        $root = $this->tree(['a' => 'abc', 'b' => '', 'c' => str_repeat('x', 1000)]);
        $page = (new tree_walker($root, []))->next(null, 2);
        $this->assertSame([['path' => 'a', 'size' => 3], ['path' => 'b', 'size' => 0]], $page);
    }

    public function test_excluded_paths_are_left_out_with_what_is_under_them(): void {
        $root = $this->tree([
            'temp/x' => '1', 'temp/deep/y' => '2', 'temporary/z' => '3', 'keep/a' => '4', 'keep/cache/b' => '5',
            'keep/cache2' => '6', 'top.txt' => '7',
        ]);
        $walker = new tree_walker($root, ['temp', 'keep/cache/', '/top.txt']);
        $this->assertSame(['keep/a', 'keep/cache2', 'temporary/z'], self::all($walker));
        $this->assertSame([], $walker->skipped());
        $this->assertSame([], $walker->unreadable());
    }

    public function test_symbolic_links_are_not_followed_and_are_listed(): void {
        $root = $this->tree(['real/f' => '1', 'file' => '2', 'z' => '3']);
        $outside = $this->tree(['secret' => 'x']);
        symlink($root . '/real', $root . '/linkdir');
        symlink($root . '/file', $root . '/linkfile');
        symlink($root . '/nothing', $root . '/dangling');
        symlink($outside, $root . '/real/out');
        $walker = new tree_walker($root, []);
        $this->assertSame(['file', 'real/f', 'z'], self::all($walker));
        $skipped = $walker->skipped();
        sort($skipped);
        $this->assertSame(['dangling', 'linkdir', 'linkfile', 'real/out'], $skipped);
        // Walking again, in pages, does not list a link twice.
        self::all($walker, 1);
        $skipped = $walker->skipped();
        sort($skipped);
        $this->assertSame(['dangling', 'linkdir', 'linkfile', 'real/out'], $skipped);
    }

    public function test_a_link_as_the_root_is_followed_once(): void {
        $root = $this->tree(['a/b' => '1']);
        $link = make_request_directory() . '/link';
        symlink($root, $link);
        $this->assertSame(['a/b'], self::all(new tree_walker($link, [])));
    }

    public function test_sockets_and_pipes_are_left_out_without_a_note(): void {
        $root = $this->tree(['f' => '1']);
        $server = stream_socket_server('unix://' . $root . '/sock', $errno, $errstr);
        $this->assertNotFalse($server);
        $this->assertTrue(posix_mkfifo($root . '/pipe', 0600));
        $walker = new tree_walker($root, []);
        $this->assertSame(['f'], self::all($walker));
        $this->assertSame([], $walker->skipped());
        $this->assertSame([], $walker->unreadable());
        fclose($server);
    }

    public function test_a_folder_that_cannot_be_listed_is_noted_and_the_walk_goes_on(): void {
        $root = $this->tree(['a/1' => '1', 'b/2' => '2', 'c/3' => '3']);
        $lister = static fn(string $dir): array|false => $dir === $root . '/b' ? false : self::list_dir($dir);
        $walker = new tree_walker($root, [], $lister);
        $this->assertSame(['a/1', 'c/3'], self::all($walker));
        $this->assertSame(['b'], $walker->unreadable());
        self::all($walker, 1);
        $this->assertSame(['b'], $walker->unreadable());
    }

    public function test_a_root_that_cannot_be_listed_is_noted_as_a_dot(): void {
        $walker = new tree_walker(make_request_directory() . '/missing', []);
        $this->assertSame([], $walker->next(null, 10));
        $this->assertSame(['.'], $walker->unreadable());
    }

    public function test_an_entry_that_is_gone_when_it_is_looked_at_is_noted(): void {
        $root = $this->tree(['a' => '1', 'c' => '3']);
        $lister = static fn(string $dir): array|false => array_merge(self::list_dir($dir), ['b']);
        $walker = new tree_walker($root, [], $lister);
        $this->assertSame(['a', 'c'], self::all($walker));
        $this->assertSame(['b'], $walker->unreadable());
    }

    public function test_names_that_the_list_cannot_hold_are_noted_in_hex_and_not_returned(): void {
        $root = $this->tree(['ok' => '1', 'dir/ok2' => '2']);
        file_put_contents($root . "/line\nbreak", 'x');
        file_put_contents($root . "/bell\x07", 'x');
        file_put_contents($root . "/del\x7f", 'x');
        file_put_contents($root . "/bad\xff\xfeutf", 'x');
        mkdir($root . "/dir/sub\x01");
        file_put_contents($root . "/dir/sub\x01/inside", 'x');
        $walker = new tree_walker($root, []);
        $this->assertSame(['dir/ok2', 'ok'], self::all($walker));
        $expected = [
            'hex:' . bin2hex("bad\xff\xfeutf"), 'hex:' . bin2hex("bell\x07"), 'hex:' . bin2hex("del\x7f"),
            'hex:' . bin2hex("dir/sub\x01"), 'hex:' . bin2hex("line\nbreak"),
        ];
        $unreadable = $walker->unreadable();
        sort($unreadable);
        $this->assertSame($expected, $unreadable);
    }

    public function test_a_name_with_valid_utf8_and_spaces_is_returned(): void {
        $root = $this->tree(['日本語/ファイル 1.txt' => 'x']);
        $this->assertSame(['日本語/ファイル 1.txt'], self::all(new tree_walker($root, [])));
    }

    public function test_an_empty_tree_gives_nothing(): void {
        $walker = new tree_walker($this->tree([]), []);
        $this->assertSame([], $walker->next(null, 10));
        $this->assertSame([], $walker->skipped());
        $this->assertSame([], $walker->unreadable());
    }

    /**
     * What the walker reads by default, for a lister that fails only for some folders.
     *
     * @param string $dir The folder.
     * @return string[]
     */
    private static function list_dir(string $dir): array {
        return array_values(array_diff(scandir($dir), ['.', '..']));
    }
}
