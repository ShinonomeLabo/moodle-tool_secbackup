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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\restore\plugin_dirs::class)]
/**
 * Tests for the folders of the plugins in the Moodle code that a restore has written.
 *
 * The trees are small copies of the real layouts: Moodle 4.5 and 5.0 keep lib/components.json at the root with folders
 * relative to it ("mod"), Moodle 5.1 and later keep it at the root too, outside public/, with folders that start with
 * public/ ("public/mod").
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\restore\plugin_dirs
 */
final class plugin_dirs_test extends \advanced_testcase {
    /** @var string[] Plugin types of the tree of Moodle 4.5, as lib/components.json has them (a part). */
    private const TYPES_405 = [
        'mod' => 'mod',
        'auth' => 'auth',
        'block' => 'blocks',
        'editor' => 'lib/editor',
        'tool' => 'admin/tool',
        'local' => 'local',
    ];

    /** @var array Subsystems of the trees (a part): one with a folder and two without, as lib/components.json could have them. */
    private const SUBSYSTEMS = ['course' => 'course', 'help' => null, 'pagetype' => ''];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Writes a file of a tree, with the folders on the way.
     *
     * @param string $root The root of the tree.
     * @param string $path The path in the tree.
     * @param string $content The content.
     */
    private static function put(string $root, string $path, string $content): void {
        if (!is_dir(dirname($root . '/' . $path))) {
            mkdir(dirname($root . '/' . $path), 0777, true);
        }
        file_put_contents($root . '/' . $path, $content);
    }

    /**
     * A tree of Moodle 4.5 with mod_assign and its subplugin types.
     *
     * @return string The root.
     */
    private static function tree_405(): string {
        $root = make_request_directory();
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => self::TYPES_405, 'subsystems' => self::SUBSYSTEMS]));
        self::put($root, 'mod/assign/db/subplugins.json', json_encode([
            'plugintypes' => ['assignsubmission' => 'mod/assign/submission', 'assignfeedback' => 'mod/assign/feedback'],
        ]));
        return $root;
    }

    /**
     * A tree of Moodle 5.1 or later with mod_assign and its subplugin types.
     *
     * @return string The root.
     */
    private static function tree_501(): string {
        $root = make_request_directory();
        $types = array_map(static fn(string $folder): string => 'public/' . $folder, self::TYPES_405);
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => $types, 'subsystems' => self::SUBSYSTEMS]));
        self::put($root, 'public/version.php', '<?php');
        self::put($root, 'public/mod/assign/db/subplugins.json', json_encode([
            'subplugintypes' => ['assignsubmission' => 'submission', 'assignfeedback' => 'feedback'],
            'plugintypes' => ['assignsubmission' => 'mod/assign/submission', 'assignfeedback' => 'mod/assign/feedback'],
        ]));
        return $root;
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

    public function test_the_plugins_of_a_tree_of_moodle_405_are_where_its_components_say(): void {
        $dirs = new plugin_dirs(self::tree_405());
        $this->assertSame('mod/attendance', $dirs->folder('mod_attendance'));
        $this->assertSame('admin/tool/foo', $dirs->folder('tool_foo'));
        $this->assertSame('local/my_plugin', $dirs->folder('local_my_plugin'));
        $this->assertSame('blocks/x1', $dirs->folder('block_x1'));
    }

    public function test_the_plugins_of_a_tree_of_moodle_501_are_under_public(): void {
        $dirs = new plugin_dirs(self::tree_501());
        $this->assertSame('public/mod/attendance', $dirs->folder('mod_attendance'));
        $this->assertSame('public/admin/tool/foo', $dirs->folder('tool_foo'));
    }

    public function test_a_subplugin_is_where_its_parent_says(): void {
        $dirs = new plugin_dirs(self::tree_405());
        $this->assertSame('mod/assign/submission/foo', $dirs->folder('assignsubmission_foo'));
        $this->assertSame('mod/assign/feedback/bar', $dirs->folder('assignfeedback_bar'));
    }

    public function test_a_subplugin_of_a_tree_of_moodle_501_is_under_public_with_either_way_of_naming_its_folder(): void {
        $root = self::tree_501();
        $dirs = new plugin_dirs($root);
        // With subplugintypes: relative to the folder of the parent.
        $this->assertSame('public/mod/assign/submission/foo', $dirs->folder('assignsubmission_foo'));
        // With plugintypes only: relative to public/, which is added when it is not there.
        self::put($root, 'public/mod/workshop/db/subplugins.json', json_encode([
            'plugintypes' => ['workshopform' => 'mod/workshop/form', 'workshopallocation' => 'public/mod/workshop/allocation'],
        ]));
        $this->assertSame('public/mod/workshop/form/rubric2', $dirs->folder('workshopform_rubric2'));
        $this->assertSame('public/mod/workshop/allocation/random2', $dirs->folder('workshopallocation_random2'));
    }

    public function test_subplugintypes_come_before_plugintypes_and_plugintypes_are_not_prefixed_before_moodle_501(): void {
        $root = self::tree_405();
        self::put($root, 'mod/quiz/db/subplugins.json', json_encode([
            'subplugintypes' => ['quiz' => 'report'],
            'plugintypes' => ['quiz' => 'elsewhere/report', 'quizaccess' => 'mod/quiz/accessrule'],
        ]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('mod/quiz/report/x', $dirs->folder('quiz_x'));
        // A file with subplugintypes does not read its plugintypes, as Moodle does not.
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('quizaccess_y')));
    }

    public function test_subplugin_types_are_read_from_the_parents_moodle_reads_them_from_and_the_first_one_counts(): void {
        $root = self::tree_405();
        // A block cannot have subplugins: Moodle reads them from mod, editor, tool and local only.
        self::put($root, 'blocks/x/db/subplugins.json', json_encode(['plugintypes' => ['blockx' => 'blocks/x/sub']]));
        // The same type in two parents: mod comes before tool, and local comes last; in a type, the names in byte order.
        self::put($root, 'mod/zeta/db/subplugins.json', json_encode(['plugintypes' => ['shared' => 'mod/zeta/shared']]));
        self::put($root, 'mod/alpha/db/subplugins.json', json_encode(['plugintypes' => ['shared' => 'mod/alpha/shared']]));
        self::put($root, 'admin/tool/aa/db/subplugins.json', json_encode(['plugintypes' => ['shared' => 'admin/tool/aa/s']]));
        self::put($root, 'local/aa/db/subplugins.json', json_encode([
            'plugintypes' => ['shared' => 'local/aa/s', 'localsub' => 'local/aa/sub'],
        ]));
        self::put($root, 'lib/editor/tiny/db/subplugins.json', json_encode([
            'plugintypes' => ['tiny' => 'lib/editor/tiny/plugins'],
        ]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('blockx_y')));
        $this->assertSame('mod/alpha/shared/y', $dirs->folder('shared_y'));
        $this->assertSame('local/aa/sub/y', $dirs->folder('localsub_y'));
        $this->assertSame('lib/editor/tiny/plugins/media2', $dirs->folder('tiny_media2'));
    }

    public function test_a_plugin_type_of_the_components_comes_before_a_subplugin_type_of_the_same_name(): void {
        $root = self::tree_405();
        self::put($root, 'mod/odd/db/subplugins.json', json_encode(['plugintypes' => ['auth' => 'mod/odd/auth']]));
        $this->assertSame('auth/x', (new plugin_dirs($root))->folder('auth_x'));
    }

    public function test_a_subplugin_type_added_to_the_tree_later_is_found(): void {
        $root = self::tree_405();
        $dirs = new plugin_dirs($root);
        $this->assertSame('mod/attendance', $dirs->folder('mod_attendance'));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('attendancesub_x')));
        self::put($root, 'mod/attendance/db/subplugins.json', json_encode([
            'subplugintypes' => ['attendancesub' => 'sub'],
        ]));
        $this->assertSame('mod/attendance/sub/x', $dirs->folder('attendancesub_x'));
    }

    public function test_a_broken_subplugins_file_is_passed_over(): void {
        $root = self::tree_405();
        self::put($root, 'mod/aaa/db/subplugins.json', '{"plugintypes":');
        self::put($root, 'mod/bbb/db/subplugins.json', json_encode(['plugintypes' => ['bsub' => ['not', 'text']]]));
        self::put($root, 'mod/ccc/db/subplugins.json', json_encode(['plugintypes' => 'not a list']));
        self::put($root, 'mod/ddd/db/subplugins.json', json_encode(['plugintypes' => ['bsub' => 'mod/ddd/sub']]));
        self::put($root, 'mod/Upper/db/subplugins.json', json_encode(['plugintypes' => ['upper' => 'mod/Upper/sub']]));
        mkdir($root . '/mod/eee/db/subplugins.json', 0777, true);
        $dirs = new plugin_dirs($root);
        $this->assertSame('mod/ddd/sub/x', $dirs->folder('bsub_x'));
        $this->assertSame('mod/assign/submission/x', $dirs->folder('assignsubmission_x'));
        // A folder that is not named as a plugin is not a plugin.
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('upper_x')));
    }

    /**
     * Components whose type or name is not of the form of Moodle.
     *
     * @return array
     */
    public static function bad_component_provider(): array {
        return [
            'no underscore' => ['attendance'],
            'empty name' => ['mod_'],
            'empty type' => ['_attendance'],
            'upper case in the name' => ['mod_Attendance'],
            'upper case in the type' => ['Mod_attendance'],
            'digit first in the name' => ['mod_1x'],
            'digit first in the type' => ['1mod_x'],
            'dash' => ['mod_a-b'],
            'slash' => ['mod_a/b'],
            'parent folder' => ['mod_../x'],
            'line feed at the end' => ["mod_x\n"],
            'line feed at the end of the type' => ["mod\n_x"],
            'nul' => ["mod_x\0"],
            'unknown type' => ['nosuchtype_x'],
            'core' => ['core_course'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_component_provider')]
    /**
     * A component of a form Moodle does not have, or of a type the tree does not know, has no folder.
     *
     * @dataProvider bad_component_provider
     * @param string $component The component.
     */
    public function test_a_component_without_a_known_type_or_of_a_bad_form_has_no_folder(string $component): void {
        $dirs = new plugin_dirs(self::tree_405());
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder($component)));
    }

    /**
     * Contents of lib/components.json that do not give folders.
     *
     * @return array
     */
    public static function bad_components_provider(): array {
        return [
            'missing' => [null],
            'not json' => ['{"plugintypes":'],
            'no plugin types' => [json_encode(['subsystems' => []])],
            'plugin types not a list' => [json_encode(['plugintypes' => 'mod'])],
            'folder not text' => [json_encode(['plugintypes' => ['mod' => ['mod']]])],
            'folder null' => [json_encode(['plugintypes' => ['mod' => null]])],
            'parent folder' => [json_encode(['plugintypes' => ['mod' => '../outside']])],
            'absolute folder' => [json_encode(['plugintypes' => ['mod' => '/etc']])],
            'empty folder' => [json_encode(['plugintypes' => ['mod' => '']])],
            'trailing slash' => [json_encode(['plugintypes' => ['mod' => 'mod/']])],
            'backslash' => [json_encode(['plugintypes' => ['mod' => 'mod\\x']])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_components_provider')]
    /**
     * A lib/components.json that is missing, broken, or names a folder that is not a plain relative path gives no folder.
     *
     * @dataProvider bad_components_provider
     * @param string|null $content The content of lib/components.json, or null for none.
     */
    public function test_components_that_do_not_give_a_plain_folder_give_no_folder(?string $content): void {
        $root = make_request_directory();
        if ($content !== null) {
            self::put($root, 'lib/components.json', $content);
        }
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('mod_attendance')));
    }

    public function test_a_subplugin_folder_that_is_not_a_plain_relative_path_is_refused(): void {
        $root = self::tree_405();
        self::put($root, 'mod/evil/db/subplugins.json', json_encode([
            'subplugintypes' => ['evilup' => '../../../etc', 'evilabs' => '/etc', 'evilempty' => ''],
        ]));
        self::put($root, 'local/evil/db/subplugins.json', json_encode(['plugintypes' => ['evilroot' => '/etc/cron.d']]));
        $dirs = new plugin_dirs($root);
        foreach (['evilup_x', 'evilabs_x', 'evilempty_x', 'evilroot_x'] as $component) {
            $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder($component)), $component);
        }
    }

    public function test_components_are_read_once_and_a_missing_file_is_read_again(): void {
        $root = make_request_directory();
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('mod_attendance')));
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => ['mod' => 'mod']]));
        $this->assertSame('mod/attendance', $dirs->folder('mod_attendance'));
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => ['mod' => 'changed']]));
        $this->assertSame('mod/attendance', $dirs->folder('mod_attendance'));
    }

    public function test_a_type_with_a_line_feed_at_the_end_is_not_looked_up(): void {
        $root = make_request_directory();
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => ["mod\n" => 'mod', 'mod' => 'mod']]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder("mod\n_x")));
    }

    /**
     * Folders of a parent type in lib/components.json that are not plain relative paths.
     *
     * @return array
     */
    public static function bad_parent_folder_provider(): array {
        return [
            'nul' => ["mo\0d"],
            'out of the tree' => ['../outside'],
            'absolute' => ['/outside'],
            'not text' => [['mod']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad_parent_folder_provider')]
    /**
     * The folder of a parent type is checked before it is listed: a folder that is not plain stops the lookup.
     *
     * @dataProvider bad_parent_folder_provider
     * @param mixed $folder The folder that lib/components.json gives to the type mod.
     */
    public function test_a_parent_folder_that_is_not_a_plain_relative_path_stops_before_it_is_read(mixed $folder): void {
        $dir = make_request_directory();
        $root = $dir . '/root';
        // What lies where the folder leads, out of the tree, would give a plain folder.
        self::put($dir, 'outside/x/db/subplugins.json', json_encode([
            'plugintypes' => ['assignsubmission' => 'mod/assign/submission'],
        ]));
        self::put($root, 'lib/components.json', json_encode(['plugintypes' => ['mod' => $folder, 'local' => 'local']]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('assignsubmission_x')));
    }

    /**
     * Folders in the folder of a parent type that core_component does not take as plugins.
     *
     * @return array
     */
    public static function not_a_plugin_provider(): array {
        $cases = [
            'activity module with an underscore' => ['mod', 'a_x'],
            'activity module named as a subsystem with a folder' => ['mod', 'course'],
            'name ending with an underscore' => ['admin/tool', 'xy_'],
            'name with two underscores' => ['admin/tool', 'ab__c'],
            'name of one letter' => ['local', 'x'],
            'name with a digit first' => ['local', '1x'],
        ];
        foreach (['amd', 'classes', 'db', 'fonts', 'lang', 'pix', 'simpletest', 'templates', 'tests', 'yui'] as $ignored) {
            $cases['folder ' . $ignored] = ['mod', $ignored];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('not_a_plugin_provider')]
    /**
     * A folder that core_component does not take as a plugin of a parent type declares no subplugins.
     *
     * @dataProvider not_a_plugin_provider
     * @param string $parent The folder of the parent type.
     * @param string $name The name of the folder in it.
     */
    public function test_a_folder_that_moodle_does_not_take_as_a_plugin_declares_no_subplugins(string $parent, string $name): void {
        $root = self::tree_405();
        self::put($root, $parent . '/' . $name . '/db/subplugins.json', json_encode(['subplugintypes' => ['odd' => 'odd']]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('odd_y')));
    }

    public function test_the_plugins_that_moodle_takes_declare_subplugins(): void {
        $root = self::tree_405();
        // Other plugins may have single underscores, and an activity module may have the name of a subsystem without a folder.
        self::put($root, 'admin/tool/a_b/db/subplugins.json', json_encode(['subplugintypes' => ['toolsub' => 'sub']]));
        // The pattern of core_component does not look at two underscores right after the first letter, so neither does this.
        self::put($root, 'admin/tool/a__b/db/subplugins.json', json_encode(['subplugintypes' => ['twosub' => 'sub']]));
        self::put($root, 'mod/help/db/subplugins.json', json_encode(['subplugintypes' => ['helpsub' => 'sub']]));
        self::put($root, 'mod/pagetype/db/subplugins.json', json_encode(['subplugintypes' => ['pagesub' => 'sub']]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('admin/tool/a_b/sub/y', $dirs->folder('toolsub_y'));
        $this->assertSame('admin/tool/a__b/sub/y', $dirs->folder('twosub_y'));
        $this->assertSame('mod/help/sub/y', $dirs->folder('helpsub_y'));
        $this->assertSame('mod/pagetype/sub/y', $dirs->folder('pagesub_y'));
    }

    public function test_a_folder_that_is_not_a_plugin_does_not_come_before_the_real_parent(): void {
        $root = self::tree_405();
        // In byte order "a_x" comes before "assign", but an activity module cannot have an underscore.
        self::put($root, 'mod/a_x/db/subplugins.json', json_encode(['plugintypes' => ['assignsubmission' => 'mod/a_x/sub']]));
        $this->assertSame('mod/assign/submission/foo', (new plugin_dirs($root))->folder('assignsubmission_foo'));
    }

    public function test_a_subplugin_type_with_the_name_of_a_subsystem_with_a_folder_is_passed_over(): void {
        $root = self::tree_405();
        self::put($root, 'mod/odd/db/subplugins.json', json_encode([
            'plugintypes' => ['course' => 'mod/odd/course', 'help' => 'mod/odd/help', 'oddsub' => 'mod/odd/sub'],
        ]));
        $dirs = new plugin_dirs($root);
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('course_x')));
        $this->assertSame('mod/odd/help/x', $dirs->folder('help_x'));
        $this->assertSame('mod/odd/sub/x', $dirs->folder('oddsub_x'));
    }

    public function test_the_plugins_of_a_type_are_taken_in_byte_order_whatever_the_locale(): void {
        $root = self::tree_405();
        self::put($root, 'admin/tool/ab/db/subplugins.json', json_encode(['plugintypes' => ['shared' => 'admin/tool/ab/s']]));
        self::put($root, 'admin/tool/a_c/db/subplugins.json', json_encode(['plugintypes' => ['shared' => 'admin/tool/a_c/s']]));
        $old = setlocale(LC_COLLATE, '0');
        // The locale of the language of a site may put "ab" before "a_c"; core_component sorts the bytes.
        $this->assertNotFalse(setlocale(LC_COLLATE, 'en_AU.UTF-8'));
        try {
            $folder = (new plugin_dirs($root))->folder('shared_y');
        } finally {
            setlocale(LC_COLLATE, $old);
        }
        $this->assertSame('admin/tool/a_c/s/y', $folder);
    }

    public function test_a_deprecated_plugin_type_and_a_deprecated_subplugin_type_have_their_folders(): void {
        $root = make_request_directory();
        self::put($root, 'lib/components.json', json_encode([
            'plugintypes' => ['mod' => 'public/mod', 'local' => 'public/local'],
            'deprecatedplugintypes' => ['mnetservice' => 'public/mnet/service', 'mod' => 'public/elsewhere'],
            'subsystems' => [],
        ]));
        self::put($root, 'public/mod/assign/db/subplugins.json', json_encode([
            'subplugintypes' => ['assignsubmission' => 'submission'],
            'deprecatedsubplugintypes' => ['assignold' => 'old', 'assignsubmission' => 'elsewhere'],
        ]));
        self::put($root, 'public/mod/quiz/db/subplugins.json', json_encode([
            'plugintypes' => ['quiz' => 'mod/quiz/report'],
            'deprecatedsubplugintypes' => ['quizold' => 'old', 'quizbad' => ['old']],
        ]));
        self::put($root, 'public/local/odd/db/subplugins.json', json_encode(['deprecatedsubplugintypes' => 'not a list']));
        self::put($root, 'public/version.php', '<?php');
        $dirs = new plugin_dirs($root);
        $this->assertSame('public/mnet/service/enrol2', $dirs->folder('mnetservice_enrol2'));
        // A type that is both active and deprecated has its active folder.
        $this->assertSame('public/mod/x', $dirs->folder('mod_x'));
        $this->assertSame('public/mod/assign/old/x', $dirs->folder('assignold_x'));
        $this->assertSame('public/mod/assign/submission/x', $dirs->folder('assignsubmission_x'));
        $this->assertSame('public/mod/quiz/report/x', $dirs->folder('quiz_x'));
        $this->assertSame('public/mod/quiz/old/x', $dirs->folder('quizold_x'));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('quizbad_x')));
    }

    public function test_deprecated_plugin_types_that_are_not_a_list_are_none(): void {
        $root = self::tree_405();
        $components = json_decode(file_get_contents($root . '/lib/components.json'), true);
        file_put_contents($root . '/lib/components.json', json_encode($components + ['deprecatedplugintypes' => 'mnet/service']));
        $dirs = new plugin_dirs($root);
        $this->assertSame('mod/x', $dirs->folder('mod_x'));
        $this->assertSame('restore_path_unsafe', $this->code_of(static fn() => $dirs->folder('mnetservice_x')));
    }
}
