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
 * Tests for the access-grants engine.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\access_manager;

/**
 * Tests the grants-backed access gate: who may use the assistant, scope and expiry, and the
 * structural student-exclusion invariant.
 *
 * @covers \local_groundedai\local\access_manager
 */
final class access_manager_test extends \advanced_testcase {
    /**
     * Reset the static caches between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        access_manager::reset_caches();
    }

    /**
     * The editingteacher role id.
     *
     * @return int
     */
    private function teacher_roleid(): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
    }

    /**
     * Guests and the not-logged-in user never get in.
     */
    public function test_guest_and_empty_are_denied(): void {
        $this->resetAfterTest();
        $this->assertFalse(access_manager::can_use(0));
        $this->assertFalse(access_manager::can_use((int) guest_user()->id));
    }

    /**
     * A site admin is always allowed, even with no grants.
     */
    public function test_siteadmin_always_allowed(): void {
        $this->resetAfterTest();
        $this->assertTrue(access_manager::can_use((int) get_admin()->id));
    }

    /**
     * With no grants, a non-admin is denied (the secure default).
     */
    public function test_no_grants_denies_non_admin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->assertFalse(access_manager::can_use((int) $teacher->id));
    }

    /**
     * A role grant admits a holder of that role; a non-holder stays out.
     */
    public function test_role_grant_admits_holder(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL);

        $this->assertTrue(access_manager::can_use((int) $teacher->id), 'a holder of the granted role is in');
        $this->assertFalse(access_manager::can_use((int) $student->id), 'a non-holder is out');
    }

    /**
     * A user grant admits exactly that user, even one who only holds the student role (a user grant names
     * an individual by design — the user-vs-role boundary).
     */
    public function test_user_grant_admits_user(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_user();

        access_manager::add_user_grant((int) $student->id, access_manager::SCOPE_ALL);

        $this->assertTrue(access_manager::can_use((int) $student->id), 'the named user is in');
        $this->assertFalse(access_manager::can_use((int) $other->id), 'another user is out');
    }

    /**
     * INVARIANT (student-exclusion): student/guest roles are not grantable; a staff role is.
     */
    public function test_student_role_is_not_eligible(): void {
        global $DB;
        $this->resetAfterTest();
        $studentid = (int) $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
        $guestid = (int) $DB->get_field('role', 'id', ['archetype' => 'guest'], IGNORE_MULTIPLE);
        $eligible = access_manager::eligible_roles();
        $this->assertArrayNotHasKey($studentid, $eligible);
        $this->assertArrayNotHasKey($guestid, $eligible);
        $this->assertArrayHasKey($this->teacher_roleid(), $eligible);
    }

    /**
     * INVARIANT (student-exclusion): add_role_grant() rejects a forbidden role and writes no row.
     */
    public function test_add_role_grant_rejects_forbidden_role(): void {
        global $DB;
        $this->resetAfterTest();
        $studentid = (int) $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
        try {
            access_manager::add_role_grant($studentid, access_manager::SCOPE_ALL);
            $this->fail('a forbidden role must not be grantable');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotgrantrole', $e->errorcode);
        }
        $this->assertEquals(0, $DB->count_records('local_groundedai_grants', ['roleid' => $studentid]));
    }

    /**
     * INVARIANT (student-exclusion): even a forbidden-role grant forced directly into the table never
     * admits a holder of that role.
     */
    public function test_forbidden_role_grant_never_admits(): void {
        global $DB;
        $this->resetAfterTest();
        $studentid = (int) $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('local_groundedai_grants', (object) [
            'granteetype' => 'role', 'roleid' => $studentid, 'userid' => 0,
            'coursescope' => 'all', 'courseid' => 0, 'expiry' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
        access_manager::reset_caches();
        $this->assertFalse(access_manager::can_use((int) $student->id));
    }

    /**
     * INVARIANT (student-exclusion): a forbidden-role grant in the table HALTS the assistant; quiet when
     * none is present. Ignores expiry so even a lapsed bad row halts.
     */
    public function test_forbidden_role_grant_halts(): void {
        global $DB;
        $this->resetAfterTest();
        access_manager::assert_no_forbidden_access();   // Quiet with an empty table.

        $studentid = (int) $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
        $DB->insert_record('local_groundedai_grants', (object) [
            'granteetype' => 'role', 'roleid' => $studentid, 'userid' => 0,
            'coursescope' => 'all', 'courseid' => 0, 'expiry' => time() - 100,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 0,
        ]);
        $this->expectException(\moodle_exception::class);
        access_manager::assert_no_forbidden_access();
    }

    /**
     * An expired grant denies; a future expiry admits.
     */
    public function test_expired_grant_denies(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL, 0, time() - 100);
        $this->assertFalse(access_manager::can_use((int) $teacher->id), 'an expired grant does not admit');

        access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ENROLLED, 0, time() + 3600);
        $this->assertTrue(access_manager::can_use((int) $teacher->id), 'a future-dated grant admits');
    }

    /**
     * effective_course_access unions the grants, widest wins, and admins are unrestricted.
     */
    public function test_effective_course_access(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $c1 = $gen->create_course();

        $this->assertTrue(access_manager::effective_course_access((int) get_admin()->id)['all']);

        $g = access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_ALL);
        $this->assertTrue(access_manager::effective_course_access((int) $user->id)['all']);

        access_manager::delete_grant($g);
        access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_ENROLLED);
        $eff = access_manager::effective_course_access((int) $user->id);
        $this->assertFalse($eff['all']);
        $this->assertTrue($eff['enrolled']);
        $this->assertSame([], $eff['courseids']);

        access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_COURSE, (int) $c1->id);
        $eff = access_manager::effective_course_access((int) $user->id);
        $this->assertTrue($eff['enrolled']);
        $this->assertSame([(int) $c1->id], $eff['courseids']);

        access_manager::add_user_grant((int) $user->id, access_manager::SCOPE_ALL);
        $this->assertTrue(access_manager::effective_course_access((int) $user->id)['all'], 'the widest grant wins');
    }

    /**
     * An identical grant cannot be added twice.
     */
    public function test_add_duplicate_throws(): void {
        $this->resetAfterTest();
        access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL);
        $this->expectException(\moodle_exception::class);
        access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL);
    }

    /**
     * A grant's scope updates; its grantee identity is immutable; delete removes it.
     */
    public function test_update_and_delete_grant(): void {
        $this->resetAfterTest();
        $id = access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL);

        access_manager::update_grant($id, ['coursescope' => access_manager::SCOPE_ENROLLED]);
        $this->assertSame(access_manager::SCOPE_ENROLLED, access_manager::get_grant($id)->coursescope);

        try {
            access_manager::update_grant($id, ['roleid' => 999]);
            $this->fail('grantee identity must be immutable');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        access_manager::delete_grant($id);
        $this->assertNull(access_manager::get_grant($id));
    }

    /**
     * A course scope needs a real course; it is rejected otherwise, and any other scope forces courseid 0.
     */
    public function test_course_scope_requires_real_course(): void {
        $this->resetAfterTest();
        try {
            access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_COURSE, 0);
            $this->fail('a course scope with no course must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidcourse', $e->errorcode);
        }
        $id = access_manager::add_role_grant($this->teacher_roleid(), access_manager::SCOPE_ALL, 55);
        $this->assertSame(0, (int) access_manager::get_grant($id)->courseid, 'a non-course scope forces courseid 0');
    }
}
