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
 * The assistant's access rules hold at every entry point and match Moodle's own visibility rules.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\access_manager;
use local_groundedai\local\conversation;
use local_groundedai\local\staff\tools;

/**
 * Edge cases of the grant scope, the remaining conversation web services, and look-ups that must not
 * show more than Moodle itself would.
 *
 * @covers \local_groundedai\local\access_manager::course_in_scope
 * @covers \local_groundedai\local\conversation::require_access
 * @covers \local_groundedai\local\staff\tools
 * @covers \local_groundedai\observer
 */
final class access_parity_test extends \advanced_testcase {
    /**
     * Reset the per-request caches and enable the assistant.
     */
    protected function setUp(): void {
        parent::setUp();
        tools::reset_caches();
        access_manager::reset_caches();
        set_config('enablestaff', 1, 'local_groundedai');
    }

    /**
     * A teacher of two courses whose grant covers only the first, with a conversation in the second.
     *
     * @param array $features Sensitive features to enable on the grant.
     * @return array [teacher, course A (granted), course B (not granted), conversation id in B]
     */
    private function teacher_with_conversation_outside_scope(array $features = []): array {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $courseb->id, 'editingteacher');
        access_manager::add_user_grant(
            (int) $teacher->id,
            access_manager::SCOPE_COURSE,
            (int) $coursea->id,
            0,
            null,
            $features
        );
        access_manager::reset_caches();
        $cid = conversation::create((int) $teacher->id, (int) \context_course::instance($courseb->id)->id);
        return [$teacher, $coursea, $courseb, $cid];
    }

    /**
     * Assert that a call is refused because the course is outside the grant.
     *
     * @param callable $call The call to make.
     * @param string $what What was attempted, for the failure message.
     */
    private function assert_scope_blocked(callable $call, string $what): void {
        try {
            $call();
            $this->fail($what . ' must be refused outside the grant scope');
        } catch (\moodle_exception $e) {
            $this->assertSame('scope_blocked', $e->errorcode, $what);
        }
    }

    /**
     * Everyone counts as enrolled in the site front page; an "enrolled courses" grant must not open it.
     */
    public function test_enrolled_grant_does_not_cover_front_page(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_ENROLLED);
        access_manager::reset_caches();

        $this->assertFalse(access_manager::course_in_scope((int) $user->id, \context_course::instance(SITEID)));
    }

    /**
     * A suspended enrolment does not count for an "enrolled courses" grant.
     */
    public function test_suspended_enrolment_is_not_in_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $course->id, 'editingteacher');
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ENROLLED);
        access_manager::reset_caches();
        $this->assertTrue(access_manager::course_in_scope((int) $teacher->id, $context));

        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $teacher->id]);
        access_manager::reset_caches();
        $this->assertFalse(access_manager::course_in_scope((int) $teacher->id, $context));
    }

    /**
     * A user with no grant is never in scope, whatever is asked.
     */
    public function test_no_grant_is_never_in_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course->id, 'editingteacher');

        $this->assertFalse(access_manager::course_in_scope((int) $user->id, \context_course::instance($course->id)));
    }

    /**
     * Every web service that reads, changes or adds to a conversation refuses one outside the grant.
     */
    public function test_conversation_services_refuse_outside_scope(): void {
        $this->resetAfterTest();
        [$teacher, , , $cid] = $this->teacher_with_conversation_outside_scope(['upload']);
        $this->setUser($teacher);

        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\rename_conversation::execute($cid, 'New name'),
            'rename'
        );
        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\archive_conversation::execute($cid, true),
            'archive'
        );
        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\send_message::execute($cid, 'How is the class doing?'),
            'send'
        );
        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\get_attachments::execute($cid),
            'list attachments'
        );
        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\prepare_upload::execute($cid),
            'prepare upload'
        );
        $this->assert_scope_blocked(
            fn() => \local_groundedai\external\upload_file::execute($cid, 0),
            'upload'
        );
    }

    /**
     * A user can still delete their own conversation after their access was narrowed.
     */
    public function test_own_conversation_can_still_be_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, , , $cid] = $this->teacher_with_conversation_outside_scope();
        $this->setUser($teacher);

        \local_groundedai\external\delete_conversation::execute($cid);
        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $cid]));
    }

    /**
     * A refusal at the entry point is recorded in the access log with its reason.
     */
    public function test_entry_refusal_is_logged(): void {
        $this->resetAfterTest();
        [$teacher, , $courseb] = $this->teacher_with_conversation_outside_scope();
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        try {
            conversation::require_access(\context_course::instance($courseb->id));
            $this->fail('expected a refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('scope_blocked', $e->errorcode);
        }
        $denied = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \local_groundedai\event\access_denied
        ));
        $this->assertCount(1, $denied);
        $this->assertSame('out_of_scope', $denied[0]->other['reason']);
    }

    /**
     * While an assignment hides identities for anonymous marking, a grader who may not see them in
     * Moodle cannot list its submissions by learner through the assistant.
     */
    public function test_anonymous_marking_is_respected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $blind = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'blindmarking' => 1]);
        $open = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $course->id, 'editingteacher');
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ALL, 0, 0, ['grades']);
        access_manager::reset_caches();
        $this->assertFalse(
            has_capability('mod/assign:viewblinddetails', \context_module::instance($blind->cmid), $teacher),
            'precondition: a teacher cannot see identities during anonymous marking'
        );

        $refused = tools::dispatch(
            'get_assignment_status',
            ['courseid' => (int) $course->id, 'cmid' => (int) $blind->cmid],
            (int) $teacher->id
        );
        $this->assertArrayHasKey('error', $refused);
        $this->assertStringContainsString('anonymous marking', $refused['error']);

        $allowed = tools::dispatch(
            'get_assignment_status',
            ['courseid' => (int) $course->id, 'cmid' => (int) $open->cmid],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $allowed);
    }

    /**
     * Enrolment methods, dates and status need the permission Moodle requires to review enrolments.
     */
    public function test_enrolment_details_need_enrolment_review(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editing = $this->getDataGenerator()->create_user();
        $nonediting = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $editing->id, (int) $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $nonediting->id, (int) $course->id, 'teacher');
        foreach ([$editing, $nonediting] as $user) {
            access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_ALL, 0, 0, ['participants']);
        }
        access_manager::reset_caches();
        $context = \context_course::instance($course->id);
        $this->assertFalse(has_capability('moodle/course:enrolreview', $context, $nonediting), 'precondition');

        $refused = tools::dispatch('get_enrolment_info', ['courseid' => (int) $course->id], (int) $nonediting->id);
        $this->assertArrayHasKey('error', $refused);

        $allowed = tools::dispatch('get_enrolment_info', ['courseid' => (int) $course->id], (int) $editing->id);
        $this->assertArrayNotHasKey('error', $allowed);
    }

    /**
     * Deleting a course also removes a conversation stored in one of its activities.
     */
    public function test_course_deletion_removes_activity_conversations(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $cid = conversation::create((int) $user->id, (int) \context_module::instance($page->cmid)->id);
        $DB->insert_record('local_groundedai_messages', (object) [
            'conversationid' => $cid, 'role' => 'user', 'content' => 'a private question', 'timecreated' => time(),
        ]);

        delete_course($course, false);

        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $cid]));
        $this->assertFalse($DB->record_exists('local_groundedai_messages', ['conversationid' => $cid]));
    }
}
