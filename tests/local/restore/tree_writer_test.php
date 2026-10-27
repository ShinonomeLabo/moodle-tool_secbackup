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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\plugin_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\tree_writer::class)]
/**
 * Tests for the writer that puts the files of a restore under one folder and nowhere else.
 *
 * Each test has a folder "base" that the writer writes in, and a folder "outside" next to it that nothing may reach.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\tree_writer
 */
final class tree_writer_test extends \advanced_testcase {
    /** @var string The folder of the test, which holds base and outside only. */
    private string $dir;

    /** @var string The folder of the writer. */
    private string $base;

    /** @var string A folder next to the folder of the writer. */
    private string $outside;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->dir = make_request_directory();
        $this->base = $this->dir . '/base';
        $this->outside = $this->dir . '/outside';
        mkdir($this->base);
        mkdir($this->outside);
    }

    /**
     * Every entry under a folder, without following links.
     *
     * @param string $folder The folder.
     * @return string[] Type ('dir', 'file' or 'link') by path from the folder, in byte order.
     */
    private static function listing(string $folder): array {
        clearstatcache(true);
        $found = [];
        foreach (array_diff(scandir($folder), ['.', '..']) as $name) {
            $path = $folder . '/' . $name;
            if (is_link($path)) {
                $found[$name] = 'link';
            } else if (is_dir($path)) {
                $found[$name] = 'dir';
                foreach (self::listing($path) as $inner => $type) {
                    $found[$name . '/' . $inner] = $type;
                }
            } else {
                $found[$name] = 'file';
            }
        }
        ksort($found, SORT_STRING);
        return $found;
    }

    /**
     * Asserts that the folder of the test holds base and outside only and that outside is empty.
     */
    private function assert_nothing_outside(): void {
        $this->assertSame(['base', 'outside'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
        $this->assertSame([], self::listing($this->outside));
    }

    /**
     * The mode of a file or folder, without its type.
     *
     * @param string $path The path.
     * @return int
     */
    private static function mode(string $path): int {
        clearstatcache(true);
        return fileperms($path) & 07777;
    }

    /**
     * Writes a file through a writer.
     *
     * @param tree_writer $writer The writer.
     * @param string $path The path of the file.
     * @param string $content The content.
     */
    private static function write(tree_writer $writer, string $path, string $content): void {
        $handle = $writer->open($path);
        fwrite($handle, $content);
        $writer->commit($path);
    }

    /**
     * Runs a call that must fail and gives the error code.
     *
     * @param callable $call The call.
     * @return string
     */
    private function code_of(callable $call): string {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e->errorcode;
        }
        $this->fail('No exception');
    }

    /**
     * Paths that are not plain relative paths.
     *
     * @return array
     */
    public static function unsafe_path_provider(): array {
        return [
            'parent folders' => ['../../etc/cron.d/x'],
            'absolute' => ['/etc/passwd'],
            'empty part' => ['a//b'],
            'dot part' => ['a/./b'],
            'backslash' => ['a\\b'],
            'nul' => ["a\0b"],
            'line feed' => ["a\nb"],
            'carriage return' => ["a\rb"],
            'tab' => ["a\tb"],
            'unit separator' => ["a\x1fb"],
            'delete' => ["a\x7fb"],
            'c1 control' => ["a\u{85}b"],
            'empty' => [''],
            'trailing slash' => ['a/'],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'leading dot part' => ['./a'],
            'dot dot at the end' => ['a/..'],
            'out after a folder' => ['a/../../b'],
            'not utf-8' => ["a\xffb"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_path_provider')]
    /**
     * A path that is not plain is refused before anything is written.
     *
     * @dataProvider unsafe_path_provider
     * @param string $path The path.
     */
    public function test_a_path_that_is_not_plain_is_refused_and_nothing_is_written(string $path): void {
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => tree_writer::check_path($path)));
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open($path)));
        $this->assertSame([], self::listing($this->base));
        $this->assert_nothing_outside();
    }

    /**
     * Paths that are plain relative paths, however odd they look.
     *
     * @return array
     */
    public static function plain_path_provider(): array {
        return [
            'code' => ['lib/setup.php'],
            'language' => ['lang/ja/local.php'],
            'stored file' => ['filedir/0a/1b/' . sha1('x')],
            'three dots' => ['...'],
            'dot before and after' => ['..a/a..'],
            'hidden' => ['.hidden/.x'],
            'space' => ['a b/c d'],
            'japanese' => ['ファイル/名前.txt'],
            'part' => ['x.part'],
            'dash' => ['-'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plain_path_provider')]
    /**
     * A plain relative path is written where it says, under the folder of the writer.
     *
     * @dataProvider plain_path_provider
     * @param string $path The path.
     */
    public function test_a_plain_path_is_written_where_it_says(string $path): void {
        tree_writer::check_path($path);
        self::write(new tree_writer($this->base, 0644, 0755), $path, 'content of ' . $path);
        $this->assertSame('content of ' . $path, file_get_contents($this->base . '/' . $path));
        $this->assertSame('file', self::listing($this->base)[$path]);
        $this->assert_nothing_outside();
    }

    public function test_a_link_to_another_folder_on_the_way_is_refused_and_nothing_is_written_there(): void {
        // The case of the review: the folder lang of the data root is a link to a folder of the system.
        symlink($this->outside, $this->base . '/lang');
        $writer = new tree_writer($this->base, 0660, 02770);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('lang/ja/local.php')));
        $this->assertSame(['lang' => 'link'], self::listing($this->base));
        $this->assert_nothing_outside();
    }

    public function test_a_link_deeper_on_the_way_is_refused_and_nothing_is_written_there(): void {
        mkdir($this->base . '/a');
        symlink($this->outside, $this->base . '/a/b');
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('a/b/c/d.php')));
        $this->assertSame(['a' => 'dir', 'a/b' => 'link'], self::listing($this->base));
        $this->assert_nothing_outside();
    }

    public function test_a_link_to_a_folder_of_the_same_tree_is_refused_too(): void {
        mkdir($this->base . '/real');
        symlink($this->base . '/real', $this->base . '/alias');
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('alias/x')));
        $this->assertSame(['alias' => 'link', 'real' => 'dir'], self::listing($this->base));
    }

    public function test_a_link_that_leads_nowhere_on_the_way_is_refused_and_its_target_is_not_made(): void {
        symlink($this->outside . '/missing', $this->base . '/lang');
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('lang/x')));
        $this->assert_nothing_outside();
    }

    public function test_a_link_in_place_of_the_file_is_refused_and_what_it_points_to_is_not_touched(): void {
        file_put_contents($this->outside . '/victim', 'keep');
        symlink($this->outside . '/victim', $this->base . '/x');
        symlink($this->outside . '/new', $this->base . '/y');
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('x')));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->open('y')));
        $this->assertSame('keep', file_get_contents($this->outside . '/victim'));
        $this->assertSame(['victim' => 'file'], self::listing($this->outside));
        $this->assertSame(['x' => 'link', 'y' => 'link'], self::listing($this->base));
    }

    public function test_a_link_left_with_the_name_of_the_part_file_is_removed_and_not_followed(): void {
        // PHP's fopen() in the mode x would follow such a link and make the file it points to.
        file_put_contents($this->outside . '/victim', 'keep');
        symlink($this->outside . '/created', $this->base . '/x.part');
        symlink($this->outside . '/victim', $this->base . '/y.part');
        $writer = new tree_writer($this->base, 0644, 0755);
        self::write($writer, 'x', 'restored x');
        self::write($writer, 'y', 'restored y');
        $this->assertSame(['x' => 'file', 'y' => 'file'], self::listing($this->base));
        $this->assertSame('restored x', file_get_contents($this->base . '/x'));
        $this->assertSame('restored y', file_get_contents($this->base . '/y'));
        $this->assertSame(['victim' => 'file'], self::listing($this->outside));
        $this->assertSame('keep', file_get_contents($this->outside . '/victim'));
    }

    public function test_a_hard_link_in_place_of_the_file_is_replaced_and_what_it_shares_is_not_written(): void {
        file_put_contents($this->outside . '/victim', 'keep');
        link($this->outside . '/victim', $this->base . '/x');
        self::write(new tree_writer($this->base, 0644, 0755), 'x', 'restored');
        $this->assertSame('restored', file_get_contents($this->base . '/x'));
        $this->assertSame('keep', file_get_contents($this->outside . '/victim'));
    }

    public function test_a_name_of_250_bytes_is_written_with_its_part_file_of_255(): void {
        $name = str_repeat('n', 250);
        self::write(new tree_writer($this->base, 0644, 0755), 'd/' . $name, 'long');
        $this->assertSame('long', file_get_contents($this->base . '/d/' . $name));
    }

    /**
     * Modes of files and folders, as a restore uses them.
     *
     * @return array
     */
    public static function mode_provider(): array {
        return [
            'code' => [0644, 0755],
            'data root' => [0660, 02770],
            'private' => [0600, 0700],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mode_provider')]
    /**
     * The file gets its name and the mode of the writer on commit, and the folders that the writer made get theirs.
     *
     * @dataProvider mode_provider
     * @param int $filemode Mode of files.
     * @param int $dirmode Mode of folders.
     */
    public function test_commit_names_the_file_and_the_file_and_the_folders_made_have_the_modes(int $filemode, int $dirmode): void {
        $writer = new tree_writer($this->base, $filemode, $dirmode);
        $handle = $writer->open('a/b/c.txt');
        fwrite($handle, 'content');
        clearstatcache(true);
        $this->assertFileDoesNotExist($this->base . '/a/b/c.txt');
        $this->assertSame('content', file_get_contents($this->base . '/a/b/c.txt.part'));
        $writer->commit('a/b/c.txt');
        $this->assertFalse(is_resource($handle));
        $this->assertSame(['a' => 'dir', 'a/b' => 'dir', 'a/b/c.txt' => 'file'], self::listing($this->base));
        $this->assertSame('content', file_get_contents($this->base . '/a/b/c.txt'));
        $this->assertSame($filemode, self::mode($this->base . '/a/b/c.txt'));
        $this->assertSame($dirmode, self::mode($this->base . '/a'));
        $this->assertSame($dirmode, self::mode($this->base . '/a/b'));
    }

    public function test_a_folder_that_was_there_keeps_its_mode(): void {
        mkdir($this->base . '/kept');
        chmod($this->base . '/kept', 0700);
        self::write(new tree_writer($this->base, 0644, 0755), 'kept/x', 'x');
        $this->assertSame(0700, self::mode($this->base . '/kept'));
        $this->assertSame(0644, self::mode($this->base . '/kept/x'));
    }

    public function test_a_handle_that_the_caller_closed_is_committed_too(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        $handle = $writer->open('x');
        fwrite($handle, 'closed first');
        fclose($handle);
        $writer->commit('x');
        $this->assertSame('closed first', file_get_contents($this->base . '/x'));
    }

    public function test_abort_removes_the_part_file_and_leaves_no_file(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        $handle = $writer->open('d/x');
        fwrite($handle, 'half');
        $writer->abort('d/x');
        $this->assertFalse(is_resource($handle));
        $this->assertSame(['d' => 'dir'], self::listing($this->base));
        // The path can be written again afterwards.
        self::write($writer, 'd/x', 'whole');
        $this->assertSame('whole', file_get_contents($this->base . '/d/x'));
    }

    public function test_abort_of_a_path_that_is_not_open_does_nothing(): void {
        file_put_contents($this->base . '/x.part', 'not of this writer');
        $writer = new tree_writer($this->base, 0644, 0755);
        $writer->abort('x');
        $writer->abort('missing/y');
        $this->assertSame(['x.part' => 'file'], self::listing($this->base));
    }

    public function test_a_file_and_a_part_file_of_an_earlier_restore_are_replaced(): void {
        file_put_contents($this->base . '/x', 'old');
        file_put_contents($this->base . '/x.part', 'half of an earlier restore');
        self::write(new tree_writer($this->base, 0644, 0755), 'x', 'new');
        $this->assertSame(['x' => 'file'], self::listing($this->base));
        $this->assertSame('new', file_get_contents($this->base . '/x'));
    }

    public function test_files_open_at_the_same_time_are_kept_apart(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        $first = $writer->open('a/one');
        $second = $writer->open('a/two');
        $third = $writer->open('b/three');
        fwrite($first, '1');
        fwrite($second, '2');
        fwrite($third, '3');
        $writer->commit('b/three');
        $writer->commit('a/one');
        $writer->abort('a/two');
        $this->assertSame(['a' => 'dir', 'a/one' => 'file', 'b' => 'dir', 'b/three' => 'file'], self::listing($this->base));
        $this->assertSame('1', file_get_contents($this->base . '/a/one'));
        $this->assertSame('3', file_get_contents($this->base . '/b/three'));
    }

    public function test_a_folder_in_place_of_the_file_or_its_part_file_and_a_file_in_place_of_a_folder_cannot_be_written(): void {
        mkdir($this->base . '/x');
        mkdir($this->base . '/y.part/inner', 0777, true);
        file_put_contents($this->base . '/f', 'a file');
        $writer = new tree_writer($this->base, 0644, 0755);
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->open('x')));
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->open('y')));
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->open('f/g')));
        $this->assertSame(['f' => 'file', 'x' => 'dir', 'y.part' => 'dir', 'y.part/inner' => 'dir'], self::listing($this->base));
    }

    public function test_a_folder_that_cannot_be_written_or_is_missing_fails(): void {
        // No one, root included, can make a file or a folder at the top of /proc.
        $proc = new tree_writer('/proc', 0644, 0755);
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $proc->open('tool_secbackup_test')));
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $proc->open('tool_secbackup_test/x')));
        $missing = new tree_writer($this->dir . '/missing', 0644, 0755);
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $missing->open('x')));
        $this->assert_nothing_outside();
    }

    public function test_commit_refuses_a_part_file_that_is_not_the_one_it_opened(): void {
        file_put_contents($this->outside . '/victim', 'keep');
        $writer = new tree_writer($this->base, 0644, 0755);
        fwrite($writer->open('x'), 'mine');
        unlink($this->base . '/x.part');
        file_put_contents($this->base . '/x.part', 'theirs');
        fwrite($writer->open('y'), 'mine');
        unlink($this->base . '/y.part');
        symlink($this->outside . '/victim', $this->base . '/y.part');
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->commit('x')));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->commit('y')));
        // The writer removes only the part file that it made: the other ones stay, and nothing is renamed.
        $writer->abort('x');
        $writer->abort('y');
        $this->assertSame(['x.part' => 'file', 'y.part' => 'link'], self::listing($this->base));
        $this->assertSame('theirs', file_get_contents($this->base . '/x.part'));
        $this->assertSame('keep', file_get_contents($this->outside . '/victim'));
    }

    public function test_commit_refuses_a_link_that_took_the_place_of_a_folder_or_of_the_file(): void {
        file_put_contents($this->outside . '/victim', 'keep');
        $writer = new tree_writer($this->base, 0644, 0755);
        fwrite($writer->open('a/x'), 'a/x');
        fwrite($writer->open('y'), 'y');
        rename($this->base . '/a', $this->base . '/moved');
        symlink($this->outside, $this->base . '/a');
        symlink($this->outside . '/victim', $this->base . '/y');
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->commit('a/x')));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->commit('y')));
        // An abort does not follow the link either.
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->abort('a/x')));
        $writer->abort('y');
        $this->assertSame(['a' => 'link', 'moved' => 'dir', 'moved/x.part' => 'file', 'y' => 'link'], self::listing($this->base));
        $this->assertSame(['victim' => 'file'], self::listing($this->outside));
        $this->assertSame('keep', file_get_contents($this->outside . '/victim'));
    }

    public function test_commit_fails_when_the_file_cannot_take_its_name_or_its_folder_is_gone(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        fwrite($writer->open('x'), 'x');
        mkdir($this->base . '/x/inner', 0777, true);
        fwrite($writer->open('d/y'), 'y');
        unlink($this->base . '/d/y.part');
        rmdir($this->base . '/d');
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->commit('x')));
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->commit('d/y')));
        // An abort still removes the part file, and a folder that is gone has nothing to remove.
        $writer->abort('x');
        $writer->abort('d/y');
        $this->assertSame(['x' => 'dir', 'x/inner' => 'dir'], self::listing($this->base));
    }

    public function test_a_restored_file_with_the_name_of_a_part_file_is_not_removed_for_another_file(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        self::write($writer, 'x.part', 'a file of the site');
        $this->assertSame('restore_write_failed', $this->code_of(static fn() => $writer->open('x')));
        $this->assertSame(['x.part' => 'file'], self::listing($this->base));
        $this->assertSame('a file of the site', file_get_contents($this->base . '/x.part'));
        // In the other order, both are written.
        $other = new tree_writer($this->base, 0644, 0755);
        self::write($other, 'y', 'y');
        self::write($other, 'y.part', 'y.part');
        $this->assertSame('y', file_get_contents($this->base . '/y'));
        $this->assertSame('y.part', file_get_contents($this->base . '/y.part'));
    }

    public function test_a_file_with_the_name_of_the_part_file_of_an_open_file_cannot_take_it_unseen(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        fwrite($writer->open('x'), 'x');
        self::write($writer, 'x.part', 'x.part');
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $writer->commit('x')));
        $writer->abort('x');
        $this->assertSame(['x.part' => 'file'], self::listing($this->base));
        $this->assertSame('x.part', file_get_contents($this->base . '/x.part'));
    }

    public function test_the_working_folder_and_the_umask_are_put_back(): void {
        $cwd = getcwd();
        $umask = umask();
        $writer = new tree_writer($this->base, 0660, 02770);
        self::write($writer, 'a/b', 'b');
        $this->code_of(static fn() => $writer->open('../x'));
        symlink($this->outside, $this->base . '/link');
        $this->code_of(static fn() => $writer->open('link/x'));
        $this->assertSame($cwd, getcwd());
        $this->assertSame($umask, umask());
    }

    public function test_a_working_folder_that_is_gone_is_left_for_the_root_folder(): void {
        $cwd = getcwd();
        $gone = $this->dir . '/gone';
        mkdir($gone);
        chdir($gone);
        rmdir($gone);
        try {
            self::write(new tree_writer($this->base, 0644, 0755), 'x', 'x');
            $now = getcwd();
        } finally {
            chdir($cwd);
        }
        $this->assertSame('/', $now);
        $this->assertSame('x', file_get_contents($this->base . '/x'));
    }

    public function test_a_path_open_already_or_not_open_is_an_error_of_the_caller(): void {
        $writer = new tree_writer($this->base, 0644, 0755);
        $writer->open('x');
        try {
            $writer->open('x');
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('open already', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('not open');
        $writer->commit('y');
    }

    /**
     * Arguments that a writer does not take.
     *
     * @return array
     */
    public static function bad_argument_provider(): array {
        return [
            'relative folder' => ['base', 0644, 0755],
            'empty folder' => ['', 0644, 0755],
            'nul in the folder' => ["/tmp/a\0b", 0644, 0755],
            'executable file' => ['/tmp', 0755, 0755],
            'set-user-id file' => ['/tmp', 04644, 0755],
            'negative file mode' => ['/tmp', -1, 0755],
            'folder mode too large' => ['/tmp', 0644, 010000],
            'negative folder mode' => ['/tmp', 0644, -1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_argument_provider')]
    /**
     * The folder must be absolute and the modes must be modes.
     *
     * @dataProvider bad_argument_provider
     * @param string $base The folder.
     * @param int $filemode Mode of files.
     * @param int $dirmode Mode of folders.
     */
    public function test_a_writer_needs_an_absolute_folder_and_modes(string $base, int $filemode, int $dirmode): void {
        $this->expectException(\coding_exception::class);
        new tree_writer($base, $filemode, $dirmode);
    }

    public function test_the_edges_of_the_modes_are_taken(): void {
        self::write(new tree_writer($this->base, 0666, 07777), 'a/x', 'x');
        $this->assertSame(0666, self::mode($this->base . '/a/x'));
        $this->assertSame(07777, self::mode($this->base . '/a'));
        self::write(new tree_writer($this->base, 0, 0700), 'b/y', 'y');
        $this->assertSame(0, self::mode($this->base . '/b/y'));
    }
}
