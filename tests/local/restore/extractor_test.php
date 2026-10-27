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

use tool_secbackup\local\crypto\chunk_writer;
use tool_secbackup\local\crypto\site_keys;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\tests\restore_fixture;
use tool_secbackup\tests\trace_strings;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\extractor::class)]
/**
 * Tests for the extraction of a generation: the code, the data root, the files and the blocks of the database.
 *
 * Each test has a generation made by restore_fixture with the writers of a backup run, and empty folders to write in.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\extractor
 */
final class extractor_test extends \advanced_testcase {
    /** @var restore_fixture The generation. */
    private restore_fixture $fixture;

    /** @var int[][] The calls of the progress function: [done, total] each. */
    private array $calls = [];

    /** @var \Closure|null Run after each call of the progress function. */
    private ?\Closure $onprogress = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->fixture = new restore_fixture();
    }

    /**
     * An extractor that records the calls of its progress function.
     *
     * @param array $input The input.
     * @param site_keys|null $keys The keys, or null for the new and the old key of the site.
     * @return extractor
     */
    private function extractor(array $input, ?site_keys $keys = null): extractor {
        return new extractor($input, $keys ?? $this->fixture->keys(), function (int $done, int $total): void {
            $this->calls[] = [$done, $total];
            if ($this->onprogress !== null) {
                ($this->onprogress)();
            }
        });
    }

    /**
     * Runs a call that must fail with a plugin_exception and gives it.
     *
     * @param callable $call The call.
     * @return plugin_exception
     */
    private function exception_of(callable $call): plugin_exception {
        try {
            $call();
        } catch (plugin_exception $e) {
            return $e;
        }
        $this->fail('No exception');
    }

    /**
     * Runs the extraction of an input, which must fail, and gives the error code.
     *
     * @param array $input The input.
     * @param site_keys|null $keys The keys, or null for the new and the old key of the site.
     * @return string
     */
    private function failure_of(array $input, ?site_keys $keys = null): string {
        $extractor = $this->extractor($input, $keys);
        return $this->exception_of(static fn() => $extractor->run())->errorcode;
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
     * The path in the file store of the restored data root of a content.
     *
     * @param string $content The content.
     * @param bool $absolute Whether the path is absolute or relative to the data root.
     * @return string
     */
    private function filedir(string $content, bool $absolute = true): string {
        $hash = sha1($content);
        $path = 'filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        return $absolute ? $this->fixture->targetdataroot . '/' . $path : $path;
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
     * Asserts that no part file is left in the folders that a restore writes in.
     */
    private function assert_no_part_file(): void {
        foreach ([$this->fixture->targetroot, $this->fixture->targetdataroot, $this->fixture->stagingdir] as $folder) {
            foreach (array_keys(self::listing($folder)) as $path) {
                $this->assertStringEndsNotWith('.part', $path);
            }
        }
    }

    /**
     * The working files of the list of the generation that are in the temporary folder.
     *
     * @return string[]
     */
    private static function working_files(): array {
        global $CFG;
        return glob($CFG->tempdir . '/tool_secbackup_restore/*') ?: [];
    }

    /**
     * The entries of the pieces of the big file in a list.
     *
     * @param array[] $entries The entries.
     * @return int[] Their indexes in the list, by number of the piece.
     */
    private static function pieces(array $entries): array {
        $indexes = [];
        foreach ($entries as $index => $entry) {
            if ($entry['t'] === 'file' && $entry['pieces'] > 1) {
                $indexes[$entry['piece']] = $index;
            }
        }
        return $indexes;
    }

    /**
     * The index of the first entry of a kind, and of a root when it is given.
     *
     * @param array[] $entries The entries.
     * @param string $kind The kind.
     * @param string|null $root The root.
     * @return int
     */
    private static function first(array $entries, string $kind, ?string $root = null): int {
        foreach ($entries as $index => $entry) {
            if ($entry['t'] === $kind && ($root === null || $entry['root'] === $root)) {
                return $index;
            }
        }
        throw new \coding_exception('No such entry');
    }

    public function test_a_generation_is_written_where_the_restored_moodle_reads_it(): void {
        $fixture = $this->fixture;
        $before = self::working_files();
        $summary = $this->extractor($fixture->build())->run();

        $big = implode('', $fixture->pieces);
        $dataroot = ['filedir' => 'dir', 'lang' => 'dir', 'lang/ja' => 'dir', 'lang/ja/local.php' => 'file', 'secret' => 'dir',
            'secret/key' => 'file'];
        foreach ([...$fixture->smallfiles, $big] as $content) {
            $this->assertSame($content, file_get_contents($this->filedir($content)));
            $path = $this->filedir($content, false);
            $dataroot[dirname($path, 2)] = 'dir';
            $dataroot[dirname($path)] = 'dir';
            $dataroot[$path] = 'file';
        }
        ksort($dataroot, SORT_STRING);
        $this->assertSame($dataroot, self::listing($fixture->targetdataroot), 'Nothing else is in the data root.');
        $local = $fixture->datarootfiles['dataroot']['lang/ja/local.php'];
        $this->assertSame($local, file_get_contents($fixture->targetdataroot . '/lang/ja/local.php'));
        $this->assertSame($fixture->datarootfiles['secret']['key'], file_get_contents($fixture->targetdataroot . '/secret/key'));

        foreach ($fixture->codefiles['core'] as $path => $content) {
            $this->assertSame($content, file_get_contents($fixture->targetroot . '/' . $path));
        }
        foreach ($fixture->codefiles['mod_attendance'] as $path => $content) {
            $this->assertSame($content, file_get_contents($fixture->targetroot . '/mod/attendance/' . $path));
        }
        $this->assertSame([
            'index.php' => 'file', 'lib' => 'dir', 'lib/components.json' => 'file', 'lib/setup.php' => 'file', 'mod' => 'dir',
            'mod/attendance' => 'dir', 'mod/attendance/lang' => 'dir', 'mod/attendance/lang/en' => 'dir',
            'mod/attendance/lang/en/attendance.php' => 'file', 'mod/attendance/version.php' => 'file',
        ], self::listing($fixture->targetroot), 'The skipped plugin is not written, and nothing else is.');

        $blocks = [];
        foreach ($fixture->tables as $table => $ofthetable) {
            foreach ($ofthetable as $rows) {
                $blocks[] = [
                    'file' => (count($blocks) + 1) . '.jsonl.gz', 'table' => $table, 'from' => (int) $rows[0][0],
                    'to' => (int) $rows[array_key_last($rows)][0], 'rows' => count($rows),
                    'plainsha256' => hash('sha256', restore_fixture::block_text($table, $rows)),
                    'text' => restore_fixture::block_text($table, $rows),
                ];
            }
        }
        $manifest = json_decode(file_get_contents($fixture->stagingdir . '/db/manifest.json'), true);
        $this->assertSame([
            'v' => 1,
            'family' => 'postgres',
            'tables' => ['course' => 3, 'tag' => 0, 'user' => 2],
            'blocks' => array_map(static fn(array $block): array => array_diff_key($block, ['text' => true]), $blocks),
        ], $manifest);
        foreach ($blocks as $block) {
            $this->assertSame($block['text'], gzdecode(file_get_contents($fixture->stagingdir . '/db/' . $block['file'])));
        }
        $this->assertSame($fixture->header, json_decode(file_get_contents($fixture->stagingdir . '/header.json'), true));
        $this->assertSame([
            'db' => 'dir', 'db/1.jsonl.gz' => 'file', 'db/2.jsonl.gz' => 'file', 'db/3.jsonl.gz' => 'file',
            'db/manifest.json' => 'file', 'header.json' => 'file',
        ], self::listing($fixture->stagingdir));

        $this->assertSame([
            'moodle' => $fixture->header['moodle'],
            'db_family' => 'postgres',
            'tables' => 3,
            'rows' => 5,
            'files' => 4,
            'plugins' => ['mod_attendance'],
            'missing_files' => 1,
        ], $summary);
        $this->assertSame($before, self::working_files(), 'The working file of the list is removed.');
    }

    public function test_the_code_the_data_root_and_the_staging_folder_have_their_modes(): void {
        $fixture = $this->fixture;
        $this->extractor($fixture->build())->run();
        $this->assertSame(0755, self::mode($fixture->targetroot . '/lib'));
        $this->assertSame(0755, self::mode($fixture->targetroot . '/mod/attendance'));
        $this->assertSame(0644, self::mode($fixture->targetroot . '/lib/components.json'));
        $this->assertSame(0644, self::mode($fixture->targetroot . '/mod/attendance/version.php'));
        $file = $this->filedir($fixture->smallfiles[0]);
        $this->assertSame(02770, self::mode($fixture->targetdataroot . '/filedir'));
        $this->assertSame(02770, self::mode(dirname($file)));
        $this->assertSame(0660, self::mode($file));
        $this->assertSame(02770, self::mode($fixture->targetdataroot . '/secret'));
        $this->assertSame(0660, self::mode($fixture->targetdataroot . '/secret/key'));
        $this->assertSame(0660, self::mode($fixture->targetdataroot . '/lang/ja/local.php'));
        $this->assertSame(02750, self::mode($fixture->stagingdir . '/db'));
        $this->assertSame(0640, self::mode($fixture->stagingdir . '/db/1.jsonl.gz'));
        $this->assertSame(0640, self::mode($fixture->stagingdir . '/db/manifest.json'));
        $this->assertSame(0640, self::mode($fixture->stagingdir . '/header.json'));
    }

    public function test_the_progress_counts_every_entry_once_and_ends_with_all_done(): void {
        $input = $this->fixture->build();
        $this->extractor($input)->run();
        $total = count($this->fixture->entries);
        $this->assertSame(17, $total);
        $this->assertCount($total + 1, $this->calls);
        foreach ($this->calls as $index => $call) {
            $this->assertSame([$index, $total], $call);
        }
        $this->assertSame([$total, $total], end($this->calls));
    }

    public function test_the_code_of_moodle_51_and_later_puts_a_plugin_under_public(): void {
        $this->fixture->codefiles['core'] = restore_fixture::core_files(true);
        $this->extractor($this->fixture->build())->run();
        $root = $this->fixture->targetroot;
        foreach ($this->fixture->codefiles['mod_attendance'] as $path => $content) {
            $this->assertSame($content, file_get_contents($root . '/public/mod/attendance/' . $path));
        }
        $this->assertFileExists($root . '/lib/components.json');
        $this->assertFileExists($root . '/public/index.php');
        $this->assertFileDoesNotExist($root . '/mod');
    }

    public function test_an_empty_file_of_the_file_store_is_written(): void {
        $this->fixture->smallfiles[] = '';
        $summary = $this->extractor($this->fixture->build())->run();
        $this->assertSame('', file_get_contents($this->filedir('')));
        $this->assertSame(5, $summary['files']);
    }

    public function test_chunks_of_the_old_key_open_with_the_old_key_given_after_the_new_one(): void {
        // Review Focus 1: the files that a run shares with the runs before the key was made again keep the old key.
        $this->fixture->oldfiles = true;
        $input = $this->fixture->build();
        $this->assertSame('restore_chunk_corrupt', $this->failure_of($input, $this->fixture->keys(false)));
        $this->assert_no_part_file();
        $this->calls = [];
        $summary = $this->extractor($input, $this->fixture->keys(true))->run();
        $this->assertSame(4, $summary['files']);
        $big = implode('', $this->fixture->pieces);
        $this->assertSame($big, file_get_contents($this->filedir($big)));
        $this->assertSame($this->fixture->smallfiles[1], file_get_contents($this->filedir($this->fixture->smallfiles[1])));
    }

    public function test_a_generation_of_the_official_moodle_code_is_refused_before_anything_is_written(): void {
        $this->fixture->header['core_diff']['official'] = true;
        $before = self::working_files();
        $this->assertSame('restore_core_official_unsupported', $this->failure_of($this->fixture->build()));
        $this->assertSame([], self::listing($this->fixture->targetroot));
        $this->assertSame([], self::listing($this->fixture->targetdataroot));
        $this->assertSame([], self::listing($this->fixture->stagingdir));
        $this->assertSame([], $this->calls);
        $this->assertSame($before, self::working_files(), 'The working file of the list is removed.');
    }

    /**
     * Changes to the entries of the pieces of the big file.
     *
     * @return array
     */
    public static function broken_pieces_provider(): array {
        return [
            'a piece in the middle is missing' => [static function (array $entries, array $pieces): array {
                unset($entries[$pieces[1]]);
                return array_values($entries);
            }],
            'the first piece is missing' => [static function (array $entries, array $pieces): array {
                unset($entries[$pieces[0]]);
                return array_values($entries);
            }],
            'the last piece is missing before the next file' => [static function (array $entries, array $pieces): array {
                unset($entries[$pieces[2]]);
                return array_values($entries);
            }],
            'the list ends before the last piece' => [static function (array $entries, array $pieces): array {
                $first = [$entries[$pieces[0]], $entries[$pieces[1]]];
                foreach ($pieces as $index) {
                    unset($entries[$index]);
                }
                return [...array_values($entries), ...$first];
            }],
            'two pieces are swapped in the list' => [static function (array $entries, array $pieces): array {
                [$entries[$pieces[1]], $entries[$pieces[2]]] = [$entries[$pieces[2]], $entries[$pieces[1]]];
                return $entries;
            }],
            'two pieces have swapped content' => [static function (array $entries, array $pieces): array {
                foreach (['chunk', 'offset', 'length'] as $field) {
                    [$entries[$pieces[1]][$field], $entries[$pieces[2]][$field]] =
                        [$entries[$pieces[2]][$field], $entries[$pieces[1]][$field]];
                }
                return $entries;
            }],
            'a piece says the file has another size' => [static function (array $entries, array $pieces): array {
                $entries[$pieces[1]]['size']++;
                return $entries;
            }],
            'a piece says the file has more pieces' => [static function (array $entries, array $pieces): array {
                $entries[$pieces[2]]['pieces'] = 4;
                return $entries;
            }],
            'the pieces say that the file is shorter' => [static function (array $entries, array $pieces): array {
                foreach ($pieces as $index) {
                    $entries[$index]['size'] -= 3;
                }
                return $entries;
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken_pieces_provider')]
    /**
     * Review Focus 3: pieces of a big file that are missing, out of order or of another file leave no file, whole or part.
     *
     * @dataProvider broken_pieces_provider
     * @param \Closure $change Takes the entries and the indexes of the pieces, gives the entries.
     */
    public function test_the_pieces_of_a_big_file_that_do_not_make_it_are_refused_and_leave_nothing(\Closure $change): void {
        $this->fixture->edit = static fn(array $entries): array => $change($entries, self::pieces($entries));
        $this->assertSame('restore_content_mismatch', $this->failure_of($this->fixture->build()));
        $this->assertFileDoesNotExist($this->filedir(implode('', $this->fixture->pieces)));
        $this->assert_no_part_file();
    }

    /**
     * Last pieces of another length.
     *
     * @return array
     */
    public static function last_piece_provider(): array {
        return [
            'one byte longer' => [static fn(string $piece): string => $piece . 'x'],
            'one byte shorter' => [static fn(string $piece): string => substr($piece, 0, -1)],
            'one zero byte longer' => [static fn(string $piece): string => $piece . "\0"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('last_piece_provider')]
    /**
     * Review Focus 3: a last piece of another length leaves no file, whole or part.
     *
     * @dataProvider last_piece_provider
     * @param \Closure $change Takes the content of the last piece, gives the new one.
     */
    public function test_a_last_piece_of_another_length_is_refused_and_leaves_nothing(\Closure $change): void {
        $input = $this->fixture->build();
        $last = $this->fixture->entries[self::pieces($this->fixture->entries)[2]];
        $this->fixture->chunk($last['chunk'], chunk_writer::TYPE_FILES, [$change($this->fixture->pieces[2])]);
        $this->assertSame('restore_content_mismatch', $this->failure_of($input));
        $this->assertFileDoesNotExist($this->filedir(implode('', $this->fixture->pieces)));
        $this->assert_no_part_file();
    }

    /**
     * Lists of pieces of the same content that are out of their place: joined, they would make the right content.
     *
     * @return array
     */
    public static function same_pieces_provider(): array {
        return [
            'the last piece before the second' => [[0, 2, 1]],
            'the second piece first' => [[1, 1, 2]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('same_pieces_provider')]
    /**
     * The pieces are joined in the order of their numbers, which is the order of the list: a piece out of its place is refused
     * even when the content would come out right.
     *
     * @dataProvider same_pieces_provider
     * @param int[] $order The numbers of the pieces, in the order of the list.
     */
    public function test_pieces_of_the_same_content_out_of_their_place_are_refused(array $order): void {
        $this->fixture->pieces = ['same', 'same', 'same'];
        $this->fixture->edit = static function (array $entries) use ($order): array {
            $pieces = self::pieces($entries);
            $listed = array_map(static fn(int $number): array => $entries[$pieces[$number]], $order);
            foreach ($pieces as $index) {
                unset($entries[$index]);
            }
            return [...$listed, ...array_values($entries)];
        };
        $this->assertSame('restore_content_mismatch', $this->failure_of($this->fixture->build()));
        $this->assertFileDoesNotExist($this->filedir('samesamesame'));
        $this->assert_no_part_file();
    }

    public function test_a_file_of_the_moodle_code_whose_last_piece_is_missing_is_refused_and_leaves_nothing(): void {
        // The Moodle code comes last in the list: the pass that writes it ends with the file still open.
        $this->fixture->edit = static function (array $entries): array {
            $entries[array_key_last($entries)]['pieces'] = 2;
            return $entries;
        };
        $this->assertSame('restore_content_mismatch', $this->failure_of($this->fixture->build()));
        $this->assertFileDoesNotExist($this->fixture->targetroot . '/lib/setup.php');
        $this->assert_no_part_file();
    }

    public function test_a_small_file_with_one_byte_changed_is_refused_and_is_not_written(): void {
        $input = $this->fixture->build();
        $changed = $this->fixture->smallfiles;
        $changed[1] = substr($changed[1], 0, -1) . '!';
        $pack = $this->fixture->entries[self::first($this->fixture->entries, 'file')]['chunk'];
        $this->fixture->chunk($pack, chunk_writer::TYPE_FILES, $changed);
        $this->assertSame('restore_content_mismatch', $this->failure_of($input));
        $this->assertSame($changed[0], file_get_contents($this->filedir($changed[0])), 'The file before is whole.');
        $this->assertFileDoesNotExist($this->filedir($this->fixture->smallfiles[1]));
        $this->assertFileDoesNotExist($this->filedir($changed[1]));
        $this->assert_no_part_file();
    }

    public function test_a_file_of_the_data_root_with_other_content_is_refused_and_is_not_written(): void {
        $input = $this->fixture->build();
        $entry = $this->fixture->entries[self::first($this->fixture->entries, 'dataroot')];
        $local = $this->fixture->datarootfiles['dataroot']['lang/ja/local.php'];
        $key = $this->fixture->datarootfiles['secret']['key'];
        $this->fixture->chunk($entry['chunk'], chunk_writer::TYPE_FILES, [strtoupper($local), $key]);
        $this->assertSame('restore_content_mismatch', $this->failure_of($input));
        $this->assertFileDoesNotExist($this->fixture->targetdataroot . '/lang/ja/local.php');
        $this->assert_no_part_file();
    }

    public function test_a_chunk_that_is_not_there_is_missing_and_leaves_no_part_file(): void {
        $input = $this->fixture->build();
        $chunk = $this->fixture->entries[self::first($this->fixture->entries, 'dataroot')]['chunk'];
        unlink($this->fixture->chunksdir . '/' . $chunk);
        $this->assertSame('restore_chunk_missing', $this->failure_of($input));
        $this->assertFileDoesNotExist($this->fixture->targetdataroot . '/lang/ja/local.php');
        $this->assert_no_part_file();
    }

    /**
     * Ways to damage the pack of the small files after its first item, which the keys of the site check.
     *
     * @return array
     */
    public static function damaged_item_provider(): array {
        return [
            'a byte of the second item is changed' => [static function (string $path, int $second): void {
                $bytes = file_get_contents($path);
                $bytes[$second + 40] = chr(ord($bytes[$second + 40]) ^ 1);
                file_put_contents($path, $bytes);
            }],
            'the chunk is cut after its first item' => [static function (string $path, int $second): void {
                file_put_contents($path, substr(file_get_contents($path), 0, $second + 30));
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damaged_item_provider')]
    /**
     * An item that does not decrypt is a chunk that is corrupt, though the chunk opened.
     *
     * @dataProvider damaged_item_provider
     * @param \Closure $damage Takes the path of the chunk and the offset of the second item.
     */
    public function test_an_item_that_does_not_decrypt_is_a_corrupt_chunk(\Closure $damage): void {
        $input = $this->fixture->build();
        $second = $this->fixture->entries[self::first($this->fixture->entries, 'file') + 1];
        $damage($this->fixture->chunksdir . '/' . $second['chunk'], $second['offset']);
        $e = $this->exception_of(fn() => $this->extractor($input)->run());
        $this->assertSame('restore_chunk_corrupt', $e->errorcode);
        $this->assertNull($e->getPrevious());
        $this->assertFileDoesNotExist($this->filedir($this->fixture->smallfiles[1]));
        $this->assert_no_part_file();
    }

    /**
     * Entries whose chunk is of another type.
     *
     * @return array
     */
    public static function other_type_provider(): array {
        return [
            'a file of the data root in a block of the database' => ['dataroot', 'db'],
            'a block of the database in a chunk of files' => ['db', 'file'],
            'code in a chunk of the data root' => ['code', 'dataroot'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('other_type_provider')]
    /**
     * A chunk whose type is not the one of its entry is corrupt.
     *
     * @dataProvider other_type_provider
     * @param string $kind The kind of the entry that is changed.
     * @param string $other The kind of the entry whose chunk it is given.
     */
    public function test_a_chunk_of_another_type_than_its_entry_is_corrupt(string $kind, string $other): void {
        $this->fixture->edit = static function (array $entries) use ($kind, $other): array {
            $from = $entries[self::first($entries, $other)];
            $index = self::first($entries, $kind);
            foreach (['chunk', 'offset', 'length'] as $field) {
                $entries[$index][$field] = $from[$field];
            }
            return $entries;
        };
        $this->assertSame('restore_chunk_corrupt', $this->failure_of($this->fixture->build()));
        $this->assert_no_part_file();
    }

    /**
     * Items of a block that do not hold its plain text.
     *
     * @return array
     */
    public static function bad_block_provider(): array {
        return [
            'other rows' => [static fn(string $plain): string => gzencode(str_replace('Biology', 'Physics', $plain), 6)],
            'a byte after the compressed text' => [static fn(string $plain): string => gzencode($plain, 6) . "\0"],
            'two compressed texts' => [static fn(string $plain): string => gzencode(substr($plain, 0, 10), 6)
                . gzencode(substr($plain, 10), 6)],
            'the plain text, not compressed' => [static fn(string $plain): string => $plain],
            'a compressed text that is cut' => [static fn(string $plain): string => substr(gzencode($plain, 6), 0, -4)],
            'a longer plain text' => [static fn(string $plain): string => gzencode($plain . 'x', 6)],
            'a shorter plain text' => [static fn(string $plain): string => gzencode(substr($plain, 0, -1), 6)],
            'nothing' => [static fn(string $plain): string => ''],
            'many times the plain text' => [static fn(string $plain): string => gzencode(str_repeat($plain, 100000), 9)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_block_provider')]
    /**
     * A block whose item does not hold its plain text is refused before it is written.
     *
     * @dataProvider bad_block_provider
     * @param \Closure $item Takes the plain text of the block, gives the item.
     */
    public function test_a_block_that_does_not_hold_its_plain_text_is_refused_and_is_not_written(\Closure $item): void {
        $input = $this->fixture->build();
        $first = $this->fixture->entries[self::first($this->fixture->entries, 'db')];
        $plain = restore_fixture::block_text('course', $this->fixture->tables['course'][0]);
        $this->fixture->chunk($first['chunk'], chunk_writer::TYPE_DB, [$item($plain)]);
        $this->assertSame('restore_content_mismatch', $this->failure_of($input));
        $this->assertSame(['db' => 'dir'], self::listing($this->fixture->stagingdir));
    }

    /**
     * Entries that name no place that a restore writes to.
     *
     * @return array
     */
    public static function unsafe_entry_provider(): array {
        return [
            'a root of the data root that is not known' => ['dataroot', 'root', 'cache'],
            'a path out of the data root' => ['dataroot', 'path', '../outside'],
            'a path out of the secret folder' => ['dataroot', 'path', '../../outside'],
            'a root of the code that is not a component' => ['code', 'root', 'attendance'],
            'core in capitals' => ['code', 'root', 'Core'],
            'a component of a type that the code does not have' => ['code', 'root', 'qtype_unknown'],
            'a path out of the folder of the plugin' => ['code', 'path', '../../outside'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_entry_provider')]
    /**
     * An entry that names no place in the folders of the restore is refused, and nothing is written for it.
     *
     * @dataProvider unsafe_entry_provider
     * @param string $kind The kind of the entry that is changed.
     * @param string $field Its field.
     * @param string $value The new value.
     */
    public function test_an_entry_that_names_no_safe_place_is_refused(string $kind, string $field, string $value): void {
        $this->fixture->edit = static function (array $entries) use ($kind, $field, $value): array {
            $index = $field === 'path' && $value === '../../outside' && $kind === 'dataroot'
                ? self::first($entries, 'dataroot', 'secret') : self::first($entries, $kind);
            $entries[$index][$field] = $value;
            return $entries;
        };
        $this->assertSame('restore_path_unsafe', $this->failure_of($this->fixture->build()));
        $folders = array_values(array_diff(scandir(dirname($this->fixture->targetroot)), ['.', '..']));
        $this->assertSame(['chunks', 'dataroot', 'root', 'source', 'staging'], $folders);
        $this->assertFileDoesNotExist(dirname($this->fixture->targetroot) . '/outside');
        $this->assert_no_part_file();
    }

    /**
     * Headers that a restore cannot use.
     *
     * @return array
     */
    public static function bad_header_provider(): array {
        return [
            'no flag of the official code' => [static function (array $header): array {
                unset($header['core_diff']['official']);
                return $header;
            }],
            'a flag of the official code as text' => [static function (array $header): array {
                $header['core_diff']['official'] = 'false';
                return $header;
            }],
            'a flag of the official code as a number' => [static function (array $header): array {
                $header['core_diff']['official'] = 0;
                return $header;
            }],
            'no family of the database' => [static function (array $header): array {
                unset($header['db']['family']);
                return $header;
            }],
            'a family of the database in capitals' => [static function (array $header): array {
                $header['db']['family'] = 'Postgres';
                return $header;
            }],
            'no tables' => [static function (array $header): array {
                unset($header['db']['tables']);
                return $header;
            }],
            'tables as an object' => [static function (array $header): array {
                $header['db']['tables'] = ['course' => 'course', 'tag' => 'tag', 'user' => 'user'];
                return $header;
            }],
            'a table that is not a name' => [static function (array $header): array {
                $header['db']['tables'][] = 'mdl course';
                return $header;
            }],
            'a table that is not text' => [static function (array $header): array {
                $header['db']['tables'][] = 7;
                return $header;
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_header_provider')]
    /**
     * A header that does not say whether the code is official or what the database has is refused before anything is written.
     *
     * @dataProvider bad_header_provider
     * @param \Closure $change Takes the header, gives the new one.
     */
    public function test_a_header_that_a_restore_cannot_use_is_refused_before_anything_is_written(\Closure $change): void {
        $this->fixture->header = $change($this->fixture->header);
        $this->assertSame('restore_manifest_invalid', $this->failure_of($this->fixture->build()));
        $this->assertSame([], self::listing($this->fixture->targetroot));
        $this->assertSame([], self::listing($this->fixture->targetdataroot));
        $this->assertSame([], self::listing($this->fixture->stagingdir));
    }

    public function test_a_block_of_a_table_that_the_header_does_not_list_is_refused(): void {
        $this->fixture->header['db']['tables'] = ['course', 'tag'];
        $this->assertSame('restore_manifest_invalid', $this->failure_of($this->fixture->build()));
        $this->assertFileDoesNotExist($this->fixture->stagingdir . '/db/manifest.json');
    }

    public function test_a_list_that_does_not_verify_is_refused_and_leaves_no_working_file(): void {
        $input = $this->fixture->build();
        $input['manifest']['signature'] = strrev($input['manifest']['signature']);
        $before = self::working_files();
        $this->assertSame('restore_manifest_invalid', $this->failure_of($input));
        $this->assertSame([], self::listing($this->fixture->targetroot));
        $this->assertSame($before, self::working_files());
    }

    public function test_a_failure_in_the_middle_removes_the_working_file_of_the_list(): void {
        $input = $this->fixture->build();
        unlink($this->fixture->chunksdir . '/' . $this->fixture->entries[self::first($this->fixture->entries, 'file')]['chunk']);
        $before = self::working_files();
        $this->assertSame('restore_chunk_missing', $this->failure_of($input));
        $this->assertSame($before, self::working_files());
    }

    public function test_a_folder_that_cannot_be_written_fails(): void {
        $input = $this->fixture->build();
        $input['target_root'] = $this->fixture->targetroot . '/missing';
        $this->assertSame('restore_write_failed', $this->failure_of($input));
        $this->assertSame([], self::listing($this->fixture->targetroot));
    }

    public function test_a_failure_while_a_folder_is_swapped_for_a_link_is_reported_and_nothing_follows_the_link(): void {
        // The restore relies on no other process writing in its folders. When one does, the part file of the big file is
        // not followed to remove it, and the failure that stopped the restore (a last piece that is too long) is reported.
        $input = $this->fixture->build();
        $last = $this->fixture->entries[self::pieces($this->fixture->entries)[2]];
        $this->fixture->chunk($last['chunk'], chunk_writer::TYPE_FILES, [$this->fixture->pieces[2] . 'x']);
        $big = $this->filedir(implode('', $this->fixture->pieces));
        $first = dirname($big, 2);
        $elsewhere = make_request_directory();
        $this->onprogress = static function () use ($big, $first, $elsewhere): void {
            if (is_file($big . '.part') && !is_link($first)) {
                rename($first, $first . '-moved');
                symlink($elsewhere, $first);
            }
        };
        $this->assertSame('restore_content_mismatch', $this->failure_of($input));
        $this->assertSame([], array_values(array_diff(scandir($elsewhere), ['.', '..'])), 'Nothing is written past the link.');
        $part = $first . '-moved/' . basename(dirname($big)) . '/' . basename($big) . '.part';
        $this->assertFileExists($part, 'The part file stays where the link led away from.');
    }

    public function test_a_folder_swapped_for_a_link_while_a_big_file_is_written_stops_the_restore(): void {
        $input = $this->fixture->build();
        $big = $this->filedir(implode('', $this->fixture->pieces));
        $first = dirname($big, 2);
        $elsewhere = make_request_directory();
        $this->onprogress = static function () use ($big, $first, $elsewhere): void {
            if (is_file($big . '.part') && !is_link($first)) {
                rename($first, $first . '-moved');
                symlink($elsewhere, $first);
            }
        };
        $this->assertSame('restore_path_unsafe', $this->failure_of($input));
        $this->assertSame([], array_values(array_diff(scandir($elsewhere), ['.', '..'])), 'Nothing is written past the link.');
    }

    public function test_a_generation_without_tables_lists_none_as_an_object(): void {
        $this->fixture->header['db']['tables'] = [];
        $this->fixture->edit = static fn(array $entries): array => array_values(
            array_filter($entries, static fn(array $entry): bool => $entry['t'] !== 'db')
        );
        $summary = $this->extractor($this->fixture->build())->run();
        $manifest = file_get_contents($this->fixture->stagingdir . '/db/manifest.json');
        $this->assertSame('{"v":1,"family":"postgres","tables":{},"blocks":[]}', $manifest);
        $this->assertSame(0, $summary['tables']);
        $this->assertSame(0, $summary['rows']);
    }

    /**
     * Inputs that are not in the form of the restore host.
     *
     * @return array
     */
    public static function bad_input_provider(): array {
        $set = static fn(string $key, mixed $value): \Closure => static function (array $input) use ($key, $value): array {
            $input[$key] = $value;
            return $input;
        };
        $manifest = static fn(string $key, mixed $value): \Closure => static function (array $input) use ($key, $value): array {
            $input['manifest'][$key] = $value;
            return $input;
        };
        $without = static fn(string $key): \Closure => static function (array $input) use ($key): array {
            unset($input[$key]);
            return $input;
        };
        return [
            'no version' => [$without('v')],
            'version 2' => [$set('v', 2)],
            'version as text' => [$set('v', '1')],
            'an unknown member' => [$set('extra', true)],
            'no site' => [$without('site_id')],
            'a site in capitals' => [$set('site_id', strtoupper(restore_fixture::SITE))],
            'a site that is not text' => [$set('site_id', 7)],
            'a run that is not a UUID' => [$set('run_id', 'run-1')],
            'a run with a line feed' => [$set('run_id', restore_fixture::RUN . "\n")],
            'a manifest that is not an object' => [$set('manifest', 'manifest.chunk')],
            'a manifest that is a list' => [$set('manifest', ['/a', 'b', 1, 'c', 'd'])],
            'a manifest without its size' => [static function (array $input): array {
                unset($input['manifest']['size']);
                return $input;
            }],
            'a manifest with an unknown member' => [$manifest('extra', 'x')],
            'a size as text' => [$manifest('size', '12')],
            'a negative size' => [$manifest('size', -1)],
            'a hash that is not text' => [$manifest('sha256', 12)],
            'a signature that is not text' => [$manifest('signature', null)],
            'a public key that is not text' => [$manifest('signing_public_key', [])],
            'a relative path of the manifest' => [$manifest('path', 'manifest.chunk')],
            'a relative folder of the chunks' => [$set('chunks_dir', 'chunks')],
            'a relative root' => [$set('target_root', 'root')],
            'an empty data root' => [$set('target_dataroot', '')],
            'a staging folder with a NUL' => [$set('staging_dir', "/tmp/a\0b")],
            'a staging folder that is not text' => [$set('staging_dir', 5)],
            'no components to skip' => [$without('skip_components')],
            'components to skip as an object' => [$set('skip_components', ['a' => 'tool_secbackup'])],
            'a component in capitals' => [$set('skip_components', ['Tool_secbackup'])],
            'core as a component to skip' => [$set('skip_components', ['core'])],
            'a component with a line feed' => [$set('skip_components', ["tool_secbackup\n"])],
            'a component that is not text' => [$set('skip_components', [7])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_input_provider')]
    /**
     * An input that is not in the form of the restore host is refused when the extractor is made.
     *
     * @dataProvider bad_input_provider
     * @param \Closure $change Takes the input, gives the new one.
     */
    public function test_an_input_that_is_not_in_its_form_is_refused(\Closure $change): void {
        $input = $change($this->fixture->build());
        $e = $this->exception_of(fn() => $this->extractor($input));
        $this->assertSame('restore_input_invalid', $e->errorcode);
    }

    public function test_no_component_needs_to_be_skipped(): void {
        $input = $this->fixture->build();
        $input['skip_components'] = [];
        $summary = $this->extractor($input)->run();
        $this->assertSame(['mod_attendance', 'tool_secbackup'], $summary['plugins']);
        $this->assertFileExists($this->fixture->targetroot . '/admin/tool/secbackup/version.php');
    }

    public function test_the_failures_hide_the_keys_and_keep_no_object_or_content_in_their_traces(): void {
        $this->fixture->oldfiles = true;
        $input = $this->fixture->build();
        $entry = $this->fixture->entries[self::first($this->fixture->entries, 'dataroot', 'secret')];
        $failures = [];
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            // Called here and not through a function given as an argument, which would be an object in a frame of the test.
            try {
                $this->extractor($input, $this->fixture->keys(false))->run();
            } catch (plugin_exception $e) {
                $failures['restore_chunk_corrupt'] = $e;
            }
            $local = $this->fixture->datarootfiles['dataroot']['lang/ja/local.php'];
            $this->fixture->chunk($entry['chunk'], chunk_writer::TYPE_FILES, [$local, 'not the key']);
            try {
                $this->extractor($input)->run();
            } catch (plugin_exception $e) {
                $failures['restore_content_mismatch'] = $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertSame(['restore_chunk_corrupt', 'restore_content_mismatch'], array_keys($failures));
        foreach ($failures as $code => $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame([], trace_strings::objects_in_plugin_frames($e));
            foreach (trace_strings::of($e) as $string) {
                $this->assertStringNotContainsString('not the key', $string);
                foreach ($this->fixture->secrets() as $secret) {
                    $this->assertStringNotContainsString($secret, $string);
                    $this->assertStringNotContainsString(base64_encode($secret), $string);
                    $this->assertStringNotContainsString(bin2hex($secret), $string);
                }
            }
        }
        // A static function: a closure bound to the test would show the test, and the keys of the fixture, in var_dump().
        $extractor = new extractor($input, $this->fixture->keys(), static function (int $done, int $total): void {
        });
        ob_start();
        var_dump($extractor);
        $shown = ob_get_clean() . var_export($extractor, true);
        foreach ($this->fixture->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $shown);
            $this->assertStringNotContainsString(base64_encode($secret), $shown);
            $this->assertStringNotContainsString(bin2hex($secret), $shown);
        }
    }
}
