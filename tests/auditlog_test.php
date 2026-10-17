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
 * Tests for the access audit trail (QM7): authorised use and every refusal are logged as events.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\access_manager;
use local_groundedai\local\staff_assistant;

/**
 * The assistant records who used it and every refusal.
 *
 * @covers \local_groundedai\event\assistant_queried
 * @covers \local_groundedai\event\access_denied
 */
final class auditlog_test extends \advanced_testcase {
    /**
     * Reset the per-request scope cache so one test's config cannot leak into the next.
     */
    protected function setUp(): void {
        parent::setUp();
        access_manager::reset_caches();
    }

    /**
     * An authorised query emits an assistant_queried event carrying the actor and the course — and NO
     * prompt or response content.
     */
    public function test_authorised_query_is_logged(): void {
        $this->resetAfterTest();
        set_config('enablestaff', 1, 'local_groundedai');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // A grant with NO data domains: the teacher may use the assistant (so the query is logged), but
        // answer() returns the "no domains" result without calling a provider — so we test the log only.
        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ALL, 0, 0, [], []);
        access_manager::reset_caches();
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        staff_assistant::answer((int) $course->id, (int) $teacher->id, 'How is the class doing?');
        $events = $this->events_of($sink, \local_groundedai\event\assistant_queried::class);

        $this->assertCount(1, $events, 'an authorised query must log exactly one assistant_queried event');
        $this->assertSame((int) $course->id, (int) $events[0]->courseid);
        $this->assertSame((int) $teacher->id, (int) $events[0]->userid);
        // The description must not carry the question text.
        $this->assertStringNotContainsString('How is the class doing', $events[0]->get_description());
    }

    /**
     * A user who is not on the allow-list is refused AND the refusal is logged.
     */
    public function test_denied_access_is_logged(): void {
        $this->resetAfterTest();
        set_config('enablestaff', 1, 'local_groundedai');
        // No grants exist, so a teacher is denied by default.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        try {
            staff_assistant::answer((int) $course->id, (int) $teacher->id, 'anything');
            $this->fail('a non-allowed user must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('noaccess', $e->errorcode);
        }
        $events = $this->events_of($sink, \local_groundedai\event\access_denied::class);
        $this->assertCount(1, $events);
        $this->assertSame('not_allowed', $events[0]->other['reason']);
    }

    /**
     * INVARIANT (student-exclusion, QM1+QM7): if a forbidden role is on the allow-list the assistant halts,
     * and the halt is logged with the forbidden_role_halt reason so an institution can see it happened.
     */
    public function test_forbidden_role_halt_is_logged(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablestaff', 1, 'local_groundedai');
        $studentid = (int) $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
        // Force a forbidden-role grant directly into the table (an accidental data edit the invariant catches).
        $DB->insert_record('local_groundedai_grants', (object) [
            'granteetype' => 'role', 'roleid' => $studentid, 'userid' => 0,
            'coursescope' => 'all', 'courseid' => 0, 'expiry' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
        $course = $this->getDataGenerator()->create_course();
        $admin = get_admin();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        try {
            staff_assistant::answer((int) $course->id, (int) $admin->id, 'anything');
            $this->fail('a forbidden role on the allow-list must halt the assistant');
        } catch (\moodle_exception $e) {
            $this->assertSame('studentaccessdetected', $e->errorcode);
        }
        $events = $this->events_of($sink, \local_groundedai\event\access_denied::class);
        $this->assertCount(1, $events);
        $this->assertSame('forbidden_role_halt', $events[0]->other['reason']);
    }

    /**
     * Filter a sink's captured events to one class.
     *
     * @param \phpunit_event_sink $sink The event sink.
     * @param string $class The event class to keep.
     * @return \core\event\base[]
     */
    private function events_of(\phpunit_event_sink $sink, string $class): array {
        return array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof $class));
    }
}
