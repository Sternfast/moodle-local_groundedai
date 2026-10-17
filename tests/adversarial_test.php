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

namespace local_groundedai;

use local_groundedai\local\staff\tools;

/**
 * Adversarial tests: each one mounts an attack on the security contract (read-only wall, staff-only
 * access, per-tool capability re-check, cross-course and separate-groups isolation, contact-PII opt-in,
 * and the deep-link tool's "never acts" guarantee) and asserts the defence holds. A green run is proof
 * the attack is blocked on this Moodle.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_groundedai\local\staff\tools
 */
final class adversarial_test extends \advanced_testcase {
    /**
     * Reset the tools' per-request caches so one test's course never leaks into another.
     */
    protected function setUp(): void {
        parent::setUp();
        tools::reset_caches();
        \local_groundedai\local\access_manager::reset_caches();
    }

    /**
     * Grant the editingteacher role every data domain (and any given features), so a tool denial is
     * proven to be the capability/isolation wall, not a disabled domain. Test actors hold editingteacher.
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
     * Prohibit a capability for a role in a context, then clear the access caches so it takes effect.
     *
     * @param string $capability The capability.
     * @param string $roleshortname The role shortname.
     * @param \context $context The context to prohibit it in.
     */
    private function prohibit(string $capability, string $roleshortname, \context $context): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Snapshot every real data table's row count — the read-only-wall yardstick.
     *
     * @return array table => count.
     */
    private function table_counts(): array {
        global $DB;
        $counts = [];
        foreach ($DB->get_tables(false) as $t) {
            $counts[$t] = $DB->count_records($t);
        }
        return $counts;
    }

    /**
     * ATTACK — cross-course read: a teacher of course A passes course B's id to every course-scoped
     * tool. Expect a recoverable error and zero of course B's data.
     */
    public function test_cross_course_read_is_blocked(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teachera = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');
        // A learner only in B, so B genuinely has data A's teacher must not see.
        $this->getDataGenerator()->create_and_enrol($courseb, 'student');

        foreach (
            ['get_course_contents', 'get_grades', 'get_grade_overview', 'get_enrolment_info',
                'get_completion_overview', 'get_student_groups', 'get_activity_settings'] as $tool
        ) {
            $result = tools::dispatch($tool, ['courseid' => (int) $courseb->id], (int) $teachera->id);
            $this->assertArrayHasKey('error', $result, "$tool must deny a foreign course");
        }
    }

    /**
     * ATTACK — disabled domain: with every domain off, a full-capability teacher still gets nothing.
     * The admin's domain switch is authoritative, independent of capability.
     */
    public function test_disabled_domain_denies_even_a_teacher(): void {
        $this->resetAfterTest();
        foreach (['courses', 'grades', 'participants', 'dates', 'progress', 'actions'] as $d) {
            set_config('domain' . $d, 0, 'local_groundedai');
        }
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        foreach (
            ['get_course_contents' => 'courses', 'get_grades' => 'grades', 'find' => 'participants',
                'get_upcoming' => 'dates', 'get_completion_overview' => 'progress',
                'get_action_link' => 'actions'] as $tool => $domain
        ) {
            $args = ['courseid' => (int) $course->id, 'query' => 'a', 'intent' => 'edit_activity'];
            $result = tools::dispatch($tool, $args, (int) $teacher->id);
            $this->assertArrayHasKey('error', $result, "$tool must be denied when its $domain domain is off");
        }
    }

    /**
     * ATTACK — argument tampering: hostile ids (negative, absent, enormous, non-existent) must degrade
     * to a recoverable error, never an uncaught exception, a crash, or leaked data.
     */
    public function test_tampered_arguments_degrade_gracefully(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $hostile = [
            ['get_grades', ['courseid' => -1, 'userid' => -5]],
            ['get_grades', ['courseid' => 999999999]],
            ['read_material', ['courseid' => (int) $course->id, 'cmid' => 0]],
            ['read_material', ['courseid' => (int) $course->id, 'cmid' => 999999999]],
            ['get_student_overview', ['courseid' => (int) $course->id, 'userid' => 0]],
            ['get_course_contents', []],
            ['get_quiz_questions', ['courseid' => (int) $course->id, 'cmid' => 999999999]],
            ['get_action_link', ['courseid' => (int) $course->id, 'intent' => "' OR 1=1 --", 'itemid' => -1]],
        ];
        foreach ($hostile as [$tool, $args]) {
            $result = tools::dispatch($tool, $args, (int) $teacher->id);
            $this->assertIsArray($result, "$tool must return an array, not throw");
            // A recoverable error OR a safely-empty payload is acceptable; a leak/crash is not.
            $this->assertTrue(
                isset($result['error']) || isset($result['note']) || array_key_exists('allowed', $result)
                    || $result === [] || isset($result['questions']) || isset($result['sections']),
                "$tool returned an unexpected shape for hostile input: " . json_encode($result)
            );
        }
    }

    /**
     * ATTACK — PII exfiltration: with the contact-email opt-in OFF, no tool may surface a learner's
     * email anywhere in its payload. A distinctive address must appear in none of the results.
     */
    public function test_no_contact_email_without_optin(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();   // All domains, but the email feature is NOT granted.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $needle = 'secret.pii.needle@example.net';
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => $needle]);

        foreach (
            [
            ['find', ['type' => 'people', 'courseid' => (int) $course->id, 'query' => '']],
            ['get_enrolment_info', ['courseid' => (int) $course->id]],
            ['get_student_overview', ['courseid' => (int) $course->id, 'userid' => (int) $learner->id]],
            ['get_student_groups', ['courseid' => (int) $course->id]],
            ] as [$tool, $args]
        ) {
            $result = tools::dispatch($tool, $args, (int) $teacher->id);
            $this->assertStringNotContainsString(
                $needle,
                json_encode($result),
                "$tool leaked a contact email while the opt-in is off"
            );
        }
    }

    /**
     * CONTROL — the opt-in actually works: with it on, people look-ups DO include the email, proving the
     * previous test's absence is the opt-in, not a broken fixture.
     */
    public function test_email_appears_only_with_optin(): void {
        $this->resetAfterTest();
        $this->enable_all_domains(['email']);   // Grant the email feature on the teacher's role grant.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $needle = 'optin.pii.needle@example.net';
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => $needle]);

        $result = tools::dispatch(
            'find',
            ['type' => 'people', 'courseid' => (int) $course->id, 'query' => ''],
            (int) $teacher->id
        );
        $this->assertStringContainsString($needle, json_encode($result));
    }

    /**
     * ATTACK — the deep-link tool must never grant an edit the caller cannot make. A teacher whose
     * grade-edit capability is prohibited asks for a grading link and gets allowed:false, with no URL and
     * no change to any table.
     */
    public function test_action_link_denies_without_capability(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->prohibit('moodle/grade:edit', 'editingteacher', $context);

        $before = $this->table_counts();
        $result = tools::dispatch(
            'get_action_link',
            ['courseid' => (int) $course->id, 'intent' => 'edit_grade', 'userid' => (int) $teacher->id],
            (int) $teacher->id
        );
        $this->assertArrayHasKey('allowed', $result);
        $this->assertFalse($result['allowed'], 'a prohibited edit must not be allowed');
        $this->assertArrayNotHasKey('url', $result, 'no deep link may be returned when not allowed');
        $this->assertSame($before, $this->table_counts(), 'get_action_link must never write');
    }

    /**
     * ATTACK — a student actor reaches the data tools directly and tries to read another learner's
     * grades. Every grade tool requires moodle/grade:viewall (which a student lacks), so each must deny.
     * (The staff-only wall proper is access_manager::can_use, enforced upstream and covered by
     * access_manager_test; here we prove the per-tool grade capability re-check also holds.)
     */
    public function test_student_actor_cannot_read_other_grades(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        foreach (
            [
            ['get_grades', ['courseid' => (int) $course->id, 'userid' => (int) $other->id]],
            ['get_grade_overview', ['courseid' => (int) $course->id]],
            ['get_grade_detail', ['courseid' => (int) $course->id, 'userid' => (int) $other->id]],
            ] as [$tool, $args]
        ) {
            $result = tools::dispatch($tool, $args, (int) $student->id);
            $this->assertArrayHasKey('error', $result, "$tool must deny a student reading another's grades");
        }
    }

    /**
     * Build a separate-groups course: a teacher confined to group A (accessallgroups prohibited) and a
     * learner in group B the teacher must not see.
     *
     * @return array [course, teacher, groupA, groupB, learnerB]
     */
    private function separate_groups_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->prohibit('moodle/site:accessallgroups', 'editingteacher', \context_course::instance($course->id));
        $ga = $gen->create_group(['courseid' => $course->id]);
        $gb = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $teacher->id]);
        $learnerb = $gen->create_and_enrol($course, 'student');
        $gen->create_group_member(['groupid' => $gb->id, 'userid' => $learnerb->id]);
        // Guards: the whole premise is a separate-groups course with a group-confined teacher.
        $this->assertSame(
            (int) SEPARATEGROUPS,
            (int) groups_get_course_groupmode(get_course($course->id)),
            'fixture precondition: course must be in separate-groups mode'
        );
        $this->assertFalse(
            has_capability('moodle/site:accessallgroups', \context_course::instance($course->id), $teacher),
            'fixture precondition: the teacher must not have accessallgroups'
        );
        return [$course, $teacher, $ga, $gb, $learnerb];
    }

    /**
     * ATTACK (separate groups) — read_material on a collaborative wiki must return only the caller's own
     * group's subwiki, never another group's private pages.
     */
    public function test_read_material_wiki_respects_separate_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        [$course, $teacher, $ga, $gb] = $this->separate_groups_fixture();
        $wiki = $this->getDataGenerator()->create_module(
            'wiki',
            ['course' => $course->id, 'wikimode' => 'collaborative', 'groupmode' => SEPARATEGROUPS]
        );
        $cm = get_coursemodule_from_instance('wiki', $wiki->id);
        // A wiki manager legitimately sees every subwiki; the leak is for a confined role, so withhold
        // managewiki too (editingteacher holds it by default).
        $this->prohibit('mod/wiki:managewiki', 'editingteacher', \context_course::instance($course->id));
        // Seed one subwiki per group directly (the generator's page creator enforces edit capability we
        // deliberately withhold from the confined teacher). The extractor reads these rows.
        $now = time();
        foreach ([[$ga->id, 'GROUP A NOTES'], [$gb->id, 'GROUP B SECRET']] as [$groupid, $content]) {
            $swid = $DB->insert_record('wiki_subwikis', ['wikiid' => $wiki->id, 'groupid' => $groupid, 'userid' => 0]);
            $DB->insert_record('wiki_pages', [
                'subwikiid' => $swid, 'title' => 'Home', 'cachedcontent' => $content, 'timecreated' => $now,
                'timemodified' => $now, 'timerendered' => $now, 'userid' => 0, 'pageviews' => 0,
                'readonly' => 0, 'content' => $content, 'contentformat' => FORMAT_HTML,
            ]);
        }

        $r = tools::dispatch('read_material', ['courseid' => (int) $course->id, 'cmid' => (int) $cm->id], (int) $teacher->id);
        $this->assertStringContainsString('GROUP A NOTES', $r['text'] ?? '', 'own group content must be readable');
        $this->assertStringNotContainsString('GROUP B SECRET', $r['text'] ?? '', 'wiki leaked another group\'s subwiki');
    }

    /**
     * ATTACK (separate groups) — get_student_groups must refuse a learner in another group.
     */
    public function test_get_student_groups_respects_separate_groups(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        [$course, $teacher, , , $learnerb] = $this->separate_groups_fixture();

        $r = tools::dispatch(
            'get_student_groups',
            ['courseid' => (int) $course->id, 'userid' => (int) $learnerb->id],
            (int) $teacher->id
        );
        $this->assertTrue(
            isset($r['error']) || ($r['groups'] ?? null) === [],
            'a cross-group learner\'s group membership must not be disclosed'
        );
    }

    /**
     * ATTACK (separate groups) — get_forum_posts must not return another group's discussion content or
     * its authors.
     */
    public function test_get_forum_posts_respects_separate_groups(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        [$course, $teacher, , $gb, $learnerb] = $this->separate_groups_fixture();
        $forum = $this->getDataGenerator()->create_module(
            'forum',
            ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]
        );
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $fg->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => (int) $learnerb->id,
            'groupid' => (int) $gb->id, 'name' => 'B only', 'message' => 'SECRET_B_CONTENT',
        ]);

        $r = tools::dispatch('get_forum_posts', ['courseid' => (int) $course->id, 'cmid' => (int) $cm->id], (int) $teacher->id);
        $this->assertStringNotContainsString('SECRET_B_CONTENT', json_encode($r), 'other group\'s discussion leaked');
    }

    /**
     * ATTACK (separate groups) — get_upcoming must not surface a calendar event targeted at a group the
     * caller is not in.
     */
    public function test_get_upcoming_respects_separate_groups(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        [$course, $teacher, , $gb] = $this->separate_groups_fixture();
        \calendar_event::create((object) [
            'name' => 'GROUP B ONLY', 'courseid' => (int) $course->id, 'groupid' => (int) $gb->id,
            'userid' => 0, 'eventtype' => 'group', 'timestart' => time() + DAYSECS,
            'timeduration' => 0, 'visible' => 1,
        ], false);

        $r = tools::dispatch('get_upcoming', ['courseid' => (int) $course->id, 'days' => 30], (int) $teacher->id);
        $names = array_column($r['upcoming'] ?? [], 'name');
        $this->assertNotContains('GROUP B ONLY', $names, 'a group event from another group leaked');
    }

    /**
     * ATTACK (cross-course) — people search must enforce can_access_course, not just the viewparticipants
     * capability a category-level role can inherit onto a course the holder cannot access.
     */
    public function test_find_people_requires_course_access(): void {
        $this->resetAfterTest();
        $this->enable_all_domains();
        global $DB;
        $gen = $this->getDataGenerator();
        $cat = $gen->create_category();
        $course = $gen->create_course(['category' => $cat->id, 'visible' => 0]);
        $gen->create_and_enrol($course, 'student');
        $outsider = $gen->create_user();
        $catctx = \context_coursecat::instance($cat->id);
        $roleid = create_role('Cat viewer', 'catviewer', '');
        assign_capability('moodle/course:viewparticipants', CAP_ALLOW, $roleid, $catctx->id, true);
        role_assign($roleid, $outsider->id, $catctx->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(can_access_course(get_course($course->id), $outsider->id));
        $r = tools::dispatch('find', ['type' => 'people', 'courseid' => (int) $course->id], (int) $outsider->id);
        $this->assertArrayHasKey('error', $r, 'people search must deny a course the caller cannot access');
    }

    /**
     * ATTACK (write-impossibility) — simulate a fully jailbroken model emitting write verbs and SQL
     * injection as admin, straight at the dispatcher. No handler exists for a write verb, int-casts and
     * bound params defuse the injection, and no handler writes — so nothing outside the plugin's own chat
     * store changes. Snapshots EVERY table (derived from the live schema), so a new write path can't hide.
     */
    public function test_wall_resists_write_verbs_and_injection(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_all_domains();
        $this->setAdminUser();
        $admin = get_admin();
        $course = $this->getDataGenerator()->create_course();
        $cid = \local_groundedai\local\conversation::create($admin->id, \context_course::instance($course->id)->id);

        $before = [];
        foreach ($DB->get_tables(false) as $t) {
            $before[$t] = $DB->count_records($t);
        }

        $inj = "'; UPDATE {grade_grades} SET finalgrade=100; --";
        $attacks = [
            ['set_grade', ['courseid' => $course->id, 'userid' => 1, 'grade' => 100]],
            ['edit_grade', ['itemid' => 1, 'userid' => 1, 'value' => 100]],
            ['delete_quiz', ['cmid' => 1]],
            ['delete_activity', ['cmid' => 1]],
            ['enrol_user', ['courseid' => $course->id, 'userid' => $admin->id, 'roleid' => 1]],
            ['assign_role', ['userid' => $admin->id, 'roleid' => 1, 'contextid' => 1]],
            ['create_user', ['username' => 'owned']],
            ['update_course', ['courseid' => $course->id, 'fullname' => 'OWNED', 'visible' => 0]],
            ['run_sql', ['sql' => 'DELETE FROM mdl_quiz']],
            ['execute', ['sql' => 'UPDATE mdl_grade_grades SET finalgrade=100']],
            ['write_record', ['table' => 'grade_grades', 'data' => ['finalgrade' => 100]]],
            ['config_set', ['plugin' => 'local_groundedai', 'name' => 'apikey', 'value' => 'leak']],
            ['get_grades', ['courseid' => $inj, 'userid' => '1 OR 1=1']],
            ['get_grade_detail', ['courseid' => $course->id, 'userid' => $inj]],
            ['read_material', ['courseid' => $course->id, 'cmid' => $inj]],
            ['get_action_link', ['intent' => $inj, 'courseid' => $course->id, 'itemid' => $inj]],
            ['get_quiz_questions', ['courseid' => $course->id, 'cmid' => '1; DROP TABLE mdl_quiz']],
            ['get_completion_overview', ['courseid' => '4 UNION SELECT 1']],
        ];
        foreach ($attacks as [$name, $args]) {
            $r = tools::dispatch($name, $args, $admin->id, $cid);
            $this->assertIsArray($r, "dispatch('{$name}') must return an array, never throw");
        }

        foreach ($DB->get_tables(false) as $t) {
            if (strpos($t, 'local_groundedai') !== false) {
                continue;   // The harness created one conversation; the wall protects MOODLE data.
            }
            $this->assertSame(
                $before[$t] ?? -1,
                $DB->count_records($t),
                "hostile tool calls mutated Moodle table '{$t}'"
            );
        }
    }

    /**
     * POLICY (byo-key-fail-closed) — with no endpoint configured (the shipped default), the provider
     * refuses to send anything, so nothing can egress before the admin sets an endpoint.
     */
    public function test_byo_key_fail_closed(): void {
        $this->resetAfterTest();
        set_config('baseurl', '', 'local_groundedai');
        $this->expectException(\moodle_exception::class);
        (new \local_groundedai\local\provider_facade())->chat([['role' => 'user', 'content' => 'hi']], 'frontier');
    }

    /**
     * POLICY (endpoint-access-gating) — once the master switch is off, reading conversation history is
     * refused too, so the kill switch is a complete off-switch, not just a UI hide.
     */
    public function test_read_endpoint_honours_kill_switch(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablestaff', 1, 'local_groundedai');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        \local_groundedai\local\access_manager::add_role_grant($roleid, \local_groundedai\local\access_manager::SCOPE_ALL);
        $this->setUser($teacher);
        $cid = \local_groundedai\local\conversation::create($teacher->id, \context_course::instance($course->id)->id);

        set_config('enablestaff', 0, 'local_groundedai');   // Flip the kill switch off.
        $this->expectException(\moodle_exception::class);
        \local_groundedai\external\get_messages::execute($cid, 0, 30);
    }

    /**
     * POLICY (course-scope-follows-moodle) — a role narrowed to 'enrolled only' may reach its enrolled
     * courses but NOT a course it could otherwise access via a system-level role assignment. Tightens
     * only; the default 'all' scope still defers to Moodle.
     */
    public function test_enrolled_scope_tightens_to_enrolment(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $teacher = $gen->create_user();
        $troleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        // A SYSTEM-level teacher role: Moodle grants access to every course, including B.
        role_assign($troleid, $teacher->id, \context_system::instance()->id);
        $gen->enrol_user($teacher->id, $coursea->id, 'editingteacher');   // Enrolled only in A.
        accesslib_clear_all_caches_for_unit_testing();

        // The ONLY grant scopes the teacher role to enrolled-only (with all domains).
        \local_groundedai\local\access_manager::add_role_grant(
            $troleid,
            \local_groundedai\local\access_manager::SCOPE_ENROLLED,
            0,
            0,
            null
        );
        tools::reset_caches();
        \local_groundedai\local\access_manager::reset_caches();

        $this->assertArrayNotHasKey(
            'error',
            tools::dispatch('get_course_contents', ['courseid' => (int) $coursea->id], (int) $teacher->id),
            'an enrolled course stays reachable under the enrolled-only scope'
        );
        $this->assertArrayHasKey(
            'error',
            tools::dispatch('get_course_contents', ['courseid' => (int) $courseb->id], (int) $teacher->id),
            'a non-enrolled course is blocked once the role is scoped to enrolled-only'
        );
    }

    /**
     * POLICY (course-scope-follows-moodle) — a grant scoped to ONE course lets the grantee reach that
     * course only, even a different course they are enrolled in. The widest active grant still never
     * widens past what Moodle grants (the gate runs can_access_course first).
     */
    public function test_course_specific_grant_limits_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $teacher = $gen->create_user();
        $troleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        // Enrolled as teacher in BOTH courses, so Moodle grants access to each.
        $gen->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $courseb->id, 'editingteacher');
        accesslib_clear_all_caches_for_unit_testing();

        // The only grant names course A specifically (with all domains).
        \local_groundedai\local\access_manager::add_role_grant(
            $troleid,
            \local_groundedai\local\access_manager::SCOPE_COURSE,
            (int) $coursea->id,
            0,
            null
        );
        tools::reset_caches();
        \local_groundedai\local\access_manager::reset_caches();

        $this->assertArrayNotHasKey(
            'error',
            tools::dispatch('get_course_contents', ['courseid' => (int) $coursea->id], (int) $teacher->id),
            'the course named in the grant is reachable'
        );
        $this->assertArrayHasKey(
            'error',
            tools::dispatch('get_course_contents', ['courseid' => (int) $courseb->id], (int) $teacher->id),
            'another enrolled course is blocked when the only grant is course-specific'
        );
    }
}
