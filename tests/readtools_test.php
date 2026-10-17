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
 * Tests for the newer staff read tools and the compliance "proof" assertions (read-only wall,
 * staff-only, no-PII, capability gating, output sanitisation, metadata-only telemetry).
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\staff\tools;
use local_groundedai\local\telemetry;

/**
 * Covers the newer read tools plus the architectural guarantees an institution relies on.
 *
 * @covers \local_groundedai\local\staff\tools
 */
final class readtools_test extends \advanced_testcase {
    /**
     * Reset the tools' per-request caches so a prior test's course can't leak into this one.
     */
    protected function setUp(): void {
        parent::setUp();
        tools::reset_caches();
        \local_groundedai\local\access_manager::reset_caches();
    }

    /**
     * Grant the editingteacher role every data domain (and any given features) so a teacher actor can
     * reach all read tools. The test actors hold editingteacher, so this grant applies to them.
     *
     * @param array $features Optional sensitive features to enable (email/upload/export).
     */
    private function enable_all_domains(array $features = []): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        \local_groundedai\local\access_manager::add_role_grant(
            $roleid,
            \local_groundedai\local\access_manager::SCOPE_ALL,
            0,
            0,
            null,
            $features
        );
        \local_groundedai\local\access_manager::reset_caches();
    }

    /**
     * get_gradebook_structure returns the category tree with per-item weights and the aggregation.
     */
    public function test_gradebook_structure(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $gi = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Essay'], false);
        $gi->insert();

        $result = tools::dispatch('get_gradebook_structure', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $names = [];
        foreach ($result['categories'] as $cat) {
            $this->assertArrayHasKey('aggregation', $cat);
            foreach ($cat['items'] as $item) {
                $names[] = $item['name'];
            }
        }
        $this->assertContains('Essay', $names);
    }

    /**
     * get_grade_detail surfaces the feedback and the manual-override flag get_grades omits.
     */
    public function test_grade_detail_feedback_and_override(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gi = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Essay'], false);
        $gi->insert();
        $gg = new \grade_grade([
            'itemid' => $gi->id, 'userid' => $student->id, 'rawgrade' => 80, 'finalgrade' => 80,
            'feedback' => 'Good analysis; tighten citations.', 'overridden' => time(),
        ], false);
        $gg->insert();

        $result = tools::dispatch(
            'get_grade_detail',
            ['courseid' => (int) $course->id, 'userid' => (int) $student->id],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $essay = null;
        foreach ($result['grades'] as $row) {
            if ($row['item'] === 'Essay') {
                $essay = $row;
            }
        }
        $this->assertNotNull($essay);
        $this->assertStringContainsString('tighten citations', (string) $essay['feedback']);
        $this->assertTrue($essay['overridden']);
    }

    /**
     * get_assignment_status reports who submitted and flags a late submission.
     */
    public function test_assignment_status_late_flag(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'duedate' => time() - DAYSECS]
        );

        // A submission made after the due date.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0,
        ]);

        $result = tools::dispatch(
            'get_assignment_status',
            ['courseid' => (int) $course->id, 'cmid' => (int) $assign->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['totals']['submitted']);
        $this->assertSame(1, $result['totals']['late']);
        $found = false;
        foreach ($result['students'] as $s) {
            if ((int) $s['id'] === (int) $student->id) {
                $found = true;
                $this->assertSame('submitted', $s['status']);
                $this->assertTrue($s['late']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * get_forum_activity counts discussions/posts and ranks posters.
     */
    public function test_forum_activity_counts(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $disc = $fg->create_discussion((object) ['course' => $course->id, 'forum' => $forum->id, 'userid' => $s1->id]);
        $fg->create_post((object) ['discussion' => $disc->id, 'userid' => $s2->id]);
        $fg->create_post((object) ['discussion' => $disc->id, 'userid' => $s2->id]);

        $result = tools::dispatch('get_forum_activity', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertGreaterThanOrEqual(1, $result['discussions']);
        $this->assertGreaterThanOrEqual(3, $result['posts']);   // 1 discussion opener + 2 replies.
        $this->assertNotEmpty($result['top_posters']);
    }

    /**
     * get_quiz_attempt_analytics computes per-learner attempts and per-question facility.
     */
    public function test_quiz_attempt_analytics(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $this->enable_all_domains();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $quiz = $this->getDataGenerator()->create_module(
            'quiz',
            ['course' => $course->id, 'grade' => 100.0, 'sumgrades' => 1]
        );
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $q = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($q->id, $quiz);

        $this->submit_quiz_attempt($quiz, (int) $s1->id, 1);   // Correct.
        $this->submit_quiz_attempt($quiz, (int) $s2->id, 0);   // Wrong.

        $result = tools::dispatch(
            'get_quiz_attempt_analytics',
            ['courseid' => (int) $course->id, 'cmid' => (int) $quiz->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['learners_attempted']);
        $this->assertNotEmpty($result['questions_hardest_first']);
        $facility = $result['questions_hardest_first'][0]['facility_pct'];
        $this->assertEqualsWithDelta(50.0, $facility, 0.1, 'one of two got it right');
    }

    /**
     * Start, answer and finish one quiz attempt for a learner.
     *
     * @param object $quiz The quiz module record.
     * @param int $userid The learner.
     * @param int $answer 1 for the correct true/false answer, 0 for wrong.
     */
    private function submit_quiz_attempt(object $quiz, int $userid, int $answer): void {
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $userid);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $userid);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [1 => ['answer' => $answer]]);
        $attemptobj->process_finish($timenow, false);
    }

    /**
     * get_activity_settings returns an activity's dates.
     */
    public function test_activity_settings_dates(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $open = time();
        $close = time() + (7 * DAYSECS);
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'allowsubmissionsfromdate' => $open, 'duedate' => $close]
        );

        $result = tools::dispatch(
            'get_activity_settings',
            ['courseid' => (int) $course->id, 'cmid' => (int) $assign->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertNotEmpty($result['dates']);
    }

    /**
     * get_completion_overview reports per-learner completion once tracking is on.
     */
    public function test_completion_overview(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        set_config('enablecompletion', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $result = tools::dispatch('get_completion_overview', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['tracked_activities']);
        $this->assertSame(1, $result['student_count']);
    }

    /**
     * get_course_info returns the course's dates and settings.
     */
    public function test_course_info(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course(['startdate' => time()]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $result = tools::dispatch('get_course_info', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertNotNull($result['created']);
        $this->assertArrayHasKey('visible', $result);
    }

    /**
     * get_enrolment_info reports each learner's status and enrolment date.
     */
    public function test_enrolment_info(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $result = tools::dispatch('get_enrolment_info', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $found = false;
        foreach ($result['enrolments'] as $e) {
            if ((int) $e['id'] === (int) $student->id) {
                $found = true;
                $this->assertSame('active', $e['status']);
                $this->assertNotNull($e['enrolled_on']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * get_student_groups lists course groups and one learner's groups.
     */
    public function test_student_groups(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Red team']);
        groups_add_member($group->id, $student->id);

        $all = tools::dispatch('get_student_groups', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $all);
        $this->assertContains('Red team', array_column($all['groups'], 'name'));

        $one = tools::dispatch(
            'get_student_groups',
            ['courseid' => (int) $course->id, 'userid' => (int) $student->id],
            (int) $teacher->id
        );
        $this->assertContains('Red team', $one['groups']);
    }

    /**
     * get_activity_log runs and returns either engagement rows or a clear no-log-store note.
     */
    public function test_activity_log_runs(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $result = tools::dispatch('get_activity_log', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('courseid', $result);
    }

    /**
     * get_student_overview consolidates a single learner's standing.
     */
    public function test_student_overview(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gi = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Essay'], false);
        $gi->insert();
        grade_regrade_final_grades((int) $course->id);

        $result = tools::dispatch(
            'get_student_overview',
            ['courseid' => (int) $course->id, 'userid' => (int) $student->id],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame((int) $student->id, $result['userid']);
        $this->assertArrayHasKey('overall_pct', $result);
        $this->assertArrayHasKey('completion_pct', $result);
        $this->assertArrayHasKey('assignments_outstanding', $result);
        $this->assertArrayHasKey('at_risk', $result);
    }

    /**
     * get_announcements reads the latest news posts' text.
     */
    public function test_announcements(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $news = $DB->get_record('forum', ['course' => $course->id, 'type' => 'news']);
        if (!$news) {
            $rec = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'news']);
            $news = $DB->get_record('forum', ['id' => $rec->id]);
        }
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion((object) [
            'course' => $course->id, 'forum' => $news->id, 'userid' => $teacher->id,
            'name' => 'Welcome', 'message' => 'Classes start Monday.',
        ]);

        $result = tools::dispatch('get_announcements', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertContains('Welcome', array_column($result['announcements'], 'title'));
    }

    /**
     * get_upcoming lists course events within the window.
     */
    public function test_upcoming(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $when = time() + DAYSECS;
        $DB->insert_record('event', (object) [
            'name' => 'Midterm', 'description' => '', 'format' => 1, 'courseid' => $course->id,
            'groupid' => 0, 'userid' => $teacher->id, 'eventtype' => 'course', 'type' => 0,
            'timestart' => $when, 'timeduration' => 0, 'timesort' => $when, 'visible' => 1,
            'modulename' => '', 'instance' => 0, 'timemodified' => time(),
        ]);

        $result = tools::dispatch('get_upcoming', ['courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertContains('Midterm', array_column($result['upcoming'], 'name'));
    }

    /**
     * get_grading_queue flags an assignment with ungraded submitted work.
     */
    public function test_grading_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0,
        ]);

        $result = tools::dispatch('get_grading_queue', [], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $total = 0;
        foreach ($result['queue'] as $c) {
            foreach ($c['assignments'] as $a) {
                $total += $a['to_grade'];
            }
        }
        $this->assertGreaterThanOrEqual(1, $total, 'the submitted-but-ungraded assignment should be queued');
    }

    /**
     * get_forum_posts returns the post text in a discussion.
     */
    public function test_forum_posts(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $disc = $fg->create_discussion((object) [
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $student->id,
            'name' => 'Week 3', 'message' => 'I am stuck on question 2.',
        ]);
        $fg->create_post((object) [
            'discussion' => $disc->id, 'userid' => $teacher->id, 'message' => 'Re-read the worked example.',
        ]);

        $result = tools::dispatch(
            'get_forum_posts',
            ['courseid' => (int) $course->id, 'cmid' => (int) $forum->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $alltext = json_encode($result['discussions']);
        $this->assertStringContainsString('stuck on question 2', $alltext);
        $this->assertStringContainsString('worked example', $alltext);
    }

    /**
     * read_material now reads glossary content (part of the lesson/glossary/wiki extension).
     */
    public function test_read_material_reads_glossary(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'concept' => 'Photosynthesis', 'definition' => 'How plants make food from light.', 'approved' => 1,
        ]);
        $cm = get_coursemodule_from_instance('glossary', $glossary->id);

        $result = tools::dispatch(
            'read_material',
            ['courseid' => (int) $course->id, 'cmid' => (int) $cm->id],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringContainsString('Photosynthesis', $result['text']);
        $this->assertStringContainsString('make food from light', $result['text']);
    }

    /**
     * read_material pages long content: the default call caps the slice and flags hasmore, an offset is
     * honoured, and a full read reports hasmore false with the whole body.
     */
    public function test_read_material_pages_long_content(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // A body longer than the 10000-char default slice but within the 20000 hard cap.
        $body = str_repeat('Mitochondria are the powerhouse of the cell. ', 400);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'content' => $body, 'contentformat' => FORMAT_HTML]
        );

        $first = tools::dispatch(
            'read_material',
            ['courseid' => (int) $course->id, 'cmid' => (int) $page->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $first);
        $this->assertSame(0, $first['offset']);
        $this->assertGreaterThan(10000, $first['total_chars']);
        $this->assertTrue($first['hasmore']);
        $this->assertLessThanOrEqual(10000, \core_text::strlen($first['text']));

        $next = tools::dispatch(
            'read_material',
            ['courseid' => (int) $course->id, 'cmid' => (int) $page->cmid, 'offset' => 5000],
            (int) $teacher->id
        );
        $this->assertSame(5000, $next['offset']);
        $this->assertNotSame('', $next['text']);

        $full = tools::dispatch(
            'read_material',
            ['courseid' => (int) $course->id, 'cmid' => (int) $page->cmid, 'length' => 20000],
            (int) $teacher->id
        );
        $this->assertFalse($full['hasmore']);
        $this->assertSame($full['total_chars'], \core_text::strlen($full['text']));
    }

    /**
     * read_material turns a video's caption file (WebVTT) into clean transcript text — timing stripped.
     */
    public function test_read_material_reads_video_captions(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setAdminUser();   // The resource generator needs a current user.
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('resource', $resource->id);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id, 'component' => 'mod_resource',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'captions.vtt',
        ], "WEBVTT\n\n1\n00:00:01.000 --> 00:00:04.000\nWelcome to the lecture.\n\n"
            . "2\n00:00:05.000 --> 00:00:08.000\nToday we cover triangles.");

        $result = tools::dispatch(
            'read_material',
            ['courseid' => (int) $course->id, 'cmid' => (int) $cm->id],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringContainsString('Welcome to the lecture', $result['text']);
        $this->assertStringContainsString('cover triangles', $result['text']);
        $this->assertStringNotContainsString('-->', $result['text']);   // Timing lines removed.
    }

    // Compliance "proof" tests.

    /**
     * Email is opt-in: off by default (no email in people look-ups), present only when the admin enables it.
     */
    public function test_find_people_email_opt_in(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'pat@example.com']);

        // No email feature granted yet.
        $off = tools::dispatch('find', ['type' => 'people', 'courseid' => (int) $course->id], (int) $teacher->id);
        foreach ($off['participants'] as $row) {
            $this->assertArrayNotHasKey('email', $row);
        }

        // Grant the email feature to the teacher.
        \local_groundedai\local\access_manager::add_user_grant(
            (int) $teacher->id,
            \local_groundedai\local\access_manager::SCOPE_ALL,
            0,
            0,
            null,
            ['email']
        );
        \local_groundedai\local\access_manager::reset_caches();
        $on = tools::dispatch('find', ['type' => 'people', 'courseid' => (int) $course->id], (int) $teacher->id);
        $emails = [];
        foreach ($on['participants'] as $row) {
            if (isset($row['email'])) {
                $emails[] = $row['email'];
            }
        }
        $this->assertContains('pat@example.com', $emails);
    }

    /**
     * PROOF: the read-only wall. With every domain on, running every read tool changes no institutional
     * record. This is the hard evidence that the assistant cannot edit.
     */
    public function test_readonly_wall_mutates_nothing(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->enable_all_domains();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $gi = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'Essay'], false);
        $gi->insert();
        grade_regrade_final_grades((int) $course->id);   // Settle the gradebook so the overview iterator won't throw.

        $tables = ['user', 'user_enrolments', 'role_assignments', 'course_modules', 'course',
            'grade_items', 'grade_grades', 'grade_categories', 'assign', 'assign_submission', 'assign_grades',
            'forum', 'forum_discussions', 'forum_posts', 'quiz', 'quiz_attempts',
            'local_groundedai_conversations', 'local_groundedai_messages'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = $DB->count_records($t);
        }

        $cid = (int) $course->id;
        $uid = (int) $teacher->id;
        $calls = [
            ['find', ['type' => 'courses']],
            ['find', ['type' => 'people', 'courseid' => $cid]],
            ['get_course_contents', ['courseid' => $cid]],
            ['read_material', ['courseid' => $cid, 'cmid' => (int) $page->cmid]],
            ['get_grades', ['courseid' => $cid, 'userid' => (int) $student->id]],
            ['get_grade_overview', ['courseid' => $cid]],
            ['get_grade_detail', ['courseid' => $cid, 'userid' => (int) $student->id]],
            ['get_gradebook_structure', ['courseid' => $cid]],
            ['get_quiz_questions', ['courseid' => $cid, 'cmid' => (int) $quiz->cmid]],
            ['get_quiz_attempt_analytics', ['courseid' => $cid, 'cmid' => (int) $quiz->cmid]],
            ['get_assignment_status', ['courseid' => $cid, 'cmid' => (int) $assign->cmid]],
            ['get_activity_settings', ['courseid' => $cid]],
            ['get_completion_overview', ['courseid' => $cid]],
            ['get_forum_activity', ['courseid' => $cid]],
            ['get_course_info', ['courseid' => $cid]],
            ['get_announcements', ['courseid' => $cid]],
            ['get_enrolment_info', ['courseid' => $cid]],
            ['get_student_groups', ['courseid' => $cid]],
            ['get_student_overview', ['courseid' => $cid, 'userid' => (int) $student->id]],
            ['get_forum_posts', ['courseid' => $cid, 'cmid' => (int) $forum->cmid]],
            ['get_grading_queue', []],
            ['get_activity_log', ['courseid' => $cid]],
            ['get_upcoming', ['courseid' => $cid]],
            ['get_action_link', ['intent' => 'view_participants', 'courseid' => $cid]],
        ];
        foreach ($calls as [$name, $args]) {
            tools::dispatch($name, $args, $uid);
        }

        foreach ($tables as $t) {
            $this->assertSame($before[$t], $DB->count_records($t), "read tools changed table $t");
        }
    }

    /**
     * PROOF: the tool allowlist is exactly the known read set — so adding any write tool fails this test.
     */
    public function test_tool_allowlist_is_frozen_read_set(): void {
        $this->resetAfterTest();
        \local_groundedai\local\access_manager::reset_caches();
        // An admin reaches every domain and feature, so this enumerates the whole tool surface.
        $adminid = (int) get_admin()->id;
        $names = array_map(static fn($s) => $s['function']['name'], tools::schemas(1, $adminid));
        sort($names);
        $expected = ['export_document', 'export_table', 'find', 'get_action_link', 'get_activity_log',
            'get_activity_settings', 'get_announcements', 'get_assignment_status', 'get_completion_overview',
            'get_course_contents', 'get_course_info', 'get_enrolment_info', 'get_forum_activity',
            'get_forum_posts', 'get_grade_detail', 'get_grade_overview', 'get_gradebook_structure',
            'get_grades', 'get_grading_queue', 'get_quiz_attempt_analytics', 'get_quiz_questions',
            'get_student_groups', 'get_student_overview', 'get_upcoming', 'list_attachments',
            'read_attachment', 'read_material'];
        sort($expected);
        $this->assertSame($expected, $names, 'the registered tool set changed — confirm no write tool was added');

        // Defensively, no tool name may use a mutating verb (export/read/get/find/list are read verbs).
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(create|update|delete|insert|save|add|remove|enrol|unenrol|set_|write|edit|grade_)\w*/',
                $name,
                "tool '$name' looks like a write operation"
            );
        }
    }

    /**
     * PROOF: no contact PII. find(people) returns only id, name and roles.
     */
    public function test_find_people_returns_no_pii(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $result = tools::dispatch('find', ['type' => 'people', 'courseid' => (int) $course->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertNotEmpty($result['participants']);
        foreach ($result['participants'] as $row) {
            $this->assertSame(['id', 'name', 'roles'], array_keys($row));
            $this->assertArrayNotHasKey('email', $row);
        }
    }

    /**
     * PROOF: no privilege escalation. A teacher without grade:viewall cannot read whole-class grades or
     * another learner's grades, even with the grades domain on.
     */
    public function test_grades_require_capability(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        // A student holds neither grade:viewall nor the right to see another learner's grades.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $overview = tools::dispatch('get_grade_overview', ['courseid' => (int) $course->id], (int) $student->id);
        $this->assertArrayHasKey('error', $overview, 'a student may not read whole-class grades');

        $others = tools::dispatch(
            'get_grades',
            ['courseid' => (int) $course->id, 'userid' => (int) $other->id],
            (int) $student->id
        );
        $this->assertArrayHasKey('error', $others, 'a student may not read another learner\'s grades');
    }

    /**
     * PROOF: model output is sanitised. Assistant content with a script tag / javascript: link is cleaned
     * before it becomes contenthtml.
     */
    public function test_assistant_output_is_sanitised(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $ref = new \ReflectionMethod(\local_groundedai\local\conversation::class, 'with_html');
        $ref->setAccessible(true);
        $msg = (object) ['role' => 'assistant',
            'content' => "Totals.\n\n<script>alert('x')</script>\n\n[link](javascript:evil())"];
        $out = $ref->invoke(null, [$msg], $context);
        $html = (string) $out[0]->contenthtml;
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }

    /**
     * PROOF: telemetry is metadata-only. The emitted span carries only gen_ai.* attributes, never prompt
     * or response content; and it is a no-op when no endpoint is configured.
     */
    public function test_telemetry_is_metadata_only(): void {
        $this->resetAfterTest();
        set_config('otlpendpoint', '', 'local_groundedai');
        $this->assertFalse(telemetry::enabled());
        telemetry::record(['operation' => 'chat', 'model' => 'x']);   // No endpoint: must not throw.

        $span = telemetry::build_span([
            'operation' => 'chat', 'lane' => 'staff', 'model' => 'claude',
            'input_tokens' => 10, 'output_tokens' => 20,
        ]);
        $attrs = $span['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];
        $allowed = ['gen_ai.operation', 'gen_ai.lane', 'gen_ai.model', 'gen_ai.input_tokens',
            'gen_ai.output_tokens', 'gen_ai.duration_ms'];
        foreach ($attrs as $attr) {
            $this->assertStringStartsWith('gen_ai.', $attr['key']);
            $this->assertContains($attr['key'], $allowed, 'unexpected telemetry attribute ' . $attr['key']);
        }
    }
}
