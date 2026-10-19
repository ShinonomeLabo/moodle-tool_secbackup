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
 * Tests for the comparison of the Moodle program files with the official list.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_secbackup\local\backup;

use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\network_fakes;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\backup\core_diff::class)]
/**
 * Tests for the comparison of the Moodle program files with the official list.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\backup\core_diff
 */
final class core_diff_test extends \advanced_testcase {
    /** @var string Connection URL of the fake service. */
    private const API = 'https://app.example.test/api/lms-backup/v1';

    /** @var int The clock of the fake service and of this server. */
    private const NOW = 1760000000;

    /** @var string The version in the folders that are made here. */
    private const VERSION = '2024100703.10';

    /** @var string The build in the folders that are made here. */
    private const BUILD = '20250113';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        network_fakes::no_security_list();
    }

    /**
     * Makes a Moodle folder: a version.php and these files.
     *
     * @param string[] $files Content by relative path.
     * @param string $versionfile Where the version.php goes.
     * @return string The folder.
     */
    private static function moodle(array $files = [], string $versionfile = 'version.php'): string {
        $root = make_request_directory();
        $files[$versionfile] = "<?php\n\$version  = 2024100703.10;\n\$release  = '4.5.3+ (Build: 20250113)';\n";
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
     * The hashes of a folder that core_diff gives, as an array.
     *
     * @param core_diff $diff The object.
     * @return string[] Hash by path.
     */
    private static function hashes(core_diff $diff): array {
        return iterator_to_array($diff->local_hashes());
    }

    /**
     * A list of official files.
     *
     * @param string[] $hashes Hash by path, in this order.
     * @param array $header Changes to the first line.
     * @return string The compressed list.
     */
    private static function official(array $hashes, array $header = []): string {
        $first = array_merge([
            'v' => 1,
            'version' => self::VERSION,
            'build' => self::BUILD,
            'release' => '4.5.3+ (Build: 20250113)',
            'count' => count($hashes),
        ], $header);
        $text = json_encode($first, JSON_UNESCAPED_SLASHES) . "\n";
        foreach ($hashes as $path => $hash) {
            $text .= $path . "\t" . $hash . "\n";
        }
        return gzencode($text);
    }

    /**
     * The hash of a text.
     *
     * @param string $text The text.
     * @return string
     */
    private static function sha(string $text): string {
        return hash('sha256', $text);
    }

    // The local files.

    public function test_the_local_files_are_hashed_in_byte_order_without_what_the_service_leaves_out(): void {
        $root = self::moodle([
            'a/b' => 'ab',
            'a-c' => 'ac',
            'a.php' => 'aphp',
            'index.php' => 'index',
            'config.php' => 'secret settings',
            'config-dist.php' => 'dist',
            'lib/config.php' => 'libconfig',
            'public/config.php' => 'publicconfig',
            '.git/HEAD' => 'ref',
            'lib/.git/HEAD' => 'ref',
            'lib/node_modules/x/y.js' => 'js',
            'node_modules/z.js' => 'js',
            'local/added/version.php' => 'plugin',
            'local/added/lib.php' => 'plugin',
            'local/other/lib.php' => 'core',
            'Zed.txt' => 'zed',
        ]);
        $diff = new core_diff($root, [$root . '/local/added']);
        $this->assertSame([
            'Zed.txt' => self::sha('zed'),
            'a-c' => self::sha('ac'),
            'a.php' => self::sha('aphp'),
            'a/b' => self::sha('ab'),
            'index.php' => self::sha('index'),
            'lib/config.php' => self::sha('libconfig'),
            'local/other/lib.php' => self::sha('core'),
            'public/config.php' => self::sha('publicconfig'),
            'version.php' => hash_file('sha256', $root . '/version.php'),
        ], self::hashes($diff));
        $this->assertSame([], $diff->unlisted());
    }

    public function test_a_file_or_link_named_like_a_skipped_folder_follows_the_rules_of_the_service(): void {
        $root = self::moodle(['lib/.git' => 'a file called .git', 'lib/real/x' => 'x']);
        symlink($root . '/lib/real', $root . '/lib/node_modules');
        $hashes = self::hashes(new core_diff($root, []));
        $this->assertSame(self::sha('a file called .git'), $hashes['lib/.git']);
        $this->assertArrayHasKey('lib/real/x', $hashes);
        $this->assertArrayNotHasKey('lib/node_modules/x', $hashes);
    }

    public function test_links_and_files_that_are_not_regular_are_left_out(): void {
        $root = self::moodle(['real.php' => 'real', 'dir/in.php' => 'in']);
        $outside = make_request_directory();
        file_put_contents($outside . '/out.php', 'outside');
        symlink($outside . '/out.php', $root . '/link.php');
        symlink($outside, $root . '/dirlink');
        symlink($root . '/nothing', $root . '/dangling');
        $this->assertTrue(posix_mkfifo($root . '/pipe', 0600));
        $this->assertSame(
            ['dir/in.php', 'real.php', 'version.php'],
            array_keys(self::hashes(new core_diff($root, [])))
        );
    }

    public function test_a_plugin_folder_outside_the_root_or_missing_changes_nothing(): void {
        $root = self::moodle(['local/x/lib.php' => 'x']);
        $diff = new core_diff($root, [make_request_directory(), $root . '/local/missing', $root . '/local/x/']);
        $this->assertSame(['version.php'], array_keys(self::hashes($diff)));
    }

    public function test_a_name_the_list_cannot_hold_is_not_listed_and_is_reported(): void {
        $root = self::moodle(['good.php' => 'good']);
        file_put_contents($root . "/tab\tname.php", 'tab');
        file_put_contents($root . "/line\nfeed.php", 'lf');
        file_put_contents($root . "/bad\xff.php", 'latin');
        $diff = new core_diff($root, []);
        $this->assertSame(['good.php', 'version.php'], array_keys(self::hashes($diff)));
        $unlisted = $diff->unlisted();
        sort($unlisted);
        $expected = ['hex:' . bin2hex("bad\xff.php"), 'hex:' . bin2hex("line\nfeed.php"), 'hex:' . bin2hex("tab\tname.php")];
        sort($expected);
        $this->assertSame($expected, $unlisted);
    }

    public function test_a_file_that_cannot_be_read_is_reported_and_not_listed(): void {
        $root = self::moodle(['open.php' => 'open', 'closed.php' => 'closed']);
        $hasher = static fn(string $path): string|false => basename($path) === 'closed.php' ? false : hash_file('sha256', $path);
        $diff = new core_diff($root, [], null, $hasher);
        $this->assertSame(['open.php', 'version.php'], array_keys(self::hashes($diff)));
        $this->assertSame(['closed.php'], $diff->unlisted());
    }

    public function test_a_folder_that_cannot_be_listed_is_reported(): void {
        $root = self::moodle(['open/a.php' => 'a', 'closed/b.php' => 'b']);
        $lister = static fn(string $path): array|false => basename($path) === 'closed' ? false : scandir($path);
        $diff = new core_diff($root, [], $lister);
        $this->assertSame(['open/a.php', 'version.php'], array_keys(self::hashes($diff)));
        $this->assertSame(['closed'], $diff->unlisted());
    }

    public function test_a_tree_of_more_files_than_a_page_is_given_whole_and_in_order(): void {
        $files = [];
        for ($i = 0; $i < 2600; $i++) {
            $files[sprintf('d%d/f%04d.txt', $i % 3, $i)] = (string) $i;
        }
        $hashes = self::hashes(new core_diff(self::moodle($files), []));
        $this->assertCount(2601, $hashes);
        $keys = array_keys($hashes);
        $sorted = $keys;
        usort($sorted, 'strcmp');
        $this->assertSame($sorted, $keys);
    }

    public function test_the_root_of_this_site_holds_the_whole_distribution(): void {
        global $CFG;
        $expected = isset($CFG->root) && is_dir($CFG->root . '/public') ? $CFG->root : $CFG->dirroot;
        $this->assertSame($expected, core_diff::site_root());
        $diff = new core_diff();
        foreach ($diff->local_hashes() as $path => $hash) {
            // The first file only: the whole tree is walked by the backup, and takes a while.
            $this->assertSame(hash_file('sha256', $expected . '/' . $path), $hash);
            break;
        }
        $this->assertSame([], array_filter($diff->unlisted(), static fn(string $p): bool => !str_starts_with($p, 'hex:')));
    }

    public function test_the_default_plugin_folders_are_those_of_the_added_plugins(): void {
        global $CFG;
        $diff = new core_diff();
        $this->assertSame(
            array_values(code_source::roots()),
            $diff->plugin_roots()
        );
        $this->assertContains($CFG->dirroot . '/admin/tool/secbackup', array_map('realpath', $diff->plugin_roots()));
    }

    // The release.

    public function test_the_release_is_read_from_the_version_file_without_running_it(): void {
        $root = self::moodle();
        $this->assertSame(
            ['version' => '2024100703.10', 'release' => '4.5.3+ (Build: 20250113)', 'build' => '20250113'],
            (new core_diff($root, []))->release_parts()
        );
    }

    public function test_the_release_of_a_distribution_with_a_public_folder_is_read_from_it(): void {
        $root = self::moodle([], 'public/version.php');
        $this->assertSame('20250113', (new core_diff($root, []))->release_parts()['build']);
        // The file in the root wins when both exist.
        file_put_contents($root . '/version.php', "<?php\n\$version = 2026100500;\n\$release = '5.3 (Build: 20261005)';\n");
        $this->assertSame(
            ['version' => '2026100500.00', 'release' => '5.3 (Build: 20261005)', 'build' => '20261005'],
            (new core_diff($root, []))->release_parts()
        );
    }

    public function test_a_version_file_is_never_run(): void {
        $root = self::moodle();
        file_put_contents($root . '/version.php', "<?php\ndefine('SECBACKUP_RAN', 1);\n\$version = 2024100703.10;\n" .
            "\$release = \"4.5 (Build: 20250113)\"; // 'x'\n");
        $this->assertSame('4.5 (Build: 20250113)', (new core_diff($root, []))->release_parts()['release']);
        $this->assertFalse(defined('SECBACKUP_RAN'));
    }

    /**
     * Version files that cannot be used.
     *
     * @return array
     */
    public static function bad_version_file_provider(): array {
        $good = "\$release = '4.5.3+ (Build: 20250113)';\n";
        return [
            'no version' => ["<?php\n" . $good],
            'version with a letter' => ["<?php\n\$version = 20241007a;\n" . $good],
            'version too short' => ["<?php\n\$version = 202410;\n" . $good],
            'version with 3 decimals' => ["<?php\n\$version = 2024100703.100;\n" . $good],
            'no release' => ["<?php\n\$version = 2024100703.10;\n"],
            'no build' => ["<?php\n\$version = 2024100703.10;\n\$release = '4.5.3';\n"],
            'build too short' => ["<?php\n\$version = 2024100703.10;\n\$release = '4.5.3 (Build: 2025011)';\n"],
        ];
    }

    /**
     * A version file that cannot be used is invalid.
     *
     * @dataProvider bad_version_file_provider
     * @param string $content The version file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_version_file_provider')]
    public function test_a_version_file_that_cannot_be_used_is_invalid(string $content): void {
        $root = self::moodle();
        file_put_contents($root . '/version.php', $content);
        $this->expect_code('core_manifest_invalid', fn() => (new core_diff($root, []))->release_parts());
    }

    public function test_no_version_file_is_invalid(): void {
        $root = make_request_directory();
        $this->expect_code('core_manifest_invalid', fn() => (new core_diff($root, []))->release_parts());
    }

    public function test_the_release_of_this_site_is_read_from_its_version_file(): void {
        global $CFG;
        $parts = (new core_diff())->release_parts();
        $this->assertSame($CFG->release, $parts['release']);
        $this->assertMatchesRegularExpression('/^\d{10}\.\d{2}\z/', $parts['version']);
        $this->assertSame(1, preg_match('/Build: (\d{8})/', $CFG->release, $m));
        $this->assertSame($m[1], $parts['build']);
        $this->assertSame(sprintf('%.2f', (float) $CFG->version), $parts['version']);
    }

    // The comparison.

    public function test_a_modified_an_added_and_a_deleted_file_are_found(): void {
        $root = self::moodle(['same.php' => 'same', 'changed.php' => 'now', 'new.php' => 'new']);
        $diff = new core_diff($root, []);
        $official = self::official([
            'changed.php' => self::sha('before'),
            'gone.php' => self::sha('gone'),
            'same.php' => self::sha('same'),
            'version.php' => hash_file('sha256', $root . '/version.php'),
        ]);
        $this->assertSame(
            ['modified' => ['changed.php'], 'added' => ['new.php'], 'deleted' => ['gone.php']],
            $diff->compare($official, $diff->local_hashes())
        );
    }

    public function test_a_site_that_is_as_the_list_has_no_difference(): void {
        $root = self::moodle(['a.php' => 'a']);
        $diff = new core_diff($root, []);
        $official = self::official(self::hashes($diff));
        $this->assertSame(['modified' => [], 'added' => [], 'deleted' => []], $diff->compare($official, $diff->local_hashes()));
    }

    public function test_an_empty_list_makes_every_local_file_added_and_an_empty_site_every_official_one_deleted(): void {
        $diff = new core_diff(self::moodle(), []);
        $local = ['a.php' => self::sha('a'), 'b.php' => self::sha('b')];
        $this->assertSame(
            ['modified' => [], 'added' => ['a.php', 'b.php'], 'deleted' => []],
            $diff->compare(self::official([]), $local)
        );
        $this->assertSame(
            ['modified' => [], 'added' => [], 'deleted' => ['a.php', 'b.php']],
            $diff->compare(self::official($local), [])
        );
    }

    public function test_the_two_lists_are_merged_in_byte_order(): void {
        $diff = new core_diff(self::moodle(), []);
        $official = ['A' => self::sha('1'), 'a-c' => self::sha('2'), 'a/b' => self::sha('3'), 'z' => self::sha('4')];
        $local = [
            'A' => self::sha('1'),
            'a-b' => self::sha('9'),
            'a-c' => self::sha('x'),
            'a/b' => self::sha('3'),
            'y' => self::sha('5'),
        ];
        $this->assertSame(
            ['modified' => ['a-c'], 'added' => ['a-b', 'y'], 'deleted' => ['z']],
            $diff->compare(self::official($official), $local)
        );
    }

    public function test_a_list_without_the_last_line_feed_is_accepted(): void {
        $diff = new core_diff(self::moodle(), []);
        $gz = gzencode(rtrim((string) gzdecode(self::official(['a.php' => self::sha('a')])), "\n"));
        $this->assertSame(
            ['modified' => [], 'added' => [], 'deleted' => []],
            $diff->compare($gz, ['a.php' => self::sha('a')])
        );
    }

    public function test_a_local_list_that_is_not_in_order_is_a_mistake_of_the_caller(): void {
        $diff = new core_diff(self::moodle(), []);
        $this->expectException(\coding_exception::class);
        $diff->compare(self::official([]), ['b.php' => self::sha('b'), 'a.php' => self::sha('a')]);
    }

    public function test_a_list_for_another_version_or_build_is_a_mismatch(): void {
        $diff = new core_diff(self::moodle(), []);
        $this->expect_code('core_manifest_mismatch', fn() => $diff->compare(
            self::official([], ['version' => '2024100703.11']),
            []
        ));
        $this->expect_code('core_manifest_mismatch', fn() => $diff->compare(
            self::official([], ['build' => '20250114']),
            []
        ));
        // The version is judged before the lines, so a list of another version with bad lines is a mismatch too.
        $gz = gzencode(json_encode([
            'v' => 1, 'version' => '2024100703.11', 'build' => self::BUILD, 'release' => 'r', 'count' => 5,
        ]) . "\nnot a line\n");
        $this->expect_code('core_manifest_mismatch', fn() => $diff->compare($gz, []));
    }

    /**
     * Lists that cannot be used.
     *
     * @return array
     */
    public static function bad_list_provider(): array {
        $header = static fn(array $change = []): string => json_encode(array_merge([
            'v' => 1, 'version' => self::VERSION, 'build' => self::BUILD, 'release' => 'r', 'count' => 1,
        ], $change));
        $hash = str_repeat('a', 64);
        $other = str_repeat('b', 64);
        return [
            'not gzip' => ['plain text'],
            'empty' => [''],
            'empty text' => [gzencode('')],
            'header is not JSON' => [gzencode("{broken\n")],
            'header is a list' => [gzencode("[1]\n")],
            'header is a number' => [gzencode("1\n")],
            'version 2' => [gzencode($header(['v' => 2]) . "\na\t$hash\n")],
            'v is text' => [gzencode($header(['v' => '1']) . "\na\t$hash\n")],
            'extra field' => [gzencode($header(['extra' => 1]) . "\na\t$hash\n")],
            'missing field' => [gzencode(json_encode(['v' => 1, 'version' => self::VERSION, 'build' => self::BUILD, 'count' => 0]) .
                "\n")],
            'version is a number' => [gzencode($header(['version' => 2024100703.1]) . "\na\t$hash\n")],
            'release is a number' => [gzencode($header(['release' => 5]) . "\na\t$hash\n")],
            'count is text' => [gzencode($header(['count' => '1']) . "\na\t$hash\n")],
            'count is negative' => [gzencode($header(['count' => -1]) . "\n")],
            'count is a fraction' => [gzencode($header(['count' => 1.5]) . "\na\t$hash\n")],
            'no tab' => [gzencode($header() . "\na $hash\n")],
            'two tabs' => [gzencode($header() . "\na\t$hash\tx\n")],
            'hash in capitals' => [gzencode($header() . "\na\t" . strtoupper($hash) . "\n")],
            'hash too short' => [gzencode($header() . "\na\t" . substr($hash, 1) . "\n")],
            'hash too long' => [gzencode($header() . "\na\t" . $hash . "a\n")],
            'hash with a letter g' => [gzencode($header() . "\na\t" . substr($hash, 1) . "g\n")],
            'empty path' => [gzencode($header() . "\n\t$hash\n")],
            'carriage return' => [gzencode($header() . "\na\t$hash\r\n")],
            'empty line between' => [gzencode($header(['count' => 2]) . "\na\t$hash\n\nb\t$hash\n")],
            'empty line at the end' => [gzencode($header() . "\na\t$hash\n\n")],
            'same path twice' => [gzencode($header(['count' => 2]) . "\na\t$hash\na\t$other\n")],
            'paths going down' => [gzencode($header(['count' => 2]) . "\nb\t$hash\na\t$hash\n")],
            'fewer lines than the count' => [gzencode($header(['count' => 2]) . "\na\t$hash\n")],
            'more lines than the count' => [gzencode($header() . "\na\t$hash\nb\t$hash\n")],
            'count of a header without lines' => [gzencode($header(['count' => 1]) . "\n")],
            'bad gzip trailer' => [substr(gzencode($header() . "\na\t$hash\n"), 0, -6)],
        ];
    }

    /**
     * A list that cannot be used is invalid.
     *
     * @dataProvider bad_list_provider
     * @param string $gzip The list.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_list_provider')]
    public function test_a_list_that_cannot_be_used_is_invalid(string $gzip): void {
        $diff = new core_diff(self::moodle(), []);
        $this->expect_code('core_manifest_invalid', fn() => $diff->compare($gzip, []));
    }

    public function test_a_list_that_expands_beyond_the_limit_is_invalid(): void {
        $diff = new core_diff(self::moodle(), []);
        $hash = str_repeat('a', 64);
        $text = self::header_line(1) . "\n";
        $gz = gzencode($text . "a\t$hash\n" . str_repeat("\n", core_diff::MAX_LIST_BYTES));
        $this->expect_code('core_manifest_invalid', fn() => $diff->compare($gz, []), 'unreadable');
    }

    /**
     * The first line of a list of the files of this test.
     *
     * @param int $count The number of files.
     * @return string
     */
    private static function header_line(int $count): string {
        return json_encode([
            'v' => 1, 'version' => self::VERSION, 'build' => self::BUILD, 'release' => 'r', 'count' => $count,
        ]);
    }

    /**
     * Runs a call that has to throw a plugin_exception with a code.
     *
     * @param string $code The code.
     * @param \Closure $call The call.
     * @param string|null $debuginfo The detail that the exception has to carry, if it matters.
     */
    private function expect_code(string $code, \Closure $call, ?string $debuginfo = null): void {
        try {
            $call();
        } catch (plugin_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame($debuginfo, $debuginfo === null ? null : $e->debuginfo);
            $this->assertNotSame('', $e->message());
            return;
        }
        $this->fail('A plugin_exception with ' . $code . ' was expected.');
    }

    // The list from the service.

    /**
     * A fake service where this key pair is enrolled.
     *
     * @param signing_keypair $pair The key pair.
     * @param array $state Changes to the state.
     * @return fake_service
     */
    private static function service(signing_keypair $pair, array $state = []): fake_service {
        return new fake_service(self::API, array_merge(
            fake_service::initial_state(),
            ['now' => self::NOW, 'signingkey' => base64_encode($pair->public_key())],
            $state
        ));
    }

    /**
     * The client and the storage transport for a service.
     *
     * @param fake_service $service The service.
     * @return array [client, object_transport]
     */
    private static function connections(fake_service $service): array {
        $settings = developer_settings::from_value(['allowprivatehosts' => true]);
        return [
            network_fakes::client($service->handler(), self::NOW, $settings),
            new object_transport($settings, network_fakes::passing_s3_guard(), $service->handler()),
        ];
    }

    /**
     * Fetches the list of the official files for the folder of this test.
     *
     * @param fake_service $service The service.
     * @param signing_keypair $pair The key pair.
     * @param string $tempdir The temporary folder.
     * @return string|null
     */
    private static function fetch(fake_service $service, signing_keypair $pair, string $tempdir): ?string {
        [$client, $objects] = self::connections($service);
        $diff = new core_diff(self::moodle(), []);
        return $diff->fetch_official(
            $client,
            endpoint::parse(self::API, developer_settings::from_value(null)),
            $pair,
            fake_service::SITE_ID,
            $objects,
            $tempdir
        );
    }

    public function test_the_official_list_is_fetched_and_verified_and_the_temporary_file_is_removed(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $gz = self::official(['a.php' => self::sha('a')]);
        $service->publish_core_manifest(self::VERSION, self::BUILD, $gz);
        $tempdir = make_request_directory();
        $this->assertSame($gz, self::fetch($service, $pair, $tempdir));
        $this->assertContains('POST /api/lms-backup/v1/core-manifests', $service->state['requests']);
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_a_service_without_a_list_gives_null(): void {
        $pair = signing_keypair::generate();
        $tempdir = make_request_directory();
        $this->assertNull(self::fetch(self::service($pair), $pair, $tempdir));
        $this->assertSame([], glob($tempdir . '/*'));
    }

    /**
     * Signed values that do not describe the object that is stored.
     *
     * @return array
     */
    public static function wrong_signed_value_provider(): array {
        return [
            'hash' => [['sha256' => base64_encode(hash('sha256', 'something else', true))]],
            'size larger than the object' => [['size' => 100000]],
        ];
    }

    /**
     * An object that is not as signed gives null.
     *
     * @dataProvider wrong_signed_value_provider
     * @param array $payload The values of the signed envelope that are replaced.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrong_signed_value_provider')]
    public function test_an_object_that_is_not_as_signed_gives_null_and_leaves_no_file(array $payload): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $service->publish_core_manifest(self::VERSION, self::BUILD, self::official(['a.php' => self::sha('a')]), $payload);
        $tempdir = make_request_directory();
        $this->assertNull(self::fetch($service, $pair, $tempdir));
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_a_list_signed_larger_than_the_limit_is_not_fetched(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $service->publish_core_manifest(self::VERSION, self::BUILD, self::official([]), ['size' => core_diff::MAX_LIST_BYTES + 1]);
        $tempdir = make_request_directory();
        $this->assertNull(self::fetch($service, $pair, $tempdir));
        $this->assertSame(['POST /api/lms-backup/v1/core-manifests'], array_values(array_filter(
            $service->state['requests'],
            static fn(string $request): bool => str_contains($request, 'core-manifests') || str_contains($request, '_s3')
        )));
    }

    public function test_an_object_that_is_smaller_than_signed_gives_null(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $gz = self::official(['a.php' => self::sha('a')]);
        $service->publish_core_manifest(self::VERSION, self::BUILD, $gz, ['size' => strlen($gz) + 1]);
        $tempdir = make_request_directory();
        $this->assertNull(self::fetch($service, $pair, $tempdir));
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_an_object_larger_than_signed_is_a_transport_error_that_is_not_hidden(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $gz = self::official(['a.php' => self::sha('a')]);
        $service->publish_core_manifest(self::VERSION, self::BUILD, $gz, ['size' => strlen($gz) - 1]);
        $tempdir = make_request_directory();
        try {
            self::fetch($service, $pair, $tempdir);
            $this->fail('The transport error was expected.');
        } catch (plugin_exception $e) {
            $this->assertSame('transport_too_large', $e->errorcode);
        }
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_an_object_that_the_storage_does_not_have_gives_null(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $service->publish_core_manifest(self::VERSION, self::BUILD, self::official([]));
        unset($service->state['objects']['core-manifests/' . self::VERSION . '/' . self::BUILD . '.gz']);
        $tempdir = make_request_directory();
        $this->assertNull(self::fetch($service, $pair, $tempdir));
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_an_error_of_the_service_is_not_hidden_and_leaves_no_file(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair, ['mode' => 'run_not_found']);
        $tempdir = make_request_directory();
        try {
            self::fetch($service, $pair, $tempdir);
            $this->fail('The error of the service was expected.');
        } catch (plugin_exception $e) {
            $this->assertSame('api_run_not_found', $e->errorcode);
        }
        $this->assertSame([], glob($tempdir . '/*'));
    }

    public function test_a_temporary_folder_that_cannot_be_used_is_a_mistake_of_the_caller(): void {
        $pair = signing_keypair::generate();
        $service = self::service($pair);
        $service->publish_core_manifest(self::VERSION, self::BUILD, self::official([]));
        $this->expectException(\coding_exception::class);
        self::fetch($service, $pair, make_request_directory() . '/missing');
    }
}
