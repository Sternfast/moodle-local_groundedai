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
 * The course scope of a grant is enforced where a conversation is opened or created.
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
 * A grant limited to one course, or to enrolled courses, must not open the assistant anywhere else —
 * at the entry points, not only inside the data tools.
 *
 * @covers \local_groundedai\local\access_manager::course_in_scope
 * @covers \local_groundedai\local\conversation::require_access
 * @covers \local_groundedai\external\create_conversation
 * @covers \local_groundedai\external\get_conversations
 */
final class entry_scope_test extends \advanced_testcase {
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
     * A teacher of two courses whose grant covers only the first.
     *
     * @return array [teacher, course A (granted), course B (not granted)]
     */
    private function teacher_granted_one_course(): array {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $courseb->id, 'editingteacher');
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_COURSE, (int) $coursea->id);
        access_manager::reset_caches();
        return [$teacher, $coursea, $courseb];
    }

    /**
     * The access check passes in the granted course and refuses the other one.
     */
    public function test_require_access_enforces_course_grant(): void {
        $this->resetAfterTest();
        [$teacher, $coursea, $courseb] = $this->teacher_granted_one_course();
        $this->setUser($teacher);

        conversation::require_access(\context_course::instance($coursea->id));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('scope_blocked', 'local_groundedai'));
        conversation::require_access(\context_course::instance($courseb->id));
    }

    /**
     * An activity inside an out-of-scope course is refused too (its course decides).
     */
    public function test_require_access_resolves_module_context_to_its_course(): void {
        $this->resetAfterTest();
        [$teacher, $coursea, $courseb] = $this->teacher_granted_one_course();
        $pagea = $this->getDataGenerator()->create_module('page', ['course' => $coursea->id]);
        $pageb = $this->getDataGenerator()->create_module('page', ['course' => $courseb->id]);
        $this->setUser($teacher);

        conversation::require_access(\context_module::instance($pagea->cmid));

        $this->expectException(\moodle_exception::class);
        conversation::require_access(\context_module::instance($pageb->cmid));
    }

    /**
     * A conversation cannot be created through the web service in a course outside the grant.
     */
    public function test_create_conversation_refused_outside_scope(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $coursea, $courseb] = $this->teacher_granted_one_course();
        $this->setUser($teacher);

        $created = \local_groundedai\external\create_conversation::execute(
            'staff',
            (int) \context_course::instance($coursea->id)->id
        );
        $this->assertGreaterThan(0, $created['id']);

        try {
            \local_groundedai\external\create_conversation::execute(
                'staff',
                (int) \context_course::instance($courseb->id)->id
            );
            $this->fail('creating a conversation outside the grant scope must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('scope_blocked', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('local_groundedai_conversations', ['userid' => $teacher->id]));
    }

    /**
     * A conversation that exists in a course cannot be opened once the grant no longer covers that course.
     */
    public function test_existing_conversation_not_opened_outside_scope(): void {
        $this->resetAfterTest();
        [$teacher, , $courseb] = $this->teacher_granted_one_course();
        $contextb = \context_course::instance($courseb->id);
        $cid = conversation::create((int) $teacher->id, (int) $contextb->id);
        $this->setUser($teacher);

        try {
            \local_groundedai\external\get_messages::execute($cid, 0, 30);
            $this->fail('opening a conversation outside the grant scope must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('scope_blocked', $e->errorcode);
        }
        try {
            \local_groundedai\external\get_conversations::execute('staff', (int) $contextb->id, false);
            $this->fail('listing conversations outside the grant scope must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('scope_blocked', $e->errorcode);
        }
    }

    /**
     * An "enrolled courses only" grant opens the assistant where the user is enrolled and nowhere else.
     */
    public function test_enrolled_scope_at_entry(): void {
        $this->resetAfterTest();
        $enrolled = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $enrolled->id, 'editingteacher');
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ENROLLED);
        access_manager::reset_caches();

        $this->assertTrue(access_manager::course_in_scope((int) $teacher->id, \context_course::instance($enrolled->id)));
        $this->assertFalse(access_manager::course_in_scope((int) $teacher->id, \context_course::instance($other->id)));
        // No course at all (for example the system context) is never inside a limited grant.
        $this->assertFalse(access_manager::course_in_scope((int) $teacher->id, null));
    }

    /**
     * An "all courses" grant and a site administrator are not narrowed.
     */
    public function test_unlimited_scope_is_not_narrowed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $course->id, 'editingteacher');
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ALL);
        access_manager::reset_caches();

        $this->assertTrue(access_manager::course_in_scope((int) $teacher->id, $context));
        $this->assertTrue(access_manager::course_in_scope((int) get_admin()->id, $context));

        $this->setUser($teacher);
        conversation::require_access($context);
    }

    /**
     * The assistant itself refuses an out-of-scope course and records the refusal in the log.
     */
    public function test_assistant_refuses_and_logs_out_of_scope(): void {
        $this->resetAfterTest();
        [$teacher, , $courseb] = $this->teacher_granted_one_course();
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        try {
            \local_groundedai\local\staff_assistant::answer((int) $courseb->id, (int) $teacher->id, 'How is the class doing?');
            $this->fail('the assistant must not run in a course outside the grant scope');
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
}
