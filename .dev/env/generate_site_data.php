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
 * Usage: php <plugin>/.dev/env/generate_site_data.php --size=S|M [--seed=1] [--force] [--plugin-data] [--files-gb=<number>]
 *
 * --plugin-data adds, to every dummy course, an activity or a block of each extra plugin of the staging end-to-end test
 * (.dev/e2e/plugins.json) that the site has installed, with data in it. --files-gb adds files of random content to the dummy
 * courses until the folder filedir holds that many GB (10^9 bytes) in total; of the files it adds, 70 % have 64 KiB to 1 MiB,
 * 25 % 1 to 4 MiB and 5 % 4 to 64 MiB. Both work on a site that has dummy data already, without --force: they add to its
 * courses. .dev/e2e/check-data.php checks what they made.
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
require_once($CFG->dirroot . '/course/lib.php');
// The question generator needs PHPUnit's classes. Moodle 4.5 loads Composer's autoloader only for PHPUnit and Behat.
// Moodle 5.3 always loads it and keeps vendor/ above $CFG->dirroot (public/), so this does nothing there.
if (is_file($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

[$options, $unrecognised] = cli_get_params(['size' => 'S', 'seed' => 1, 'force' => false, 'plugin-data' => false,
    'files-gb' => null], []);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
$filesgb = $options['files-gb'];
if ($filesgb !== null && (!is_numeric($filesgb) || (float) $filesgb <= 0)) {
    cli_error('--files-gb must be a number above 0 (GB of 10^9 bytes)');
}
$sizes = [
    'S' => ['courses' => 3, 'users' => 30, 'files' => 60, 'large' => 2],
    'M' => ['courses' => 10, 'users' => 200, 'files' => 600, 'large' => 10],
];
if (!isset($sizes[$options['size']])) {
    cli_error('--size must be S or M');
}
$size = $sizes[$options['size']];
mt_srand((int) $options['seed']);

$exists = $DB->record_exists_select('user', $DB->sql_like('username', ':p'), ['p' => 'secbk\_%']);
$addonly = $exists && !$options['force'];
if ($addonly && !$options['plugin-data'] && $filesgb === null) {
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

/**
 * Writes a file of random bytes in parts of 1 MiB, so that a large file is never held in memory as a whole.
 *
 * The bytes are the AES-128-CTR key stream of a key made from $seed: fast, and different for every seed. The counter of each
 * part starts at a multiple of 65536 blocks (1 MiB), so no part repeats another.
 *
 * @param string $path The file to write (replaced).
 * @param int $length Number of bytes.
 * @param string $seed Text that the bytes are made from.
 */
function secbk_dev_random_file(string $path, int $length, string $seed): void {
    $key = substr(hash('sha256', $seed, true), 0, 16);
    $out = fopen($path, 'wb');
    if ($out === false) {
        throw new coding_exception("Cannot write $path");
    }
    $part = 1048576;
    $zeros = str_repeat("\0", $part);
    for ($done = 0, $counter = 0; $done < $length; $done += $part, $counter++) {
        $want = min($part, $length - $done);
        $bytes = openssl_encrypt($want === $part ? $zeros : substr($zeros, 0, $want), 'aes-128-ctr', $key,
            OPENSSL_RAW_DATA, pack('J2', 0, $counter * 65536));
        if ($bytes === false || fwrite($out, $bytes) !== $want) {
            fclose($out);
            throw new coding_exception("Cannot write $path");
        }
    }
    fclose($out);
}

/**
 * Stores a file of random bytes that no other file has, and returns it.
 *
 * The bytes go through a temporary file, so that a file of 64 MiB is never held in memory.
 *
 * @param file_storage $fs File storage.
 * @param array $where contextid, component, filearea, itemid.
 * @param string $name File name.
 * @param int $length File size in bytes.
 * @return stored_file
 */
function secbk_dev_random_stored_file(file_storage $fs, array $where, string $name, int $length): stored_file {
    global $CFG;
    static $path = null;
    if ($path === null) {
        $path = make_request_directory() . '/random.bin';
    }
    // The site identifier makes the files of two sites differ, and the place and the name of a file make the files of one
    // site differ.
    secbk_dev_random_file($path, $length, implode('|', [$CFG->siteidentifier, mt_rand(), $where['contextid'],
        $where['component'], $where['filearea'], $where['itemid'], $name]));
    return $fs->create_file_from_pathname($where + ['filepath' => '/', 'filename' => $name], $path);
}

/**
 * Returns the bytes of all files in the folder filedir, counted as .dev/e2e/check-data.php counts them.
 *
 * @return int
 */
function secbk_dev_filedir_bytes(): int {
    global $CFG;
    $dir = $CFG->filedir ?? $CFG->dataroot . '/filedir';
    $bytes = 0;
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }
    }
    return $bytes;
}

/**
 * Returns the dummy courses.
 *
 * @return stdClass[]
 */
function secbk_dev_courses(): array {
    global $DB;
    return $DB->get_records_select('course', $DB->sql_like('shortname', ':p'), ['p' => 'secbk-%'], 'id');
}

/**
 * Returns the users who have a role in a course.
 *
 * @param int $courseid Course id.
 * @param string $role Short name of the role.
 * @return stdClass[] Users (id and username), by id.
 */
function secbk_dev_course_users(int $courseid, string $role): array {
    global $DB;
    return $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.username
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
           JOIN {context} ctx ON ctx.id = ra.contextid
          WHERE ctx.contextlevel = :level AND ctx.instanceid = :courseid AND r.shortname = :role AND u.deleted = 0
       ORDER BY u.id",
        ['level' => CONTEXT_COURSE, 'courseid' => $courseid, 'role' => $role]
    );
}

/**
 * mod_attendance: an attendance activity with three sessions that were taken, with a status for every student.
 *
 * The activity comes from the plugin's generator, which also adds the default statuses. The sessions and the log are written
 * as the tables of the plugin (db/install.xml) hold them.
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_mod_attendance(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    if ($DB->record_exists('attendance', ['course' => $course->id])) {
        return;
    }
    $attendance = $generator->create_module('attendance', ['course' => $course->id, 'name' => 'Attendance']);
    $statuses = array_keys($DB->get_records('attendance_statuses', ['attendanceid' => $attendance->id, 'deleted' => 0],
        'grade DESC, id', 'id'));
    if (!$statuses) {
        cli_error('mod_attendance made no statuses for its activity.');
    }
    $now = time();
    for ($s = 1; $s <= 3; $s++) {
        $date = $now - (4 - $s) * WEEKSECS;
        $sessionid = $DB->insert_record('attendance_sessions', ['attendanceid' => $attendance->id, 'groupid' => 0,
            'sessdate' => $date, 'duration' => HOURSECS, 'lasttaken' => $date + HOURSECS, 'lasttakenby' => $teacher->id,
            'timemodified' => $date + HOURSECS, 'description' => "Lecture $s", 'descriptionformat' => FORMAT_HTML,
            'statusset' => 0, 'calendarevent' => 0]);
        foreach ($students as $student) {
            // Most students have the status with the highest grade (present), the others any status.
            $status = mt_rand(1, 10) <= 8 ? $statuses[0] : $statuses[mt_rand(0, count($statuses) - 1)];
            $DB->insert_record('attendance_log', ['sessionid' => $sessionid, 'studentid' => $student->id,
                'statusid' => $status, 'statusset' => implode(',', $statuses), 'timetaken' => $date + HOURSECS,
                'takenby' => $teacher->id, 'remarks' => '']);
        }
    }
}

/**
 * block_autoattend: the block, and three closed sessions taken by hand (method M), with a status for every student.
 *
 * The plugin has no data generator: the block comes from Moodle's generator for blocks, and the sessions are written as the
 * tables of the plugin (db/install.xml) hold them.
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_block_autoattend(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    $context = context_course::instance($course->id);
    if ($DB->record_exists('block_instances', ['blockname' => 'autoattend', 'parentcontextid' => $context->id])) {
        return;
    }
    $generator->create_block('autoattend', ['parentcontextid' => $context->id, 'pagetypepattern' => 'course-view-*']);
    $today = usergetmidnight(time());
    for ($s = 1; $s <= 3; $s++) {
        $date = $today - (4 - $s) * WEEKSECS;
        $start = $date + 9 * HOURSECS;
        $end = $start + 90 * MINSECS;
        $sessionid = $DB->insert_record('autoattend_sessions', ['courseid' => $course->id, 'classid' => 0,
            'creator' => $teacher->id, 'sessdate' => $date, 'starttime' => $start, 'endtime' => $end, 'summertime' => 0,
            'latetime' => 10 * MINSECS, 'takenby' => $teacher->id, 'timetaken' => $end, 'method' => 'M', 'attendkey' => '',
            'denysameip' => 0, 'allowip' => '', 'description' => "Lecture $s", 'state' => 'C', 'timemodified' => $end]);
        foreach ($students as $student) {
            // P is present, L late and X absent: statuses that the plugin installs.
            $status = ['P', 'P', 'P', 'P', 'L', 'X'][mt_rand(0, 5)];
            $DB->insert_record('autoattend_students', ['attsid' => $sessionid, 'studentid' => $student->id,
                'status' => $status, 'called' => 'M', 'calledby' => $teacher->id, 'calledtime' => $start, 'sentemail' => 0,
                'remarks' => '', 'ipaddress' => '']);
        }
    }
}

/**
 * mod_autoattendmod: the activity. The plugin allows one in a course, and only in a course that has the block of
 * block_autoattend (it stops the script otherwise).
 *
 * The plugin has no data generator, so the activity is added through the course API (create_module()).
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_mod_autoattendmod(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    if ($DB->record_exists('autoattendmod', ['course' => $course->id])) {
        return;
    }
    create_module((object) ['modulename' => 'autoattendmod', 'course' => $course->id, 'section' => 0, 'visible' => 1,
        'name' => 'Auto attendance',
        'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()]]);
}

/**
 * block_sharing_cart: the block, and an item in the cart of the teacher with its backup file.
 *
 * The generator of the plugin makes items of the cart, not blocks, so the block comes from Moodle's generator for blocks. A
 * backed-up item keeps its backup file in the area of the plugin in the teacher's context, where the backup task of the
 * plugin puts it; this file holds random bytes, not a real backup.
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_block_sharing_cart(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    $context = context_course::instance($course->id);
    if ($DB->record_exists('block_instances', ['blockname' => 'sharing_cart', 'parentcontextid' => $context->id])) {
        return;
    }
    (new default_block_generator($generator, 'sharing_cart'))->create_instance(['parentcontextid' => $context->id,
        'pagetypepattern' => 'course-view-*']);
    $name = 'Resource of ' . $course->shortname;
    $generator->get_plugin_generator('block_sharing_cart')->create_sharing_cart_item(['user_id' => $teacher->id,
        'type' => 'mod_resource', 'name' => $name]);
    $item = $DB->get_record('block_sharing_cart_items', ['user_id' => $teacher->id, 'name' => $name], 'id', MUST_EXIST);
    $file = secbk_dev_random_stored_file(get_file_storage(), ['contextid' => context_user::instance($teacher->id)->id,
        'component' => 'block_sharing_cart', 'filearea' => 'backup', 'itemid' => $item->id],
        'backup-' . $course->shortname . '.mbz', mt_rand(64, 512) * 1024);
    $DB->update_record('block_sharing_cart_items', ['id' => $item->id, 'file_id' => $file->get_id(),
        'original_course_fullname' => $course->fullname]);
}

/**
 * mod_videoassessment: the activity, from the plugin's generator, and a video of each of two students as a converted upload
 * leaves it: a row of videoassessment_videos, its file in the area "video", and the link of the video to the student.
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_mod_videoassessment(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    if ($DB->record_exists('videoassessment', ['course' => $course->id])) {
        return;
    }
    $activity = $generator->create_module('videoassessment', ['course' => $course->id, 'name' => 'Video assessment']);
    $contextid = context_module::instance($activity->cmid)->id;
    foreach (array_slice($students, 0, 2) as $n => $student) {
        $name = "video-{$student->id}.mp4";
        $time = time();
        secbk_dev_random_stored_file(get_file_storage(), ['contextid' => $contextid, 'component' => 'mod_videoassessment',
            'filearea' => 'video', 'itemid' => 0], $name, mt_rand(1024, 4096) * 1024);
        $videoid = $DB->insert_record('videoassessment_videos', ['videoassessment' => $activity->id, 'filepath' => '/',
            'filename' => $name, 'thumbnailname' => '', 'tmpname' => '', 'originalname' => "recording-$n.mp4",
            'timecreated' => $time, 'timemodified' => $time]);
        $DB->insert_record('videoassessment_video_assocs', ['videoassessment' => $activity->id, 'videoid' => $videoid,
            'associationtype' => '1', 'timing' => 'before', 'associationid' => $student->id, 'timemodified' => $time]);
    }
}

/**
 * mod_questionnaire: a questionnaire with a yes/no question and the answers of up to five students, all through the plugin's
 * generator, with the same calls as the plugin's own tests.
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_mod_questionnaire(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    if ($DB->record_exists('questionnaire', ['course' => $course->id])) {
        return;
    }
    $questionnairegenerator = $generator->get_plugin_generator('mod_questionnaire');
    // QUESYESNO is a constant of the plugin, which its generator loads.
    $questionnaire = $questionnairegenerator->create_test_questionnaire($course, QUESYESNO,
        ['content' => 'Was the lecture clear?']);
    $question = reset($questionnaire->questions);
    foreach (array_slice($students, 0, 5) as $n => $student) {
        $questionnairegenerator->create_question_response($questionnaire, $question, $n % 3 ? 'y' : 'n', $student->id);
    }
}

/**
 * block_xp: the block, and points for every student.
 *
 * The plugin has no data generator, so the block comes from Moodle's generator for blocks. Adding the block on a course page
 * turns the points of the course on, which that generator does not do, so it is done here through the plugin's API, which
 * also gives the points (the tables block_xp_config and block_xp).
 *
 * @param testing_data_generator $generator Data generator.
 * @param stdClass $course Course.
 * @param stdClass $teacher Teacher of the course.
 * @param stdClass[] $students Students of the course.
 */
function secbk_dev_block_xp(
    testing_data_generator $generator,
    stdClass $course,
    stdClass $teacher,
    array $students
): void {
    global $DB;
    $context = context_course::instance($course->id);
    if ($DB->record_exists('block_instances', ['blockname' => 'xp', 'parentcontextid' => $context->id])) {
        return;
    }
    $generator->create_block('xp', ['parentcontextid' => $context->id, 'pagetypepattern' => 'course-view-*']);
    $world = \block_xp\di::get('course_world_factory')->get_world($course->id);
    $world->get_config()->set('enabled', true);
    $store = $world->get_store();
    foreach ($students as $student) {
        $store->increase($student->id, mt_rand(1, 50) * 10);
    }
}

/**
 * --plugin-data: adds to every dummy course an activity or a block of each extra plugin that the site has installed, with
 * data in it. A course that has the activity or block of a plugin already is left as it is for that plugin.
 *
 * @param testing_data_generator $generator Data generator.
 */
function secbk_dev_plugin_data(testing_data_generator $generator): void {
    // In this order: the activity of mod_autoattendmod needs the block of block_autoattend in its course.
    $components = ['mod_attendance', 'block_autoattend', 'mod_autoattendmod', 'block_sharing_cart', 'mod_videoassessment',
        'mod_questionnaire', 'block_xp'];
    $installed = [];
    foreach ($components as $component) {
        $info = core_plugin_manager::instance()->get_plugin_info($component);
        if ($info && $info->get_status() === core_plugin_manager::PLUGIN_STATUS_UPTODATE) {
            $installed[] = $component;
        }
    }
    cli_writeln('plugin data: ' . ($installed ? implode(', ', $installed) : 'none, no extra plugin is installed'));
    if (in_array('mod_autoattendmod', $installed) && !in_array('block_autoattend', $installed)) {
        cli_error('mod_autoattendmod is installed without block_autoattend: its activity needs the block in the course.');
    }
    foreach (secbk_dev_courses() as $course) {
        $teachers = secbk_dev_course_users($course->id, 'editingteacher');
        $teacher = $teachers ? reset($teachers) : get_admin();
        $students = array_values(secbk_dev_course_users($course->id, 'student'));
        foreach ($installed as $component) {
            $function = 'secbk_dev_' . $component;
            $function($generator, $course, $teacher, $students);
        }
    }
}

/**
 * --files-gb: adds files of random content to the dummy courses, in a folder "Bulk files" in each, until the folder filedir
 * holds $gb GB (10^9 bytes) in total.
 *
 * Of every 20 files it adds, 14 have 64 KiB to 1 MiB, 5 have 1 to 4 MiB and 1 has more than 4 MiB, up to 64 MiB. That one
 * comes first, so that a small total has a file too that the backup cuts into parts. The last file is cut to what is still
 * missing, so the total ends at the target. A site that holds more than 5 % above the target already stops the script.
 *
 * @param testing_data_generator $generator Data generator.
 * @param float $gb The total, in GB.
 * @param int $run Time of this run, which the names of the files contain.
 */
function secbk_dev_fill(testing_data_generator $generator, float $gb, int $run): void {
    global $CFG;
    $target = (int) round($gb * 1e9);
    $have = secbk_dev_filedir_bytes();
    if ($have > $target * 1.05) {
        cli_error("The files of the site have $have bytes already, more than 5 % above --files-gb ($target bytes).");
    }
    if ($have >= $target) {
        cli_writeln("bulk files: none added, filedir has $have bytes already (wanted $target)");
        return;
    }
    // Each file is written to a temporary file of up to 64 MiB first, then copied into filedir.
    $needed = $target - $have + 256 * 1048576;
    $free = disk_free_space($CFG->dataroot);
    if ($free !== false && $free < $needed) {
        cli_error(sprintf('Not enough free disk space: %d bytes free, %d bytes needed.', $free, $needed));
    }
    $folders = [];
    foreach (secbk_dev_courses() as $course) {
        $folder = $generator->create_module('folder', ['course' => $course->id, 'name' => 'Bulk files']);
        $folders[] = context_module::instance($folder->cmid)->id;
    }
    if (!$folders) {
        cli_error('There is no dummy course to add the files to.');
    }
    $fs = get_file_storage();
    $mib = 1048576;
    for ($n = 0; $have < $target; $n++) {
        if ($n % 20 === 0) {
            $length = mt_rand(4 * $mib + 1, 64 * $mib);
        } else if ($n % 4 === 1) {
            // Files 1, 5, 9, 13 and 17 of every 20.
            $length = mt_rand($mib, 4 * $mib - 1);
        } else {
            $length = mt_rand(64 * 1024, $mib - 1);
        }
        $length = min($length, $target - $have);
        secbk_dev_random_stored_file($fs, ['contextid' => $folders[$n % count($folders)], 'component' => 'mod_folder',
            'filearea' => 'content', 'itemid' => 0], sprintf('bulk-%d-%05d.bin', $run, $n), $length);
        $have += $length;
        if (($n + 1) % 250 === 0) {
            cli_writeln(sprintf('bulk files: %d added, filedir %d of %d bytes', $n + 1, $have, $target));
        }
    }
    cli_writeln(sprintf('bulk files: %d added, filedir %d bytes (wanted %d)', $n, secbk_dev_filedir_bytes(), $target));
}

$files = 0;
if ($addonly) {
    cli_writeln('Dummy data already exists: adding to its courses.');
} else {
    $users = [];
    for ($i = 1; $i <= $size['users']; $i++) {
        $users[] = $generator->create_user([
            'username' => 'secbk_' . $run . '_' . $i,
            'firstname' => 'Test',
            'lastname' => 'User' . $i,
            'email' => 'secbk' . $run . '_' . $i . '@example.com',
        ]);
    }

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
}

if ($options['plugin-data']) {
    secbk_dev_plugin_data($generator);
}
if ($filesgb !== null) {
    secbk_dev_fill($generator, (float) $filesgb, $run);
}

$counts = [
    'user' => $DB->count_records_select('user', $DB->sql_like('username', ':p'), ['p' => 'secbk\_%']),
    'course' => $DB->count_records_select('course', $DB->sql_like('shortname', ':p'), ['p' => 'secbk-%']),
    'files_created' => $files,
    'distinct_contenthash' => $DB->count_records_sql("SELECT COUNT(DISTINCT contenthash) FROM {files} WHERE filename <> '.'"),
    'forum_posts' => $DB->count_records('forum_posts'),
    'assign_submission' => $DB->count_records('assign_submission'),
    'quiz_attempts' => $DB->count_records('quiz_attempts'),
    'filedir_bytes' => secbk_dev_filedir_bytes(),
];
foreach ($counts as $name => $count) {
    cli_writeln(sprintf('%-22s %d', $name, $count));
}
