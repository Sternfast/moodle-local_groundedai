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
 * Tests for the staff read-only tool allowlist, its per-grant domain gating, and the deep-link tool.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\access_manager;
use local_groundedai\local\staff\tools;

/**
 * Tests the read-only tool allowlist, its per-grant domain gating, and the deep-link guidance tool.
 *
 * @covers \local_groundedai\local\staff\tools
 */
final class tools_test extends \advanced_testcase {
    /**
     * Reset the per-request caches so a prior test's state can't leak into this one.
     */
    protected function setUp(): void {
        parent::setUp();
        tools::reset_caches();
        access_manager::reset_caches();
    }

    /**
     * Give a user a grant over all courses with the named domains (and features), so their tools are
     * scoped to exactly those domains.
     *
     * @param int $userid The user.
     * @param array $domains The data domains to enable.
     * @param array $features Optional sensitive features.
     */
    private function grant_user(int $userid, array $domains, array $features = []): void {
        access_manager::add_user_grant($userid, access_manager::SCOPE_ALL, 0, 0, $domains, $features);
        access_manager::reset_caches();
    }

    /**
     * enabled_domains() reflects exactly the domains the user's grants enable.
     */
    public function test_enabled_domains_reflects_grants(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame([], tools::enabled_domains((int) $user->id));

        $this->grant_user((int) $user->id, ['grades', 'actions']);
        $domains = tools::enabled_domains((int) $user->id);
        $this->assertContains('grades', $domains);
        $this->assertContains('actions', $domains);
        $this->assertNotContains('courses', $domains);
    }

    /**
     * The action-link tool schema is only offered when the user's grant enables the actions domain.
     */
    public function test_schemas_gate_action_link_on_actions_domain(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->grant_user((int) $teacher->id, ['grades']);
        $this->assertNotContains('get_action_link', self::schema_names(tools::schemas(0, (int) $teacher->id)));

        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ENROLLED, 0, 0, ['actions']);
        access_manager::reset_caches();
        $this->assertContains('get_action_link', self::schema_names(tools::schemas(0, (int) $teacher->id)));
    }

    /**
     * Dispatching a tool whose domain the user's grant does not enable is a recoverable error.
     */
    public function test_dispatch_disabled_domain_is_error(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // All domains except actions.
        $this->grant_user((int) $teacher->id, ['courses', 'grades', 'participants', 'dates', 'progress']);
        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'view_participants', 'courseid' => (int) $course->id],
            (int) $teacher->id
        );
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * An unknown tool name returns the recoverable "unknown or disabled tool" error.
     */
    public function test_dispatch_unknown_tool(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $result = tools::dispatch('definitely_not_a_tool', [], (int) $admin->id);
        $this->assertSame(['error' => 'unknown or disabled tool'], $result);
    }

    /**
     * An editing teacher (who holds grade:edit) gets an allowed single-view link for a learner, with the
     * exact required params; a non-editing teacher (no grade:edit) is denied — both reach the action
     * gate because both grants enable the actions domain.
     */
    public function test_action_link_edit_grade_capability_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editing = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $nonediting = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant_user((int) $editing->id, ['actions']);
        $this->grant_user((int) $nonediting->id, ['actions']);

        $args = ['intent' => 'edit_grade', 'courseid' => (int) $course->id, 'userid' => (int) $student->id];

        $allowed = tools::dispatch('get_action_link', $args, (int) $editing->id);
        $this->assertTrue($allowed['allowed'], 'editing teacher should be allowed to edit a grade');
        $this->assertStringContainsString('/grade/report/singleview/index.php', $allowed['url']);
        $this->assertStringContainsString('item=user', $allowed['url']);
        $this->assertStringContainsString('userid=' . $student->id, $allowed['url']);
        $this->assertStringContainsString('id=' . $course->id, $allowed['url']);
        $this->assertNotEmpty($allowed['steps']);

        $denied = tools::dispatch('get_action_link', $args, (int) $nonediting->id);
        $this->assertFalse($denied['allowed'], 'non-editing teacher lacks grade:edit');
        $this->assertArrayNotHasKey('url', $denied, 'a denied result must never carry a url');
        $this->assertArrayHasKey('reason', $denied);
    }

    /**
     * edit_grade requires a userid; omitting it is a recoverable error, not a fatal.
     */
    public function test_action_link_edit_grade_requires_userid(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editing = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->grant_user((int) $editing->id, ['actions']);
        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'edit_grade', 'courseid' => (int) $course->id],
            (int) $editing->id
        );
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * view_participants returns the participants URL for a teacher.
     */
    public function test_action_link_view_participants(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->grant_user((int) $teacher->id, ['actions']);
        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'view_participants', 'courseid' => (int) $course->id],
            (int) $teacher->id
        );
        $this->assertTrue($result['allowed']);
        $this->assertStringContainsString('/user/index.php', $result['url']);
        $this->assertStringContainsString('id=' . $course->id, $result['url']);
    }

    /**
     * edit_activity checks moodle/course:manageactivities in the MODULE context and links to modedit.
     */
    public function test_action_link_edit_activity_module_context(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->grant_user((int) $teacher->id, ['actions']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'edit_activity', 'courseid' => (int) $course->id, 'cmid' => (int) $page->cmid],
            (int) $teacher->id
        );
        $this->assertTrue($result['allowed']);
        $this->assertStringContainsString('/course/modedit.php', $result['url']);
        $this->assertStringContainsString('update=' . $page->cmid, $result['url']);
    }

    /**
     * A nonexistent course id is a recoverable error, never an uncaught exception.
     */
    public function test_action_link_nonexistent_course(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'view_participants', 'courseid' => 999999],
            (int) $admin->id
        );
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('url', $result);
    }

    /**
     * An unknown intent is a recoverable error.
     */
    public function test_action_link_unknown_intent(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $admin = get_admin();
        $result = tools::dispatch(
            'get_action_link',
            ['intent' => 'delete_everything', 'courseid' => (int) $course->id],
            (int) $admin->id
        );
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * The participants find tool lists enrolled people a teacher may view, and is gated by its domain.
     */
    public function test_find_people_is_domain_gated(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Participants domain off => the tool is not usable.
        $this->grant_user((int) $teacher->id, ['courses']);
        $off = tools::dispatch(
            'find',
            ['type' => 'people', 'courseid' => (int) $course->id],
            (int) $teacher->id
        );
        $this->assertArrayHasKey('error', $off);

        access_manager::add_user_grant((int) $teacher->id, access_manager::SCOPE_ENROLLED, 0, 0, ['participants']);
        access_manager::reset_caches();
        $on = tools::dispatch(
            'find',
            ['type' => 'people', 'courseid' => (int) $course->id],
            (int) $teacher->id
        );
        $this->assertArrayNotHasKey('error', $on);
    }

    /**
     * Tool-call schema names from the schema list.
     *
     * @param array $schemas The tool schemas.
     * @return string[]
     */
    private static function schema_names(array $schemas): array {
        return array_map(static fn($s) => $s['function']['name'] ?? '', $schemas);
    }
}
