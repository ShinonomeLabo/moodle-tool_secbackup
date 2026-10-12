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
 * Development only: fills a site with dummy courses, users, posts, submissions, attempts and files.
 *
 * Usage: php <plugin>/.dev/env/generate_site_data.php --size=S|M [--seed=1] [--force]
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// The same relative path reaches config.php on 4.5 (admin/tool/...) and on 5.1+ (public/admin/tool/...).
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
// The question generator needs PHPUnit's classes. Moodle 4.5 loads Composer's autoloader only for PHPUnit and Behat.
// Moodle 5.3 always loads it and keeps vendor/ above $CFG->dirroot (public/), so this does nothing there.
if (is_file($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

[$options] = cli_get_params(['size' => 'S', 'seed' => 1, 'force' => false], []);
$sizes = [
    'S' => ['courses' => 3, 'users' => 30, 'files' => 60, 'large' => 2],
    'M' => ['courses' => 10, 'users' => 200, 'files' => 600, 'large' => 10],
];
if (!isset($sizes[$options['size']])) {
    cli_error('--size must be S or M');
}
$size = $sizes[$options['size']];
mt_srand((int) $options['seed']);

if (!$options['force'] && $DB->record_exists_select('user', $DB->sql_like('username', ':p'), ['p' => 'secbk\_%'])) {
    cli_writeln('Dummy data already exists. Use --force to add more.');
    exit(0);
}

\core\session\manager::set_user(get_admin());
$generator = new testing_data_generator();
$fs = get_file_storage();
$run = time();

/**
 * Returns deterministic pseudo-random bytes.
 *
 * @param int $length Number of bytes.
 * @return string
 */
function secbk_dev_bytes(int $length): string {
    $out = '';
    while (strlen($out) < $length) {
        $out .= pack('N', mt_rand());
    }
    return substr($out, 0, $length);
}

/**
 * Stores one file and returns it. Some contents repeat on purpose (deduplication tests).
 *
 * @param file_storage $fs File storage.
 * @param array $where contextid, component, filearea, itemid.
 * @param string $name File name.
 * @param int $length File size in bytes.
 * @return stored_file
 */
function secbk_dev_file(file_storage $fs, array $where, string $name, int $length): stored_file {
    static $repeat = null;
    if ($repeat === null) {
        $repeat = secbk_dev_bytes(4096);
    }
    $content = (mt_rand(1, 10) === 1) ? $repeat : secbk_dev_bytes($length);
    return $fs->create_file_from_string($where + ['filepath' => '/', 'filename' => $name], $content);
}

$users = [];
for ($i = 1; $i <= $size['users']; $i++) {
    $users[] = $generator->create_user([
        'username' => 'secbk_' . $run . '_' . $i,
        'firstname' => 'Test',
        'lastname' => 'User' . $i,
        'email' => 'secbk' . $run . '_' . $i . '@example.com',
    ]);
}

$files = 0;
$filesper = (int) ceil($size['files'] / $size['courses']);
$questiongenerator = $generator->get_plugin_generator('core_question');
$quizgenerator = $generator->get_plugin_generator('mod_quiz');
$assigngenerator = $generator->get_plugin_generator('mod_assign');
$forumgenerator = $generator->get_plugin_generator('mod_forum');

for ($c = 1; $c <= $size['courses']; $c++) {
    $course = $generator->create_course(['fullname' => "Test course $c", 'shortname' => "secbk-$run-$c"]);
    $teacher = $users[($c - 1) % count($users)];
    $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
    $students = [];
    foreach ($users as $index => $user) {
        if ($index % $size['courses'] === ($c - 1) % $size['courses'] || $index < 10) {
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
    }

    // Course files in a resource module.
    $resource = $generator->create_module('resource', ['course' => $course->id]);
    $resourcecontext = context_module::instance($resource->cmid);
    for ($f = 1; $f <= $filesper; $f++) {
        $length = ($f <= $size['large'] && $c === 1) ? mt_rand(5, 20) * 1024 * 1024 : mt_rand(1, 2048) * 1024;
        secbk_dev_file($fs, ['contextid' => $resourcecontext->id, 'component' => 'mod_resource',
            'filearea' => 'content', 'itemid' => 0], "file-$f.bin", $length);
        $files++;
    }

    // Forum posts, some with attachments.
    $forum = $generator->create_module('forum', ['course' => $course->id]);
    $forumcontext = context_module::instance($forum->cmid);
    foreach (array_slice($students, 0, 5) as $student) {
        $discussion = $forumgenerator->create_discussion(['course' => $course->id, 'forum' => $forum->id,
            'userid' => $student->id, 'message' => 'Hello from ' . fullname($student)]);
        $post = $forumgenerator->create_post(['discussion' => $discussion->id, 'userid' => $teacher->id,
            'parent' => $discussion->firstpost, 'message' => 'Reply']);
        secbk_dev_file($fs, ['contextid' => $forumcontext->id, 'component' => 'mod_forum',
            'filearea' => 'attachment', 'itemid' => $post->id], 'attachment.bin', mt_rand(1, 512) * 1024);
        $files++;
    }

    // Assignment submissions with online text and a file.
    // The file submission plugin reads its maximum size setting without a default, so it is given here.
    $assign = $generator->create_module('assign', ['course' => $course->id,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
        'assignsubmission_file_maxfiles' => 3, 'assignsubmission_file_maxsizebytes' => 2 * 1024 * 1024]);
    $assigncontext = context_module::instance($assign->cmid);
    foreach (array_slice($students, 0, 5) as $student) {
        // The generator takes the online text under the plugin name ("onlinetext").
        $assigngenerator->create_submission(['userid' => $student->id, 'cmid' => $assign->cmid,
            'onlinetext' => 'Submission of ' . fullname($student)]);
        $submission = $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $student->id,
            'latest' => 1], '*', MUST_EXIST);
        secbk_dev_file($fs, ['contextid' => $assigncontext->id, 'component' => 'assignsubmission_file',
            'filearea' => 'submission_files', 'itemid' => $submission->id], 'report.bin', mt_rand(16, 1024) * 1024);
        $files++;
    }

    // A quiz with two questions and finished attempts.
    $quiz = $quizgenerator->create_instance(['course' => $course->id, 'questionsperpage' => 0, 'grade' => 100.0,
        'sumgrades' => 2]);
    $category = $questiongenerator->create_question_category();
    $tf = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
    $sa = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
    quiz_add_quiz_question($tf->id, $quiz);
    quiz_add_quiz_question($sa->id, $quiz);
    foreach (array_slice($students, 0, 3) as $student) {
        // The generator starts the attempt for the current user, so the student has to be the current user.
        \core\session\manager::init_empty_session();
        \core\session\manager::set_user(clone $student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        // The generator's submit_responses() refuses to run outside unit tests, so the answers go through the
        // public attempt API, the way the generator does it.
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $postdata = [];
        foreach ([1 => 'True', 2 => 'frog'] as $slot => $response) {
            $postdata += $questiongenerator->get_simulated_post_data_for_question_attempt(
                $attemptobj->get_question_attempt($slot), $response, false);
        }
        $attemptobj->process_submitted_actions(time(), false, $postdata);
        if (method_exists($attemptobj, 'process_submit')) {
            // Moodle 5.0 and later finish an attempt in two steps.
            $attemptobj->process_submit(time(), false);
            $attemptobj->process_grade_submission(time());
        } else {
            $attemptobj->process_finish(time(), false);
        }
    }
    \core\session\manager::init_empty_session();
    \core\session\manager::set_user(get_admin());
}

// Private files of some users.
foreach (array_slice($users, 0, 10) as $user) {
    secbk_dev_file($fs, ['contextid' => context_user::instance($user->id)->id, 'component' => 'user',
        'filearea' => 'private', 'itemid' => 0], 'private.bin', mt_rand(1, 256) * 1024);
    $files++;
}

$counts = [
    'user' => $DB->count_records_select('user', $DB->sql_like('username', ':p'), ['p' => 'secbk\_%']),
    'course' => $DB->count_records_select('course', $DB->sql_like('shortname', ':p'), ['p' => 'secbk-%']),
    'files_created' => $files,
    'distinct_contenthash' => $DB->count_records_sql("SELECT COUNT(DISTINCT contenthash) FROM {files} WHERE filename <> '.'"),
    'forum_posts' => $DB->count_records('forum_posts'),
    'assign_submission' => $DB->count_records('assign_submission'),
    'quiz_attempts' => $DB->count_records('quiz_attempts'),
];
foreach ($counts as $name => $count) {
    cli_writeln(sprintf('%-22s %d', $name, $count));
}
