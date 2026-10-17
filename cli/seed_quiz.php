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
 * Seed a Quiz with a few true/false questions so get_quiz_questions can be demoed.
 *
 * Usage: php seed_quiz.php [courseid]
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->libdir . '/questionlib.php');

global $DB, $CFG;

$courseid = isset($argv[1]) ? (int) $argv[1] : 4;
$course = get_course($courseid);
$admin = get_admin();
\core\session\manager::set_user($admin);

if ($DB->record_exists('quiz', ['course' => $courseid, 'name' => 'Geometry Quiz (exam)'])) {
    echo "quiz already exists\n";
    exit(0);
}

// 1. Create the quiz activity.
$moduleinfo = (object) [
    'modulename'                 => 'quiz',
    'module'                     => $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST),
    'course'                     => $course->id,
    'section'                    => 1,
    'visible'                    => 1,
    'name'                       => 'Geometry Quiz (exam)',
    'intro'                      => 'End-of-unit geometry quiz.',
    'introformat'                => FORMAT_HTML,
    'preferredbehaviour'         => 'deferredfeedback',
    'grade'                      => 100,
    'grademethod'                => 1,
    'questionsperpage'           => 1,
    'shuffleanswers'             => 1,
    'attempts'                   => 0,
    'timeopen'                   => 0,
    'timeclose'                  => 0,
    'timelimit'                  => 0,
    'overduehandling'            => 'autosubmit',
    'graceperiod'                => 0,
    'decimalpoints'              => 2,
    'questiondecimalpoints'      => -1,
    'navmethod'                  => 'free',
    'quizpassword'               => '',
    'subnet'                     => '',
    'browsersecurity'            => '-',
    'delay1'                     => 0,
    'delay2'                     => 0,
    'showuserpicture'            => 0,
    'cmidnumber'                 => '',
    'groupmode'                  => 0,
    'groupingid'                 => 0,
    'visibleoncoursepage'        => 1,
    'completion'                 => 0,
    'completionview'             => 0,
    'completionexpected'         => 0,
    'availabilityconditionsjson' => null,
];
$moduleinfo = add_moduleinfo($moduleinfo, $course);
$quiz = $DB->get_record('quiz', ['id' => $moduleinfo->instance], '*', MUST_EXIST);
echo "created quiz cmid {$moduleinfo->coursemodule} (instance {$quiz->id})\n";

// 2. Default course question category.
$coursecontext = context_course::instance($courseid);
question_make_default_categories([$coursecontext]);
$category = question_get_default_category($coursecontext->id);

// 3. Create a few true/false questions.
$qtype = question_bank::get_qtype('truefalse');
$items = [
    ['The interior angles of a triangle sum to 180 degrees.', 1],
    ['In a right triangle, a squared plus b squared equals c squared (Pythagorean theorem).', 1],
    ['All triangles with equal perimeters are congruent.', 0],
];
$count = 0;
foreach ($items as [$text, $correct]) {
    $form = new stdClass();
    $form->category = $category->id . ',' . $coursecontext->id;
    $form->name = 'TF ' . (++$count);
    $form->questiontext = ['text' => $text, 'format' => FORMAT_HTML];
    $form->questiontextformat = FORMAT_HTML;
    $form->defaultmark = 1;
    $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
    $form->penalty = 1;
    $form->correctanswer = $correct;
    $form->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
    $form->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
    $form->qtype = 'truefalse';

    $question = new stdClass();
    $question->category = $category->id;
    $question->qtype = 'truefalse';
    $question->createdby = $admin->id;
    $question->modifiedby = $admin->id;
    $newq = $qtype->save_question($question, $form);

    quiz_add_quiz_question($newq->id, $quiz, 0, 1);
    echo "added question {$newq->id}: {$form->name}\n";
}

quiz_update_sumgrades($quiz);
rebuild_course_cache($courseid, true);
echo "done\n";
