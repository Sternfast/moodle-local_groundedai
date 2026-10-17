<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Seed a course with several students and topic-named graded items (varied scores) so the
 * whole-class grade analytics have something meaningful to report. Idempotent.
 *
 * Usage: php seed_analytics.php [courseid]
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

global $DB;

$courseid = isset($argv[1]) ? (int) $argv[1] : 4;
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Topic-named graded items (max 100). Geometry is deliberately the hardest class-wide.
$items = ['Algebra Quiz', 'Geometry Quiz', 'History Essay', 'Chemistry Exam'];

// Students and their scores per item, designed to give each a clear strength/weakness.
$students = [
    'ada'       => ['first' => 'Ada', 'last' => 'Lovelace', 'scores' => [92, 48, 85, 78]],
    'grace'     => ['first' => 'Grace', 'last' => 'Hopper', 'scores' => [88, 55, 90, 70]],
    'alan'      => ['first' => 'Alan', 'last' => 'Turing', 'scores' => [72, 40, 60, 95]],
    'katherine' => ['first' => 'Katherine', 'last' => 'Johnson', 'scores' => [95, 62, 80, 88]],
    'linus'     => ['first' => 'Linus', 'last' => 'Torvalds', 'scores' => [45, 35, 50, 58]],
];

// Enrolment plumbing.
$manual = enrol_get_plugin('manual');
$enrolinstance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

// Create / enrol students.
$userids = [];
foreach ($students as $key => $s) {
    $username = 'ga_' . $key;
    $user = $DB->get_record('user', ['username' => $username]);
    if (!$user) {
        $new = (object) [
            'username'  => $username,
            'auth'      => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'firstname' => $s['first'],
            'lastname'  => $s['last'],
            'email'     => $username . '@example.com',
        ];
        $uid = user_create_user($new, false, false);
        $user = $DB->get_record('user', ['id' => $uid]);
        echo "created user {$username} (id {$uid})\n";
    }
    $userids[$key] = (int) $user->id;
    if (!is_enrolled($context, $user->id)) {
        $manual->enrol_user($enrolinstance, $user->id, $studentrole->id);
        echo "enrolled {$username}\n";
    }
}

// Create / fetch the manual grade items.
$gradeitems = [];
foreach ($items as $name) {
    $existing = grade_item::fetch(['courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => $name]);
    if ($existing) {
        $gi = $existing;
    } else {
        $gi = new grade_item(['courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => $name], false);
        $gi->grademax = 100;
        $gi->grademin = 0;
        $gi->insert();
        echo "created grade item '{$name}' (id {$gi->id})\n";
    }
    $gradeitems[] = $gi;
}

// Set each student's grades.
foreach ($students as $key => $s) {
    $uid = $userids[$key];
    foreach ($s['scores'] as $i => $score) {
        $gradeitems[$i]->update_final_grade($uid, (float) $score, 'cli_seed');
    }
    echo "graded {$key}: " . implode(', ', $s['scores']) . "\n";
}

grade_regrade_final_grades($courseid);
echo "regraded course {$courseid}\n";
echo "done\n";
